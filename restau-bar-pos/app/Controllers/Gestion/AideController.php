<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;

/**
 * Aide : le manuel d'utilisation (docs/manuel/), ses captures et sa version PDF,
 * servis dans l'espace de gestion.
 */
final class AideController
{
    private const DOSSIER = RACINE . '/docs/manuel';

    public function index(): void
    {
        Session::exigerConnexion();
        $html = @file_get_contents(self::DOSSIER . '/index.html');
        if ($html === false) {
            pageIntrouvable();
        }

        // Les captures sont référencées en relatif (captures/…) : la base les sert depuis /gestion/aide/
        $barre = '<div style="position:sticky;top:0;z-index:10;display:flex;flex-wrap:wrap;gap:8px 20px;align-items:center;'
            . 'padding:10px max(16px,env(safe-area-inset-left,0px));background:#111315;color:#F2EFEA;font:600 14px Sora,system-ui,sans-serif">'
            . '<a href="/gestion" style="color:#F2EFEA">← ' . e(t('aide.retour')) . '</a>'
            . '<a href="/gestion/aide/manuel.pdf" style="color:#FFB547">' . e(t('aide.telecharger_pdf')) . '</a>'
            . '</div>';
        $html = preg_replace('/<head>/', '<head><base href="/gestion/aide/">', $html, 1);
        $html = preg_replace('/<body>/', '<body>' . $barre, $html, 1);

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    public function capture(string $nom): void
    {
        Session::exigerConnexion();
        $this->envoyer(self::DOSSIER . '/captures/' . $nom . '.jpg', 'image/jpeg');
    }

    public function pdf(): void
    {
        Session::exigerConnexion();
        header('Content-Disposition: inline; filename="manuel-restau-bar-pos.pdf"');
        $this->envoyer(self::DOSSIER . '/manuel-restau-bar-pos.pdf', 'application/pdf');
    }

    private function envoyer(string $fichier, string $type): void
    {
        if (!is_file($fichier)) {
            pageIntrouvable();
        }
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($fichier));
        header('Cache-Control: private, max-age=86400');
        readfile($fichier);
    }
}
