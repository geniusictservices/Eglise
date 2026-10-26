-- Ventes encaissées à la caisse (cahier des charges, section 10.4) : chaque vente garde ses lignes
-- (nom et prix au moment de la vente), ses paiements (moyen, compte, devise, taux) et la monnaie rendue.
-- Chaque paiement entre dans un compte de trésorerie (mouvement de nature « vente »).

CREATE TABLE ventes (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id   INT UNSIGNED NOT NULL,
    sequence           INT UNSIGNED NOT NULL,               -- numéro dans l'établissement : 1, 2, 3…
    numero             VARCHAR(24)  NOT NULL,               -- numéro affiché : « CDL-000123 »
    cle                CHAR(36)     NOT NULL,               -- envoyée par la caisse : un double envoi ne crée pas deux ventes
    zone_id            INT UNSIGNED NULL,
    grille_prix_id     INT UNSIGNED NULL,
    utilisateur_id     INT UNSIGNED NULL,                   -- qui a vendu (connecté à la caisse)
    appareil_id        INT UNSIGNED NULL,
    devise_id          INT UNSIGNED NOT NULL,               -- devise du ticket (la devise locale)
    total              DECIMAL(18,3) NOT NULL,              -- total dans la devise du ticket
    reference_id       INT UNSIGNED NULL,                   -- devise de référence au moment de la vente
    total_reference    DECIMAL(18,3) NULL,                  -- total dans la devise de référence (prix de cette devise)
    encaisse_reference DECIMAL(18,3) NULL,                  -- contre-valeur réellement encaissée (après monnaie rendue)
    statut             ENUM('payee','annulee') NOT NULL DEFAULT 'payee',
    annulee_le         DATETIME     NULL,
    annulee_par        INT UNSIGNED NULL,
    motif_annulation   VARCHAR(255) NULL,
    cree_le            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ventes_cle (cle),
    UNIQUE KEY uq_ventes_numero (etablissement_id, sequence),
    KEY idx_ventes_date (cree_le),
    KEY idx_ventes_vendeur (utilisateur_id, cree_le),
    CONSTRAINT fk_vente_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_vente_devise FOREIGN KEY (devise_id) REFERENCES devises(id),
    CONSTRAINT fk_vente_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Totaux de la vente dans chaque devise acceptée (pour rendre la monnaie et les rapports par devise)
CREATE TABLE vente_totaux (
    vente_id   INT UNSIGNED NOT NULL,
    devise_id  INT UNSIGNED NOT NULL,
    total      DECIMAL(18,3) NOT NULL,
    taux       DECIMAL(18,6) NULL,                          -- unités de cette devise pour 1 unité de référence
    PRIMARY KEY (vente_id, devise_id),
    CONSTRAINT fk_vt_vente FOREIGN KEY (vente_id) REFERENCES ventes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vente_lignes (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vente_id           INT UNSIGNED NOT NULL,
    produit_id         INT UNSIGNED NULL,
    conditionnement_id INT UNSIGNED NULL,
    libelle            VARCHAR(160) NOT NULL,               -- « Primus Grande 72 cl » au moment de la vente
    format             VARCHAR(80)  NULL,
    quantite           SMALLINT UNSIGNED NOT NULL,
    prix_unitaire      DECIMAL(18,3) NOT NULL,              -- dans la devise du ticket
    montant            DECIMAL(18,3) NOT NULL,
    prix_reference     DECIMAL(18,3) NULL,                  -- dans la devise de référence
    KEY idx_lignes_vente (vente_id),
    KEY idx_lignes_produit (produit_id),
    CONSTRAINT fk_vl_vente FOREIGN KEY (vente_id) REFERENCES ventes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paiements : un par devise et par moyen (espèces, mobile money, banque) ; « recu » est ce que le client
-- a donné, « montant » ce qui reste dans le compte (la monnaie rendue sort de la caisse de sa devise).
CREATE TABLE vente_paiements (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vente_id       INT UNSIGNED NOT NULL,
    moyen          ENUM('especes','mobile_money','banque') NOT NULL,
    compte_id      INT UNSIGNED NOT NULL,
    devise_id      INT UNSIGNED NOT NULL,
    recu           DECIMAL(18,3) NOT NULL,
    taux           DECIMAL(18,6) NULL,
    contre_valeur  DECIMAL(18,3) NULL,                      -- de « recu », dans la devise de référence
    KEY idx_vp_vente (vente_id),
    CONSTRAINT fk_vp_vente FOREIGN KEY (vente_id) REFERENCES ventes(id) ON DELETE CASCADE,
    CONSTRAINT fk_vp_compte FOREIGN KEY (compte_id) REFERENCES comptes_tresorerie(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Monnaie rendue (en espèces, dans la devise choisie, depuis la caisse de cette devise)
ALTER TABLE ventes
    ADD COLUMN rendu DECIMAL(18,3) NOT NULL DEFAULT 0 AFTER encaisse_reference,
    ADD COLUMN rendu_devise_id INT UNSIGNED NULL AFTER rendu,
    ADD COLUMN rendu_compte_id INT UNSIGNED NULL AFTER rendu_devise_id;

-- Annuler une vente (rembourser) est un droit à part
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT id, 'ventes.annuler' FROM roles WHERE code = 'gerant';
