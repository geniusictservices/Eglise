<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\View;
use App\Repositories\CatalogueRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\EtablissementRepository;
use DateTimeImmutable;

/** Pages publiques : présentation du client, page et menu de chaque établissement, codes QR. */
final class VitrineController
{
    private EtablissementRepository $etablissements;
    private DeviseRepository $devises;

    public function __construct()
    {
        $this->etablissements = new EtablissementRepository();
        $this->devises = new DeviseRepository();
    }

    public function accueil(): void
    {
        $maintenant = new DateTimeImmutable();
        $liste = [];
        foreach ($this->etablissements->actifs() as $etab) {
            $etab['etat'] = $this->etablissements->etatOuverture($this->etablissements->horaires((int) $etab['id']), $maintenant);
            $liste[] = $etab;
        }
        if (count($liste) === 1) {
            rediriger('/e/' . $liste[0]['slug']);
        }

        View::afficher('vitrine/accueil', [
            'titre' => Config::get('parametres.nom_client', ''),
            'nomClient' => Config::get('parametres.nom_client', ''),
            'etablissements' => $liste,
        ]);
    }

    public function etablissement(string $slug): void
    {
        $etab = $this->etablissements->parSlug($slug);
        if ($etab === null) {
            pageIntrouvable();
        }
        $id = (int) $etab['id'];

        // Zone (code QR du carré VIP, de la terrasse…) et grille de prix correspondante
        $zone = null;
        if (isset($_GET['zone']) && is_string($_GET['zone'])) {
            $zone = $this->etablissements->zoneParCode($id, $_GET['zone']);
        }
        $grilleDefaut = (int) $etab['grille_prix_id'];
        $grille = $zone && $zone['grille_prix_id'] ? (int) $zone['grille_prix_id'] : $grilleDefaut;

        // Devises acceptées par l'établissement, parmi les devises actives
        $acceptees = $this->devises->accepteesParmi($this->etablissements->deviseIds($id));
        $devise = $this->choisirDevise($acceptees, $etab);

        $horaires = $this->etablissements->horaires($id);
        $menu = $devise ? (new CatalogueRepository($this->devises))->menu($id, $grille, $grilleDefaut, $devise) : [];

        View::afficher('vitrine/etablissement', [
            'titre' => $etab['nom'],
            'etab' => $etab,
            'zone' => $zone,
            'devise' => $devise,
            'devises' => $acceptees,
            'menu' => $menu,
            'horaires' => $this->etablissements->horairesRegroupes($horaires),
            'etat' => $this->etablissements->etatOuverture($horaires, new DateTimeImmutable()),
            'autres' => count($this->etablissements->actifs()) > 1,
        ]);
    }

    /** /q/<code> : compte le scan puis ouvre le menu de l'établissement (et de la zone). */
    public function scanQr(string $code): void
    {
        $qr = Database::un(
            'SELECT q.id, e.slug, z.code AS zone
               FROM qr_codes q
               JOIN etablissements e ON e.id = q.etablissement_id
               LEFT JOIN zones z ON z.id = q.zone_id
              WHERE q.code = ? AND q.actif = 1',
            [$code]
        );
        if ($qr === null) {
            pageIntrouvable();
        }
        Database::executer(
            'INSERT INTO qr_scans (qr_code_id, jour, nombre) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE nombre = nombre + 1',
            [$qr['id'], date('Y-m-d')]
        );
        rediriger('/e/' . $qr['slug'] . ($qr['zone'] ? '?zone=' . rawurlencode($qr['zone']) : ''));
    }

    /** Devise demandée (?devise=), sinon mémorisée (cookie), sinon celle de l'établissement. */
    private function choisirDevise(array $acceptees, array $etab): ?array
    {
        $parCode = array_column($acceptees, null, 'code');
        $demandee = $_GET['devise'] ?? null;

        if (is_string($demandee) && isset($parCode[$demandee])) {
            setcookie('devise', $demandee, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
            return $parCode[$demandee];
        }
        $memorisee = $_COOKIE['devise'] ?? null;
        if (is_string($memorisee) && isset($parCode[$memorisee])) {
            return $parCode[$memorisee];
        }
        $parDefaut = (int) ($etab['devise_affichage_id'] ?? 0);
        return $acceptees[$parDefaut] ?? (reset($acceptees) ?: null);
    }
}
