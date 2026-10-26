<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Images envoyées par les utilisateurs (photos des produits, couvertures…).
 * Le fichier est contrôlé (type réel, taille, dimensions), ré-encodé en JPEG avec GD et enregistré
 * sous un nom aléatoire dans public/uploads/<sous-domaine>/<dossier>/.
 *
 *   $chemin = Images::enregistrer($_FILES['photo'], 'produits', 1200, (string) $id);
 *   // « /uploads/demo/produits/12-3f9a0c1d2b4e5f60.jpg »
 *
 * En cas de problème, une RuntimeException est levée avec un message traduit, à afficher tel quel.
 */
final class Images
{
    public const OCTETS_MAX = 8 * 1024 * 1024;
    public const QUALITE_JPEG = 82;

    /** Au-delà, le décodage demanderait trop de mémoire (≈ 5 octets par pixel). */
    private const PIXELS_MAX = 40_000_000;

    private const TYPES = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    /**
     * Enregistre une image envoyée et rend son chemin public.
     *
     * @param array  $fichier  entrée de $_FILES (name, type, tmp_name, error, size)
     * @param string $dossier  sous-dossier de public/uploads/<sous-domaine>/, ex. « produits »
     * @param int    $coteMax  plus grand côté de l'image enregistrée, en pixels
     * @param string $prefixe  début du nom de fichier, ex. l'identifiant du produit
     * @throws RuntimeException message clair pour l'utilisateur
     */
    public static function enregistrer(array $fichier, string $dossier, int $coteMax = 1200, string $prefixe = ''): string
    {
        if (!preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $dossier) || !preg_match('/^[a-z0-9-]*$/', $prefixe)) {
            throw new RuntimeException('Dossier ou préfixe d\'image invalide.');
        }

        $source = self::verifierEnvoi($fichier);
        [$largeur, $hauteur, $type] = self::verifierImage($source);

        $memoire = ini_get('memory_limit');
        if ($memoire !== '-1' && self::enOctets((string) $memoire) < 256 * 1024 * 1024) {
            ini_set('memory_limit', '256M');
        }

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        };
        if ($image === false) {
            throw new RuntimeException(t('image.illisible'));
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = self::redresser($image, $source);
            $largeur = imagesx($image);
            $hauteur = imagesy($image);
        }

        // Redimensionnement, sur fond blanc (la transparence n'existe pas en JPEG)
        $ratio = min(1, $coteMax / max($largeur, $hauteur));
        $nouvelleLargeur = max(1, (int) round($largeur * $ratio));
        $nouvelleHauteur = max(1, (int) round($hauteur * $ratio));
        $finale = imagecreatetruecolor($nouvelleLargeur, $nouvelleHauteur);
        imagefill($finale, 0, 0, imagecolorallocate($finale, 255, 255, 255));
        imagecopyresampled($finale, $image, 0, 0, 0, 0, $nouvelleLargeur, $nouvelleHauteur, $largeur, $hauteur);
        imagedestroy($image);
        imageinterlace($finale, true);

        $sousDomaine = Instance::courante()->sousDomaine;
        $relatif = '/uploads/' . $sousDomaine . '/' . $dossier;
        $repertoire = RACINE . '/public' . $relatif;
        if (!is_dir($repertoire) && !mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
            imagedestroy($finale);
            throw new RuntimeException(t('image.enregistrement_impossible'));
        }

        $nom = ($prefixe !== '' ? $prefixe . '-' : '') . bin2hex(random_bytes(8)) . '.jpg';
        $temporaire = $repertoire . '/.' . $nom . '.tmp';
        $ok = imagejpeg($finale, $temporaire, self::QUALITE_JPEG);
        imagedestroy($finale);
        if (!$ok || !rename($temporaire, $repertoire . '/' . $nom)) {
            @unlink($temporaire);
            throw new RuntimeException(t('image.enregistrement_impossible'));
        }
        return $relatif . '/' . $nom;
    }

    /** Supprime une image enregistrée par enregistrer(). Ignore les chemins extérieurs aux envois de l'instance. */
    public static function supprimer(?string $cheminPublic): void
    {
        if ($cheminPublic === null || $cheminPublic === '') {
            return;
        }
        $prefixe = '/uploads/' . Instance::courante()->sousDomaine . '/';
        if (!str_starts_with($cheminPublic, $prefixe) || !preg_match('#^/uploads/[a-z0-9/-]+/[a-z0-9-]+\.jpg$#', $cheminPublic)) {
            return;
        }
        $fichier = RACINE . '/public' . $cheminPublic;
        if (is_file($fichier)) {
            @unlink($fichier);
        }
    }

    /** Taille maximale réellement acceptée : 8 Mo, ou moins si la configuration de PHP est plus basse. */
    public static function octetsMax(): int
    {
        $limites = [self::OCTETS_MAX];
        foreach (['upload_max_filesize', 'post_max_size'] as $option) {
            $valeur = self::enOctets((string) ini_get($option));
            if ($valeur > 0) {
                $limites[] = $valeur;
            }
        }
        return min($limites);
    }

    /** « 8 Mo », « 2 Mo » */
    public static function tailleLisible(int $octets): string
    {
        return t('image.mo', ['n' => rtrim(rtrim(number_format($octets / 1048576, 1, '.', ''), '0'), '.')]);
    }

    /** Vrai quand la requête dépasse post_max_size : PHP vide alors $_POST et $_FILES. */
    public static function requeteTropLourde(): bool
    {
        $longueur = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $limite = self::enOctets((string) ini_get('post_max_size'));
        return $_POST === [] && $limite > 0 && $longueur > $limite;
    }

    private static function verifierEnvoi(array $fichier): string
    {
        $erreur = $fichier['error'] ?? UPLOAD_ERR_NO_FILE;
        if (is_array($erreur)) {
            throw new RuntimeException(t('image.envoi_echoue'));
        }
        $taille = self::tailleLisible(self::octetsMax());
        switch ((int) $erreur) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new RuntimeException(t('image.aucun_fichier'));
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new RuntimeException(t('image.trop_lourde', ['max' => $taille]));
            default:
                throw new RuntimeException(t('image.envoi_echoue'));
        }

        $source = (string) ($fichier['tmp_name'] ?? '');
        if ($source === '' || !is_uploaded_file($source)) {
            throw new RuntimeException(t('image.envoi_echoue'));
        }
        $octets = (int) filesize($source);
        if ($octets <= 0) {
            throw new RuntimeException(t('image.aucun_fichier'));
        }
        if ($octets > self::octetsMax()) {
            throw new RuntimeException(t('image.trop_lourde', ['max' => $taille]));
        }
        return $source;
    }

    /** @return array{int, int, int} largeur, hauteur, type IMAGETYPE_* */
    private static function verifierImage(string $source): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source) ?: '';
        $infos = @getimagesize($source);
        if (!isset(self::TYPES[$mime]) || $infos === false || $infos[2] !== self::TYPES[$mime]) {
            throw new RuntimeException(t('image.type_refuse'));
        }
        [$largeur, $hauteur] = $infos;
        if ($largeur < 1 || $hauteur < 1) {
            throw new RuntimeException(t('image.illisible'));
        }
        if ($largeur * $hauteur > self::PIXELS_MAX) {
            throw new RuntimeException(t('image.trop_grande'));
        }
        return [$largeur, $hauteur, $infos[2]];
    }

    /** Remet droite une photo de téléphone selon son orientation EXIF. */
    private static function redresser(\GdImage $image, string $source): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($source);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $tournee = imagerotate($image, $angle, 0);
        if ($tournee === false) {
            return $image;
        }
        imagedestroy($image);
        return $tournee;
    }

    private static function enOctets(string $valeur): int
    {
        $valeur = trim($valeur);
        if ($valeur === '' || $valeur === '-1') {
            return 0;
        }
        $nombre = (int) $valeur;
        return match (strtolower(substr($valeur, -1))) {
            'g' => $nombre * 1024 ** 3,
            'm' => $nombre * 1024 ** 2,
            'k' => $nombre * 1024,
            default => $nombre,
        };
    }
}
