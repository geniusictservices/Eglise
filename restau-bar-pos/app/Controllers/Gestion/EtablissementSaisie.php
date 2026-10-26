<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Repositories\GestionEtablissementRepository;

/**
 * Lecture et contrôle des saisies de la fiche établissement et des grilles de prix.
 * Chaque méthode rend [valeurs nettoyées, erreurs] ; les erreurs sont indexées par nom de champ
 * pour être affichées sous le champ concerné et en tête de section.
 */
final class EtablissementSaisie
{
    public const TYPES = ['restaurant_bar', 'lounge', 'nightclub'];
    public const MOTIF_CODE = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(private GestionEtablissementRepository $depot)
    {
    }

    /** « Le Comptoir du Lac » → « le-comptoir-du-lac » */
    public static function slugifier(string $texte, int $max = 64): string
    {
        if (class_exists(\Normalizer::class)) {
            $texte = (string) \Normalizer::normalize($texte, \Normalizer::FORM_D);
            $texte = (string) preg_replace('/\p{Mn}+/u', '', $texte);
        }
        $texte = strtr(mb_strtolower($texte), [
            'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss', 'ø' => 'o', 'đ' => 'd', 'ł' => 'l', '&' => '-et-',
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ñ' => 'n',
        ]);
        $texte = trim((string) preg_replace('/[^a-z0-9]+/', '-', $texte), '-');
        return trim(substr($texte, 0, $max), '-');
    }

    // Informations

    /** @return array{array, array<string, string>} */
    public function informations(array $post, int $saufId = 0): array
    {
        $v = [
            'nom' => self::ligne($post, 'nom'),
            'slug' => strtolower(self::ligne($post, 'slug')),
            'type' => self::ligne($post, 'type'),
            'slogan_fr' => self::ligne($post, 'slogan_fr'),
            'slogan_en' => self::ligne($post, 'slogan_en'),
            'description_fr' => self::paragraphe($post, 'description_fr'),
            'description_en' => self::paragraphe($post, 'description_en'),
            'adresse' => self::ligne($post, 'adresse'),
            'ville' => self::ligne($post, 'ville'),
            'lien_carte' => self::url(self::ligne($post, 'lien_carte')),
            'telephone' => self::ligne($post, 'telephone'),
            'whatsapp' => self::ligne($post, 'whatsapp'),
            'email' => strtolower(self::ligne($post, 'email')),
            'facebook' => self::reseau(self::ligne($post, 'facebook'), 'https://www.facebook.com/'),
            'instagram' => self::reseau(self::ligne($post, 'instagram'), 'https://www.instagram.com/'),
            'tiktok' => self::reseau(self::ligne($post, 'tiktok'), 'https://www.tiktok.com/@'),
        ];
        $erreurs = [];

        if ($v['nom'] === '') {
            $erreurs['nom'] = t('etab.erreur_nom');
        }
        // Adresse web : proposée à partir du nom si elle est laissée vide
        if ($v['slug'] === '' && $v['nom'] !== '') {
            $v['slug'] = $this->depot->slugLibre(self::slugifier($v['nom']) ?: 'etablissement', $saufId);
        }
        if ($v['slug'] === '' && !isset($erreurs['nom'])) {
            $erreurs['slug'] = t('etab.erreur_slug_vide');
        } elseif ($v['slug'] !== '' && (strlen($v['slug']) > 64 || !preg_match(self::MOTIF_CODE, $v['slug']))) {
            $erreurs['slug'] = t('etab.erreur_slug_format', ['exemple' => self::slugifier($v['slug']) ?: 'mon-bar']);
        } elseif ($v['slug'] !== '' && $this->depot->slugPris($v['slug'], $saufId)) {
            $erreurs['slug'] = t('etab.erreur_slug_pris', ['slug' => $v['slug'], 'exemple' => $this->depot->slugLibre($v['slug'], $saufId)]);
        }
        if (!in_array($v['type'], self::TYPES, true)) {
            $erreurs['type'] = t('etab.erreur_type');
        }

        $longueurs = [
            'nom' => 128, 'slogan_fr' => 255, 'slogan_en' => 255, 'description_fr' => 5000, 'description_en' => 5000,
            'adresse' => 255, 'ville' => 128, 'lien_carte' => 512, 'telephone' => 32, 'whatsapp' => 32,
            'email' => 128, 'facebook' => 255, 'instagram' => 255, 'tiktok' => 255,
        ];
        foreach ($longueurs as $champ => $max) {
            if (!isset($erreurs[$champ]) && mb_strlen($v[$champ]) > $max) {
                $erreurs[$champ] = t('etab.erreur_trop_long', ['champ' => t('etab.' . $champ), 'max' => $max]);
            }
        }

        if (!isset($erreurs['lien_carte']) && $v['lien_carte'] !== '' && !self::urlValide($v['lien_carte'])) {
            $erreurs['lien_carte'] = t('etab.erreur_lien_carte');
        }
        foreach (['facebook', 'instagram', 'tiktok'] as $reseau) {
            if (!isset($erreurs[$reseau]) && $v[$reseau] !== '' && !self::urlValide($v[$reseau])) {
                $erreurs[$reseau] = t('etab.erreur_reseau', ['reseau' => t('etab.' . $reseau)]);
            }
        }
        if (!isset($erreurs['telephone']) && $v['telephone'] !== '' && !self::telephoneValide($v['telephone'])) {
            $erreurs['telephone'] = t('etab.erreur_telephone');
        }
        if (!isset($erreurs['whatsapp']) && $v['whatsapp'] !== ''
            && (!preg_match('/^(\+|00)/', $v['whatsapp']) || !self::telephoneValide($v['whatsapp']))) {
            $erreurs['whatsapp'] = t('etab.erreur_whatsapp');
        }
        if (!isset($erreurs['email']) && $v['email'] !== '' && filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
            $erreurs['email'] = t('etab.erreur_email');
        }

        return [$v, $erreurs];
    }

    /** Valeurs pour la base : chaîne vide → NULL pour les champs facultatifs. */
    public static function pourBase(array $infos): array
    {
        foreach ($infos as $cle => $valeur) {
            if (!in_array($cle, ['nom', 'slug', 'type'], true) && $valeur === '') {
                $infos[$cle] = null;
            }
        }
        return $infos;
    }

    // Apparence

    /** @return array{array, array<string, string>} ['couleur' => ?'#RRGGBB', 'couverture_credit' => ?string] */
    public function apparence(array $post): array
    {
        $erreurs = [];
        $couleur = null;
        if (!empty($post['couleur_perso'])) {
            $couleur = couleurValide(self::ligne($post, 'couleur'));
            if ($couleur === null) {
                $erreurs['couleur'] = t('etab.erreur_couleur');
            }
        }
        $credit = self::ligne($post, 'couverture_credit');
        if (mb_strlen($credit) > 255) {
            $erreurs['couverture_credit'] = t('etab.erreur_trop_long', ['champ' => t('etab.couverture_credit'), 'max' => 255]);
        }
        return [['couleur' => $couleur, 'couleur_saisie' => self::ligne($post, 'couleur'), 'couverture_credit' => $credit === '' ? null : $credit], $erreurs];
    }

    // Prix et devises

    /**
     * @param array $grilles  lignes de grilles_prix
     * @param array $devises  devises actives indexées par id
     * @return array{array, array<string, string>}
     */
    public function prix(array $post, array $grilles, array $devises): array
    {
        $grilleId = self::entier($post['grille_prix_id'] ?? null);
        $deviseIds = [];
        foreach (is_array($post['devises'] ?? null) ? $post['devises'] : [] as $brut) {
            $did = self::entier($brut);
            if ($did !== null && isset($devises[$did])) {
                $deviseIds[] = $did;
            }
        }
        $deviseIds = array_values(array_unique($deviseIds));
        $affichage = self::entier($post['devise_affichage_id'] ?? null);

        $erreurs = [];
        if ($grilleId === null || !in_array($grilleId, array_map('intval', array_column($grilles, 'id')), true)) {
            $erreurs['grille_prix_id'] = t('etab.erreur_grille');
        }
        if ($deviseIds === []) {
            $erreurs['devises'] = t('etab.erreur_devises');
        }
        // Devise affichée : forcément une des devises acceptées (la première à défaut)
        if ($affichage === null || !in_array($affichage, $deviseIds, true)) {
            $affichage = $deviseIds[0] ?? null;
        }
        return [['grille_prix_id' => $grilleId, 'devises' => $deviseIds, 'devise_affichage_id' => $affichage], $erreurs];
    }

    // Horaires

    /**
     * Lignes de l'éditeur. Une ligne entièrement vide est ignorée.
     * @return array{array, string[]} lignes [service_fr, service_en, jours, ouverture, fermeture] et erreurs
     */
    public function horaires(array $post): array
    {
        $lignes = [];
        $erreurs = [];
        $numero = 0;
        foreach (is_array($post['horaires'] ?? null) ? $post['horaires'] : [] as $brut) {
            if (!is_array($brut)) {
                continue;
            }
            $jours = [];
            foreach (is_array($brut['jours'] ?? null) ? $brut['jours'] : [] as $jour) {
                $j = self::entier($jour);
                if ($j !== null && $j >= 1 && $j <= 7) {
                    $jours[] = $j;
                }
            }
            $jours = array_values(array_unique($jours));
            sort($jours);
            $ligne = [
                'service_fr' => self::ligne($brut, 'service_fr'),
                'service_en' => self::ligne($brut, 'service_en'),
                'jours' => $jours,
                'ouverture' => self::heure(self::ligne($brut, 'ouverture')),
                'fermeture' => self::heure(self::ligne($brut, 'fermeture')),
                'ouverture_saisie' => self::ligne($brut, 'ouverture'),
                'fermeture_saisie' => self::ligne($brut, 'fermeture'),
            ];
            if ($ligne['service_fr'] === '' && $ligne['service_en'] === '' && $jours === []
                && $ligne['ouverture_saisie'] === '' && $ligne['fermeture_saisie'] === '') {
                continue;
            }
            $numero++;
            $ligne['erreur'] = false;
            $prefixe = t('etab.horaires_ligne', ['n' => $numero]) . ' : ';
            if ($ligne['service_fr'] === '') {
                $erreurs[] = $prefixe . t('etab.erreur_service');
                $ligne['erreur'] = true;
            } elseif (mb_strlen($ligne['service_fr']) > 64 || mb_strlen($ligne['service_en']) > 64) {
                $erreurs[] = $prefixe . t('etab.erreur_trop_long', ['champ' => t('etab.service'), 'max' => 64]);
                $ligne['erreur'] = true;
            }
            if ($jours === []) {
                $erreurs[] = $prefixe . t('etab.erreur_jours');
                $ligne['erreur'] = true;
            }
            if ($ligne['ouverture'] === null || $ligne['fermeture'] === null) {
                $erreurs[] = $prefixe . t('etab.erreur_heures');
                $ligne['erreur'] = true;
            }
            $lignes[] = $ligne;
        }
        return [$lignes, $erreurs];
    }

    /** « 9:5 » refusé, « 9:00 », « 09:00 », « 9h », « 21h30 », « 09:00:00 » acceptés → « 09:00 ». */
    public static function heure(string $saisie): ?string
    {
        if (!preg_match('/^(\d{1,2})(?:[:hH](\d{2})?)?(?::\d{2})?$/', $saisie, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        if ($h === 24 && $min === 0) {
            $h = 0;
        }
        if ($h > 23 || $min > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $min);
    }

    // Zones

    /**
     * @param array $grilles lignes de grilles_prix
     * @return array{array, array<string, string>}
     */
    public function zone(array $post, int $etablissementId, array $grilles, int $saufId = 0): array
    {
        $v = [
            'code' => strtolower(self::ligne($post, 'code')),
            'nom_fr' => self::ligne($post, 'nom_fr'),
            'nom_en' => self::ligne($post, 'nom_en'),
            'grille_prix_id' => self::entier($post['grille_prix_id'] ?? null),
        ];
        $erreurs = [];
        if ($v['nom_fr'] === '') {
            $erreurs['nom_fr'] = t('etab.erreur_zone_nom');
        } elseif (mb_strlen($v['nom_fr']) > 64 || mb_strlen($v['nom_en']) > 64) {
            $erreurs['nom_fr'] = t('etab.erreur_trop_long', ['champ' => t('etab.zone_nom'), 'max' => 64]);
        }
        if ($v['code'] === '' && $v['nom_fr'] !== '') {
            $v['code'] = self::slugifier($v['nom_fr'], 32);
        }
        if ($v['code'] === '' && !isset($erreurs['nom_fr'])) {
            $erreurs['code'] = t('etab.erreur_code_vide');
        } elseif ($v['code'] !== '' && (strlen($v['code']) > 32 || !preg_match(self::MOTIF_CODE, $v['code']))) {
            $erreurs['code'] = t('etab.erreur_code_format', ['exemple' => self::slugifier($v['code'], 32) ?: 'vip']);
        } elseif ($v['code'] !== '' && $this->depot->codeZonePris($etablissementId, $v['code'], $saufId)) {
            $erreurs['code'] = t('etab.erreur_zone_code_pris', ['code' => $v['code']]);
        }
        if ($v['grille_prix_id'] !== null && !in_array($v['grille_prix_id'], array_map('intval', array_column($grilles, 'id')), true)) {
            $erreurs['grille_prix_id'] = t('etab.erreur_grille');
        }
        if ($v['nom_en'] === '') {
            $v['nom_en'] = $v['nom_fr'];
        }
        return [$v, $erreurs];
    }

    // Grilles de prix

    /** @return array{array, array<string, string>} */
    public function grille(array $post, int $saufId = 0): array
    {
        $v = [
            'code' => strtolower(self::ligne($post, 'code')),
            'nom_fr' => self::ligne($post, 'nom_fr'),
            'nom_en' => self::ligne($post, 'nom_en'),
        ];
        $erreurs = [];
        if ($v['nom_fr'] === '') {
            $erreurs['nom_fr'] = t('grilles.erreur_nom');
        } elseif (mb_strlen($v['nom_fr']) > 64 || mb_strlen($v['nom_en']) > 64) {
            $erreurs['nom_fr'] = t('etab.erreur_trop_long', ['champ' => t('grilles.nom'), 'max' => 64]);
        }
        if ($v['code'] === '' && $v['nom_fr'] !== '') {
            $v['code'] = self::slugifier((string) preg_replace('/^(prix|price)\s+/iu', '', $v['nom_fr']), 32);
        }
        if ($v['code'] === '' && !isset($erreurs['nom_fr'])) {
            $erreurs['code'] = t('etab.erreur_code_vide');
        } elseif ($v['code'] !== '' && (strlen($v['code']) > 32 || !preg_match(self::MOTIF_CODE, $v['code']))) {
            $erreurs['code'] = t('etab.erreur_code_format', ['exemple' => self::slugifier($v['code'], 32) ?: 'nuit']);
        } elseif ($v['code'] !== '' && $this->depot->codeGrillePris($v['code'], $saufId)) {
            $erreurs['code'] = t('grilles.erreur_code_pris', ['code' => $v['code']]);
        }
        if ($v['nom_en'] === '') {
            $v['nom_en'] = $v['nom_fr'];
        }
        return [$v, $erreurs];
    }

    // Outils

    /** Texte sur une ligne, sans caractères de contrôle. */
    public static function ligne(array $post, string $cle): string
    {
        $valeur = $post[$cle] ?? '';
        return is_string($valeur) ? trim((string) preg_replace('/\p{Cc}+/u', ' ', $valeur)) : '';
    }

    /** Texte sur plusieurs lignes : on garde les retours à la ligne. */
    private static function paragraphe(array $post, string $cle): string
    {
        $valeur = $post[$cle] ?? '';
        if (!is_string($valeur)) {
            return '';
        }
        $valeur = str_replace(["\r\n", "\r"], "\n", $valeur);
        return trim((string) preg_replace('/[^\P{Cc}\n]+/u', '', $valeur));
    }

    private static function entier(mixed $valeur): ?int
    {
        return is_string($valeur) && ctype_digit($valeur) && strlen($valeur) < 10 ? (int) $valeur : null;
    }

    /** « maps.app.goo.gl/xyz » → « https://maps.app.goo.gl/xyz » */
    private static function url(string $saisie): string
    {
        if ($saisie !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $saisie) && preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+(/|$)#i', $saisie)) {
            return 'https://' . $saisie;
        }
        return $saisie;
    }

    /** « @monbar » ou « monbar » → adresse complète du réseau ; une adresse est gardée telle quelle. */
    private static function reseau(string $saisie, string $base): string
    {
        // « @le.comptoir » est un nom d'utilisateur ; « le.comptoir » sans @ ressemble à une adresse
        if (preg_match('/^@([A-Za-z0-9._-]{1,60})$/', $saisie, $m) || preg_match('/^([A-Za-z0-9_-]{1,60})$/', $saisie, $m)) {
            return $base . $m[1];
        }
        return self::url($saisie);
    }

    private static function urlValide(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $schema = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($schema, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /** Chiffres, espaces, +, -, points et parenthèses ; de 6 à 15 chiffres. */
    private static function telephoneValide(string $numero): bool
    {
        if (!preg_match('/^\+?[0-9 ().-]+$/', $numero)) {
            return false;
        }
        $chiffres = strlen((string) preg_replace('/\D+/', '', $numero));
        return $chiffres >= 6 && $chiffres <= 15;
    }
}
