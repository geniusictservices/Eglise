<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Services horaires (cahier des charges, section 5) : un même lieu change de mode dans la journée
 * (Restaurant 12–15, Lounge 18–23, Club 23–5). Les heures d'un service sont celles des horaires de
 * l'établissement (table horaires, page publique) ; ses réglages (grille de prix, catégories vendues,
 * modules : tables, cuisine, partage, mode rapide) sont dans services_horaires, rattachés par le nom.
 *
 * Grille de prix appliquée à la caisse, dans l'ordre : celle de la zone (Carré VIP), sinon le happy hour en
 * cours, sinon celle du service en cours, sinon celle de l'établissement. Sans service en cours (entre deux
 * services, ou horaires non renseignés), la caisse vend tout, avec les modules par défaut du type de lieu.
 */
final class ServiceRepository
{
    public const MODULES = ['tables', 'cuisine', 'partage', 'rapide'];

    /** @var array<int, list<array>> */
    private array $horaires = [];
    /** @var array<int, array<string, array>> */
    private array $reglages = [];

    /** Modules par défaut selon le type de lieu (section 4). */
    public static function modulesParDefaut(string $type): array
    {
        return match ($type) {
            'nightclub' => ['tables' => true, 'cuisine' => false, 'partage' => false, 'rapide' => true],
            'lounge' => ['tables' => true, 'cuisine' => true, 'partage' => true, 'rapide' => false],
            default => ['tables' => true, 'cuisine' => true, 'partage' => true, 'rapide' => false],
        };
    }

    /**
     * Service en cours dans l'établissement, avec ses réglages, ou null.
     * Un service qui passe minuit (22:00 → 05:00) est en cours le lendemain matin, au titre du jour où il a commencé.
     * @return array{nom_fr: string, nom_en: string, debut: string, fin: string, id: ?int, grille_prix_id: ?int,
     *               tables: bool, cuisine: bool, partage: bool, rapide: bool, categories: list<int>}|null
     */
    public function actif(array $etab, ?\DateTimeImmutable $moment = null): ?array
    {
        $moment ??= new \DateTimeImmutable();
        $trouve = null;
        $depuis = null;
        foreach ($this->horairesDe((int) $etab['id']) as $h) {
            $debut = self::debutEnCours((int) $h['jour'], $h['ouverture'], $h['fermeture'], $moment);
            // Deux services qui se chevauchent : celui qui a commencé le plus tard
            if ($debut !== null && ($depuis === null || $debut > $depuis)) {
                $trouve = $h;
                $depuis = $debut;
            }
        }
        if ($trouve === null) {
            return null;
        }
        $reglage = $this->reglagesDe((int) $etab['id'])[mb_strtolower(trim($trouve['service_fr']))] ?? null;
        $modules = $reglage !== null
            ? array_map(static fn ($v) => (bool) $v, array_intersect_key($reglage, array_flip(self::MODULES)))
            : self::modulesParDefaut((string) $etab['type']);
        return [
            'nom_fr' => $trouve['service_fr'], 'nom_en' => $trouve['service_en'],
            'debut' => substr($trouve['ouverture'], 0, 5), 'fin' => substr($trouve['fermeture'], 0, 5),
            'id' => $reglage !== null ? (int) $reglage['id'] : null,
            'grille_prix_id' => $reglage !== null && $reglage['grille_prix_id'] !== null ? (int) $reglage['grille_prix_id'] : null,
            'categories' => $reglage['categories'] ?? [],
        ] + $modules;
    }

    /**
     * Moment où a commencé l'occurrence en cours d'un créneau (jour 1 = lundi … 7 = dimanche), ou null.
     * Fermeture <= ouverture : le créneau se termine le lendemain.
     */
    public static function debutEnCours(int $jour, string $ouverture, string $fermeture, \DateTimeImmutable $moment): ?\DateTimeImmutable
    {
        $o = substr($ouverture, 0, 8);
        $f = substr($fermeture, 0, 8);
        $t = $moment->format('H:i:s');
        $n = (int) $moment->format('N');
        $veille = $n === 1 ? 7 : $n - 1;
        $date = $moment->format('Y-m-d');
        if ($f > $o) {
            return $jour === $n && $t >= $o && $t < $f ? new \DateTimeImmutable($date . ' ' . $o) : null;
        }
        if ($jour === $n && $t >= $o) {
            return new \DateTimeImmutable($date . ' ' . $o);
        }
        if ($jour === $veille && $t < $f) {
            return (new \DateTimeImmutable($date . ' ' . $o))->modify('-1 day');
        }
        return null;
    }

    /** Happy hour en cours (plage horaire et jours), ou null. */
    public function happyHour(array $etab, ?\DateTimeImmutable $moment = null): ?array
    {
        $moment ??= new \DateTimeImmutable();
        foreach (Database::tous('SELECT * FROM happy_hours WHERE etablissement_id = ? AND actif = 1 ORDER BY debut, id', [(int) $etab['id']]) as $hh) {
            foreach (array_map('intval', explode(',', $hh['jours'])) as $jour) {
                if (self::debutEnCours($jour, $hh['debut'], $hh['fin'], $moment) !== null) {
                    return $hh;
                }
            }
        }
        return null;
    }

    /**
     * Grille de prix à appliquer maintenant, et d'où elle vient.
     * @return array{grille: int, source: string, service: ?array, happy: ?array}
     *         source : zone, happy_hour, service ou etablissement
     */
    public function grille(array $etab, ?array $zone, ?\DateTimeImmutable $moment = null): array
    {
        $service = $this->actif($etab, $moment);
        $happy = $this->happyHour($etab, $moment);
        if ($zone !== null && !empty($zone['grille_prix_id'])) {
            return ['grille' => (int) $zone['grille_prix_id'], 'source' => 'zone', 'service' => $service, 'happy' => $happy];
        }
        if ($happy !== null) {
            return ['grille' => (int) $happy['grille_prix_id'], 'source' => 'happy_hour', 'service' => $service, 'happy' => $happy];
        }
        if ($service !== null && $service['grille_prix_id'] !== null) {
            return ['grille' => $service['grille_prix_id'], 'source' => 'service', 'service' => $service, 'happy' => null];
        }
        return ['grille' => (int) $etab['grille_prix_id'], 'source' => 'etablissement', 'service' => $service, 'happy' => null];
    }

    /** Modules du moment : ceux du service en cours, sinon ceux du type de lieu. */
    public function modules(array $etab, ?array $service): array
    {
        if ($service === null) {
            return self::modulesParDefaut((string) $etab['type']);
        }
        return array_intersect_key($service, array_flip(self::MODULES));
    }

    // ---------- Écran de gestion ----------

    /**
     * Services de l'établissement : chaque nom des horaires (avec ses créneaux), puis les réglages orphelins
     * (le service a été renommé ou retiré des horaires).
     * @return list<array{nom_fr: string, nom_en: string, creneaux: list<array>, reglage: ?array}>
     */
    public function services(array $etab): array
    {
        $services = [];
        foreach ($this->horairesDe((int) $etab['id']) as $h) {
            $cle = mb_strtolower(trim($h['service_fr']));
            $services[$cle] ??= ['nom_fr' => $h['service_fr'], 'nom_en' => $h['service_en'], 'creneaux' => [], 'reglage' => null];
            $services[$cle]['creneaux'][] = $h;
        }
        foreach ($this->reglagesDe((int) $etab['id']) as $cle => $reglage) {
            if (isset($services[$cle])) {
                $services[$cle]['reglage'] = $reglage;
            } else {
                $services[$cle] = ['nom_fr' => $reglage['nom_fr'], 'nom_en' => $reglage['nom_fr'], 'creneaux' => [], 'reglage' => $reglage];
            }
        }
        return array_values($services);
    }

    /** Enregistre les réglages d'un service (créés au premier enregistrement). */
    public function enregistrer(int $etabId, string $nomFr, ?int $grille, array $modules, array $categories): void
    {
        $existant = Database::un('SELECT id FROM services_horaires WHERE etablissement_id = ? AND nom_fr = ?', [$etabId, $nomFr]);
        $valeurs = [$grille];
        foreach (self::MODULES as $m) {
            $valeurs[] = !empty($modules[$m]) ? 1 : 0;
        }
        if ($existant === null) {
            Database::executer(
                'INSERT INTO services_horaires (etablissement_id, nom_fr, grille_prix_id, tables, cuisine, partage, rapide) VALUES (?, ?, ?, ?, ?, ?, ?)',
                array_merge([$etabId, $nomFr], $valeurs)
            );
            $id = Database::dernierId();
        } else {
            $id = (int) $existant['id'];
            Database::executer('UPDATE services_horaires SET grille_prix_id = ?, tables = ?, cuisine = ?, partage = ?, rapide = ? WHERE id = ?', array_merge($valeurs, [$id]));
        }
        Database::executer('DELETE FROM service_categories WHERE service_id = ?', [$id]);
        foreach (array_unique($categories) as $categorie) {
            Database::executer('INSERT INTO service_categories (service_id, categorie_id) VALUES (?, ?)', [$id, $categorie]);
        }
        $this->reglages = [];
    }

    public function supprimerReglage(int $etabId, int $id): void
    {
        Database::executer('DELETE FROM services_horaires WHERE id = ? AND etablissement_id = ?', [$id, $etabId]);
        $this->reglages = [];
    }

    /** @return list<array> */
    public function happyHours(int $etabId): array
    {
        return Database::tous(
            'SELECT h.*, g.nom_fr AS grille_nom_fr, g.nom_en AS grille_nom_en FROM happy_hours h JOIN grilles_prix g ON g.id = h.grille_prix_id
              WHERE h.etablissement_id = ? ORDER BY h.debut, h.id',
            [$etabId]
        );
    }

    private function horairesDe(int $etabId): array
    {
        return $this->horaires[$etabId] ??= Database::tous('SELECT * FROM horaires WHERE etablissement_id = ? ORDER BY jour, ouverture, id', [$etabId]);
    }

    /** @return array<string, array> nom du service en minuscules → réglages (avec « categories ») */
    private function reglagesDe(int $etabId): array
    {
        if (!isset($this->reglages[$etabId])) {
            $reglages = [];
            foreach (Database::tous('SELECT * FROM services_horaires WHERE etablissement_id = ? ORDER BY id', [$etabId]) as $r) {
                $r['categories'] = array_map('intval', array_column(Database::tous('SELECT categorie_id FROM service_categories WHERE service_id = ?', [(int) $r['id']]), 'categorie_id'));
                $reglages[mb_strtolower(trim($r['nom_fr']))] = $r;
            }
            $this->reglages[$etabId] = $reglages;
        }
        return $this->reglages[$etabId];
    }
}
