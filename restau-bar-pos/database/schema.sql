-- restau-bar-pos — schéma de la base d'une instance (phase 1)
-- MySQL 8 / MariaDB 10.6+, encodage utf8mb4.

SET NAMES utf8mb4;

-- Paramètres généraux de l'instance (nom du client, langue par défaut, fuseau horaire…)
CREATE TABLE parametres (
    cle    VARCHAR(64) PRIMARY KEY,
    valeur TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Devises configurables. Une seule est la devise de référence.
CREATE TABLE devises (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code           CHAR(3)      NOT NULL UNIQUE,          -- code ISO : USD, CDF, EUR, RWF…
    symbole        VARCHAR(8)   NOT NULL,
    nom_fr         VARCHAR(64)  NOT NULL,
    nom_en         VARCHAR(64)  NOT NULL,
    decimales      TINYINT UNSIGNED NOT NULL DEFAULT 2,
    arrondi        DECIMAL(14,4) NOT NULL DEFAULT 0,      -- 0 = pas d'arrondi, 100 = au 100 le plus proche
    symbole_avant  TINYINT(1)   NOT NULL DEFAULT 0,       -- « $5 » ou « 5 FC »
    est_reference  TINYINT(1)   NOT NULL DEFAULT 0,
    active         TINYINT(1)   NOT NULL DEFAULT 1,
    position       SMALLINT     NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique des taux : nombre d'unités de la devise pour 1 unité de la devise de référence.
-- Le taux en vigueur est le plus récent dont valable_depuis est passé.
CREATE TABLE taux_change (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    devise_id      INT UNSIGNED NOT NULL,
    taux           DECIMAL(18,6) NOT NULL,
    valable_depuis DATETIME     NOT NULL,
    cree_par       INT UNSIGNED NULL,
    cree_le        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_taux_devise_date (devise_id, valable_depuis),
    CONSTRAINT fk_taux_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grilles de prix : normal, VIP, nuit, gros…
CREATE TABLE grilles_prix (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code    VARCHAR(32) NOT NULL UNIQUE,
    nom_fr  VARCHAR(64) NOT NULL,
    nom_en  VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE etablissements (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug             VARCHAR(64)  NOT NULL UNIQUE,
    nom              VARCHAR(128) NOT NULL,
    type             ENUM('restaurant_bar','lounge','nightclub') NOT NULL,
    slogan_fr        VARCHAR(255) NULL,
    slogan_en        VARCHAR(255) NULL,
    description_fr   TEXT NULL,
    description_en   TEXT NULL,
    adresse          VARCHAR(255) NULL,
    ville            VARCHAR(128) NULL,
    lien_carte       VARCHAR(512) NULL,
    telephone        VARCHAR(32)  NULL,
    whatsapp         VARCHAR(32)  NULL,
    email            VARCHAR(128) NULL,
    facebook         VARCHAR(255) NULL,
    instagram        VARCHAR(255) NULL,
    tiktok           VARCHAR(255) NULL,
    logo             VARCHAR(255) NULL,
    couverture       VARCHAR(255) NULL,
    couleur          CHAR(7)      NULL,          -- couleur d'accent de la vitrine, ex. #C8553D
    grille_prix_id   INT UNSIGNED NOT NULL,       -- grille appliquée par défaut
    devise_affichage_id INT UNSIGNED NULL,        -- devise affichée par défaut sur la vitrine
    actif            TINYINT(1)   NOT NULL DEFAULT 1,
    position         SMALLINT     NOT NULL DEFAULT 0,
    CONSTRAINT fk_etab_grille FOREIGN KEY (grille_prix_id) REFERENCES grilles_prix(id),
    CONSTRAINT fk_etab_devise FOREIGN KEY (devise_affichage_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Devises acceptées (et affichées) par établissement
CREATE TABLE etablissement_devises (
    etablissement_id INT UNSIGNED NOT NULL,
    devise_id        INT UNSIGNED NOT NULL,
    PRIMARY KEY (etablissement_id, devise_id),
    CONSTRAINT fk_ed_etab   FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_ed_devise FOREIGN KEY (devise_id)        REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horaires par service. jour : 1 = lundi … 7 = dimanche.
-- Si fermeture <= ouverture, le service se termine le lendemain (ex. 22:00 → 05:00).
CREATE TABLE horaires (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    service_fr       VARCHAR(64) NOT NULL,
    service_en       VARCHAR(64) NOT NULL,
    jour             TINYINT UNSIGNED NOT NULL,
    ouverture        TIME NOT NULL,
    fermeture        TIME NOT NULL,
    KEY idx_horaires_etab (etablissement_id, jour),
    CONSTRAINT fk_horaires_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zones d'un établissement (salle, terrasse, VIP…), chacune pouvant avoir sa grille de prix
CREATE TABLE zones (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    code             VARCHAR(32) NOT NULL,
    nom_fr           VARCHAR(64) NOT NULL,
    nom_en           VARCHAR(64) NOT NULL,
    grille_prix_id   INT UNSIGNED NULL,           -- NULL = grille de l'établissement
    UNIQUE KEY uq_zone (etablissement_id, code),
    CONSTRAINT fk_zone_etab   FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_zone_grille FOREIGN KEY (grille_prix_id)   REFERENCES grilles_prix(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id      INT UNSIGNED NULL,
    nom_fr         VARCHAR(128) NOT NULL,
    nom_en         VARCHAR(128) NOT NULL,
    position       SMALLINT NOT NULL DEFAULT 0,
    visible_public TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE produits (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    categorie_id   INT UNSIGNED NOT NULL,
    nom_fr         VARCHAR(128) NOT NULL,
    nom_en         VARCHAR(128) NOT NULL,
    description_fr TEXT NULL,
    description_en TEXT NULL,
    photo          VARCHAR(255) NULL,
    unite_base     VARCHAR(16)  NOT NULL DEFAULT 'piece',   -- bouteille, canette, cl, l, g, kg, piece…
    badges         VARCHAR(128) NULL,                       -- liste séparée par des virgules : nouveau,populaire,epice
    allergenes_fr  VARCHAR(255) NULL,
    allergenes_en  VARCHAR(255) NULL,
    visible_public TINYINT(1) NOT NULL DEFAULT 1,
    disponible     TINYINT(1) NOT NULL DEFAULT 1,           -- 0 = en rupture
    actif          TINYINT(1) NOT NULL DEFAULT 1,
    position       SMALLINT NOT NULL DEFAULT 0,
    KEY idx_produits_cat (categorie_id, position),
    CONSTRAINT fk_produit_cat FOREIGN KEY (categorie_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Produits proposés par chaque établissement
CREATE TABLE produit_etablissements (
    produit_id       INT UNSIGNED NOT NULL,
    etablissement_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (produit_id, etablissement_id),
    CONSTRAINT fk_pe_produit FOREIGN KEY (produit_id)       REFERENCES produits(id) ON DELETE CASCADE,
    CONSTRAINT fk_pe_etab    FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conditionnements : casier = 12 bouteilles, dose = 4 cl… exprimés en unité de base du produit
CREATE TABLE conditionnements (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produit_id     INT UNSIGNED NOT NULL,
    nom_fr         VARCHAR(64) NOT NULL,
    nom_en         VARCHAR(64) NOT NULL,
    quantite_base  DECIMAL(14,3) NOT NULL,
    vendable       TINYINT(1) NOT NULL DEFAULT 1,
    achetable      TINYINT(1) NOT NULL DEFAULT 0,
    code_barres    VARCHAR(64) NULL,
    position       SMALLINT NOT NULL DEFAULT 0,
    KEY idx_cond_produit (produit_id, position),
    CONSTRAINT fk_cond_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prix d'un conditionnement dans une grille et une devise.
-- Un prix absent dans une devise est calculé depuis la devise de référence avec le taux du moment.
CREATE TABLE prix (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conditionnement_id INT UNSIGNED NOT NULL,
    grille_prix_id     INT UNSIGNED NOT NULL,
    devise_id          INT UNSIGNED NOT NULL,
    montant            DECIMAL(14,2) NOT NULL,
    UNIQUE KEY uq_prix (conditionnement_id, grille_prix_id, devise_id),
    CONSTRAINT fk_prix_cond   FOREIGN KEY (conditionnement_id) REFERENCES conditionnements(id) ON DELETE CASCADE,
    CONSTRAINT fk_prix_grille FOREIGN KEY (grille_prix_id)     REFERENCES grilles_prix(id),
    CONSTRAINT fk_prix_devise FOREIGN KEY (devise_id)          REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comptes de l'espace de gestion (les rôles détaillés arrivent en phase 2)
CREATE TABLE utilisateurs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom          VARCHAR(128) NOT NULL,
    email        VARCHAR(128) NOT NULL UNIQUE,
    mot_de_passe VARCHAR(255) NOT NULL,
    langue       CHAR(2)      NOT NULL DEFAULT 'fr',
    actif        TINYINT(1)   NOT NULL DEFAULT 1,
    derniere_connexion DATETIME NULL,
    cree_le      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Codes QR : chaque code renvoie vers /q/<code>, qui compte le scan puis redirige vers le menu
CREATE TABLE qr_codes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code             VARCHAR(16)  NOT NULL UNIQUE,
    etablissement_id INT UNSIGNED NOT NULL,
    zone_id          INT UNSIGNED NULL,
    libelle          VARCHAR(128) NOT NULL,
    actif            TINYINT(1)   NOT NULL DEFAULT 1,
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qr_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_qr_zone FOREIGN KEY (zone_id)          REFERENCES zones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qr_scans (
    qr_code_id INT UNSIGNED NOT NULL,
    jour       DATE NOT NULL,
    nombre     INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (qr_code_id, jour),
    CONSTRAINT fk_scan_qr FOREIGN KEY (qr_code_id) REFERENCES qr_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
