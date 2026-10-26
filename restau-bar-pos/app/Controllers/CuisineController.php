<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acces;
use App\Core\Journal;
use App\Core\Plateforme;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CuisineRepository;
use App\Repositories\EtablissementRepository;

/**
 * Écran de cuisine (cahier des charges, section 8.2) : /caisse/cuisine (règles d'abonnement de la caisse : jamais coupé en plein service), en plein écran, un bon par envoi d'une note
 * à un poste, de grandes cartes (reçu → en préparation → prêt → servi), temps écoulé et alerte de retard,
 * plats à suivre, précisions et allergies en évidence, son facultatif, impression du bon (80 mm), ruptures.
 *
 * Deux façons d'ouvrir l'écran :
 * - un ÉCRAN AUTORISÉ (tablette fixée en cuisine) : un gérant (droit « cuisine.parametrer » ou
 *   « caisse.appareils ») l'autorise une fois ; l'écran garde un jeton (cookie) et s'affiche sans connexion ni PIN
 *   (mains occupées) ; il se retire dans Cuisine > Postes et écrans ;
 * - une personne CONNECTÉE à la gestion avec « cuisine.voir » (chef sur son téléphone, gérant).
 * Bloquer un plat en rupture : « cuisine.ruptures » pour une personne connectée, permis sur un écran autorisé.
 */
final class CuisineController
{
    private CuisineRepository $cuisine;

    public function __construct()
    {
        $this->cuisine = new CuisineRepository();
    }

    public function index(): void
    {
        $acces = $this->acces(false, true);
        if ($acces === null) {
            return;
        }
        ['etab' => $etab, 'etablissements' => $liste, 'ecran' => $ecran, 'utilisateur' => $utilisateur] = $acces;
        $postes = $this->cuisine->postes((int) $etab['id']);
        $poste = $this->posteChoisi($postes, $ecran);
        View::afficher('cuisine/index', [
            'titre' => t('cuisine.titre'),
            'etab' => $etab,
            'etablissements' => $liste,
            'postes' => $postes,
            'poste' => $poste,
            'ecran' => $ecran,
            'utilisateur' => $utilisateur,
            'peutAutoriser' => $ecran === null && $utilisateur !== null
                && (Acces::a((int) $utilisateur['id'], 'cuisine.parametrer', (int) $etab['id']) || Acces::a((int) $utilisateur['id'], 'caisse.appareils', (int) $etab['id'])),
            'peutRupture' => $ecran !== null || ($utilisateur !== null && Acces::a((int) $utilisateur['id'], 'cuisine.ruptures', (int) $etab['id'])),
            'scripts' => ['/assets/js/cuisine.js'],
        ], 'cuisine');
    }

    /** GET /caisse/cuisine/donnees?etab=&poste= : bons en cours (JSON), interrogé toutes les quelques secondes. */
    public function donnees(): void
    {
        ['etab' => $etab, 'ecran' => $ecran] = $this->acces(false);
        $postes = $this->cuisine->postes((int) $etab['id']);
        $poste = $this->posteChoisi($postes, $ecran);
        $this->repondre(200, [
            'bons' => $this->cuisine->bons((int) $etab['id'], $poste !== null ? (int) $poste['id'] : null),
            'maintenant' => date('Y-m-d H:i:s'),
        ]);
    }

    /** POST /caisse/cuisine/statut : bon = id (toutes ses lignes) ou lignes = [ids], statut = recu | preparation | pret | servi. */
    public function statut(): void
    {
        ['etab' => $etab] = $this->acces(true);
        $statut = is_string($_POST['statut'] ?? null) ? $_POST['statut'] : '';
        if (!in_array($statut, CuisineRepository::STATUTS, true)) {
            $this->repondre(422, ['erreur' => t('cuisine.statut_inconnu')]);
        }
        $ids = (int) ($_POST['bon'] ?? 0) > 0
            ? $this->cuisine->lignesDuBon((int) $etab['id'], (int) $_POST['bon'])
            : array_map('intval', json_decode(is_string($_POST['lignes'] ?? null) ? $_POST['lignes'] : '[]', true) ?: []);
        $n = $this->cuisine->changerStatut((int) $etab['id'], $ids, $statut);
        $this->repondre(200, ['changees' => $n, 'csrf' => Session::jetonCsrf()]);
    }

    /** GET /caisse/cuisine/ruptures?etab=&poste= : plats des postes et leur disponibilité. */
    public function ruptures(): void
    {
        ['etab' => $etab, 'ecran' => $ecran] = $this->acces(false);
        $poste = $this->posteChoisi($this->cuisine->postes((int) $etab['id']), $ecran);
        $this->repondre(200, ['produits' => $this->cuisine->produitsDesPostes((int) $etab['id'], $poste !== null ? (int) $poste['id'] : null)]);
    }

    /** POST /caisse/cuisine/rupture : produit, disponible (0 : rupture, le plat disparaît des caisses). */
    public function rupture(): void
    {
        ['etab' => $etab, 'ecran' => $ecran, 'utilisateur' => $utilisateur] = $this->acces(true);
        if ($ecran === null && !Acces::a((int) $utilisateur['id'], 'cuisine.ruptures', (int) $etab['id'])) {
            $this->repondre(403, ['erreur' => t('acces.interdit_titre')]);
        }
        $disponible = (string) ($_POST['disponible'] ?? '0') === '1';
        $produit = $this->cuisine->rupture((int) $etab['id'], (int) ($_POST['produit'] ?? 0), $disponible);
        if ($produit === null) {
            $this->repondre(404, ['erreur' => t('cuisine.produit_inconnu')]);
        }
        Journal::ecrire($disponible ? 'rupture_levee' : 'rupture', $produit['nom_fr'], ['etablissement' => $etab['nom'], 'ecran' => $ecran['nom'] ?? null],
            $utilisateur !== null ? (int) $utilisateur['id'] : null);
        $this->repondre(200, ['ok' => true, 'csrf' => Session::jetonCsrf()]);
    }

    /** GET /caisse/cuisine/bon/{id} : bon de cuisine à imprimer (HTML du bon, JSON). */
    public function bon(string $id): void
    {
        ['etab' => $etab] = $this->acces(false);
        $bon = ctype_digit($id) ? $this->cuisine->bon((int) $id) : null;
        if ($bon === null || (int) $bon['envoi']['etablissement_id'] !== (int) $etab['id']) {
            $this->repondre(404, ['erreur' => t('cuisine.bon_introuvable')]);
        }
        $this->repondre(200, ['bon' => View::rendre('cuisine/_bon', $bon)]);
    }

    /** POST /caisse/cuisine/autoriser : un gérant fait de ce navigateur un écran de cuisine (puis il est déconnecté). */
    public function autoriser(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $etab = (new EtablissementRepository())->parSlug((string) ($_POST['etab'] ?? ''));
        $uid = (int) $utilisateur['id'];
        if ($etab === null || !(Acces::a($uid, 'cuisine.parametrer', (int) $etab['id']) || Acces::a($uid, 'caisse.appareils', (int) $etab['id']))) {
            http_response_code(403);
            View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
            return;
        }
        $poste = (int) ($_POST['poste'] ?? 0);
        $poste = $poste > 0 && ($p = $this->cuisine->poste($poste)) !== null && (int) $p['etablissement_id'] === (int) $etab['id'] ? $poste : null;
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $nom = $nom === '' ? t('cuisine.ecran_nom_defaut', ['etablissement' => $etab['nom']]) : mb_substr($nom, 0, 80);
        $id = CuisineRepository::autoriser($nom, (int) $etab['id'], $poste, $uid);
        Journal::ecrire('ecran_cuisine_autorise', $nom, ['etablissement' => $etab['nom']], $uid);
        // Comme une caisse partagée : la session du gérant ne reste pas ouverte sur l'écran de la cuisine
        Session::deconnecter();
        unset($id);
        rediriger('/caisse/cuisine');
    }

    // ---------- Outils ----------

    /**
     * Écran autorisé (cookie) ou personne connectée avec « cuisine.voir ».
     * $page : pour la page HTML (redirection vers la connexion, page interdite) ; sinon JSON.
     * @return array{etab: array, etablissements: list<array>, ecran: ?array, utilisateur: ?array}|null
     */
    private function acces(bool $ecriture, bool $page = false): ?array
    {
        Session::demarrer();
        if ($ecriture) {
            $jeton = $_POST['csrf'] ?? '';
            if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
                $this->repondre(419, ['erreur' => t('encaisser.expire')]);
            }
        }
        if (!Plateforme::moduleInclus('cuisine')) {
            $page ? pageIntrouvable() : $this->repondre(403, ['erreur' => t('note.module_absent')]);
        }
        $etablissements = new EtablissementRepository();
        $ecran = CuisineRepository::ecran();
        if ($ecran !== null) {
            $etab = $etablissements->parId((int) $ecran['etablissement_id']);
            return ['etab' => $etab, 'etablissements' => [$etab], 'ecran' => $ecran, 'utilisateur' => null];
        }
        $utilisateur = Session::utilisateur();
        if ($utilisateur === null) {
            if ($page) {
                rediriger('/gestion/connexion');
            }
            $this->repondre(401, ['erreur' => t('encaisser.connexion')]);
        }
        $permis = Acces::etablissements((int) $utilisateur['id'], 'cuisine.voir');
        $liste = array_values(array_filter($etablissements->actifs(), static fn (array $e) => $permis === null || in_array((int) $e['id'], $permis, true)));
        if ($liste === []) {
            if ($page) {
                http_response_code(403);
                View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
                return null;
            }
            $this->repondre(403, ['erreur' => t('acces.interdit_titre')]);
        }
        $slug = $ecriture ? ($_POST['etab'] ?? null) : ($_GET['etab'] ?? null);
        $etab = $liste[0];
        foreach ($liste as $candidat) {
            if ($candidat['slug'] === $slug) {
                $etab = $candidat;
            }
        }
        return ['etab' => $etab, 'etablissements' => $liste, 'ecran' => null, 'utilisateur' => $utilisateur];
    }

    /** Poste affiché : celui de l'écran autorisé, sinon ?poste= (0 ou absent : tous). */
    private function posteChoisi(array $postes, ?array $ecran): ?array
    {
        $choix = isset($_GET['poste']) ? (int) $_GET['poste'] : ($ecran !== null && $ecran['poste_id'] !== null ? (int) $ecran['poste_id'] : 0);
        foreach ($postes as $p) {
            if ((int) $p['id'] === $choix) {
                return $p;
            }
        }
        return null;
    }

    private function repondre(int $code, array $donnees): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
