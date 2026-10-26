<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;
use App\Core\Parametres;

/**
 * Plannings (créneaux par journée d'exploitation, service et poste), jours fériés, congés et absences
 * justifiées, et bilan de présence : le planning comparé aux pointages (retards, absences, départs
 * anticipés, heures supplémentaires, heures un jour férié). Le bilan sert à l'écran Présence, aux
 * rapports et au calcul des bulletins de paie.
 */
final class PlanningRepository
{
    public const TYPES_ABSENCE = ['conge', 'maladie', 'permission', 'justification', 'sans_solde'];
    /** Heures supplémentaires comptées au-delà de ce dépassement (minutes) */
    public const SEUIL_HEURES_SUP = 15;

    // ---------- Créneaux ----------

    /** @return list<array> créneaux d'un établissement entre deux journées (incluses) */
    public function creneaux(?int $etablissementId, string $du, string $au, ?array $employes = null): array
    {
        $params = [$du, $au];
        $where = ['p.journee BETWEEN ? AND ?'];
        if ($etablissementId !== null) {
            $where[] = 'p.etablissement_id = ?';
            $params[] = $etablissementId;
        }
        if ($employes !== null) {
            if ($employes === []) {
                return [];
            }
            $where[] = 'p.employe_id IN (' . implode(',', array_fill(0, count($employes), '?')) . ')';
            array_push($params, ...array_map('intval', $employes));
        }
        return Database::tous(
            'SELECT p.*, e.nom AS employe_nom, x.nom AS etablissement_nom FROM plannings p JOIN employes e ON e.id = p.employe_id
               JOIN etablissements x ON x.id = p.etablissement_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY p.journee, p.debut, e.nom',
            $params
        );
    }

    public function creneau(int $id): ?array
    {
        return Database::un('SELECT * FROM plannings WHERE id = ?', [$id]);
    }

    /**
     * Ajouter un créneau à plusieurs journées (« 18:00 – 02:00 » : la fin passe au lendemain).
     * @param list<string> $journees Y-m-d
     * @return array{ajoutes: int, chevauchements: list<string>}
     */
    public function ajouter(int $etablissementId, int $employeId, array $journees, string $heureDebut, string $heureFin,
                            ?string $service, ?string $poste, int $pause, ?string $note, int $par): array
    {
        $ajoutes = 0;
        $chevauchements = [];
        foreach (array_unique($journees) as $journee) {
            [$debut, $fin] = self::bornes($journee, $heureDebut, $heureFin);
            if (Database::un('SELECT id FROM plannings WHERE employe_id = ? AND debut < ? AND fin > ? LIMIT 1', [$employeId, $fin, $debut]) !== null) {
                $chevauchements[] = $journee;
                continue;
            }
            Database::executer(
                'INSERT INTO plannings (etablissement_id, employe_id, journee, debut, fin, service, poste, pause_minutes, note, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$etablissementId, $employeId, $journee, $debut, $fin, $service, $poste, $pause, $note, $par]
            );
            $ajoutes++;
        }
        if ($ajoutes > 0) {
            Journal::ecrire('planning_ajoute', 'employe ' . $employeId, ['journees' => $journees, 'debut' => $heureDebut, 'fin' => $heureFin], $par);
        }
        return ['ajoutes' => $ajoutes, 'chevauchements' => $chevauchements];
    }

    /** @return array{0: string, 1: string} début et fin (la fin au lendemain si elle n'est pas après le début) */
    public static function bornes(string $journee, string $heureDebut, string $heureFin): array
    {
        $debut = strtotime($journee . ' ' . $heureDebut);
        $fin = strtotime($journee . ' ' . $heureFin);
        if ($fin <= $debut) {
            $fin = strtotime('+1 day', $fin);
        }
        return [date('Y-m-d H:i:s', $debut), date('Y-m-d H:i:s', $fin)];
    }

    public function supprimer(int $id, int $par): void
    {
        Database::executer('DELETE FROM plannings WHERE id = ?', [$id]);
        Journal::ecrire('planning_supprime', 'creneau ' . $id, [], $par);
    }

    /** Copier les créneaux d'une semaine vers une autre (sans doublon ni chevauchement). */
    public function copierSemaine(int $etablissementId, string $lundiSource, string $lundiCible, int $par): int
    {
        $decalage = (int) round((strtotime($lundiCible) - strtotime($lundiSource)) / 86400);
        $copies = 0;
        foreach ($this->creneaux($etablissementId, $lundiSource, date('Y-m-d', strtotime($lundiSource . ' +6 days'))) as $c) {
            $debut = date('Y-m-d H:i:s', strtotime($c['debut'] . " +$decalage days"));
            $fin = date('Y-m-d H:i:s', strtotime($c['fin'] . " +$decalage days"));
            if (Database::un('SELECT id FROM plannings WHERE employe_id = ? AND debut < ? AND fin > ? LIMIT 1', [(int) $c['employe_id'], $fin, $debut]) !== null) {
                continue;
            }
            if (Database::un('SELECT id FROM employes WHERE id = ? AND actif = 1', [(int) $c['employe_id']]) === null) {
                continue;
            }
            Database::executer(
                'INSERT INTO plannings (etablissement_id, employe_id, journee, debut, fin, service, poste, pause_minutes, note, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$etablissementId, (int) $c['employe_id'], date('Y-m-d', strtotime($c['journee'] . " +$decalage days")), $debut, $fin,
                    $c['service'], $c['poste'], (int) $c['pause_minutes'], $c['note'], $par]
            );
            $copies++;
        }
        Journal::ecrire('planning_copie', 'etablissement ' . $etablissementId, ['de' => $lundiSource, 'vers' => $lundiCible, 'creneaux' => $copies], $par);
        return $copies;
    }

    /** Services proposés : ceux des horaires de l'établissement (Restaurant, Club…). @return list<string> */
    public function services(int $etablissementId): array
    {
        return array_column(Database::tous('SELECT DISTINCT service_fr FROM horaires WHERE etablissement_id = ? ORDER BY service_fr', [$etablissementId]), 'service_fr');
    }

    // ---------- Jours fériés ----------

    /** @return list<array> */
    public function feries(): array
    {
        return Database::tous('SELECT * FROM jours_feries ORDER BY annee IS NOT NULL, annee, mois, jour');
    }

    /** @return array<string, string> date → nom (dans la langue choisie) des jours fériés actifs entre deux dates */
    public function feriesEntre(string $du, string $au, string $langue = 'fr'): array
    {
        $resultat = [];
        $lignes = Database::tous('SELECT * FROM jours_feries WHERE actif = 1');
        for ($annee = (int) substr($du, 0, 4); $annee <= (int) substr($au, 0, 4); $annee++) {
            foreach ($lignes as $l) {
                if ($l['annee'] !== null && (int) $l['annee'] !== $annee) {
                    continue;
                }
                if (!checkdate((int) $l['mois'], (int) $l['jour'], $annee)) {
                    continue;
                }
                $date = sprintf('%04d-%02d-%02d', $annee, (int) $l['mois'], (int) $l['jour']);
                if ($date >= $du && $date <= $au) {
                    $resultat[$date] = $langue === 'en' ? $l['nom_en'] : $l['nom_fr'];
                }
            }
        }
        ksort($resultat);
        return $resultat;
    }

    public function ajouterFerie(int $mois, int $jour, ?int $annee, string $nomFr, string $nomEn, int $par): void
    {
        Database::executer('INSERT INTO jours_feries (mois, jour, annee, nom_fr, nom_en) VALUES (?, ?, ?, ?, ?)', [$mois, $jour, $annee, $nomFr, $nomEn]);
        Journal::ecrire('ferie_ajoute', $nomFr, ['date' => sprintf('%02d/%02d', $jour, $mois), 'annee' => $annee], $par);
    }

    public function ferieExiste(int $mois, int $jour, ?int $annee): bool
    {
        return Database::un('SELECT id FROM jours_feries WHERE mois = ? AND jour = ? AND annee <=> ?', [$mois, $jour, $annee]) !== null;
    }

    public function definirFerieActif(int $id, bool $actif, int $par): void
    {
        Database::executer('UPDATE jours_feries SET actif = ? WHERE id = ?', [$actif ? 1 : 0, $id]);
        Journal::ecrire($actif ? 'ferie_active' : 'ferie_desactive', 'ferie ' . $id, [], $par);
    }

    public function supprimerFerie(int $id, int $par): void
    {
        Database::executer('DELETE FROM jours_feries WHERE id = ?', [$id]);
        Journal::ecrire('ferie_supprime', 'ferie ' . $id, [], $par);
    }

    // ---------- Congés et absences justifiées ----------

    /** @return list<array> */
    public function absences(?array $portee, array $f = []): array
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
        if (!empty($f['du']) && !empty($f['au'])) {
            $where[] = 'a.debut <= ? AND a.fin >= ?';
            array_push($params, $f['au'], $f['du']);
        }
        return Database::tous(
            'SELECT a.*, e.nom AS employe_nom, e.poste, x.nom AS etablissement_nom, d.nom AS demande_par_nom, v.nom AS decide_par_nom
               FROM absences a JOIN employes e ON e.id = a.employe_id JOIN etablissements x ON x.id = e.etablissement_id
               LEFT JOIN utilisateurs d ON d.id = a.demande_par LEFT JOIN utilisateurs v ON v.id = a.decide_par
              WHERE ' . implode(' AND ', $where) . "
              ORDER BY a.statut = 'en_attente' DESC, a.debut DESC, a.id DESC LIMIT 300",
            $params
        );
    }

    public function absence(int $id): ?array
    {
        return Database::un('SELECT * FROM absences WHERE id = ?', [$id]);
    }

    public function demanderAbsence(int $employeId, string $type, string $debut, string $fin, ?string $motif, int $par): int
    {
        Database::executer('INSERT INTO absences (employe_id, type, debut, fin, motif, demande_par) VALUES (?, ?, ?, ?, ?, ?)',
            [$employeId, $type, $debut, $fin, $motif, $par]);
        $id = Database::dernierId();
        Journal::ecrire('absence_demandee', 'employe ' . $employeId, ['absence' => $id, 'type' => $type, 'du' => $debut, 'au' => $fin], $par);
        return $id;
    }

    /** @throws \DomainException « deja_decidee » */
    public function deciderAbsence(int $id, bool $accepte, ?string $motif, int $par): void
    {
        $n = Database::executer(
            "UPDATE absences SET statut = ?, decide_par = ?, decide_le = NOW(), decision_motif = ? WHERE id = ? AND statut = 'en_attente'",
            [$accepte ? 'acceptee' : 'refusee', $par, $motif, $id]
        );
        if ($n === 0) {
            throw new \DomainException('deja_decidee');
        }
        Journal::ecrire($accepte ? 'absence_acceptee' : 'absence_refusee', 'absence ' . $id, ['motif' => $motif], $par);
    }

    // ---------- Bilan : planning comparé aux pointages ----------

    /**
     * Bilan de présence d'employés sur des journées (incluses), éventuellement pour un seul établissement.
     * Pour chaque employé : totaux et détail par journée (créneaux, pointages, statut, retard, départ anticipé,
     * heures travaillées, heures supplémentaires, heures un jour férié).
     *
     * @param list<int> $employes
     * @return array<int, array{totaux: array, jours: array<string, array>}>
     */
    public function bilan(array $employes, string $du, string $au, ?int $etablissementId = null, ?int $maintenant = null): array
    {
        if ($employes === []) {
            return [];
        }
        $maintenant ??= time();
        $tolerance = (int) Parametres::get('personnel.tolerance_retard', '5');
        $marques = implode(',', array_fill(0, count($employes), '?'));
        $params = array_merge(array_map('intval', $employes), [$du, $au]);
        $filtreEtab = $etablissementId !== null ? ' AND etablissement_id = ' . (int) $etablissementId : '';

        $creneaux = [];
        foreach (Database::tous("SELECT * FROM plannings WHERE employe_id IN ($marques) AND journee BETWEEN ? AND ? $filtreEtab ORDER BY debut", $params) as $c) {
            $creneaux[(int) $c['employe_id']][$c['journee']][] = $c;
        }
        $pointages = [];
        foreach (Database::tous("SELECT * FROM pointages WHERE employe_id IN ($marques) AND journee BETWEEN ? AND ? $filtreEtab ORDER BY arrivee", $params) as $p) {
            $pointages[(int) $p['employe_id']][$p['journee']][] = $p;
        }
        $absences = [];
        foreach (Database::tous("SELECT * FROM absences WHERE employe_id IN ($marques) AND statut <> 'refusee' AND debut <= ? AND fin >= ?",
            array_merge(array_map('intval', $employes), [$au, $du])) as $a) {
            $absences[(int) $a['employe_id']][] = $a;
        }
        $feries = $this->feriesEntre($du, $au, \App\Core\I18n::langue());

        $resultat = [];
        foreach ($employes as $employeId) {
            $employeId = (int) $employeId;
            $totaux = [
                'creneaux' => 0, 'jours_planifies' => 0, 'jours_travailles' => 0, 'minutes_planifiees' => 0, 'minutes_travaillees' => 0,
                'retards' => 0, 'minutes_retard' => 0, 'departs_anticipes' => 0, 'minutes_depart_anticipe' => 0,
                'absences' => 0, 'absences_justifiees' => 0, 'sans_solde' => 0, 'en_attente' => 0,
                'minutes_sup' => 0, 'minutes_ferie' => 0, 'departs_manquants' => 0, 'hors_planning' => 0,
            ];
            $jours = [];
            $journees = array_unique(array_merge(array_keys($creneaux[$employeId] ?? []), array_keys($pointages[$employeId] ?? [])));
            sort($journees);
            foreach ($journees as $journee) {
                $cs = $creneaux[$employeId][$journee] ?? [];
                $ps = $pointages[$employeId][$journee] ?? [];
                $jour = ['journee' => $journee, 'creneaux' => $cs, 'pointages' => $ps, 'ferie' => $feries[$journee] ?? null,
                    'statut' => 'present', 'retard' => 0, 'depart_anticipe' => 0, 'minutes_planifiees' => 0, 'minutes_travaillees' => 0,
                    'minutes_sup' => 0, 'minutes_ferie' => 0, 'absence' => null, 'depart_manquant' => false, 'en_cours' => false];
                $pause = 0;
                foreach ($cs as $c) {
                    $jour['minutes_planifiees'] += max(0, (int) round((strtotime($c['fin']) - strtotime($c['debut'])) / 60) - (int) $c['pause_minutes']);
                    $pause += (int) $c['pause_minutes'];
                }
                $travaillees = 0;
                foreach ($ps as $p) {
                    if ($p['depart'] === null) {
                        if (strtotime($p['arrivee']) >= $maintenant - PointageRepository::HEURES_MAX * 3600) {
                            $jour['en_cours'] = true;
                            $travaillees += (int) max(0, round(($maintenant - strtotime($p['arrivee'])) / 60));
                        } else {
                            $jour['depart_manquant'] = true;
                        }
                        continue;
                    }
                    $travaillees += (int) round((strtotime($p['depart']) - strtotime($p['arrivee'])) / 60);
                }
                // La pause planifiée est déduite des heures travaillées d'un créneau effectué en entier
                $jour['minutes_travaillees'] = max(0, $travaillees - ($travaillees > $pause * 2 ? $pause : 0));
                $couverture = null;
                foreach ($absences[$employeId] ?? [] as $a) {
                    if ($a['debut'] <= $journee && $a['fin'] >= $journee) {
                        $couverture = $a;
                        break;
                    }
                }
                $jour['absence'] = $couverture;

                if ($cs !== [] && $ps === []) {
                    $premier = strtotime($cs[0]['debut']);
                    if ($premier > $maintenant) {
                        $jour['statut'] = 'a_venir';
                    } elseif ($couverture !== null && $couverture['statut'] === 'acceptee') {
                        $jour['statut'] = $couverture['type'] === 'sans_solde' ? 'sans_solde' : 'justifie';
                    } elseif ($jour['ferie'] !== null) {
                        $jour['statut'] = 'ferie';
                    } elseif ($premier + $tolerance * 60 > $maintenant) {
                        $jour['statut'] = 'a_venir';
                    } else {
                        $jour['statut'] = $couverture !== null ? 'en_attente' : 'absent';
                    }
                } elseif ($cs === []) {
                    $jour['statut'] = 'hors_planning';
                } else {
                    $arrivee = strtotime($ps[0]['arrivee']);
                    $retard = (int) floor(($arrivee - strtotime($cs[0]['debut'])) / 60);
                    if ($retard > $tolerance) {
                        $jour['retard'] = $retard;
                        $jour['statut'] = 'retard';
                    }
                    $dernier = end($ps);
                    $finPrevue = strtotime(end($cs)['fin']);
                    if ($dernier['depart'] !== null) {
                        $avance = (int) floor(($finPrevue - strtotime($dernier['depart'])) / 60);
                        if ($avance > $tolerance) {
                            $jour['depart_anticipe'] = $avance;
                        }
                    }
                }
                if ($ps !== [] && !$jour['en_cours']) {
                    if ($jour['ferie'] !== null) {
                        $jour['minutes_ferie'] = $jour['minutes_travaillees'];
                    } elseif ($jour['minutes_travaillees'] - $jour['minutes_planifiees'] > self::SEUIL_HEURES_SUP) {
                        $jour['minutes_sup'] = $jour['minutes_travaillees'] - $jour['minutes_planifiees'];
                    }
                }

                $totaux['creneaux'] += count($cs);
                $totaux['jours_planifies'] += $cs !== [] ? 1 : 0;
                $totaux['jours_travailles'] += $ps !== [] ? 1 : 0;
                $totaux['minutes_planifiees'] += $jour['minutes_planifiees'];
                $totaux['minutes_travaillees'] += $jour['minutes_travaillees'];
                $totaux['retards'] += $jour['retard'] > 0 ? 1 : 0;
                $totaux['minutes_retard'] += $jour['retard'];
                $totaux['departs_anticipes'] += $jour['depart_anticipe'] > 0 ? 1 : 0;
                $totaux['minutes_depart_anticipe'] += $jour['depart_anticipe'];
                $totaux['absences'] += $jour['statut'] === 'absent' ? count($cs) : 0;
                $totaux['absences_justifiees'] += $jour['statut'] === 'justifie' ? count($cs) : 0;
                $totaux['sans_solde'] += $jour['statut'] === 'sans_solde' ? count($cs) : 0;
                $totaux['en_attente'] += $jour['statut'] === 'en_attente' ? count($cs) : 0;
                $totaux['minutes_sup'] += $jour['minutes_sup'];
                $totaux['minutes_ferie'] += $jour['minutes_ferie'];
                $totaux['departs_manquants'] += $jour['depart_manquant'] ? 1 : 0;
                $totaux['hors_planning'] += $jour['statut'] === 'hors_planning' ? 1 : 0;
                $jours[$journee] = $jour;
            }
            $resultat[$employeId] = ['totaux' => $totaux, 'jours' => $jours];
        }
        return $resultat;
    }
}
