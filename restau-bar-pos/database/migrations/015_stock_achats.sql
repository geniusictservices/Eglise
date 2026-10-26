-- Stock et achats (cahier des charges, sections 2, 9, 13.1 et 13.2).
--
-- Lieux : chaque établissement a des DÉPÔTS (réserve, économat, chambre froide) et des COMPTOIRS (bar, bar VIP,
-- cuisine, chicha), chacun avec son stock. Un dépôt sans établissement est un dépôt central qui sert
-- plusieurs établissements. Le comptoir « par défaut » d'un établissement vend tout ce qui n'est pas routé
-- ailleurs ; la catégorie d'un produit (Plats → Cuisine) passe avant l'appareil et la zone de la vente.
--
-- Unités : le stock se compte toujours dans l'unité de base du produit (bouteille, cl, kg, pièce) ;
-- une vente de 2 « Casier » (quantite_base 12) fait sortir 24 bouteilles. Une taille (Primus Grande)
-- est un produit à part, avec son propre stock. Un produit qui a une fiche technique (cocktail, plat)
-- n'a pas de stock : sa vente fait sortir ses ingrédients.
--
-- Coûts : coût moyen pondéré (CUMP) par produit et par unité de base, dans la devise de référence,
-- recalculé à chaque réception.

CREATE TABLE lieux_stock (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NULL,                    -- NULL = dépôt central (plusieurs établissements)
    type             ENUM('depot','comptoir') NOT NULL,
    nom              VARCHAR(80)  NOT NULL,
    description      VARCHAR(255) NULL,
    par_defaut       TINYINT(1)   NOT NULL DEFAULT 0,      -- comptoir qui vend par défaut dans l'établissement
    actif            TINYINT(1)   NOT NULL DEFAULT 1,
    position         SMALLINT     NOT NULL DEFAULT 0,
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lieux_etab (etablissement_id, type, position),
    CONSTRAINT fk_lieu_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chaque établissement existant reçoit son comptoir par défaut « Bar » (les nouveaux le reçoivent à la
-- première vente ou à l'ouverture de l'écran Dépôts et comptoirs)
INSERT INTO lieux_stock (etablissement_id, type, nom, par_defaut, position)
SELECT id, 'comptoir', 'Bar', 1, 1 FROM etablissements;

-- Réglages du stock par établissement
CREATE TABLE stock_parametres (
    etablissement_id   INT UNSIGNED PRIMARY KEY,
    mode_negatif       ENUM('vendre','bloquer') NOT NULL DEFAULT 'vendre',  -- vendre avec une alerte, ou « épuisé » à 0
    validation_separee TINYINT(1) NOT NULL DEFAULT 1,      -- inventaire validé par une autre personne que celle qui a compté
    maj_par            INT UNSIGNED NULL,
    maj_le             DATETIME NULL,
    CONSTRAINT fk_stockparam_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Routage : quel comptoir vend (et voit baisser son stock) selon la catégorie, la zone ou l'appareil
CREATE TABLE stock_routage_categories (
    etablissement_id INT UNSIGNED NOT NULL,
    categorie_id     INT UNSIGNED NOT NULL,
    lieu_id          INT UNSIGNED NOT NULL,
    PRIMARY KEY (etablissement_id, categorie_id),
    CONSTRAINT fk_rcat_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_rcat_cat FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_rcat_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_routage_zones (
    zone_id INT UNSIGNED PRIMARY KEY,
    lieu_id INT UNSIGNED NOT NULL,
    CONSTRAINT fk_rzone_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    CONSTRAINT fk_rzone_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_routage_appareils (
    appareil_id INT UNSIGNED PRIMARY KEY,
    lieu_id     INT UNSIGNED NOT NULL,
    CONSTRAINT fk_rapp_appareil FOREIGN KEY (appareil_id) REFERENCES appareils_caisse(id) ON DELETE CASCADE,
    CONSTRAINT fk_rapp_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock actuel d'un produit dans un lieu (unité de base) ; toujours égal à la somme de ses mouvements
CREATE TABLE stock_niveaux (
    lieu_id      INT UNSIGNED NOT NULL,
    produit_id   INT UNSIGNED NOT NULL,
    quantite     DECIMAL(16,3) NOT NULL DEFAULT 0,
    seuil_alerte DECIMAL(16,3) NULL,                       -- stock bas à partir de ce niveau
    stock_cible  DECIMAL(16,3) NULL,                       -- niveau visé par les suggestions de commande
    maj_le       DATETIME NULL,
    PRIMARY KEY (lieu_id, produit_id),
    KEY idx_niveaux_produit (produit_id),
    CONSTRAINT fk_niveau_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id) ON DELETE CASCADE,
    CONSTRAINT fk_niveau_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des mouvements : qui, quand, pourquoi. source_id désigne, selon le type, la vente (vente,
-- recette, vide_recupere, annulation_vente), la réception (reception, vide_rendu), le transfert, l'inventaire
-- ou la perte.
CREATE TABLE stock_mouvements (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lieu_id            INT UNSIGNED NOT NULL,
    produit_id         INT UNSIGNED NOT NULL,
    type               ENUM('ouverture','reception','vente','recette','annulation_vente','transfert_sortie','transfert_entree',
                            'transfert_retour','inventaire','perte','ajustement','vide_recupere','vide_rendu') NOT NULL,
    quantite           DECIMAL(16,3) NOT NULL,             -- unité de base : + entrée, − sortie
    stock_apres        DECIMAL(16,3) NULL,
    conditionnement_id INT UNSIGNED NULL,                   -- format saisi (casier, bouteille…)
    quantite_saisie    DECIMAL(16,3) NULL,                  -- quantité dans ce format
    cout_unitaire      DECIMAL(18,6) NULL,                  -- CUMP par unité de base (devise de référence) à ce moment
    source_id          INT UNSIGNED NULL,
    motif              VARCHAR(255) NULL,
    utilisateur_id     INT UNSIGNED NULL,
    date_mouvement     DATETIME NOT NULL,
    cree_le            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mvt_lieu (lieu_id, produit_id, date_mouvement),
    KEY idx_mvt_source (type, source_id),
    KEY idx_mvt_date (date_mouvement),
    CONSTRAINT fk_mvt_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id),
    CONSTRAINT fk_mvt_produit FOREIGN KEY (produit_id) REFERENCES produits(id),
    CONSTRAINT fk_mvt_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coût moyen pondéré par unité de base, dans la devise de référence
CREATE TABLE stock_couts (
    produit_id INT UNSIGNED PRIMARY KEY,
    cump       DECIMAL(18,6) NOT NULL,
    devise_id  INT UNSIGNED NOT NULL,
    maj_le     DATETIME NOT NULL,
    CONSTRAINT fk_cout_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE,
    CONSTRAINT fk_cout_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fiches techniques : « rendement » unités de base du produit vendu (1 verre, 1 assiette) demandent ces
-- ingrédients ; rendement_pct = part utilisable de l'ingrédient (1 kg de poisson → 600 g utilisables = 60)
CREATE TABLE recettes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produit_id INT UNSIGNED NOT NULL UNIQUE,
    rendement  DECIMAL(12,3) NOT NULL DEFAULT 1,
    notes      TEXT NULL,
    actif      TINYINT(1) NOT NULL DEFAULT 1,
    maj_par    INT UNSIGNED NULL,
    maj_le     DATETIME NULL,
    CONSTRAINT fk_recette_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recette_ingredients (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recette_id    INT UNSIGNED NOT NULL,
    produit_id    INT UNSIGNED NOT NULL,
    quantite      DECIMAL(14,4) NOT NULL,                  -- unité de base de l'ingrédient, utilisable
    rendement_pct DECIMAL(5,2) NOT NULL DEFAULT 100,
    position      SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_recette_ingredient (recette_id, produit_id),
    CONSTRAINT fk_ri_recette FOREIGN KEY (recette_id) REFERENCES recettes(id) ON DELETE CASCADE,
    CONSTRAINT fk_ri_produit FOREIGN KEY (produit_id) REFERENCES produits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Achats ----------

CREATE TABLE fournisseurs (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom            VARCHAR(128) NOT NULL,
    type           ENUM('brasserie','distributeur','grossiste','marche','autre') NOT NULL DEFAULT 'distributeur',
    contact        VARCHAR(128) NULL,
    telephone      VARCHAR(32)  NULL,
    email          VARCHAR(128) NULL,
    adresse        VARCHAR(255) NULL,
    delai_paiement SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- jours avant l'échéance d'une facture
    devise_id      INT UNSIGNED NULL,                      -- devise habituelle des factures
    notes          TEXT NULL,
    actif          TINYINT(1) NOT NULL DEFAULT 1,
    cree_le        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fournisseur_nom (nom),
    CONSTRAINT fk_fournisseur_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commandes_achat (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero           VARCHAR(24) NULL UNIQUE,              -- « BC-000012 »
    fournisseur_id   INT UNSIGNED NOT NULL,
    lieu_id          INT UNSIGNED NOT NULL,                -- lieu de livraison
    etablissement_id INT UNSIGNED NULL,
    devise_id        INT UNSIGNED NOT NULL,
    statut           ENUM('brouillon','envoyee','partielle','recue','annulee') NOT NULL DEFAULT 'brouillon',
    date_commande    DATE NOT NULL,
    date_prevue      DATE NULL,
    notes            TEXT NULL,
    utilisateur_id   INT UNSIGNED NULL,
    cree_le          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    envoyee_le       DATETIME NULL,
    annulee_le       DATETIME NULL,
    KEY idx_commande_statut (statut, date_commande),
    CONSTRAINT fk_commande_fournisseur FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs(id),
    CONSTRAINT fk_commande_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id),
    CONSTRAINT fk_commande_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commande_lignes (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    commande_id        INT UNSIGNED NOT NULL,
    produit_id         INT UNSIGNED NOT NULL,
    conditionnement_id INT UNSIGNED NULL,
    quantite           DECIMAL(12,3) NOT NULL,             -- dans le format commandé
    quantite_base      DECIMAL(16,3) NOT NULL,
    prix_unitaire      DECIMAL(18,3) NULL,                 -- prix attendu par format, devise de la commande
    position           SMALLINT NOT NULL DEFAULT 0,
    KEY idx_cl_commande (commande_id),
    CONSTRAINT fk_cl_commande FOREIGN KEY (commande_id) REFERENCES commandes_achat(id) ON DELETE CASCADE,
    CONSTRAINT fk_cl_produit FOREIGN KEY (produit_id) REFERENCES produits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réception d'une livraison (ou achat au marché, sans facture, avec la photo du ticket) dans un dépôt ou un comptoir
CREATE TABLE receptions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero           VARCHAR(24) NULL UNIQUE,              -- « REC-000012 »
    fournisseur_id   INT UNSIGNED NOT NULL,
    commande_id      INT UNSIGNED NULL,
    lieu_id          INT UNSIGNED NOT NULL,
    etablissement_id INT UNSIGNED NULL,
    devise_id        INT UNSIGNED NOT NULL,                -- devise de la facture
    taux             DECIMAL(18,6) NULL,                   -- unités de cette devise pour 1 unité de référence, ce jour-là
    date_reception   DATETIME NOT NULL,
    facture_numero   VARCHAR(64) NULL,
    sans_facture     TINYINT(1) NOT NULL DEFAULT 0,
    justificatif     VARCHAR(255) NULL,                    -- photo de la facture ou du ticket
    montant_lignes   DECIMAL(18,3) NOT NULL DEFAULT 0,
    montant_consigne DECIMAL(18,3) NOT NULL DEFAULT 0,     -- consigne payée pour des emballages gardés
    total            DECIMAL(18,3) NOT NULL DEFAULT 0,
    echeance         DATE NULL,
    notes            TEXT NULL,
    utilisateur_id   INT UNSIGNED NULL,
    cree_le          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reception_date (date_reception),
    KEY idx_reception_fournisseur (fournisseur_id, date_reception),
    CONSTRAINT fk_reception_fournisseur FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs(id),
    CONSTRAINT fk_reception_commande FOREIGN KEY (commande_id) REFERENCES commandes_achat(id) ON DELETE SET NULL,
    CONSTRAINT fk_reception_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id),
    CONSTRAINT fk_reception_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reception_lignes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reception_id        INT UNSIGNED NOT NULL,
    commande_ligne_id   INT UNSIGNED NULL,
    produit_id          INT UNSIGNED NOT NULL,
    conditionnement_id  INT UNSIGNED NULL,
    quantite_commandee  DECIMAL(12,3) NULL,                -- dans le format, si la réception vient d'une commande
    quantite            DECIMAL(12,3) NOT NULL,            -- reçue, dans le format
    quantite_base       DECIMAL(16,3) NOT NULL,
    prix_unitaire       DECIMAL(18,3) NOT NULL,            -- par format, devise de la réception
    montant             DECIMAL(18,3) NOT NULL,
    cout_base_reference DECIMAL(18,6) NULL,                -- coût par unité de base en devise de référence
    position            SMALLINT NOT NULL DEFAULT 0,
    KEY idx_rl_reception (reception_id),
    KEY idx_rl_produit (produit_id),
    CONSTRAINT fk_rl_reception FOREIGN KEY (reception_id) REFERENCES receptions(id) ON DELETE CASCADE,
    CONSTRAINT fk_rl_produit FOREIGN KEY (produit_id) REFERENCES produits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paiements aux fournisseurs : chaque ligne sort d'un compte de trésorerie, dans une de ses devises
-- (mouvement de nature « achat », source = paiements_fournisseurs.id). contre_valeur : dans la devise de la facture.
CREATE TABLE paiements_fournisseurs (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reception_id   INT UNSIGNED NOT NULL,
    fournisseur_id INT UNSIGNED NOT NULL,
    date_paiement  DATETIME NOT NULL,
    notes          VARCHAR(255) NULL,
    utilisateur_id INT UNSIGNED NULL,
    cree_le        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pf_reception (reception_id),
    CONSTRAINT fk_pf_reception FOREIGN KEY (reception_id) REFERENCES receptions(id),
    CONSTRAINT fk_pf_fournisseur FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE paiement_fournisseur_lignes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paiement_id   INT UNSIGNED NOT NULL,
    compte_id     INT UNSIGNED NOT NULL,
    devise_id     INT UNSIGNED NOT NULL,
    montant       DECIMAL(18,3) NOT NULL,
    taux          DECIMAL(18,6) NULL,                      -- unités de la devise payée pour 1 unité de la devise de la facture
    contre_valeur DECIMAL(18,3) NOT NULL,                  -- dans la devise de la facture
    position      SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_pfl_compte_devise (paiement_id, compte_id, devise_id),
    CONSTRAINT fk_pfl_paiement FOREIGN KEY (paiement_id) REFERENCES paiements_fournisseurs(id) ON DELETE CASCADE,
    CONSTRAINT fk_pfl_compte FOREIGN KEY (compte_id) REFERENCES comptes_tresorerie(id),
    CONSTRAINT fk_pfl_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Emballages consignés ----------

-- Un emballage (casier vide, bouteille vide) est un produit stocké, avec une valeur de consigne
CREATE TABLE emballages (
    produit_id      INT UNSIGNED PRIMARY KEY,
    valeur_consigne DECIMAL(14,3) NOT NULL DEFAULT 0,
    devise_id       INT UNSIGNED NOT NULL,
    CONSTRAINT fk_emballage_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE,
    CONSTRAINT fk_emballage_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emballages qui viennent avec un format : 1 casier de Primus Grande = 1 casier vide + 12 bouteilles vides
CREATE TABLE conditionnement_emballages (
    conditionnement_id INT UNSIGNED NOT NULL,
    emballage_id       INT UNSIGNED NOT NULL,
    quantite           DECIMAL(10,3) NOT NULL,
    PRIMARY KEY (conditionnement_id, emballage_id),
    CONSTRAINT fk_ce_cond FOREIGN KEY (conditionnement_id) REFERENCES conditionnements(id) ON DELETE CASCADE,
    CONSTRAINT fk_ce_emballage FOREIGN KEY (emballage_id) REFERENCES emballages(produit_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emballages dus avec chaque tiers. Fournisseur : + = nous gardons ses emballages (livraison), − = rendus
-- ou consigne payée. Client (vente en gros) : + = le client garde les nôtres, − = rendus.
CREATE TABLE emballage_mouvements (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fournisseur_id INT UNSIGNED NULL,
    client_id      INT UNSIGNED NULL,
    produit_id     INT UNSIGNED NOT NULL,                  -- l'emballage
    type           ENUM('recu','rendu','consigne_payee','pret','retour','ajustement') NOT NULL,
    quantite       DECIMAL(12,3) NOT NULL,
    reception_id   INT UNSIGNED NULL,
    valeur         DECIMAL(18,3) NULL,                     -- consigne payée
    devise_id      INT UNSIGNED NULL,
    date_mouvement DATETIME NOT NULL,
    notes          VARCHAR(255) NULL,
    utilisateur_id INT UNSIGNED NULL,
    cree_le        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_em_fournisseur (fournisseur_id),
    KEY idx_em_client (client_id),
    CONSTRAINT fk_em_fournisseur FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs(id),
    CONSTRAINT fk_em_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_em_produit FOREIGN KEY (produit_id) REFERENCES produits(id),
    CONSTRAINT fk_em_reception FOREIGN KEY (reception_id) REFERENCES receptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Transferts, inventaires, pertes ----------

-- Validé des deux côtés : l'expéditeur confirme la sortie (le stock quitte la source), le lieu qui reçoit
-- confirme la réception avec la quantité reçue (l'écart est gardé)
CREATE TABLE transferts_stock (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero              VARCHAR(24) NULL UNIQUE,           -- « TR-000012 »
    lieu_source_id      INT UNSIGNED NOT NULL,
    lieu_destination_id INT UNSIGNED NOT NULL,
    statut              ENUM('brouillon','expedie','recu','annule') NOT NULL DEFAULT 'brouillon',
    notes               VARCHAR(255) NULL,
    note_reception      VARCHAR(255) NULL,
    cree_par            INT UNSIGNED NULL,
    cree_le             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expedie_par         INT UNSIGNED NULL,
    expedie_le          DATETIME NULL,
    recu_par            INT UNSIGNED NULL,
    recu_le             DATETIME NULL,
    annule_par          INT UNSIGNED NULL,
    annule_le           DATETIME NULL,
    KEY idx_transfert_statut (statut, cree_le),
    CONSTRAINT fk_ts_source FOREIGN KEY (lieu_source_id) REFERENCES lieux_stock(id),
    CONSTRAINT fk_ts_destination FOREIGN KEY (lieu_destination_id) REFERENCES lieux_stock(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transfert_stock_lignes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfert_id        INT UNSIGNED NOT NULL,
    produit_id          INT UNSIGNED NOT NULL,
    conditionnement_id  INT UNSIGNED NULL,
    quantite            DECIMAL(12,3) NOT NULL,            -- envoyée, dans le format
    quantite_base       DECIMAL(16,3) NOT NULL,
    quantite_recue_base DECIMAL(16,3) NULL,
    position            SMALLINT NOT NULL DEFAULT 0,
    KEY idx_tsl_transfert (transfert_id),
    CONSTRAINT fk_tsl_transfert FOREIGN KEY (transfert_id) REFERENCES transferts_stock(id) ON DELETE CASCADE,
    CONSTRAINT fk_tsl_produit FOREIGN KEY (produit_id) REFERENCES produits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventaire d'un lieu : le stock attendu est figé à la création (fige_le) ; à la validation, le stock est
-- corrigé de l'écart (compté − attendu), sans effacer les mouvements faits depuis
CREATE TABLE inventaires (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero       VARCHAR(24) NULL UNIQUE,                  -- « INV-000012 »
    lieu_id      INT UNSIGNED NOT NULL,
    type         ENUM('complet','service','partiel') NOT NULL DEFAULT 'complet',
    categorie_id INT UNSIGNED NULL,                        -- inventaire partiel d'une catégorie
    statut       ENUM('en_cours','compte','valide','annule') NOT NULL DEFAULT 'en_cours',
    fige_le      DATETIME NOT NULL,
    notes        VARCHAR(255) NULL,
    cree_par     INT UNSIGNED NULL,
    cree_le      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    compte_par   INT UNSIGNED NULL,
    compte_le    DATETIME NULL,
    valide_par   INT UNSIGNED NULL,
    valide_le    DATETIME NULL,
    valide_seul  TINYINT(1) NOT NULL DEFAULT 0,            -- validé par la personne qui a compté
    annule_par   INT UNSIGNED NULL,
    annule_le    DATETIME NULL,
    KEY idx_inventaire_lieu (lieu_id, statut),
    CONSTRAINT fk_inventaire_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventaire_lignes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventaire_id INT UNSIGNED NOT NULL,
    produit_id    INT UNSIGNED NOT NULL,
    debut         DECIMAL(16,3) NOT NULL DEFAULT 0,        -- stock après l'inventaire validé précédent
    entrees       DECIMAL(16,3) NOT NULL DEFAULT 0,        -- réceptions et transferts reçus depuis
    ventes        DECIMAL(16,3) NOT NULL DEFAULT 0,        -- ventes et recettes (positif)
    pertes        DECIMAL(16,3) NOT NULL DEFAULT 0,        -- pertes (positif)
    autres        DECIMAL(16,3) NOT NULL DEFAULT 0,        -- transferts envoyés, ajustements, annulations (±)
    attendu       DECIMAL(16,3) NOT NULL,
    compte        DECIMAL(16,3) NULL,
    saisie        VARCHAR(255) NULL,                       -- détail du comptage par format (JSON)
    cout_unitaire DECIMAL(18,6) NULL,
    justification VARCHAR(255) NULL,
    UNIQUE KEY uq_inventaire_produit (inventaire_id, produit_id),
    CONSTRAINT fk_il_inventaire FOREIGN KEY (inventaire_id) REFERENCES inventaires(id) ON DELETE CASCADE,
    CONSTRAINT fk_il_produit FOREIGN KEY (produit_id) REFERENCES produits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pertes_stock (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lieu_id            INT UNSIGNED NOT NULL,
    produit_id         INT UNSIGNED NOT NULL,
    conditionnement_id INT UNSIGNED NULL,
    quantite           DECIMAL(12,3) NOT NULL,             -- dans le format
    quantite_base      DECIMAL(16,3) NOT NULL,
    motif              ENUM('casse','offert','personnel','perime','renvoye','autre') NOT NULL,
    commentaire        VARCHAR(255) NOT NULL,
    cout               DECIMAL(18,3) NULL,                 -- valeur perdue (CUMP), devise de référence
    date_perte         DATETIME NOT NULL,
    utilisateur_id     INT UNSIGNED NULL,
    cree_le            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_perte_date (date_perte),
    CONSTRAINT fk_perte_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id),
    CONSTRAINT fk_perte_produit FOREIGN KEY (produit_id) REFERENCES produits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Droits des rôles modèles ----------
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission FROM roles r
  JOIN (SELECT 'gerant' AS role, 'stock.transferer' AS permission UNION ALL SELECT 'gerant', 'stock.inventorier'
        UNION ALL SELECT 'gerant', 'stock.valider' UNION ALL SELECT 'gerant', 'stock.ajuster'
        UNION ALL SELECT 'gerant', 'stock.recettes' UNION ALL SELECT 'gerant', 'stock.parametrer'
        UNION ALL SELECT 'gerant', 'achats.fournisseurs' UNION ALL SELECT 'gerant', 'achats.commander'
        UNION ALL SELECT 'gerant', 'achats.recevoir' UNION ALL SELECT 'gerant', 'achats.payer'
        UNION ALL SELECT 'magasinier', 'stock.transferer' UNION ALL SELECT 'magasinier', 'stock.inventorier'
        UNION ALL SELECT 'magasinier', 'stock.ajuster' UNION ALL SELECT 'magasinier', 'achats.voir'
        UNION ALL SELECT 'magasinier', 'achats.recevoir'
        UNION ALL SELECT 'acheteur', 'achats.fournisseurs' UNION ALL SELECT 'acheteur', 'achats.commander'
        UNION ALL SELECT 'acheteur', 'achats.recevoir'
        UNION ALL SELECT 'chef_cuisine', 'stock.transferer' UNION ALL SELECT 'chef_cuisine', 'stock.inventorier'
        UNION ALL SELECT 'chef_cuisine', 'stock.ajuster' UNION ALL SELECT 'chef_cuisine', 'stock.recettes'
        UNION ALL SELECT 'chef_cuisine', 'achats.recevoir'
        UNION ALL SELECT 'comptable', 'stock.voir' UNION ALL SELECT 'comptable', 'achats.payer') p ON p.role = r.code;
