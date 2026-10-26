<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;
use App\Core\Parametres;

/**
 * Paie (cahier des charges, sections 13.4 et 17).
 *
 * Bulletin d'un employé pour une période : lignes dans chaque devise du salaire (150 $ + 100 000 FC).
 * Gains : salaire de base (au mois, à la semaine, ou par soirée travaillée), heures supplémentaires,
 * heures un jour férié, commission sur les ventes payées, primes, pourboires. Retenues : absences non
 * justifiées, congés sans solde, avances (déduites d'office), dettes du personnel et manquants de caisse
 * (proposés : le gérant peut les réduire ou les étaler avant de valider), autres retenues.
 *
 * Validation : les dettes sont réduites sans mouvement de trésorerie (DetteRepository::retenirSurPaie),
 * les manquants retenus ne sont plus proposés, les avances sont marquées déduites. Paiement : une ou
 * plusieurs lignes (compte, devise, montant), chacune sortie de son compte (mouvement « salaire »).
 */
final class PaieRepository
{
    public const GAINS = ['base', 'heures_sup', 'ferie', 'commission', 'prime', 'pourboire'];
    public const RETENUES = ['absence', 'sans_solde', 'avance', 'dette', 'manquant', 'retenue'];
    /** Lignes ajoutées à la main */
    public const MANUELLES = ['prime', 'pourboire', 'retenue'];
    /** Heures de référence quand il n'y a pas de planning (mois légal de 173,33 h, semaine de 40 h, soirée de 8 h) */
    public const HEURES_REFERENCE = ['mois' => 173.33, 'semaine' => 40.0, 'soiree' => 8.0];
    private const EPSILON = 0.0005;

    private GestionTresorerieRepository $tresorerie;
    private DetteRepository $dettes;

    public function __construct(?GestionTresorerieRepository $tresorerie = null)
    {
        $this->tresorerie = $tresorerie ?? new GestionTresorerieRepository();
        $this->dettes = new DetteRepository($this->tresorerie);
    }

    public function tresorerie(): GestionTresorerieRepository
    {
        return $this->tresorerie;
    }

    /** @return array{0: string, 1: string} premier et dernier jour d'un mois « Y-m » */
    public static function mois(string $mois): array
    {
        $debut = \DateTimeImmutable::createFromFormat('!Y-m', $mois) ?: new \DateTimeImmutable('first day of this month');
        return [$debut->format('Y-m-01'), $debut->format('Y-m-t')];
    }

    /** @return array{0: string, 1: string} lundi et dimanche de la semaine qui contient cette date */
    public static function semaine(string $date): array
    {
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new \DateTimeImmutable('today');
        $lundi = $jour->modify('-' . ((int) $jour->format('N') - 1) . ' days');
        return [$lundi->format('Y-m-d'), $lundi->modify('+6 days')->format('Y-m-d')];
    }

    private function devise(int $id): array
    {
        return $this->tresorerie->devises()[$id] ?? ['id' => $id, 'code' => '?', 'symbole' => '?', 'decimales' => 2, 'arrondi' => 0, 'symbole_avant' => 0, 'taux' => null];
    }

    private function arrondir(float $montant, int $deviseId): float
    {
        return round($montant, (int) $this->devise($deviseId)['decimales']);
    }

    /** Arrondi vers le haut à la précision de la devise (maximum d'une retenue convertie). */
    private function arrondirHaut(float $montant, int $deviseId): float
    {
        $f = 10 ** (int) $this->devise($deviseId)['decimales'];
        return ceil(round($montant * $f, 6)) / $f;
    }

    // ---------- Lecture ----------

    /**
     * @param array{debut?: string, fin?: string, etablissement?: ?int, statut?: ?string, employe?: ?int} $f
     * @return list<array> bulletins avec net et payé par devise
     */
    public function bulletins(?array $portee, array $f = []): array
    {
        $params = [];
        $where = [PersonnelRepository::conditionPortee($portee, $params)];
        if (!empty($f['debut']) && !empty($f['fin'])) {
            $where[] = 'b.debut <= ? AND b.fin >= ?';
            array_push($params, $f['fin'], $f['debut']);
        }
        foreach (['etablissement' => 'b.etablissement_id', 'employe' => 'b.employe_id'] as $cle => $colonne) {
            if (!empty($f[$cle])) {
                $where[] = "$colonne = ?";
                $params[] = (int) $f[$cle];
            }
        }
        if (!empty($f['statut'])) {
            $where[] = 'b.statut = ?';
            $params[] = $f['statut'];
        } else {
            $where[] = "b.statut <> 'annule'";
        }
        $bulletins = Database::tous(
            'SELECT b.*, e.nom AS employe_nom, e.poste, e.periode AS employe_periode, u.avatar, x.nom AS etablissement_nom
               FROM bulletins b JOIN employes e ON e.id = b.employe_id JOIN etablissements x ON x.id = b.etablissement_id
               LEFT JOIN utilisateurs u ON u.id = e.utilisateur_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY b.fin DESC, e.nom LIMIT 500',
            $params
        );
        if ($bulletins === []) {
            return [];
        }
        $ids = array_map(static fn (array $b) => (int) $b['id'], $bulletins);
        $lignes = [];
        foreach (Database::tous('SELECT * FROM bulletin_lignes WHERE bulletin_id IN (' . implode(',', $ids) . ')') as $l) {
            $lignes[(int) $l['bulletin_id']][] = $l;
        }
        $payes = $this->payes($ids);
        foreach ($bulletins as &$b) {
            $b['totaux'] = self::totaux($lignes[(int) $b['id']] ?? []);
            $b['paye'] = $payes[(int) $b['id']] ?? [];
        }
        unset($b);
        return $bulletins;
    }

    public function bulletin(int $id): ?array
    {
        $b = Database::un(
            'SELECT b.*, e.nom AS employe_nom, e.poste, e.contrat, e.periode AS employe_periode, e.utilisateur_id, e.telephone AS employe_telephone,
                    e.debut AS employe_debut, x.nom AS etablissement_nom, x.adresse AS etablissement_adresse, x.ville AS etablissement_ville,
                    c.nom AS cree_par_nom, v.nom AS valide_par_nom, a.nom AS annule_par_nom
               FROM bulletins b JOIN employes e ON e.id = b.employe_id JOIN etablissements x ON x.id = b.etablissement_id
               LEFT JOIN utilisateurs c ON c.id = b.cree_par LEFT JOIN utilisateurs v ON v.id = b.valide_par LEFT JOIN utilisateurs a ON a.id = b.annule_par
              WHERE b.id = ?',
            [$id]
        );
        if ($b === null) {
            return null;
        }
        $b['lignes'] = $this->lignes($id);
        $b['totaux'] = self::totaux($b['lignes']);
        $b['paye'] = $this->payes([$id])[$id] ?? [];
        $b['paiements'] = $this->paiements(['bulletin' => $id]);
        $b['dettes_retenues'] = Database::tous('SELECT bd.*, d.devise_id, d.cree_le AS dette_le, d.reste FROM bulletin_dettes bd JOIN dettes d ON d.id = bd.dette_id WHERE bd.bulletin_id = ?', [$id]);
        return $b;
    }

    /** @return list<array> lignes d'un bulletin, gains puis retenues */
    public function lignes(int $bulletinId): array
    {
        return Database::tous(
            "SELECT l.*, s.fermee_le AS session_fermee_le, s.ouverte_le AS session_ouverte_le, a.date_avance
               FROM bulletin_lignes l LEFT JOIN sessions_caisse s ON s.id = l.session_id LEFT JOIN avances a ON a.id = l.avance_id
              WHERE l.bulletin_id = ?
              ORDER BY FIELD(l.type, 'base', 'heures_sup', 'ferie', 'commission', 'prime', 'pourboire', 'absence', 'sans_solde', 'avance', 'dette', 'manquant', 'retenue'), l.position, l.id",
            [$bulletinId]
        );
    }

    /** @return array{gains: array<int, float>, retenues: array<int, float>, net: array<int, float>} par devise */
    public static function totaux(array $lignes): array
    {
        $gains = $retenues = [];
        foreach ($lignes as $l) {
            $d = (int) $l['devise_id'];
            if (in_array($l['type'], self::GAINS, true)) {
                $gains[$d] = ($gains[$d] ?? 0) + (float) $l['montant'];
            } else {
                $retenues[$d] = ($retenues[$d] ?? 0) + (float) $l['montant'];
            }
        }
        $net = [];
        foreach (array_unique(array_merge(array_keys($gains), array_keys($retenues))) as $d) {
            $net[$d] = round(($gains[$d] ?? 0) - ($retenues[$d] ?? 0), 3);
        }
        return ['gains' => $gains, 'retenues' => $retenues, 'net' => $net];
    }

    /** @return array<int, array<int, float>> bulletin → devise → payé (paiements valides) */
    private function payes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $resultat = [];
        foreach (Database::tous(
            "SELECT p.bulletin_id, l.devise_id, SUM(l.montant) AS montant FROM paiements_personnel p JOIN paiement_personnel_lignes l ON l.paiement_id = p.id
              WHERE p.statut = 'valide' AND p.type = 'salaire' AND p.bulletin_id IN (" . implode(',', array_map('intval', $ids)) . ')
              GROUP BY p.bulletin_id, l.devise_id'
        ) as $l) {
            $resultat[(int) $l['bulletin_id']][(int) $l['devise_id']] = (float) $l['montant'];
        }
        return $resultat;
    }

    /** @return array<int, float> reste à payer par devise d'un bulletin (tel que rendu par bulletin()) */
    public static function resteAPayer(array $bulletin): array
    {
        $reste = [];
        foreach ($bulletin['totaux']['net'] as $d => $net) {
            $r = round($net - ($bulletin['paye'][$d] ?? 0), 3);
            if ($r > self::EPSILON) {
                $reste[$d] = $r;
            }
        }
        return $reste;
    }

    /** Bulletin non annulé de l'employé qui chevauche cette période. */
    public function chevauchement(int $employeId, string $debut, string $fin, int $saufId = 0): ?array
    {
        return Database::un("SELECT * FROM bulletins WHERE employe_id = ? AND statut <> 'annule' AND debut <= ? AND fin >= ? AND id <> ? LIMIT 1",
            [$employeId, $fin, $debut, $saufId]);
    }

    // ---------- Préparation et calcul ----------

    /**
     * Préparer le bulletin (brouillon) d'un employé pour une période.
     * @throws \DomainException « chevauchement »
     */
    public function preparer(array $employe, string $debut, string $fin, int $par): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::un('SELECT id FROM employes WHERE id = ? FOR UPDATE', [(int) $employe['id']]);
            if ($this->chevauchement((int) $employe['id'], $debut, $fin) !== null) {
                throw new \DomainException('chevauchement');
            }
            Database::executer('INSERT INTO bulletins (employe_id, etablissement_id, debut, fin, periode, cree_par) VALUES (?, ?, ?, ?, ?, ?)',
                [(int) $employe['id'], (int) $employe['etablissement_id'], $debut, $fin, $employe['periode'], $par]);
            $id = Database::dernierId();
            $this->calculer($id, $employe, $par, false);
            Journal::ecrire('bulletin_prepare', $employe['nom'], ['bulletin' => $id, 'du' => $debut, 'au' => $fin], $par);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * (Re)calcule les lignes d'un brouillon : les lignes calculées sont remplacées (les propositions de retenues
     * reviennent à leur valeur proposée), les lignes ajoutées à la main sont gardées.
     */
    public function calculer(int $bulletinId, array $employe, int $par, bool $journal = true): void
    {
        $b = Database::un('SELECT * FROM bulletins WHERE id = ?', [$bulletinId]);
        if ($b === null || $b['statut'] !== 'brouillon') {
            throw new \DomainException('pas_brouillon');
        }
        $debut = $b['debut'];
        $fin = $b['fin'];
        $periode = $b['periode'];
        Database::executer('DELETE FROM bulletin_lignes WHERE bulletin_id = ? AND auto = 1', [$bulletinId]);
        $manuelles = Database::tous('SELECT * FROM bulletin_lignes WHERE bulletin_id = ?', [$bulletinId]);

        $bilan = (new PlanningRepository())->bilan([(int) $employe['id']], $debut, $fin)[(int) $employe['id']]['totaux'];
        $devises = $this->tresorerie->devises();
        $ordre = array_flip(array_keys($devises));
        $salaires = array_filter($employe['salaires'], static fn ($m) => $m > 0);
        uksort($salaires, static fn ($a, $c) => ($ordre[$a] ?? 999) <=> ($ordre[$c] ?? 999));

        // Prorata d'arrivée ou de départ en cours de période (mois, semaine)
        $joursPeriode = (int) round((strtotime($fin) - strtotime($debut)) / 86400) + 1;
        $du = max($debut, (string) $employe['debut']);
        $au = $employe['fin'] !== null ? min($fin, (string) $employe['fin']) : $fin;
        $joursEmploye = $au >= $du ? (int) round((strtotime($au) - strtotime($du)) / 86400) + 1 : 0;
        $prorata = $periode === 'soiree' ? 1.0 : min(1.0, $joursEmploye / max(1, $joursPeriode));

        $heuresPlanifiees = $bilan['minutes_planifiees'] / 60;
        $heuresRef = $heuresPlanifiees > 0 ? $heuresPlanifiees : self::HEURES_REFERENCE[$periode];
        $majSup = (float) Parametres::get('personnel.majoration_heures_sup', '1.3');
        $majFerie = (float) Parametres::get('personnel.majoration_ferie', '2');
        $lignes = [];
        $ajouter = static function (array $l) use (&$lignes): void {
            $lignes[] = $l + ['quantite' => null, 'taux' => null, 'avance_id' => null, 'session_id' => null, 'source_devise_id' => null,
                'source_montant' => null, 'source_du' => null];
        };

        foreach ($salaires as $deviseId => $salaire) {
            if ($periode === 'soiree') {
                $nuits = $bilan['jours_travailles'];
                if ($nuits > 0) {
                    $ajouter(['type' => 'base', 'libelle' => 'Soirées travaillées', 'quantite' => $nuits, 'taux' => $salaire,
                        'devise_id' => $deviseId, 'montant' => $this->arrondir($nuits * $salaire, $deviseId)]);
                }
                $tauxHoraire = $salaire / self::HEURES_REFERENCE['soiree'];
            } else {
                $base = $this->arrondir($salaire * $prorata, $deviseId);
                $ajouter(['type' => 'base', 'libelle' => 'Salaire de base', 'quantite' => $prorata < 1 ? $joursEmploye : null,
                    'taux' => $prorata < 1 ? $salaire / $joursPeriode : null, 'devise_id' => $deviseId, 'montant' => $base]);
                $tauxHoraire = $salaire * $prorata / max(0.01, $heuresRef * ($heuresPlanifiees > 0 ? 1 : $prorata));
                // Absences non justifiées (et demandes encore en attente), congés sans solde : au prorata des créneaux planifiés
                if ($bilan['creneaux'] > 0) {
                    $parCreneau = $salaire * $prorata / $bilan['creneaux'];
                    $absences = $bilan['absences'] + $bilan['en_attente'];
                    if ($absences > 0) {
                        $ajouter(['type' => 'absence', 'libelle' => 'Absences non justifiées', 'quantite' => $absences, 'taux' => $parCreneau,
                            'devise_id' => $deviseId, 'montant' => $this->arrondir(min($base, $absences * $parCreneau), $deviseId)]);
                    }
                    if ($bilan['sans_solde'] > 0) {
                        $ajouter(['type' => 'sans_solde', 'libelle' => 'Congé sans solde', 'quantite' => $bilan['sans_solde'], 'taux' => $parCreneau,
                            'devise_id' => $deviseId, 'montant' => $this->arrondir(min($base, $bilan['sans_solde'] * $parCreneau), $deviseId)]);
                    }
                }
            }
            if ($bilan['minutes_sup'] > 0) {
                $heures = round($bilan['minutes_sup'] / 60, 2);
                $ajouter(['type' => 'heures_sup', 'libelle' => 'Heures supplémentaires', 'quantite' => $heures, 'taux' => $tauxHoraire * $majSup,
                    'devise_id' => $deviseId, 'montant' => $this->arrondir($heures * $tauxHoraire * $majSup, $deviseId)]);
            }
            if ($bilan['minutes_ferie'] > 0 && $majFerie > 1) {
                $heures = round($bilan['minutes_ferie'] / 60, 2);
                $ajouter(['type' => 'ferie', 'libelle' => 'Heures un jour férié', 'quantite' => $heures, 'taux' => $tauxHoraire * ($majFerie - 1),
                    'devise_id' => $deviseId, 'montant' => $this->arrondir($heures * $tauxHoraire * ($majFerie - 1), $deviseId)]);
            }
        }

        // Commission sur les ventes payées de la personne (une vente à crédit compte une fois l'ardoise soldée)
        if ((float) $employe['commission_pct'] > 0 && !empty($employe['utilisateur_id'])) {
            foreach (Database::tous(
                "SELECT v.devise_id, SUM(v.total) AS total FROM ventes v
                  WHERE v.utilisateur_id = ? AND v.statut = 'payee' AND v.cree_le BETWEEN ? AND ?
                    AND NOT EXISTS (SELECT 1 FROM dettes d WHERE d.vente_id = v.id AND d.statut NOT IN ('soldee', 'annulee'))
                  GROUP BY v.devise_id",
                [(int) $employe['utilisateur_id'], $debut . ' 00:00:00', $fin . ' 23:59:59']
            ) as $v) {
                $montant = $this->arrondir((float) $v['total'] * (float) $employe['commission_pct'] / 100, (int) $v['devise_id']);
                if ($montant > 0) {
                    $ajouter(['type' => 'commission', 'libelle' => 'Commission sur les ventes', 'quantite' => (float) $employe['commission_pct'],
                        'taux' => (float) $v['total'], 'devise_id' => (int) $v['devise_id'], 'montant' => $montant]);
                }
            }
        }

        // Avances à déduire (versées jusqu'à la fin de la période), dans chaque devise payée
        foreach (Database::tous(
            "SELECT a.id, a.date_avance, l.devise_id, SUM(l.montant) AS montant FROM avances a
               JOIN paiements_personnel p ON p.avance_id = a.id AND p.statut = 'valide'
               JOIN paiement_personnel_lignes l ON l.paiement_id = p.id
              WHERE a.employe_id = ? AND a.statut = 'a_deduire' AND a.date_avance <= ?
              GROUP BY a.id, a.date_avance, l.devise_id ORDER BY a.date_avance, a.id",
            [(int) $employe['id'], $fin . ' 23:59:59']
        ) as $a) {
            $ajouter(['type' => 'avance', 'libelle' => 'Avance sur salaire', 'avance_id' => (int) $a['id'], 'devise_id' => (int) $a['devise_id'],
                'montant' => (float) $a['montant']]);
        }

        // Ce qui reste à payer dans chaque devise, avant les retenues proposées
        $disponible = self::totaux(array_merge($manuelles, $lignes))['net'];
        $principale = array_key_first($salaires) ?? (array_key_first($disponible) ?? null);

        // Retenues proposées : dettes du personnel, puis manquants de caisse (les plus anciens d'abord),
        // plafonnées à ce qui reste dans la devise retenue ; le reste dû sera proposé sur le bulletin suivant
        $proposer = function (string $type, string $libelle, int $sourceDevise, float $du, ?int $sessionId) use (&$disponible, $principale, $ajouter): void {
            $cible = isset($disponible[$sourceDevise]) && $disponible[$sourceDevise] > self::EPSILON ? $sourceDevise : ($principale ?? $sourceDevise);
            $taux = $cible === $sourceDevise ? 1.0 : ($this->dettes->convertir(1.0, $sourceDevise, $cible) ?? null);
            if ($taux === null) {
                $cible = $sourceDevise;
                $taux = 1.0;
            }
            $maximum = $this->arrondirHaut($du * $taux, $cible);
            $montant = max(0.0, min($maximum, $this->arrondir($disponible[$cible] ?? 0.0, $cible)));
            $disponible[$cible] = ($disponible[$cible] ?? 0.0) - $montant;
            $ajouter(['type' => $type, 'libelle' => $libelle, 'taux' => $taux, 'devise_id' => $cible, 'montant' => $montant,
                'session_id' => $sessionId, 'source_devise_id' => $sourceDevise, 'source_du' => $du,
                'source_montant' => $taux == 1.0 ? $montant : min($du, round($montant / $taux, 3))]);
        };
        $personnel = new PersonnelRepository();
        foreach ($personnel->dettes($employe)['encours'] as $deviseId => $reste) {
            if ($reste > self::EPSILON) {
                $proposer('dette', 'Dettes du personnel', (int) $deviseId, (float) $reste, null);
            }
        }
        foreach ($personnel->manquants($employe, true, $bulletinId) as $m) {
            $proposer('manquant', 'Manquant de caisse', $m['devise_id'], $m['reste'], $m['session_id']);
        }

        foreach ($lignes as $position => $l) {
            Database::executer(
                'INSERT INTO bulletin_lignes (bulletin_id, type, libelle, quantite, taux, devise_id, montant, avance_id, session_id, source_devise_id,
                                              source_montant, source_du, auto, position)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                [$bulletinId, $l['type'], $l['libelle'], $l['quantite'], $l['taux'] !== null ? round((float) $l['taux'], 6) : null, $l['devise_id'],
                    GestionTresorerieRepository::decimal((float) $l['montant']), $l['avance_id'], $l['session_id'], $l['source_devise_id'],
                    $l['source_montant'] !== null ? GestionTresorerieRepository::decimal((float) $l['source_montant']) : null,
                    $l['source_du'] !== null ? GestionTresorerieRepository::decimal((float) $l['source_du']) : null, $position]
            );
        }
        Database::executer(
            'UPDATE bulletins SET creneaux_planifies = ?, jours_travailles = ?, heures_planifiees = ?, heures_travaillees = ?, retards = ?, retard_minutes = ?,
                    absences_injustifiees = ? WHERE id = ?',
            [$bilan['creneaux'], $bilan['jours_travailles'], round($bilan['minutes_planifiees'] / 60, 2), round($bilan['minutes_travaillees'] / 60, 2),
                $bilan['retards'], $bilan['minutes_retard'], $bilan['absences'] + $bilan['en_attente'], $bulletinId]
        );
        if ($journal) {
            Journal::ecrire('bulletin_recalcule', 'bulletin ' . $bulletinId, [], $par);
        }
    }

    // ---------- Modification du brouillon ----------

    /** Maximum d'une retenue proposée (dette, manquant), dans la devise du bulletin. */
    public function maximumRetenue(array $ligne): float
    {
        return $this->arrondirHaut((float) $ligne['source_du'] * (float) ($ligne['taux'] ?: 1), (int) $ligne['devise_id']);
    }

    /**
     * Enregistre les changements d'un brouillon. Les valeurs sont déjà contrôlées par le contrôleur :
     * quantites (id → heures ou créneaux), montants (id → montant d'une retenue proposée ou d'une ligne à la main),
     * retirer (ids d'avances reportées et de lignes à la main supprimées), nouvelle (ligne à la main), note.
     * @param array{quantites: array<int, float>, montants: array<int, float>, retirer: list<int>, nouvelle: ?array, note: ?string} $c
     */
    public function modifier(int $bulletinId, array $c, int $par): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $b = Database::un('SELECT * FROM bulletins WHERE id = ? FOR UPDATE', [$bulletinId]);
            if ($b === null || $b['statut'] !== 'brouillon') {
                throw new \DomainException('pas_brouillon');
            }
            $lignes = array_column($this->lignes($bulletinId), null, 'id');
            foreach ($c['quantites'] as $id => $quantite) {
                $l = $lignes[$id] ?? null;
                if ($l !== null && in_array($l['type'], ['heures_sup', 'ferie', 'absence', 'sans_solde'], true)) {
                    $montant = $this->arrondir($quantite * (float) $l['taux'], (int) $l['devise_id']);
                    Database::executer('UPDATE bulletin_lignes SET quantite = ?, montant = ? WHERE id = ?', [$quantite, GestionTresorerieRepository::decimal($montant), $id]);
                }
            }
            foreach ($c['montants'] as $id => $montant) {
                $l = $lignes[$id] ?? null;
                if ($l === null) {
                    continue;
                }
                if (in_array($l['type'], ['dette', 'manquant'], true)) {
                    $montant = max(0.0, min($this->maximumRetenue($l), $montant));
                    $taux = (float) ($l['taux'] ?: 1);
                    $source = $taux == 1.0 ? $montant : min((float) $l['source_du'], round($montant / $taux, 3));
                    Database::executer('UPDATE bulletin_lignes SET montant = ?, source_montant = ? WHERE id = ?',
                        [GestionTresorerieRepository::decimal($montant), GestionTresorerieRepository::decimal($source), $id]);
                } elseif (!$l['auto'] && $montant > 0) {
                    Database::executer('UPDATE bulletin_lignes SET montant = ? WHERE id = ?', [GestionTresorerieRepository::decimal($montant), $id]);
                }
            }
            foreach ($c['retirer'] as $id) {
                $l = $lignes[$id] ?? null;
                if ($l !== null && ($l['type'] === 'avance' || !$l['auto'])) {
                    Database::executer('DELETE FROM bulletin_lignes WHERE id = ?', [$id]);
                }
            }
            if (!empty($c['nouvelle'])) {
                $n = $c['nouvelle'];
                Database::executer('INSERT INTO bulletin_lignes (bulletin_id, type, libelle, devise_id, montant, auto, position) VALUES (?, ?, ?, ?, ?, 0, 99)',
                    [$bulletinId, $n['type'], $n['libelle'], (int) $n['devise_id'], GestionTresorerieRepository::decimal((float) $n['montant'])]);
            }
            Database::executer('UPDATE bulletins SET note = ? WHERE id = ?', [$c['note'], $bulletinId]);
            Journal::ecrire('bulletin_modifie', 'bulletin ' . $bulletinId, [], $par);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ---------- Validation et annulation ----------

    /**
     * Valider : les retenues deviennent définitives (dettes réduites sans trésorerie, manquants marqués,
     * avances déduites). Refusé si un net devient négatif ou si une retenue n'est plus possible.
     * @throws \DomainException « pas_brouillon », « chevauchement », « net_negatif », « manquant_deja », « dette_trop », « avance_deja »
     */
    public function valider(int $bulletinId, int $par): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $b = Database::un('SELECT * FROM bulletins WHERE id = ? FOR UPDATE', [$bulletinId]);
            if ($b === null || $b['statut'] !== 'brouillon') {
                throw new \DomainException('pas_brouillon');
            }
            if ($this->chevauchement((int) $b['employe_id'], $b['debut'], $b['fin'], $bulletinId) !== null) {
                throw new \DomainException('chevauchement');
            }
            // Les retenues proposées à zéro disparaissent du bulletin validé
            Database::executer("DELETE FROM bulletin_lignes WHERE bulletin_id = ? AND type IN ('dette', 'manquant') AND montant <= 0", [$bulletinId]);
            $lignes = $this->lignes($bulletinId);
            foreach (self::totaux($lignes)['net'] as $net) {
                if ($net < -self::EPSILON) {
                    throw new \DomainException('net_negatif');
                }
            }
            $employe = (new PersonnelRepository())->employe((int) $b['employe_id']);
            foreach ($lignes as $l) {
                if ($l['type'] === 'manquant') {
                    $total = (float) Database::un('SELECT COALESCE(-SUM(ecart), 0) AS n FROM session_caisse_comptes WHERE session_id = ? AND devise_id = ? AND ecart IS NOT NULL',
                        [(int) $l['session_id'], (int) $l['source_devise_id']])['n'];
                    Database::un('SELECT id FROM sessions_caisse WHERE id = ? FOR UPDATE', [(int) $l['session_id']]);
                    $reste = $total - PersonnelRepository::dejaRetenu((int) $l['session_id'], (int) $l['source_devise_id'], $bulletinId);
                    if ((float) $l['source_montant'] > $reste + 0.001) {
                        throw new \DomainException('manquant_deja');
                    }
                } elseif ($l['type'] === 'dette') {
                    $client = Database::un("SELECT id FROM clients WHERE utilisateur_id = ? AND type = 'personnel'", [(int) ($employe['utilisateur_id'] ?? 0)]);
                    try {
                        if ($client === null) {
                            throw new \DomainException('rien_a_rembourser');
                        }
                        $this->dettes->retenirSurPaie((int) $client['id'], (int) $l['source_devise_id'], (float) $l['source_montant'], $bulletinId, $par);
                    } catch (\DomainException) {
                        throw new \DomainException('dette_trop');
                    }
                } elseif ($l['type'] === 'avance') {
                    $n = Database::executer("UPDATE avances SET statut = 'deduite', bulletin_id = ? WHERE id = ? AND statut = 'a_deduire'", [$bulletinId, (int) $l['avance_id']]);
                    if ($n === 0 && Database::un('SELECT id FROM avances WHERE id = ? AND bulletin_id = ?', [(int) $l['avance_id'], $bulletinId]) === null) {
                        throw new \DomainException('avance_deja');
                    }
                }
            }
            $rien = array_filter(self::totaux($lignes)['net'], static fn ($n) => $n > self::EPSILON) === [];
            Database::executer('UPDATE bulletins SET statut = ?, valide_par = ?, valide_le = NOW() WHERE id = ?', [$rien ? 'paye' : 'valide', $par, $bulletinId]);
            Journal::ecrire('bulletin_valide', $employe['nom'] ?? null, ['bulletin' => $bulletinId, 'net' => self::totaux($lignes)['net']], $par);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Supprimer un brouillon, ou annuler un bulletin validé sans paiement : les dettes retrouvent ce qui avait été
     * retenu, les manquants et les avances seront proposés à nouveau.
     * @throws \DomainException « deja_paye », « dette_close », « annule »
     */
    public function annuler(int $bulletinId, string $motif, int $par): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $b = Database::un('SELECT * FROM bulletins WHERE id = ? FOR UPDATE', [$bulletinId]);
            if ($b === null || $b['statut'] === 'annule') {
                throw new \DomainException('annule');
            }
            if ($b['statut'] === 'brouillon') {
                Database::executer('DELETE FROM bulletins WHERE id = ?', [$bulletinId]);
                Journal::ecrire('bulletin_supprime', 'bulletin ' . $bulletinId, [], $par);
                $pdo->commit();
                return;
            }
            if (Database::un("SELECT id FROM paiements_personnel WHERE bulletin_id = ? AND statut = 'valide' LIMIT 1", [$bulletinId]) !== null) {
                throw new \DomainException('deja_paye');
            }
            $this->dettes->annulerRetenuesPaie($bulletinId, $par);
            Database::executer("UPDATE avances SET statut = 'a_deduire', bulletin_id = NULL WHERE bulletin_id = ? AND statut = 'deduite'", [$bulletinId]);
            Database::executer("UPDATE bulletins SET statut = 'annule', annule_par = ?, annule_le = NOW(), motif_annulation = ? WHERE id = ?",
                [$par, mb_substr($motif, 0, 255), $bulletinId]);
            Journal::ecrire('bulletin_annule', 'bulletin ' . $bulletinId, ['motif' => mb_substr($motif, 0, 255)], $par);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ---------- Paiements (salaires et avances) ----------

    /**
     * @param array{bulletin?: int, employe?: int, debut?: string, fin?: string, type?: string} $f
     * @return list<array> paiements avec leurs lignes
     */
    public function paiements(array $f, ?array $portee = null): array
    {
        $params = [];
        $where = [PersonnelRepository::conditionPortee($portee, $params)];
        foreach (['bulletin' => 'p.bulletin_id', 'employe' => 'p.employe_id', 'avance' => 'p.avance_id'] as $cle => $colonne) {
            if (!empty($f[$cle])) {
                $where[] = "$colonne = ?";
                $params[] = (int) $f[$cle];
            }
        }
        if (!empty($f['type'])) {
            $where[] = 'p.type = ?';
            $params[] = $f['type'];
        }
        if (!empty($f['debut']) && !empty($f['fin'])) {
            $where[] = 'p.date_paiement BETWEEN ? AND ?';
            array_push($params, $f['debut'] . ' 00:00:00', $f['fin'] . ' 23:59:59');
        }
        $paiements = Database::tous(
            'SELECT p.*, e.nom AS employe_nom, u.nom AS utilisateur_nom, a.nom AS annule_par_nom FROM paiements_personnel p JOIN employes e ON e.id = p.employe_id
               LEFT JOIN utilisateurs u ON u.id = p.utilisateur_id LEFT JOIN utilisateurs a ON a.id = p.annule_par
              WHERE ' . implode(' AND ', $where) . ' ORDER BY p.date_paiement DESC, p.id DESC LIMIT 500',
            $params
        );
        if ($paiements === []) {
            return [];
        }
        $ids = array_map(static fn (array $p) => (int) $p['id'], $paiements);
        $lignes = [];
        foreach (Database::tous('SELECT l.*, c.nom AS compte_nom, c.type AS compte_type FROM paiement_personnel_lignes l JOIN comptes_tresorerie c ON c.id = l.compte_id
                                  WHERE l.paiement_id IN (' . implode(',', $ids) . ') ORDER BY l.position, l.id') as $l) {
            $lignes[(int) $l['paiement_id']][] = $l;
        }
        foreach ($paiements as &$p) {
            $p['lignes'] = $lignes[(int) $p['id']] ?? [];
        }
        unset($p);
        return $paiements;
    }

    public function paiement(int $id): ?array
    {
        $p = Database::un('SELECT * FROM paiements_personnel WHERE id = ?', [$id]);
        if ($p !== null) {
            $p['lignes'] = Database::tous('SELECT * FROM paiement_personnel_lignes WHERE paiement_id = ? ORDER BY position, id', [$id]);
        }
        return $p;
    }

    /** Écrit un paiement et ses sorties de trésorerie ; à appeler dans une transaction. @param list<array{compte: array, devise: array, montant: float}> $lignes */
    private function ecrirePaiement(string $type, array $employe, ?int $bulletinId, ?int $avanceId, string $date, ?string $note, array $lignes, string $libelle, int $par): int
    {
        Database::executer(
            'INSERT INTO paiements_personnel (type, employe_id, etablissement_id, bulletin_id, avance_id, date_paiement, note, utilisateur_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$type, (int) $employe['id'], (int) $employe['etablissement_id'], $bulletinId, $avanceId, $date, $note, $par]
        );
        $id = Database::dernierId();
        foreach (array_values($lignes) as $position => $l) {
            Database::executer('INSERT INTO paiement_personnel_lignes (paiement_id, compte_id, devise_id, montant, position) VALUES (?, ?, ?, ?, ?)',
                [$id, (int) $l['compte']['id'], (int) $l['devise']['id'], GestionTresorerieRepository::decimal($l['montant']), $position]);
            $this->tresorerie->ajouterMouvement($l['compte'], 'sortie', $l['montant'], $type, $id, $date, $libelle, $par, (int) $l['devise']['id']);
        }
        return $id;
    }

    /**
     * Payer tout ou partie d'un bulletin validé ; chaque devise ne peut dépasser ce qui reste à payer dans cette devise.
     * @param list<array{compte: array, devise: array, montant: float}> $lignes
     * @throws \DomainException « pas_valide », « devise » (devise sans reste à payer), « trop »
     */
    public function payer(int $bulletinId, array $lignes, string $date, ?string $note, string $libelle, int $par): int
    {
        return $this->tresorerie->transaction(function () use ($bulletinId, $lignes, $date, $note, $libelle, $par): int {
            Database::un('SELECT id FROM bulletins WHERE id = ? FOR UPDATE', [$bulletinId]);
            $b = $this->bulletin($bulletinId);
            if ($b === null || $b['statut'] !== 'valide') {
                throw new \DomainException('pas_valide');
            }
            $reste = self::resteAPayer($b);
            $parDevise = [];
            foreach ($lignes as $l) {
                $d = (int) $l['devise']['id'];
                $parDevise[$d] = ($parDevise[$d] ?? 0) + $l['montant'];
            }
            foreach ($parDevise as $d => $montant) {
                if (!isset($reste[$d])) {
                    throw new \DomainException('devise');
                }
                if ($montant > $reste[$d] + self::EPSILON) {
                    throw new \DomainException('trop');
                }
            }
            $employe = (new PersonnelRepository())->employe((int) $b['employe_id']);
            $employe['etablissement_id'] = (int) $b['etablissement_id'];
            $id = $this->ecrirePaiement('salaire', $employe, $bulletinId, null, $date, $note, $lignes, $libelle, $par);
            $solde = true;
            foreach ($reste as $d => $r) {
                if ($r - ($parDevise[$d] ?? 0) > self::EPSILON) {
                    $solde = false;
                }
            }
            if ($solde) {
                Database::executer("UPDATE bulletins SET statut = 'paye' WHERE id = ?", [$bulletinId]);
            }
            Journal::ecrire('salaire_paye', $employe['nom'], ['bulletin' => $bulletinId, 'paiement' => $id, 'montants' => $parDevise], $par);
            return $id;
        });
    }

    /**
     * Annuler un paiement saisi par erreur : contre-écriture dans chaque compte (l'argent y revient), le bulletin
     * redevient « validé » ; une avance annulée n'est plus déduite.
     * @throws \DomainException « paiement_introuvable », « avance_deduite »
     */
    public function annulerPaiement(int $paiementId, string $motif, int $par): void
    {
        $this->tresorerie->transaction(function () use ($paiementId, $motif, $par): void {
            $p = Database::un('SELECT * FROM paiements_personnel WHERE id = ? FOR UPDATE', [$paiementId]);
            if ($p === null || $p['statut'] !== 'valide') {
                throw new \DomainException('paiement_introuvable');
            }
            if ($p['type'] === 'avance') {
                $avance = Database::un('SELECT * FROM avances WHERE id = ? FOR UPDATE', [(int) $p['avance_id']]);
                if ($avance['statut'] !== 'a_deduire' || Database::un(
                    "SELECT l.id FROM bulletin_lignes l JOIN bulletins b ON b.id = l.bulletin_id WHERE l.avance_id = ? AND b.statut = 'brouillon' LIMIT 1",
                    [(int) $avance['id']]
                ) !== null) {
                    throw new \DomainException('avance_deduite');
                }
                Database::executer("UPDATE avances SET statut = 'annulee', annule_par = ?, annule_le = NOW(), motif_annulation = ? WHERE id = ?",
                    [$par, mb_substr($motif, 0, 255), (int) $avance['id']]);
            }
            $maintenant = date('Y-m-d H:i:s');
            foreach (Database::tous('SELECT * FROM paiement_personnel_lignes WHERE paiement_id = ?', [$paiementId]) as $l) {
                $this->tresorerie->ajouterMouvement(['id' => (int) $l['compte_id']], 'entree', (float) $l['montant'], $p['type'], $paiementId, $maintenant,
                    'Annulation du paiement ' . $paiementId, $par, (int) $l['devise_id']);
            }
            Database::executer("UPDATE paiements_personnel SET statut = 'annule', annule_par = ?, annule_le = NOW(), motif_annulation = ? WHERE id = ?",
                [$par, mb_substr($motif, 0, 255), $paiementId]);
            if ($p['bulletin_id'] !== null) {
                Database::executer("UPDATE bulletins SET statut = 'valide' WHERE id = ? AND statut = 'paye'", [(int) $p['bulletin_id']]);
            }
            Journal::ecrire('paiement_personnel_annule', 'paiement ' . $paiementId, ['motif' => mb_substr($motif, 0, 255)], $par);
        });
    }

    // ---------- Avances ----------

    /** @return list<array> avances avec leur paiement (lignes) */
    public function avances(?array $portee, array $f = []): array
    {
        $params = [];
        $where = [PersonnelRepository::conditionPortee($portee, $params)];
        if (!empty($f['statut'])) {
            $where[] = 'a.statut = ?';
            $params[] = $f['statut'];
        }
        if (!empty($f['employe'])) {
            $where[] = 'a.employe_id = ?';
            $params[] = (int) $f['employe'];
        }
        if (!empty($f['etablissement'])) {
            $where[] = 'a.etablissement_id = ?';
            $params[] = (int) $f['etablissement'];
        }
        if (!empty($f['id'])) {
            $where[] = 'a.id = ?';
            $params[] = (int) $f['id'];
        }
        $avances = Database::tous(
            'SELECT a.*, e.nom AS employe_nom, e.poste, x.nom AS etablissement_nom, c.nom AS cree_par_nom, p.id AS paiement_id,
                    b.debut AS bulletin_debut, b.fin AS bulletin_fin
               FROM avances a JOIN employes e ON e.id = a.employe_id JOIN etablissements x ON x.id = a.etablissement_id
               LEFT JOIN utilisateurs c ON c.id = a.cree_par
               LEFT JOIN paiements_personnel p ON p.avance_id = a.id AND p.type = \'avance\'
               LEFT JOIN bulletins b ON b.id = a.bulletin_id
              WHERE ' . implode(' AND ', $where) . " ORDER BY a.statut = 'a_deduire' DESC, a.date_avance DESC LIMIT 300",
            $params
        );
        $ids = array_filter(array_map(static fn (array $a) => (int) $a['paiement_id'], $avances));
        $lignes = [];
        if ($ids !== []) {
            foreach (Database::tous('SELECT l.*, c.nom AS compte_nom, c.type AS compte_type FROM paiement_personnel_lignes l JOIN comptes_tresorerie c ON c.id = l.compte_id
                                      WHERE l.paiement_id IN (' . implode(',', $ids) . ')') as $l) {
                $lignes[(int) $l['paiement_id']][] = $l;
            }
        }
        foreach ($avances as &$a) {
            $a['lignes'] = $lignes[(int) $a['paiement_id']] ?? [];
        }
        unset($a);
        return $avances;
    }

    /** @param list<array{compte: array, devise: array, montant: float}> $lignes */
    public function creerAvance(array $employe, string $date, ?string $motif, array $lignes, string $libelle, int $par): int
    {
        return $this->tresorerie->transaction(function () use ($employe, $date, $motif, $lignes, $libelle, $par): int {
            Database::executer('INSERT INTO avances (employe_id, etablissement_id, date_avance, motif, cree_par) VALUES (?, ?, ?, ?, ?)',
                [(int) $employe['id'], (int) $employe['etablissement_id'], $date, $motif, $par]);
            $id = Database::dernierId();
            $this->ecrirePaiement('avance', $employe, null, $id, $date, $motif, $lignes, $libelle, $par);
            Journal::ecrire('avance_versee', $employe['nom'], ['avance' => $id, 'montants' => array_map(static fn ($l) => [$l['devise']['code'], $l['montant']], $lignes)], $par);
            return $id;
        });
    }

    // ---------- Masse salariale ----------

    /**
     * Masse salariale par établissement et par mois (mois de fin de la période des bulletins validés ou payés) :
     * brut, retenues, net par devise ; et ce qui a été versé (salaires, avances) d'après les paiements du mois.
     * @return array<string, array<int, array{etablissement: string, brut: array, retenues: array, net: array, salaires: array, avances: array, bulletins: int}>> mois → établissement
     */
    public function masseSalariale(?array $portee, string $du, string $au, ?int $etablissementId = null): array
    {
        $params = [$du, $au];
        $filtre = '';
        if ($portee !== null) {
            if ($portee === []) {
                return [];
            }
            $filtre .= ' AND x.id IN (' . implode(',', array_map('intval', $portee)) . ')';
        }
        if ($etablissementId !== null) {
            $filtre .= ' AND x.id = ' . (int) $etablissementId;
        }
        $resultat = [];
        $case = function (string $mois, int $etab, string $nom) use (&$resultat): void {
            $resultat[$mois][$etab] ??= ['etablissement' => $nom, 'brut' => [], 'retenues' => [], 'net' => [], 'salaires' => [], 'avances' => [], 'bulletins' => 0, 'employes' => []];
        };
        foreach (Database::tous(
            "SELECT DATE_FORMAT(b.fin, '%Y-%m') AS mois, x.id AS etab, x.nom, b.id AS bulletin, b.employe_id, l.type, l.devise_id, SUM(l.montant) AS montant
               FROM bulletins b JOIN bulletin_lignes l ON l.bulletin_id = b.id JOIN etablissements x ON x.id = b.etablissement_id
              WHERE b.statut IN ('valide', 'paye') AND b.fin BETWEEN ? AND ? $filtre
              GROUP BY mois, x.id, x.nom, b.id, b.employe_id, l.type, l.devise_id",
            $params
        ) as $l) {
            $case($l['mois'], (int) $l['etab'], $l['nom']);
            $c = &$resultat[$l['mois']][(int) $l['etab']];
            $d = (int) $l['devise_id'];
            $cle = in_array($l['type'], self::GAINS, true) ? 'brut' : 'retenues';
            $c[$cle][$d] = ($c[$cle][$d] ?? 0) + (float) $l['montant'];
            $c['net'][$d] = ($c['brut'][$d] ?? 0) - ($c['retenues'][$d] ?? 0);
            $c['bulletins_ids'][(int) $l['bulletin']] = true;
            $c['employes'][(int) $l['employe_id']] = true;
            $c['bulletins'] = count($c['bulletins_ids']);
            unset($c);
        }
        foreach (Database::tous(
            "SELECT DATE_FORMAT(p.date_paiement, '%Y-%m') AS mois, x.id AS etab, x.nom, p.type, l.devise_id, SUM(l.montant) AS montant
               FROM paiements_personnel p JOIN paiement_personnel_lignes l ON l.paiement_id = p.id JOIN etablissements x ON x.id = p.etablissement_id
              WHERE p.statut = 'valide' AND p.date_paiement BETWEEN ? AND ? $filtre
              GROUP BY mois, x.id, x.nom, p.type, l.devise_id",
            [$du . ' 00:00:00', $au . ' 23:59:59']
        ) as $l) {
            $case($l['mois'], (int) $l['etab'], $l['nom']);
            $cle = $l['type'] === 'avance' ? 'avances' : 'salaires';
            $resultat[$l['mois']][(int) $l['etab']][$cle][(int) $l['devise_id']] = ($resultat[$l['mois']][(int) $l['etab']][$cle][(int) $l['devise_id']] ?? 0) + (float) $l['montant'];
        }
        krsort($resultat);
        return $resultat;
    }

    // ---------- Pourboires ----------

    /**
     * Répartit une somme de pourboires entre les brouillons de la période d'un établissement, au prorata des heures
     * travaillées des employés qui y participent (employes.pourboires). Ajoute une ligne « pourboire » à chacun.
     * @return array<int, float> bulletin → part
     * @throws \DomainException « personne » (aucun brouillon d'un participant avec des heures)
     */
    public function repartirPourboires(int $etablissementId, string $debut, string $fin, int $deviseId, float $montant, string $libelle, int $par): array
    {
        return $this->tresorerie->transaction(function () use ($etablissementId, $debut, $fin, $deviseId, $montant, $libelle, $par): array {
            $brouillons = Database::tous(
                "SELECT b.id, b.employe_id, b.heures_travaillees FROM bulletins b JOIN employes e ON e.id = b.employe_id
                  WHERE b.etablissement_id = ? AND b.statut = 'brouillon' AND b.debut <= ? AND b.fin >= ? AND e.pourboires = 1 AND b.heures_travaillees > 0 FOR UPDATE",
                [$etablissementId, $fin, $debut]
            );
            $heures = array_sum(array_map(static fn (array $b) => (float) $b['heures_travaillees'], $brouillons));
            if ($brouillons === [] || $heures <= 0) {
                throw new \DomainException('personne');
            }
            $parts = [];
            $reparti = 0.0;
            foreach ($brouillons as $i => $b) {
                $part = $i === count($brouillons) - 1
                    ? $this->arrondir($montant - $reparti, $deviseId)
                    : $this->arrondir($montant * (float) $b['heures_travaillees'] / $heures, $deviseId);
                $reparti += $part;
                if ($part <= 0) {
                    continue;
                }
                Database::executer('INSERT INTO bulletin_lignes (bulletin_id, type, libelle, quantite, devise_id, montant, auto, position) VALUES (?, \'pourboire\', ?, ?, ?, ?, 0, 98)',
                    [(int) $b['id'], $libelle, (float) $b['heures_travaillees'], $deviseId, GestionTresorerieRepository::decimal($part)]);
                $parts[(int) $b['id']] = $part;
            }
            Journal::ecrire('pourboires_repartis', 'etablissement ' . $etablissementId, ['du' => $debut, 'au' => $fin, 'montant' => $montant, 'devise' => $deviseId, 'parts' => $parts], $par);
            return $parts;
        });
    }
}
