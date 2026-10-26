<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Journal;

/**
 * Emballages consignés (section 9.4) : casiers et bouteilles vides, articles de stock avec une valeur de
 * consigne. Une livraison apporte des emballages que l'on doit au fournisseur (formats liés : 1 casier de
 * Primus Grande = 1 casier vide + 12 bouteilles vides) ; on les rend (les vides sortent du lieu) ou on paie
 * la consigne. Vendre une bouteille sur place rend son vide au comptoir. Soldes par fournisseur et par client.
 */
final class EmballageRepository
{
    public const TYPES_FOURNISSEUR = ['recu', 'rendu', 'consigne_payee', 'ajustement'];
    public const TYPES_CLIENT = ['pret', 'retour', 'ajustement'];

    private StockRepository $stock;

    public function __construct(?StockRepository $stock = null)
    {
        $this->stock = $stock ?? new StockRepository();
    }

    /** @return array<int, array> emballages (produit, valeur de consigne, devise) indexés par produit */
    public function emballages(): array
    {
        return array_column(Database::tous(
            'SELECT e.*, p.nom_fr, p.nom_en, p.unite_base, p.actif, d.code AS devise_code
               FROM emballages e JOIN produits p ON p.id = e.produit_id JOIN devises d ON d.id = e.devise_id
              ORDER BY p.position, p.nom_fr'
        ), null, 'produit_id');
    }

    /** Déclare un produit existant comme emballage, ou change sa consigne. */
    public function definir(int $produitId, float $valeur, int $deviseId): void
    {
        Database::executer(
            'INSERT INTO emballages (produit_id, valeur_consigne, devise_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valeur_consigne = VALUES(valeur_consigne), devise_id = VALUES(devise_id)',
            [$produitId, number_format($valeur, 3, '.', ''), $deviseId]
        );
    }

    /**
     * Crée un article d'emballage (produit de la catégorie « Emballages », cachée du menu, compté à la pièce).
     * @return int identifiant du produit
     */
    public function creerArticle(string $nomFr, string $nomEn, float $valeur, int $deviseId): int
    {
        return (new GestionTresorerieRepository())->transaction(function () use ($nomFr, $nomEn, $valeur, $deviseId): int {
            $categorie = Database::un('SELECT id FROM categories WHERE nom_fr = \'Emballages\' AND parent_id IS NULL LIMIT 1');
            if ($categorie === null) {
                $position = (int) Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM categories WHERE parent_id IS NULL')['p'];
                Database::executer('INSERT INTO categories (nom_fr, nom_en, position, visible_public) VALUES (\'Emballages\', \'Returnables\', ?, 0)', [$position]);
                $categorieId = Database::dernierId();
            } else {
                $categorieId = (int) $categorie['id'];
            }
            $position = (int) Database::un('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM produits WHERE categorie_id = ?', [$categorieId])['p'];
            Database::executer(
                'INSERT INTO produits (categorie_id, nom_fr, nom_en, unite_base, visible_public, position) VALUES (?, ?, ?, \'piece\', 0, ?)',
                [$categorieId, $nomFr, $nomEn, $position]
            );
            $id = Database::dernierId();
            Database::executer(
                'INSERT INTO conditionnements (produit_id, nom_fr, nom_en, quantite_base, vendable, achetable, position) VALUES (?, \'Pièce\', \'Piece\', 1, 0, 0, 1)',
                [$id]
            );
            $this->definir($id, $valeur, $deviseId);
            return $id;
        });
    }

    /** Formats liés à des emballages : conditionnement_id => [emballage_id => quantité]. */
    public function liens(): array
    {
        $resultat = [];
        foreach (Database::tous('SELECT * FROM conditionnement_emballages') as $l) {
            $resultat[(int) $l['conditionnement_id']][(int) $l['emballage_id']] = (float) $l['quantite'];
        }
        return $resultat;
    }

    public function lier(int $conditionnementId, int $emballageId, ?float $quantite): void
    {
        if ($quantite === null || $quantite <= 0) {
            Database::executer('DELETE FROM conditionnement_emballages WHERE conditionnement_id = ? AND emballage_id = ?', [$conditionnementId, $emballageId]);
            return;
        }
        Database::executer(
            'INSERT INTO conditionnement_emballages (conditionnement_id, emballage_id, quantite) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantite = VALUES(quantite)',
            [$conditionnementId, $emballageId, StockRepository::q($quantite)]
        );
    }

    /**
     * Soldes des emballages dus, par tiers : [tiers_id => [nom, emballages: [produit_id => quantité], valeur: [devise_id => montant]]].
     * @param 'fournisseur'|'client' $tiers
     */
    public function soldes(string $tiers): array
    {
        $colonne = $tiers === 'client' ? 'client_id' : 'fournisseur_id';
        $table = $tiers === 'client' ? 'clients' : 'fournisseurs';
        $emballages = $this->emballages();
        $resultat = [];
        foreach (Database::tous(
            'SELECT m.' . $colonne . ' AS tiers_id, t.nom, m.produit_id, SUM(m.quantite) AS quantite
               FROM emballage_mouvements m JOIN ' . $table . ' t ON t.id = m.' . $colonne . '
              WHERE m.' . $colonne . ' IS NOT NULL
           GROUP BY m.' . $colonne . ', t.nom, m.produit_id
             HAVING ABS(SUM(m.quantite)) > 0.0005
           ORDER BY t.nom'
        ) as $l) {
            $id = (int) $l['tiers_id'];
            $resultat[$id] ??= ['nom' => $l['nom'], 'emballages' => [], 'valeur' => []];
            $resultat[$id]['emballages'][(int) $l['produit_id']] = (float) $l['quantite'];
            if (isset($emballages[(int) $l['produit_id']])) {
                $e = $emballages[(int) $l['produit_id']];
                $resultat[$id]['valeur'][(int) $e['devise_id']] = ($resultat[$id]['valeur'][(int) $e['devise_id']] ?? 0) + (float) $l['quantite'] * (float) $e['valeur_consigne'];
            }
        }
        return $resultat;
    }

    /** Derniers échanges d'emballages (tous tiers). */
    public function mouvements(int $limite = 50, ?int $fournisseurId = null): array
    {
        return Database::tous(
            'SELECT m.*, p.nom_fr, p.nom_en, f.nom AS fournisseur, c.nom AS client, r.numero AS reception_numero, u.nom AS auteur, d.code AS devise_code
               FROM emballage_mouvements m
               JOIN produits p ON p.id = m.produit_id
          LEFT JOIN fournisseurs f ON f.id = m.fournisseur_id
          LEFT JOIN clients c ON c.id = m.client_id
          LEFT JOIN receptions r ON r.id = m.reception_id
          LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
          LEFT JOIN devises d ON d.id = m.devise_id'
            . ($fournisseurId !== null ? ' WHERE m.fournisseur_id = ' . (int) $fournisseurId : '') . '
           ORDER BY m.date_mouvement DESC, m.id DESC LIMIT ' . max(1, $limite)
        );
    }

    /**
     * Échange d'emballages avec un tiers. Quantité positive ; le sens vient du type : fournisseur « recu » +,
     * « rendu » et « consigne_payee » − ; client « pret » +, « retour » −. Les vides rendus au fournisseur ou
     * prêtés au client sortent du lieu ; ceux que le client rapporte y entrent.
     */
    public function echanger(?int $fournisseurId, ?int $clientId, int $emballageId, string $type, float $quantite, ?int $lieuId,
                             ?int $receptionId, ?float $valeur, ?int $deviseId, string $date, ?string $notes, int $utilisateurId): void
    {
        $signe = in_array($type, ['recu', 'pret', 'ajustement'], true) ? 1 : -1;
        Database::executer(
            'INSERT INTO emballage_mouvements (fournisseur_id, client_id, produit_id, type, quantite, reception_id, valeur, devise_id, date_mouvement, notes, utilisateur_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$fournisseurId, $clientId, $emballageId, $type, StockRepository::q($signe * $quantite), $receptionId,
             $valeur !== null ? number_format($valeur, 3, '.', '') : null, $deviseId, $date, $notes, $utilisateurId]
        );
        if ($lieuId !== null && in_array($type, ['rendu', 'pret', 'retour'], true)) {
            $sens = $type === 'retour' ? 1 : -1;
            $this->stock->mouvement($lieuId, $emballageId, $sens * $quantite, $type === 'retour' ? 'vide_recupere' : 'vide_rendu', $receptionId, $date,
                t('emballages.type_' . $type) . ($notes ? ' : ' . $notes : ''), $utilisateurId);
        }
        if ($receptionId === null) {
            Journal::ecrire('emballages_echange', (string) $emballageId, ['type' => $type, 'quantite' => $quantite, 'fournisseur' => $fournisseurId, 'client' => $clientId], $utilisateurId);
        }
    }
}
