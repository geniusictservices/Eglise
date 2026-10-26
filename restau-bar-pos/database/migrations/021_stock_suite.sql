-- Stock, suite (cahier des charges, sections 2.1, 8.1, 8.3 et 8.4) :
--  - préparations intermédiaires (sauce, marinade, bouillon) : une fiche « préparation » se PRODUIT à l'avance
--    (les ingrédients sortent, N unités de la préparation entrent en stock), puis les plats la consomment ;
--  - lots avec date de péremption (réceptions, productions) : les sorties prennent d'abord ce qui périme le plus tôt ;
--  - caisse des achats au marché de chaque établissement (compte de trésorerie remis au chef ou à l'acheteur) ;
--  - coût matière par plat : chaque sortie d'ingrédient garde le produit vendu qui l'a demandée ;
--  - vente en gros au dépôt (grille « gros », clients revendeurs, emballages prêtés ou consigne facturée).

-- Une fiche « préparation » ne se vend pas : elle se produit
ALTER TABLE recettes ADD COLUMN preparation TINYINT(1) NOT NULL DEFAULT 0 AFTER rendement;

ALTER TABLE stock_mouvements
    MODIFY type ENUM('ouverture','reception','vente','recette','annulation_vente','transfert_sortie','transfert_entree',
                     'transfert_retour','inventaire','perte','ajustement','vide_recupere','vide_rendu','production') NOT NULL,
    ADD COLUMN origine_produit_id INT UNSIGNED NULL AFTER produit_id,        -- fiche technique : le produit vendu
    ADD KEY idx_mvt_origine (origine_produit_id, date_mouvement);

-- Production d'une préparation dans un lieu (souvent la cuisine)
CREATE TABLE productions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero          VARCHAR(24) NULL UNIQUE,                 -- « PR-000012 »
    lieu_id         INT UNSIGNED NOT NULL,
    produit_id      INT UNSIGNED NOT NULL,                   -- la préparation
    quantite        DECIMAL(14,3) NOT NULL,                  -- unités de base produites
    cout_total      DECIMAL(18,6) NULL,                      -- ingrédients au coût moyen (devise de référence)
    peremption      DATE NULL,
    notes           VARCHAR(255) NULL,
    utilisateur_id  INT UNSIGNED NULL,
    date_production DATETIME NOT NULL,
    cree_le         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_production_date (date_production),
    CONSTRAINT fk_production_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id),
    CONSTRAINT fk_production_produit FOREIGN KEY (produit_id) REFERENCES produits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lots : ce qui reste de chaque entrée datée ; les sorties consomment d'abord le lot qui périme le plus tôt
CREATE TABLE stock_lots (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lieu_id           INT UNSIGNED NOT NULL,
    produit_id        INT UNSIGNED NOT NULL,
    numero            VARCHAR(64) NULL,
    peremption        DATE NULL,
    quantite_initiale DECIMAL(16,3) NOT NULL,
    quantite_restante DECIMAL(16,3) NOT NULL,
    origine           ENUM('reception','production','transfert','ajustement') NOT NULL,
    source_id         INT UNSIGNED NULL,
    cree_le           DATETIME NOT NULL,
    KEY idx_lots_lieu (lieu_id, produit_id, peremption),
    KEY idx_lots_peremption (peremption),
    CONSTRAINT fk_lot_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id) ON DELETE CASCADE,
    CONSTRAINT fk_lot_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Part de chaque mouvement prise dans (−) ou rendue à (+) un lot
CREATE TABLE stock_lot_mouvements (
    lot_id       INT UNSIGNED NOT NULL,
    mouvement_id INT UNSIGNED NOT NULL,
    quantite     DECIMAL(16,3) NOT NULL,
    PRIMARY KEY (lot_id, mouvement_id),
    KEY idx_lm_mouvement (mouvement_id),
    CONSTRAINT fk_lm_lot FOREIGN KEY (lot_id) REFERENCES stock_lots(id) ON DELETE CASCADE,
    CONSTRAINT fk_lm_mouvement FOREIGN KEY (mouvement_id) REFERENCES stock_mouvements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE reception_lignes
    ADD COLUMN peremption DATE NULL AFTER cout_base_reference,
    ADD COLUMN lot_numero VARCHAR(64) NULL AFTER peremption;

-- Réglages : alerte de péremption (jours) et caisse des achats au marché (fonds remis au chef ou à l'acheteur)
ALTER TABLE stock_parametres
    ADD COLUMN alerte_peremption SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER validation_separee,
    ADD COLUMN compte_marche_id INT UNSIGNED NULL AFTER alerte_peremption,
    ADD CONSTRAINT fk_stockparam_compte FOREIGN KEY (compte_marche_id) REFERENCES comptes_tresorerie(id) ON DELETE SET NULL;

-- Vente en gros au dépôt
ALTER TABLE lieux_stock ADD COLUMN vente_gros TINYINT(1) NOT NULL DEFAULT 0 AFTER par_defaut;
INSERT IGNORE INTO grilles_prix (code, nom_fr, nom_en) VALUES ('gros', 'Prix de gros', 'Wholesale price');

-- Vente en gros : la vente (ventes) sort du dépôt, au client revendeur ; emballages prêtés ou consigne facturée
CREATE TABLE ventes_gros (
    vente_id          INT UNSIGNED PRIMARY KEY,
    lieu_id           INT UNSIGNED NOT NULL,
    client_id         INT UNSIGNED NULL,
    consigne_facturee TINYINT(1) NOT NULL DEFAULT 0,
    notes             VARCHAR(255) NULL,
    CONSTRAINT fk_vg_vente FOREIGN KEY (vente_id) REFERENCES ventes(id) ON DELETE CASCADE,
    CONSTRAINT fk_vg_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id),
    CONSTRAINT fk_vg_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE emballage_mouvements
    MODIFY type ENUM('recu','rendu','consigne_payee','pret','retour','ajustement','consigne_facturee') NOT NULL,
    ADD COLUMN vente_id INT UNSIGNED NULL AFTER reception_id,
    ADD CONSTRAINT fk_em_vente FOREIGN KEY (vente_id) REFERENCES ventes(id) ON DELETE CASCADE;

-- Droits des rôles modèles
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission FROM roles r
  JOIN (SELECT 'gerant' AS role, 'ventes.gros' AS permission UNION ALL SELECT 'magasinier', 'ventes.gros'
        UNION ALL SELECT 'gerant', 'achats.marche' UNION ALL SELECT 'chef_cuisine', 'achats.marche'
        UNION ALL SELECT 'acheteur', 'achats.marche') p ON p.role = r.code;
