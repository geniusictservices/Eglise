<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectIndicator;
use App\Models\ProjectRemittance;
use App\Support\FiscalYear;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les projets du siège (ou d'une région) portés par les paroisses.
 *
 * Le siège fixe la part de chaque paroisse. La paroisse reçoit un projet relais : même nom,
 * objectif égal à sa part, tranches au prorata de celles du siège. Elle y collecte (promesses,
 * dons, collectes marquées), puis verse au siège ; le versement est une sortie du projet relais.
 * Le siège confirme la réception : l'argent entre dans son projet. Ces versements sont des
 * mouvements internes au réseau : ils ne gonflent pas les totaux consolidés et ne comptent pas
 * dans les quotes-parts.
 */
class ProjectNetwork
{
    public const SENT_CATEGORY = 'Versements au siège pour les projets';

    public function __construct(private Projects $projects, private Ledger $ledger, private ExchangeRateService $rates, private Notifier $notifier) {}

    /** Les niveaux qui peuvent porter une part : tous ceux qui sont sous le niveau du projet. */
    public function eligible(Project $project): Collection
    {
        $owner = $project->loadMissing('organization')->organization;

        return Organization::query()->subtreeOf($owner)->where('id', '!=', $owner->id)->orderBy('path')->get();
    }

    /**
     * Fixe la part d'un niveau (en dollars). À zéro, la part est retirée : le projet relais est
     * supprimé s'il n'a encore rien reçu, sinon abandonné.
     */
    public function setShare(Project $project, Organization $unit, float $share): ?Project
    {
        if ($project->isRelay()) {
            throw new InvalidArgumentException(__('Une paroisse ne répartit pas un projet du siège.'));
        }
        if (! $unit->isDescendantOf($project->loadMissing('organization')->organization)) {
            throw new InvalidArgumentException(__('Ce niveau n’est pas sous :o.', ['o' => $project->organization->displayName()]));
        }
        $relay = Project::withoutOrganizationScope()->where('organization_id', $unit->id)->where('parent_project_id', $project->id)->first();

        if ($share < 0.005) {
            if ($relay && $relay->transactions()->exists()) {
                $relay->update(['status' => 'cancelled']);
            } else {
                $relay?->delete();
            }

            return null;
        }

        return DB::transaction(function () use ($project, $unit, $share, $relay) {
            $goal = $project->goal_amount !== null ? (float) $project->goal_amount : (float) $project->years()->sum('income_planned');
            $ratio = $goal > 0 ? $share / $goal : 0;
            $years = $project->years()->get()->map(fn ($y) => [
                'fiscal_year' => $y->fiscal_year,
                'income_planned' => round((float) $y->income_planned * $ratio, 2),
                'expense_planned' => round((float) $y->income_planned * $ratio, 2), // ce qui est collecté est versé au siège
                'note' => __('Part de :p', ['p' => $unit->displayName()]),
            ])->all();
            if ($years === []) {
                $years = [['fiscal_year' => FiscalYear::current($unit), 'income_planned' => $share, 'expense_planned' => $share]];
            }

            $isNew = ! $relay;
            $relay = $this->projects->save($unit, [
                'name' => $project->name, 'kind' => $project->kind, 'description' => $project->description,
                'theme' => __('Projets de :o', ['o' => $project->organization->displayName()]),
                'goal_amount' => $share, 'goal_currency' => 'USD',
                'starts_on' => $project->starts_on?->toDateString(), 'ends_on' => $project->ends_on?->toDateString(),
                'status' => in_array($relay?->status, ['done', 'cancelled'], true) ? 'ongoing' : ($relay->status ?? 'ongoing'),
            ], $years, $relay);
            $relay->forceFill(['parent_project_id' => $project->id])->save();

            if ($isNew) {
                // L'avancement de la paroisse : sa part collectée, et sa part versée au siège.
                $this->projects->saveIndicator($relay, ['kind' => 'collected', 'name' => __('Part collectée')]);
                $this->projects->saveIndicator($relay, ['kind' => 'spent', 'name' => __('Part versée à :o', ['o' => $project->organization->displayName()]), 'target' => $share]);
            } else {
                ProjectIndicator::where('project_id', $relay->id)->where('kind', 'spent')->update(['target' => $share]);
            }

            return $relay;
        });
    }

    /**
     * Le projet vu du siège, paroisse par paroisse : part, collecté sur place, versé, reçu,
     * reste à collecter, reste à verser.
     *
     * @return array{rows: Collection, totals: array{share: float, collected: float, sent: float, received: float, to_collect: float, to_send: float}, pending: Collection}
     */
    public function overview(Project $project): array
    {
        $remittances = ProjectRemittance::with(['from', 'sender'])->where('parent_project_id', $project->id)->get();
        $rows = $project->relays()->with('organization')->where('status', '!=', 'cancelled')->get()->map(function (Project $relay) use ($remittances) {
            $totals = $this->projects->totals($relay);
            $mine = $remittances->where('project_id', $relay->id);
            $collected = round($totals['received'], 2);
            $sent = round((float) $mine->sum('usd_amount'), 2);

            return [
                'relay' => $relay, 'organization' => $relay->organization,
                'share' => (float) $relay->goal_amount, 'collected' => $collected, 'promised' => $totals['promised'],
                'sent' => $sent, 'received' => round((float) $mine->where('status', 'received')->sum('usd_amount'), 2),
                'to_collect' => round(max(0, (float) $relay->goal_amount - $collected), 2),
                'to_send' => round(max(0, $collected - $sent), 2),
            ];
        })->sortBy(fn ($r) => $r['organization']->name)->values();

        $sum = fn (string $k) => round((float) $rows->sum($k), 2);

        return [
            'rows' => $rows,
            'totals' => ['share' => $sum('share'), 'collected' => $sum('collected'), 'sent' => $sum('sent'), 'received' => $sum('received'),
                'to_collect' => $sum('to_collect'), 'to_send' => $sum('to_send')],
            'pending' => $remittances->where('status', 'sent')->sortBy('paid_on')->values(),
        ];
    }

    /** La paroisse verse au siège ce qu'elle a collecté pour le projet. */
    public function send(Project $relay, CashAccount $account, string $currency, string $amount, ?string $reference = null): ProjectRemittance
    {
        $parent = $relay->parentProject ?? throw new InvalidArgumentException(__('Ce projet n’est pas un projet du siège.'));
        if ($account->organization_id !== $relay->organization_id) {
            throw new InvalidArgumentException(__('Choisissez un compte de la paroisse.'));
        }
        $organization = $relay->loadMissing('organization')->organization;
        $rate = $currency === 'USD' ? null : $this->rates->rate($organization, $currency);
        if ($currency !== 'USD' && ! $rate) {
            throw new InvalidArgumentException(__('Saisissez d’abord le taux du jour pour :currency.', ['currency' => $currency]));
        }
        $usd = $currency === 'USD' ? (float) $amount : (float) $amount / (float) (string) $rate;
        $held = $this->projects->reserved($organization)['projects']->firstWhere('project.id', $relay->id)['amount'] ?? 0;
        if ($usd > $held + 0.01) {
            throw new InvalidArgumentException(__('La paroisse n’a que :m collectés pour ce projet, pas encore versés.', ['m' => Money::format($held, 'USD')]));
        }

        return DB::transaction(function () use ($relay, $parent, $account, $currency, $amount, $reference, $organization) {
            $category = FinanceCategory::withoutOrganizationScope()->firstOrCreate(['organization_id' => $organization->id, 'type' => 'expense', 'name' => self::SENT_CATEGORY], ['position' => 96]);
            $expense = $this->ledger->record($account, $currency, 'expense', ['amount' => $amount, 'category_id' => $category->id, 'project_id' => $relay->id,
                'description' => __('Versement à :o : :p', ['o' => $parent->organization->displayName(), 'p' => $parent->name]), 'external_reference' => $reference]);
            $remittance = ProjectRemittance::create(['project_id' => $relay->id, 'parent_project_id' => $parent->id,
                'from_organization_id' => $organization->id, 'to_organization_id' => $parent->organization_id,
                'amount' => $expense->amount, 'currency' => $currency, 'usd_amount' => $expense->usd_amount, 'paid_on' => today(),
                'reference' => $reference, 'expense_transaction_id' => $expense->id, 'sent_by' => auth()->id()]);
            $this->notifier->send($parent->organization, $this->notifier->withPermission($parent->organization, 'finance.income'), "remittance.{$remittance->id}", [
                'title' => __(':c a versé pour « :p »', ['c' => $organization->displayName(), 'p' => $parent->name]),
                'body' => Money::format($remittance->amount, $currency).__(' · à confirmer à la réception'),
                'url' => route('projects.show', ['projet' => $parent->id, 'onglet' => 'paroisses']), 'icon' => 'hand-coins']);

            return $remittance;
        });
    }

    /** Le siège confirme la réception : l'argent entre dans son projet, dans le compte choisi. */
    public function receive(ProjectRemittance $remittance, CashAccount $account): void
    {
        if ($remittance->status === 'received') {
            throw new InvalidArgumentException(__('Ce versement est déjà reçu.'));
        }
        if ($account->organization_id !== $remittance->to_organization_id) {
            throw new InvalidArgumentException(__('Choisissez un compte de :o.', ['o' => $remittance->to?->displayName()]));
        }
        DB::transaction(function () use ($remittance, $account) {
            $remittance->loadMissing(['from', 'parentProject']);
            $income = $this->ledger->record($account, $remittance->currency, 'income', ['amount' => (string) $remittance->amount,
                'category_id' => $remittance->parentProject->category_id, 'project_id' => $remittance->parent_project_id,
                'payer_name' => $remittance->from->displayName(), 'description' => __('Versement de :c : :p', ['c' => $remittance->from->displayName(), 'p' => $remittance->parentProject->name]),
                'external_reference' => $remittance->reference]);
            $remittance->update(['status' => 'received', 'income_transaction_id' => $income->id, 'received_by' => auth()->id(), 'received_at' => now()]);
            $this->notifier->settle("remittance.{$remittance->id}");
        });
    }
}
