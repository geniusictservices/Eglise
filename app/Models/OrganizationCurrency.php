<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Une devise utilisée par une organisation, en plus du dollar. */
class OrganizationCurrency extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function name(): string
    {
        return config("waumini.currencies.{$this->currency}.name", $this->currency);
    }
}
