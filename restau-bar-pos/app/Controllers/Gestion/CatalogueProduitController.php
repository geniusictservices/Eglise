<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Database;
use App\Core\Images;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;
use App\Repositories\GestionCatalogueRepository;
use App\Repositories\MediathequeRepository;
use RuntimeException;
use Throwable;

/**
 * Fiche produit : informations, photo, formats et prix, établissements, désactivation.
 * Chaque section a son propre formulaire. En cas d'erreur, la fiche est réaffichée avec la saisie.
 *
 * Photo : choisie dans la médiathèque (champ caché « photo_media ») ou envoyée par l'ancien champ fichier,
 * alors ajoutée à la médiathèque. Remplacer ou retirer la photo ne supprime jamais le fichier :
 * l'image peut servir ailleurs, seule la médiathèque supprime une image inutilisée.
 */
final class CatalogueProduitController
{
    private const COTE_PHOTO = 1200;

    /** Format proposé à la création selon l'unité de base (les unités de mesure demandent un nom). */
    private const FORMAT_PAR_DEFAUT = [
        'bouteille' => ['Bouteille', 'Bottle'],
        'canette' => ['Canette', 'Can'],
        'verre' => ['Verre', 'Glass'],
        'piece' => ['Portion', 'Serving'],
    ];

    private GestionCatalogueRepository $catalogue;
    private DeviseRepository $devises;
    private CatalogueSaisie $saisie;
    private MediathequeRepository $mediatheque;

    public function __construct()
    {
        $this->catalogue = new GestionCatalogueRepository();
        $this->devises = new DeviseRepository();
        $this->saisie = new CatalogueSaisie($this->catalogue);
        $this->mediatheque = new MediathequeRepository();
    }

    // Création

    public function nouveau(): void
    {
        $utilisateur = Session::exigerConnexion();
        $categorie = is_string($_GET['categorie'] ?? null) && ctype_digit($_GET['categorie']) ? (int) $_GET['categorie'] : 0;
        $this->afficherNouveau($utilisateur, [
            'infos' => ['categorie_id' => $categorie, 'unite_base' => 'bouteille'],
            'format' => ['nom_fr' => 'Bouteille', 'nom_en' => 'Bottle', 'quantite_saisie' => '1'],
            'vente' => ['etablissements' => array_map('intval', array_column((new EtablissementRepository())->actifs(), 'id')), 'visible_public' => true, 'disponible' => true],
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        if (Images::requeteTropLourde()) {
            $this->afficherNouveau($utilisateur, ['erreurs' => [t('image.trop_lourde', ['max' => Images::tailleLisible(Images::octetsMax())])]], 422);
            return;
        }
        Session::verifierCsrf();

        $etablissements = (new EtablissementRepository())->actifs();
        [$infos, $erreurs] = $this->saisie->informations($_POST);
        [$vente] = $this->saisie->vente($_POST, $etablissements);

        // Premier format : nom repris de l'unité si l'utilisateur l'a laissé vide
        $postFormat = is_array($_POST['format'] ?? null) ? $_POST['format'] : [];
        if (trim((string) ($postFormat['nom_fr'] ?? '')) === '' && isset(self::FORMAT_PAR_DEFAUT[$infos['unite_base']])) {
            [$postFormat['nom_fr'], $postFormat['nom_en']] = self::FORMAT_PAR_DEFAUT[$infos['unite_base']];
        }
        $postFormat['vendable'] = '1';
        [$format, $erreursFormat] = $this->saisie->format($postFormat);
        $erreurs = array_merge($erreurs, $erreursFormat);

        $grille = $this->catalogue->grilleParDefaut();
        $saisiesPrix = is_array($_POST['prix'] ?? null) ? $_POST['prix'] : [];
        $prix = [];
        foreach ($this->devises->actives() as $deviseId => $devise) {
            $brut = is_string($saisiesPrix[$deviseId] ?? null) ? $saisiesPrix[$deviseId] : '';
            [$montant, $erreur] = $this->saisie->montant($brut, $devise, $devise['code']);
            if ($erreur !== null) {
                $erreurs[] = $erreur;
            }
            $prix[$deviseId] = $montant;
        }

        // Photo : fichier envoyé (sans JavaScript) ou image choisie dans la médiathèque
        $champPhoto = ChampImage::lire('photo', 'photo_media', null, $this->mediatheque);
        if ($champPhoto['erreur'] !== null) {
            $erreurs[] = $champPhoto['erreur'];
        }
        $credit = $this->credit($_POST);
        if (mb_strlen((string) $credit) > 255) {
            $erreurs[] = t('catalogue.erreur_trop_long', ['champ' => t('catalogue.photo_credit'), 'max' => 255]);
        }

        $etat = ['infos' => $infos, 'format' => $format, 'prix' => $saisiesPrix, 'vente' => $vente, 'credit' => $credit,
            'photo' => $champPhoto['action'] === 'choisie' ? $champPhoto['chemin'] : ''];
        if ($erreurs !== []) {
            $this->afficherNouveau($utilisateur, $etat + ['erreurs' => $erreurs], 422);
            return;
        }

        // Tout dans une transaction : si la photo est refusée, rien n'est créé
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $photo = null;
        try {
            $id = $this->catalogue->creerProduit($infos + $vente);
            $this->catalogue->definirEtablissements($id, $vente['etablissements']);
            $formatId = $this->catalogue->ajouterFormat($id, $format);
            foreach ($prix as $deviseId => $montant) {
                if ($montant !== null) {
                    $this->catalogue->definirPrix($formatId, $grille, $deviseId, $montant);
                }
            }
            if ($champPhoto['action'] === 'fichier') {
                $photo = $this->mediatheque->enregistrerEnvoi($champPhoto['fichier'], 'produits', self::COTE_PHOTO, (int) $utilisateur['id'], $credit, (string) $id)['chemin'];
                $this->catalogue->definirPhoto($id, $photo, $credit);
            } elseif ($champPhoto['action'] === 'choisie') {
                $this->catalogue->definirPhoto($id, $champPhoto['chemin'], $credit);
            }
            $pdo->commit();
        } catch (RuntimeException $erreur) {
            $pdo->rollBack();
            Images::supprimer($photo);
            $this->afficherNouveau($utilisateur, $etat + ['erreurs' => [$erreur->getMessage()]], 422);
            return;
        } catch (Throwable $erreur) {
            $pdo->rollBack();
            Images::supprimer($photo);
            throw $erreur;
        }

        Session::flash(t('catalogue.produit_cree', ['nom' => $infos['nom_fr']]));
        rediriger('/gestion/catalogue/produits/' . $id);
    }

    // Fiche

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherFiche($utilisateur, $this->produitOu404($id));
    }

    public function enregistrerInfos(string $id): void
    {
        [$utilisateur, $produit] = $this->debutPost($id);
        [$infos, $erreurs] = $this->saisie->informations($_POST);
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $produit, ['infos' => $infos, 'erreurs' => ['infos' => $erreurs]], 422);
            return;
        }
        $this->catalogue->modifierInformations((int) $produit['id'], $infos);
        $this->succes($produit, 'catalogue.infos_enregistrees', 'infos');
    }

    public function envoyerPhoto(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $produit = $this->produitOu404($id);
        if (Images::requeteTropLourde()) {
            $this->afficherFiche($utilisateur, $produit, ['erreurs' => ['photo' => [t('image.trop_lourde', ['max' => Images::tailleLisible(Images::octetsMax())])]]], 422);
            return;
        }
        Session::verifierCsrf();

        $credit = $this->credit($_POST);
        if (mb_strlen((string) $credit) > 255) {
            $this->afficherFiche($utilisateur, $produit, ['credit' => $credit, 'erreurs' => ['photo' => [
                t('catalogue.erreur_trop_long', ['champ' => t('catalogue.photo_credit'), 'max' => 255]),
            ]]], 422);
            return;
        }

        $champ = ChampImage::lire('photo', 'photo_media', $produit['photo'], $this->mediatheque);
        if ($champ['erreur'] !== null) {
            $this->afficherFiche($utilisateur, $produit, ['credit' => $credit, 'erreurs' => ['photo' => [$champ['erreur']]]], 422);
            return;
        }
        switch ($champ['action']) {
            case 'fichier':
                // Ancien champ fichier : l'image est aussi enregistrée dans la médiathèque
                try {
                    $photo = $this->mediatheque->enregistrerEnvoi($champ['fichier'], 'produits', self::COTE_PHOTO, (int) $utilisateur['id'], $credit, (string) (int) $produit['id'])['chemin'];
                } catch (RuntimeException $erreur) {
                    $this->afficherFiche($utilisateur, $produit, ['credit' => $credit, 'erreurs' => ['photo' => [$erreur->getMessage()]]], 422);
                    return;
                }
                $this->catalogue->definirPhoto((int) $produit['id'], $photo, $credit);
                $this->succes($produit, 'catalogue.photo_enregistree', 'photo');
            case 'choisie':
                $this->catalogue->definirPhoto((int) $produit['id'], $champ['chemin'], $credit);
                $this->succes($produit, 'catalogue.photo_enregistree', 'photo');
            case 'retiree':
                // Le fichier reste dans la médiathèque
                $this->catalogue->definirPhoto((int) $produit['id'], null, null);
                $this->succes($produit, 'catalogue.photo_supprimee', 'photo');
        }

        // Seul le crédit change
        if ($produit['photo'] === null && $credit !== null) {
            $this->afficherFiche($utilisateur, $produit, ['credit' => $credit, 'erreurs' => ['photo' => [t('image.aucun_fichier')]]], 422);
            return;
        }
        $this->catalogue->definirCreditPhoto((int) $produit['id'], $credit);
        $this->succes($produit, 'catalogue.credit_enregistre', 'photo');
    }

    public function supprimerPhoto(string $id): void
    {
        [, $produit] = $this->debutPost($id);
        // Le fichier reste dans la médiathèque, où il peut servir ailleurs
        $this->catalogue->definirPhoto((int) $produit['id'], null, null);
        $this->succes($produit, 'catalogue.photo_supprimee', 'photo');
    }

    // Formats

    public function ajouterFormat(string $id): void
    {
        [$utilisateur, $produit] = $this->debutPost($id);
        [$format, $erreurs] = $this->saisie->format($_POST);
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $produit, ['nouveauFormat' => $format, 'erreurs' => ['nouveau-format' => $erreurs]], 422);
            return;
        }
        $this->catalogue->ajouterFormat((int) $produit['id'], $format);
        $this->succes($produit, 'catalogue.format_ajoute', 'formats');
    }

    public function modifierFormat(string $id, string $format): void
    {
        [$utilisateur, $produit] = $this->debutPost($id);
        $existant = $this->formatOu404($produit, $format);
        [$valeurs, $erreurs] = $this->saisie->format($_POST, (int) $existant['id']);
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $produit, [
                'formats' => [(int) $existant['id'] => $valeurs],
                'erreurs' => ['format-' . $existant['id'] => $erreurs],
            ], 422);
            return;
        }
        $this->catalogue->modifierFormat((int) $existant['id'], $valeurs);
        $this->succes($produit, 'catalogue.format_enregistre', 'formats');
    }

    public function supprimerFormat(string $id, string $format): void
    {
        [, $produit] = $this->debutPost($id);
        $existant = $this->formatOu404($produit, $format);
        $this->catalogue->supprimerFormat((int) $existant['id']);
        $this->succes($produit, 'catalogue.format_supprime', 'formats');
    }

    // Prix

    public function enregistrerPrix(string $id): void
    {
        [$utilisateur, $produit] = $this->debutPost($id);
        $saisies = is_array($_POST['prix'] ?? null) ? $_POST['prix'] : [];
        $devises = $this->devises->actives();
        $grilles = $this->catalogue->grilles();

        $valeurs = [];
        $erreurs = [];
        foreach ($this->catalogue->formats((int) $produit['id']) as $format) {
            if (!$format['vendable']) {
                continue;
            }
            foreach ($grilles as $grille) {
                foreach ($devises as $deviseId => $devise) {
                    $brut = $saisies[$format['id']][$grille['id']][$deviseId] ?? '';
                    $brut = is_string($brut) ? $brut : '';
                    $libelle = champ($format, 'nom') . ' · ' . champ($grille, 'nom') . ' · ' . $devise['code'];
                    [$montant, $erreur] = $this->saisie->montant($brut, $devise, $libelle);
                    if ($erreur !== null) {
                        $erreurs[] = $erreur;
                    }
                    $valeurs[] = [(int) $format['id'], (int) $grille['id'], $deviseId, $montant];
                }
            }
        }
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $produit, ['prix' => $saisies, 'erreurs' => ['prix' => $erreurs]], 422);
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        foreach ($valeurs as [$formatId, $grilleId, $deviseId, $montant]) {
            $this->catalogue->definirPrix($formatId, $grilleId, $deviseId, $montant);
        }
        $pdo->commit();
        $this->succes($produit, 'catalogue.prix_enregistres', 'prix');
    }

    // Vente, désactivation

    public function enregistrerVente(string $id): void
    {
        [, $produit] = $this->debutPost($id);
        [$vente] = $this->saisie->vente($_POST, (new EtablissementRepository())->actifs());
        $this->catalogue->modifierVente((int) $produit['id'], $vente['visible_public'], $vente['disponible'], $vente['etablissements']);
        $this->succes($produit, 'catalogue.vente_enregistree', 'vente');
    }

    public function desactiver(string $id): void
    {
        [, $produit] = $this->debutPost($id);
        $this->catalogue->desactiver((int) $produit['id']);
        Session::flash(t('catalogue.produit_desactive', ['nom' => champ($produit, 'nom')]));
        rediriger('/gestion/catalogue');
    }

    public function reactiver(string $id): void
    {
        [, $produit] = $this->debutPost($id);
        $this->catalogue->reactiver((int) $produit['id']);
        $this->succes($produit, 'catalogue.produit_reactive', 'infos');
    }

    // Affichage

    private function afficherNouveau(array $utilisateur, array $etat, int $code = 200): void
    {
        http_response_code($code);
        $grille = $this->catalogue->grilleParDefaut();
        View::afficher('gestion/catalogue/nouveau', [
            'titre' => t('catalogue.nouveau_produit'),
            'utilisateur' => $utilisateur,
            'categories' => $this->catalogue->categories(),
            'etablissements' => (new EtablissementRepository())->actifs(),
            'devises' => $this->devises->actives(),
            'reference' => $this->devises->reference(),
            'grille' => current(array_filter($this->catalogue->grilles(), static fn (array $g) => (int) $g['id'] === $grille)) ?: null,
            'calcul' => $this->donneesCalcul($grille),
            'infos' => $etat['infos'] ?? [],
            'format' => $etat['format'] ?? [],
            'prix' => $etat['prix'] ?? [],
            'vente' => $etat['vente'] ?? ['etablissements' => [], 'visible_public' => true, 'disponible' => true],
            'credit' => $etat['credit'] ?? '',
            'photo' => $etat['photo'] ?? '',
            'erreurs' => $etat['erreurs'] ?? [],
            'formatsParDefaut' => self::FORMAT_PAR_DEFAUT,
            'octetsMax' => Images::octetsMax(),
        ], 'gestion');
    }

    private function afficherFiche(array $utilisateur, array $produit, array $etat = [], int $code = 200): void
    {
        http_response_code($code);
        $id = (int) $produit['id'];
        $formats = $this->catalogue->formats($id);
        $grilles = $this->catalogue->grilles();
        $devises = $this->devises->actives();
        $reference = $this->devises->reference();
        $grilleDefaut = $this->catalogue->grilleParDefaut();
        $prix = $this->catalogue->prixDuProduit($id);

        // Prix calculés (cases vides), avec le même ordre de recherche que le menu
        $automatiques = [];
        foreach ($formats as $format) {
            foreach ($grilles as $grille) {
                foreach ($devises as $deviseId => $devise) {
                    $montant = $this->prixCalcule($prix[$format['id']] ?? [], (int) $grille['id'], $grilleDefaut, $devise, $reference);
                    $automatiques[$format['id']][$grille['id']][$deviseId] = $montant === null ? null : $this->devises->formater($montant, $devise, false);
                }
            }
        }

        $etablissements = (new EtablissementRepository())->actifs();
        $categorie = Database::un('SELECT * FROM categories WHERE id = ?', [$produit['categorie_id']]);

        View::afficher('gestion/catalogue/produit', [
            'titre' => champ($produit, 'nom'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'produit' => $produit,
            'categorie' => $categorie,
            'infos' => ($etat['infos'] ?? []) + $produit,
            'credit' => array_key_exists('credit', $etat) ? (string) $etat['credit'] : (string) $produit['photo_credit'],
            'categories' => $this->catalogue->categories(),
            'formats' => $formats,
            'formatsSaisis' => $etat['formats'] ?? [],
            'nouveauFormat' => $etat['nouveauFormat'] ?? ['vendable' => true],
            'grilles' => $grilles,
            'devises' => $devises,
            'reference' => $reference,
            'prix' => $prix,
            'prixSaisis' => $etat['prix'] ?? null,
            'automatiques' => $automatiques,
            'calcul' => $this->donneesCalcul($grilleDefaut),
            'etablissements' => $etablissements,
            'vente' => [
                'etablissements' => $this->catalogue->etablissementsDuProduit($id),
                'visible_public' => (bool) $produit['visible_public'],
                'disponible' => (bool) $produit['disponible'],
            ],
            'erreurs' => $etat['erreurs'] ?? [],
            'octetsMax' => Images::octetsMax(),
        ], 'gestion');
    }

    /**
     * Prix d'une case vide tel que le menu le calculera :
     * prix de la grille en devise de référence converti, puis prix de la grille par défaut.
     */
    private function prixCalcule(array $prixFormat, int $grilleId, int $grilleDefaut, array $devise, ?array $reference): ?float
    {
        if (isset($prixFormat[$grilleId][$devise['id']])) {
            return null;
        }
        $chaine = [[$grilleId, false], [$grilleDefaut, true]];
        foreach ($chaine as [$grille, $memeDevise]) {
            if ($memeDevise && $grille !== $grilleId && isset($prixFormat[$grille][$devise['id']])) {
                return $prixFormat[$grille][$devise['id']];
            }
            if ($reference !== null && $devise['id'] !== $reference['id'] && isset($prixFormat[$grille][$reference['id']])) {
                return $this->devises->convertirDepuisReference($prixFormat[$grille][$reference['id']], $devise);
            }
            if ($grille === $grilleId && $grilleId === $grilleDefaut) {
                break;
            }
        }
        return null;
    }

    /** Données pour recalculer les prix automatiques dans le navigateur pendant la saisie. */
    private function donneesCalcul(int $grilleDefaut): array
    {
        $reference = $this->devises->reference();
        return [
            'langue' => \App\Core\I18n::langue(),
            'reference' => $reference ? (int) $reference['id'] : null,
            'grilleDefaut' => $grilleDefaut,
            'auto' => t('catalogue.prix_auto', ['montant' => '{montant}']),
            'devises' => array_values(array_map(static fn (array $d) => [
                'id' => (int) $d['id'],
                'taux' => $d['taux'] === null ? null : (float) $d['taux'],
                'arrondi' => (float) $d['arrondi'],
                'decimales' => (int) $d['decimales'],
                'symbole' => $d['symbole'],
                'symboleAvant' => (bool) $d['symbole_avant'],
            ], $this->devises->actives())),
        ];
    }

    // Outils

    /** @return array{array, array} [utilisateur, produit] */
    private function debutPost(string $id): array
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        return [$utilisateur, $this->produitOu404($id)];
    }

    private function produitOu404(string $id): array
    {
        $produit = ctype_digit($id) ? $this->catalogue->produit((int) $id) : null;
        if ($produit === null) {
            pageIntrouvable();
        }
        return $produit;
    }

    private function formatOu404(array $produit, string $format): array
    {
        $ligne = ctype_digit($format) ? $this->catalogue->format((int) $produit['id'], (int) $format) : null;
        if ($ligne === null) {
            pageIntrouvable();
        }
        return $ligne;
    }

    private function credit(array $post): ?string
    {
        $credit = is_string($post['photo_credit'] ?? null) ? trim((string) preg_replace('/\p{Cc}/u', '', $post['photo_credit'])) : '';
        return $credit === '' ? null : $credit;
    }

    private function succes(array $produit, string $cle, string $section): never
    {
        Session::flash(t($cle));
        rediriger('/gestion/catalogue/produits/' . (int) $produit['id'] . '#' . $section);
    }
}
