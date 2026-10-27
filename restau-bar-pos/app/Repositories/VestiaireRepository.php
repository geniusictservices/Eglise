<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\CodeQr;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Journee;

/**
 * Vestiaire (cahier des charges, section 4) : un objet déposé reçoit un ticket numéroté (numéro de la soirée et code
 * QR) ; frais de vestiaire facultatifs (une vente, payée comme à la caisse) ; retour en scannant le ticket ou en
 * tapant son numéro ; liste de ce qui reste au vestiaire à la fermeture.
 */
final class VestiaireRepository
{
    public static function disponible(): bool
    {
        return RapportRepository::tableExiste('vestiaire_tickets');
    }

    /** Frais de vestiaire par code de devise (vide : gratuit). @return array<int, float> devise_id → montant */
    public function frais(int $etabId): array
    {
        if (!self::disponible()) {
            return [];
        }
        return array_map('floatval', array_column(Database::tous('SELECT devise_id, montant FROM vestiaire_frais WHERE etablissement_id = ? AND montant > 0', [$etabId]), 'montant', 'devise_id'));
    }

    /** @param array<int, float> $montants devise_id → montant */
    public function definirFrais(int $etabId, array $montants, int $par): void
    {
        Database::executer('DELETE FROM vestiaire_frais WHERE etablissement_id = ?', [$etabId]);
        foreach ($montants as $deviseId => $m) {
            if ($m > 0) {
                Database::executer('INSERT INTO vestiaire_frais (etablissement_id, devise_id, montant) VALUES (?, ?, ?)', [$etabId, $deviseId, number_format($m, 3, '.', '')]);
            }
        }
        Journal::ecrire('vestiaire_frais', (string) $etabId, ['montants' => $montants], $par);
    }

    /**
     * Dépose un objet : ticket au numéro suivant de la soirée (dans la transaction de la vente des frais, ou seul).
     * @return array ticket (avec « code »)
     */
    public function deposer(array $etab, ?string $description, int $objets, ?int $venteId, int $par, ?string $cle = null): array
    {
        $tresorerie = new GestionTresorerieRepository();
        return $tresorerie->transaction(function () use ($etab, $description, $objets, $venteId, $par, $cle): array {
            if ($cle !== null && ($deja = Database::un('SELECT * FROM vestiaire_tickets WHERE cle = ?', [$cle])) !== null) {
                return self::avecCode($deja);
            }
            $journee = Journee::aujourdhui($etab);
            $numero = (int) Database::un('SELECT COALESCE(MAX(numero), 0) + 1 AS n FROM vestiaire_tickets WHERE etablissement_id = ? AND journee = ? FOR UPDATE',
                [(int) $etab['id'], $journee])['n'];
            Database::executer(
                'INSERT INTO vestiaire_tickets (etablissement_id, journee, numero, jeton, description, objets, vente_id, depose_par, depose_le, cle) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                [(int) $etab['id'], $journee, $numero, CodeQr::jeton(), $description, max(1, min(20, $objets)), $venteId, $par, $cle]
            );
            return self::avecCode(Database::un('SELECT * FROM vestiaire_tickets WHERE id = ?', [Database::dernierId()]));
        });
    }

    public static function avecCode(array $t): array
    {
        return $t + ['code' => CodeQr::code('V', (int) $t['id'], $t['jeton'])];
    }

    /** Tickets d'une vente (frais payés), pour le reçu. */
    public function deVente(int $venteId): array
    {
        return array_map([self::class, 'avecCode'], Database::tous('SELECT * FROM vestiaire_tickets WHERE vente_id = ? ORDER BY id', [$venteId]));
    }

    public function parIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return array_map([self::class, 'avecCode'], Database::tous('SELECT * FROM vestiaire_tickets WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id'));
    }

    /**
     * Trouve un ticket : code QR scanné, ou numéro tapé (ticket encore au vestiaire de cette soirée, sinon le plus récent).
     */
    public function trouver(array $etab, mixed $saisie): ?array
    {
        $code = CodeQr::lire($saisie, 'V');
        if ($code !== null) {
            $t = Database::un('SELECT * FROM vestiaire_tickets WHERE id = ? AND etablissement_id = ?', [$code['numero'], (int) $etab['id']]);
            return $t !== null && hash_equals($t['jeton'], $code['jeton']) ? self::avecCode($t) : null;
        }
        $numero = is_string($saisie) || is_int($saisie) ? trim((string) $saisie) : '';
        if (!ctype_digit($numero) || (int) $numero < 1) {
            return null;
        }
        $t = Database::un(
            "SELECT * FROM vestiaire_tickets WHERE etablissement_id = ? AND numero = ? AND (journee = ? OR statut = 'depose')
           ORDER BY journee = ? DESC, statut = 'depose' DESC, id DESC LIMIT 1",
            [(int) $etab['id'], (int) $numero, Journee::aujourdhui($etab), Journee::aujourdhui($etab)]
        );
        return $t !== null ? self::avecCode($t) : null;
    }

    /** Rend l'objet : une seule fois. @return bool false s'il était déjà rendu */
    public function rendre(int $ticketId, int $par): bool
    {
        $n = Database::executer("UPDATE vestiaire_tickets SET statut = 'rendu', rendu_par = ?, rendu_le = NOW() WHERE id = ? AND statut = 'depose'", [$par, $ticketId]);
        if ($n > 0) {
            Journal::ecrire('vestiaire_rendu', (string) $ticketId, [], $par);
        }
        return $n > 0;
    }

    /** Objets encore au vestiaire (toutes soirées), du plus ancien au plus récent. */
    public function deposes(int $etabId): array
    {
        return array_map([self::class, 'avecCode'], Database::tous(
            "SELECT v.*, u.nom AS depose_par_nom FROM vestiaire_tickets v LEFT JOIN utilisateurs u ON u.id = v.depose_par
              WHERE v.etablissement_id = ? AND v.statut = 'depose' ORDER BY v.journee, v.numero",
            [$etabId]
        ));
    }

    /** Tickets d'une soirée (gestion). */
    public function soiree(int $etabId, string $journee): array
    {
        return array_map([self::class, 'avecCode'], Database::tous(
            'SELECT v.*, d.nom AS depose_par_nom, r.nom AS rendu_par_nom, ve.numero AS vente_numero FROM vestiaire_tickets v
          LEFT JOIN utilisateurs d ON d.id = v.depose_par LEFT JOIN utilisateurs r ON r.id = v.rendu_par LEFT JOIN ventes ve ON ve.id = v.vente_id
              WHERE v.etablissement_id = ? AND v.journee = ? ORDER BY v.numero',
            [$etabId, $journee]
        ));
    }
}
