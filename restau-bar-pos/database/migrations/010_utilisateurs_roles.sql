-- Utilisateurs, rôles et permissions (cahier des charges, section 6), caisses partagées et journal.
-- Connexion : PIN de 6 chiffres à la caisse ; téléphone ou e-mail et mot de passe pour la gestion.

-- Un serveur peut n'avoir ni e-mail ni mot de passe : seulement son PIN pour la caisse
ALTER TABLE utilisateurs
    MODIFY email VARCHAR(128) NULL,
    MODIFY mot_de_passe VARCHAR(255) NULL,
    ADD COLUMN telephone VARCHAR(32) NULL AFTER email,
    ADD COLUMN pin VARCHAR(255) NULL AFTER mot_de_passe,
    ADD COLUMN pin_essais TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER pin,
    ADD COLUMN pin_bloque_le DATETIME NULL AFTER pin_essais,
    ADD COLUMN connexion_essais TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER pin_bloque_le,
    ADD COLUMN connexion_bloquee_jusqua DATETIME NULL AFTER connexion_essais,
    ADD COLUMN poste VARCHAR(80) NULL AFTER telephone,
    ADD COLUMN modifie_le DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    ADD UNIQUE KEY uq_utilisateurs_telephone (telephone);

-- Rôles : modèles proposés à l'installation, modifiables ; le propriétaire a tous les droits
CREATE TABLE roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(40)  NOT NULL UNIQUE,
    nom_fr      VARCHAR(80)  NOT NULL,
    nom_en      VARCHAR(80)  NOT NULL,
    description_fr VARCHAR(255) NULL,
    description_en VARCHAR(255) NULL,
    tout        TINYINT(1)   NOT NULL DEFAULT 0,   -- toutes les permissions, présentes et futures
    position    SMALLINT     NOT NULL DEFAULT 0,
    cree_le     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions d'un rôle : codes définis dans app/Core/Permissions.php
CREATE TABLE role_permissions (
    role_id     INT UNSIGNED NOT NULL,
    permission  VARCHAR(64)  NOT NULL,
    PRIMARY KEY (role_id, permission),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plusieurs rôles par personne, chacun avec sa portée (NULL = tous les établissements)
CREATE TABLE utilisateur_roles (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id   INT UNSIGNED NOT NULL,
    role_id          INT UNSIGNED NOT NULL,
    etablissement_id INT UNSIGNED NULL,
    UNIQUE KEY uq_ur (utilisateur_id, role_id, etablissement_id),
    CONSTRAINT fk_ur_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caisses partagées : seuls les appareils autorisés par un gérant acceptent la connexion par PIN
CREATE TABLE appareils_caisse (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom              VARCHAR(80)  NOT NULL,
    etablissement_id INT UNSIGNED NOT NULL,
    jeton            CHAR(64)     NOT NULL UNIQUE,  -- empreinte SHA-256 du jeton gardé dans le cookie de l'appareil
    autorise_par     INT UNSIGNED NULL,
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dernier_usage    DATETIME     NULL,
    pin_echecs       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    actif            TINYINT(1)   NOT NULL DEFAULT 1,
    CONSTRAINT fk_appareil_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_appareil_utilisateur FOREIGN KEY (autorise_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal : qui a fait quoi, quand, sur quel appareil
CREATE TABLE journal (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT UNSIGNED NULL,
    action         VARCHAR(64)  NOT NULL,
    cible          VARCHAR(255) NULL,
    details        TEXT         NULL,
    appareil_id    INT UNSIGNED NULL,
    ip             VARCHAR(45)  NULL,
    cree_le        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_journal_date (cree_le),
    KEY idx_journal_utilisateur (utilisateur_id, cree_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rôles modèles (section 6.1)
INSERT INTO roles (code, nom_fr, nom_en, description_fr, description_en, tout, position) VALUES
 ('proprietaire', 'Propriétaire / Direction', 'Owner / Management', 'Tout, y compris les rapports financiers et la configuration.', 'Everything, including financial reports and settings.', 1, 1),
 ('gerant', 'Gérant', 'Manager', 'Valide les crédits et remises, fixe les taux, gère le personnel, la carte et la trésorerie de son établissement.', 'Approves credit and discounts, sets rates, manages staff, menu and cash of the venue.', 0, 2),
 ('superviseur', 'Superviseur / chef de service', 'Supervisor', 'Surveille un service, accorde de petites remises.', 'Oversees a service, grants small discounts.', 0, 3),
 ('caissier', 'Caissier', 'Cashier', 'Encaisse et reçoit les remboursements de dettes.', 'Takes payments and debt repayments.', 0, 4),
 ('serveur', 'Serveur', 'Waiter', 'Prend les commandes sur ses tables.', 'Takes orders at their tables.', 0, 5),
 ('barman', 'Barman', 'Bartender', 'Vend au comptoir.', 'Sells at the bar.', 0, 6),
 ('comptable', 'Comptable', 'Accountant', 'Dépenses, trésorerie, rapports ; aucun accès à la caisse.', 'Expenses, cash and reports; no till access.', 0, 7),
 ('rh', 'RH', 'HR', 'Employés, plannings, présence, salaires, avances.', 'Employees, schedules, attendance, payroll, advances.', 0, 8),
 ('chef_cuisine', 'Chef de cuisine', 'Head chef', 'Fiches techniques, ruptures, commandes, inventaires.', 'Recipes, stock-outs, orders, counts.', 0, 9),
 ('magasinier', 'Magasinier', 'Storekeeper', 'Réceptions, transferts et inventaire du dépôt.', 'Deliveries, transfers and warehouse counts.', 0, 10),
 ('acheteur', 'Acheteur / économe', 'Buyer', 'Achats au marché, réceptions.', 'Market purchases, deliveries.', 0, 11);

INSERT INTO role_permissions (role_id, permission)
SELECT r.id, p.permission FROM roles r JOIN (
    SELECT 'gerant' AS code, 'caisse.vendre' AS permission UNION ALL SELECT 'gerant', 'caisse.encaisser'
    UNION ALL SELECT 'gerant', 'caisse.credit' UNION ALL SELECT 'gerant', 'caisse.remise' UNION ALL SELECT 'gerant', 'caisse.annuler'
    UNION ALL SELECT 'gerant', 'caisse.appareils' UNION ALL SELECT 'gerant', 'ventes.voir'
    UNION ALL SELECT 'gerant', 'achats.voir' UNION ALL SELECT 'gerant', 'stock.voir' UNION ALL SELECT 'gerant', 'personnel.voir'
    UNION ALL SELECT 'gerant', 'finance.voir' UNION ALL SELECT 'gerant', 'finance.operations' UNION ALL SELECT 'gerant', 'finance.taux'
    UNION ALL SELECT 'gerant', 'rapports.voir' UNION ALL SELECT 'gerant', 'carte.voir' UNION ALL SELECT 'gerant', 'carte.modifier'
    UNION ALL SELECT 'gerant', 'carte.prix'
    UNION ALL SELECT 'superviseur', 'caisse.vendre' UNION ALL SELECT 'superviseur', 'caisse.encaisser' UNION ALL SELECT 'superviseur', 'caisse.remise'
    UNION ALL SELECT 'superviseur', 'ventes.voir' UNION ALL SELECT 'superviseur', 'carte.voir'
    UNION ALL SELECT 'caissier', 'caisse.vendre' UNION ALL SELECT 'caissier', 'caisse.encaisser'
    UNION ALL SELECT 'serveur', 'caisse.vendre'
    UNION ALL SELECT 'barman', 'caisse.vendre' UNION ALL SELECT 'barman', 'caisse.encaisser'
    UNION ALL SELECT 'comptable', 'finance.voir' UNION ALL SELECT 'comptable', 'finance.operations' UNION ALL SELECT 'comptable', 'finance.comptes'
    UNION ALL SELECT 'comptable', 'rapports.voir' UNION ALL SELECT 'comptable', 'rapports.marges' UNION ALL SELECT 'comptable', 'ventes.voir'
    UNION ALL SELECT 'comptable', 'achats.voir'
    UNION ALL SELECT 'rh', 'personnel.voir' UNION ALL SELECT 'rh', 'personnel.paie'
    UNION ALL SELECT 'chef_cuisine', 'stock.voir' UNION ALL SELECT 'chef_cuisine', 'achats.voir' UNION ALL SELECT 'chef_cuisine', 'carte.voir'
    UNION ALL SELECT 'magasinier', 'stock.voir'
    UNION ALL SELECT 'acheteur', 'achats.voir' UNION ALL SELECT 'acheteur', 'stock.voir'
) p ON p.code = r.code;

-- Les comptes existants (l'administrateur créé à l'installation) deviennent propriétaires
INSERT INTO utilisateur_roles (utilisateur_id, role_id, etablissement_id)
SELECT u.id, r.id, NULL FROM utilisateurs u JOIN roles r ON r.code = 'proprietaire';
