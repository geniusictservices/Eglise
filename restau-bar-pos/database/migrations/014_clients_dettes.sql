-- Clients et dettes (cahier des charges, section 7) : ardoise à la caisse, garant, remboursements,
-- relances automatiques par SMS (Africa's Talking) et WhatsApp Business.
--
-- Une dette engage la personne qui a servi le client, sauf si un garant (un autre agent, le gérant,
-- le promoteur…) confirme qu'il s'en porte garant : il en devient alors le responsable.
-- Après l'échéance, le gérant peut mettre la dette à la charge du responsable : elle devient une dette
-- du personnel (client de type « personnel » lié à l'utilisateur), retenue plus tard sur la paie.

-- Délai de paiement proposé à la caisse (échéance = jour de la vente + délai)
ALTER TABLE etablissements ADD COLUMN credit_delai_jours SMALLINT UNSIGNED NOT NULL DEFAULT 7 AFTER photo_a_la_caisse;

CREATE TABLE clients (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nom               VARCHAR(128) NOT NULL,
    telephone         VARCHAR(32)  NULL,             -- format international : +243970000000
    email             VARCHAR(128) NULL,
    anniversaire      DATE         NULL,
    langue            CHAR(2)      NOT NULL DEFAULT 'fr',
    type              ENUM('particulier','habitue','vip','entreprise','revendeur','personnel') NOT NULL DEFAULT 'particulier',
    utilisateur_id    INT UNSIGNED NULL,             -- client « personnel » : le membre du personnel
    notes             TEXT         NULL,
    plafond           DECIMAL(18,3) NULL,            -- NULL : pas de plafond
    plafond_devise_id INT UNSIGNED NULL,
    canal             ENUM('sms','whatsapp') NOT NULL DEFAULT 'sms',   -- canal préféré des relances
    relances          TINYINT(1)   NOT NULL DEFAULT 1,
    bloque            TINYINT(1)   NOT NULL DEFAULT 0,                 -- crédit bloqué par le gérant
    actif             TINYINT(1)   NOT NULL DEFAULT 1,
    cree_par          INT UNSIGNED NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le        DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client_telephone (telephone),
    UNIQUE KEY uq_client_utilisateur (utilisateur_id),
    KEY idx_client_nom (nom),
    CONSTRAINT fk_client_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id),
    CONSTRAINT fk_client_plafond_devise FOREIGN KEY (plafond_devise_id) REFERENCES devises(id),
    CONSTRAINT fk_client_cree_par FOREIGN KEY (cree_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dettes (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id         INT UNSIGNED NOT NULL,
    etablissement_id  INT UNSIGNED NOT NULL,
    vente_id          INT UNSIGNED NULL,             -- ticket mis à crédit
    origine_id        INT UNSIGNED NULL,             -- dette d'un client mise à la charge du responsable
    devise_id         INT UNSIGNED NOT NULL,
    montant           DECIMAL(18,3) NOT NULL,
    reste             DECIMAL(18,3) NOT NULL,        -- tenu à jour à chaque remboursement
    echeance          DATE         NOT NULL,
    servi_par         INT UNSIGNED NULL,             -- qui a servi le client
    accorde_par       INT UNSIGNED NULL,             -- qui a mis la note à crédit (droit « caisse.credit »)
    garant_id         INT UNSIGNED NULL,             -- garant désigné…
    garant_statut     ENUM('en_attente','accepte','refuse') NULL,   -- …qui confirme plus tard
    garant_repondu_le DATETIME     NULL,
    responsable_id    INT UNSIGNED NULL,             -- servi_par, ou le garant une fois qu'il a accepté
    statut            ENUM('ouverte','soldee','annulee','remise','transferee') NOT NULL DEFAULT 'ouverte',
    note              VARCHAR(255) NULL,
    motif             VARCHAR(255) NULL,             -- annulation, remise ou mise à la charge
    cloturee_par      INT UNSIGNED NULL,
    cloturee_le       DATETIME     NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dette_client (client_id, statut),
    KEY idx_dette_responsable (responsable_id, statut),
    KEY idx_dette_garant (garant_id, garant_statut),
    KEY idx_dette_echeance (statut, echeance),
    KEY idx_dette_vente (vente_id),
    CONSTRAINT fk_dette_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_dette_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_dette_vente FOREIGN KEY (vente_id) REFERENCES ventes(id),
    CONSTRAINT fk_dette_origine FOREIGN KEY (origine_id) REFERENCES dettes(id),
    CONSTRAINT fk_dette_devise FOREIGN KEY (devise_id) REFERENCES devises(id),
    CONSTRAINT fk_dette_servi FOREIGN KEY (servi_par) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_dette_accorde FOREIGN KEY (accorde_par) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_dette_garant FOREIGN KEY (garant_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_dette_responsable FOREIGN KEY (responsable_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_dette_cloturee FOREIGN KEY (cloturee_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remboursement d'un client : un montant dans une devise, entré dans un compte de trésorerie,
-- réparti sur ses dettes (les plus anciennes d'abord), converti au taux du jour dans la devise de chaque dette
CREATE TABLE remboursements (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id         INT UNSIGNED NOT NULL,
    etablissement_id  INT UNSIGNED NOT NULL,
    compte_id         INT UNSIGNED NOT NULL,
    moyen             ENUM('caisse','mobile_money','banque') NOT NULL,   -- type du compte au moment du paiement
    devise_id         INT UNSIGNED NOT NULL,
    montant           DECIMAL(18,3) NOT NULL,
    taux              DECIMAL(18,6) NULL,
    contre_valeur     DECIMAL(18,3) NULL,            -- en devise de référence
    utilisateur_id    INT UNSIGNED NULL,
    appareil_id       INT UNSIGNED NULL,
    cle               CHAR(36)     NULL,             -- un double envoi ne compte pas deux fois
    statut            ENUM('valide','annule') NOT NULL DEFAULT 'valide',
    annule_le         DATETIME     NULL,
    annule_par        INT UNSIGNED NULL,
    motif_annulation  VARCHAR(255) NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_remboursement_cle (cle),
    KEY idx_remboursement_client (client_id),
    KEY idx_remboursement_date (cree_le),
    CONSTRAINT fk_remb_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_remb_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_remb_compte FOREIGN KEY (compte_id) REFERENCES comptes_tresorerie(id),
    CONSTRAINT fk_remb_devise FOREIGN KEY (devise_id) REFERENCES devises(id),
    CONSTRAINT fk_remb_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_remb_annule_par FOREIGN KEY (annule_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE remboursement_dettes (
    remboursement_id  INT UNSIGNED NOT NULL,
    dette_id          INT UNSIGNED NOT NULL,
    montant           DECIMAL(18,3) NOT NULL,        -- dans la devise de la dette
    PRIMARY KEY (remboursement_id, dette_id),
    KEY idx_rd_dette (dette_id),
    CONSTRAINT fk_rd_remboursement FOREIGN KEY (remboursement_id) REFERENCES remboursements(id) ON DELETE CASCADE,
    CONSTRAINT fk_rd_dette FOREIGN KEY (dette_id) REFERENCES dettes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des SMS et messages WhatsApp ; une relance par dette, étape et destinataire
CREATE TABLE messages (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    canal             ENUM('sms','whatsapp') NOT NULL,
    destinataire      VARCHAR(32)  NOT NULL,
    client_id         INT UNSIGNED NULL,
    utilisateur_id    INT UNSIGNED NULL,             -- destinataire membre du personnel (responsable, garant)
    dette_id          INT UNSIGNED NULL,
    evenement         ENUM('rappel_client','rappel_responsable','garant_demande','test') NOT NULL,
    etape             VARCHAR(8)   NULL,             -- J-1, J+1, J+7, J+30…
    texte             TEXT         NOT NULL,
    statut            ENUM('envoye','echec','simule') NOT NULL,
    fournisseur_id    VARCHAR(128) NULL,             -- identifiant du message chez Africa's Talking ou Meta
    erreur            VARCHAR(255) NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_message_etape (dette_id, evenement, etape, destinataire),
    KEY idx_message_date (cree_le),
    CONSTRAINT fk_message_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_message_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_message_dette FOREIGN KEY (dette_id) REFERENCES dettes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réglages de l'instance (table existante) : messagerie et relances ; les clés d'API ne sont jamais réaffichées
ALTER TABLE parametres
    ADD COLUMN modifie_par INT UNSIGNED NULL,
    ADD COLUMN modifie_le DATETIME NULL ON UPDATE CURRENT_TIMESTAMP;
INSERT IGNORE INTO parametres (cle, valeur) VALUES
    ('relances.actives', '1'),
    ('relances.etapes', '-1,1,7,30'),          -- jours par rapport à l'échéance : la veille, puis J+1, J+7, J+30
    ('relances.canal', 'prefere'),             -- prefere (canal du client, l'autre en secours), sms, whatsapp
    ('relances.responsable', '1'),             -- prévenir aussi le responsable de la dette
    ('relances.heure', '10');                  -- heure locale d'envoi (le script tourne chaque heure)

-- Droits des rôles modèles. « caisse.rembourser » : encaisser un remboursement à la caisse (sans ouvrir
-- la gestion) ; « dettes.rembourser » : l'enregistrer dans la gestion (virement reçu à la banque…)
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission FROM roles r
  JOIN (SELECT 'gerant' AS role, 'clients.voir' AS permission UNION ALL SELECT 'gerant', 'clients.modifier'
        UNION ALL SELECT 'gerant', 'dettes.voir' UNION ALL SELECT 'gerant', 'dettes.rembourser' UNION ALL SELECT 'gerant', 'dettes.gerer'
        UNION ALL SELECT 'superviseur', 'caisse.credit' UNION ALL SELECT 'superviseur', 'clients.voir'
        UNION ALL SELECT 'superviseur', 'dettes.voir' UNION ALL SELECT 'superviseur', 'dettes.rembourser'
        UNION ALL SELECT 'gerant', 'caisse.rembourser' UNION ALL SELECT 'superviseur', 'caisse.rembourser'
        UNION ALL SELECT 'caissier', 'caisse.rembourser' UNION ALL SELECT 'barman', 'caisse.rembourser'
        UNION ALL SELECT 'comptable', 'clients.voir' UNION ALL SELECT 'comptable', 'dettes.voir'
        UNION ALL SELECT 'comptable', 'dettes.rembourser') p ON p.role = r.code;
