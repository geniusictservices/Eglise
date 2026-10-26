-- Salle et cuisine (cahier des charges, sections 4, 5, 8.2 et 14) : plan de salle et tables, notes ouvertes
-- (on paie à la fin), partage de l'addition, circuit des commandes en cuisine (postes, écran, bons),
-- services horaires et happy hour, journée d'exploitation, réservations.
--
-- Une NOTE (table notes) est un ticket envoyé à une table ou nommé (« Bar — Patrick ») sans être payé ; plusieurs
-- serveurs y ajoutent des lignes (chaque ligne garde qui l'a commandée). Payer une note crée une ou plusieurs
-- ventes (partage par articles ou en parts égales) : chaque ligne garde la quantité déjà payée.
-- Une ligne partagée en parts égales est vendue en fractions (1/3 de Tilapia) : vente_lignes.quantite devient décimale.

-- Journée d'exploitation : une vente avant cette heure compte pour la veille (une vente à 2 h avec 7 h → la veille)
ALTER TABLE etablissements ADD COLUMN fin_journee TIME NOT NULL DEFAULT '05:00:00' AFTER sessions_caisse;

-- Tables du plan de salle, par zone (zone_id NULL : établissement sans zones). Position et taille en cases
-- d'une grille de 24 colonnes sur 16 rangées par zone.
CREATE TABLE salle_tables (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    zone_id          INT UNSIGNED NULL,
    nom              VARCHAR(32)  NOT NULL,                 -- « T4 », « VIP 2 », « Comptoir 1 »
    places           TINYINT UNSIGNED NOT NULL DEFAULT 4,
    forme            ENUM('carree','ronde','rectangle','banquette') NOT NULL DEFAULT 'carree',
    x                TINYINT UNSIGNED NOT NULL DEFAULT 0,
    y                TINYINT UNSIGNED NOT NULL DEFAULT 0,
    largeur          TINYINT UNSIGNED NOT NULL DEFAULT 3,
    hauteur          TINYINT UNSIGNED NOT NULL DEFAULT 3,
    actif            TINYINT(1)   NOT NULL DEFAULT 1,
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tables_etab (etablissement_id, zone_id, actif),
    CONSTRAINT fk_table_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_table_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Postes de la cuisine (grill, froid, pizza, pâtisserie, bar…), chacun avec son écran ; lieu_id : comptoir du
-- module stock qu'il sert (une catégorie routée vers ce comptoir arrive à ce poste)
CREATE TABLE postes_cuisine (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    nom              VARCHAR(60)  NOT NULL,
    lieu_id          INT UNSIGNED NULL,
    alerte_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 15,  -- en retard au-delà (temps depuis l'envoi)
    actif            TINYINT(1)   NOT NULL DEFAULT 1,
    position         SMALLINT     NOT NULL DEFAULT 0,
    KEY idx_postes_etab (etablissement_id, actif, position),
    CONSTRAINT fk_poste_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_poste_lieu FOREIGN KEY (lieu_id) REFERENCES lieux_stock(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Routage propre à la cuisine : catégorie (ou sa catégorie parente) → poste, par établissement.
-- Sans routage ici, le comptoir du module stock décide (poste rattaché à ce comptoir) ; sinon pas de cuisine.
CREATE TABLE cuisine_routage (
    etablissement_id INT UNSIGNED NOT NULL,
    categorie_id     INT UNSIGNED NOT NULL,
    poste_id         INT UNSIGNED NOT NULL,
    PRIMARY KEY (etablissement_id, categorie_id),
    CONSTRAINT fk_croute_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_croute_cat FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_croute_poste FOREIGN KEY (poste_id) REFERENCES postes_cuisine(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Écrans de cuisine autorisés par un gérant (comme les caisses partagées) : la tablette de la cuisine affiche
-- les commandes sans connexion ; le navigateur garde un jeton dont la base n'a que l'empreinte SHA-256.
CREATE TABLE ecrans_cuisine (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom              VARCHAR(80)  NOT NULL,
    etablissement_id INT UNSIGNED NOT NULL,
    poste_id         INT UNSIGNED NULL,                     -- NULL : tous les postes
    jeton            CHAR(64)     NOT NULL UNIQUE,
    autorise_par     INT UNSIGNED NULL,
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dernier_usage    DATETIME     NULL,
    actif            TINYINT(1)   NOT NULL DEFAULT 1,
    CONSTRAINT fk_ecran_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_ecran_poste FOREIGN KEY (poste_id) REFERENCES postes_cuisine(id) ON DELETE SET NULL,
    CONSTRAINT fk_ecran_utilisateur FOREIGN KEY (autorise_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réservations : nom, téléphone, personnes, heure, table, notes. La table est « réservée » sur le plan.
CREATE TABLE reservations (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    debut            DATETIME     NOT NULL,                 -- jour et heure réels (00:30 après minuit = le lendemain)
    nom              VARCHAR(80)  NOT NULL,
    telephone        VARCHAR(32)  NULL,
    personnes        TINYINT UNSIGNED NOT NULL DEFAULT 2,
    table_id         INT UNSIGNED NULL,
    notes            VARCHAR(255) NULL,
    statut           ENUM('prevue','arrivee','annulee','absente') NOT NULL DEFAULT 'prevue',
    cree_par         INT UNSIGNED NULL,
    cree_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_resa_etab (etablissement_id, debut),
    KEY idx_resa_table (table_id, debut),
    CONSTRAINT fk_resa_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_resa_table FOREIGN KEY (table_id) REFERENCES salle_tables(id) ON DELETE SET NULL,
    CONSTRAINT fk_resa_cree_par FOREIGN KEY (cree_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notes ouvertes. type « comptoir » : vente directe dont des lignes partent en cuisine (déjà payée).
CREATE TABLE notes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    type             ENUM('note','comptoir') NOT NULL DEFAULT 'note',
    table_id         INT UNSIGNED NULL,
    nom              VARCHAR(80)  NULL,                     -- note nommée : « Bar — Patrick »
    zone_id          INT UNSIGNED NULL,                     -- zone de la table : grille de prix des nouvelles lignes
    couverts         TINYINT UNSIGNED NULL,
    statut           ENUM('ouverte','payee','annulee','fusionnee') NOT NULL DEFAULT 'ouverte',
    serveur_id       INT UNSIGNED NULL,                     -- qui a ouvert la note (proposé « servi par » d'une ardoise)
    reservation_id   INT UNSIGNED NULL,
    ouverte_le       DATETIME     NOT NULL,
    addition_le      DATETIME     NULL,                     -- addition demandée (pré-note imprimée)
    addition_par     INT UNSIGNED NULL,
    parts            TINYINT UNSIGNED NULL,                 -- partage en parts égales en cours : nombre de parts
    parts_payees     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    fermee_le        DATETIME     NULL,
    fusionnee_dans   INT UNSIGNED NULL,
    vente_id         INT UNSIGNED NULL,                     -- type « comptoir » : la vente directe
    version          INT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_note_etab (etablissement_id, statut),
    KEY idx_note_table (table_id, statut),
    KEY idx_note_date (ouverte_le),
    CONSTRAINT fk_note_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_note_table FOREIGN KEY (table_id) REFERENCES salle_tables(id) ON DELETE SET NULL,
    CONSTRAINT fk_note_serveur FOREIGN KEY (serveur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bon de cuisine : un envoi d'une note à un poste (écran et bon imprimé de 80 mm)
CREATE TABLE note_envois (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id          INT UNSIGNED NOT NULL,
    poste_id         INT UNSIGNED NOT NULL,
    par              INT UNSIGNED NULL,
    cree_le          DATETIME     NOT NULL,
    KEY idx_envoi_poste (poste_id, cree_le),
    CONSTRAINT fk_envoi_cmd FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    CONSTRAINT fk_envoi_poste FOREIGN KEY (poste_id) REFERENCES postes_cuisine(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lignes d'une note : qui l'a commandée, précisions (cuisson, « sans piment »), allergie, envoi en plusieurs
-- temps (temps 2 : « à lancer » par le serveur), poste et statut en cuisine, quantité déjà payée, annulation.
CREATE TABLE note_lignes (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id                INT UNSIGNED NOT NULL,
    produit_id             INT UNSIGNED NULL,             -- le produit vendu (la taille pour Primus Grande)
    conditionnement_id     INT UNSIGNED NULL,
    categorie_id           INT UNSIGNED NULL,
    libelle                VARCHAR(160) NOT NULL,
    format                 VARCHAR(80)  NULL,
    quantite               SMALLINT UNSIGNED NOT NULL,
    quantite_payee         DECIMAL(10,3) NOT NULL DEFAULT 0,
    grille_prix_id         INT UNSIGNED NULL,             -- grille au moment de la commande (happy hour, VIP)
    prix                   DECIMAL(18,3) NOT NULL DEFAULT 0, -- prix unitaire dans la devise du ticket, à l'envoi
    prix_devises           VARCHAR(255) NULL,             -- prix unitaires à l'envoi par devise (JSON), si le format disparaît
    remarque               VARCHAR(160) NULL,
    allergie               VARCHAR(160) NULL,
    temps                  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    poste_id               INT UNSIGNED NULL,
    envoi_id               INT UNSIGNED NULL,
    statut                 ENUM('sans','attente','recu','preparation','pret','servi') NOT NULL DEFAULT 'sans',
    ajoute_par             INT UNSIGNED NULL,
    ajoute_le              DATETIME     NOT NULL,
    envoye_le              DATETIME     NULL,             -- arrivée au poste (tout de suite, ou quand le serveur lance)
    preparation_le         DATETIME     NULL,
    pret_le                DATETIME     NULL,
    servi_le               DATETIME     NULL,
    annulee_le             DATETIME     NULL,
    annulee_par            INT UNSIGNED NULL,             -- qui a demandé
    annulation_validee_par INT UNSIGNED NULL,             -- le supérieur qui a tapé son PIN
    annulation_motif       VARCHAR(160) NULL,
    perte                  TINYINT(1)   NOT NULL DEFAULT 0, -- annulée après le lancement en cuisine : compte comme perte
    KEY idx_nl_note (note_id),
    KEY idx_nl_poste (poste_id, statut),
    KEY idx_nl_envoi (envoi_id),
    CONSTRAINT fk_nl_cmd FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    CONSTRAINT fk_nl_poste FOREIGN KEY (poste_id) REFERENCES postes_cuisine(id) ON DELETE SET NULL,
    CONSTRAINT fk_nl_ajoute_par FOREIGN KEY (ajoute_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ventes créées depuis une note (plusieurs si l'addition est partagée), et ce que chaque vente a payé
CREATE TABLE note_paiements (
    vente_id           INT UNSIGNED NOT NULL,
    note_ligne_id      INT UNSIGNED NOT NULL,
    note_id            INT UNSIGNED NOT NULL,
    quantite           DECIMAL(10,3) NOT NULL,
    PRIMARY KEY (vente_id, note_ligne_id),
    KEY idx_np_note (note_id),
    CONSTRAINT fk_np_vente FOREIGN KEY (vente_id) REFERENCES ventes(id) ON DELETE CASCADE,
    CONSTRAINT fk_np_ligne FOREIGN KEY (note_ligne_id) REFERENCES note_lignes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vente_lignes MODIFY quantite DECIMAL(10,3) NOT NULL;

-- Réglages des services horaires. Un service est un nom des horaires de l'établissement (horaires.service_fr :
-- « Restaurant », « Lounge », « Club ») : ses heures restent dans les horaires (page publique), ses réglages ici.
CREATE TABLE services_horaires (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    nom_fr           VARCHAR(64)  NOT NULL,
    grille_prix_id   INT UNSIGNED NULL,                     -- NULL : grille de l'établissement
    tables           TINYINT(1)   NOT NULL DEFAULT 1,       -- plan de salle et notes ouvertes
    cuisine          TINYINT(1)   NOT NULL DEFAULT 1,       -- envoi en cuisine
    partage          TINYINT(1)   NOT NULL DEFAULT 1,       -- partage de l'addition
    rapide           TINYINT(1)   NOT NULL DEFAULT 0,       -- mode comptoir rapide (grandes tuiles)
    UNIQUE KEY uq_service (etablissement_id, nom_fr),
    CONSTRAINT fk_service_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_grille FOREIGN KEY (grille_prix_id) REFERENCES grilles_prix(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catégories (principales) vendues pendant le service ; aucune ligne : toutes (la cuisine fermée disparaît)
CREATE TABLE service_categories (
    service_id   INT UNSIGNED NOT NULL,
    categorie_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, categorie_id),
    CONSTRAINT fk_scat_service FOREIGN KEY (service_id) REFERENCES services_horaires(id) ON DELETE CASCADE,
    CONSTRAINT fk_scat_cat FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Happy hour : une grille de prix sur une plage horaire, certains jours (1 = lundi … 7 = dimanche)
CREATE TABLE happy_hours (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    nom_fr           VARCHAR(64)  NOT NULL,
    nom_en           VARCHAR(64)  NOT NULL,
    jours            VARCHAR(13)  NOT NULL DEFAULT '1,2,3,4,5,6,7',
    debut            TIME         NOT NULL,
    fin              TIME         NOT NULL,                 -- fin <= début : se termine le lendemain
    grille_prix_id   INT UNSIGNED NOT NULL,
    actif            TINYINT(1)   NOT NULL DEFAULT 1,
    CONSTRAINT fk_hh_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_hh_grille FOREIGN KEY (grille_prix_id) REFERENCES grilles_prix(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Droits : le gérant a tout ; le superviseur voit la salle, gère les réservations et suit la cuisine ;
-- le chef de cuisine règle ses postes et ses ruptures. Nouveau rôle modèle : cuisinier (écran de son poste).
INSERT IGNORE INTO roles (code, nom_fr, nom_en, description_fr, description_en, tout, position) VALUES
 ('cuisinier', 'Cuisinier', 'Cook', 'Voit les commandes de son poste et indique qu''un plat est prêt.', 'Sees the orders of their station and marks dishes ready.', 0, 12);

INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission FROM roles r JOIN (
    SELECT 'gerant' AS code, 'salle.voir' AS permission UNION ALL SELECT 'gerant', 'salle.plan'
    UNION ALL SELECT 'gerant', 'salle.reservations' UNION ALL SELECT 'gerant', 'salle.services'
    UNION ALL SELECT 'gerant', 'cuisine.voir' UNION ALL SELECT 'gerant', 'cuisine.ruptures' UNION ALL SELECT 'gerant', 'cuisine.parametrer'
    UNION ALL SELECT 'superviseur', 'salle.voir' UNION ALL SELECT 'superviseur', 'salle.reservations' UNION ALL SELECT 'superviseur', 'cuisine.voir'
    UNION ALL SELECT 'superviseur', 'caisse.annuler'
    UNION ALL SELECT 'chef_cuisine', 'cuisine.voir' UNION ALL SELECT 'chef_cuisine', 'cuisine.ruptures' UNION ALL SELECT 'chef_cuisine', 'cuisine.parametrer'
    UNION ALL SELECT 'cuisinier', 'cuisine.voir'
) p ON p.code = r.code;
