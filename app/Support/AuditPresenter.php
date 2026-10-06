<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

/** Traduit les lignes du journal d'audit en phrases lisibles. */
class AuditPresenter
{
    private const SUBJECTS = [
        'organization' => ['la communauté', 'le niveau'],
        'user' => ['l’utilisateur', 'l’utilisateur'],
        'role' => ['le rôle', 'le rôle'],
        'role_assignment' => ['une attribution de rôle', 'une attribution de rôle'],
        'organization_currency' => ['une devise', 'une devise'],
        'exchange_rate' => ['un taux de change', 'un taux de change'],
        'attachment_request' => ['une demande de rattachement', 'une demande de rattachement'],
    ];

    private const FIELDS = [
        'name' => 'Nom', 'short_name' => 'Nom court', 'phone' => 'Téléphone', 'email' => 'E-mail',
        'city' => 'Ville', 'province' => 'Province', 'address' => 'Adresse', 'locale' => 'Langue',
        'timezone' => 'Fuseau horaire', 'level_label' => 'Niveau', 'status' => 'Statut',
        'terminology' => 'Libellés', 'permissions' => 'Permissions', 'description' => 'Description',
        'rate' => 'Taux', 'currency' => 'Devise', 'effective_on' => 'Date', 'is_active' => 'Actif',
        'includes_descendants' => 'Niveaux inférieurs', 'parent_id' => 'Niveau supérieur',
        'role_id' => 'Rôle', 'organization_id' => 'Communauté', 'user_id' => 'Utilisateur',
        'support_access_until' => 'Accès du support', 'must_change_password' => 'Changement de mot de passe',
    ];

    public static function sentence(AuditLog $log): string
    {
        if ($log->description) {
            return $log->description;
        }

        $values = ($log->new_values ?: $log->old_values) ?? [];

        if ($log->subject_type === 'exchange_rate' && isset($values['currency'], $values['rate']) && $log->event !== 'deleted') {
            return __('a enregistré le taux :currency : :rate', [
                'currency' => $values['currency'],
                'rate' => Money::rate((string) $values['rate'], $values['currency']),
            ]);
        }

        if ($log->subject_type === 'role_assignment' && isset($values['role_id'], $values['user_id'])) {
            $role = Role::find($values['role_id'])?->name ?? '?';
            $user = User::find($values['user_id'])?->name ?? '?';

            return $log->event === 'deleted'
                ? __('a retiré le rôle :role à :user', ['role' => $role, 'user' => $user])
                : __('a attribué le rôle :role à :user', ['role' => $role, 'user' => $user]);
        }

        if ($log->subject_type === 'organization_currency' && isset($values['currency']) && $log->event === 'created') {
            return __('a ajouté la devise :currency', ['currency' => $values['currency']]);
        }

        $subject = __(self::SUBJECTS[$log->subject_type][0] ?? 'un élément');
        $name = $log->new_values['name'] ?? $log->old_values['name'] ?? null;
        $label = $name ? "{$subject} « {$name} »" : $subject;

        return match ($log->event) {
            'created' => __('a créé :subject', ['subject' => $label]),
            'updated' => __('a modifié :subject', ['subject' => $label]),
            'deleted' => __('a supprimé :subject', ['subject' => $label]),
            default => $log->event,
        };
    }

    public static function field(string $key): string
    {
        return __(self::FIELDS[$key] ?? $key);
    }

    public static function value(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? __('oui') : __('non'),
            is_array($value) => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $value)) ?: '—',
            default => (string) $value,
        };
    }
}
