<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DetteRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\GestionDetteRepository;

/**
 * Ventes > Clients : liste (recherche, type, avec dette, en retard, encours par devise), fiche (encours, dettes,
 * remboursements, messages), création et modification (clients.modifier). Les clients « personnel » se créent
 * seuls quand une dette est mise à la charge d'un membre du personnel : ils ne se modifient pas ici.
 */
final class ClientController
{
    private GestionDetteRepository $depot;

    public function __construct()
    {
        $this->depot = new GestionDetteRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $q = mb_substr(TresorerieSaisie::texte($_GET['q'] ?? null), 0, 64);
        $type = in_array($_GET['type'] ?? null, GestionDetteRepository::TYPES_CLIENT, true) ? $_GET['type'] : null;
        $filtres = ['q' => $q, 'type' => $type, 'avec_dette' => ($_GET['dette'] ?? '') === '1', 'retard' => ($_GET['retard'] ?? '') === '1'];
        $page = DetteAffichage::page();
        $liste = $this->depot->listeClients($filtres, $uid, $page);
        $pages = max(1, (int) ceil($liste['total'] / GestionDetteRepository::PAR_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $liste = $this->depot->listeClients($filtres, $uid, $page);
        }
        View::afficher('gestion/clients/index', [
            'titre' => t('gclients.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'filtres' => $filtres,
            'clients' => $liste['clients'],
            'nombre' => $liste['total'],
            'page' => $page,
            'pages' => $pages,
            'encoursTotal' => $this->depot->encoursTotal($filtres, $uid),
            'devises' => VenteController::devises(),
            'peutModifier' => Acces::a($uid, 'clients.modifier'),
        ], 'gestion');
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $fiche = $this->ficheOu404($id, $uid);
        View::afficher('gestion/clients/fiche', [
            'titre' => $fiche['client']['nom'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'fiche' => $fiche,
            'devises' => VenteController::devises(),
            'peutModifier' => Acces::a($uid, 'clients.modifier') && $fiche['client']['type'] !== 'personnel',
            'voirDettes' => Acces::a($uid, 'dettes.voir'),
            'peutRembourser' => Acces::a($uid, 'dettes.rembourser'),
            'voirVentes' => Acces::a($uid, 'ventes.voir'),
            'voirUtilisateurs' => Acces::a($uid, 'parametres.utilisateurs'),
        ], 'gestion');
    }

    public function nouveau(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->exigerModifier($utilisateur);
        $locale = (new DeviseRepository())->locale();
        $this->afficherFormulaire($utilisateur, null, [
            'nom' => mb_substr(TresorerieSaisie::texte($_GET['nom'] ?? null), 0, 128), 'telephone' => mb_substr(TresorerieSaisie::texte($_GET['telephone'] ?? null), 0, 32),
            'email' => '', 'anniversaire' => '', 'langue' => 'fr', 'type' => 'particulier', 'notes' => '', 'plafond' => '',
            'plafond_devise_id' => $locale ? (int) $locale['id'] : null, 'canal' => 'sms', 'relances' => true, 'bloque' => false,
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->exigerModifier($utilisateur);
        [$v, $erreurs, $plafond] = $this->lire();
        if ($erreurs === []) {
            try {
                $id = $this->depot->creerClient(['plafond' => $plafond] + $v, (int) $utilisateur['id']);
                Session::flash(t('gclients.cree_flash', ['nom' => $v['nom']]));
                rediriger('/gestion/clients/' . $id);
            } catch (\InvalidArgumentException $e) {
                $erreurs[$e->getMessage() === 'telephone_pris' ? 'telephone' : $e->getMessage()] = $this->message($e->getMessage(), $v);
            }
        }
        $this->afficherFormulaire($utilisateur, null, $v, $erreurs, 422);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->exigerModifier($utilisateur);
        $client = $this->clientModifiable($id, $utilisateur);
        $this->afficherFormulaire($utilisateur, $client, [
            'nom' => $client['nom'], 'telephone' => afficherTelephone($client['telephone']), 'email' => (string) $client['email'],
            'anniversaire' => (string) $client['anniversaire'], 'langue' => $client['langue'], 'type' => $client['type'],
            'notes' => (string) $client['notes'], 'plafond' => $client['plafond'] !== null ? TresorerieAffichage::saisie($client['plafond']) : '',
            'plafond_devise_id' => $client['plafond_devise_id'] !== null ? (int) $client['plafond_devise_id'] : ((new DeviseRepository())->locale()['id'] ?? null),
            'canal' => $client['canal'], 'relances' => (bool) $client['relances'], 'bloque' => (bool) $client['bloque'],
        ]);
    }

    public function enregistrer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->exigerModifier($utilisateur);
        $client = $this->clientModifiable($id, $utilisateur);
        [$v, $erreurs, $plafond] = $this->lire();
        if ($erreurs === []) {
            try {
                $this->depot->modifierClient((int) $client['id'], ['plafond' => $plafond] + $v, (int) $utilisateur['id']);
                Session::flash(t('gclients.modifie_flash', ['nom' => $v['nom']]));
                rediriger('/gestion/clients/' . (int) $client['id']);
            } catch (\InvalidArgumentException $e) {
                $erreurs[$e->getMessage() === 'telephone_pris' ? 'telephone' : $e->getMessage()] = $this->message($e->getMessage(), $v);
            }
        }
        $this->afficherFormulaire($utilisateur, $client, $v, $erreurs, 422);
    }

    /**
     * Lit le formulaire : valeurs, erreurs (messages) et plafond converti (null = pas de plafond).
     * @return array{0: array, 1: array<string, string>, 2: ?float}
     */
    private function lire(): array
    {
        [$v, $codes] = GestionDetteRepository::lireClient($_POST);
        $erreurs = [];
        foreach ($codes as $champ => $code) {
            $erreurs[$champ] = $this->message($code, $v);
        }
        $plafond = null;
        if ($v['plafond'] !== '') {
            $devises = (new DeviseRepository())->actives();
            $devise = $v['plafond_devise_id'] !== null ? ($devises[$v['plafond_devise_id']] ?? null) : null;
            if ($devise === null) {
                $erreurs['plafond_devise_id'] = t('gclients.erreur_plafond_devise');
            } else {
                [$plafond, $erreur] = TresorerieSaisie::montant($v['plafond'], $devise, true);
                if ($erreur !== null) {
                    $erreurs['plafond'] = $erreur;
                }
            }
        }
        return [$v, $erreurs, $plafond];
    }

    private function message(string $code, array $v): string
    {
        if ($code === 'telephone_pris') {
            $autre = (new DetteRepository())->clientParTelephone((string) DetteRepository::normaliserTelephone($v['telephone']));
            return t('gclients.erreur_telephone_pris', ['nom' => $autre['nom'] ?? '?', 'telephone' => afficherTelephone($autre['telephone'] ?? '')]);
        }
        return t('gclients.erreur_' . $code, ['n' => $code === 'nom_long' ? 128 : 5000]);
    }

    private function afficherFormulaire(array $utilisateur, ?array $client, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/clients/formulaire', [
            'titre' => $client ? t('gclients.modifier_titre', ['nom' => $client['nom']]) : t('gclients.nouveau'),
            'utilisateur' => $utilisateur,
            'client' => $client,
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'devises' => (new DeviseRepository())->actives(),
        ], 'gestion');
    }

    private function exigerModifier(array $utilisateur): void
    {
        if (!Acces::a((int) $utilisateur['id'], 'clients.modifier')) {
            DetteAffichage::interdit($utilisateur);
        }
    }

    private function ficheOu404(string $id, int $uid): array
    {
        $fiche = ctype_digit($id) && strlen($id) < 11 ? $this->depot->ficheClient((int) $id, $uid) : null;
        if ($fiche === null) {
            pageIntrouvable();
        }
        return $fiche;
    }

    /** Client à modifier : existant, et pas un client « personnel » (lecture seule, 409). */
    private function clientModifiable(string $id, array $utilisateur): array
    {
        $client = ctype_digit($id) && strlen($id) < 11 ? $this->depot->dettes()->client((int) $id) : null;
        if ($client === null) {
            pageIntrouvable();
        }
        if ($client['type'] === 'personnel') {
            http_response_code(409);
            Session::flash(t('gclients.personnel_lecture_seule'));
            $fiche = $this->depot->ficheClient((int) $client['id'], (int) $utilisateur['id']);
            View::afficher('gestion/clients/fiche', [
                'titre' => $client['nom'],
                'utilisateur' => $utilisateur,
                'flash' => Session::lireFlash(),
                'fiche' => $fiche,
                'devises' => VenteController::devises(),
                'peutModifier' => false,
                'voirDettes' => Acces::a((int) $utilisateur['id'], 'dettes.voir'),
                'peutRembourser' => Acces::a((int) $utilisateur['id'], 'dettes.rembourser'),
                'voirVentes' => Acces::a((int) $utilisateur['id'], 'ventes.voir'),
                'voirUtilisateurs' => Acces::a((int) $utilisateur['id'], 'parametres.utilisateurs'),
            ], 'gestion');
            exit;
        }
        return $client;
    }
}
