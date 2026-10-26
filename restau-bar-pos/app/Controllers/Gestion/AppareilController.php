<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Session;
use App\Core\View;
use App\Repositories\EtablissementRepository;
use App\Repositories\GestionUtilisateurRepository;

/** Paramètres > Utilisateurs > Caisses partagées (réglages par établissement, appareils autorisés) et journal des actions. */
final class AppareilController
{
    private GestionUtilisateurRepository $depot;

    public function __construct()
    {
        $this->depot = new GestionUtilisateurRepository();
    }

    public function index(): void
    {
        $moi = Session::exigerConnexion();
        View::afficher('gestion/utilisateurs/appareils', [
            'titre' => t('appareils.titre'),
            'utilisateur' => $moi,
            'flash' => Session::lireFlash(),
            'appareils' => $this->depot->appareils(),
            'etablissements' => $this->etablissementsReglables((int) $moi['id']),
            'erreurs' => [],
        ], 'gestion');
    }

    /**
     * Réglages des caisses partagées d'un établissement (choisis par l'administrateur) :
     * message de l'écran de verrouillage, verrouillage obligatoire après chaque encaissement
     * et/ou après N minutes sans toucher l'écran, photo modifiable depuis la caisse.
     */
    public function reglages(string $id): void
    {
        $moi = Session::exigerConnexion();
        Session::verifierCsrf();
        $etab = ctype_digit($id) ? (new EtablissementRepository())->parId((int) $id) : null;
        if ($etab === null) {
            pageIntrouvable();
        }
        if (!Acces::a((int) $moi['id'], 'parametres.utilisateurs', (int) $etab['id'])) {
            http_response_code(403);
            View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $moi], 'gestion');
            return;
        }
        $texte = static fn (string $cle): ?string => ($v = mb_substr(trim((string) preg_replace('/\p{Cc}/u', ' ', is_string($_POST[$cle] ?? null) ? $_POST[$cle] : '')), 0, 255)) === '' ? null : $v;
        $minutes = (int) ($_POST['verrou_minutes'] ?? 0);
        if (!in_array($minutes, self::MINUTES, true)) {
            $minutes = 0;
        }
        Database::executer(
            'UPDATE etablissements SET verrou_apres_encaissement = ?, verrou_minutes = ?, message_verrou_fr = ?, message_verrou_en = ?, photo_a_la_caisse = ? WHERE id = ?',
            [(int) !empty($_POST['verrou_apres_encaissement']), $minutes, $texte('message_verrou_fr'), $texte('message_verrou_en'), (int) !empty($_POST['photo_a_la_caisse']), (int) $etab['id']]
        );
        Journal::ecrire('reglages_caisse', $etab['nom'], [
            'apres_encaissement' => !empty($_POST['verrou_apres_encaissement']), 'minutes' => $minutes, 'photo' => !empty($_POST['photo_a_la_caisse']),
        ]);
        Session::flash(t('verrou.enregistre', ['etablissement' => $etab['nom']]));
        rediriger('/gestion/utilisateurs/appareils#reglages-' . (int) $etab['id']);
    }

    /** Délais proposés pour le verrouillage sans toucher l'écran (0 = jamais). */
    public const MINUTES = [0, 1, 2, 5, 10, 15, 30];

    /** @return list<array> établissements dont la personne peut régler les caisses partagées */
    private function etablissementsReglables(int $moiId): array
    {
        return array_values(array_filter(
            Database::tous('SELECT * FROM etablissements WHERE actif = 1 ORDER BY nom'),
            static fn (array $e) => Acces::a($moiId, 'parametres.utilisateurs', (int) $e['id'])
        ));
    }

    /** Retirer un appareil : il ne peut plus servir de caisse partagée (tablette perdue, remplacée…). */
    public function retirer(string $id): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();
        $appareil = ctype_digit($id) ? $this->depot->appareil((int) $id) : null;
        if ($appareil === null) {
            pageIntrouvable();
        }
        $this->depot->retirerAppareil((int) $appareil['id']);
        Journal::ecrire('appareil_retire', $appareil['nom'], [], null, (int) $appareil['id']);
        Session::flash(t('appareils.retire', ['nom' => $appareil['nom']]));
        rediriger('/gestion/utilisateurs/appareils');
    }

    public function journal(): void
    {
        $moi = Session::exigerConnexion();
        $personne = isset($_GET['personne']) && ctype_digit((string) $_GET['personne']) ? (int) $_GET['personne'] : null;
        View::afficher('gestion/utilisateurs/journal', [
            'titre' => t('journal.titre'),
            'utilisateur' => $moi,
            'entrees' => $this->depot->journal($personne),
            'personnes' => $this->depot->tous(),
            'personne' => $personne,
        ], 'gestion');
    }
}
