<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\I18n;
use App\Repositories\GestionCatalogueRepository;

/**
 * Lecture et validation des formulaires du catalogue.
 * Chaque méthode rend [valeurs propres, messages d'erreur].
 */
final class CatalogueSaisie
{
    public function __construct(private readonly GestionCatalogueRepository $catalogue)
    {
    }

    /** Section « Informations » de la fiche produit. */
    public function informations(array $post): array
    {
        $erreurs = [];
        $valeurs = [
            'categorie_id' => (int) ($post['categorie_id'] ?? 0),
            'nom_fr' => self::texte($post, 'nom_fr'),
            'nom_en' => self::texte($post, 'nom_en'),
            'description_fr' => self::texte($post, 'description_fr', true),
            'description_en' => self::texte($post, 'description_en', true),
            'unite_base' => (string) ($post['unite_base'] ?? ''),
            'allergenes_fr' => self::texte($post, 'allergenes_fr'),
            'allergenes_en' => self::texte($post, 'allergenes_en'),
        ];
        $badges = is_array($post['badges'] ?? null) ? $post['badges'] : [];
        $valeurs['badges'] = implode(',', array_values(array_intersect(GestionCatalogueRepository::BADGES, $badges)));

        if ($this->catalogue->categorie($valeurs['categorie_id']) === null) {
            $erreurs[] = t('catalogue.erreur_categorie');
        }
        if ($valeurs['nom_fr'] === '') {
            $erreurs[] = t('catalogue.erreur_nom_produit');
        }
        foreach (['nom_fr' => 128, 'nom_en' => 128, 'allergenes_fr' => 255, 'allergenes_en' => 255, 'description_fr' => 2000, 'description_en' => 2000] as $cle => $max) {
            if (mb_strlen($valeurs[$cle]) > $max) {
                $erreurs[] = t('catalogue.erreur_trop_long', ['champ' => t('catalogue.champ_' . $cle), 'max' => $max]);
            }
        }
        if (!in_array($valeurs['unite_base'], GestionCatalogueRepository::UNITES, true)) {
            $erreurs[] = t('catalogue.erreur_unite');
        }
        foreach (['description_fr', 'description_en', 'allergenes_fr', 'allergenes_en'] as $cle) {
            $valeurs[$cle] = $valeurs[$cle] === '' ? null : $valeurs[$cle];
        }
        $valeurs['badges'] = $valeurs['badges'] === '' ? null : $valeurs['badges'];
        return [$valeurs, $erreurs];
    }

    /** Section « Où il est vendu ». */
    public function vente(array $post, array $etablissements): array
    {
        $coches = is_array($post['etablissements'] ?? null) ? array_map('intval', $post['etablissements']) : [];
        $ids = array_values(array_intersect(array_map('intval', array_column($etablissements, 'id')), $coches));
        return [[
            'etablissements' => $ids,
            'visible_public' => !empty($post['visible_public']),
            'disponible' => !empty($post['disponible']),
        ], []];
    }

    /** Un format (conditionnement). */
    public function format(array $post, int $saufFormatId = 0): array
    {
        $erreurs = [];
        $valeurs = [
            'nom_fr' => self::texte($post, 'nom_fr'),
            'nom_en' => self::texte($post, 'nom_en'),
            'quantite_saisie' => self::texte($post, 'quantite_base'),
            'vendable' => !empty($post['vendable']),
            'achetable' => !empty($post['achetable']),
            'code_barres' => self::texte($post, 'code_barres'),
        ];
        if ($valeurs['nom_fr'] === '') {
            $erreurs[] = t('catalogue.erreur_nom_format');
        }
        if (mb_strlen($valeurs['nom_fr']) > 64 || mb_strlen($valeurs['nom_en']) > 64) {
            $erreurs[] = t('catalogue.erreur_trop_long', ['champ' => t('catalogue.format_nom'), 'max' => 64]);
        }

        $quantite = self::nombre($valeurs['quantite_saisie']);
        if ($quantite === null || $quantite <= 0 || $quantite >= 1e11 || self::decimales($valeurs['quantite_saisie']) > 3) {
            $erreurs[] = t('catalogue.erreur_quantite');
        }
        $valeurs['quantite_base'] = $quantite === null ? null : number_format($quantite, 3, '.', '');

        if ($valeurs['code_barres'] !== '') {
            if (mb_strlen($valeurs['code_barres']) > 64 || !preg_match('/^[0-9A-Za-z.\- ]+$/', $valeurs['code_barres'])) {
                $erreurs[] = t('catalogue.erreur_code_barres');
            } elseif (($autre = $this->catalogue->codeBarresUtilise($valeurs['code_barres'], $saufFormatId)) !== null) {
                $erreurs[] = t('catalogue.erreur_code_barres_pris', ['produit' => champ($autre, 'nom') . ' · ' . champ($autre, 'format')]);
            }
        }
        $valeurs['code_barres'] = $valeurs['code_barres'] === '' ? null : $valeurs['code_barres'];
        return [$valeurs, $erreurs];
    }

    /**
     * Montant saisi dans une case de prix : null si la case est vide.
     * @return array{?float, ?string} [montant, message d'erreur]
     */
    public function montant(string $saisie, array $devise, string $libelle): array
    {
        $saisie = trim($saisie);
        if ($saisie === '') {
            return [null, null];
        }
        $montant = self::nombre($saisie);
        if ($montant === null || $montant <= 0) {
            return [null, t('catalogue.erreur_prix', ['case' => $libelle])];
        }
        $decimales = min(2, (int) $devise['decimales']);
        if (self::decimales($saisie) > $decimales) {
            return [null, $decimales === 0
                ? t('catalogue.erreur_prix_entier', ['case' => $libelle, 'devise' => $devise['code']])
                : t('catalogue.erreur_prix_decimales', ['case' => $libelle, 'n' => $decimales])];
        }
        if ($montant >= 1e12) {
            return [null, t('catalogue.erreur_prix', ['case' => $libelle])];
        }
        return [round($montant, $decimales), null];
    }

    /** Montant prêt à être modifié dans un champ : « 2,50 » en français, « 2.50 » en anglais. */
    public static function pourSaisie(float $montant, int $decimales): string
    {
        return number_format($montant, min(2, $decimales), I18n::langue() === 'fr' ? ',' : '.', '');
    }

    /** Quantité sans zéros inutiles : 70.000 → « 70 », 0.250 → « 0,25 ». */
    public static function quantite(string|float $quantite): string
    {
        $texte = rtrim(rtrim(number_format((float) $quantite, 3, '.', ''), '0'), '.');
        return I18n::langue() === 'fr' ? str_replace('.', ',', $texte) : $texte;
    }

    private static function texte(array $post, string $cle, bool $multiligne = false): string
    {
        $valeur = $post[$cle] ?? '';
        if (!is_string($valeur)) {
            return '';
        }
        $valeur = trim(str_replace("\r\n", "\n", $valeur));
        // Caractères de contrôle retirés (les retours à la ligne restent permis dans les descriptions)
        return (string) preg_replace($multiligne ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u', '', $valeur);
    }

    /** « 5 500 », « 2,50 », « 1,234.50 » → nombre, sinon null. */
    private static function nombre(string $saisie): ?float
    {
        $propre = self::normaliser($saisie);
        return preg_match('/^\d+(\.\d+)?$/', $propre) ? (float) $propre : null;
    }

    private static function decimales(string $saisie): int
    {
        $propre = self::normaliser($saisie);
        $point = strpos($propre, '.');
        return $point === false ? 0 : strlen($propre) - $point - 1;
    }

    private static function normaliser(string $saisie): string
    {
        $propre = (string) preg_replace('/[\s\x{00A0}\x{202F}\']+/u', '', $saisie);
        // Les deux séparateurs présents : le premier sépare les milliers
        if (str_contains($propre, ',') && str_contains($propre, '.')) {
            $propre = strrpos($propre, ',') > strrpos($propre, '.')
                ? str_replace('.', '', $propre)
                : str_replace(',', '', $propre);
        }
        return str_replace(',', '.', $propre);
    }
}
