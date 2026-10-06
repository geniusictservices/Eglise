<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une demande d'aide adressée à Genius ICT. */
class SupportTicket extends Model
{
    public const CATEGORIES = [
        'question' => 'Une question sur l’utilisation',
        'problem' => 'Quelque chose ne marche pas',
        'data' => 'Reprise ou correction de données',
        'billing' => 'Abonnement et paiement',
        'idea' => 'Une idée pour Waumini',
    ];

    /** open : en attente de Genius ICT ; answered : en attente de la communauté ; closed : réglé. */
    public const STATUSES = ['open' => 'En attente du support', 'answered' => 'Réponse reçue', 'closed' => 'Réglé'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open', 'category' => 'question'];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->oldest('id');
    }
}
