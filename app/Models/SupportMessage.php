<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un message d'un ticket : de la communauté ou de Genius ICT. */
class SupportMessage extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['from_staff' => false];

    protected function casts(): array
    {
        return ['from_staff' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
