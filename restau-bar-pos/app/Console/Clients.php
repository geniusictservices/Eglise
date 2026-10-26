<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\BaseConsole;

/** Clients de la plateforme, leur abonnement et leurs paiements (base de la console). */
final class Clients
{
    public const MOYENS = ['mobile_money', 'virement', 'especes'];
    public const DEVISES = ['USD', 'CDF'];

    private const SELECTION = 'SELECT c.*, f.nom AS formule_nom, f.prix_mois, f.prix_trimestre, f.prix_annee, f.sms_inclus,
                                      f.max_etablissements, f.max_utilisateurs, f.modules AS formule_modules
                                 FROM clients c LEFT JOIN formules f ON f.id = c.formule_id';

    /** @return list<array> avec « statut » (Abonnement::statut()) */
    public static function tous(?string $aujourdhui = null): array
    {
        return array_map(
            static fn (array $c) => $c + ['statut' => Abonnement::statut($c, $aujourdhui)],
            BaseConsole::tous(self::SELECTION . ' ORDER BY c.nom')
        );
    }

    public static function un(int $id): ?array
    {
        $client = BaseConsole::un(self::SELECTION . ' WHERE c.id = ?', [$id]);
        return $client === null ? null : $client + ['statut' => Abonnement::statut($client)];
    }

    public static function parSousDomaine(string $sousDomaine): ?array
    {
        $client = BaseConsole::un(self::SELECTION . ' WHERE c.sous_domaine = ?', [$sousDomaine]);
        return $client === null ? null : $client + ['statut' => Abonnement::statut($client)];
    }

    /** @return list<string> */
    public static function sousDomaines(): array
    {
        return array_column(BaseConsole::tous('SELECT sous_domaine FROM clients'), 'sous_domaine');
    }

    public static function creer(array $v): int
    {
        BaseConsole::executer(
            'INSERT INTO clients (nom, sous_domaine, contact_nom, contact_telephone, contact_email, langue, notes, formule_id, periode, debut, echeance, essai)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$v['nom'], $v['sous_domaine'], $v['contact_nom'] ?: null, $v['contact_telephone'] ?: null, $v['contact_email'] ?: null, $v['langue'],
             $v['notes'] ?: null, $v['formule_id'], $v['periode'], $v['debut'], $v['echeance'], $v['essai'] ? 1 : 0]
        );
        return BaseConsole::dernierId();
    }

    public static function modifierContact(int $id, array $v): void
    {
        BaseConsole::executer(
            'UPDATE clients SET nom = ?, contact_nom = ?, contact_telephone = ?, contact_email = ?, langue = ?, notes = ? WHERE id = ?',
            [$v['nom'], $v['contact_nom'] ?: null, $v['contact_telephone'] ?: null, $v['contact_email'] ?: null, $v['langue'], $v['notes'] ?: null, $id]
        );
    }

    public static function modifierAbonnement(int $id, array $v): void
    {
        BaseConsole::executer(
            'UPDATE clients SET formule_id = ?, periode = ?, debut = ?, echeance = ?, essai = ?, jours_grace = ?, jours_lecture_seule = ? WHERE id = ?',
            [$v['formule_id'], $v['periode'], $v['debut'], $v['echeance'], $v['essai'] ? 1 : 0, $v['jours_grace'], $v['jours_lecture_seule'], $id]
        );
    }

    public static function forcerStatut(int $id, ?string $statut, ?string $motif): void
    {
        BaseConsole::executer(
            'UPDATE clients SET statut_force = ?, statut_force_motif = ?, statut_force_le = ? WHERE id = ?',
            [$statut, $statut === null ? null : $motif, $statut === null ? null : date('Y-m-d H:i:s'), $id]
        );
    }

    public static function definirExpediteur(int $id, ?string $expediteur): void
    {
        BaseConsole::executer('UPDATE clients SET expediteur_sms = ? WHERE id = ?', [$expediteur, $id]);
    }

    public static function marquerInstalle(int $id, array $db): void
    {
        BaseConsole::executer(
            'UPDATE clients SET db_hote = ?, db_port = ?, db_nom = ?, db_utilisateur = ?, installe_le = NOW() WHERE id = ?',
            [$db['hote'], (int) $db['port'], $db['nom'], $db['utilisateur'], $id]
        );
    }

    /** @return list<array> */
    public static function paiements(int $clientId): array
    {
        return BaseConsole::tous(
            'SELECT p.*, a.nom AS saisi_par_nom FROM paiements p LEFT JOIN super_admins a ON a.id = p.saisi_par
              WHERE p.client_id = ? ORDER BY p.paye_le DESC, p.id DESC',
            [$clientId]
        );
    }

    /**
     * Paiement noté à la main : prolonge l'échéance de N périodes (Abonnement::prolonger()), sort de l'essai.
     * $echeanceChoisie : nouvelle échéance saisie à la main (sinon calculée).
     */
    public static function enregistrerPaiement(array $client, array $v, int $adminId): array
    {
        $pdo = BaseConsole::pdo();
        $pdo->beginTransaction();
        try {
            $actuel = BaseConsole::un('SELECT echeance, essai, periode, jours_grace, jours_lecture_seule FROM clients WHERE id = ? FOR UPDATE', [(int) $client['id']]);
            // Date du paiement : un client suspendu repart de ce jour-là
            $nouvelle = $v['echeance'] ?? Abonnement::prolonger($actuel, $v['periode'], (int) $v['periodes'], $v['paye_le']);
            BaseConsole::executer(
                'INSERT INTO paiements (client_id, montant, devise, moyen, reference, paye_le, periode, periodes, echeance_avant, echeance_apres, essai_avant, periode_avant, note, saisi_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $client['id'], $v['montant'], $v['devise'], $v['moyen'], $v['reference'] ?: null, $v['paye_le'], $v['periode'], (int) $v['periodes'],
                 $actuel['echeance'], $nouvelle, (int) $actuel['essai'], $actuel['periode'], $v['note'] ?: null, $adminId]
            );
            $id = BaseConsole::dernierId();
            BaseConsole::executer('UPDATE clients SET echeance = ?, essai = 0, periode = ? WHERE id = ?', [$nouvelle, $v['periode'], (int) $client['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'echeance_avant' => $actuel['echeance'], 'echeance_apres' => $nouvelle];
    }

    /** Annule le dernier paiement encore valable (saisi par erreur) : l'échéance (et la période) reviennent à leur valeur d'avant. */
    public static function annulerPaiement(int $clientId, int $paiementId, string $motif, int $adminId): bool
    {
        $dernier = BaseConsole::un('SELECT * FROM paiements WHERE client_id = ? AND annule_le IS NULL ORDER BY id DESC LIMIT 1', [$clientId]);
        if ($dernier === null || (int) $dernier['id'] !== $paiementId) {
            return false;
        }
        BaseConsole::executer('UPDATE paiements SET annule_le = NOW(), annule_par = ?, annule_motif = ? WHERE id = ?', [$adminId, $motif, $paiementId]);
        BaseConsole::executer('UPDATE clients SET echeance = ?, essai = ?, periode = COALESCE(?, periode) WHERE id = ?',
            [$dernier['echeance_avant'], (int) $dernier['essai_avant'], $dernier['periode_avant'], $clientId]);
        return true;
    }

    /** @return list<array> relances et messages envoyés par la console à ce client */
    public static function messages(int $clientId, int $limite = 30): array
    {
        return BaseConsole::tous('SELECT * FROM messages WHERE client_id = ? ORDER BY id DESC LIMIT ' . $limite, [$clientId]);
    }

    /** @return list<array> */
    public static function journal(?int $clientId = null, int $limite = 50): array
    {
        return BaseConsole::tous(
            'SELECT j.*, a.nom AS admin_nom, c.nom AS client_nom FROM journal j
               LEFT JOIN super_admins a ON a.id = j.super_admin_id LEFT JOIN clients c ON c.id = j.client_id'
            . ($clientId !== null ? ' WHERE j.client_id = ?' : '') . ' ORDER BY j.id DESC LIMIT ' . $limite,
            $clientId !== null ? [$clientId] : []
        );
    }

    /**
     * Chiffres du tableau de bord : clients par statut, revenu mensuel récurrent (formules en cours ramenées au mois,
     * hors essai et suspension), encaissé ce mois-ci par devise, clients en retard, essais qui finissent.
     */
    public static function statistiques(?string $aujourdhui = null): array
    {
        $aujourdhui ??= date('Y-m-d');
        $clients = self::tous($aujourdhui);
        $parStatut = array_fill_keys(Abonnement::STATUTS, 0);
        $mrr = 0.0;
        $retard = [];
        $essais = [];
        $aInstaller = 0;
        foreach ($clients as $c) {
            if ($c['installe_le'] === null) {
                $aInstaller++;
            }
            $code = $c['statut']['code'];
            $parStatut[$code]++;
            if (!in_array($code, ['essai', 'suspendu'], true)) {
                $mrr += Abonnement::mensuel($c, $c['periode']);
            }
            if (in_array($code, ['a_relancer', 'grace', 'lecture_seule', 'suspendu'], true)) {
                $retard[] = $c;
            }
            if ($code === 'essai' && ($c['statut']['jours'] ?? 99) <= Abonnement::JOURS_RELANCE) {
                $essais[] = $c;
            }
        }
        usort($retard, static fn ($a, $b) => ($a['statut']['jours'] ?? 0) <=> ($b['statut']['jours'] ?? 0));
        $debut = substr($aujourdhui, 0, 7) . '-01';
        $encaisse = array_column(BaseConsole::tous(
            'SELECT devise, SUM(montant) AS total, COUNT(*) AS nombre FROM paiements WHERE annule_le IS NULL AND paye_le >= ? AND paye_le < ? GROUP BY devise ORDER BY devise = \'USD\' DESC',
            [$debut, Abonnement::ajouterMois($debut, 1)]
        ), null, 'devise');
        return [
            'clients' => count($clients), 'a_installer' => $aInstaller, 'par_statut' => $parStatut, 'mrr' => $mrr,
            'encaisse' => $encaisse, 'retard' => $retard, 'essais' => $essais,
        ];
    }
}
