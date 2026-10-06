<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un compte de la communauté : caisse physique, mobile money ou banque.
 * Il tient une ou plusieurs devises, chacune avec son solde.
 */
class CashAccount extends Model
{
    use Auditable, BelongsToOrganization;

    public const KINDS = [
        'cash' => 'Caisse physique',
        'mobile' => 'Mobile money',
        'bank' => 'Banque',
    ];

    /** Opérateurs et banques proposés (la communauté peut en saisir d'autres). */
    public const PROVIDERS = [
        'mobile' => ['M-Pesa (Vodacom)', 'Airtel Money', 'Orange Money', 'Afrimoney'],
        'bank' => ['Rawbank', 'Equity BCDC', 'TMB (Trust Merchant Bank)', 'FirstBank', 'Access Bank', 'Ecobank', 'Standard Bank', 'Sofibanque', 'UBA', 'BOA', 'Solidaire Banque', 'Advans Banque', 'FINCA'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function currencies(): HasMany
    {
        return $this->hasMany(CashAccountCurrency::class)->orderByRaw("currency = 'USD' DESC")->orderBy('currency');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class);
    }

    public function icon(): string
    {
        return ['cash' => 'banknote', 'mobile' => 'smartphone', 'bank' => 'landmark'][$this->kind] ?? 'wallet';
    }

    /** « Caisse principale », « M-Pesa · 0812… », « Rawbank · 0501… » */
    public function label(): string
    {
        return $this->name;
    }
}
