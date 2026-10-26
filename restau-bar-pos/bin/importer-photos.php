<?php

declare(strict_types=1);

/**
 * Importe les photos libres de droits des données de démonstration depuis Wikimedia Commons.
 *
 *   php bin/importer-photos.php --instance=demo            import
 *   php bin/importer-photos.php --instance=demo --essai    recherche seulement : n'écrit rien
 *   php bin/importer-photos.php --instance=demo --forcer   remplace aussi les photos déjà en place
 *   php bin/importer-photos.php --sql                      affiche les UPDATE des photos importées (pour une nouvelle démo)
 *
 * Liste des produits et des requêtes de recherche : database/photos-demo.json.
 * Seules les licences libres qui permettent l'usage commercial sont retenues (CC0, domaine public,
 * CC BY, CC BY-SA), en JPEG ou PNG, en paysage et d'au moins 800 px de large, sans nom de marque.
 * Les images sont ré-encodées en JPEG dans public/assets/img/demo/ ; les crédits sont enregistrés
 * dans la base (photo_credit, couverture_credit), dans public/assets/img/demo/credits.json
 * et dans docs/credits-photos.md.
 *
 * Une photo envoyée depuis la gestion n'est jamais remplacée sans --forcer ; les illustrations SVG
 * de démonstration (public/assets/img/demo/…svg) le sont.
 *
 * Codes de sortie : 0 tout est importé, 1 erreur d'utilisation ou de configuration,
 * 2 photo introuvable pour certaines entrées, 3 accès réseau refusé.
 *
 * Options de test (non documentées dans l'aide) : --analyser=<réponse.json> analyse une réponse
 * enregistrée de l'API sans réseau ; --api=<url> remplace l'adresse de l'API ; --delai=<secondes>.
 */

use App\Core\Database;
use App\Core\Instance;

if (PHP_SAPI !== 'cli') {
    exit("À lancer en ligne de commande.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

const PHOTOS_API = 'https://commons.wikimedia.org/w/api.php';
const PHOTOS_DOMAINES = ['commons.wikimedia.org', 'upload.wikimedia.org'];
const PHOTOS_USER_AGENT = 'restau-bar-pos/1.0 (import de photos de démonstration)';
const PHOTOS_LARGEUR_MIN = 800;
const PHOTOS_COTE_MAX = 1000;
const PHOTOS_QUALITE = 82;
const PHOTOS_OCTETS_MAX = 15 * 1024 * 1024;
const PHOTOS_PIXELS_MAX = 40_000_000;
const PHOTOS_DELAI = 1.0;
const PHOTOS_CREDIT_MAX = 255;

/** Accès refusé par le réseau (proxy, pare-feu, DNS) : l'import s'arrête. */
final class AccesReseauRefuse extends RuntimeException
{
    public function __construct(public readonly string $hote, string $detail)
    {
        parent::__construct($detail);
    }
}

// ---------------------------------------------------------------------------
// Liste des photos à importer
// ---------------------------------------------------------------------------

/**
 * Lit et contrôle database/photos-demo.json.
 *
 * @return array{marques: string[], mots: string[], entrees: list<array{type: string, cle: string, id: ?int, nom: string, slug: string, recherches: string[]}>}
 */
function chargerListe(string $fichier): array
{
    $donnees = json_decode((string) @file_get_contents($fichier), true);
    if (!is_array($donnees)) {
        throw new RuntimeException("Fichier illisible ou JSON invalide : $fichier");
    }
    $entrees = [];
    foreach (['produits' => 'produit', 'etablissements' => 'etablissement'] as $section => $type) {
        foreach ($donnees[$section] ?? [] as $n => $entree) {
            $id = $type === 'produit' ? ($entree['id'] ?? null) : null;
            $slug = (string) ($entree['slug'] ?? '');
            $recherches = array_values(array_filter((array) ($entree['recherches'] ?? []), 'is_string'));
            if (($type === 'produit' && (!is_int($id) || $id < 1)) || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || $recherches === []) {
                throw new RuntimeException("Entrée invalide dans $fichier : $section n° " . ($n + 1) . ' (id, slug en minuscules et tirets, recherches)');
            }
            $entrees[] = [
                'type' => $type,
                'cle' => $type === 'produit' ? (string) $id : $slug,
                'id' => $id,
                'nom' => (string) ($entree['nom'] ?? ''),
                'slug' => $slug,
                'recherches' => $recherches,
            ];
        }
    }
    return [
        'marques' => array_values(array_filter((array) ($donnees['marques_exclues'] ?? []), 'is_string')),
        'mots' => array_values(array_filter((array) ($donnees['mots_exclus'] ?? []), 'is_string')),
        'entrees' => $entrees,
    ];
}

// ---------------------------------------------------------------------------
// Analyse des réponses de l'API Commons
// ---------------------------------------------------------------------------

function urlRecherche(string $api, string $requete): string
{
    return $api . '?' . http_build_query([
        'action' => 'query',
        'format' => 'json',
        'formatversion' => '2',
        'generator' => 'search',
        'gsrsearch' => $requete . ' filetype:bitmap',
        'gsrnamespace' => '6',
        'gsrlimit' => '30',
        'prop' => 'imageinfo',
        'iiprop' => 'url|extmetadata|mime|size',
        'iiurlwidth' => '1200',
        'iiextmetadatafilter' => 'Artist|Credit|LicenseShortName|License|LicenseUrl|UsageTerms|NonFree|Restrictions|ObjectName|ImageDescription',
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Texte en minuscules, sans accents ni ponctuation, mots séparés par une espace. */
function normaliser(string $texte): string
{
    if (class_exists(Normalizer::class)) {
        $texte = (string) Normalizer::normalize($texte, Normalizer::FORM_D);
        $texte = (string) preg_replace('/\p{Mn}+/u', '', $texte);
    }
    $texte = mb_strtolower($texte, 'UTF-8');
    $texte = strtr($texte, ['œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', 'ø' => 'o', 'ł' => 'l']);
    return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $texte));
}

/** Premier nom de la liste présent dans le texte (mots entiers), sinon null. */
function motTrouve(string $texte, array $liste): ?string
{
    $texte = ' ' . normaliser($texte) . ' ';
    foreach ($liste as $mot) {
        $cherche = normaliser($mot);
        if ($cherche !== '' && str_contains($texte, ' ' . $cherche . ' ')) {
            return $mot;
        }
    }
    return null;
}

/** Texte lisible d'un champ HTML de Commons (auteur, description) : sans balises ni entités. */
function texteBrut(string $html): string
{
    $html = (string) preg_replace('#<(br|/p|/div|/li)\b[^>]*>#i', ' ', $html);
    $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texte = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $texte);
    return trim((string) preg_replace('/\s+/u', ' ', $texte));
}

/**
 * Licence libre permettant l'usage commercial, sinon null.
 *
 * @return array{nom: string, url: string, attribution: bool}|null
 */
function licenceLibre(array $meta): ?array
{
    $valeur = static fn (string $cle): string => trim((string) ($meta[$cle]['value'] ?? ''));
    if (in_array(strtolower($valeur('NonFree')), ['true', '1', 'yes'], true)) {
        return null;
    }
    $nom = texteBrut($valeur('LicenseShortName'));
    $url = $valeur('LicenseUrl');
    if ($url !== '' && str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if ($url !== '' && !preg_match('#^https?://[^\s<>"]+$#', $url)) {
        $url = '';
    }

    foreach ([$valeur('License'), $nom] as $code) {
        $code = (string) preg_replace('/[\s_]+/', '-', strtolower(trim($code)));
        if ($code === '') {
            continue;
        }
        // Pas d'utilisation commerciale (NC) ni de modification interdite (ND)
        if (preg_match('/(^|-)(nc|nd)(-|$)/', $code)) {
            return null;
        }
        if (preg_match('/^(cc-?0|cc-zero)(-1\.0)?$/', $code)) {
            return ['nom' => 'CC0', 'url' => $url ?: 'https://creativecommons.org/publicdomain/zero/1.0/', 'attribution' => false];
        }
        if (preg_match('/^(pd|public-domain)(-[a-z0-9.-]+)?$/', $code)) {
            return ['nom' => 'Public domain', 'url' => $url, 'attribution' => false];
        }
        if (preg_match('/^cc-by(-sa)?(-\d+(\.\d+)?)?(-[a-z]{2,3}){0,2}$/', $code)) {
            return ['nom' => $nom !== '' ? $nom : strtoupper(str_replace('cc-by', 'cc by', $code)), 'url' => $url, 'attribution' => true];
        }
    }
    return null;
}

/** Crédit affiché : « Auteur · Licence · Wikimedia Commons », 255 caractères au plus. */
function formaterCredit(string $auteur, string $licence): string
{
    $fin = ' · ' . $licence . ' · Wikimedia Commons';
    $place = PHOTOS_CREDIT_MAX - mb_strlen($fin);
    if (mb_strlen($auteur) > $place) {
        $auteur = rtrim(mb_substr($auteur, 0, $place - 1)) . '…';
    }
    return $auteur . $fin;
}

/**
 * Trie les fichiers d'une réponse de l'API : candidats retenus (dans l'ordre de pertinence) et écartés avec la raison.
 *
 * @param string[] $dejaPris titres déjà utilisés pour une autre entrée
 * @return array{retenus: list<array>, ecartes: list<array{titre: string, raison: string}>}
 */
function analyserReponse(array $reponse, array $marques, array $mots, array $dejaPris = []): array
{
    $pages = $reponse['query']['pages'] ?? [];
    if (!is_array($pages)) {
        $pages = [];
    }
    $pages = array_values(array_filter($pages, 'is_array'));
    usort($pages, static fn (array $a, array $b) => ($a['index'] ?? PHP_INT_MAX) <=> ($b['index'] ?? PHP_INT_MAX));

    $retenus = [];
    $ecartes = [];
    foreach ($pages as $page) {
        $titre = (string) ($page['title'] ?? '');
        $info = $page['imageinfo'][0] ?? null;
        $ecarter = static function (string $raison) use (&$ecartes, $titre): void {
            $ecartes[] = ['titre' => $titre, 'raison' => $raison];
        };
        if (!is_array($info)) {
            $ecarter('pas d\'informations sur l\'image');
            continue;
        }
        if (in_array($titre, $dejaPris, true)) {
            $ecarter('déjà utilisée pour une autre entrée');
            continue;
        }
        $meta = is_array($info['extmetadata'] ?? null) ? $info['extmetadata'] : [];
        $mime = (string) ($info['mime'] ?? '');
        $largeur = (int) ($info['width'] ?? 0);
        $hauteur = (int) ($info['height'] ?? 0);

        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $ecarter("format $mime");
            continue;
        }
        if ($largeur < PHOTOS_LARGEUR_MIN) {
            $ecarter("trop petite ({$largeur} px de large)");
            continue;
        }
        if ($largeur <= $hauteur) {
            $ecarter("pas en paysage ({$largeur} × {$hauteur})");
            continue;
        }
        $licence = licenceLibre($meta);
        if ($licence === null) {
            $ecarter('licence non retenue (' . (texteBrut((string) ($meta['LicenseShortName']['value'] ?? '')) ?: 'inconnue') . ')');
            continue;
        }
        if (str_contains(strtolower((string) ($meta['Restrictions']['value'] ?? '')), 'trademark')) {
            $ecarter('marque déposée visible (restriction « trademarked »)');
            continue;
        }
        $texteMarques = $titre . ' ' . texteBrut((string) ($meta['ObjectName']['value'] ?? '')) . ' ' . texteBrut((string) ($meta['ImageDescription']['value'] ?? ''));
        if (($marque = motTrouve($texteMarques, $marques)) !== null) {
            $ecarter("nom de marque « $marque »");
            continue;
        }
        if (($mot = motTrouve($titre, $mots)) !== null) {
            $ecarter("mot exclu « $mot » dans le titre");
            continue;
        }
        $auteur = mb_substr(texteBrut((string) ($meta['Artist']['value'] ?? '')), 0, 150);
        if ($auteur === '' && $licence['attribution']) {
            $auteur = mb_substr(texteBrut((string) ($meta['Credit']['value'] ?? '')), 0, 150);
        }
        if ($auteur === '') {
            if ($licence['attribution']) {
                $ecarter('auteur inconnu alors que la licence demande de le citer');
                continue;
            }
            $auteur = 'Auteur inconnu';
        }
        $vignette = (string) ($info['thumburl'] ?? $info['url'] ?? '');
        $pageSource = (string) ($info['descriptionurl'] ?? '');
        if ($vignette === '' || $pageSource === '') {
            $ecarter('adresse de l\'image absente');
            continue;
        }
        $retenus[] = [
            'titre' => $titre,
            'vignette' => $vignette,
            'page' => $pageSource,
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'auteur' => $auteur,
            'licence' => $licence['nom'],
            'licence_url' => $licence['url'],
            'credit' => formaterCredit($auteur, $licence['nom']),
        ];
    }
    return ['retenus' => $retenus, 'ecartes' => $ecartes];
}

// ---------------------------------------------------------------------------
// Réseau
// ---------------------------------------------------------------------------

/**
 * Télécharge une adresse (HTTPS, ou HTTP pour un serveur de test local) et rend le contenu.
 *
 * @throws AccesReseauRefuse quand le réseau refuse l'accès à l'hôte
 * @throws RuntimeException  pour les autres erreurs
 */
function telecharger(string $url, int $octetsMax): string
{
    $hote = (string) parse_url($url, PHP_URL_HOST);
    for ($essai = 1; ; $essai++) {
        [$code, $corps, $attente] = function_exists('curl_init')
            ? requeteCurl($url, $hote, $octetsMax)
            : requeteFlux($url, $hote, $octetsMax);
        if ($code === 200) {
            return $corps;
        }
        // Trop de requêtes ou serveur occupé : on attend puis on réessaie
        if (in_array($code, [429, 502, 503, 504], true) && $essai < 3) {
            $secondes = max(2, min(60, $attente ?? 5 * $essai));
            echo "    Serveur occupé (HTTP $code), nouvel essai dans $secondes s…\n";
            sleep($secondes);
            continue;
        }
        if ($code === 407) {
            throw new AccesReseauRefuse($hote, "HTTP $code");
        }
        $extrait = mb_substr(texteBrut($corps), 0, 160);
        throw new RuntimeException("HTTP $code pour $url" . ($extrait !== '' ? " ($extrait)" : ''));
    }
}

/** @return array{int, string, ?int} code HTTP, corps, délai Retry-After */
function requeteCurl(string $url, string $hote, int $octetsMax): array
{
    $corps = '';
    $attente = null;
    $curl = curl_init($url);
    $options = [
        CURLOPT_USERAGENT => PHOTOS_USER_AGENT,
        CURLOPT_HTTPHEADER => ['Accept: application/json, image/jpeg, image/png;q=0.9, */*;q=0.1'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($c, string $morceau) use (&$corps, $octetsMax): int {
            if (strlen($corps) + strlen($morceau) > $octetsMax) {
                return 0; // interrompt le transfert
            }
            $corps .= $morceau;
            return strlen($morceau);
        },
        CURLOPT_HEADERFUNCTION => static function ($c, string $ligne) use (&$attente): int {
            if (preg_match('/^Retry-After:\s*(\d+)/i', $ligne, $m)) {
                $attente = (int) $m[1];
            }
            return strlen($ligne);
        },
    ];
    // Certificats du proxy de l'environnement, s'il y en a un
    foreach (['CURL_CA_BUNDLE', 'SSL_CERT_FILE'] as $variable) {
        $ca = getenv($variable);
        if ($ca !== false && is_file($ca)) {
            $options[CURLOPT_CAINFO] = $ca;
            break;
        }
    }
    curl_setopt_array($curl, $options);
    $ok = curl_exec($curl);
    $erreur = curl_errno($curl);
    $message = curl_error($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($ok === false || $erreur !== 0) {
        if ($erreur === CURLE_WRITE_ERROR) {
            throw new RuntimeException('Fichier trop lourd (plus de ' . intdiv($octetsMax, 1048576) . " Mo) : $url");
        }
        // Proxy introuvable, hôte inconnu, connexion refusée, tunnel CONNECT refusé, délai dépassé, TLS
        if (in_array($erreur, [5, 6, 7, 28, 56, 97], true) || str_contains($message, 'CONNECT')) {
            throw new AccesReseauRefuse($hote, $message);
        }
        throw new RuntimeException("Téléchargement impossible ($message) : $url");
    }
    return [$code, $corps, $attente];
}

/** Variante sans l'extension curl, avec le proxy indiqué par HTTPS_PROXY. */
function requeteFlux(string $url, string $hote, int $octetsMax): array
{
    $http = [
        'method' => 'GET',
        'header' => "User-Agent: " . PHOTOS_USER_AGENT . "\r\nAccept: */*\r\n",
        'timeout' => 60,
        'ignore_errors' => true,
        'follow_location' => 1,
        'max_redirects' => 3,
    ];
    $proxy = getenv(str_starts_with($url, 'https:') ? 'HTTPS_PROXY' : 'HTTP_PROXY') ?: getenv(str_starts_with($url, 'https:') ? 'https_proxy' : 'http_proxy');
    if ($proxy && !in_array($hote, ['127.0.0.1', 'localhost'], true)) {
        $http['proxy'] = 'tcp://' . preg_replace('#^https?://#', '', rtrim($proxy, '/'));
    }
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    $ca = getenv('SSL_CERT_FILE') ?: getenv('CURL_CA_BUNDLE');
    if ($ca && is_file($ca)) {
        $ssl['cafile'] = $ca;
    }
    $flux = @fopen($url, 'rb', false, stream_context_create(['http' => $http, 'ssl' => $ssl]));
    if ($flux === false) {
        throw new AccesReseauRefuse($hote, (string) (error_get_last()['message'] ?? 'connexion impossible'));
    }
    $corps = (string) stream_get_contents($flux, $octetsMax + 1);
    $entetes = stream_get_meta_data($flux)['wrapper_data'] ?? [];
    fclose($flux);
    if (strlen($corps) > $octetsMax) {
        throw new RuntimeException('Fichier trop lourd (plus de ' . intdiv($octetsMax, 1048576) . " Mo) : $url");
    }
    $code = 0;
    $attente = null;
    foreach ((array) $entetes as $ligne) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $ligne, $m)) {
            $code = (int) $m[1];
        } elseif (preg_match('/^Retry-After:\s*(\d+)/i', (string) $ligne, $m)) {
            $attente = (int) $m[1];
        }
    }
    return [$code, $corps, $attente];
}

/** Message affiché quand le réseau refuse l'accès. */
function messageAccesRefuse(AccesReseauRefuse $e, string $sousDomaine): string
{
    $domaines = implode("\n", array_map(static fn (string $d) => "    - $d", PHOTOS_DOMAINES));
    return <<<TXT

Accès réseau refusé à {$e->hote} ({$e->getMessage()}).

Pour importer les photos, autorisez ces domaines dans les réglages réseau de l'environnement
(Network access → domaines autorisés) :
$domaines

Puis relancez : php bin/importer-photos.php --instance=$sousDomaine
Les photos déjà importées sont conservées.

TXT;
}

// ---------------------------------------------------------------------------
// Image
// ---------------------------------------------------------------------------

/**
 * Contrôle une image téléchargée (type réel, dimensions, paysage), la ré-encode en JPEG avec GD
 * et l'écrit dans $destination (fichier temporaire puis renommage).
 *
 * @return array{int, int} largeur et hauteur de l'image enregistrée
 * @throws RuntimeException image refusée ou illisible
 */
function reencoderImage(string $donnees, string $destination, int $coteMax = PHOTOS_COTE_MAX, int $qualite = PHOTOS_QUALITE, int $largeurMin = PHOTOS_LARGEUR_MIN): array
{
    $types = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($donnees) ?: '';
    $infos = @getimagesizefromstring($donnees);
    if (!isset($types[$mime]) || $infos === false || $infos[2] !== $types[$mime]) {
        throw new RuntimeException('type refusé (' . ($mime ?: 'inconnu') . '), seuls JPEG et PNG sont acceptés');
    }
    [$largeur, $hauteur] = $infos;
    if ($largeur < 1 || $hauteur < 1 || $largeur * $hauteur > PHOTOS_PIXELS_MAX) {
        throw new RuntimeException("dimensions refusées ({$largeur} × {$hauteur})");
    }
    $memoire = (string) ini_get('memory_limit');
    if ($memoire !== '-1' && (int) $memoire < 256 && str_ends_with(strtoupper($memoire), 'M')) {
        ini_set('memory_limit', '256M');
    }

    $image = @imagecreatefromstring($donnees);
    if ($image === false) {
        throw new RuntimeException('image illisible');
    }
    if ($infos[2] === IMAGETYPE_JPEG) {
        $image = redresser($image, $donnees);
    }
    $largeur = imagesx($image);
    $hauteur = imagesy($image);
    if ($largeur <= $hauteur) {
        imagedestroy($image);
        throw new RuntimeException("pas en paysage ({$largeur} × {$hauteur})");
    }
    if ($largeur < $largeurMin) {
        imagedestroy($image);
        throw new RuntimeException("trop petite ({$largeur} px de large, minimum $largeurMin)");
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

    $repertoire = dirname($destination);
    if (!is_dir($repertoire) && !mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
        imagedestroy($finale);
        throw new RuntimeException("impossible de créer le dossier $repertoire");
    }
    $temporaire = $repertoire . '/.' . basename($destination) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = imagejpeg($finale, $temporaire, $qualite);
    imagedestroy($finale);
    if (!$ok || !rename($temporaire, $destination)) {
        @unlink($temporaire);
        throw new RuntimeException("impossible d'écrire $destination");
    }
    return [$nouvelleLargeur, $nouvelleHauteur];
}

/** Remet droite une photo selon son orientation EXIF. */
function redresser(GdImage $image, string $donnees): GdImage
{
    if (!function_exists('exif_read_data')) {
        return $image;
    }
    $flux = fopen('php://memory', 'r+b');
    fwrite($flux, $donnees);
    rewind($flux);
    $exif = @exif_read_data($flux);
    fclose($flux);
    $angle = match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)) {
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

// ---------------------------------------------------------------------------
// Crédits
// ---------------------------------------------------------------------------

function fichierManifeste(): string
{
    return RACINE . '/public/assets/img/demo/credits.json';
}

/** @return array{produits: array<string, array>, etablissements: array<string, array>} */
function lireManifeste(string $fichier): array
{
    $donnees = is_file($fichier) ? json_decode((string) file_get_contents($fichier), true) : null;
    return [
        'produits' => is_array($donnees['produits'] ?? null) ? $donnees['produits'] : [],
        'etablissements' => is_array($donnees['etablissements'] ?? null) ? $donnees['etablissements'] : [],
    ];
}

function ecrireManifeste(string $fichier, array $manifeste): void
{
    ksort($manifeste['produits'], SORT_NATURAL);
    ksort($manifeste['etablissements']);
    $json = json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (!is_dir(dirname($fichier))) {
        mkdir(dirname($fichier), 0775, true);
    }
    file_put_contents($fichier, $json);
}

/** Contenu de docs/credits-photos.md. */
function documentCredits(array $manifeste): string
{
    $cellule = static fn (string $texte): string => str_replace(['|', "\n"], ['\|', ' '], $texte);
    $lignes = [
        '# Crédits des photos de démonstration',
        '',
        'Photos libres de droits importées depuis [Wikimedia Commons](https://commons.wikimedia.org/) par `php bin/importer-photos.php`.',
        'Ce fichier est réécrit à chaque import : ne pas le modifier à la main. Voir aussi [photos.md](photos.md).',
        '',
    ];
    $total = count($manifeste['produits']) + count($manifeste['etablissements']);
    if ($total === 0) {
        $lignes[] = 'Aucune photo importée pour l\'instant. Les données de démonstration utilisent les illustrations SVG du projet (dessins originaux, sans crédit à citer).';
        return implode("\n", $lignes) . "\n";
    }
    $lignes[] = '| Produit ou établissement | Fichier | Auteur | Licence | Page source |';
    $lignes[] = '|---|---|---|---|---|';
    foreach (['produits', 'etablissements'] as $section) {
        foreach ($manifeste[$section] as $photo) {
            $licence = $photo['licence_url'] !== ''
                ? '[' . $cellule($photo['licence']) . '](' . $photo['licence_url'] . ')'
                : $cellule($photo['licence']);
            $lignes[] = sprintf(
                '| %s | `%s` | %s | %s | [%s](%s) |',
                $cellule($photo['nom']),
                $photo['fichier'],
                $cellule($photo['auteur']),
                $licence,
                $cellule(preg_replace('/^(File|Fichier):/', '', $photo['titre'])),
                $photo['page'],
            );
        }
    }
    return implode("\n", $lignes) . "\n";
}

/** UPDATE à ajouter aux données de démonstration pour qu'une nouvelle instance ait les photos. */
function sqlDemo(array $manifeste): string
{
    $q = static fn (string $v): string => "'" . str_replace(['\\', "'"], ['\\\\', "''"], $v) . "'";
    $sql = '';
    foreach ($manifeste['produits'] as $id => $photo) {
        $sql .= sprintf("UPDATE produits SET photo = %s, photo_credit = %s WHERE id = %d;\n", $q($photo['fichier']), $q($photo['credit']), (int) $id);
    }
    foreach ($manifeste['etablissements'] as $slug => $photo) {
        $sql .= sprintf("UPDATE etablissements SET couverture = %s, couverture_credit = %s WHERE slug = %s;\n", $q($photo['fichier']), $q($photo['credit']), $q((string) $slug));
    }
    return $sql;
}

// ---------------------------------------------------------------------------
// Programme principal
// ---------------------------------------------------------------------------

/** Une photo déjà en place doit-elle être gardée ? Les illustrations SVG de démonstration sont remplacées. */
function photoAGarder(?string $photo, bool $forcer): bool
{
    if ($forcer || $photo === null || $photo === '') {
        return false;
    }
    return !preg_match('#^/assets/img/demo/[a-z]+/[a-z0-9-]+\.svg$#', $photo);
}

function principal(array $argv): int
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $m)) {
            fwrite(STDERR, "Argument inattendu : $argument\n");
            return 1;
        }
        $options[$m[1]] = $m[2] ?? true;
    }
    $inconnues = array_diff(array_keys($options), ['instance', 'essai', 'forcer', 'sql', 'analyser', 'api', 'delai', 'aide']);
    if ($inconnues || isset($options['aide']) || ($options === [])) {
        if ($inconnues) {
            fwrite(STDERR, 'Option inconnue : --' . implode(', --', $inconnues) . "\n");
        }
        fwrite(STDERR, "Usage : php bin/importer-photos.php --instance=demo [--essai] [--forcer]\n"
            . "        php bin/importer-photos.php --sql\n"
            . "  --essai   recherche les photos sans rien télécharger ni écrire\n"
            . "  --forcer  remplace aussi les photos déjà en place\n"
            . "  --sql     affiche les UPDATE des photos importées, pour les données de démonstration\n");
        return $inconnues ? 1 : (isset($options['aide']) ? 0 : 1);
    }

    try {
        $liste = chargerListe(RACINE . '/database/photos-demo.json');
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }

    if (isset($options['sql'])) {
        $sql = sqlDemo(lireManifeste(fichierManifeste()));
        if ($sql === '') {
            fwrite(STDERR, "Aucune photo importée pour l'instant (public/assets/img/demo/credits.json est vide).\n");
            return 2;
        }
        echo $sql;
        return 0;
    }

    // Analyse d'une réponse enregistrée, sans réseau ni base (tests)
    if (isset($options['analyser'])) {
        $reponse = json_decode((string) @file_get_contents((string) $options['analyser']), true);
        if (!is_array($reponse)) {
            fwrite(STDERR, "Réponse illisible : {$options['analyser']}\n");
            return 1;
        }
        $resultat = analyserReponse($reponse, $liste['marques'], $liste['mots']);
        echo json_encode($resultat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return $resultat['retenus'] ? 0 : 2;
    }

    $sousDomaine = (string) ($options['instance'] ?? '');
    if ($sousDomaine === '' || Instance::charger($sousDomaine) === null) {
        fwrite(STDERR, "Instance inconnue ou inactive : « $sousDomaine » (voir config/instances/).\n");
        return 1;
    }
    $essai = isset($options['essai']);
    $forcer = isset($options['forcer']);
    $api = PHOTOS_API;
    $hotesImages = ['upload.wikimedia.org'];
    if (isset($options['api'])) {
        $api = (string) $options['api'];
        if (!preg_match('#^https?://[a-z0-9.-]+(:\d+)?/#i', $api)) {
            fwrite(STDERR, "Adresse d'API invalide : $api\n");
            return 1;
        }
        $hotesImages[] = (string) parse_url($api, PHP_URL_HOST);
    }
    $delai = isset($options['delai']) ? max(0.0, (float) $options['delai']) : PHOTOS_DELAI;

    try {
        foreach (['produits' => 'photo_credit', 'etablissements' => 'couverture_credit'] as $table => $colonne) {
            if (Database::un("SHOW COLUMNS FROM $table LIKE '$colonne'") === null) {
                fwrite(STDERR, "Colonne $table.$colonne absente : lancez d'abord php bin/migrer.php $sousDomaine\n");
                return 1;
            }
        }
    } catch (PDOException $e) {
        fwrite(STDERR, 'Connexion à la base impossible : ' . $e->getMessage() . "\n");
        return 1;
    }

    echo ($essai ? 'Essai (rien n\'est écrit)' : 'Import') . " des photos de démonstration pour l'instance « $sousDomaine »\n";
    echo "Source : Wikimedia Commons ($api)\n\n";

    $manifeste = lireManifeste(fichierManifeste());
    $pris = array_column(array_merge(array_values($manifeste['produits']), array_values($manifeste['etablissements'])), 'titre');
    $importees = $gardees = $trouveesEssai = 0;
    $introuvables = [];
    $premiereRequete = true;
    $refus = null;

    foreach ($liste['entrees'] as $entree) {
        $estProduit = $entree['type'] === 'produit';
        $ligne = $estProduit
            ? Database::un('SELECT id, nom_fr AS nom, photo FROM produits WHERE id = ?', [$entree['id']])
            : Database::un('SELECT id, nom, couverture AS photo FROM etablissements WHERE slug = ?', [$entree['slug']]);
        $libelle = $estProduit ? "Produit {$entree['id']} « {$entree['nom']} »" : "Établissement « {$entree['nom']} »";
        echo $libelle . "\n";

        if ($ligne === null) {
            echo "    absent de cette instance : ignoré\n";
            continue;
        }
        // Garde-fou : sur une instance client, l'identifiant peut désigner un autre produit
        if ($entree['nom'] !== '' && normaliser((string) $ligne['nom']) !== normaliser($entree['nom'])) {
            echo "    s'appelle « {$ligne['nom']} » dans cette instance : ignoré (ce ne sont pas les données de démonstration)\n";
            continue;
        }
        if (photoAGarder($ligne['photo'], $forcer)) {
            echo "    photo déjà en place ({$ligne['photo']}) : gardée (--forcer pour la remplacer)\n";
            $gardees++;
            continue;
        }

        $relatif = $estProduit
            ? '/assets/img/demo/produits/' . $entree['id'] . '-' . $entree['slug'] . '.jpg'
            : '/assets/img/demo/etablissements/' . $entree['slug'] . '.jpg';
        $trouvee = false;
        $ligneOuverte = false;
        try {
            foreach ($entree['recherches'] as $requete) {
                if (!$premiereRequete && $delai > 0) {
                    usleep((int) ($delai * 1_000_000));
                }
                $premiereRequete = false;
                echo "    recherche « $requete »… ";
                $ligneOuverte = true;
                $reponse = json_decode(telecharger(urlRecherche($api, $requete), 5 * 1024 * 1024), true);
                $ligneOuverte = false;
                if (!is_array($reponse)) {
                    echo "réponse illisible\n";
                    continue;
                }
                if (isset($reponse['error'])) {
                    echo 'erreur de l\'API : ' . texteBrut((string) ($reponse['error']['info'] ?? '')) . "\n";
                    continue;
                }
                $resultat = analyserReponse($reponse, $liste['marques'], $liste['mots'], $pris);
                echo count($resultat['retenus']) . ' photo(s) utilisable(s) sur ' . (count($resultat['retenus']) + count($resultat['ecartes'])) . "\n";

                foreach (array_slice($resultat['retenus'], 0, 3) as $photo) {
                    if ($essai) {
                        echo "    serait importée : {$photo['titre']} ({$photo['licence']}, {$photo['largeur']} × {$photo['hauteur']})\n";
                        echo "        → $relatif\n        crédit : {$photo['credit']}\n";
                        $trouvee = true;
                        $trouveesEssai++;
                        break;
                    }
                    $hoteImage = (string) parse_url($photo['vignette'], PHP_URL_HOST);
                    $schema = (string) parse_url($photo['vignette'], PHP_URL_SCHEME);
                    if (!in_array($hoteImage, $hotesImages, true) || ($schema !== 'https' && !isset($options['api']))) {
                        echo "    écartée : adresse d'image inattendue ({$photo['vignette']})\n";
                        continue;
                    }
                    if ($delai > 0) {
                        usleep((int) ($delai * 1_000_000));
                    }
                    try {
                        $donnees = telecharger($photo['vignette'], PHOTOS_OCTETS_MAX);
                        [$l, $h] = reencoderImage($donnees, RACINE . '/public' . $relatif);
                    } catch (AccesReseauRefuse $e) {
                        throw $e;
                    } catch (RuntimeException $e) {
                        echo "    écartée : {$photo['titre']} : {$e->getMessage()}\n";
                        continue;
                    }
                    if ($estProduit) {
                        Database::executer('UPDATE produits SET photo = ?, photo_credit = ? WHERE id = ?', [$relatif, $photo['credit'], $ligne['id']]);
                    } else {
                        Database::executer('UPDATE etablissements SET couverture = ?, couverture_credit = ? WHERE id = ?', [$relatif, $photo['credit'], $ligne['id']]);
                    }
                    $manifeste[$estProduit ? 'produits' : 'etablissements'][$entree['cle']] = [
                        'nom' => $entree['nom'],
                        'fichier' => $relatif,
                        'titre' => $photo['titre'],
                        'page' => $photo['page'],
                        'auteur' => $photo['auteur'],
                        'licence' => $photo['licence'],
                        'licence_url' => $photo['licence_url'],
                        'credit' => $photo['credit'],
                    ];
                    $pris[] = $photo['titre'];
                    echo "    importée : {$photo['titre']} → $relatif ($l × $h)\n        crédit : {$photo['credit']}\n";
                    $trouvee = true;
                    $importees++;
                    break;
                }
                if ($trouvee) {
                    break;
                }
            }
        } catch (AccesReseauRefuse $e) {
            echo $ligneOuverte ? "refusé\n" : "    téléchargement refusé\n";
            $refus = $e;
            break;
        } catch (RuntimeException $e) {
            echo "    erreur : {$e->getMessage()}\n";
        }
        if (!$trouvee) {
            $introuvables[] = $libelle;
            echo "    aucune photo convenable : à chercher à la main ou avec d'autres requêtes dans database/photos-demo.json\n";
        }
    }

    if (!$essai && $importees > 0) {
        ecrireManifeste(fichierManifeste(), $manifeste);
        file_put_contents(RACINE . '/docs/credits-photos.md', documentCredits($manifeste));
    }

    if ($refus !== null) {
        fwrite(STDERR, messageAccesRefuse($refus, $sousDomaine));
        if ($importees > 0) {
            echo "$importees photo(s) importée(s) avant l'interruption.\n";
        }
        return 3;
    }

    echo "\n" . ($essai ? 'Photos trouvées' : 'Photos importées') . ' : ' . ($essai ? $trouveesEssai : $importees)
        . ", gardées : $gardees, sans photo : " . count($introuvables) . "\n";
    if (!$essai && $importees > 0) {
        echo "Crédits : docs/credits-photos.md. Pour les données de démonstration : php bin/importer-photos.php --sql\n";
    }
    return $introuvables ? 2 : 0;
}

if (!defined('PHOTOS_SANS_EXECUTION')) {
    exit(principal($argv));
}
