<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;

/**
 * Inscrit automatiquement au journal d'audit chaque création, modification
 * et suppression du modèle, avec les valeurs avant et après.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => AuditLogger::$muted ? null : app(AuditLogger::class)->record(
            'created', $model, [], $model->auditableValues($model->getAttributes())
        ));

        static::updated(function ($model) {
            $changes = $model->auditableValues($model->getChanges());
            unset($changes['updated_at']);

            if ($changes === [] || AuditLogger::$muted) {
                return;
            }

            $original = $model->getRawOriginal();
            $before = array_map(fn ($key) => $original[$key] ?? null, array_combine(array_keys($changes), array_keys($changes)));
            app(AuditLogger::class)->record('updated', $model, $before, $changes);
        });

        static::deleted(fn ($model) => AuditLogger::$muted ? null : app(AuditLogger::class)->record(
            'deleted', $model, $model->auditableValues($model->getAttributes()), []
        ));
    }

    /** Retire les champs sensibles (mot de passe, jetons) avant journalisation. */
    public function auditableValues(array $values): array
    {
        $hidden = array_merge($this->getHidden(), ['password', 'remember_token', 'created_at', 'updated_at']);

        return array_diff_key($values, array_flip($hidden));
    }

    /** Organisation à laquelle rattacher la ligne du journal. */
    public function auditOrganizationId(): ?int
    {
        return $this->organization_id ?? null;
    }
}
