<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conditions d'utilisation et politique de confidentialité, versionnées. */
class LegalDocument extends Model
{
    use Auditable;

    public const KEYS = ['terms' => 'Conditions d’utilisation', 'privacy' => 'Politique de confidentialité'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /** Fichiers de départ, dans docs/legal. */
    public const FILES = ['terms' => 'conditions', 'privacy' => 'confidentialite'];

    /** Version publiée en vigueur (la première est reprise de docs/legal). */
    public static function current(string $key): ?self
    {
        static::ensureSeeded($key);

        return static::published()->where('key', $key)->orderByDesc('version')->first();
    }

    public static function draft(string $key): ?self
    {
        return static::whereNull('published_at')->where('key', $key)->orderByDesc('version')->first();
    }

    public static function ensureSeeded(string $key): void
    {
        if (static::where('key', $key)->exists()) {
            return;
        }

        $body = file_get_contents(base_path('docs/legal/'.self::FILES[$key].'.md'));
        $title = preg_match('/^# (.+)$/m', $body, $m) ? trim($m[1]) : self::KEYS[$key];
        $body = trim(preg_replace('/^# .+\R+/', '', $body, 1));

        static::create(['key' => $key, 'version' => 1, 'title' => $title, 'body' => $body, 'summary' => 'Première version', 'published_at' => now()]);
    }
}
