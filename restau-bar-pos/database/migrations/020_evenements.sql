-- Événements (cahier des charges, section 13.5) : les soirées d'un lounge ou d'un nightclub, avec leur date,
-- leur thème, les DJ ou artistes et leur budget. Les coûts (cachets, publicité, location de matériel) sont des
-- dépenses ordinaires (module Finance, compte payeur et devise) rattachées à la soirée ; les recettes sont les
-- ventes de l'établissement pendant la soirée (de debut à fin). Rentabilité = recettes − coûts.

CREATE TABLE evenements (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    etablissement_id  INT UNSIGNED NOT NULL,
    titre             VARCHAR(128) NOT NULL,             -- « Afro Night », « Soirée blanche »
    theme             VARCHAR(255) NULL,                 -- « Amapiano et rumba, dress code blanc »
    artistes          VARCHAR(500) NULL,                 -- DJ et artistes, un par ligne ou séparés par des virgules
    debut             DATETIME     NOT NULL,             -- ouverture des portes
    fin               DATETIME     NOT NULL,             -- fermeture (souvent le lendemain matin)
    budget            DECIMAL(18,3) NULL,                -- coûts prévus
    budget_devise_id  INT UNSIGNED NULL,
    description       TEXT         NULL,
    statut            ENUM('prevu','annule') NOT NULL DEFAULT 'prevu',   -- passé ou à venir : selon les dates
    cree_par          INT UNSIGNED NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le        DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_evenement_etab (etablissement_id, debut),
    KEY idx_evenement_debut (debut),
    CONSTRAINT fk_evenement_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_evenement_devise FOREIGN KEY (budget_devise_id) REFERENCES devises(id),
    CONSTRAINT fk_evenement_cree_par FOREIGN KEY (cree_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une dépense peut être un coût d'une soirée (cachet du DJ, affiches, location de la sono)
ALTER TABLE depenses
    ADD COLUMN evenement_id INT UNSIGNED NULL AFTER etablissement_id,
    ADD KEY idx_depense_evenement (evenement_id),
    ADD CONSTRAINT fk_depense_evenement FOREIGN KEY (evenement_id) REFERENCES evenements(id) ON DELETE SET NULL;

-- Catégories de dépenses utiles aux soirées (« Artistes et DJ » existe déjà dans la démonstration)
INSERT INTO categories_depenses (nom_fr, nom_en, position)
SELECT 'Artistes et DJ', 'Artists and DJs', 7 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM categories_depenses WHERE nom_fr = 'Artistes et DJ');
INSERT INTO categories_depenses (nom_fr, nom_en, position)
SELECT 'Publicité', 'Advertising', (SELECT COALESCE(MAX(c.position), 0) + 1 FROM categories_depenses c) FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM categories_depenses WHERE nom_fr = 'Publicité');
INSERT INTO categories_depenses (nom_fr, nom_en, position)
SELECT 'Location de matériel', 'Equipment rental', (SELECT COALESCE(MAX(c.position), 0) + 1 FROM categories_depenses c) FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM categories_depenses WHERE nom_fr = 'Location de matériel');

-- Droits des rôles modèles : le gérant prépare les soirées, le superviseur et le comptable les consultent
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission FROM roles r
  JOIN (SELECT 'gerant' AS role, 'evenements.voir' AS permission UNION ALL SELECT 'gerant', 'evenements.gerer'
        UNION ALL SELECT 'superviseur', 'evenements.voir' UNION ALL SELECT 'comptable', 'evenements.voir') p ON p.role = r.code;
