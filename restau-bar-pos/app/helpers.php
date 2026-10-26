<?php

declare(strict_types=1);

use App\Core\I18n;
use App\Core\View;

/** Échappe une valeur pour l'afficher dans du HTML. */
function e(mixed $valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Texte traduit de l'interface. */
function t(string $cle, array $params = []): string
{
    return I18n::texte($cle, $params);
}

/** Champ traduit d'une ligne de la base : champ($produit, 'nom') lit nom_en ou nom_fr. */
function champ(array $ligne, string $nom): string
{
    $valeur = $ligne[$nom . '_' . I18n::langue()] ?? null;
    if ($valeur === null || $valeur === '') {
        $valeur = $ligne[$nom . '_fr'] ?? '';
    }
    return (string) $valeur;
}

/** URL de la page courante avec des paramètres modifiés (null retire le paramètre). */
function urlAvec(array $params): string
{
    $chemin = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $requete = array_merge($_GET, $params);
    $requete = array_filter($requete, static fn ($v) => $v !== null && $v !== '');
    return $chemin . ($requete ? '?' . http_build_query($requete) : '');
}

function rediriger(string $url, int $code = 302): never
{
    header('Location: ' . $url, true, $code);
    exit;
}

function pageIntrouvable(): never
{
    http_response_code(404);
    View::afficher('erreurs/404', [], null);
    exit;
}

/** Jours regroupés en plages : [1,2,3,4] → « Lun – Jeu », les 7 jours → « Tous les jours ». */
function formatJours(array $jours): string
{
    $jours = array_values(array_unique($jours));
    sort($jours);
    if (count($jours) === 7) {
        return t('jours.tous');
    }
    $plages = [];
    $debut = $precedent = null;
    foreach ($jours as $jour) {
        if ($debut !== null && $jour === $precedent + 1) {
            $precedent = $jour;
            continue;
        }
        if ($debut !== null) {
            $plages[] = [$debut, $precedent];
        }
        $debut = $precedent = $jour;
    }
    $plages[] = [$debut, $precedent];

    return implode(', ', array_map(
        static fn (array $p) => $p[0] === $p[1]
            ? t('jour.' . $p[0])
            : t('jour.' . $p[0]) . ($p[1] - $p[0] > 1 ? ' – ' : ', ') . t('jour.' . $p[1]),
        $plages
    ));
}

/** Lien WhatsApp à partir d'un numéro au format international. */
/** Numéro de téléphone comparable : chiffres seulement, « + » gardé en tête (« +243 81 555 0101 » → « +243815550101 »). */
function normaliserTelephone(string $numero): string
{
    $numero = trim($numero);
    $plus = str_starts_with($numero, '+') ? '+' : '';
    return $plus . preg_replace('/\D+/', '', $numero);
}

/** Numéro lisible : « +243810000101 » → « +243 81 000 0101 » (RDC), « +250788123456 » → « +250 788 123 456 » (Rwanda). */
function afficherTelephone(?string $numero): string
{
    $numero = (string) $numero;
    if (preg_match('/^\+243(\d{2})(\d{3})(\d{4})$/', $numero, $m)) {
        return '+243 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
    }
    if (preg_match('/^\+(\d{3})(\d{3})(\d{3})(\d{3})$/', $numero, $m)) {
        return '+' . $m[1] . ' ' . $m[2] . ' ' . $m[3] . ' ' . $m[4];
    }
    return $numero;
}

function lienWhatsapp(string $numero): string
{
    return 'https://wa.me/' . preg_replace('/\D+/', '', $numero);
}

/** Chemin d'image utilisable dans du CSS (chemin local ou https), sinon null. */
function urlImageSure(?string $url): ?string
{
    if ($url === null || !preg_match('#^(/|https://)[^\s\'"()\\\\<>]+$#', $url)) {
        return null;
    }
    return $url;
}

/** Couleur au format #RRGGBB (en majuscules), sinon null. */
function couleurValide(?string $couleur): ?string
{
    return $couleur !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $couleur) ? strtoupper($couleur) : null;
}

/** Luminance relative d'une couleur #RRGGBB (WCAG 2), de 0 (noir) à 1 (blanc). */
function luminanceCouleur(string $hex): float
{
    $canaux = array_map(static function (string $paire): float {
        $v = hexdec($paire) / 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, str_split(substr($hex, 1, 6), 2));
    return 0.2126 * $canaux[0] + 0.7152 * $canaux[1] + 0.0722 * $canaux[2];
}

/** Rapport de contraste entre deux couleurs #RRGGBB (de 1 à 21). */
function contrasteCouleurs(string $a, string $b): float
{
    $la = luminanceCouleur($a);
    $lb = luminanceCouleur($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** Couleur du texte posé sur un fond #RRGGBB : noir basalte (#111315) ou blanc, selon le meilleur contraste. */
function texteSurCouleur(string $fond): string
{
    return contrasteCouleurs($fond, '#111315') >= contrasteCouleurs($fond, '#FFFFFF') ? '#111315' : '#FFFFFF';
}

/**
 * Variante d'une couleur utilisable comme texte sur un fond donné : assombrie (fond clair)
 * ou éclaircie (fond sombre) par petits pas jusqu'au contraste demandé.
 */
function couleurLisible(string $couleur, string $fond, float $ratio = 4.5): string
{
    $cible = luminanceCouleur($fond) > 0.4 ? 0 : 255;
    $rgb = array_map('hexdec', str_split(substr($couleur, 1, 6), 2));
    $resultat = strtoupper($couleur);
    for ($pas = 0; $pas <= 20 && contrasteCouleurs($resultat, $fond) < $ratio; $pas++) {
        $rgb = array_map(static fn (float|int $v): float => $v + ($cible - $v) * 0.08, $rgb);
        $resultat = sprintf('#%02X%02X%02X', ...array_map(static fn (float $v): int => (int) round($v), $rgb));
    }
    return $resultat;
}

/** Mention « Photo : … » d'un crédit photo, sans répéter « Photo : » si l'auteur l'a déjà saisi. */
function creditPhoto(string $credit): string
{
    $credit = trim((string) preg_replace('/^(photo|crédit|credit)\s*:\s*/iu', '', trim($credit)));
    return t('vitrine.photo_credit', ['credit' => $credit]);
}
