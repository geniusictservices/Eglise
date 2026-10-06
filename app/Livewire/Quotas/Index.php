<?php

namespace App\Livewire\Quotas;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\QuotaPayment;
use App\Services\Quotas;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Les quotes-parts : ce que la communauté doit à son niveau supérieur, et ce qu'elle reçoit des siens. */
#[Title('Quotes-parts')]
class Index extends Component
{
    use WritesInOrganization;

    public array $rule = ['mode' => 'none', 'percent' => '', 'amount' => '', 'currency' => 'USD'];

    public array $send = ['period' => '', 'account' => '', 'currency' => 'USD', 'amount' => '', 'reference' => ''];

    public ?int $receivingId = null;

    public string $receiveAccount = '';

    public function mount(Quotas $quotas): void
    {
        abort_unless(Gate::any(['consolidation.view', 'finance.view']), 403);
        if ($rule = $quotas->rule($this->organization())) {
            $this->rule = ['mode' => $rule->mode, 'percent' => (string) $rule->percent, 'amount' => (string) $rule->amount, 'currency' => $rule->currency ?? 'USD'];
        }
    }

    public function saveRule(Quotas $quotas): void
    {
        $this->authorizeWrite('finance.settings');
        $this->validate(['rule.mode' => 'required|in:none,percent,fixed', 'rule.percent' => 'nullable|numeric', 'rule.amount' => 'nullable|numeric', 'rule.currency' => 'nullable|string|size:3']);
        try {
            $quotas->setRule($this->organization(), $this->rule['mode'], (float) $this->rule['percent'], (float) $this->rule['amount'], $this->rule['currency']);
        } catch (InvalidArgumentException $e) {
            $this->addError('rule.percent', $e->getMessage());

            return;
        }
        $this->notify(__('Règle de quote-part enregistrée.'));
    }

    public function askSend(Quotas $quotas, string $period): void
    {
        $this->authorizeWrite('finance.disburse');
        $owed = $quotas->owed($this->organization(), $period);
        $this->send = ['period' => $period, 'account' => (string) (CashAccount::where('is_active', true)->orderBy('position')->value('id') ?? ''), 'currency' => 'USD',
            'amount' => $owed ? (string) $owed['remaining'] : '', 'reference' => ''];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'send-quota');
    }

    public function sendQuota(Quotas $quotas): void
    {
        $this->authorizeWrite('finance.disburse');
        $this->validate(['send.account' => 'required|integer', 'send.currency' => 'required|string|size:3', 'send.amount' => 'required|numeric|gt:0', 'send.reference' => 'nullable|string|max:100'],
            attributes: ['send.amount' => __('montant'), 'send.account' => __('compte')]);
        try {
            $quotas->send($this->organization(), $this->send['period'], CashAccount::findOrFail($this->send['account']), $this->send['currency'], (string) $this->send['amount'], $this->send['reference'] ?: null);
        } catch (InvalidArgumentException $e) {
            $this->addError('send.amount', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'send-quota');
        $this->notify(__('Quote-part versée : la dépense est au journal, le niveau supérieur est prévenu.'));
    }

    public function askReceive(int $id): void
    {
        $this->authorizeWrite('finance.income');
        $this->receivingId = QuotaPayment::where('to_organization_id', $this->organization()->id)->where('status', 'sent')->findOrFail($id)->id;
        $this->receiveAccount = (string) (CashAccount::where('is_active', true)->orderBy('position')->value('id') ?? '');
        $this->dispatch('open-modal', name: 'receive-quota');
    }

    public function receive(Quotas $quotas): void
    {
        $this->authorizeWrite('finance.income');
        $this->validate(['receiveAccount' => 'required|integer'], attributes: ['receiveAccount' => __('compte')]);
        try {
            $quotas->receive(QuotaPayment::where('to_organization_id', $this->organization()->id)->findOrFail($this->receivingId), CashAccount::findOrFail($this->receiveAccount));
        } catch (InvalidArgumentException $e) {
            $this->addError('receiveAccount', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'receive-quota');
        $this->notify(__('Réception confirmée : la recette est au journal.'));
    }

    public function render(Quotas $quotas)
    {
        $organization = $this->organization()->loadMissing('parent');
        $periods = collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'));
        $children = $organization->children()->orderBy('name')->get();

        return view('livewire.quotas.index', [
            'parent' => $organization->parent,
            'parentRule' => $organization->parent ? $quotas->rule($organization->parent) : null,
            'owed' => $periods->reverse()->map(fn ($p) => $quotas->owed($organization, $p))->filter()->values(),
            'children' => $children,
            'grid' => $children->isNotEmpty() && $quotas->rule($organization)
                ? $children->mapWithKeys(fn ($c) => [$c->id => $periods->slice(-3)->mapWithKeys(fn ($p) => [$p => $quotas->owed($c, $p)])])
                : collect(),
            'gridPeriods' => $periods->slice(-3),
            'pending' => QuotaPayment::with('from')->where('to_organization_id', $organization->id)->where('status', 'sent')->latest()->get(),
            'accounts' => CashAccount::where('is_active', true)->with('currencies')->orderBy('position')->get(),
            'quotas' => $quotas,
            'canSettings' => Gate::allows('finance.settings') && ! $organization->isReadOnly(),
            'canSend' => Gate::allows('finance.disburse') && ! $organization->isReadOnly(),
            'canReceive' => Gate::allows('finance.income') && ! $organization->isReadOnly(),
        ]);
    }
}
