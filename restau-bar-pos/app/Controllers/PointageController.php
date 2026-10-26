<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Session;
use App\Core\View;
use App\Repositories\PointageRepository;

/**
 * Pointage par code QR (pages publiques, sans connexion à la gestion).
 *
 * /pointage/borne : écran de la borne autorisée (code QR changeant, plein écran, tablette bon marché) ;
 * /pointage/borne/jeton : le code en cours (JSON, rafraîchi par la borne) ;
 * /pointer?t=… : page ouverte par le téléphone de l'employé qui scanne le code ; il s'identifie la première
 * fois (téléphone + PIN), puis pointe son arrivée ou son départ avec sa position GPS.
 */
final class PointageController
{
    private PointageRepository $pointages;

    public function __construct()
    {
        // Module « Personnel et paie » absent de la formule d'abonnement : pas de pointage
        if (!\App\Core\Plateforme::moduleInclus('personnel')) {
            pageIntrouvable();
        }
        $this->pointages = new PointageRepository();
    }

    public function borne(): void
    {
        $borne = PointageRepository::borne();
        if ($borne === null) {
            http_response_code(403);
            View::afficher('pointage/borne-inconnue', ['titre' => t('pointage.borne_inconnue_titre')], 'pointage');
            return;
        }
        View::afficher('pointage/borne', [
            'titre' => t('pointage.borne_titre'),
            'borne' => $borne,
            'code' => $this->code($borne),
            'sansPosition' => $borne['latitude'] === null || $borne['longitude'] === null,
            'theme' => 'nuit',
            'scripts' => ['/assets/vendor/qrcode.js', '/assets/js/pointage-borne.js'],
        ], 'pointage');
    }

    public function jeton(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $borne = PointageRepository::borne();
        if ($borne === null) {
            http_response_code(403);
            echo json_encode(['erreur' => t('pointage.borne_inconnue_titre')], JSON_UNESCAPED_UNICODE);
            return;
        }
        echo json_encode($this->code($borne), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{jeton: string, url: string, expire_dans: int, duree: int} */
    private function code(array $borne): array
    {
        $fenetre = PointageRepository::fenetre();
        $jeton = PointageRepository::jeton($borne, $fenetre);
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $hote = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return [
            'jeton' => $jeton,
            'url' => ($https ? 'https' : 'http') . '://' . $hote . '/pointer?t=' . $jeton,
            'expire_dans' => ($fenetre + 1) * PointageRepository::FENETRE - time(),
            'duree' => PointageRepository::FENETRE,
        ];
    }

    // ---------- Téléphone de l'employé ----------

    public function page(): void
    {
        $this->afficherPage(null);
    }

    private function afficherPage(?string $erreurIdentification, int $code = 200, string $telephone = ''): void
    {
        http_response_code($code);
        $jeton = is_string($_GET['t'] ?? null) ? $_GET['t'] : (is_string($_POST['t'] ?? null) ? $_POST['t'] : '');
        $code_qr = null;
        $erreurQr = null;
        if ($jeton !== '') {
            try {
                $code_qr = $this->pointages->lireJeton($jeton);
            } catch (\DomainException $e) {
                $erreurQr = $e->getMessage();
            }
        }
        $employe = $this->pointages->employeDuTelephone();
        $ouverte = $employe ? $this->pointages->ouverte((int) $employe['id']) : null;
        View::afficher('pointage/telephone', [
            'titre' => t('pointage.titre'),
            'jeton' => $jeton,
            'qr' => $code_qr,
            'erreurQr' => $erreurQr,
            'employe' => $employe,
            'ouverte' => $ouverte,
            'rattache' => $employe && $code_qr ? in_array((int) $code_qr['borne']['etablissement_id'], $employe['etablissements'], true) : true,
            'erreurIdentification' => $erreurIdentification,
            'telephone' => $telephone,
            'theme' => 'auto',
            'scripts' => ['/assets/js/pointage-telephone.js'],
        ], 'pointage');
    }

    public function identifier(): void
    {
        Session::verifierCsrf();
        $telephone = mb_substr(trim((string) ($_POST['telephone'] ?? '')), 0, 40);
        $pin = preg_replace('/\D+/', '', (string) ($_POST['pin'] ?? '')) ?? '';
        try {
            $this->pointages->identifier($telephone, $pin, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        } catch (\DomainException $e) {
            $codes = ['trop_essais' => 429, 'bloque' => 423];
            $this->afficherPage(t('pointage.erreur_' . $e->getMessage()), $codes[$e->getMessage()] ?? 422, $telephone);
            return;
        }
        $jeton = is_string($_POST['t'] ?? null) && preg_match('/^[0-9a-f-]{1,64}$/', $_POST['t']) ? $_POST['t'] : '';
        rediriger('/pointer' . ($jeton !== '' ? '?t=' . $jeton : ''));
    }

    public function oublier(): void
    {
        Session::verifierCsrf();
        PointageRepository::oublierTelephone();
        rediriger('/pointer');
    }

    /** POST /pointer : t, lat, lng, precision → arrivée ou départ (réponse JSON). */
    public function pointer(): void
    {
        Session::demarrer();
        $csrf = $_POST['csrf'] ?? '';
        if (!is_string($csrf) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $this->repondre(419, 'expire_page');
        }
        $employe = $this->pointages->employeDuTelephone();
        if ($employe === null) {
            $this->repondre(401, 'non_identifie');
        }
        try {
            $qr = $this->pointages->lireJeton((string) ($_POST['t'] ?? ''));
        } catch (\DomainException $e) {
            $this->repondre($e->getMessage() === 'qr_expire' ? 410 : 422, $e->getMessage());
        }
        $borne = $qr['borne'];
        $etabId = (int) $borne['etablissement_id'];
        if (!in_array($etabId, $employe['etablissements'], true)) {
            $this->repondre(403, 'pas_rattache', ['etablissement' => $borne['etablissement_nom']]);
        }
        $lat = is_numeric($_POST['lat'] ?? null) ? (float) $_POST['lat'] : null;
        $lng = is_numeric($_POST['lng'] ?? null) ? (float) $_POST['lng'] : null;
        $precision = is_numeric($_POST['precision'] ?? null) ? (int) round((float) $_POST['precision']) : null;
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            $this->repondre(422, 'position');
        }
        if ($precision !== null && $precision > PointageRepository::PRECISION_MAX) {
            $this->repondre(422, 'precision', ['precision' => $precision]);
        }
        if ($borne['latitude'] === null || $borne['longitude'] === null) {
            $this->repondre(409, 'etablissement_sans_position', ['etablissement' => $borne['etablissement_nom']]);
        }
        $distance = (int) round(PointageRepository::distance($lat, $lng, (float) $borne['latitude'], (float) $borne['longitude']));
        $rayon = (int) $borne['pointage_rayon'];
        if ($distance > $rayon) {
            $this->repondre(403, 'trop_loin', ['distance' => self::distanceLisible($distance), 'rayon' => $rayon, 'etablissement' => $borne['etablissement_nom']]);
        }
        try {
            $resultat = $this->pointages->pointer($employe, $etabId, [
                'source' => 'qr', 'borne_id' => (int) $borne['id'], 'fenetre' => $qr['fenetre'], 'lat' => $lat, 'lng' => $lng,
                'precision' => $precision, 'distance' => $distance, 'telephone_id' => (int) $employe['telephone_id'], 'sessions' => (bool) $borne['sessions_caisse'],
            ]);
        } catch (\DomainException $e) {
            $ouverte = $this->pointages->ouverte((int) $employe['id']);
            $this->repondre(409, $e->getMessage(), ['etablissement' => $ouverte['etablissement_nom'] ?? $borne['etablissement_nom']]);
        }
        $p = $resultat['pointage'];
        $heure = date('H:i', strtotime($resultat['sens'] === 'arrivee' ? $p['arrivee'] : $p['depart']));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sens' => $resultat['sens'],
            'message' => t('pointage.ok_' . $resultat['sens'], ['nom' => $employe['nom'], 'heure' => $heure, 'etablissement' => $borne['etablissement_nom']]),
            'detail' => t('pointage.ok_distance', ['distance' => self::distanceLisible($distance)]),
            'heure' => $heure,
            'csrf' => Session::jetonCsrf(),
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function distanceLisible(int $metres): string
    {
        return $metres >= 1000 ? str_replace('.', \App\Core\I18n::langue() === 'fr' ? ',' : '.', (string) round($metres / 1000, 1)) . ' km' : $metres . ' m';
    }

    private function repondre(int $code, string $erreur, array $params = []): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => $erreur, 'erreur' => t('pointage.erreur_' . $erreur, $params)], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
