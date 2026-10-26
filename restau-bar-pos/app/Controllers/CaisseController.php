<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acces;
use App\Core\Avatars;
use App\Core\CaissePartagee;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CatalogueRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;

/**
 * Écran de caisse : produits en grille et ticket en cours.
 * L'écran s'adapte au type d'établissement (mode comptoir rapide et thème sombre pour un nightclub).
 * L'enregistrement des ventes et l'encaissement arrivent en phase 3.
 */
final class CaisseController
{
    public function index(): void
    {
        $etablissements = new EtablissementRepository();
        $appareil = CaissePartagee::appareil();

        if ($appareil !== null) {
            // Caisse partagée : l'établissement est celui de l'appareil ; chacun entre avec son PIN
            $etab = $etablissements->parSlug($appareil['slug']);
            if ($etab === null) {
                pageIntrouvable();
            }
            $caissier = CaissePartagee::caissier();
            if ($caissier !== null && !Acces::a($caissier['id'], 'caisse.vendre', (int) $etab['id'])) {
                CaissePartagee::verrouiller();
                $caissier = null;
            }
            if ($caissier === null) {
                // ?personne=<id> : pavé du PIN de cette personne (sans JavaScript)
                $choisi = (int) ($_GET['personne'] ?? 0);
                $this->afficherQui($appareil, $etab, null, $choisi > 0 ? $choisi : null);
                return;
            }
            $personne = $caissier;
            $liste = [$etab];
        } else {
            // Appareil personnel : il faut être connecté à la gestion avec le droit de vendre
            $utilisateur = Session::exigerConnexion();
            $permis = Acces::etablissements((int) $utilisateur['id'], 'caisse.vendre');
            $liste = array_values(array_filter(
                $etablissements->actifs(),
                static fn (array $e) => $permis === null || in_array((int) $e['id'], $permis, true)
            ));
            if ($liste === []) {
                http_response_code(403);
                View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
                return;
            }
            $etab = $liste[0];
            if (isset($_GET['e']) && is_string($_GET['e'])) {
                foreach ($liste as $candidat) {
                    if ($candidat['slug'] === $_GET['e']) {
                        $etab = $candidat;
                    }
                }
            }
            $personne = ['id' => (int) $utilisateur['id'], 'nom' => $utilisateur['nom']];
        }
        $id = (int) $etab['id'];
        $devises = new DeviseRepository();

        $zones = $etablissements->zones($id);
        $zone = null;
        if (isset($_GET['zone']) && is_string($_GET['zone'])) {
            $zone = $etablissements->zoneParCode($id, $_GET['zone']);
        }
        $grilleDefaut = (int) $etab['grille_prix_id'];
        $grille = $zone && $zone['grille_prix_id'] ? (int) $zone['grille_prix_id'] : $grilleDefaut;

        $acceptees = $devises->accepteesParmi($etablissements->deviseIds($id));
        // Tickets en devise locale, le dollar en deuxième position (ordre de DeviseRepository::actives())
        $locale = $devises->locale();
        $principale = ($locale ? ($acceptees[(int) $locale['id']] ?? null) : null)
            ?? $acceptees[(int) $etab['devise_affichage_id']] ?? $devises->reference() ?? reset($acceptees);

        $donnees = $this->donnees($id, $grille, $grilleDefaut, $acceptees, $principale, $etab['type']);
        $personne['avatar'] = Database::un('SELECT avatar FROM utilisateurs WHERE id = ?', [$personne['id']])['avatar'] ?? null;
        Session::demarrer();
        $messagePhoto = $_SESSION['caisse_photo'] ?? null;
        unset($_SESSION['caisse_photo']);
        View::afficher('caisse/index', [
            'titre' => t('caisse.titre'),
            'utilisateur' => $personne,
            'partagee' => $appareil !== null,
            'appareil' => $appareil,
            // « Revenir aux modules » seulement pour qui a des modules de gestion
            'aLaGestion' => Acces::aLaGestion($personne['id']),
            'peutAutoriser' => $appareil === null && Acces::a($personne['id'], 'caisse.appareils', $id),
            'photoPermise' => (bool) $etab['photo_a_la_caisse'],
            'scripts' => ['/assets/js/caisse.js', '/assets/js/photo-profil.js'],
            'messagePhoto' => $messagePhoto,
            'etab' => $etab,
            'etablissements' => $liste,
            'zones' => $zones,
            'zone' => $zone,
            // Ticket gardé par personne : un serveur ne retrouve pas le ticket du précédent
            'regle' => $appareil !== null ? CaissePartagee::regle($etab) : null,
            // Verrouillage choisi par l'administrateur (caisses partagées seulement)
            'donnees' => $donnees + ['cleTicket' => $etab['slug'] . '.' . $personne['id'], 'verrou' => [
                'minutes' => $appareil !== null ? (int) $etab['verrou_minutes'] : 0,
                'apresEncaissement' => $appareil !== null && (bool) $etab['verrou_apres_encaissement'],
            ]],
        ], 'caisse');
    }

    /** Connexion par PIN sur une caisse partagée. */
    public function pin(): void
    {
        Session::verifierCsrf();
        $appareil = CaissePartagee::appareil();
        if ($appareil === null) {
            http_response_code(403);
            exit(t('caisse_pin.appareil_requis'));
        }
        $etab = (new EtablissementRepository())->parSlug($appareil['slug']);
        if ($etab === null) {
            pageIntrouvable();
        }
        $appareilId = (int) $appareil['id'];
        if (CaissePartagee::appareilSature($appareilId)) {
            $this->afficherQui($appareil, $etab, t('caisse_pin.appareil_sature'), null, 429);
            return;
        }
        $utilisateurId = (int) ($_POST['utilisateur_id'] ?? 0);
        $pin = (string) ($_POST['pin'] ?? '');
        $personne = $utilisateurId > 0
            ? Database::un('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1 AND pin IS NOT NULL', [$utilisateurId])
            : null;
        if ($personne === null || !Acces::a($utilisateurId, 'caisse.vendre', (int) $etab['id'])) {
            $this->afficherQui($appareil, $etab, t('caisse_pin.choisir'), null, 422);
            return;
        }
        if ($personne['pin_bloque_le'] !== null) {
            $this->afficherQui($appareil, $etab, t('caisse_pin.bloque', ['nom' => $personne['nom']]), $utilisateurId, 423);
            return;
        }
        if (!CaissePartagee::pinValide($pin) || !password_verify($pin, $personne['pin'])) {
            $essais = (int) $personne['pin_essais'] + 1;
            $bloque = $essais >= CaissePartagee::ESSAIS_PIN;
            Database::executer(
                'UPDATE utilisateurs SET pin_essais = ?, pin_bloque_le = ? WHERE id = ?',
                [$bloque ? 0 : $essais, $bloque ? date('Y-m-d H:i:s') : null, $utilisateurId]
            );
            Database::executer('UPDATE appareils_caisse SET pin_echecs = pin_echecs + 1 WHERE id = ?', [$appareilId]);
            Journal::ecrire($bloque ? 'pin_bloque' : 'pin_echec', $personne['nom'], [], $utilisateurId, $appareilId);
            if ($bloque) {
                // Compte aussi comme un échec pour l'appareil
                Journal::ecrire('pin_echec', $personne['nom'], [], $utilisateurId, $appareilId);
            }
            $message = $bloque
                ? t('caisse_pin.bloque', ['nom' => $personne['nom']])
                : t('caisse_pin.incorrect', ['reste' => CaissePartagee::ESSAIS_PIN - $essais]);
            $this->afficherQui($appareil, $etab, $message, $bloque ? null : $utilisateurId, 422);
            return;
        }
        Database::executer('UPDATE utilisateurs SET pin_essais = 0 WHERE id = ?', [$utilisateurId]);
        CaissePartagee::connecter($personne, $appareil);
        Journal::ecrire('pin_connexion', $appareil['nom'], [], $utilisateurId, $appareilId);
        $zone = is_string($_POST['zone'] ?? null) && preg_match('/^[a-z0-9-]+$/', $_POST['zone']) ? $_POST['zone'] : '';
        rediriger('/caisse' . ($zone !== '' ? '?zone=' . $zone : ''));
    }

    /** Photo de profil prise sur la caisse (selfie), si l'établissement le permet. */
    public function photo(): void
    {
        Session::verifierCsrf();
        $appareil = CaissePartagee::appareil();
        if ($appareil !== null) {
            $personne = CaissePartagee::caissier();
            $etab = (new EtablissementRepository())->parSlug($appareil['slug']);
        } else {
            $utilisateur = Session::exigerConnexion();
            $personne = ['id' => (int) $utilisateur['id'], 'nom' => $utilisateur['nom']];
            $etab = (new EtablissementRepository())->parSlug(is_string($_GET['e'] ?? null) ? $_GET['e'] : '');
        }
        if ($personne === null || $etab === null || !$etab['photo_a_la_caisse'] || !Acces::a($personne['id'], 'caisse.vendre', (int) $etab['id'])) {
            http_response_code(403);
            exit(t('acces.interdit_titre'));
        }
        $erreur = Avatars::traiterFormulaire($personne['id'], $personne['nom']);
        $_SESSION['caisse_photo'] = $erreur ?? t('profil.photo_enregistree_moi');
        rediriger('/caisse' . ($appareil === null ? '?e=' . $etab['slug'] : ''));
    }

    /** Verrouille la caisse partagée : retour à « Qui êtes-vous ? » (après chaque encaissement, ou à la demande). */
    public function verrouiller(): void
    {
        Session::verifierCsrf();
        $caissier = CaissePartagee::caissier();
        if ($caissier !== null) {
            Journal::ecrire('caisse_verrouillee', null, [], $caissier['id'], $caissier['appareil_id']);
        }
        CaissePartagee::verrouiller();
        rediriger('/caisse');
    }

    /** Un gérant fait de cet appareil une caisse partagée de l'établissement. */
    public function autoriserAppareil(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $etab = (new EtablissementRepository())->parSlug((string) ($_POST['e'] ?? ''));
        if ($etab === null || !Acces::a((int) $utilisateur['id'], 'caisse.appareils', (int) $etab['id'])) {
            http_response_code(403);
            View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
            return;
        }
        $nom = trim((string) ($_POST['nom'] ?? ''));
        $nom = $nom === '' ? t('caisse_pin.nom_defaut', ['etablissement' => $etab['nom']]) : mb_substr($nom, 0, 80);
        $id = CaissePartagee::autoriser($nom, (int) $etab['id'], (int) $utilisateur['id']);
        Journal::ecrire('appareil_autorise', $nom, ['etablissement' => $etab['nom']], (int) $utilisateur['id'], $id);
        // Le gérant est déconnecté : la tablette attend maintenant le PIN de chacun
        Session::deconnecter();
        rediriger('/caisse');
    }

    /** Écran « Qui êtes-vous ? » : les personnes qui peuvent vendre ici, puis le pavé du PIN. */
    private function afficherQui(array $appareil, array $etab, ?string $erreur = null, ?int $choisi = null, int $code = 200): void
    {
        http_response_code($code);
        $personnes = [];
        foreach (Database::tous('SELECT id, nom, poste, avatar, pin_bloque_le FROM utilisateurs WHERE actif = 1 AND pin IS NOT NULL ORDER BY nom') as $ligne) {
            if (Acces::a((int) $ligne['id'], 'caisse.vendre', (int) $etab['id'])) {
                $personnes[] = $ligne;
            }
        }
        View::afficher('caisse/qui', [
            'titre' => t('caisse_pin.titre'),
            'etab' => $etab,
            'appareil' => $appareil,
            'personnes' => $personnes,
            'erreur' => $erreur,
            'choisi' => $choisi,
            'zone' => is_string($_GET['zone'] ?? null) ? $_GET['zone'] : (is_string($_POST['zone'] ?? null) ? $_POST['zone'] : ''),
            'scripts' => ['/assets/js/caisse-pin.js'],
        ], 'caisse');
    }

    /**
     * Catalogue de la caisse, avec le prix de chaque format dans chaque devise acceptée.
     * Les prix sont calculés côté serveur pour respecter les prix fixes et les arrondis de chaque devise.
     *
     * Onglets : catégories principales, chacune avec ses sous-catégories (« sous »).
     * Un produit à tailles est une seule tuile : ses formats sont ceux de toutes ses tailles,
     * chacun avec le libellé de sa taille (« Grande 72 cl ») et sa disponibilité.
     */
    private function donnees(int $etablissementId, int $grille, int $grilleDefaut, array $acceptees, array $principale, string $type): array
    {
        $catalogue = new CatalogueRepository(new DeviseRepository());
        $categories = [];
        $produits = [];

        foreach ($acceptees as $devise) {
            foreach ($catalogue->menu($etablissementId, $grille, $grilleDefaut, $devise, false) as $categorie) {
                $categories[$categorie['id']] ??= ['id' => $categorie['id'], 'nom' => champ($categorie, 'nom'), 'sous' => []];
                $groupes = [[$categorie, 0]];
                foreach ($categorie['sous_categories'] as $sous) {
                    $categories[$categorie['id']]['sous'][$sous['id']] ??= ['id' => $sous['id'], 'nom' => champ($sous, 'nom')];
                    $groupes[] = [$sous, (int) $sous['id']];
                }
                foreach ($groupes as [$groupe, $sousId]) {
                    foreach ($groupe['produits'] as $produit) {
                        $this->ajouterProduit($produits, $produit, (int) $categorie['id'], $sousId, $devise);
                    }
                }
            }
        }

        foreach ($produits as &$produit) {
            $produit['formats'] = array_values($produit['formats']);
        }
        unset($produit);
        foreach ($categories as &$categorie) {
            $categorie['sous'] = array_values($categorie['sous']);
        }
        unset($categorie);

        return [
            'mode' => $type === 'nightclub' ? 'rapide' : 'standard',
            'langue' => \App\Core\I18n::langue(),
            'principale' => $principale['code'],
            'devises' => array_values(array_map(static fn (array $d) => [
                'code' => $d['code'],
                'symbole' => $d['symbole'],
                'decimales' => (int) $d['decimales'],
                'symboleAvant' => (bool) $d['symbole_avant'],
            ], $acceptees)),
            'categories' => array_values($categories),
            'produits' => array_values($produits),
        ];
    }

    /** Ajoute un produit (et ses tailles) au catalogue de la caisse, avec ses prix dans une devise. */
    private function ajouterProduit(array &$produits, array $produit, int $categorieId, int $sousId, array $devise): void
    {
        $id = (int) $produit['id'];
        $recherche = $produit['nom_fr'] . ' ' . $produit['nom_en'];
        foreach ($produit['tailles'] as $taille) {
            $recherche .= ' ' . $taille['taille_fr'] . ' ' . $taille['taille_en'] . ' ' . $taille['contenance'];
        }
        $produits[$id] ??= [
            'id' => $id,
            'categorie' => $categorieId,
            'sous' => $sousId,
            'nom' => champ($produit, 'nom'),
            'recherche' => mb_strtolower($recherche),
            'disponible' => (bool) $produit['disponible'],
            'photo' => urlImageSure($produit['photo']),
            'tailles' => count($produit['tailles']),
            'formats' => [],
        ];
        // Formats du produit lui-même, puis ceux de chaque taille
        $sources = [[$produit, '', (bool) $produit['disponible']]];
        foreach ($produit['tailles'] as $taille) {
            $sources[] = [$taille, $taille['libelle_taille'], (bool) $taille['disponible']];
        }
        foreach ($sources as [$source, $libelleTaille, $disponible]) {
            foreach ($source['conditionnements'] as $cond) {
                $produits[$id]['formats'][$cond['id']] ??= [
                    'id' => (int) $cond['id'],
                    'nom' => champ($cond, 'nom'),
                    'unite' => (float) $cond['quantite_base'] === 1.0,
                    'taille' => $libelleTaille,
                    'tailleId' => (int) $source['id'],
                    'freres' => count($source['conditionnements']),
                    'disponible' => $disponible,
                    'prix' => [],
                ];
                $produits[$id]['formats'][$cond['id']]['prix'][$devise['code']] = $cond['montant'];
            }
        }
    }
}
