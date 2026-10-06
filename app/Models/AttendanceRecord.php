<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Les présences d'une date : effectifs, pointage nominatif et visiteurs, chacun facultatif. */
class AttendanceRecord extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurs_on' => 'date'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(AttendanceCheckin::class);
    }

    public function namedVisitors(): HasMany
    {
        return $this->hasMany(AttendanceVisitor::class)->orderBy('id');
    }
}
