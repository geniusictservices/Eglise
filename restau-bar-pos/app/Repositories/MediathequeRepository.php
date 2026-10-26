<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Controllers\Gestion\EtablissementSaisie;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Images;

/**
 * Médiathèque : images de l'instance (table medias) et endroits où elles sont utilisées
 * (photo d'un produit, logo ou couverture d'un établissement).
 * Une image utilisée ne peut pas être supprimée ; les produits et établissements ne suppriment
 * jamais de fichier eux-mêmes.
 */
final class MediathequeRepository
{
    public const FILTRES = ['toutes', 'utilisees', 'non-utilisees'];
    public const TRIS = ['recentes', 'anciennes', 'nom'];

    /** Nombre d'utilisations d'une image, calculé dans la requête (m = medias). */
    private const UTILISATIONS = '(SELECT COUNT(*) FROM produits p WHERE p.photo = m.chemin)
        + (SELECT COUNT(*) FROM etablissements el WHERE el.logo = m.chemin)
        + (SELECT COUNT(*) FROM etablissements ec WHERE ec.couverture = m.chemin)';

    /**
     * Images filtrées, les plus récentes d'abord (par défaut).
     * @return array{0: array, 1: int} [lignes, nombre total correspondant aux filtres]
     */
    public function liste(string $recherche = '', string $filtre = 'toutes', string $tri = 'recentes', int $limite = 48, int $decalage = 0): array
    {
        $params = [];
        $where = '';
        if ($recherche !== '') {
            $where = 'WHERE m.nom LIKE ?';
            $params[] = '%' . addcslashes($recherche, '%_\\') . '%';
        }
        $interne = 'SELECT m.*, u.nom AS auteur, ' . self::UTILISATIONS . ' AS utilisations
            FROM medias m LEFT JOIN utilisateurs u ON u.id = m.cree_par ' . $where;
        $condition = match ($filtre) {
            'utilisees' => 'WHERE t.utilisations > 0',
            'non-utilisees' => 'WHERE t.utilisations = 0',
            default => '',
        };
        $ordre = match ($tri) {
            'anciennes' => 't.cree_le ASC, t.id ASC',
            'nom' => 't.nom ASC, t.id DESC',
            default => 't.cree_le DESC, t.id DESC',
        };

        $total = (int) (Database::un("SELECT COUNT(*) AS n FROM ($interne) t $condition", $params)['n'] ?? 0);
        $lignes = Database::tous(
            "SELECT * FROM ($interne) t $condition ORDER BY $ordre LIMIT " . max(1, $limite) . ' OFFSET ' . max(0, $decalage),
            $params
        );
        return [array_map([$this, 'completer'], $lignes), $total];
    }

    /** Nombre d'images par filtre, pour les puces « toutes / utilisées / non utilisées ». */
    public function compteurs(): array
    {
        $ligne = Database::un('SELECT COUNT(*) AS toutes, COALESCE(SUM(t.utilisations > 0), 0) AS utilisees
            FROM (SELECT m.id, ' . self::UTILISATIONS . ' AS utilisations FROM medias m) t');
        $toutes = (int) ($ligne['toutes'] ?? 0);
        $utilisees = (int) ($ligne['utilisees'] ?? 0);
        return ['toutes' => $toutes, 'utilisees' => $utilisees, 'non-utilisees' => $toutes - $utilisees];
    }

    public function media(int $id): ?array
    {
        $ligne = Database::un('SELECT m.*, u.nom AS auteur, ' . self::UTILISATIONS . ' AS utilisations
            FROM medias m LEFT JOIN utilisateurs u ON u.id = m.cree_par WHERE m.id = ?', [$id]);
        return $ligne === null ? null : $this->completer($ligne);
    }

    public function parChemin(string $chemin): ?array
    {
        return Database::un('SELECT * FROM medias WHERE chemin = ?', [$chemin]);
    }

    /**
     * Enregistre dans la médiathèque une image déjà écrite sur le disque (par Images::enregistrer).
     * Si le chemin y est déjà, rend la ligne existante.
     */
    public function ajouter(string $chemin, string $nom, ?int $utilisateurId, ?string $credit = null): int
    {
        $existant = $this->parChemin($chemin);
        if ($existant !== null) {
            return (int) $existant['id'];
        }
        $infos = self::infosFichier($chemin);
        Database::executer(
            'INSERT INTO medias (chemin, nom, type_mime, largeur, hauteur, octets, credit, cree_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$chemin, mb_substr($nom, 0, 160), $infos['type_mime'], $infos['largeur'], $infos['hauteur'], $infos['octets'], $credit, $utilisateurId]
        );
        return Database::dernierId();
    }

    /**
     * Contrôle, ré-encode et enregistre une image envoyée (Images::enregistrer), puis l'ajoute à la médiathèque.
     * Le nom lisible vient du nom du fichier envoyé.
     *
     * @param array  $fichier entrée de $_FILES
     * @param string $dossier « mediatheque », « produits », « etablissements »
     * @return array{chemin: string, id: int}
     * @throws \RuntimeException message clair pour l'utilisateur
     */
    public function enregistrerEnvoi(array $fichier, string $dossier, int $coteMax, ?int $utilisateurId, ?string $credit = null, string $prefixe = ''): array
    {
        $nom = self::nomLisible(is_string($fichier['name'] ?? null) ? $fichier['name'] : '');
        if ($prefixe === '') {
            $prefixe = self::prefixeFichier($nom);
        }
        $chemin = Images::enregistrer($fichier, $dossier, $coteMax, $prefixe);
        try {
            $id = $this->ajouter($chemin, $nom, $utilisateurId, $credit);
        } catch (\Throwable $erreur) {
            Images::supprimer($chemin);
            throw $erreur;
        }
        return ['chemin' => $chemin, 'id' => $id];
    }

    public function modifier(int $id, string $nom, ?string $altFr, ?string $altEn, ?string $credit): void
    {
        Database::executer('UPDATE medias SET nom = ?, alt_fr = ?, alt_en = ?, credit = ? WHERE id = ?', [$nom, $altFr, $altEn, $credit, $id]);
    }

    public function supprimer(int $id): void
    {
        Database::executer('DELETE FROM medias WHERE id = ?', [$id]);
    }

    /**
     * Endroits où une image est utilisée, avec un lien vers la fiche concernée.
     * @return list<array{type: string, id: int, nom: string, lien: string, actif: bool}>
     */
    public function utilisations(string $chemin): array
    {
        $liste = [];
        foreach (Database::tous('SELECT id, nom_fr, nom_en, actif FROM produits WHERE photo = ? ORDER BY nom_fr', [$chemin]) as $p) {
            $liste[] = ['type' => 'produit', 'id' => (int) $p['id'], 'nom' => champ($p, 'nom'), 'lien' => '/gestion/catalogue/produits/' . (int) $p['id'] . '#photo', 'actif' => (bool) $p['actif']];
        }
        foreach (['logo', 'couverture'] as $colonne) {
            foreach (Database::tous("SELECT id, nom, actif FROM etablissements WHERE $colonne = ? ORDER BY nom", [$chemin]) as $e) {
                $liste[] = ['type' => $colonne, 'id' => (int) $e['id'], 'nom' => (string) $e['nom'], 'lien' => '/gestion/etablissements/' . (int) $e['id'] . '#apparence', 'actif' => (bool) $e['actif']];
            }
        }
        return $liste;
    }

    /**
     * Complète type, dimensions et poids d'une image enregistrée sans eux (images reprises par la migration),
     * quand son fichier existe dans public/.
     */
    public function completer(array $media): array
    {
        if ($media['octets'] !== null && $media['type_mime'] !== null) {
            return $media;
        }
        $infos = self::infosFichier((string) $media['chemin']);
        if ($infos['octets'] === null) {
            return $media;
        }
        Database::executer(
            'UPDATE medias SET type_mime = ?, largeur = ?, hauteur = ?, octets = ? WHERE id = ?',
            [$infos['type_mime'], $infos['largeur'], $infos['hauteur'], $infos['octets'], (int) $media['id']]
        );
        return array_merge($media, $infos);
    }

    /**
     * Type, dimensions et poids du fichier d'un chemin public. Valeurs nulles si le fichier n'existe pas.
     * @return array{type_mime: ?string, largeur: ?int, hauteur: ?int, octets: ?int}
     */
    public static function infosFichier(string $chemin): array
    {
        $vide = ['type_mime' => null, 'largeur' => null, 'hauteur' => null, 'octets' => null];
        $fichier = self::fichierLocal($chemin);
        if ($fichier === null) {
            return $vide;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($fichier) ?: null;
        $largeur = $hauteur = null;
        $taille = @getimagesize($fichier);
        if ($taille !== false) {
            [$largeur, $hauteur] = $taille;
        } elseif (str_ends_with(strtolower($fichier), '.svg')) {
            $mime = 'image/svg+xml';
            $debut = (string) file_get_contents($fichier, false, null, 0, 2048);
            if (preg_match('/viewBox="\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*"/', $debut, $vb)) {
                [$largeur, $hauteur] = [(int) round((float) $vb[1]), (int) round((float) $vb[2])];
            }
        }
        return ['type_mime' => $mime, 'largeur' => $largeur, 'hauteur' => $hauteur, 'octets' => (int) filesize($fichier)];
    }

    /** Fichier sur le disque d'un chemin public (/uploads/… ou /assets/…), ou null. */
    public static function fichierLocal(string $chemin): ?string
    {
        if (!preg_match('#^/(uploads|assets)/[A-Za-z0-9._/-]+$#', $chemin) || str_contains($chemin, '..')) {
            return null;
        }
        $fichier = RACINE . '/public' . $chemin;
        return is_file($fichier) ? $fichier : null;
    }

    /** Vrai si le fichier peut être effacé : uniquement dans public/uploads, jamais dans public/assets. */
    public static function fichierSupprimable(string $chemin): bool
    {
        return str_starts_with($chemin, '/uploads/');
    }

    /** « terrasse_au-coucher.JPG » → « Terrasse au coucher » ; nom par défaut pour une image collée. */
    public static function nomLisible(string $nomFichier): string
    {
        $nom = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', basename(str_replace('\\', '/', $nomFichier)));
        $nom = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $nom);
        $nom = trim((string) preg_replace('/[\s_\-.]+/u', ' ', $nom));
        if ($nom === '' || in_array(mb_strtolower($nom), ['image', 'blob', 'clipboard', 'presse papiers'], true)) {
            return t('mediatheque.nom_colle', ['date' => date(I18n::langue() === 'fr' ? 'd/m/Y H:i' : 'Y-m-d H:i')]);
        }
        $nom = mb_substr($nom, 0, 120);
        return mb_strtoupper(mb_substr($nom, 0, 1)) . mb_substr($nom, 1);
    }

    /** Début du nom du fichier enregistré : « terrasse-au-coucher » (lettres, chiffres, tirets). */
    public static function prefixeFichier(string $nom): string
    {
        return EtablissementSaisie::slugifier($nom, 40);
    }

    /** « 245 Ko », « 1,2 Mo » */
    public static function poidsLisible(?int $octets): string
    {
        if ($octets === null) {
            return '—';
        }
        if ($octets < 1024 * 1024) {
            return t('mediatheque.ko', ['n' => max(1, (int) round($octets / 1024))]);
        }
        $mo = rtrim(rtrim(number_format($octets / 1048576, 1, '.', ''), '0'), '.');
        return t('image.mo', ['n' => I18n::langue() === 'fr' ? str_replace('.', ',', $mo) : $mo]);
    }

    /** « 1600 × 1200 px » */
    public static function dimensions(array $media): string
    {
        return $media['largeur'] !== null && $media['hauteur'] !== null
            ? t('mediatheque.dimensions', ['l' => (int) $media['largeur'], 'h' => (int) $media['hauteur']])
            : '—';
    }

    /** « Utilisée 3 fois », « Non utilisée » */
    public static function texteUtilisations(int $n): string
    {
        return match (true) {
            $n === 0 => t('mediatheque.non_utilisee'),
            $n === 1 => t('mediatheque.utilisee_une'),
            default => t('mediatheque.utilisee_n', ['n' => $n]),
        };
    }

    /** « utilisée 3 fois », pour l'insérer dans une phrase */
    public static function texteUtilisationsMinuscule(int $n): string
    {
        $texte = self::texteUtilisations($n);
        return mb_strtolower(mb_substr($texte, 0, 1)) . mb_substr($texte, 1);
    }

    /** Données d'une image pour le JavaScript (fenêtre de choix, envoi). */
    public static function pourJson(array $media): array
    {
        return [
            'id' => (int) $media['id'],
            'chemin' => (string) $media['chemin'],
            'nom' => (string) $media['nom'],
            'alt' => champ($media, 'alt'),
            'credit' => (string) ($media['credit'] ?? ''),
            'dimensions' => self::dimensions($media),
            'poids' => self::poidsLisible($media['octets'] === null ? null : (int) $media['octets']),
            'utilisations' => (int) ($media['utilisations'] ?? 0),
            'utilisationsTexte' => self::texteUtilisations((int) ($media['utilisations'] ?? 0)),
            'fiche' => '/gestion/mediatheque/' . (int) $media['id'],
        ];
    }
}
