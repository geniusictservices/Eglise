<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\CodeQr;
use App\Core\Database;
use App\Core\Journal;
use App\Core\Journee;

/**
 * Entrées et billetterie (cahier des charges, section 4) : prix d'entrée par soirée (ou par défaut) et par catégorie
 * (normal, VIP, early bird, gratuit, réduit), vendus à la caisse de l'entrée (un point de vente sans stock) ; un
 * billet par entrée, avec un code QR imprimé (80 mm) ou montré ; contrôle à la porte (caméra ou saisie), qui refuse
 * un billet déjà utilisé, inconnu, annulé ou d'une autre soirée ; comptes de la nuit ; recettes de la soirée.
 */
final class BilletterieRepository
{
    public const CATEGORIES = ['normal', 'vip', 'early', 'gratuit', 'reduit'];

    public static function disponible(): bool
    {
        return RapportRepository::tableExiste('billets');
    }

    /**
     * Soirée en cours (ou de la journée d'exploitation) : journée et événement éventuel.
     * @return array{journee: string, evenement: ?array}
     */
    public function soiree(array $etab, ?string $maintenant = null): array
    {
        $maintenant ??= date('Y-m-d H:i:s');
        $journee = Journee::du($maintenant, $etab);
        $ev = null;
        if (RapportRepository::tableExiste('evenements')) {
            $ev = Database::un(
                "SELECT * FROM evenements WHERE etablissement_id = ? AND statut = 'prevu' AND debut <= ? AND fin > ? ORDER BY debut DESC LIMIT 1",
                [(int) $etab['id'], $maintenant, $maintenant]
            );
            if ($ev === null) {
                [$debut, $fin] = Journee::bornes($journee, $etab);
                $ev = Database::un(
                    "SELECT * FROM evenements WHERE etablissement_id = ? AND statut = 'prevu' AND debut >= ? AND debut < ? ORDER BY debut LIMIT 1",
                    [(int) $etab['id'], $debut, $fin]
                );
            }
        }
        return ['journee' => $journee, 'evenement' => $ev];
    }

    /**
     * Tarifs vendus maintenant : ceux de la soirée (événement), sinon ceux par défaut ; early bird jusqu'à sa fin de
     * vente. Prix dans chaque devise acceptée (absent : converti depuis une devise renseignée, avec son arrondi).
     * @param array<int, array> $acceptees devises acceptées (id → devise avec taux)
     * @return list<array{id: int, categorie: string, nom: string, prix: array<string, ?float>, evenement_id: ?int}>
     */
    public function tarifsEnVente(array $etab, ?array $evenement, array $acceptees, ?string $maintenant = null): array
    {
        $maintenant ??= date('Y-m-d H:i:s');
        $tarifs = [];
        if ($evenement !== null) {
            $tarifs = Database::tous('SELECT * FROM entree_tarifs WHERE etablissement_id = ? AND evenement_id = ? AND actif = 1 AND (fin_vente IS NULL OR fin_vente > ?) ORDER BY position, id',
                [(int) $etab['id'], (int) $evenement['id'], $maintenant]);
        }
        if ($tarifs === []) {
            $tarifs = Database::tous('SELECT * FROM entree_tarifs WHERE etablissement_id = ? AND evenement_id IS NULL AND actif = 1 AND (fin_vente IS NULL OR fin_vente > ?) ORDER BY position, id',
                [(int) $etab['id'], $maintenant]);
        }
        return array_map(fn (array $t) => [
            'id' => (int) $t['id'], 'categorie' => $t['categorie'], 'nom' => champ($t, 'nom'),
            'prix' => $this->prix((int) $t['id'], $acceptees), 'evenement_id' => $t['evenement_id'] !== null ? (int) $t['evenement_id'] : null,
            'fin_vente' => $t['fin_vente'],
        ], $tarifs);
    }

    /** Tarif (actif ou non). */
    public function tarif(int $id): ?array
    {
        return Database::un('SELECT * FROM entree_tarifs WHERE id = ?', [$id]);
    }

    /**
     * Prix d'un tarif dans chaque devise acceptée (code → montant) ; gratuit : 0 partout.
     * @return array<string, ?float>
     */
    public function prix(int $tarifId, array $acceptees): array
    {
        $saisis = array_map('floatval', array_column(Database::tous('SELECT devise_id, montant FROM entree_tarif_prix WHERE tarif_id = ?', [$tarifId]), 'montant', 'devise_id'));
        $devises = new DeviseRepository();
        $prix = [];
        // Équivalent en devise de référence, depuis le premier prix saisi dans une devise qui a un taux
        $reference = null;
        foreach ($saisis as $deviseId => $m) {
            $taux = $acceptees[$deviseId]['taux'] ?? ($devises->actives()[$deviseId]['taux'] ?? null);
            if ($taux !== null && (float) $taux > 0) {
                $reference = $m / (float) $taux;
                break;
            }
        }
        foreach ($acceptees as $id => $d) {
            if (isset($saisis[$id])) {
                $prix[$d['code']] = $saisis[$id];
            } elseif ($saisis === []) {
                $prix[$d['code']] = 0.0;
            } else {
                $prix[$d['code']] = $reference !== null ? $devises->convertirDepuisReference($reference, $d) : null;
            }
        }
        return $prix;
    }

    /**
     * Crée un billet (dans la transaction de la vente, ou au pointage d'un invité).
     * @param array{etablissement_id: int, evenement_id: ?int, journee: string, tarif_id: ?int, categorie: string, vente_id: ?int,
     *              vente_ligne_id: ?int, invite_id?: ?int, prix: float, devise_id: ?int, utilise?: bool, par: ?int, date?: string} $b
     * @return array{id: int, code: string}
     */
    public function creer(array $b): array
    {
        $jeton = CodeQr::jeton();
        $date = $b['date'] ?? date('Y-m-d H:i:s');
        $utilise = !empty($b['utilise']);
        Database::executer(
            'INSERT INTO billets (jeton, etablissement_id, evenement_id, journee, tarif_id, categorie, vente_id, vente_ligne_id, invite_id, prix, devise_id,
                                  statut, utilise_le, utilise_par, cree_par, cree_le)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$jeton, $b['etablissement_id'], $b['evenement_id'], $b['journee'], $b['tarif_id'], $b['categorie'], $b['vente_id'], $b['vente_ligne_id'],
                $b['invite_id'] ?? null, number_format($b['prix'], 3, '.', ''), $b['devise_id'], $utilise ? 'utilise' : 'valide',
                $utilise ? $date : null, $utilise ? $b['par'] : null, $b['par'], $date]
        );
        $id = Database::dernierId();
        return ['id' => $id, 'code' => CodeQr::code('B', $id, $jeton)];
    }

    /** Billets d'une vente (pour le reçu), avec leur code et leur tarif. */
    public function deVente(int $venteId): array
    {
        return array_map(static fn (array $b) => $b + ['code' => CodeQr::code('B', (int) $b['id'], $b['jeton'])], Database::tous(
            'SELECT b.*, t.nom_fr AS tarif_fr, t.nom_en AS tarif_en, ev.titre AS evenement_titre
               FROM billets b LEFT JOIN entree_tarifs t ON t.id = b.tarif_id LEFT JOIN evenements ev ON ev.id = b.evenement_id
              WHERE b.vente_id = ? ORDER BY b.id',
            [$venteId]
        ));
    }

    /** Billets par identifiants (billets offerts sans vente). */
    public function parIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return array_map(static fn (array $b) => $b + ['code' => CodeQr::code('B', (int) $b['id'], $b['jeton'])], Database::tous(
            'SELECT b.*, t.nom_fr AS tarif_fr, t.nom_en AS tarif_en, ev.titre AS evenement_titre
               FROM billets b LEFT JOIN entree_tarifs t ON t.id = b.tarif_id LEFT JOIN evenements ev ON ev.id = b.evenement_id
              WHERE b.id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY b.id'
        ));
    }

    /**
     * Contrôle d'un billet à la porte : valide → utilisé maintenant (une seule fois, même avec deux scans simultanés).
     * @return array{resultat: string, billet: ?array}
     *         resultat : ok, deja (utilise_le, utilise_par_nom), invalide, annule, autre_soiree
     */
    public function controler(array $etab, mixed $saisie, string $journee, int $par, ?int $appareilId): array
    {
        $code = CodeQr::lire($saisie, 'B');
        $billet = $code !== null ? Database::un(
            'SELECT b.*, t.nom_fr AS tarif_fr, t.nom_en AS tarif_en, u.nom AS utilise_par_nom FROM billets b
          LEFT JOIN entree_tarifs t ON t.id = b.tarif_id LEFT JOIN utilisateurs u ON u.id = b.utilise_par
              WHERE b.id = ? AND b.etablissement_id = ?',
            [$code['numero'], (int) $etab['id']]
        ) : null;
        if ($billet !== null && !hash_equals($billet['jeton'], $code['jeton'])) {
            $billet = null;
        }
        $resultat = 'invalide';
        if ($billet !== null) {
            if ($billet['statut'] === 'annule') {
                $resultat = 'annule';
            } elseif ($billet['statut'] === 'utilise') {
                $resultat = 'deja';
            } elseif ($billet['journee'] !== $journee) {
                $resultat = 'autre_soiree';
            } else {
                $n = Database::executer("UPDATE billets SET statut = 'utilise', utilise_le = NOW(), utilise_par = ? WHERE id = ? AND statut = 'valide'", [$par, (int) $billet['id']]);
                if ($n === 1) {
                    $resultat = 'ok';
                } else {
                    $resultat = 'deja';
                }
                $billet = Database::un('SELECT b.*, t.nom_fr AS tarif_fr, t.nom_en AS tarif_en, u.nom AS utilise_par_nom FROM billets b
                    LEFT JOIN entree_tarifs t ON t.id = b.tarif_id LEFT JOIN utilisateurs u ON u.id = b.utilise_par WHERE b.id = ?', [(int) $billet['id']]);
            }
        }
        Database::executer(
            'INSERT INTO billet_controles (etablissement_id, billet_id, code_lu, resultat, utilisateur_id, appareil_id, cree_le) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [(int) $etab['id'], $billet ? (int) $billet['id'] : null, mb_substr(is_string($saisie) ? trim($saisie) : '', 0, 64), $resultat, $par, $appareilId]
        );
        return ['resultat' => $resultat, 'billet' => $billet];
    }

    /** Annule les billets d'une vente annulée (pas ceux déjà utilisés : la personne est entrée). */
    public function annulerVente(int $venteId): void
    {
        if (self::disponible()) {
            Database::executer("UPDATE billets SET statut = 'annule' WHERE vente_id = ? AND statut = 'valide'", [$venteId]);
        }
    }

    /**
     * Comptes d'une nuit : billets vendus ou offerts, entrées (billets utilisés), refus au contrôle, par catégorie.
     * @return array{categories: array<string, array{vendus: int, entres: int}>, vendus: int, entres: int, refus: int, invites: int}
     */
    public function compteurs(int $etabId, string $journee): array
    {
        $categories = array_fill_keys(self::CATEGORIES, ['vendus' => 0, 'entres' => 0]);
        foreach (Database::tous("SELECT categorie, COUNT(*) AS n, SUM(statut = 'utilise') AS entres FROM billets WHERE etablissement_id = ? AND journee = ? AND statut <> 'annule' GROUP BY categorie",
            [$etabId, $journee]) as $l) {
            $categories[$l['categorie']] = ['vendus' => (int) $l['n'], 'entres' => (int) $l['entres']];
        }
        [$debut, $fin] = Journee::bornes($journee, Database::un('SELECT fin_journee FROM etablissements WHERE id = ?', [$etabId]));
        $refus = (int) (Database::un("SELECT COUNT(*) AS n FROM billet_controles WHERE etablissement_id = ? AND resultat <> 'ok' AND cree_le >= ? AND cree_le < ?", [$etabId, $debut, $fin])['n'] ?? 0);
        $invites = (int) (Database::un('SELECT COUNT(*) AS n FROM billets WHERE etablissement_id = ? AND journee = ? AND invite_id IS NOT NULL AND statut = \'utilise\'', [$etabId, $journee])['n'] ?? 0);
        return [
            'categories' => $categories,
            'vendus' => array_sum(array_column($categories, 'vendus')),
            'entres' => array_sum(array_column($categories, 'entres')),
            'refus' => $refus,
            'invites' => $invites,
        ];
    }

    /**
     * Entrées d'une soirée (fiche de l'événement) : par catégorie, billets, entrés, recette dans la devise du ticket.
     * @return array{categories: list<array>, billets: int, entres: int, invites: int, recettes: array<int, float>}
     */
    public function soireeBilan(int $evenementId): array
    {
        $lignes = Database::tous(
            "SELECT b.categorie, b.devise_id, COUNT(*) AS billets, SUM(b.statut = 'utilise') AS entres, SUM(b.prix) AS recette, SUM(b.invite_id IS NOT NULL) AS invites
               FROM billets b LEFT JOIN ventes v ON v.id = b.vente_id
              WHERE b.evenement_id = ? AND b.statut <> 'annule' AND (v.id IS NULL OR v.statut = 'payee')
           GROUP BY b.categorie, b.devise_id ORDER BY FIELD(b.categorie, 'normal', 'vip', 'early', 'reduit', 'gratuit')",
            [$evenementId]
        );
        $recettes = [];
        $categories = [];
        foreach ($lignes as $l) {
            if ($l['devise_id'] !== null) {
                $recettes[(int) $l['devise_id']] = ($recettes[(int) $l['devise_id']] ?? 0) + (float) $l['recette'];
            }
            $c = $l['categorie'];
            $categories[$c] ??= ['categorie' => $c, 'billets' => 0, 'entres' => 0, 'recettes' => []];
            $categories[$c]['billets'] += (int) $l['billets'];
            $categories[$c]['entres'] += (int) $l['entres'];
            if ($l['devise_id'] !== null) {
                $categories[$c]['recettes'][(int) $l['devise_id']] = ($categories[$c]['recettes'][(int) $l['devise_id']] ?? 0) + (float) $l['recette'];
            }
        }
        return [
            'categories' => array_values($categories),
            'billets' => array_sum(array_column($categories, 'billets')),
            'entres' => array_sum(array_column($categories, 'entres')),
            'invites' => (int) array_sum(array_column($lignes, 'invites')),
            'recettes' => $recettes,
        ];
    }

    // ---------- Gestion des tarifs ----------

    /** Tarifs d'un établissement : par défaut (evenement NULL) et des soirées à venir ou récentes. */
    public function tarifs(int $etabId): array
    {
        $lignes = Database::tous(
            'SELECT t.*, ev.titre AS evenement_titre, ev.debut AS evenement_debut FROM entree_tarifs t LEFT JOIN evenements ev ON ev.id = t.evenement_id
              WHERE t.etablissement_id = ? ORDER BY t.evenement_id IS NOT NULL, ev.debut DESC, t.position, t.id',
            [$etabId]
        );
        foreach ($lignes as &$l) {
            $l['montants'] = array_map('floatval', array_column(Database::tous('SELECT devise_id, montant FROM entree_tarif_prix WHERE tarif_id = ?', [(int) $l['id']]), 'montant', 'devise_id'));
        }
        unset($l);
        return $lignes;
    }

    /**
     * @param array{evenement_id: ?int, categorie: string, nom_fr: string, nom_en: string, fin_vente: ?string, actif: bool, position: int} $v
     * @param array<int, float> $montants devise_id → montant (gratuit : aucun)
     */
    public function enregistrerTarif(?int $id, int $etabId, array $v, array $montants, int $par): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $valeurs = [$v['evenement_id'], $v['categorie'], $v['nom_fr'], $v['nom_en'] !== '' ? $v['nom_en'] : $v['nom_fr'], $v['fin_vente'], $v['actif'] ? 1 : 0, $v['position']];
            if ($id === null) {
                Database::executer('INSERT INTO entree_tarifs (etablissement_id, evenement_id, categorie, nom_fr, nom_en, fin_vente, actif, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    array_merge([$etabId], $valeurs));
                $id = Database::dernierId();
            } else {
                Database::executer('UPDATE entree_tarifs SET evenement_id = ?, categorie = ?, nom_fr = ?, nom_en = ?, fin_vente = ?, actif = ?, position = ? WHERE id = ? AND etablissement_id = ?',
                    array_merge($valeurs, [$id, $etabId]));
            }
            Database::executer('DELETE FROM entree_tarif_prix WHERE tarif_id = ?', [$id]);
            if ($v['categorie'] !== 'gratuit') {
                foreach ($montants as $deviseId => $m) {
                    if ($m > 0) {
                        Database::executer('INSERT INTO entree_tarif_prix (tarif_id, devise_id, montant) VALUES (?, ?, ?)', [$id, $deviseId, number_format($m, 3, '.', '')]);
                    }
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Journal::ecrire('tarif_entree', $v['nom_fr'], ['categorie' => $v['categorie'], 'evenement' => $v['evenement_id'], 'montants' => $montants], $par);
        return $id;
    }

    /** Supprime un tarif jamais vendu (sinon il se désactive). */
    public function supprimerTarif(int $id, int $etabId, int $par): bool
    {
        if (Database::un('SELECT id FROM billets WHERE tarif_id = ? LIMIT 1', [$id]) !== null) {
            Database::executer('UPDATE entree_tarifs SET actif = 0 WHERE id = ? AND etablissement_id = ?', [$id, $etabId]);
            return false;
        }
        Database::executer('DELETE FROM entree_tarifs WHERE id = ? AND etablissement_id = ?', [$id, $etabId]);
        Journal::ecrire('tarif_entree_supprime', (string) $id, [], $par);
        return true;
    }

    /** Billets d'une nuit (écran de gestion), les plus récents d'abord. */
    public function billetsNuit(int $etabId, string $journee): array
    {
        return Database::tous(
            'SELECT b.*, t.nom_fr AS tarif_fr, t.nom_en AS tarif_en, v.numero, u.nom AS utilise_par_nom, i.nom AS invite_nom
               FROM billets b LEFT JOIN entree_tarifs t ON t.id = b.tarif_id LEFT JOIN ventes v ON v.id = b.vente_id
          LEFT JOIN utilisateurs u ON u.id = b.utilise_par LEFT JOIN invites i ON i.id = b.invite_id
              WHERE b.etablissement_id = ? AND b.journee = ? ORDER BY b.id DESC LIMIT 500',
            [$etabId, $journee]
        );
    }

    /** Nuits avec des billets (les plus récentes d'abord) : journée, billets, entrés. */
    public function nuits(int $etabId, int $limite = 30): array
    {
        return Database::tous(
            "SELECT journee, COUNT(*) AS billets, SUM(statut = 'utilise') AS entres, MAX(evenement_id) AS evenement_id
               FROM billets WHERE etablissement_id = ? AND statut <> 'annule' GROUP BY journee ORDER BY journee DESC LIMIT " . (int) $limite,
            [$etabId]
        );
    }
}
