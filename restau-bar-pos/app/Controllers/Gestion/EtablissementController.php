<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Database;
use App\Core\Images;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;
use App\Repositories\GestionEtablissementRepository;
use App\Repositories\MediathequeRepository;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Établissements : liste, création, fiche en sections (informations, apparence, prix et devises,
 * horaires, zones, activation). Chaque section a son propre formulaire ; en cas d'erreur,
 * la fiche est réaffichée avec la saisie et le message sous le champ à corriger.
 *
 * Logo et couverture : choisis dans la médiathèque (champs cachés « logo_media », « couverture_media »)
 * ou envoyés par l'ancien champ fichier, alors ajoutés à la médiathèque. Remplacer ou retirer une image
 * ne supprime jamais le fichier : seule la médiathèque supprime une image inutilisée.
 */
final class EtablissementController
{
    private const COTE_LOGO = 600;
    private const COTE_COUVERTURE = 1920;

    private GestionEtablissementRepository $depot;
    private EtablissementRepository $etablissements;
    private DeviseRepository $devises;
    private EtablissementSaisie $saisie;

    public function __construct()
    {
        $this->depot = new GestionEtablissementRepository();
        $this->etablissements = new EtablissementRepository();
        $this->devises = new DeviseRepository();
        $this->saisie = new EtablissementSaisie($this->depot);
    }

    // Liste

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $maintenant = new DateTimeImmutable();
        $liste = [];
        foreach ($this->depot->liste() as $etab) {
            $horaires = $this->etablissements->horaires((int) $etab['id']);
            $etab['sans_horaires'] = $horaires === [];
            $etab['etat'] = $this->etablissements->etatOuverture($horaires, $maintenant);
            $liste[] = $etab;
        }
        View::afficher('gestion/etablissements/index', [
            'titre' => t('etab.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'etablissements' => $liste,
        ], 'gestion');
    }

    // Création

    public function nouveau(): void
    {
        $utilisateur = Session::exigerConnexion();
        $grilles = $this->depot->grilles();
        $reference = $this->devises->reference();
        $this->afficherNouveau($utilisateur, [
            'infos' => ['type' => 'restaurant_bar'],
            'prix' => [
                'grille_prix_id' => $this->grilleParDefaut($grilles),
                'devises' => array_keys($this->devises->actives()),
                'devise_affichage_id' => $reference ? (int) $reference['id'] : null,
                'credit_delai_jours' => 7,
            ],
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        [$infos, $erreursInfos] = $this->saisie->informations($_POST);
        [$prix, $erreursPrix] = $this->saisie->prix($_POST, $this->depot->grilles(), $this->devises->actives());
        if ($this->devises->actives() === []) {
            unset($erreursPrix['devises']);
        }
        if ($erreursInfos !== [] || $erreursPrix !== []) {
            $this->afficherNouveau($utilisateur, [
                'infos' => $infos,
                'prix' => $prix,
                'erreurs' => ['infos' => $erreursInfos, 'prix' => $erreursPrix],
            ], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $id = $this->depot->creer(EtablissementSaisie::pourBase($infos), (int) $prix['grille_prix_id'], $prix['devises'], $prix['devise_affichage_id'], (int) $prix['credit_delai_jours']);
            $pdo->commit();
        } catch (Throwable $erreur) {
            $pdo->rollBack();
            throw $erreur;
        }
        Session::flash(t('etab.cree', ['nom' => $infos['nom']]));
        rediriger('/gestion/etablissements/' . $id . '#apparence');
    }

    // Fiche

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherFiche($utilisateur, $this->etablissementOu404($id));
    }

    public function enregistrerInfos(string $id): void
    {
        [$utilisateur, $etab] = $this->debutPost($id);
        [$infos, $erreurs] = $this->saisie->informations($_POST, (int) $etab['id']);
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $etab, ['infos' => $infos, 'erreurs' => ['infos' => $erreurs]], 422);
            return;
        }
        $this->depot->modifierInfos((int) $etab['id'], EtablissementSaisie::pourBase($infos));
        $this->succes($etab, 'etab.infos_enregistrees', 'infos');
    }

    // Apparence : couleur, logo, couverture et son crédit

    public function enregistrerApparence(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $etab = $this->etablissementOu404($id);
        if (Images::requeteTropLourde()) {
            $this->afficherFiche($utilisateur, $etab, ['erreurs' => ['apparence' => [
                'fichier' => t('image.trop_lourde', ['max' => Images::tailleLisible(Images::octetsMax())]),
            ]]], 422);
            return;
        }
        Session::verifierCsrf();

        [$apparence, $erreurs] = $this->saisie->apparence($_POST);
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $etab, ['apparence' => $apparence, 'erreurs' => ['apparence' => $erreurs]], 422);
            return;
        }

        // Images : fichier envoyé (sans JavaScript), image choisie dans la médiathèque ou retrait.
        // Si l'une est refusée, rien n'est enregistré.
        $mediatheque = new MediathequeRepository();
        $tailles = ['logo' => self::COTE_LOGO, 'couverture' => self::COTE_COUVERTURE];
        $champs = [];
        foreach ($tailles as $colonne => $cote) {
            $champs[$colonne] = ChampImage::lire($colonne, $colonne . '_media', $etab[$colonne], $mediatheque);
            if ($champs[$colonne]['erreur'] !== null) {
                $this->afficherFiche($utilisateur, $etab, ['apparence' => $apparence, 'erreurs' => ['apparence' => [
                    $colonne => t('etab.' . $colonne) . ' : ' . $champs[$colonne]['erreur'],
                ]]], 422);
                return;
            }
        }

        $nouvelles = [];
        $envoyes = [];
        foreach ($champs as $colonne => $champ) {
            if ($champ['action'] === 'choisie') {
                $nouvelles[$colonne] = $champ['chemin'];
            } elseif ($champ['action'] === 'retiree') {
                $nouvelles[$colonne] = null;
            } elseif ($champ['action'] === 'fichier') {
                try {
                    $chemin = Images::enregistrer($champ['fichier'], 'etablissements', $tailles[$colonne], (int) $etab['id'] . '-' . $colonne);
                } catch (RuntimeException $erreur) {
                    foreach ($envoyes as $fichierEnvoye) {
                        Images::supprimer($fichierEnvoye['chemin']);
                    }
                    $this->afficherFiche($utilisateur, $etab, ['apparence' => $apparence, 'erreurs' => ['apparence' => [
                        $colonne => t('etab.' . $colonne) . ' : ' . $erreur->getMessage(),
                    ]]], 422);
                    return;
                }
                $nouvelles[$colonne] = $chemin;
                $envoyes[$colonne] = ['chemin' => $chemin, 'nom' => is_string($champ['fichier']['name'] ?? null) ? $champ['fichier']['name'] : ''];
            }
        }

        // Sans couverture, son crédit n'a plus d'objet
        $creditCouverture = array_key_exists('couverture', $nouvelles) && $nouvelles['couverture'] === null ? null : $apparence['couverture_credit'];
        $this->depot->definirCouleur((int) $etab['id'], $apparence['couleur'], $creditCouverture);
        foreach ($envoyes as $colonne => $fichierEnvoye) {
            // L'image envoyée par l'ancien champ fichier rejoint la médiathèque
            $mediatheque->ajouter($fichierEnvoye['chemin'], MediathequeRepository::nomLisible($fichierEnvoye['nom']), (int) $utilisateur['id'],
                $colonne === 'couverture' ? $apparence['couverture_credit'] : null);
        }
        foreach ($nouvelles as $colonne => $chemin) {
            $this->depot->definirImage((int) $etab['id'], $colonne, $chemin);
        }
        $this->succes($etab, 'etab.apparence_enregistree', 'apparence');
    }

    public function supprimerLogo(string $id): void
    {
        $this->supprimerImage($id, 'logo');
    }

    public function supprimerCouverture(string $id): void
    {
        $this->supprimerImage($id, 'couverture');
    }

    private function supprimerImage(string $id, string $colonne): void
    {
        [, $etab] = $this->debutPost($id);
        // Le fichier reste dans la médiathèque, où il peut servir ailleurs
        $this->depot->definirImage((int) $etab['id'], $colonne, null);
        $this->succes($etab, 'etab.' . $colonne . '_retire', 'apparence');
    }

    // Prix et devises

    public function enregistrerPrix(string $id): void
    {
        [$utilisateur, $etab] = $this->debutPost($id);
        $devises = $this->devises->actives();
        [$prix, $erreurs] = $this->saisie->prix($_POST, $this->depot->grilles(), $devises);
        if ($devises === []) {
            unset($erreurs['devises']);
        }
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $etab, ['prix' => $prix, 'erreurs' => ['prix' => $erreurs]], 422);
            return;
        }
        $this->depot->definirPrix((int) $etab['id'], (int) $prix['grille_prix_id'], $prix['devises'], $prix['devise_affichage_id'], (int) $prix['credit_delai_jours']);
        $this->succes($etab, 'etab.prix_enregistres', 'prix');
    }

    // Horaires

    public function enregistrerHoraires(string $id): void
    {
        [$utilisateur, $etab] = $this->debutPost($id);
        [$lignes, $erreurs] = $this->saisie->horaires($_POST);
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $etab, ['horaires' => $lignes, 'erreurs' => ['horaires' => $erreurs]], 422);
            return;
        }
        $this->depot->remplacerHoraires((int) $etab['id'], $lignes);
        $this->succes($etab, $lignes === [] ? 'etab.horaires_vides' : 'etab.horaires_enregistres', 'horaires');
    }

    // Zones

    public function ajouterZone(string $id): void
    {
        [$utilisateur, $etab] = $this->debutPost($id);
        [$zone, $erreurs] = $this->saisie->zone($_POST, (int) $etab['id'], $this->depot->grilles());
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $etab, ['nouvelleZone' => $zone, 'erreurs' => ['nouvelle-zone' => $erreurs]], 422);
            return;
        }
        $zoneId = $this->depot->ajouterZone((int) $etab['id'], $zone);
        Session::flash(t('etab.zone_ajoutee', ['nom' => $zone['nom_fr']]));
        rediriger('/gestion/etablissements/' . (int) $etab['id'] . '#zone-' . $zoneId);
    }

    public function modifierZone(string $id, string $zone): void
    {
        [$utilisateur, $etab] = $this->debutPost($id);
        $existante = $this->zoneOu404($etab, $zone);
        [$valeurs, $erreurs] = $this->saisie->zone($_POST, (int) $etab['id'], $this->depot->grilles(), (int) $existante['id']);
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $etab, [
                'zonesSaisies' => [(int) $existante['id'] => $valeurs],
                'erreurs' => ['zone-' . $existante['id'] => $erreurs],
            ], 422);
            return;
        }
        $this->depot->modifierZone((int) $existante['id'], $valeurs);
        Session::flash(t('etab.zone_enregistree', ['nom' => $valeurs['nom_fr']]));
        rediriger('/gestion/etablissements/' . (int) $etab['id'] . '#zone-' . (int) $existante['id']);
    }

    public function supprimerZone(string $id, string $zone): void
    {
        [, $etab] = $this->debutPost($id);
        $existante = $this->zoneOu404($etab, $zone);
        $this->depot->supprimerZone((int) $existante['id']);
        Session::flash((int) $existante['nb_qr'] > 0
            ? t('etab.zone_supprimee_qr', ['nom' => champ($existante, 'nom'), 'n' => (int) $existante['nb_qr']])
            : t('etab.zone_supprimee', ['nom' => champ($existante, 'nom')]));
        rediriger('/gestion/etablissements/' . (int) $etab['id'] . '#zones');
    }

    // Activation

    public function desactiver(string $id): void
    {
        [, $etab] = $this->debutPost($id);
        $this->depot->definirActif((int) $etab['id'], false);
        $this->succes($etab, 'etab.desactive_ok', 'activation', ['nom' => $etab['nom']]);
    }

    public function reactiver(string $id): void
    {
        [, $etab] = $this->debutPost($id);
        $this->depot->definirActif((int) $etab['id'], true);
        $this->succes($etab, 'etab.reactive_ok', 'activation', ['nom' => $etab['nom']]);
    }

    // Affichage

    private function afficherNouveau(array $utilisateur, array $etat, int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/etablissements/nouveau', [
            'titre' => t('etab.nouveau'),
            'utilisateur' => $utilisateur,
            'infos' => $etat['infos'] ?? [],
            'prix' => $etat['prix'] ?? ['grille_prix_id' => null, 'devises' => [], 'devise_affichage_id' => null, 'credit_delai_jours' => 7],
            'grilles' => $this->depot->grilles(),
            'devises' => $this->devises->actives(),
            'erreurs' => $etat['erreurs'] ?? [],
        ], 'gestion');
    }

    private function afficherFiche(array $utilisateur, array $etab, array $etat = [], int $code = 200): void
    {
        http_response_code($code);
        $id = (int) $etab['id'];
        $horaires = $this->etablissements->horaires($id);
        $apparence = $etat['apparence'] ?? null;

        View::afficher('gestion/etablissements/fiche', [
            'titre' => $etab['nom'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'etab' => $etab,
            'infos' => ($etat['infos'] ?? []) + $etab,
            'apparence' => [
                'couleur' => $apparence['couleur'] ?? $etab['couleur'],
                'couleur_perso' => $apparence !== null ? !empty($_POST['couleur_perso']) : $etab['couleur'] !== null,
                'couleur_saisie' => $apparence['couleur_saisie'] ?? (string) $etab['couleur'],
                'couverture_credit' => $apparence !== null ? (string) $apparence['couverture_credit'] : (string) $etab['couverture_credit'],
            ],
            'prix' => $etat['prix'] ?? [
                'grille_prix_id' => (int) $etab['grille_prix_id'],
                'devises' => $this->etablissements->deviseIds($id),
                'devise_affichage_id' => $etab['devise_affichage_id'] === null ? null : (int) $etab['devise_affichage_id'],
                'credit_delai_jours' => (int) $etab['credit_delai_jours'],
            ],
            'horaires' => $etat['horaires'] ?? $this->depot->horairesPourEditeur($id),
            'etatOuverture' => $this->etablissements->etatOuverture($horaires, new DateTimeImmutable()),
            'zones' => $this->depot->zones($id),
            'zonesSaisies' => $etat['zonesSaisies'] ?? [],
            'nouvelleZone' => $etat['nouvelleZone'] ?? ['code' => '', 'nom_fr' => '', 'nom_en' => '', 'grille_prix_id' => null],
            'grilles' => $this->depot->grilles(),
            'devises' => $this->devises->actives(),
            'nbQr' => (int) (Database::un('SELECT COUNT(*) AS n FROM qr_codes WHERE etablissement_id = ?', [$id])['n'] ?? 0),
            'erreurs' => $etat['erreurs'] ?? [],
            'octetsMax' => Images::octetsMax(),
        ], 'gestion');
    }

    /** Grille « normal » si elle existe, sinon la première. */
    private function grilleParDefaut(array $grilles): ?int
    {
        foreach ($grilles as $grille) {
            if ($grille['code'] === 'normal') {
                return (int) $grille['id'];
            }
        }
        return isset($grilles[0]) ? (int) $grilles[0]['id'] : null;
    }

    // Outils

    /** @return array{array, array} [utilisateur, établissement] */
    private function debutPost(string $id): array
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        return [$utilisateur, $this->etablissementOu404($id)];
    }

    private function etablissementOu404(string $id): array
    {
        $etab = ctype_digit($id) && strlen($id) < 10 ? $this->depot->etablissement((int) $id) : null;
        if ($etab === null) {
            pageIntrouvable();
        }
        return $etab;
    }

    private function zoneOu404(array $etab, string $zone): array
    {
        $ligne = ctype_digit($zone) && strlen($zone) < 10 ? $this->depot->zone((int) $etab['id'], (int) $zone) : null;
        if ($ligne === null) {
            pageIntrouvable();
        }
        return $ligne;
    }

    private function succes(array $etab, string $cle, string $section, array $params = []): never
    {
        Session::flash(t($cle, $params));
        rediriger('/gestion/etablissements/' . (int) $etab['id'] . '#' . $section);
    }
}
