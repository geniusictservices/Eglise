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
