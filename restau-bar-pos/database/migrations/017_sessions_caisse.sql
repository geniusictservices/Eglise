-- Sessions de caisse (cahier des charges, sections 3 et 17), au choix de chaque établissement :
-- la personne ouvre sa session avec le fond de caisse compté par devise, encaisse, puis clôture en comptant
-- ce qu'elle a dans sa caisse. L'écart (compté − attendu) est écrit dans la trésorerie (« ajustement ») ;
-- un manquant peut ensuite être retenu sur la paie (module Personnel et paie).

ALTER TABLE etablissements ADD COLUMN sessions_caisse TINYINT(1) NOT NULL DEFAULT 0 AFTER credit_delai_jours;

CREATE TABLE sessions_caisse (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    etablissement_id  INT UNSIGNED NOT NULL,
    utilisateur_id    INT UNSIGNED NOT NULL,           -- la personne qui encaisse
    appareil_id       INT UNSIGNED NULL,
    statut            ENUM('ouverte','fermee') NOT NULL DEFAULT 'ouverte',
    ouverte_le        DATETIME     NOT NULL,
    fermee_le         DATETIME     NULL,
    fermee_par        INT UNSIGNED NULL,               -- elle-même, ou le gérant pour une session oubliée
    note              VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_session_personne (etablissement_id, utilisateur_id, statut),
    KEY idx_session_date (ouverte_le),
    CONSTRAINT fk_session_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_session_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id),
    CONSTRAINT fk_session_fermee_par FOREIGN KEY (fermee_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pour chaque caisse (compte de type caisse) et chaque devise : fond, attendu, compté, écart
CREATE TABLE session_caisse_comptes (
    session_id        INT UNSIGNED NOT NULL,
    compte_id         INT UNSIGNED NOT NULL,
    devise_id         INT UNSIGNED NOT NULL,
    fond              DECIMAL(18,3) NOT NULL DEFAULT 0,
    attendu           DECIMAL(18,3) NULL,              -- fond + entrées − sorties de la session, à la clôture
    compte            DECIMAL(18,3) NULL,              -- compté à la clôture
    ecart             DECIMAL(18,3) NULL,              -- compté − attendu (négatif : manquant)
    PRIMARY KEY (session_id, compte_id, devise_id),
    CONSTRAINT fk_scc_session FOREIGN KEY (session_id) REFERENCES sessions_caisse(id) ON DELETE CASCADE,
    CONSTRAINT fk_scc_compte FOREIGN KEY (compte_id) REFERENCES comptes_tresorerie(id),
    CONSTRAINT fk_scc_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ventes ADD COLUMN session_id INT UNSIGNED NULL AFTER appareil_id, ADD KEY idx_vente_session (session_id);
ALTER TABLE remboursements ADD COLUMN session_id INT UNSIGNED NULL AFTER appareil_id, ADD KEY idx_remb_session (session_id);

-- Le gérant peut clôturer la session oubliée de quelqu'un d'autre
INSERT IGNORE INTO role_permissions (role_id, permission) SELECT id, 'caisse.cloturer' FROM roles WHERE code = 'gerant';
