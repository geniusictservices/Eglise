<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une rencontre du groupe et ses présences. */
class GroupMeeting extends Model
{
    use Auditable, BelongsToOrganization;

    public const STATUSES = ['present' => 'Présent', 'excused' => 'Excusé', 'absent' => 'Absent'];

    protected $guarded = ['id'];

    protected $attributes = ['visitors' => 0];

    protected function casts(): array
    {
        return ['held_on' => 'date', 'visitors' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(GroupAttendance::class);
    }

    public function presentCount(): int
    {
        return $this->attendances->where('status', 'present')->count();
    }
}
