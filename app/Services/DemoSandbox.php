<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Démo publique : chaque visiteur reçoit sa propre copie de la communauté
 * de démonstration, isolée des vraies communautés et effacée automatiquement
 * après quelques jours.
 */
class DemoSandbox
{
    /** Les fichiers d'une communauté : [table, colonne du chemin, colonne de la communauté]. */
    private const FILES = [
        ['organizations', 'logo_path', 'id'],
        ['members', 'photo_path', 'organization_id'],
        ['payment_declarations', 'screenshot_path', 'organization_id'],
        ['websites', 'cover_path', 'organization_id'],
        ['sermons', 'audio_path', 'organization_id'],
    ];

    public function __construct(private DemoCommunityBuilder $builder) {}

    public function days(): int
    {
        return (int) config('waumini.demo.days', 3);
    }

    /**
     * Crée un bac à sable et renvoie [communauté, administrateur, mot de passe].
     *
     * @return array{0: Organization, 1: User, 2: string}
     */
    public function create(): array
    {
        $active = Organization::where('is_demo', true)->whereNull('parent_id')->count();
        if ($active >= (int) config('waumini.demo.max_active', 300)) {
            throw new RuntimeException(__('La démonstration est très demandée en ce moment. Réessayez dans quelques heures.'));
        }

        // Numéros fictifs de la forme 01xx xxx xxx (+2431…) : réservés à la démo.
        do {
            $code = (string) random_int(100000, 999999);
        } while (User::where('phone', 'like', "+2431{$code}%")->exists());

        $password = 'Demo-'.random_int(1000, 9999);
        $expiresAt = now()->addDays($this->days());

        $siege = DB::transaction(fn () => $this->builder->build(
            fn (int $n) => sprintf('+2431%s%02d', $code, $n),
            $password,
            ['is_demo' => true],
            $expiresAt,
        ));

        $admin = User::where('phone', "+2431{$code}01")->firstOrFail();

        // Rappel des identifiants, pour revenir depuis un autre appareil.
        $siege->update(['settings' => array_merge($siege->settings ?? [], [
            'demo' => ['phone' => $admin->phone, 'password' => $password],
        ])]);

        return [$siege->fresh(), $admin, $password];
    }

    /** Numéro à taper sur l'écran de connexion : 0123 456 701 */
    public static function localPhone(string $e164): string
    {
        $local = '0'.substr($e164, 4);

        return trim(chunk_split(substr($local, 0, 4), 4, ' ').chunk_split(substr($local, 4), 3, ' '));
    }

    /** Efface les bacs à sable expirés. Renvoie le nombre de communautés effacées. */
    public function purgeExpired(): int
    {
        $count = 0;

        Organization::withTrashed()
            ->where('is_demo', true)
            ->whereNull('parent_id')
            ->where('demo_expires_at', '<', now())
            ->each(function (Organization $root) use (&$count) {
                $this->purge($root);
                $count++;
            });

        return $count;
    }

    public function purge(Organization $root): void
    {
        abort_unless($root->is_demo, 500, 'Seules les communautés de démonstration peuvent être purgées.');

        $files = [];
        DB::transaction(function () use ($root, &$files) {
            $organizations = Organization::withTrashed()->where('path', 'like', $root->path.'%')->get();
            $ids = $organizations->pluck('id');
            // Les fichiers déposés par le visiteur (logo, photos, audios…) partent avec la démo.
            foreach (self::FILES as [$table, $column, $owner]) {
                $files = array_merge($files, DB::table($table)->whereIn($owner, $ids)->whereNotNull($column)->pluck($column)->all());
            }
            $files = array_merge($files, DB::table('expense_attachments')->join('expense_requests', 'expense_requests.id', '=', 'expense_attachments.expense_request_id')
                ->whereIn('expense_requests.organization_id', $ids)->pluck('expense_attachments.path')->all());
            $userIds = User::where('is_demo', true)
                ->whereHas('roleAssignments', fn ($q) => $q->whereIn('organization_id', $ids))
                ->pluck('id');

            DB::table('audit_logs')->where('chain', 'demo')
                ->where(fn ($q) => $q->whereIn('organization_id', $ids)
                    ->orWhereIn('user_id', $userIds)
                    ->orWhere(fn ($q) => $q->where('subject_type', 'user')->whereIn('subject_id', $userIds)))
                ->delete();
            DB::table('attachment_requests')->whereIn('organization_id', $ids)->orWhereIn('target_id', $ids)->delete();
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->update(['current_organization_id' => null]);

            // Des feuilles vers la racine : un niveau ne peut pas disparaître avant ses niveaux inférieurs.
            $organizations->sortByDesc('depth')->each(fn (Organization $o) => DB::table('organizations')->where('id', $o->id)->delete());

            DB::table('notifications')->whereIn('notifiable_id', $userIds)->where('notifiable_type', 'user')->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        });
        Storage::disk('local')->delete(array_filter($files));
    }

    public static function isDemoPhone(?string $phone): bool
    {
        $phone = Phone::normalize($phone);

        return $phone !== null && str_starts_with($phone, '+2431') && strlen($phone) === 13;
    }
}
