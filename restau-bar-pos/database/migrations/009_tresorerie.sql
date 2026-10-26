-- Trésorerie : comptes (espèces, mobile money, banque), journal des mouvements, transferts entre comptes,
-- dépenses et leurs paiements.
-- Chaque compte tient UNE devise : « Caisse USD », « Caisse CDF », « M-Pesa CDF »… Une opération payée
-- dans deux devises (moitié en dollars, moitié en francs) a donc deux lignes, une par compte.
-- Le solde d'un compte se calcule toujours depuis le journal (mouvements_tresorerie), jamais depuis une
-- colonne : ventes, achats et remboursements des phases suivantes y écriront leurs lignes de la même façon
-- (nature + source_id), sans changer le calcul des soldes.

CREATE TABLE comptes_tresorerie (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom              VARCHAR(128) NOT NULL,
    type             ENUM('especes','mobile_money','banque') NOT NULL,
    devise_id        INT UNSIGNED NOT NULL,
    operateur        VARCHAR(128) NULL,              -- Vodacom M-Pesa, Airtel Money, Orange Money, Rawbank, Equity BCDC…
    numero           VARCHAR(64)  NULL,              -- numéro de téléphone ou de compte
    etablissement_id INT UNSIGNED NULL,              -- NULL = commun à tous les établissements
    solde_initial    DECIMAL(18,3) NOT NULL DEFAULT 0,
    solde_initial_le DATETIME     NOT NULL,          -- date du solde initial (ligne « solde_initial » du journal)
    active           TINYINT(1)   NOT NULL DEFAULT 1,
    position         SMALLINT     NOT NULL DEFAULT 0,
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_comptes_type (active, type, position),
    CONSTRAINT fk_compte_devise FOREIGN KEY (devise_id)        REFERENCES devises(id),
    CONSTRAINT fk_compte_etab   FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal : une ligne par mouvement d'un compte, dans la devise du compte.
-- taux : unités de la devise pour 1 unité de la devise de référence (reference_id) au moment de l'opération ;
-- contre_valeur : le montant converti dans cette référence (NULL si la devise n'avait pas de taux).
-- nature + source_id désignent la ligne d'origine : transferts_tresorerie.id, depenses.id, et plus tard
-- les ventes, achats, remboursements ; solde_initial pointe vers le compte lui-même.
CREATE TABLE mouvements_tresorerie (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    compte_id      INT UNSIGNED NOT NULL,
    sens           ENUM('entree','sortie') NOT NULL,
    montant        DECIMAL(18,3) NOT NULL,           -- toujours positif, dans la devise du compte
    devise_id      INT UNSIGNED NOT NULL,            -- devise du compte au moment de l'opération
    taux           DECIMAL(18,6) NULL,
    reference_id   INT UNSIGNED NULL,
    contre_valeur  DECIMAL(18,3) NULL,
    nature         ENUM('solde_initial','transfert','depense','vente','achat','remboursement','ajustement') NOT NULL,
    source_id      INT UNSIGNED NULL,
    date_operation DATETIME     NOT NULL,
    libelle        VARCHAR(255) NULL,
    utilisateur_id INT UNSIGNED NULL,
    cree_le        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mouvements_compte (compte_id, date_operation, id),
    KEY idx_mouvements_source (nature, source_id),
    CONSTRAINT fk_mouvement_compte FOREIGN KEY (compte_id)      REFERENCES comptes_tresorerie(id),
    CONSTRAINT fk_mouvement_devise FOREIGN KEY (devise_id)      REFERENCES devises(id),
    CONSTRAINT fk_mouvement_ref    FOREIGN KEY (reference_id)   REFERENCES devises(id),
    CONSTRAINT fk_mouvement_util   FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transfert d'un compte à un autre (dépôt en banque, retrait mobile money, change de dollars en francs…).
-- taux : unités de la devise de destination pour 1 unité de la devise source, celui réellement appliqué
-- (montant_destination / montant_source). Deux lignes dans le journal : sortie de la source, entrée sur la destination.
CREATE TABLE transferts_tresorerie (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    compte_source_id      INT UNSIGNED NOT NULL,
    compte_destination_id INT UNSIGNED NOT NULL,
    montant_source        DECIMAL(18,3) NOT NULL,
    montant_destination   DECIMAL(18,3) NOT NULL,
    taux                  DECIMAL(18,6) NOT NULL DEFAULT 1,
    date_transfert        DATETIME     NOT NULL,
    motif                 VARCHAR(255) NULL,
    utilisateur_id        INT UNSIGNED NULL,
    cree_le               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_transferts_date (date_transfert),
    CONSTRAINT fk_transfert_source FOREIGN KEY (compte_source_id)      REFERENCES comptes_tresorerie(id),
    CONSTRAINT fk_transfert_dest   FOREIGN KEY (compte_destination_id) REFERENCES comptes_tresorerie(id),
    CONSTRAINT fk_transfert_util   FOREIGN KEY (utilisateur_id)        REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories_depenses (
    id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom_fr   VARCHAR(128) NOT NULL,
    nom_en   VARCHAR(128) NOT NULL,
    position SMALLINT     NOT NULL DEFAULT 0,
    active   TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categories_depenses (nom_fr, nom_en, position) VALUES
 ('Loyer', 'Rent', 1),
 ('Électricité', 'Electricity', 2),
 ('Carburant du groupe électrogène', 'Generator fuel', 3),
 ('Eau', 'Water', 4),
 ('Entretien et réparations', 'Maintenance and repairs', 5),
 ('Sécurité', 'Security', 6),
 ('Artistes et DJ', 'Artists and DJs', 7),
 ('Salaires et primes', 'Wages and bonuses', 8),
 ('Achats au marché', 'Market purchases', 9),
 ('Transport', 'Transport', 10),
 ('Taxes et redevances', 'Taxes and fees', 11),
 ('Divers', 'Miscellaneous', 12);

CREATE TABLE depenses (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date_depense     DATETIME     NOT NULL,
    categorie_id     INT UNSIGNED NOT NULL,
    etablissement_id INT UNSIGNED NULL,              -- NULL = commune à tous les établissements
    libelle          VARCHAR(255) NOT NULL,
    beneficiaire     VARCHAR(128) NULL,
    notes            TEXT NULL,
    utilisateur_id   INT UNSIGNED NULL,
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_depenses_date (date_depense),
    CONSTRAINT fk_depense_categorie FOREIGN KEY (categorie_id)     REFERENCES categories_depenses(id),
    CONSTRAINT fk_depense_etab      FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE SET NULL,
    CONSTRAINT fk_depense_util      FOREIGN KEY (utilisateur_id)   REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paiements d'une dépense : le compte donne le moyen (espèces, mobile money, banque) et la devise.
-- Payée dans deux devises = deux lignes. Un compte n'apparaît qu'une fois par dépense.
CREATE TABLE depense_paiements (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    depense_id INT UNSIGNED NOT NULL,
    compte_id  INT UNSIGNED NOT NULL,
    montant    DECIMAL(18,3) NOT NULL,
    position   SMALLINT     NOT NULL DEFAULT 0,
    UNIQUE KEY uq_paiement_compte (depense_id, compte_id),
    CONSTRAINT fk_paiement_depense FOREIGN KEY (depense_id) REFERENCES depenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_paiement_compte  FOREIGN KEY (compte_id)  REFERENCES comptes_tresorerie(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
