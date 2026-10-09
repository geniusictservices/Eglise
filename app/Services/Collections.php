<?php

namespace App\Services;

use App\Models\CollectionSheet;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * La collecte du culte : ce qui a été compté (billets) doit correspondre à ce
 * qui est déclaré (offrandes collectives et enveloppes). À la validation,
 * chaque offrande et chaque enveloppe devient une recette du compte choisi.
 */
class Collections
{
    public function __construct(private Ledger $ledger) {}

    /** Billets et pièces à compter, pour une devise. */
    public static function denominations(string $currency): array
    {
        return config("waumini.finance.denominations.{$currency}", []);
    }

    /**
     * Par devise : total compté (si des billets ont été saisis), total déclaré et écart.
     *
     * @return array<string, array{counted: ?BigDecimal, declared: BigDecimal, collective: BigDecimal, envelopes: BigDecimal, difference: ?BigDecimal}>
     */
    public function summary(CollectionSheet $sheet): array
    {
        $sheet->loadMissing(['lines', 'envelopes']);
        $currencies = collect($sheet->lines->pluck('currency'))->merge($sheet->envelopes->pluck('currency'))
            ->merge(array_keys(array_filter($sheet->counts ?? [])))->unique()->sort()->values();

        $summary = [];
        foreach ($currencies as $currency) {
            $collective = $sheet->lines->where('currency', $currency)->reduce(fn ($s, $l) => $s->plus((string) $l->amount), BigDecimal::zero());
            $envelopes = $sheet->envelopes->where('currency', $currency)->reduce(fn ($s, $e) => $s->plus((string) $e->amount), BigDecimal::zero());
            $counted = $this->counted($sheet->counts[$currency] ?? []);
            $declared = $collective->plus($envelopes);

            $summary[$currency] = [
                'counted' => $counted,
                'declared' => $declared,
                'collective' => $collective,
                'envelopes' => $envelopes,
                'difference' => $counted?->minus($declared),
            ];
        }

        return $summary;
    }

    /** Total des billets comptés, ou null si rien n'a été compté. */
    public function counted(array $counts): ?BigDecimal
    {
        $counts = array_filter($counts, fn ($q) => (int) $q > 0);
        if ($counts === []) {
            return null;
        }

        return collect($counts)->reduce(fn ($sum, $qty, $value) => $sum->plus(BigDecimal::of((string) $value)->multipliedBy((int) $qty)), BigDecimal::zero());
    }

    public function validate(CollectionSheet $sheet): void
    {
        if (! $sheet->isDraft()) {
            throw new InvalidArgumentException(__('Cette feuille a déjà été validée.'));
        }

        $summary = $this->summary($sheet);
        if ($summary === [] || collect($summary)->every(fn ($s) => $s['declared']->isZero())) {
            throw new InvalidArgumentException(__('La feuille est vide : saisissez au moins une offrande ou une enveloppe.'));
        }
        foreach ($summary as $currency => $s) {
            if ($s['difference'] !== null && ! $s['difference']->isZero()) {
                throw new InvalidArgumentException(__('Le comptage des billets en :currency ne correspond pas au total déclaré : corrigez l’écart avant de valider.', ['currency' => $currency]));
            }
        }
        if (count(array_filter($sheet->counters ?? [])) < 1) {
            throw new InvalidArgumentException(__('Indiquez qui a compté la collecte.'));
        }

        DB::transaction(function () use ($sheet) {
            // Relue sous verrou : un double clic ne passe pas deux fois.
            if (! CollectionSheet::withoutOrganizationScope()->lockForUpdate()->findOrFail($sheet->id)->isDraft()) {
                throw new InvalidArgumentException(__('Cette feuille a déjà été validée.'));
            }
            $account = $sheet->account;
            $label = $sheet->service_label.' · '.$sheet->service_date->translatedFormat('j F Y');
            $base = ['occurred_on' => $sheet->service_date->toDateString(), 'collection_id' => $sheet->id, 'payment_method' => 'cash'];

            foreach ($sheet->lines as $line) {
                if ((float) $line->amount > 0) {
                    $this->ledger->record($account, $line->currency, 'income', $base + ['amount' => (string) $line->amount, 'category_id' => $line->category_id, 'description' => $label]);
                }
            }
            foreach ($sheet->envelopes as $envelope) {
                $this->ledger->record($account, $envelope->currency, 'income', $base + ['amount' => (string) $envelope->amount, 'category_id' => $envelope->category_id,
                    'member_id' => $envelope->member_id, 'payer_name' => $envelope->member_id ? null : $envelope->payer_name, 'description' => $label]);
            }

            $sheet->update(['status' => 'validated', 'validated_by' => auth()->id(), 'validated_at' => now()]);
        });
    }

    /** Annule une feuille validée : ses recettes sont annulées, avec le motif. */
    public function cancel(CollectionSheet $sheet, string $reason): void
    {
        DB::transaction(function () use ($sheet, $reason) {
            foreach ($sheet->transactions()->whereNull('cancelled_at')->get() as $t) {
                $this->ledger->cancel($t, __('Feuille de collecte annulée : :r', ['r' => $reason]), fromOwner: true);
            }
            $sheet->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
        });
    }
}
