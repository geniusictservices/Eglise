<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une réunion : participants, ordre du jour, procès-verbal et décisions. */
class Meeting extends Model
{
    use Auditable, BelongsToOrganization;

    public const KINDS = ['council' => 'Conseil', 'committee' => 'Comité', 'department' => 'Département', 'assembly' => 'Assemblée générale', 'other' => 'Autre'];

    public const ATTENDANCE = ['present' => 'Présent', 'excused' => 'Excusé', 'absent' => 'Absent'];

    protected $guarded = ['id'];

    protected $attributes = ['kind' => 'council', 'status' => 'planned'];

    protected function casts(): array
    {
        return ['held_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class)->orderBy('id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(MeetingDecision::class)->orderBy('id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
