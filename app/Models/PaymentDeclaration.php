<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un paiement mobile money déclaré, en attente de vérification par la finance. */
class PaymentDeclaration extends Model
{
    use Auditable, BelongsToOrganization;

    public const STATUSES = ['pending' => 'À vérifier', 'validated' => 'Validé', 'rejected' => 'Rejeté'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (PaymentDeclaration $d) {
            $d->transaction_reference = strtoupper(preg_replace('/\s+/', '', (string) $d->transaction_reference));
            if ($d->isDirty('declarant_phone') && $d->declarant_phone) {
                $d->declarant_phone = Phone::normalize($d->declarant_phone) ?? $d->declarant_phone;
            }
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }

    public function pledge(): BelongsTo
    {
        return $this->belongsTo(Pledge::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class, 'finance_transaction_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function declarantName(): string
    {
        return $this->member?->fullName() ?? (string) $this->declarant_name;
    }

    /** Le même ID de transaction a-t-il déjà servi (déclaration ou opération) ? */
    public function duplicates(): array
    {
        $ref = $this->transaction_reference;

        return [
            'declarations' => static::where('transaction_reference', $ref)->whereKeyNot($this->id)->where('status', '!=', 'rejected')->count(),
            'transactions' => FinanceTransaction::valid()->whereRaw('UPPER(REPLACE(external_reference, " ", "")) = ?', [$ref])->count(),
        ];
    }
}
