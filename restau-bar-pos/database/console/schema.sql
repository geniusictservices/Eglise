-- Base de la console super admin (admin.<domaine>) : séparée des bases des instances.
-- Installation : php bin/console-admin.php installer (ou la page d'installation de la console).
-- Évolutions : database/console/migrations/NNN_nom.sql (php bin/console-admin.php migrer).
-- La console ne garde aucune donnée commerciale des clients (ventes, clients, dettes) : seulement l'abonnement.

-- Comptes super admin (e-mail et mot de passe de 12 caractères au moins)
CREATE TABLE super_admins (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                      VARCHAR(128) NOT NULL,
    email                    VARCHAR(128) NOT NULL UNIQUE,
    mot_de_passe             VARCHAR(255) NOT NULL,
    langue                   CHAR(2)      NOT NULL DEFAULT 'fr',
    actif                    TINYINT(1)   NOT NULL DEFAULT 1,
    connexion_essais         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    connexion_bloquee_jusqua DATETIME     NULL,
    derniere_connexion       DATETIME     NULL,
    cree_le                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formules d'abonnement : modules inclus (codes de App\Core\Modules::offre()), limites, prix en dollars
CREATE TABLE formules (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                VARCHAR(80)   NOT NULL,
    description        VARCHAR(255)  NULL,
    modules            TEXT          NOT NULL,            -- JSON : ["caisse","ventes",…]
    max_etablissements SMALLINT UNSIGNED NULL,             -- NULL = sans limite
    max_utilisateurs   SMALLINT UNSIGNED NULL,
    prix_mois          DECIMAL(10,2) NULL,                 -- NULL = période non proposée
    prix_trimestre     DECIMAL(10,2) NULL,
    prix_annee         DECIMAL(10,2) NULL,
    sms_inclus         INT UNSIGNED  NOT NULL DEFAULT 0,   -- SMS compris par mois (au-delà : refacturés)
    actif              TINYINT(1)    NOT NULL DEFAULT 1,
    cree_le            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le         DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clients : une instance chacun, sur son sous-domaine, avec sa propre base.
-- Le mot de passe de la base n'est jamais gardé ici : il est seulement dans config/instances/<sous-domaine>.php.
CREATE TABLE clients (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                  VARCHAR(128) NOT NULL,
    sous_domaine         VARCHAR(63)  NOT NULL UNIQUE,
    contact_nom          VARCHAR(128) NULL,
    contact_telephone    VARCHAR(32)  NULL,
    contact_email        VARCHAR(128) NULL,
    langue               CHAR(2)      NOT NULL DEFAULT 'fr',
    notes                TEXT         NULL,
    formule_id           INT UNSIGNED NULL,
    periode              ENUM('mois','trimestre','annee') NOT NULL DEFAULT 'mois',
    debut                DATE         NULL,
    echeance             DATE         NULL,                -- date à laquelle le paiement suivant est dû
    essai                TINYINT(1)   NOT NULL DEFAULT 0,  -- en essai gratuit jusqu'à l'échéance
    jours_grace          SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    jours_lecture_seule  SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    statut_force         ENUM('actif','lecture_seule','suspendu') NULL,  -- décision à la main ; NULL = selon les dates
    statut_force_motif   VARCHAR(255) NULL,
    statut_force_le      DATETIME     NULL,
    expediteur_sms       VARCHAR(11)  NULL,                -- sender ID propre au client (sinon celui de la plateforme)
    db_hote              VARCHAR(128) NULL,
    db_port              SMALLINT UNSIGNED NULL,
    db_nom               VARCHAR(64)  NULL,
    db_utilisateur       VARCHAR(64)  NULL,
    installe_le          DATETIME     NULL,                -- NULL : instance pas encore installée
    cree_le              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le           DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_client_formule FOREIGN KEY (formule_id) REFERENCES formules(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paiements notés à la main ; chacun prolonge l'échéance d'une ou plusieurs périodes
CREATE TABLE paiements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED  NOT NULL,
    montant         DECIMAL(12,2) NOT NULL,
    devise          CHAR(3)       NOT NULL,                -- USD ou CDF
    moyen           ENUM('mobile_money','virement','especes') NOT NULL,
    reference       VARCHAR(64)   NULL,
    paye_le         DATE          NOT NULL,
    periode         ENUM('mois','trimestre','annee') NOT NULL,
    periodes        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    echeance_avant  DATE          NULL,
    echeance_apres  DATE          NOT NULL,
    essai_avant     TINYINT(1)    NOT NULL DEFAULT 0,
    periode_avant   ENUM('mois','trimestre','annee') NULL,
    note            VARCHAR(255)  NULL,
    saisi_par       INT UNSIGNED  NULL,
    cree_le         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    annule_le       DATETIME      NULL,
    annule_par      INT UNSIGNED  NULL,
    annule_motif    VARCHAR(255)  NULL,
    KEY idx_paiement_date (paye_le),
    CONSTRAINT fk_paiement_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_paiement_admin FOREIGN KEY (saisi_par) REFERENCES super_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages de la console : relances d'abonnement au propriétaire (SMS, e-mail) et messages d'essai.
-- Une relance (client, échéance, étape, canal) ne part qu'une fois.
CREATE TABLE messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NULL,
    canal           ENUM('sms','whatsapp','email') NOT NULL,
    destinataire    VARCHAR(128) NOT NULL,
    evenement       VARCHAR(32)  NOT NULL,                 -- relance_abonnement, test
    etape           VARCHAR(16)  NULL,                     -- J-7, J-3, J-1, J+1, lecture_seule, suspendu
    echeance        DATE         NULL,
    texte           TEXT         NOT NULL,
    statut          ENUM('envoye','echec','simule') NOT NULL,
    fournisseur_id  VARCHAR(128) NULL,
    erreur          VARCHAR(255) NULL,
    cree_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_message_relance (client_id, evenement, echeance, etape, canal),
    KEY idx_message_date (cree_le),
    CONSTRAINT fk_message_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal de la console : qui a fait quoi (paiements, statuts, sauvegardes, mises à jour, assistance…)
CREATE TABLE journal (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    super_admin_id  INT UNSIGNED NULL,
    client_id       INT UNSIGNED NULL,
    action          VARCHAR(64)  NOT NULL,
    details         TEXT         NULL,                     -- JSON
    ip              VARCHAR(45)  NULL,
    cree_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_journal_date (cree_le),
    KEY idx_journal_client (client_id, cree_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réglages de la plateforme : API de messagerie (clés jamais réaffichées), contact, relances, installation
CREATE TABLE parametres (
    cle          VARCHAR(64) PRIMARY KEY,
    valeur       TEXT        NULL,
    modifie_par  INT UNSIGNED NULL,
    modifie_le   DATETIME    NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO parametres (cle, valeur) VALUES
    ('plateforme.nom', 'restau-bar-pos'),
    ('whatsapp.version', 'v21.0'),
    ('whatsapp.modele_rappel_client', 'rappel_client'),
    ('whatsapp.modele_rappel_responsable', 'rappel_responsable'),
    ('whatsapp.modele_garant_demande', 'garant_demande'),
    ('whatsapp.langue_fr', 'fr'),
    ('whatsapp.langue_en', 'en'),
    ('relances.actives', '1'),
    ('relances.etapes', '-7,-3,-1,1'),
    ('relances.heure', '9'),
    ('installation.db_hote', 'localhost');

-- Formules proposées au départ (modifiables dans la console)
INSERT INTO formules (nom, description, modules, max_etablissements, max_utilisateurs, prix_mois, prix_trimestre, prix_annee, sms_inclus) VALUES
    ('Essentiel', 'Un bar : caisse, ventes et ardoises, carte et vitrine.', '["caisse","ventes","carte","rapports"]', 1, 8, 25.00, 70.00, 250.00, 100),
    ('Pro', 'Plusieurs établissements, finance, stock et achats.', '["caisse","ventes","achats","stock","finance","rapports","carte"]', 3, 25, 49.00, 135.00, 490.00, 300),
    ('Club', 'Tout, avec les modules nightclub, événements et cashless.', '["caisse","ventes","achats","stock","personnel","finance","rapports","carte","cuisine","nightclub","evenements","cashless"]', NULL, NULL, 89.00, 240.00, 890.00, 800);
