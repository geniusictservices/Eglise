<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\CodeQr;
use App\Core\Database;
use App\Core\Journal;

/**
 * Cashless (décidé : carte ou bracelet à code QR scanné par la caméra de la tablette, cahier des charges section 17).
 * Une carte a un solde dans chaque devise. L'argent entre dans la trésorerie à la RECHARGE (compte et devise choisis
 * comme à l'encaissement) ; payer avec la carte ne fait aucun mouvement de trésorerie, seulement une baisse du solde ;
 * le remboursement du solde restant est une sortie de trésorerie. Le serveur vérifie tout : carte active, code
 * complet (numéro + jeton), solde suffisant (jamais négatif, sous verrou), double envoi sans effet (clé unique).
 * Passif : total des soldes encore dus aux porteurs, par devise.
 */
final class CashlessRepository
{
    private GestionTresorerieRepository $tresorerie;

    public function __construct(?GestionTresorerieRepository $tresorerie = null)
    {
        $this->tresorerie = $tresorerie ?? new GestionTresorerieRepository();
    }

    public static function disponible(): bool
    {
        return RapportRepository::tableExiste('cartes_cashless');
    }

    public static function code(array $carte): string
    {
        return CodeQr::code('C', (int) $carte['numero'], $carte['jeton']);
    }

    /**
     * Crée des cartes (ou bracelets) aux numéros suivants.
     * @return list<array> cartes créées (avec « code »)
     */
    public function creer(int $nombre, string $support, ?string $porteur, int $par): array
    {
        return $this->tresorerie->transaction(function () use ($nombre, $support, $porteur, $par): array {
            $suivant = (int) Database::un('SELECT COALESCE(MAX(numero), 0) + 1 AS n FROM cartes_cashless FOR UPDATE')['n'];
            $ids = [];
            for ($k = 0; $k < max(1, min(200, $nombre)); $k++) {
                Database::executer('INSERT INTO cartes_cashless (numero, jeton, support, porteur, cree_par) VALUES (?, ?, ?, ?, ?)',
                    [$suivant + $k, CodeQr::jeton(), $support === 'bracelet' ? 'bracelet' : 'carte', $porteur, $par]);
                $ids[] = Database::dernierId();
            }
            Journal::ecrire('cartes_creees', (string) count($ids), ['de' => $suivant, 'support' => $support], $par);
            return $this->parIds($ids);
        });
    }

    public function parIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return array_map(fn (array $c) => $c + ['code' => self::code($c), 'soldes' => $this->soldes((int) $c['id'])],
            Database::tous('SELECT * FROM cartes_cashless WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY numero'));
    }

    public function carte(int $id): ?array
    {
        $c = Database::un('SELECT c.*, cl.nom AS client_nom, u.nom AS bloquee_par_nom FROM cartes_cashless c
            LEFT JOIN clients cl ON cl.id = c.client_id LEFT JOIN utilisateurs u ON u.id = c.bloquee_par WHERE c.id = ?', [$id]);
        return $c !== null ? $c + ['code' => self::code($c), 'soldes' => $this->soldes($id)] : null;
    }

    /** Carte d'un code scanné ou tapé (numéro + jeton ; un numéro seul ne suffit jamais). */
    public function parCode(mixed $saisie): ?array
    {
        $code = CodeQr::lire($saisie, 'C');
        if ($code === null) {
            return null;
        }
        $c = Database::un('SELECT id, jeton FROM cartes_cashless WHERE numero = ?', [$code['numero']]);
        return $c !== null && hash_equals($c['jeton'], $code['jeton']) ? $this->carte((int) $c['id']) : null;
    }

    /** @return array<int, float> devise_id → solde */
    public function soldes(int $carteId): array
    {
        return array_map('floatval', array_column(Database::tous('SELECT devise_id, solde FROM carte_soldes WHERE carte_id = ?', [$carteId]), 'solde', 'devise_id'));
    }

    /**
     * Recharge : le solde de la carte augmente dans sa devise ; les paiements entrent dans leurs comptes (nature
     * « cashless »), la monnaie sort d'une caisse. Un double envoi (même clé) renvoie la recharge déjà faite.
     * @param array{carte_id: int, devise: array, montant: float, paiements: list<array{compte: array, devise: array, recu: float, taux: ?float}>,
     *              rendu: ?array{montant: float, devise: array, compte: array}, etablissement_id: int, utilisateur_id: int,
     *              appareil_id: ?int, session_id: ?int, cle: string, date?: string} $r
     * @return int mouvement de la carte
     * @throws \DomainException « bloquee »
     */
    public function recharger(array $r): int
    {
        return $this->tresorerie->transaction(function () use ($r): int {
            $deja = Database::un('SELECT id FROM carte_mouvements WHERE cle = ?', [$r['cle']]);
            if ($deja !== null) {
                return (int) $deja['id'];
            }
            $carte = Database::un('SELECT * FROM cartes_cashless WHERE id = ? FOR UPDATE', [$r['carte_id']]);
            if ($carte === null || $carte['statut'] !== 'active') {
                throw new \DomainException('bloquee');
            }
            $date = $r['date'] ?? date('Y-m-d H:i:s');
            $deviseId = (int) $r['devise']['id'];
            $solde = $this->ajusterSolde((int) $carte['id'], $deviseId, $r['montant']);
            Database::executer(
                'INSERT INTO carte_mouvements (carte_id, devise_id, type, montant, solde_apres, etablissement_id, utilisateur_id, appareil_id, session_id, rendu, rendu_devise_id, cle, cree_le)
                 VALUES (?, ?, \'recharge\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $carte['id'], $deviseId, number_format($r['montant'], 3, '.', ''), number_format($solde, 3, '.', ''), $r['etablissement_id'], $r['utilisateur_id'],
                    $r['appareil_id'], $r['session_id'], number_format((float) ($r['rendu']['montant'] ?? 0), 3, '.', ''),
                    isset($r['rendu']) ? (int) $r['rendu']['devise']['id'] : null, $r['cle'], $date]
            );
            $id = Database::dernierId();
            $libelle = 'Recharge carte ' . self::code($carte);
            foreach ($r['paiements'] as $p) {
                Database::executer('INSERT INTO carte_paiements (mouvement_id, compte_id, devise_id, recu, taux) VALUES (?, ?, ?, ?, ?)',
                    [$id, (int) $p['compte']['id'], (int) $p['devise']['id'], number_format($p['recu'], 3, '.', ''), $p['taux'] !== null ? GestionDeviseRepository::decimal($p['taux']) : null]);
                $this->tresorerie->ajouterMouvement($p['compte'], 'entree', $p['recu'], 'cashless', $id, $date, $libelle, $r['utilisateur_id'], (int) $p['devise']['id']);
            }
            if (!empty($r['rendu']) && $r['rendu']['montant'] > 0) {
                $this->tresorerie->ajouterMouvement($r['rendu']['compte'], 'sortie', $r['rendu']['montant'], 'cashless', $id, $date,
                    'Monnaie rendue, recharge ' . self::code($carte), $r['utilisateur_id'], (int) $r['rendu']['devise']['id']);
            }
            Journal::ecrire('carte_rechargee', self::code($carte), ['montant' => $r['montant'], 'devise' => $r['devise']['code'] ?? $deviseId], $r['utilisateur_id'], $r['appareil_id']);
            return $id;
        });
    }

    /**
     * Paiement d'une vente avec la carte (dans la transaction de la vente) : le solde baisse, sans mouvement de
     * trésorerie. Jamais de solde négatif : la ligne du solde est verrouillée avant la vérification.
     * @throws \DomainException « bloquee » ou « solde »
     */
    public function debiter(int $carteId, int $deviseId, float $montant, int $venteId, int $etabId, int $par, ?int $appareilId, string $date): void
    {
        $carte = Database::un('SELECT * FROM cartes_cashless WHERE id = ? FOR UPDATE', [$carteId]);
        if ($carte === null || $carte['statut'] !== 'active') {
            throw new \DomainException('bloquee');
        }
        $ligne = Database::un('SELECT solde FROM carte_soldes WHERE carte_id = ? AND devise_id = ? FOR UPDATE', [$carteId, $deviseId]);
        $solde = $ligne !== null ? (float) $ligne['solde'] : 0.0;
        if ($montant <= 0 || $montant > $solde + 0.0005) {
            throw new \DomainException('solde');
        }
        $apres = round($solde - $montant, 3);
        Database::executer('UPDATE carte_soldes SET solde = ? WHERE carte_id = ? AND devise_id = ?', [number_format(max(0.0, $apres), 3, '.', ''), $carteId, $deviseId]);
        Database::executer(
            'INSERT INTO carte_mouvements (carte_id, devise_id, type, montant, solde_apres, vente_id, etablissement_id, utilisateur_id, appareil_id, cree_le)
             VALUES (?, ?, \'paiement\', ?, ?, ?, ?, ?, ?, ?)',
            [$carteId, $deviseId, number_format(-$montant, 3, '.', ''), number_format(max(0.0, $apres), 3, '.', ''), $venteId, $etabId, $par, $appareilId, $date]
        );
    }

    /**
     * Remboursement du solde restant (carte active ou bloquée) : sortie du compte choisi, dans la devise de la carte.
     * @throws \DomainException « solde » (plus que le solde) ou « devise » (le compte ne tient pas la devise)
     */
    public function rembourser(int $carteId, int $deviseId, float $montant, array $compte, int $etabId, int $par, ?int $appareilId, ?int $sessionId, string $cle): int
    {
        return $this->tresorerie->transaction(function () use ($carteId, $deviseId, $montant, $compte, $etabId, $par, $appareilId, $sessionId, $cle): int {
            $deja = Database::un('SELECT id FROM carte_mouvements WHERE cle = ?', [$cle]);
            if ($deja !== null) {
                return (int) $deja['id'];
            }
            $carte = Database::un('SELECT * FROM cartes_cashless WHERE id = ? FOR UPDATE', [$carteId]);
            $ligne = Database::un('SELECT solde FROM carte_soldes WHERE carte_id = ? AND devise_id = ? FOR UPDATE', [$carteId, $deviseId]);
            $solde = $ligne !== null ? (float) $ligne['solde'] : 0.0;
            if ($carte === null || $montant <= 0 || $montant > $solde + 0.0005) {
                throw new \DomainException('solde');
            }
            if (!GestionTresorerieRepository::tient($compte, $deviseId)) {
                throw new \DomainException('devise');
            }
            $apres = round($solde - $montant, 3);
            Database::executer('UPDATE carte_soldes SET solde = ? WHERE carte_id = ? AND devise_id = ?', [number_format(max(0.0, $apres), 3, '.', ''), $carteId, $deviseId]);
            $date = date('Y-m-d H:i:s');
            Database::executer(
                'INSERT INTO carte_mouvements (carte_id, devise_id, type, montant, solde_apres, etablissement_id, utilisateur_id, appareil_id, session_id, cle, cree_le)
                 VALUES (?, ?, \'remboursement\', ?, ?, ?, ?, ?, ?, ?, ?)',
                [$carteId, $deviseId, number_format(-$montant, 3, '.', ''), number_format(max(0.0, $apres), 3, '.', ''), $etabId, $par, $appareilId, $sessionId, $cle, $date]
            );
            $id = Database::dernierId();
            Database::executer('INSERT INTO carte_paiements (mouvement_id, compte_id, devise_id, recu, taux) VALUES (?, ?, ?, ?, NULL)',
                [$id, (int) $compte['id'], $deviseId, number_format($montant, 3, '.', '')]);
            $this->tresorerie->ajouterMouvement($compte, 'sortie', $montant, 'cashless', $id, $date, 'Remboursement carte ' . self::code($carte), $par, $deviseId);
            Journal::ecrire('carte_remboursee', self::code($carte), ['montant' => $montant, 'devise' => $deviseId], $par, $appareilId);
            return $id;
        });
    }

    /** Annulation d'une vente payée avec des cartes : leur part revient sur leur solde. */
    public function annulerVente(int $venteId, int $par): void
    {
        if (!self::disponible()) {
            return;
        }
        foreach (Database::tous('SELECT * FROM vente_cashless WHERE vente_id = ?', [$venteId]) as $p) {
            if (Database::un("SELECT id FROM carte_mouvements WHERE type = 'annulation' AND vente_id = ? AND carte_id = ? AND devise_id = ? LIMIT 1",
                [$venteId, (int) $p['carte_id'], (int) $p['devise_id']]) !== null) {
                continue;
            }
            $solde = $this->ajusterSolde((int) $p['carte_id'], (int) $p['devise_id'], (float) $p['montant']);
            Database::executer(
                'INSERT INTO carte_mouvements (carte_id, devise_id, type, montant, solde_apres, vente_id, utilisateur_id, cree_le) VALUES (?, ?, \'annulation\', ?, ?, ?, ?, NOW())',
                [(int) $p['carte_id'], (int) $p['devise_id'], number_format((float) $p['montant'], 3, '.', ''), number_format($solde, 3, '.', ''), $venteId, $par]
            );
        }
    }

    private function ajusterSolde(int $carteId, int $deviseId, float $delta): float
    {
        Database::executer('INSERT INTO carte_soldes (carte_id, devise_id, solde) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE solde = solde + VALUES(solde)',
            [$carteId, $deviseId, number_format($delta, 3, '.', '')]);
        return (float) Database::un('SELECT solde FROM carte_soldes WHERE carte_id = ? AND devise_id = ?', [$carteId, $deviseId])['solde'];
    }

    public function bloquer(int $carteId, bool $bloquer, ?string $motif, int $par): void
    {
        if ($bloquer) {
            Database::executer("UPDATE cartes_cashless SET statut = 'bloquee', bloquee_le = NOW(), bloquee_par = ?, motif_blocage = ? WHERE id = ?", [$par, $motif, $carteId]);
        } else {
            Database::executer("UPDATE cartes_cashless SET statut = 'active', bloquee_le = NULL, bloquee_par = NULL, motif_blocage = NULL WHERE id = ?", [$carteId]);
        }
        Journal::ecrire($bloquer ? 'carte_bloquee' : 'carte_debloquee', (string) $carteId, ['motif' => $motif], $par);
    }

    public function definirPorteur(int $carteId, ?string $porteur, ?int $clientId): void
    {
        Database::executer('UPDATE cartes_cashless SET porteur = ?, client_id = ? WHERE id = ?', [$porteur, $clientId, $carteId]);
    }

    /** Historique d'une carte, le plus récent d'abord. */
    public function historique(int $carteId, int $limite = 100): array
    {
        return Database::tous(
            'SELECT m.*, d.code AS devise_code, v.numero AS vente_numero, u.nom AS par_nom, e.nom AS etablissement_nom,
                    (SELECT GROUP_CONCAT(c.nom SEPARATOR \', \') FROM carte_paiements p JOIN comptes_tresorerie c ON c.id = p.compte_id WHERE p.mouvement_id = m.id) AS comptes
               FROM carte_mouvements m JOIN devises d ON d.id = m.devise_id LEFT JOIN ventes v ON v.id = m.vente_id
          LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id LEFT JOIN etablissements e ON e.id = m.etablissement_id
              WHERE m.carte_id = ? ORDER BY m.id DESC LIMIT ' . (int) $limite,
            [$carteId]
        );
    }

    /** Cartes (recherche par numéro ou porteur), avec leurs soldes. */
    public function liste(string $recherche = '', ?string $statut = null): array
    {
        $where = '1 = 1';
        $params = [];
        if ($recherche !== '') {
            $where .= ' AND (c.numero = ? OR c.porteur LIKE ? OR cl.nom LIKE ?)';
            $params[] = (int) preg_replace('/\D/', '', $recherche);
            $params[] = '%' . $recherche . '%';
            $params[] = '%' . $recherche . '%';
        }
        if ($statut !== null) {
            $where .= ' AND c.statut = ?';
            $params[] = $statut;
        }
        $cartes = Database::tous('SELECT c.*, cl.nom AS client_nom FROM cartes_cashless c LEFT JOIN clients cl ON cl.id = c.client_id WHERE ' . $where . ' ORDER BY c.numero DESC LIMIT 300', $params);
        $soldes = [];
        if ($cartes !== []) {
            foreach (Database::tous('SELECT carte_id, devise_id, solde FROM carte_soldes WHERE carte_id IN (' . implode(',', array_map(static fn (array $c) => (int) $c['id'], $cartes)) . ')') as $s) {
                $soldes[(int) $s['carte_id']][(int) $s['devise_id']] = (float) $s['solde'];
            }
        }
        return array_map(static fn (array $c) => $c + ['code' => self::code($c), 'soldes' => $soldes[(int) $c['id']] ?? []], $cartes);
    }

    /**
     * Passif : soldes encore dus aux porteurs, par devise (cartes actives et bloquées), et ce qui a bougé sur une
     * période (recharges, paiements, remboursements).
     * @return array{soldes: array<int, array{total: float, cartes: int, bloquees: float}>, periode: array<int, array<string, float>>}
     */
    public function passif(string $debut, string $fin): array
    {
        $soldes = [];
        foreach (Database::tous("SELECT s.devise_id, SUM(s.solde) AS total, SUM(s.solde > 0) AS cartes, SUM(IF(c.statut = 'bloquee', s.solde, 0)) AS bloquees
                                   FROM carte_soldes s JOIN cartes_cashless c ON c.id = s.carte_id GROUP BY s.devise_id") as $l) {
            $soldes[(int) $l['devise_id']] = ['total' => (float) $l['total'], 'cartes' => (int) $l['cartes'], 'bloquees' => (float) $l['bloquees']];
        }
        $periode = [];
        foreach (Database::tous('SELECT devise_id, type, SUM(montant) AS total, COUNT(*) AS n FROM carte_mouvements WHERE cree_le >= ? AND cree_le < ? GROUP BY devise_id, type', [$debut, $fin]) as $l) {
            $periode[(int) $l['devise_id']][$l['type']] = (float) $l['total'];
            $periode[(int) $l['devise_id']][$l['type'] . '_n'] = (float) $l['n'];
        }
        return ['soldes' => $soldes, 'periode' => $periode];
    }

    /** Paiements par carte d'une vente (reçu). */
    public function deVente(int $venteId): array
    {
        if (!self::disponible()) {
            return [];
        }
        return array_map(static fn (array $p) => $p + ['code' => CodeQr::code('C', (int) $p['numero'], $p['jeton'])], Database::tous(
            'SELECT vc.*, c.numero, c.jeton, (SELECT s.solde FROM carte_soldes s WHERE s.carte_id = vc.carte_id AND s.devise_id = vc.devise_id) AS solde
               FROM vente_cashless vc JOIN cartes_cashless c ON c.id = vc.carte_id WHERE vc.vente_id = ? ORDER BY vc.id',
            [$venteId]
        ));
    }

    /** Recharge (reçu) : le mouvement, ses paiements et la carte. */
    public function recharge(int $mouvementId): ?array
    {
        $m = Database::un('SELECT m.*, d.code AS devise_code, u.nom AS par_nom FROM carte_mouvements m JOIN devises d ON d.id = m.devise_id
            LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id WHERE m.id = ?', [$mouvementId]);
        if ($m === null) {
            return null;
        }
        $m['paiements'] = Database::tous('SELECT p.*, c.nom AS compte, c.type AS moyen FROM carte_paiements p JOIN comptes_tresorerie c ON c.id = p.compte_id WHERE p.mouvement_id = ? ORDER BY p.id', [$mouvementId]);
        $m['carte'] = $this->carte((int) $m['carte_id']);
        return $m;
    }
}
