<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Images;
use App\Core\Session;
use App\Core\View;
use App\Repositories\MediathequeRepository;
use RuntimeException;

/**
 * Médiathèque : grille des images, envoi (glisser-déposer, choix, collage ; JSON pour fetch,
 * formulaire classique sans JavaScript), liste pour la fenêtre « Choisir dans la médiathèque »,
 * fiche d'une image (textes alternatifs, crédit, utilisations) et suppression d'une image inutilisée.
 */
final class MediathequeController
{
    /** Plus grand côté des images envoyées dans la médiathèque. */
    public const COTE_MAX = 1600;
    private const PAR_PAGE = 48;
    private const PAR_PAGE_CHOIX = 30;

    private MediathequeRepository $mediatheque;

    public function __construct()
    {
        $this->mediatheque = new MediathequeRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherIndex($utilisateur);
    }

    /** Envoi d'une ou plusieurs images : « image » (une par requête fetch) ou « images[] » (formulaire). */
    public function envoyer(): void
    {
        $utilisateur = Session::exigerConnexion();
        $json = self::demandeJson();
        if (Images::requeteTropLourde()) {
            $message = t('image.trop_lourde', ['max' => Images::tailleLisible(Images::octetsMax())]);
            if ($json) {
                self::repondreJson(['medias' => [], 'erreurs' => [['nom' => '', 'message' => $message]]], 413);
            }
            $this->afficherIndex($utilisateur, ['erreursEnvoi' => [$message]], 413);
            return;
        }
        Session::verifierCsrf();

        $fichiers = self::fichiersEnvoyes();
        if ($fichiers === []) {
            $message = t('image.aucun_fichier');
            if ($json) {
                self::repondreJson(['medias' => [], 'erreurs' => [['nom' => '', 'message' => $message]]], 422);
            }
            $this->afficherIndex($utilisateur, ['erreursEnvoi' => [$message]], 422);
            return;
        }

        $medias = [];
        $erreurs = [];
        foreach ($fichiers as $fichier) {
            $nomOriginal = is_string($fichier['name'] ?? null) ? $fichier['name'] : '';
            try {
                $envoi = $this->mediatheque->enregistrerEnvoi($fichier, 'mediatheque', self::COTE_MAX, (int) $utilisateur['id']);
                $medias[] = $this->mediatheque->media($envoi['id']);
            } catch (RuntimeException $erreur) {
                $erreurs[] = ['nom' => $nomOriginal, 'message' => $erreur->getMessage()];
            }
        }

        if ($json) {
            self::repondreJson([
                'medias' => array_map(static fn (array $m) => MediathequeRepository::pourJson($m) + [
                    'html' => View::rendre('gestion/mediatheque/_carte', ['media' => $m]),
                ], $medias),
                'erreurs' => $erreurs,
                'message' => $medias !== [] ? self::texteAjoutees(count($medias)) : '',
            ], $medias === [] ? 422 : 200);
        }

        if ($erreurs !== []) {
            $this->afficherIndex($utilisateur, [
                'flash' => $medias !== [] ? self::texteAjoutees(count($medias)) : null,
                'erreursEnvoi' => array_map(static fn (array $e) => ($e['nom'] !== '' ? $e['nom'] . ' : ' : '') . $e['message'], $erreurs),
            ], $medias === [] ? 422 : 200);
            return;
        }
        Session::flash(self::texteAjoutees(count($medias)));
        rediriger('/gestion/mediatheque');
    }

    /** Images pour la fenêtre de choix (JSON) : ?q=…&page=… */
    public function liste(): void
    {
        Session::exigerConnexion();
        $recherche = self::recherche();
        $page = max(1, self::entier($_GET['page'] ?? null) ?: 1);
        [$lignes, $total] = $this->mediatheque->liste($recherche, 'toutes', 'recentes', self::PAR_PAGE_CHOIX, ($page - 1) * self::PAR_PAGE_CHOIX);
        self::repondreJson([
            'medias' => array_map([MediathequeRepository::class, 'pourJson'], $lignes),
            'total' => $total,
            'suivante' => $page * self::PAR_PAGE_CHOIX < $total ? $page + 1 : null,
        ]);
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherFiche($utilisateur, $this->mediaOu404($id));
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $media = $this->mediaOu404($id);

        $valeurs = [];
        foreach (['nom' => 160, 'alt_fr' => 255, 'alt_en' => 255, 'credit' => 255] as $champ => $max) {
            $brut = is_string($_POST[$champ] ?? null) ? $_POST[$champ] : '';
            $valeurs[$champ] = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $brut));
        }
        $erreurs = [];
        if ($valeurs['nom'] === '') {
            $erreurs['nom'] = t('mediatheque.erreur_nom');
        }
        foreach (['nom' => 160, 'alt_fr' => 255, 'alt_en' => 255, 'credit' => 255] as $champ => $max) {
            if (mb_strlen($valeurs[$champ]) > $max) {
                $erreurs[$champ] = t('mediatheque.erreur_trop_long', ['champ' => t('mediatheque.' . $champ), 'max' => $max]);
            }
        }
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $media, ['valeurs' => $valeurs, 'erreurs' => $erreurs], 422);
            return;
        }
        $vide = static fn (string $v): ?string => $v === '' ? null : $v;
        $this->mediatheque->modifier((int) $media['id'], $valeurs['nom'], $vide($valeurs['alt_fr']), $vide($valeurs['alt_en']), $vide($valeurs['credit']));
        Session::flash(t('mediatheque.enregistree'));
        rediriger('/gestion/mediatheque/' . (int) $media['id']);
    }

    /** Supprime une image qui n'est utilisée nulle part (et son fichier s'il est dans public/uploads). */
    public function supprimer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $media = $this->mediaOu404($id);
        if ((int) $media['utilisations'] > 0) {
            $this->afficherFiche($utilisateur, $media, ['erreurSuppression' => t('mediatheque.suppression_impossible', [
                'n' => MediathequeRepository::texteUtilisationsMinuscule((int) $media['utilisations']),
            ])], 409);
            return;
        }
        $this->mediatheque->supprimer((int) $media['id']);
        if (MediathequeRepository::fichierSupprimable((string) $media['chemin'])) {
            Images::supprimer((string) $media['chemin']);
        }
        Session::flash(t('mediatheque.supprimee', ['nom' => $media['nom']]));
        rediriger('/gestion/mediatheque');
    }

    // Affichage

    private function afficherIndex(array $utilisateur, array $etat = [], int $code = 200): void
    {
        http_response_code($code);
        $recherche = self::recherche();
        $filtre = in_array($_GET['filtre'] ?? null, MediathequeRepository::FILTRES, true) ? $_GET['filtre'] : 'toutes';
        $tri = in_array($_GET['tri'] ?? null, MediathequeRepository::TRIS, true) ? $_GET['tri'] : 'recentes';
        $page = max(1, self::entier($_GET['page'] ?? null) ?: 1);
        [$medias, $total] = $this->mediatheque->liste($recherche, $filtre, $tri, self::PAR_PAGE, ($page - 1) * self::PAR_PAGE);

        View::afficher('gestion/mediatheque/index', [
            'titre' => t('mediatheque.titre'),
            'utilisateur' => $utilisateur,
            'flash' => array_key_exists('flash', $etat) ? $etat['flash'] : Session::lireFlash(),
            'medias' => $medias,
            'total' => $total,
            'compteurs' => $this->mediatheque->compteurs(),
            'recherche' => $recherche,
            'filtre' => $filtre,
            'tri' => $tri,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAR_PAGE)),
            'erreursEnvoi' => $etat['erreursEnvoi'] ?? [],
            'octetsMax' => Images::octetsMax(),
        ], 'gestion');
    }

    private function afficherFiche(array $utilisateur, array $media, array $etat = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/mediatheque/fiche', [
            'titre' => $media['nom'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'media' => $media,
            'valeurs' => $etat['valeurs'] ?? [
                'nom' => (string) $media['nom'],
                'alt_fr' => (string) $media['alt_fr'],
                'alt_en' => (string) $media['alt_en'],
                'credit' => (string) $media['credit'],
            ],
            'erreurs' => $etat['erreurs'] ?? [],
            'erreurSuppression' => $etat['erreurSuppression'] ?? null,
            'utilisations' => $this->mediatheque->utilisations((string) $media['chemin']),
            'supprimable' => MediathequeRepository::fichierSupprimable((string) $media['chemin']),
        ], 'gestion');
    }

    // Outils

    private function mediaOu404(string $id): array
    {
        $media = ctype_digit($id) && strlen($id) < 10 ? $this->mediatheque->media((int) $id) : null;
        if ($media === null) {
            pageIntrouvable();
        }
        return $media;
    }

    /** Fichiers envoyés, sous forme d'entrées $_FILES simples. */
    private static function fichiersEnvoyes(): array
    {
        $liste = [];
        $simple = $_FILES['image'] ?? null;
        if (is_array($simple) && !is_array($simple['error'] ?? null) && ($simple['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $liste[] = $simple;
        }
        $multiple = $_FILES['images'] ?? null;
        if (is_array($multiple) && is_array($multiple['error'] ?? null)) {
            foreach (array_keys($multiple['error']) as $i) {
                if (is_array($multiple['error'][$i]) || $multiple['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $liste[] = [
                    'name' => $multiple['name'][$i] ?? '',
                    'type' => $multiple['type'][$i] ?? '',
                    'tmp_name' => $multiple['tmp_name'][$i] ?? '',
                    'error' => $multiple['error'][$i],
                    'size' => $multiple['size'][$i] ?? 0,
                ];
            }
        }
        return array_slice($liste, 0, 20);
    }

    private static function texteAjoutees(int $n): string
    {
        return $n === 1 ? t('mediatheque.ajoutee_une') : t('mediatheque.ajoutees', ['n' => $n]);
    }

    private static function demandeJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    private static function repondreJson(array $donnees, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function recherche(): string
    {
        return is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 64) : '';
    }

    private static function entier(mixed $valeur): int
    {
        return is_string($valeur) && ctype_digit($valeur) && strlen($valeur) < 7 ? (int) $valeur : 0;
    }
}
