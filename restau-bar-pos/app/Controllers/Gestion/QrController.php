<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\EtablissementRepository;

/** Codes QR des menus : liste, création, page à imprimer. */
final class QrController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $etablissements = new EtablissementRepository();

        $zones = [];
        foreach ($etablissements->actifs() as $etab) {
            $zones[$etab['id']] = $etablissements->zones((int) $etab['id']);
        }

        View::afficher('gestion/qr', [
            'titre' => t('gestion.qr'),
            'utilisateur' => $utilisateur,
            'codes' => $this->codes(),
            'etablissements' => $etablissements->actifs(),
            'zones' => $zones,
            'flash' => Session::lireFlash(),
            'base' => $this->urlBase(),
        ], 'gestion');
    }

    public function creer(): void
    {
        Session::exigerConnexion();
        Session::verifierCsrf();

        $etablissementId = (int) ($_POST['etablissement_id'] ?? 0);
        $zoneId = (int) ($_POST['zone_id'] ?? 0) ?: null;
        $libelle = trim((string) ($_POST['libelle'] ?? ''));

        $etab = (new EtablissementRepository())->parId($etablissementId);
        if ($etab === null || $libelle === '' || mb_strlen($libelle) > 128) {
            Session::flash(t('gestion.qr_invalide'));
            rediriger('/gestion/qr');
        }
        if ($zoneId !== null && Database::un('SELECT id FROM zones WHERE id = ? AND etablissement_id = ?', [$zoneId, $etablissementId]) === null) {
            $zoneId = null;
        }

        Database::executer(
            'INSERT INTO qr_codes (code, etablissement_id, zone_id, libelle) VALUES (?, ?, ?, ?)',
            [$this->nouveauCode(), $etablissementId, $zoneId, $libelle]
        );
        Session::flash(t('gestion.qr_cree'));
        rediriger('/gestion/qr');
    }

    public function imprimer(string $code): void
    {
        Session::exigerConnexion();
        $qr = Database::un(
            'SELECT q.*, e.nom AS etablissement, e.couleur, z.nom_fr AS zone_fr, z.nom_en AS zone_en
               FROM qr_codes q
               JOIN etablissements e ON e.id = q.etablissement_id
               LEFT JOIN zones z ON z.id = q.zone_id
              WHERE q.code = ?',
            [$code]
        );
        if ($qr === null) {
            pageIntrouvable();
        }
        View::afficher('gestion/qr-imprimer', ['qr' => $qr, 'url' => $this->urlBase() . '/q/' . $qr['code']], null);
    }

    private function codes(): array
    {
        return Database::tous(
            'SELECT q.*, e.nom AS etablissement, z.nom_fr AS zone_fr, z.nom_en AS zone_en,
                    COALESCE((SELECT SUM(s.nombre) FROM qr_scans s WHERE s.qr_code_id = q.id), 0) AS scans_total,
                    COALESCE((SELECT SUM(s.nombre) FROM qr_scans s WHERE s.qr_code_id = q.id AND s.jour >= ?), 0) AS scans_7j
               FROM qr_codes q
               JOIN etablissements e ON e.id = q.etablissement_id
               LEFT JOIN zones z ON z.id = q.zone_id
              ORDER BY e.position, q.id',
            [date('Y-m-d', strtotime('-6 days'))]
        );
    }

    private function nouveauCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Database::un('SELECT id FROM qr_codes WHERE code = ?', [$code]) !== null);
        return $code;
    }

    private function urlBase(): string
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
