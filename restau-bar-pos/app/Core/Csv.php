<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Export des rapports en CSV pour un tableur : UTF-8 avec BOM (Excel reconnaît les accents), séparateur « ; »
 * (Excel en français), montants en nombres simples sans séparateur de milliers (virgule décimale en français,
 * point en anglais), devise dans sa propre colonne. Chaque écran exporte avec ?export=csv dans son adresse,
 * les mêmes filtres que ce qu'il affiche.
 *
 * Une cellule de texte qui commence par = + - @ est préfixée d'une apostrophe : le tableur ne l'exécute pas
 * comme une formule (un libellé saisi par un client ne peut pas devenir une formule).
 */
final class Csv
{
    public const SEPARATEUR = ';';

    /** L'écran est-il demandé en CSV ? */
    public static function demande(): bool
    {
        return ($_GET['export'] ?? '') === 'csv';
    }

    /** Montant ou quantité pour le tableur : « 1234,5 » (fr) ou « 1234.5 » (en), vide si null. */
    public static function nombre(float|int|string|null $valeur, int $decimales = 2): string
    {
        if ($valeur === null || $valeur === '') {
            return '';
        }
        $valeur = round((float) $valeur, $decimales);
        if (abs($valeur) < 0.5 * 10 ** -$decimales) {
            $valeur = 0.0;
        }
        return number_format($valeur, $decimales, I18n::langue() === 'fr' ? ',' : '.', '');
    }

    /** Montant dans une devise : autant de décimales qu'elle en a (0 pour le franc congolais). */
    public static function montant(float|int|string|null $valeur, ?array $devise): string
    {
        return self::nombre($valeur, $devise !== null ? (int) $devise['decimales'] : 2);
    }

    /** Date « Y-m-d H:i » ou « Y-m-d » (lisible par tous les tableurs). */
    public static function date(?string $date, bool $heure = true): string
    {
        if ($date === null || $date === '' || ($t = strtotime($date)) === false) {
            return '';
        }
        return date($heure ? 'Y-m-d H:i' : 'Y-m-d', $t);
    }

    /** Nom de fichier sûr : « ventes-2026-10-01_2026-10-26.csv ». */
    public static function nomFichier(string $nom, ?array $periode = null): string
    {
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom) ?: 'export'));
        if ($periode !== null && ($periode['du'] ?? null) !== null) {
            $base .= '-' . $periode['du'] . '_' . ($periode['au'] ?? $periode['du']);
        } else {
            $base .= '-' . date('Y-m-d');
        }
        return trim($base, '-') . '.csv';
    }

    /** Contenu CSV (BOM compris) d'un en-tête et de lignes. */
    public static function contenu(array $entetes, iterable $lignes): string
    {
        $flux = fopen('php://temp', 'r+');
        fwrite($flux, "\u{FEFF}");
        fputcsv($flux, array_map([self::class, 'cellule'], $entetes), self::SEPARATEUR, '"', '');
        foreach ($lignes as $ligne) {
            fputcsv($flux, array_map([self::class, 'cellule'], array_values($ligne)), self::SEPARATEUR, '"', '');
        }
        rewind($flux);
        $texte = (string) stream_get_contents($flux);
        fclose($flux);
        return $texte;
    }

    /** Envoie le fichier au navigateur (téléchargement) et termine la requête. */
    public static function envoyer(string $fichier, array $entetes, iterable $lignes): never
    {
        $texte = self::contenu($entetes, $lignes);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fichier . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . strlen($texte));
        echo $texte;
        exit;
    }

    /** Valeur d'une cellule : les nombres déjà formatés passent tels quels, un texte « =… » est neutralisé. */
    private static function cellule(mixed $valeur): string
    {
        if ($valeur === null) {
            return '';
        }
        if (is_bool($valeur)) {
            return $valeur ? '1' : '0';
        }
        if (is_int($valeur) || is_float($valeur)) {
            return (string) $valeur;
        }
        $texte = str_replace(["\r\n", "\r"], "\n", (string) $valeur);
        // Un nombre formaté par nombre() (« -1234,5 ») reste un nombre ; tout autre texte « -… », « =… » est protégé
        if ($texte !== '' && preg_match('/^[=+\-@\t]/', $texte) && !preg_match('/^-?\d+([.,]\d+)?$/', $texte)) {
            $texte = "'" . $texte;
        }
        return $texte;
    }
}
