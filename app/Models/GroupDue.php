<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** La cotisation d'un mois payée par un membre du groupe. */
class GroupDue extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date'];
    }
}
