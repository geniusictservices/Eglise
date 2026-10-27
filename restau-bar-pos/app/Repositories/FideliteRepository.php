<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Fidélité (lounge, au choix de chaque établissement) : un client identifié à l'encaissement gagne des points selon
 * ce qu'il dépense (1 point par tranche de « montant_point » dans une devise, le dollar par défaut) ; il les échange
 * contre une récompense : une remise en % sur le ticket, ou un article offert. Solde sur la fiche du client.
 */
final class FideliteRepository
{
    public static function disponible(): bool
    {
        return RapportRepository::tableExiste('fidelite_reglages');
    }

    /** Réglages de l'établissement (null : fidélité non activée). */
    public function reglages(int $etabId, bool $memeInactive = false): ?array
    {
        if (!self::disponible()) {
            return null;
        }
        $r = Database::un('SELECT r.*, d.code AS devise_code FROM fidelite_reglages r JOIN devises d ON d.id = r.devise_id WHERE r.etablissement_id = ?', [$etabId]);
        if ($r === null || (!$memeInactive && !(int) $r['actif'])) {
            return null;
        }
        return $r;
    }

    public function definirReglages(int $etabId, bool $actif, float $montantPoint, int $deviseId, int $par): void
    {
        Database::executer(
            'INSERT INTO fidelite_reglages (etablissement_id, actif, montant_point, devise_id) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE actif = VALUES(actif), montant_point = VALUES(montant_point), devise_id = VALUES(devise_id)',
            [$etabId, $actif ? 1 : 0, number_format(max(0.001, $montantPoint), 3, '.', ''), $deviseId]
        );
        Journal::ecrire('fidelite_reglages', (string) $etabId, ['actif' => $actif, 'montant_point' => $montantPoint, 'devise' => $deviseId], $par);
    }

    public function recompenses(int $etabId, bool $actives = true): array
    {
        if (!self::disponible()) {
            return [];
        }
        return Database::tous(
            'SELECT r.*, c.nom_fr AS format_fr, c.nom_en AS format_en, p.nom_fr AS produit_fr, p.nom_en AS produit_en
               FROM fidelite_recompenses r LEFT JOIN conditionnements c ON c.id = r.conditionnement_id LEFT JOIN produits p ON p.id = c.produit_id
              WHERE r.etablissement_id = ?' . ($actives ? ' AND r.actif = 1' : '') . ' ORDER BY r.points, r.id',
            [$etabId]
        );
    }

    public function recompense(int $id): ?array
    {
        return Database::un('SELECT * FROM fidelite_recompenses WHERE id = ?', [$id]);
    }

    /** @param array{nom_fr: string, nom_en: string, points: int, type: string, pourcent: ?float, conditionnement_id: ?int, actif: bool} $v */
    public function enregistrerRecompense(?int $id, int $etabId, array $v, int $par): int
    {
        $valeurs = [$v['nom_fr'], $v['nom_en'] !== '' ? $v['nom_en'] : $v['nom_fr'], $v['points'], $v['type'],
            $v['type'] === 'remise' ? number_format((float) $v['pourcent'], 2, '.', '') : null, $v['type'] === 'offert' ? $v['conditionnement_id'] : null, $v['actif'] ? 1 : 0];
        if ($id === null) {
            Database::executer('INSERT INTO fidelite_recompenses (etablissement_id, nom_fr, nom_en, points, type, pourcent, conditionnement_id, actif) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge([$etabId], $valeurs));
            $id = Database::dernierId();
        } else {
            Database::executer('UPDATE fidelite_recompenses SET nom_fr = ?, nom_en = ?, points = ?, type = ?, pourcent = ?, conditionnement_id = ?, actif = ? WHERE id = ? AND etablissement_id = ?',
                array_merge($valeurs, [$id, $etabId]));
        }
        Journal::ecrire('fidelite_recompense', $v['nom_fr'], ['points' => $v['points'], 'type' => $v['type']], $par);
        return $id;
    }

    /** Points d'un client (toutes les opérations, tous établissements). */
    public function solde(int $clientId, bool $verrouiller = false): int
    {
        if (!self::disponible()) {
            return 0;
        }
        if ($verrouiller) {
            Database::un('SELECT id FROM clients WHERE id = ? FOR UPDATE', [$clientId]);
        }
        return (int) (Database::un('SELECT COALESCE(SUM(points), 0) AS p FROM fidelite_mouvements WHERE client_id = ?', [$clientId])['p'] ?? 0);
    }

    /** Points gagnés pour un montant dépensé dans la devise des réglages. */
    public static function pointsPour(array $reglages, float $montant): int
    {
        $pas = (float) $reglages['montant_point'];
        return $pas > 0 ? (int) floor(round($montant / $pas, 6)) : 0;
    }

    /** Écrit un mouvement de points (dans la transaction de la vente). */
    public function mouvement(int $clientId, int $etabId, ?int $venteId, string $type, int $points, ?int $recompenseId, ?int $par, ?string $date = null): void
    {
        if ($points === 0) {
            return;
        }
        Database::executer('INSERT INTO fidelite_mouvements (client_id, etablissement_id, vente_id, type, points, recompense_id, utilisateur_id, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $etabId, $venteId, $type, $points, $recompenseId, $par, $date ?? date('Y-m-d H:i:s')]);
    }

    /** Annulation d'une vente : ses points gagnés et utilisés sont repris. */
    public function annulerVente(int $venteId, int $par): void
    {
        if (!self::disponible()) {
            return;
        }
        if (Database::un("SELECT id FROM fidelite_mouvements WHERE vente_id = ? AND type = 'annulation' LIMIT 1", [$venteId]) !== null) {
            return;
        }
        foreach (Database::tous("SELECT client_id, etablissement_id, SUM(points) AS p FROM fidelite_mouvements WHERE vente_id = ? AND type IN ('gain', 'utilisation') GROUP BY client_id, etablissement_id", [$venteId]) as $l) {
            $this->mouvement((int) $l['client_id'], (int) $l['etablissement_id'], $venteId, 'annulation', -(int) $l['p'], null, $par);
        }
    }

    /** Historique des points d'un client (fiche du client). */
    public function historique(int $clientId, int $limite = 30): array
    {
        if (!self::disponible()) {
            return [];
        }
        return Database::tous(
            'SELECT m.*, v.numero, e.nom AS etablissement_nom, r.nom_fr AS recompense_fr, r.nom_en AS recompense_en FROM fidelite_mouvements m
          LEFT JOIN ventes v ON v.id = m.vente_id LEFT JOIN etablissements e ON e.id = m.etablissement_id LEFT JOIN fidelite_recompenses r ON r.id = m.recompense_id
              WHERE m.client_id = ? ORDER BY m.id DESC LIMIT ' . (int) $limite,
            [$clientId]
        );
    }

    /** Meilleurs clients (points) d'un établissement. */
    public function classement(int $etabId, int $limite = 30): array
    {
        return Database::tous(
            'SELECT c.id, c.nom, c.telephone, SUM(m.points) AS points, SUM(IF(m.type = \'gain\', m.points, 0)) AS gagnes, MAX(m.cree_le) AS dernier
               FROM fidelite_mouvements m JOIN clients c ON c.id = m.client_id WHERE m.etablissement_id = ?
           GROUP BY c.id ORDER BY points DESC LIMIT ' . (int) $limite,
            [$etabId]
        );
    }
}
