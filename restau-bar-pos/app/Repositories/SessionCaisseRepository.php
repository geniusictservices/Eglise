<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Sessions de caisse (au choix de chaque établissement, etablissements.sessions_caisse).
 *
 * Ouverture : la personne compte son fond pour chaque caisse (compte de type « caisse » de l'établissement
 * ou commun) et chaque devise. Pendant la session, ses ventes et les remboursements qu'elle encaisse portent
 * le numéro de la session. Clôture : elle compte ce qu'elle a ; attendu = fond + entrées − sorties de la
 * session dans cette caisse et cette devise ; l'écart (compté − attendu) est écrit dans la trésorerie
 * (« ajustement ») pour que le solde suive la réalité. Un manquant peut être retenu sur la paie.
 */
final class SessionCaisseRepository
{
    private GestionTresorerieRepository $tresorerie;

    public function __construct(?GestionTresorerieRepository $tresorerie = null)
    {
        $this->tresorerie = $tresorerie ?? new GestionTresorerieRepository();
    }

    public static function obligatoires(array $etab): bool
    {
        return !empty($etab['sessions_caisse']);
    }

    /** Session ouverte de cette personne dans cet établissement. */
    public function ouverte(int $etablissementId, int $utilisateurId): ?array
    {
        return Database::un(
            "SELECT * FROM sessions_caisse WHERE etablissement_id = ? AND utilisateur_id = ? AND statut = 'ouverte' ORDER BY id DESC LIMIT 1",
            [$etablissementId, $utilisateurId]
        );
    }

    public function session(int $id): ?array
    {
        return Database::un(
            'SELECT s.*, u.nom AS utilisateur_nom, f.nom AS fermee_par_nom, e.nom AS etablissement_nom
               FROM sessions_caisse s JOIN utilisateurs u ON u.id = s.utilisateur_id JOIN etablissements e ON e.id = s.etablissement_id
               LEFT JOIN utilisateurs f ON f.id = s.fermee_par WHERE s.id = ?',
            [$id]
        );
    }

    /**
     * Caisses à compter : comptes actifs de type « caisse » de l'établissement (ou communs), avec chaque
     * devise qu'ils tiennent parmi celles acceptées. Devise locale d'abord, le dollar ensuite.
     * @param array<int, array> $acceptees devises acceptées (indexées par id)
     * @return list<array{compte_id: int, nom: string, devise_id: int, code: string}>
     */
    public function caissesACompter(int $etablissementId, array $acceptees): array
    {
        $lignes = Database::tous(
            "SELECT c.id, c.nom, cd.devise_id FROM comptes_tresorerie c JOIN compte_devises cd ON cd.compte_id = c.id
              WHERE c.active = 1 AND c.type = 'caisse' AND (c.etablissement_id IS NULL OR c.etablissement_id = ?)
              ORDER BY c.etablissement_id IS NULL, c.nom, c.id",
            [$etablissementId]
        );
        $ordre = array_flip(array_keys($acceptees));
        $resultat = [];
        foreach ($lignes as $l) {
            if (isset($acceptees[(int) $l['devise_id']])) {
                $resultat[] = ['compte_id' => (int) $l['id'], 'nom' => $l['nom'], 'devise_id' => (int) $l['devise_id'], 'code' => $acceptees[(int) $l['devise_id']]['code']];
            }
        }
        usort($resultat, static fn (array $a, array $b) => [$a['compte_id'], $ordre[$a['devise_id']]] <=> [$b['compte_id'], $ordre[$b['devise_id']]]);
        return $resultat;
    }

    /**
     * Ouvrir une session avec le fond compté (compte_id → devise_id → montant).
     * @param array<int, array<int, float>> $fonds
     * @return int la session (l'existante si la personne en a déjà une ouverte)
     */
    public function ouvrir(int $etablissementId, int $utilisateurId, ?int $appareilId, array $caisses, array $fonds): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::un('SELECT id FROM utilisateurs WHERE id = ? FOR UPDATE', [$utilisateurId]);
            if (($deja = $this->ouverte($etablissementId, $utilisateurId)) !== null) {
                $pdo->commit();
                return (int) $deja['id'];
            }
            Database::executer(
                "INSERT INTO sessions_caisse (etablissement_id, utilisateur_id, appareil_id, statut, ouverte_le) VALUES (?, ?, ?, 'ouverte', ?)",
                [$etablissementId, $utilisateurId, $appareilId, date('Y-m-d H:i:s')]
            );
            $id = Database::dernierId();
            foreach ($caisses as $c) {
                Database::executer('INSERT INTO session_caisse_comptes (session_id, compte_id, devise_id, fond) VALUES (?, ?, ?, ?)', [
                    $id, $c['compte_id'], $c['devise_id'], GestionTresorerieRepository::decimal(max(0.0, (float) ($fonds[$c['compte_id']][$c['devise_id']] ?? 0))),
                ]);
            }
            Journal::ecrire('session_ouverte', 'session ' . $id, ['fonds' => $fonds], $utilisateurId, $appareilId);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Attendu dans chaque caisse et devise de la session : fond + mouvements des ventes et des remboursements
     * de la session (paiements entrés, monnaie rendue, annulations).
     * @return list<array{compte_id: int, devise_id: int, nom: string, fond: float, mouvements: float, attendu: float, compte: ?float, ecart: ?float}>
     */
    public function lignes(int $sessionId): array
    {
        $lignes = Database::tous(
            'SELECT scc.*, c.nom FROM session_caisse_comptes scc JOIN comptes_tresorerie c ON c.id = scc.compte_id JOIN devises d ON d.id = scc.devise_id
              WHERE scc.session_id = ? ORDER BY c.nom, scc.compte_id, d.est_locale DESC, d.code = \'USD\' DESC, d.position, d.code',
            [$sessionId]
        );
        $resultat = [];
        foreach ($lignes as $l) {
            $mouvements = (float) Database::un(
                "SELECT COALESCE(SUM(IF(m.sens = 'entree', m.montant, -m.montant)), 0) AS total FROM mouvements_tresorerie m
                  WHERE m.compte_id = ? AND m.devise_id = ? AND (
                        (m.nature = 'vente' AND m.source_id IN (SELECT id FROM ventes WHERE session_id = ?))
                     OR (m.nature = 'remboursement' AND m.source_id IN (SELECT id FROM remboursements WHERE session_id = ?)))",
                [(int) $l['compte_id'], (int) $l['devise_id'], $sessionId, $sessionId]
            )['total'];
            $resultat[] = [
                'compte_id' => (int) $l['compte_id'], 'devise_id' => (int) $l['devise_id'], 'nom' => $l['nom'],
                'fond' => (float) $l['fond'], 'mouvements' => round($mouvements, 3),
                'attendu' => $l['attendu'] !== null ? (float) $l['attendu'] : round((float) $l['fond'] + $mouvements, 3),
                'compte' => $l['compte'] !== null ? (float) $l['compte'] : null, 'ecart' => $l['ecart'] !== null ? (float) $l['ecart'] : null,
            ];
        }
        return $resultat;
    }

    /**
     * Clôturer : compté (compte_id → devise_id → montant) ; l'écart de chaque caisse et devise est écrit
     * dans la trésorerie. Par la personne elle-même ou, pour une session oubliée, par le gérant.
     * @param array<int, array<int, float>> $comptes
     * @throws \DomainException « deja_fermee »
     */
    public function cloturer(int $sessionId, array $comptes, int $par, ?string $note): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $session = Database::un('SELECT * FROM sessions_caisse WHERE id = ? FOR UPDATE', [$sessionId]);
            if ($session === null || $session['statut'] !== 'ouverte') {
                throw new \DomainException('deja_fermee');
            }
            $maintenant = date('Y-m-d H:i:s');
            foreach ($this->lignes($sessionId) as $l) {
                $compte = round(max(0.0, (float) ($comptes[$l['compte_id']][$l['devise_id']] ?? 0)), 3);
                $ecart = round($compte - $l['attendu'], 3);
                Database::executer('UPDATE session_caisse_comptes SET attendu = ?, compte = ?, ecart = ? WHERE session_id = ? AND compte_id = ? AND devise_id = ?', [
                    GestionTresorerieRepository::decimal($l['attendu']), GestionTresorerieRepository::decimal($compte), GestionTresorerieRepository::decimal($ecart),
                    $sessionId, $l['compte_id'], $l['devise_id'],
                ]);
                if (abs($ecart) >= 0.001) {
                    $this->tresorerie->ajouterMouvement(['id' => $l['compte_id']], $ecart > 0 ? 'entree' : 'sortie', abs($ecart), 'ajustement', $sessionId, $maintenant,
                        ($ecart > 0 ? 'Excédent' : 'Manquant') . ' de caisse, session ' . $sessionId, $par, $l['devise_id']);
                }
            }
            $note = $note !== null ? trim($note) : '';
            Database::executer("UPDATE sessions_caisse SET statut = 'fermee', fermee_le = ?, fermee_par = ?, note = ? WHERE id = ?",
                [$maintenant, $par, $note !== '' ? mb_substr($note, 0, 255) : null, $sessionId]);
            Journal::ecrire('session_fermee', 'session ' . $sessionId, ['par' => $par], $par);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Résumé de la session pour le rapport de clôture : tickets, total vendu (devise du ticket), encaissé par
     * compte et devise, part à l'ardoise, remboursements d'ardoises, ventes annulées.
     */
    public function resume(int $sessionId): array
    {
        $ventes = Database::un(
            "SELECT COUNT(*) AS tickets, COALESCE(SUM(IF(statut = 'payee', 1, 0)), 0) AS payees, COALESCE(SUM(IF(statut = 'annulee', 1, 0)), 0) AS annulees
               FROM ventes WHERE session_id = ?",
            [$sessionId]
        );
        return [
            'tickets' => (int) $ventes['payees'], 'annulees' => (int) $ventes['annulees'],
            'totaux' => Database::tous(
                "SELECT v.devise_id, SUM(v.total) AS total FROM ventes v WHERE v.session_id = ? AND v.statut = 'payee' GROUP BY v.devise_id",
                [$sessionId]
            ),
            'encaisse' => Database::tous(
                "SELECT p.compte_id, c.nom AS compte, p.moyen, p.devise_id, SUM(p.recu) AS montant FROM vente_paiements p
                   JOIN ventes v ON v.id = p.vente_id JOIN comptes_tresorerie c ON c.id = p.compte_id
                  WHERE v.session_id = ? AND v.statut = 'payee' GROUP BY p.compte_id, c.nom, p.moyen, p.devise_id ORDER BY FIELD(p.moyen, 'caisse', 'mobile_money', 'banque'), c.nom",
                [$sessionId]
            ),
            'ardoises' => Database::tous(
                "SELECT d.devise_id, COUNT(*) AS nombre, SUM(d.montant) AS montant FROM dettes d JOIN ventes v ON v.id = d.vente_id
                  WHERE v.session_id = ? AND d.statut <> 'annulee' GROUP BY d.devise_id",
                [$sessionId]
            ),
            'remboursements' => Database::tous(
                "SELECT devise_id, COUNT(*) AS nombre, SUM(montant) AS montant FROM remboursements WHERE session_id = ? AND statut = 'valide' GROUP BY devise_id",
                [$sessionId]
            ),
        ];
    }

    /**
     * Sessions pour la gestion (filtres : établissements permis, personne, statut, période).
     * @param ?list<int> $etablissements null = tous
     * @return list<array>
     */
    public function liste(?array $etablissements, array $filtres): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($etablissements !== null) {
            if ($etablissements === []) {
                return [];
            }
            $where[] = 's.etablissement_id IN (' . implode(',', array_fill(0, count($etablissements), '?')) . ')';
            array_push($params, ...$etablissements);
        }
        foreach (['etablissement' => 's.etablissement_id', 'personne' => 's.utilisateur_id'] as $cle => $colonne) {
            if (!empty($filtres[$cle])) {
                $where[] = "$colonne = ?";
                $params[] = (int) $filtres[$cle];
            }
        }
        if (in_array($filtres['statut'] ?? '', ['ouverte', 'fermee'], true)) {
            $where[] = 's.statut = ?';
            $params[] = $filtres['statut'];
        }
        if (!empty($filtres['debut'])) {
            $where[] = 's.ouverte_le >= ?';
            $params[] = $filtres['debut'] . ' 00:00:00';
        }
        if (!empty($filtres['fin'])) {
            $where[] = 's.ouverte_le <= ?';
            $params[] = $filtres['fin'] . ' 23:59:59';
        }
        return Database::tous(
            'SELECT s.*, u.nom AS utilisateur_nom, e.nom AS etablissement_nom,
                    (SELECT COUNT(*) FROM ventes v WHERE v.session_id = s.id AND v.statut = \'payee\') AS tickets
               FROM sessions_caisse s JOIN utilisateurs u ON u.id = s.utilisateur_id JOIN etablissements e ON e.id = s.etablissement_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY s.statut = \'ouverte\' DESC, s.ouverte_le DESC LIMIT 300',
            $params
        );
    }

    /** @return list<array> écarts par devise d'une liste de sessions (session_id, devise_id, ecart) */
    public function ecarts(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        return Database::tous(
            'SELECT session_id, devise_id, SUM(ecart) AS ecart FROM session_caisse_comptes WHERE session_id IN (' . implode(',', array_map('intval', $sessionIds)) . ')
              AND ecart IS NOT NULL GROUP BY session_id, devise_id'
        );
    }
}
