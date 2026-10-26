<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\Console;
use App\Console\Journal;
use App\Console\SessionConsole;
use App\Core\BaseConsole;

/** Mon compte (super admin) : changer son mot de passe (12 caractères au moins). */
final class CompteController extends Base
{
    public function index(): void
    {
        $admin = $this->admin();
        $this->afficher($admin);
    }

    public function motDePasse(): void
    {
        $admin = $this->poste();
        $actuel = is_string($_POST['actuel'] ?? null) ? $_POST['actuel'] : '';
        $nouveau = is_string($_POST['nouveau'] ?? null) ? $_POST['nouveau'] : '';
        $confirmation = is_string($_POST['confirmation'] ?? null) ? $_POST['confirmation'] : '';
        $ligne = BaseConsole::un('SELECT mot_de_passe FROM super_admins WHERE id = ?', [(int) $admin['id']]);
        $erreurs = [];
        if ($ligne === null || !password_verify($actuel, $ligne['mot_de_passe'])) {
            $erreurs['actuel'] = t('console.compte.erreur_actuel');
        }
        if (($erreur = Console::erreurMotDePasse($nouveau)) !== null) {
            $erreurs['nouveau'] = $erreur;
        } elseif (!hash_equals($nouveau, $confirmation)) {
            $erreurs['confirmation'] = t('console.compte.erreur_confirmation');
        }
        if ($erreurs !== []) {
            $this->afficher($admin, $erreurs, 422);
            return;
        }
        BaseConsole::executer('UPDATE super_admins SET mot_de_passe = ? WHERE id = ?', [password_hash($nouveau, PASSWORD_DEFAULT), (int) $admin['id']]);
        session_regenerate_id(true);
        Journal::ecrire('mot_de_passe_change', null, [], (int) $admin['id']);
        SessionConsole::flash(t('console.compte.change_flash'));
        rediriger('/compte');
    }

    private function afficher(array $admin, array $erreurs = [], int $code = 200): void
    {
        $this->vue('compte', [
            'titre' => t('console.compte.titre'),
            'compte' => BaseConsole::un('SELECT nom, email, derniere_connexion, cree_le FROM super_admins WHERE id = ?', [(int) $admin['id']]),
            'admins' => BaseConsole::tous('SELECT nom, email, actif, derniere_connexion FROM super_admins ORDER BY nom'),
            'erreurs' => $erreurs,
            'longueurMin' => Console::LONGUEUR_MIN,
        ], $code);
    }
}
