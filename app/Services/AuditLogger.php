<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Écrit dans le journal d'audit chaîné.
 *
 * Chaque ligne contient l'empreinte SHA-256 de son contenu et de la ligne
 * précédente : effacer ou retoucher une ligne casse la chaîne, ce que
 * verify() détecte.
 */
class AuditLogger
{
    /** @var array<int, bool> */
    private array $demoOrganizations = [];

    public function __construct(private CurrentOrganization $current) {}

    /** Les actions dans un bac à sable de démo vont dans la chaîne « demo ». */
    private function chainFor(?Model $subject, ?int $organizationId): string
    {
        if ($subject instanceof User && $subject->is_demo) {
            return 'demo';
        }

        if ($subject instanceof Organization && $subject->is_demo) {
            return 'demo';
        }

        if ($organizationId === null) {
            return 'main';
        }

        $this->demoOrganizations[$organizationId] ??= (bool) Organization::withTrashed()->whereKey($organizationId)->value('is_demo');

        return $this->demoOrganizations[$organizationId] ? 'demo' : 'main';
    }

    /** Vrai pendant une opération de masse journalisée en une seule ligne (import). */
    public static bool $muted = false;

    /** Exécute sans journaliser chaque ligne : l'appelant inscrit lui-même un résumé. */
    public static function quietly(callable $callback): mixed
    {
        $previous = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $previous;
        }
    }

    public function record(string $event, ?Model $subject = null, array $old = [], array $new = [], ?string $description = null, ?int $organizationId = null): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        $organizationId ??= $subject && method_exists($subject, 'auditOrganizationId')
            ? $subject->auditOrganizationId()
            : null;
        $organizationId ??= $this->current->id();

        $data = [
            'chain' => $this->chainFor($subject, $organizationId),
            'organization_id' => $organizationId,
            'user_id' => auth()->id(),
            'event' => $event,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        return DB::transaction(function () use ($data) {
            $previous = AuditLog::query()->where('chain', $data['chain'])->orderByDesc('id')->lockForUpdate()->value('hash');
            $data['previous_hash'] = $previous;
            $data['hash'] = self::hash($data);

            return AuditLog::create($data);
        });
    }

    public static function hash(array $data): string
    {
        $payload = [
            $data['previous_hash'] ?? '',
            $data['organization_id'] ?? '',
            $data['user_id'] ?? '',
            $data['event'],
            $data['subject_type'] ?? '',
            $data['subject_id'] ?? '',
            $data['description'] ?? '',
            self::canonicalJson($data['old_values'] ?? null),
            self::canonicalJson($data['new_values'] ?? null),
            $data['created_at'],
        ];

        return hash('sha256', implode('|', array_map('strval', $payload)));
    }

    /** JSON aux clés triées : MySQL réordonne les clés des colonnes JSON. */
    private static function canonicalJson(mixed $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (is_array($v)) {
                ksort($v);

                return array_map($sort, $v);
            }

            return $v;
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Vérifie la chaîne des vraies communautés. Renvoie l'identifiant de la première ligne
     * altérée, ou null si le journal est intact.
     */
    public function verify(): ?int
    {
        $previous = null;

        foreach (AuditLog::query()->where('chain', 'main')->orderBy('id')->cursor() as $log) {
            $data = [
                'previous_hash' => $log->previous_hash,
                'organization_id' => $log->organization_id,
                'user_id' => $log->user_id,
                'event' => $log->event,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'description' => $log->description,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->getRawOriginal('created_at'),
            ];

            if ($log->previous_hash !== $previous || ! hash_equals($log->hash, self::hash($data))) {
                return $log->id;
            }

            $previous = $log->hash;
        }

        return null;
    }
}
