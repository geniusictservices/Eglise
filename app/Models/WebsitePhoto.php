<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Une photo de la galerie du site vitrine. */
class WebsitePhoto extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];
}
