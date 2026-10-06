<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/** Ce qu'un niveau demande à ses niveaux inférieurs : un pourcentage de leurs recettes, ou un montant fixe par mois. */
class QuotaRule extends Model
{
    use Auditable;

    public const MODES = ['percent' => 'Un pourcentage des recettes', 'fixed' => 'Un montant fixe par mois'];

    protected $guarded = ['id'];

    public function describe(): string
    {
        return $this->mode === 'percent'
            ? __(':p % des recettes du mois', ['p' => rtrim(rtrim((string) $this->percent, '0'), '.')])
            : __(':m par mois', ['m' => Money::format($this->amount, $this->currency)]);
    }

    public function auditOrganizationId(): ?int
    {
        return $this->organization_id;
    }
}
