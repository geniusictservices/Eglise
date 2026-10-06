<?php

namespace App\Services;

use App\Jobs\SendPushNotification;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Les nouveautés : une ligne par destinataire, non lue tant qu'il n'a pas
 * ouvert la page concernée. Chaque nouveauté porte une clé (« expense.12.approve ») :
 * quand l'objet est réglé par quelqu'un, elle se range chez tous.
 */
class Notifier
{
    /**
     * Les personnes qui ont cette permission dans la communauté elle-même
     * (pas les responsables des niveaux supérieurs), sauf l'auteur de l'action.
     *
     * @return Collection<int, User>
     */
    /** L'équipe Genius ICT qui a cette permission de l'espace d'administration. */
    public function staffWith(string $permission): Collection
    {
        return User::where('is_platform_staff', true)->where('is_active', true)->get()->filter(fn (User $u) => $u->hasPlatformPermission($permission))->values();
    }

    public function withPermission(Organization $organization, string $permission): Collection
    {
        return RoleAssignment::with(['role', 'user'])->where('organization_id', $organization->id)->get()
            ->filter(fn (RoleAssignment $a) => $a->user?->is_active && $a->role->grants($permission))
            ->pluck('user')->unique('id')->values();
    }

    /**
     * @param  iterable<User|int|null>|User|int|null  $recipients
     * @param  array{title: string, body?: ?string, url: string, icon?: string}  $content
     */
    /** Sans organisation : une nouveauté de l'espace Genius ICT, pour son équipe. */
    public function send(?Organization $organization, iterable|User|int|null $recipients, string $key, array $content): int
    {
        $ids = collect(is_iterable($recipients) ? $recipients : [$recipients])
            ->map(fn ($r) => $r instanceof User ? $r->id : $r)->filter()->unique()
            ->reject(fn (int $id) => $id === auth()->id())->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        $path = '/'.ltrim((string) parse_url($content['url'], PHP_URL_PATH), '/');
        $data = ['title' => $content['title'], 'body' => $content['body'] ?? null, 'icon' => $content['icon'] ?? 'bell', 'url' => $path];

        foreach (User::whereIn('id', $ids)->where('is_active', true)->pluck('id') as $userId) {
            // La même nouveauté encore non lue est remplacée, pas répétée.
            DatabaseNotification::where('notifiable_type', 'user')->where('notifiable_id', $userId)
                ->where('key', $key)->whereNull('read_at')->delete();
            $notification = DatabaseNotification::create([
                'id' => (string) Str::uuid(), 'type' => 'waumini', 'notifiable_type' => 'user', 'notifiable_id' => $userId,
                'organization_id' => $organization?->id, 'key' => $key, 'path' => $path, 'data' => $data,
            ]);
            if (config('waumini.push.public_key')) {
                SendPushNotification::dispatch($notification->id);
            }
        }

        return $ids->count();
    }

    /** L'objet est réglé : ses nouveautés se rangent chez tous. « expense.12.* » range toutes celles de la dépense. */
    public function settle(string $key): void
    {
        $query = DatabaseNotification::whereNull('read_at');
        str_ends_with($key, '*') ? $query->where('key', 'like', addcslashes(substr($key, 0, -1), '%_').'%') : $query->where('key', $key);
        $query->update(['read_at' => now()]);
    }

    /** L'utilisateur a ouvert cette page : ses nouveautés qui y mènent sont lues. */
    public function opened(User $user, string $path): void
    {
        DatabaseNotification::where('notifiable_type', 'user')->where('notifiable_id', $user->id)
            ->whereNull('read_at')->where('path', '/'.ltrim($path, '/'))->update(['read_at' => now()]);
    }

    public function unreadCount(User $user): int
    {
        return DatabaseNotification::where('notifiable_type', 'user')->where('notifiable_id', $user->id)->whereNull('read_at')->count();
    }
}
