<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Écritures et lectures de l'espace de gestion pour les établissements :
 * fiche, apparence, prix et devises, horaires, zones, grilles de prix.
 */
final class GestionEtablissementRepository
{
    /** Colonnes de la section « Informations ». */
    public const CHAMPS_INFOS = [
        'nom', 'slug', 'type', 'slogan_fr', 'slogan_en', 'description_fr', 'description_en',
        'adresse', 'ville', 'lien_carte', 'telephone', 'whatsapp', 'email', 'facebook', 'instagram', 'tiktok',
    ];

    // Établissements

    /** Tous les établissements, actifs d'abord. */
    public function liste(): array
    {
        return Database::tous('SELECT * FROM etablissements ORDER BY actif DESC, position, nom');
    }

    public function etablissement(int $id): ?array
    {
        return Database::un('SELECT * FROM etablissements WHERE id = ?', [$id]);
    }

    public function slugPris(string $slug, int $saufId = 0): bool
    {
        return Database::un('SELECT id FROM etablissements WHERE slug = ? AND id <> ?', [$slug, $saufId]) !== null;
    }

    /** Premier slug libre : « mon-bar », sinon « mon-bar-2 », « mon-bar-3 »… */
    public function slugLibre(string $base, int $saufId = 0): string
    {
        $base = substr($base, 0, 60);
        $slug = $base;
        for ($n = 2; $this->slugPris($slug, $saufId); $n++) {
            $slug = rtrim(substr($base, 0, 60), '-') . '-' . $n;
        }
        return $slug;
    }

    /** Crée un établissement avec la grille et les devises données (et le délai de paiement d'une ardoise) ; rend son identifiant. */
    public function creer(array $infos, int $grilleId, array $deviseIds, ?int $deviseAffichage, int $creditDelaiJours = 7): int
    {
        $colonnes = self::CHAMPS_INFOS;
        $position = (int) (Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM etablissements')['p'] ?? 1);
        Database::executer(
            'INSERT INTO etablissements (' . implode(', ', $colonnes) . ', grille_prix_id, devise_affichage_id, credit_delai_jours, actif, position)
             VALUES (' . implode(', ', array_fill(0, count($colonnes), '?')) . ', ?, ?, ?, 1, ?)',
            [...array_map(static fn (string $c) => $infos[$c], $colonnes), $grilleId, $deviseAffichage, $creditDelaiJours, $position]
        );
        $id = Database::dernierId();
        $this->definirDevises($id, $deviseIds);
        return $id;
    }

    public function modifierInfos(int $id, array $infos): void
    {
        $colonnes = self::CHAMPS_INFOS;
        Database::executer(
            'UPDATE etablissements SET ' . implode(', ', array_map(static fn (string $c) => $c . ' = ?', $colonnes)) . ' WHERE id = ?',
            [...array_map(static fn (string $c) => $infos[$c], $colonnes), $id]
        );
    }

    public function definirCouleur(int $id, ?string $couleur, ?string $credit): void
    {
        Database::executer('UPDATE etablissements SET couleur = ?, couverture_credit = ? WHERE id = ?', [$couleur, $credit, $id]);
    }

    /** @param string $colonne « logo » ou « couverture » */
    public function definirImage(int $id, string $colonne, ?string $chemin): void
    {
        if (!in_array($colonne, ['logo', 'couverture'], true)) {
            return;
        }
        Database::executer('UPDATE etablissements SET ' . $colonne . ' = ? WHERE id = ?', [$chemin, $id]);
    }

    public function definirPrix(int $id, int $grilleId, array $deviseIds, ?int $deviseAffichage, ?int $creditDelaiJours = null): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        Database::executer('UPDATE etablissements SET grille_prix_id = ?, devise_affichage_id = ? WHERE id = ?', [$grilleId, $deviseAffichage, $id]);
        if ($creditDelaiJours !== null) {
            Database::executer('UPDATE etablissements SET credit_delai_jours = ? WHERE id = ?', [$creditDelaiJours, $id]);
        }
        $this->definirDevises($id, $deviseIds);
        $pdo->commit();
    }

    private function definirDevises(int $id, array $deviseIds): void
    {
        Database::executer('DELETE FROM etablissement_devises WHERE etablissement_id = ?', [$id]);
        foreach (array_unique($deviseIds) as $deviseId) {
            Database::executer('INSERT INTO etablissement_devises (etablissement_id, devise_id) VALUES (?, ?)', [$id, (int) $deviseId]);
        }
    }

    public function definirActif(int $id, bool $actif): void
    {
        Database::executer('UPDATE etablissements SET actif = ? WHERE id = ?', [$actif ? 1 : 0, $id]);
    }

    // Horaires

    /** Lignes dans l'ordre de saisie (une ligne par jour). */
    public function horaires(int $id): array
    {
        return Database::tous('SELECT * FROM horaires WHERE etablissement_id = ? ORDER BY id', [$id]);
    }

    /**
     * Regroupe les lignes de même service et mêmes heures, pour l'éditeur :
     * [['service_fr', 'service_en', 'jours' => [1, 2], 'ouverture' => 'HH:MM', 'fermeture' => 'HH:MM']]
     */
    public function horairesPourEditeur(int $id): array
    {
        $groupes = [];
        foreach ($this->horaires($id) as $ligne) {
            $ouverture = substr($ligne['ouverture'], 0, 5);
            $fermeture = substr($ligne['fermeture'], 0, 5);
            $cle = $ligne['service_fr'] . "\0" . $ligne['service_en'] . "\0" . $ouverture . "\0" . $fermeture;
            $groupes[$cle] ??= [
                'service_fr' => $ligne['service_fr'],
                'service_en' => $ligne['service_en'],
                'jours' => [],
                'ouverture' => $ouverture,
                'fermeture' => $fermeture,
            ];
            $groupes[$cle]['jours'][] = (int) $ligne['jour'];
        }
        foreach ($groupes as &$groupe) {
            $groupe['jours'] = array_values(array_unique($groupe['jours']));
            sort($groupe['jours']);
        }
        return array_values($groupes);
    }

    /** Remplace tous les horaires : une ligne de la base par jour coché. */
    public function remplacerHoraires(int $id, array $lignes): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        Database::executer('DELETE FROM horaires WHERE etablissement_id = ?', [$id]);
        $dejaVu = [];
        foreach ($lignes as $ligne) {
            foreach ($ligne['jours'] as $jour) {
                $cle = $ligne['service_fr'] . "\0" . $jour . "\0" . $ligne['ouverture'] . "\0" . $ligne['fermeture'];
                if (isset($dejaVu[$cle])) {
                    continue;
                }
                $dejaVu[$cle] = true;
                Database::executer(
                    'INSERT INTO horaires (etablissement_id, service_fr, service_en, jour, ouverture, fermeture) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $ligne['service_fr'], $ligne['service_en'], $jour, $ligne['ouverture'], $ligne['fermeture']]
                );
            }
        }
        $pdo->commit();
    }

    // Zones

    /** Zones avec le nombre de codes QR qui les utilisent. */
    public function zones(int $id): array
    {
        return Database::tous(
            'SELECT z.*, (SELECT COUNT(*) FROM qr_codes q WHERE q.zone_id = z.id) AS nb_qr
               FROM zones z WHERE z.etablissement_id = ? ORDER BY z.id',
            [$id]
        );
    }

    public function zone(int $etablissementId, int $zoneId): ?array
    {
        return Database::un(
            'SELECT z.*, (SELECT COUNT(*) FROM qr_codes q WHERE q.zone_id = z.id) AS nb_qr
               FROM zones z WHERE z.etablissement_id = ? AND z.id = ?',
            [$etablissementId, $zoneId]
        );
    }

    public function codeZonePris(int $etablissementId, string $code, int $saufId = 0): bool
    {
        return Database::un('SELECT id FROM zones WHERE etablissement_id = ? AND code = ? AND id <> ?', [$etablissementId, $code, $saufId]) !== null;
    }

    public function ajouterZone(int $etablissementId, array $zone): int
    {
        Database::executer(
            'INSERT INTO zones (etablissement_id, code, nom_fr, nom_en, grille_prix_id) VALUES (?, ?, ?, ?, ?)',
            [$etablissementId, $zone['code'], $zone['nom_fr'], $zone['nom_en'], $zone['grille_prix_id']]
        );
        return Database::dernierId();
    }

    public function modifierZone(int $zoneId, array $zone): void
    {
        Database::executer(
            'UPDATE zones SET code = ?, nom_fr = ?, nom_en = ?, grille_prix_id = ? WHERE id = ?',
            [$zone['code'], $zone['nom_fr'], $zone['nom_en'], $zone['grille_prix_id'], $zoneId]
        );
    }

    /** Les codes QR de la zone restent, sans zone (clé étrangère ON DELETE SET NULL). */
    public function supprimerZone(int $zoneId): void
    {
        Database::executer('DELETE FROM zones WHERE id = ?', [$zoneId]);
    }

    // Grilles de prix

    /** Grilles avec leur utilisation : établissements, zones, prix saisis. */
    public function grilles(): array
    {
        return Database::tous(
            'SELECT g.*,
                    (SELECT COUNT(*) FROM etablissements e WHERE e.grille_prix_id = g.id) AS nb_etablissements,
                    (SELECT COUNT(*) FROM zones z WHERE z.grille_prix_id = g.id) AS nb_zones,
                    (SELECT COUNT(*) FROM prix p WHERE p.grille_prix_id = g.id) AS nb_prix
               FROM grilles_prix g ORDER BY g.id'
        );
    }

    public function grille(int $id): ?array
    {
        foreach ($this->grilles() as $grille) {
            if ((int) $grille['id'] === $id) {
                return $grille;
            }
        }
        return null;
    }

    public function codeGrillePris(string $code, int $saufId = 0): bool
    {
        return Database::un('SELECT id FROM grilles_prix WHERE code = ? AND id <> ?', [$code, $saufId]) !== null;
    }

    public function creerGrille(array $grille): int
    {
        Database::executer('INSERT INTO grilles_prix (code, nom_fr, nom_en) VALUES (?, ?, ?)', [$grille['code'], $grille['nom_fr'], $grille['nom_en']]);
        return Database::dernierId();
    }

    public function modifierGrille(int $id, array $grille): void
    {
        Database::executer('UPDATE grilles_prix SET code = ?, nom_fr = ?, nom_en = ? WHERE id = ?', [$grille['code'], $grille['nom_fr'], $grille['nom_en'], $id]);
    }

    public static function grilleUtilisee(array $grille): bool
    {
        return (int) $grille['nb_etablissements'] + (int) $grille['nb_zones'] + (int) $grille['nb_prix'] > 0;
    }

    public function supprimerGrille(int $id): void
    {
        Database::executer('DELETE FROM grilles_prix WHERE id = ?', [$id]);
    }
}
