<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un visiteur venu à une activité : à accueillir, puis à revoir. */
class AttendanceVisitor extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['followed_up_at' => 'datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }
}
