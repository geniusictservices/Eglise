<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;
use App\Core\Journee;

/**
 * Listes d'invités et promoteurs (cahier des charges, section 4) : invités d'une soirée (nom, téléphone, +N,
 * promoteur, entrée gratuite ou réduite), pointés à la porte ; commissions des promoteurs : par entrée de leurs
 * invités et/ou un pourcentage des notes (bouteilles) de leurs invités ; relevé d'une période et paiement par la
 * trésorerie (dépense de la catégorie « Commissions », compte et devise).
 */
final class PromoteurRepository
{
    public static function disponible(): bool
    {
        return RapportRepository::tableExiste('promoteurs');
    }

    // ---------- Promoteurs ----------

    public function promoteurs(bool $actifsSeulement = false, ?int $etabId = null): array
    {
        $where = $actifsSeulement ? 'p.actif = 1' : '1 = 1';
        $params = [];
        if ($etabId !== null) {
            $where .= ' AND (p.etablissement_id IS NULL OR p.etablissement_id = ?)';
            $params[] = $etabId;
        }
        return Database::tous(
            'SELECT p.*, u.nom AS utilisateur_nom, d.code AS devise_code, e.nom AS etablissement_nom
               FROM promoteurs p JOIN devises d ON d.id = p.devise_id LEFT JOIN utilisateurs u ON u.id = p.utilisateur_id
          LEFT JOIN etablissements e ON e.id = p.etablissement_id
              WHERE ' . $where . ' ORDER BY p.actif DESC, p.nom',
            $params
        );
    }

    public function promoteur(int $id): ?array
    {
        return Database::un('SELECT p.*, d.code AS devise_code FROM promoteurs p JOIN devises d ON d.id = p.devise_id WHERE p.id = ?', [$id]);
    }

    /** Promoteur lié à un compte (espace du promoteur). */
    public function duCompte(int $utilisateurId): ?array
    {
        return Database::un('SELECT p.*, d.code AS devise_code FROM promoteurs p JOIN devises d ON d.id = p.devise_id WHERE p.utilisateur_id = ? AND p.actif = 1', [$utilisateurId]);
    }

    /** @param array{nom: string, telephone: ?string, utilisateur_id: ?int, etablissement_id: ?int, devise_id: int, par_entree: ?float, pourcent_notes: ?float, actif: bool} $v */
    public function enregistrerPromoteur(?int $id, array $v, int $par): int
    {
        $valeurs = [$v['utilisateur_id'], $v['nom'], $v['telephone'], $v['etablissement_id'], $v['devise_id'],
            $v['par_entree'] !== null ? number_format($v['par_entree'], 3, '.', '') : null,
            $v['pourcent_notes'] !== null ? number_format($v['pourcent_notes'], 2, '.', '') : null, $v['actif'] ? 1 : 0];
        if ($id === null) {
            Database::executer('INSERT INTO promoteurs (utilisateur_id, nom, telephone, etablissement_id, devise_id, par_entree, pourcent_notes, actif) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $valeurs);
            $id = Database::dernierId();
        } else {
            Database::executer('UPDATE promoteurs SET utilisateur_id = ?, nom = ?, telephone = ?, etablissement_id = ?, devise_id = ?, par_entree = ?, pourcent_notes = ?, actif = ? WHERE id = ?',
                array_merge($valeurs, [$id]));
        }
        Journal::ecrire('promoteur_enregistre', $v['nom'], ['par_entree' => $v['par_entree'], 'pourcent' => $v['pourcent_notes']], $par);
        return $id;
    }

    // ---------- Invités ----------

    /**
     * Invités d'une soirée (d'un promoteur, ou recherche par nom ou téléphone).
     * @return list<array>
     */
    public function invites(int $evenementId, ?int $promoteurId = null, string $recherche = ''): array
    {
        $where = 'i.evenement_id = ?';
        $params = [$evenementId];
        if ($promoteurId !== null) {
            $where .= ' AND i.promoteur_id = ?';
            $params[] = $promoteurId;
        }
        if ($recherche !== '') {
            $where .= ' AND (i.nom LIKE ? OR i.telephone LIKE ?)';
            $params[] = '%' . $recherche . '%';
            $params[] = '%' . preg_replace('/\D/', '', $recherche) . '%';
        }
        return Database::tous(
            'SELECT i.*, p.nom AS promoteur_nom, t.nom_fr AS tarif_fr, t.nom_en AS tarif_en, u.nom AS pointe_par_nom
               FROM invites i LEFT JOIN promoteurs p ON p.id = i.promoteur_id LEFT JOIN entree_tarifs t ON t.id = i.tarif_id
          LEFT JOIN utilisateurs u ON u.id = i.pointe_par
              WHERE ' . $where . ' ORDER BY i.arrives > 0, i.nom LIMIT 300',
            $params
        );
    }

    public function invite(int $id): ?array
    {
        return Database::un(
            'SELECT i.*, ev.etablissement_id, ev.debut AS evenement_debut, ev.titre AS evenement_titre, p.nom AS promoteur_nom
               FROM invites i JOIN evenements ev ON ev.id = i.evenement_id LEFT JOIN promoteurs p ON p.id = i.promoteur_id WHERE i.id = ?',
            [$id]
        );
    }

    /** @param array{nom: string, telephone: ?string, accompagnants: int, entree: string, tarif_id: ?int, note: ?string, promoteur_id: ?int} $v */
    public function enregistrerInvite(?int $id, int $evenementId, array $v, int $par): int
    {
        $valeurs = [$v['promoteur_id'], $v['nom'], $v['telephone'], $v['accompagnants'], $v['entree'], $v['entree'] === 'reduite' ? $v['tarif_id'] : null, $v['note']];
        if ($id === null) {
            Database::executer('INSERT INTO invites (evenement_id, promoteur_id, nom, telephone, accompagnants, entree, tarif_id, note, cree_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge([$evenementId], $valeurs, [$par]));
            $id = Database::dernierId();
            Journal::ecrire('invite_ajoute', $v['nom'], ['soiree' => $evenementId, 'promoteur' => $v['promoteur_id']], $par);
        } else {
            Database::executer('UPDATE invites SET promoteur_id = ?, nom = ?, telephone = ?, accompagnants = ?, entree = ?, tarif_id = ?, note = ? WHERE id = ? AND evenement_id = ?',
                array_merge($valeurs, [$id, $evenementId]));
        }
        return $id;
    }

    /** Retire un invité pas encore arrivé. */
    public function retirerInvite(int $id, int $par): bool
    {
        $n = Database::executer('DELETE FROM invites WHERE id = ? AND arrives = 0', [$id]);
        if ($n > 0) {
            Journal::ecrire('invite_retire', (string) $id, [], $par);
        }
        return $n > 0;
    }

    /**
     * Pointe des personnes d'une invitation à la porte (l'invité et ses accompagnants, en une ou plusieurs fois).
     * Entrée gratuite : un billet gratuit (déjà utilisé) par personne, compté dans les entrées de la nuit.
     * Entrée réduite : rien ici, la caisse de l'entrée vend le tarif réduit (billets liés à l'invité).
     * La commission par entrée du promoteur suit le nombre de personnes entrées.
     * @return array{invite: array, billets: list<int>}
     * @throws \DomainException « complet » : plus personne à pointer sur cette invitation
     */
    public function pointer(int $inviteId, int $nombre, array $etab, int $par): array
    {
        $tresorerie = new GestionTresorerieRepository();
        return $tresorerie->transaction(function () use ($inviteId, $nombre, $etab, $par): array {
            $invite = Database::un('SELECT * FROM invites WHERE id = ? FOR UPDATE', [$inviteId]);
            if ($invite === null) {
                throw new \DomainException('introuvable');
            }
            $places = 1 + (int) $invite['accompagnants'] - (int) $invite['arrives'];
            if ($nombre < 1 || $nombre > $places) {
                throw new \DomainException('complet');
            }
            Database::executer('UPDATE invites SET arrives = arrives + ?, arrive_le = COALESCE(arrive_le, NOW()), pointe_par = ? WHERE id = ?', [$nombre, $par, $inviteId]);
            $billets = [];
            if ($invite['entree'] === 'gratuite') {
                $billetterie = new BilletterieRepository();
                $journee = Journee::du(Database::un('SELECT debut FROM evenements WHERE id = ?', [(int) $invite['evenement_id']])['debut'] ?? 'now', $etab);
                $gratuit = Database::un("SELECT id FROM entree_tarifs WHERE etablissement_id = ? AND categorie = 'gratuit' AND (evenement_id = ? OR evenement_id IS NULL) ORDER BY evenement_id IS NULL, id LIMIT 1",
                    [(int) $etab['id'], (int) $invite['evenement_id']]);
                for ($k = 0; $k < $nombre; $k++) {
                    $billets[] = $billetterie->creer([
                        'etablissement_id' => (int) $etab['id'], 'evenement_id' => (int) $invite['evenement_id'], 'journee' => $journee,
                        'tarif_id' => $gratuit ? (int) $gratuit['id'] : null, 'categorie' => 'gratuit', 'vente_id' => null, 'vente_ligne_id' => null,
                        'invite_id' => $inviteId, 'prix' => 0.0, 'devise_id' => null, 'utilise' => true, 'par' => $par,
                    ])['id'];
                }
            }
            $this->commissionEntrees($inviteId);
            return ['invite' => Database::un('SELECT * FROM invites WHERE id = ?', [$inviteId]), 'billets' => $billets];
        });
    }

    /** Commission par entrée d'une invitation : (personnes entrées) × (commission par entrée du promoteur). */
    public function commissionEntrees(int $inviteId): void
    {
        $i = Database::un('SELECT i.*, ev.debut, ev.etablissement_id FROM invites i JOIN evenements ev ON ev.id = i.evenement_id WHERE i.id = ?', [$inviteId]);
        if ($i === null || $i['promoteur_id'] === null) {
            return;
        }
        $p = $this->promoteur((int) $i['promoteur_id']);
        if ($p === null || $p['par_entree'] === null || (float) $p['par_entree'] <= 0 || (int) $i['arrives'] <= 0) {
            return;
        }
        $etab = Database::un('SELECT * FROM etablissements WHERE id = ?', [(int) $i['etablissement_id']]);
        $montant = round((float) $p['par_entree'] * (int) $i['arrives'], 3);
        Database::executer(
            "INSERT INTO commissions (promoteur_id, evenement_id, journee, type, invite_id, base, devise_id, montant, cree_le)
             VALUES (?, ?, ?, 'entree', ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE base = IF(statut = 'due', VALUES(base), base), montant = IF(statut = 'due', VALUES(montant), montant)",
            [(int) $p['id'], (int) $i['evenement_id'], Journee::du($i['debut'], $etab), $inviteId, (int) $i['arrives'], (int) $p['devise_id'], number_format($montant, 3, '.', '')]
        );
    }

    /**
     * Commission sur une note d'un invité, quand elle est entièrement payée : pourcentage du promoteur sur le total
     * des ventes payées de la note (dans la devise de la commission). Recalculée après l'annulation d'une vente.
     */
    public function commissionNote(int $noteId): void
    {
        if (!self::disponible()) {
            return;
        }
        $note = Database::un('SELECT n.*, i.promoteur_id, i.evenement_id FROM notes n JOIN invites i ON i.id = n.invite_id WHERE n.id = ?', [$noteId]);
        if ($note === null || $note['promoteur_id'] === null) {
            return;
        }
        $p = $this->promoteur((int) $note['promoteur_id']);
        if ($p === null || $p['pourcent_notes'] === null || (float) $p['pourcent_notes'] <= 0) {
            return;
        }
        $deviseId = (int) $p['devise_id'];
        $ventes = Database::tous(
            "SELECT DISTINCT v.id, v.total_reference, vt.total AS total_devise FROM note_paiements np JOIN ventes v ON v.id = np.vente_id
          LEFT JOIN vente_totaux vt ON vt.vente_id = v.id AND vt.devise_id = ? WHERE np.note_id = ? AND v.statut = 'payee'",
            [$deviseId, $noteId]
        );
        $taux = (new DeviseRepository())->actives()[$deviseId]['taux'] ?? null;
        $base = 0.0;
        foreach ($ventes as $v) {
            if ($v['total_devise'] !== null) {
                $base += (float) $v['total_devise'];
            } elseif ($v['total_reference'] !== null && $taux !== null) {
                $base += (float) $v['total_reference'] * (float) $taux;
            }
        }
        $montant = round($base * (float) $p['pourcent_notes'] / 100, 3);
        $etab = Database::un('SELECT * FROM etablissements WHERE id = ?', [(int) $note['etablissement_id']]);
        if ($note['statut'] !== 'payee' || $montant <= 0) {
            Database::executer("UPDATE commissions SET statut = 'annulee' WHERE type = 'note' AND note_id = ? AND statut = 'due'", [$noteId]);
            return;
        }
        Database::executer(
            "INSERT INTO commissions (promoteur_id, evenement_id, journee, type, note_id, base, devise_id, montant, cree_le)
             VALUES (?, ?, ?, 'note', ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE base = IF(statut <> 'payee', VALUES(base), base), montant = IF(statut <> 'payee', VALUES(montant), montant),
                                     statut = IF(statut = 'payee', statut, 'due')",
            [(int) $p['id'], (int) $note['evenement_id'], Journee::du($note['ouverte_le'], $etab), $noteId, number_format($base, 3, '.', ''), $deviseId, number_format($montant, 3, '.', '')]
        );
    }

    /** Invités pointés ce soir, pour lier une note (caisse). */
    public function invitesArrives(int $evenementId): array
    {
        return Database::tous(
            'SELECT i.id, i.nom, i.arrives, p.nom AS promoteur_nom FROM invites i LEFT JOIN promoteurs p ON p.id = i.promoteur_id
              WHERE i.evenement_id = ? AND i.arrives > 0 ORDER BY i.nom',
            [$evenementId]
        );
    }

    // ---------- Commissions : relevé et paiement ----------

    /**
     * Relevé d'un promoteur sur une période de journées : commissions dues et payées.
     * @return array{lignes: list<array>, du: float, paye: float, annule: float}
     */
    public function releve(int $promoteurId, string $du, string $au): array
    {
        $lignes = Database::tous(
            "SELECT c.*, ev.titre AS evenement_titre, i.nom AS invite_nom, i.arrives, n.nom AS note_nom, t.nom AS table_nom
               FROM commissions c LEFT JOIN evenements ev ON ev.id = c.evenement_id LEFT JOIN invites i ON i.id = c.invite_id
          LEFT JOIN notes n ON n.id = c.note_id LEFT JOIN salle_tables t ON t.id = n.table_id
              WHERE c.promoteur_id = ? AND c.journee BETWEEN ? AND ? ORDER BY c.journee DESC, c.id DESC",
            [$promoteurId, $du, $au]
        );
        $totaux = ['due' => 0.0, 'payee' => 0.0, 'annulee' => 0.0];
        foreach ($lignes as $l) {
            $totaux[$l['statut']] += (float) $l['montant'];
        }
        return ['lignes' => $lignes, 'du' => $totaux['due'], 'paye' => $totaux['payee'], 'annule' => $totaux['annulee']];
    }

    /** Totaux dus par promoteur (toutes périodes). @return array<int, float> */
    public function dusParPromoteur(): array
    {
        return array_map('floatval', array_column(Database::tous("SELECT promoteur_id, SUM(montant) AS du FROM commissions WHERE statut = 'due' GROUP BY promoteur_id"), 'du', 'promoteur_id'));
    }

    /**
     * Paie les commissions dues d'une période : une dépense « Commissions » depuis un compte qui tient la devise du
     * promoteur, en une transaction ; un double envoi (même clé) ne paie qu'une fois.
     * @return int identifiant du paiement
     * @throws \DomainException « rien » (rien à payer) ou « devise » (le compte ne tient pas la devise)
     */
    public function payer(int $promoteurId, string $du, string $au, array $compte, ?int $etabId, string $cle, int $par): int
    {
        $tresorerie = new GestionTresorerieRepository();
        return $tresorerie->transaction(function () use ($promoteurId, $du, $au, $compte, $etabId, $cle, $par, $tresorerie): int {
            $deja = Database::un('SELECT id FROM commission_paiements WHERE cle = ?', [$cle]);
            if ($deja !== null) {
                return (int) $deja['id'];
            }
            $p = Database::un('SELECT * FROM promoteurs WHERE id = ? FOR UPDATE', [$promoteurId]);
            if ($p === null) {
                throw new \DomainException('rien');
            }
            $deviseId = (int) $p['devise_id'];
            if (!GestionTresorerieRepository::tient($compte, $deviseId)) {
                throw new \DomainException('devise');
            }
            $dues = Database::tous("SELECT id, montant FROM commissions WHERE promoteur_id = ? AND statut = 'due' AND journee BETWEEN ? AND ? FOR UPDATE", [$promoteurId, $du, $au]);
            $total = round(array_sum(array_map(static fn (array $c) => (float) $c['montant'], $dues)), 3);
            if ($dues === [] || $total <= 0) {
                throw new \DomainException('rien');
            }
            $categorie = Database::un("SELECT id FROM categories_depenses WHERE nom_fr = 'Commissions' ORDER BY id LIMIT 1");
            if ($categorie === null) {
                Database::executer("INSERT INTO categories_depenses (nom_fr, nom_en, position) VALUES ('Commissions', 'Commissions', 99)");
                $categorie = ['id' => Database::dernierId()];
            }
            $libelle = mb_substr(t('nuit.commission_libelle', ['nom' => $p['nom'], 'du' => date('d/m/Y', strtotime($du)), 'au' => date('d/m/Y', strtotime($au))]), 0, 255);
            $depenseId = (new GestionDepenseRepository($tresorerie))->creer([
                'date_depense' => date('Y-m-d H:i:s'), 'categorie_id' => (int) $categorie['id'], 'etablissement_id' => $etabId,
                'libelle' => $libelle, 'beneficiaire' => mb_substr($p['nom'], 0, 128), 'notes' => null,
            ], [['compte' => $compte, 'devise' => ['id' => $deviseId], 'montant' => $total]], $par);
            Database::executer('INSERT INTO commission_paiements (promoteur_id, du, au, devise_id, montant, depense_id, cle, cree_par, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$promoteurId, $du, $au, $deviseId, number_format($total, 3, '.', ''), $depenseId, $cle, $par]);
            $paiementId = Database::dernierId();
            Database::executer('UPDATE commissions SET statut = \'payee\', paiement_id = ? WHERE id IN (' . implode(',', array_map(static fn (array $c) => (int) $c['id'], $dues)) . ')', [$paiementId]);
            Journal::ecrire('commissions_payees', $p['nom'], ['montant' => $total, 'devise' => $deviseId, 'du' => $du, 'au' => $au, 'depense' => $depenseId], $par);
            return $paiementId;
        });
    }

    public function paiements(int $promoteurId): array
    {
        return Database::tous(
            'SELECT cp.*, d.code AS devise_code, u.nom AS par_nom FROM commission_paiements cp JOIN devises d ON d.id = cp.devise_id
          LEFT JOIN utilisateurs u ON u.id = cp.cree_par WHERE cp.promoteur_id = ? ORDER BY cp.id DESC LIMIT 50',
            [$promoteurId]
        );
    }
}
