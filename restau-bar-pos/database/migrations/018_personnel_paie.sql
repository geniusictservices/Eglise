-- Personnel et paie (cahier des charges, sections 13.4 et 17) : employés, pointage par code QR changeant
-- et position GPS, pointage de secours à la caisse, plannings, jours fériés, congés et justifications,
-- bulletins de paie (USD et/ou CDF), retenues proposées (dettes du personnel, manquants de caisse),
-- avances, paiements depuis la trésorerie (mouvements « salaire » et « avance »).

-- Position de l'établissement (pointage GPS), rayon accepté, pointage obligatoire pour ouvrir la caisse
ALTER TABLE etablissements
    ADD COLUMN latitude DECIMAL(9,6) NULL,
    ADD COLUMN longitude DECIMAL(9,6) NULL,
    ADD COLUMN pointage_rayon SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    ADD COLUMN pointage_obligatoire TINYINT(1) NOT NULL DEFAULT 0;

-- Paiements de salaires et d'avances dans le journal de la trésorerie (source : paiements_personnel.id)
ALTER TABLE mouvements_tresorerie
    MODIFY nature ENUM('solde_initial','transfert','depense','vente','achat','remboursement','ajustement','salaire','avance') NOT NULL;

-- ---------- Employés ----------

-- Un employé peut ne pas avoir de compte (agent de sécurité, hôtesse) : il pointe alors avec son téléphone
-- et son PIN de pointage (pin_pointage). Avec un compte, il utilise le téléphone et le PIN de son compte.
CREATE TABLE employes (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id    INT UNSIGNED NULL,
    nom               VARCHAR(128) NOT NULL,
    telephone         VARCHAR(32)  NULL,             -- format international (+243…) ; celui du compte s'il y en a un
    poste             VARCHAR(80)  NULL,
    etablissement_id  INT UNSIGNED NOT NULL,         -- établissement principal (bulletin, masse salariale)
    contrat           ENUM('cdi','cdd','journalier') NOT NULL DEFAULT 'cdi',
    debut             DATE         NOT NULL,
    fin               DATE         NULL,             -- fin d'un CDD, ou départ
    periode           ENUM('mois','semaine','soiree') NOT NULL DEFAULT 'mois',   -- salaire par mois, semaine ou soirée
    commission_pct    DECIMAL(5,2) NULL,             -- % des ventes payées de la personne (facultatif)
    pourboires        TINYINT(1)   NOT NULL DEFAULT 0,  -- participe à la répartition des pourboires
    notes             TEXT         NULL,
    pin_pointage      VARCHAR(255) NULL,             -- sans compte : PIN de 6 chiffres (empreinte)
    pin_essais        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    pin_bloque_le     DATETIME     NULL,
    actif             TINYINT(1)   NOT NULL DEFAULT 1,
    cree_par          INT UNSIGNED NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le        DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employe_utilisateur (utilisateur_id),
    UNIQUE KEY uq_employe_telephone (telephone),
    KEY idx_employe_etab (etablissement_id, actif),
    CONSTRAINT fk_employe_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id),
    CONSTRAINT fk_employe_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_employe_cree_par FOREIGN KEY (cree_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Établissements où l'employé travaille (le principal compris)
CREATE TABLE employe_etablissements (
    employe_id        INT UNSIGNED NOT NULL,
    etablissement_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (employe_id, etablissement_id),
    KEY idx_ee_etab (etablissement_id),
    CONSTRAINT fk_ee_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE,
    CONSTRAINT fk_ee_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Salaire dans une ou plusieurs devises (150 $ + 100 000 FC), pour la période de l'employé
CREATE TABLE employe_salaires (
    employe_id        INT UNSIGNED NOT NULL,
    devise_id         INT UNSIGNED NOT NULL,
    montant           DECIMAL(18,3) NOT NULL,
    PRIMARY KEY (employe_id, devise_id),
    CONSTRAINT fk_es_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE,
    CONSTRAINT fk_es_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents (contrat, pièce d'identité…), rangés hors de la racine web (storage/documents/<instance>/)
CREATE TABLE employe_documents (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employe_id        INT UNSIGNED NOT NULL,
    nom               VARCHAR(255) NOT NULL,
    fichier           VARCHAR(255) NOT NULL,
    type_mime         VARCHAR(100) NOT NULL,
    taille            INT UNSIGNED NOT NULL,
    ajoute_par        INT UNSIGNED NULL,
    ajoute_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_employe (employe_id),
    CONSTRAINT fk_doc_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_ajoute_par FOREIGN KEY (ajoute_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Pointage ----------

-- Borne de pointage : écran (tablette) à l'entrée qui affiche le code QR changeant, autorisée par un gérant.
-- Le navigateur garde un jeton dans un cookie ; la base n'en garde que l'empreinte SHA-256.
CREATE TABLE pointage_bornes (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    etablissement_id  INT UNSIGNED NOT NULL,
    nom               VARCHAR(80)  NOT NULL,
    jeton             CHAR(64)     NOT NULL,
    autorise_par      INT UNSIGNED NULL,
    actif             TINYINT(1)   NOT NULL DEFAULT 1,
    dernier_usage     DATETIME     NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_borne_jeton (jeton),
    CONSTRAINT fk_borne_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_borne_autorise_par FOREIGN KEY (autorise_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Téléphone d'un employé, reconnu par un cookie de longue durée après son téléphone et son PIN.
-- Un seul téléphone actif par employé : en identifier un autre retire le précédent.
CREATE TABLE pointage_telephones (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employe_id        INT UNSIGNED NOT NULL,
    jeton             CHAR(64)     NOT NULL,
    appareil          VARCHAR(255) NULL,             -- navigateur (User-Agent), pour le reconnaître dans la liste
    actif             TINYINT(1)   NOT NULL DEFAULT 1,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dernier_usage     DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_telephone_jeton (jeton),
    KEY idx_telephone_employe (employe_id, actif),
    CONSTRAINT fk_telephone_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une présence : arrivée et départ, chacun avec sa source (code QR, secours à la caisse, gestion),
-- sa position et sa distance à l'établissement. journee : journée d'exploitation (un service qui passe
-- minuit compte pour la journée où il a commencé).
CREATE TABLE pointages (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employe_id        INT UNSIGNED NOT NULL,
    etablissement_id  INT UNSIGNED NOT NULL,
    journee           DATE         NOT NULL,
    arrivee           DATETIME     NOT NULL,
    depart            DATETIME     NULL,
    arrivee_source    ENUM('qr','secours','gestion') NOT NULL,
    depart_source     ENUM('qr','secours','gestion') NULL,
    arrivee_lat       DECIMAL(9,6) NULL,
    arrivee_lng       DECIMAL(9,6) NULL,
    arrivee_precision INT UNSIGNED NULL,             -- en mètres, donnée par le téléphone
    arrivee_distance  INT UNSIGNED NULL,             -- en mètres, jusqu'à l'établissement
    depart_lat        DECIMAL(9,6) NULL,
    depart_lng        DECIMAL(9,6) NULL,
    depart_precision  INT UNSIGNED NULL,
    depart_distance   INT UNSIGNED NULL,
    arrivee_borne_id  INT UNSIGNED NULL,
    depart_borne_id   INT UNSIGNED NULL,
    arrivee_fenetre   BIGINT UNSIGNED NULL,          -- fenêtre du code QR utilisé (un code ne sert qu'une fois par personne)
    depart_fenetre    BIGINT UNSIGNED NULL,
    arrivee_valide_par INT UNSIGNED NULL,            -- secours : le gérant qui a tapé son PIN ; gestion : qui a saisi
    depart_valide_par  INT UNSIGNED NULL,
    appareil_id       INT UNSIGNED NULL,             -- caisse partagée du pointage de secours
    telephone_id      INT UNSIGNED NULL,
    note              VARCHAR(255) NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le        DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pointage_employe (employe_id, arrivee),
    KEY idx_pointage_journee (etablissement_id, journee),
    KEY idx_pointage_ouvert (employe_id, depart),
    CONSTRAINT fk_pointage_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE,
    CONSTRAINT fk_pointage_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_pointage_arrivee_valide FOREIGN KEY (arrivee_valide_par) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_pointage_depart_valide FOREIGN KEY (depart_valide_par) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_pointage_arrivee_borne FOREIGN KEY (arrivee_borne_id) REFERENCES pointage_bornes(id) ON DELETE SET NULL,
    CONSTRAINT fk_pointage_depart_borne FOREIGN KEY (depart_borne_id) REFERENCES pointage_bornes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Corrections du gérant (ajout, modification, suppression d'une présence), toujours avec un motif
CREATE TABLE pointage_corrections (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pointage_id       INT UNSIGNED NULL,
    employe_id        INT UNSIGNED NOT NULL,
    action            ENUM('ajout','modification','suppression') NOT NULL,
    avant             TEXT         NULL,             -- JSON : arrivée, départ
    apres             TEXT         NULL,
    motif             VARCHAR(255) NOT NULL,
    par               INT UNSIGNED NULL,
    le                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_correction_employe (employe_id, le),
    KEY idx_correction_pointage (pointage_id),
    CONSTRAINT fk_correction_pointage FOREIGN KEY (pointage_id) REFERENCES pointages(id) ON DELETE SET NULL,
    CONSTRAINT fk_correction_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE,
    CONSTRAINT fk_correction_par FOREIGN KEY (par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Plannings, jours fériés, congés ----------

-- Un créneau : journée d'exploitation, début et fin (la fin peut tomber le lendemain), service et poste
CREATE TABLE plannings (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    etablissement_id  INT UNSIGNED NOT NULL,
    employe_id        INT UNSIGNED NOT NULL,
    journee           DATE         NOT NULL,
    debut             DATETIME     NOT NULL,
    fin               DATETIME     NOT NULL,
    service           VARCHAR(64)  NULL,             -- restaurant, lounge, club…
    poste             VARCHAR(64)  NULL,             -- bar, cuisine, salle, sécurité, entrée…
    pause_minutes     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    note              VARCHAR(255) NULL,
    cree_par          INT UNSIGNED NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_planning_journee (etablissement_id, journee),
    KEY idx_planning_employe (employe_id, journee),
    CONSTRAINT fk_planning_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_planning_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE,
    CONSTRAINT fk_planning_cree_par FOREIGN KEY (cree_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jours fériés : date fixe chaque année (annee NULL) ou d'une seule année ; liste modifiable
CREATE TABLE jours_feries (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mois              TINYINT UNSIGNED NOT NULL,
    jour              TINYINT UNSIGNED NOT NULL,
    annee             SMALLINT UNSIGNED NULL,
    nom_fr            VARCHAR(128) NOT NULL,
    nom_en            VARCHAR(128) NOT NULL,
    actif             TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ferie (mois, jour, annee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- République démocratique du Congo (jours fériés légaux, chaque année)
INSERT IGNORE INTO jours_feries (mois, jour, annee, nom_fr, nom_en) VALUES
    (1, 1, NULL, 'Nouvel an', 'New Year''s Day'),
    (1, 4, NULL, 'Martyrs de l''indépendance', 'Martyrs of Independence Day'),
    (1, 16, NULL, 'Laurent-Désiré Kabila', 'Laurent-Désiré Kabila Day'),
    (1, 17, NULL, 'Patrice Emery Lumumba', 'Patrice Emery Lumumba Day'),
    (4, 6, NULL, 'Simon Kimbangu et la conscience africaine', 'Simon Kimbangu and African Consciousness Day'),
    (5, 1, NULL, 'Fête du travail', 'Labour Day'),
    (5, 17, NULL, 'Révolution et libération', 'Revolution and Liberation Day'),
    (6, 30, NULL, 'Indépendance', 'Independence Day'),
    (8, 1, NULL, 'Fête des parents', 'Parents'' Day'),
    (8, 2, NULL, 'Genocost (génocide congolais)', 'Genocost (Congolese genocide)'),
    (12, 25, NULL, 'Noël', 'Christmas Day');

-- Congés demandés et absences justifiées, validés (ou refusés) par le gérant. Un jour couvert par une
-- demande acceptée n'est pas une absence injustifiée ; « sans_solde » est retenu sur la paie.
CREATE TABLE absences (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employe_id        INT UNSIGNED NOT NULL,
    type              ENUM('conge','maladie','permission','justification','sans_solde') NOT NULL,
    debut             DATE         NOT NULL,
    fin               DATE         NOT NULL,
    motif             VARCHAR(500) NULL,
    statut            ENUM('en_attente','acceptee','refusee') NOT NULL DEFAULT 'en_attente',
    demande_par       INT UNSIGNED NULL,
    demande_le        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decide_par        INT UNSIGNED NULL,
    decide_le         DATETIME     NULL,
    decision_motif    VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_absence_employe (employe_id, debut),
    KEY idx_absence_statut (statut),
    CONSTRAINT fk_absence_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE,
    CONSTRAINT fk_absence_demande_par FOREIGN KEY (demande_par) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_absence_decide_par FOREIGN KEY (decide_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Paie ----------

CREATE TABLE bulletins (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employe_id        INT UNSIGNED NOT NULL,
    etablissement_id  INT UNSIGNED NOT NULL,
    debut             DATE         NOT NULL,
    fin               DATE         NOT NULL,
    periode           ENUM('mois','semaine','soiree') NOT NULL,
    statut            ENUM('brouillon','valide','paye','annule') NOT NULL DEFAULT 'brouillon',
    -- Présence de la période, figée à la préparation (recalculée à chaque « Recalculer »)
    creneaux_planifies SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    jours_travailles  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    heures_planifiees DECIMAL(7,2) NOT NULL DEFAULT 0,
    heures_travaillees DECIMAL(7,2) NOT NULL DEFAULT 0,
    retards           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    retard_minutes    INT UNSIGNED NOT NULL DEFAULT 0,
    absences_injustifiees SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    note              TEXT         NULL,
    cree_par          INT UNSIGNED NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    valide_par        INT UNSIGNED NULL,
    valide_le         DATETIME     NULL,
    annule_par        INT UNSIGNED NULL,
    annule_le         DATETIME     NULL,
    motif_annulation  VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_bulletin_employe (employe_id, debut),
    KEY idx_bulletin_periode (etablissement_id, fin, statut),
    CONSTRAINT fk_bulletin_employe FOREIGN KEY (employe_id) REFERENCES employes(id),
    CONSTRAINT fk_bulletin_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_bulletin_cree_par FOREIGN KEY (cree_par) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_bulletin_valide_par FOREIGN KEY (valide_par) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_bulletin_annule_par FOREIGN KEY (annule_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avance sur salaire, payée depuis la trésorerie (paiements_personnel), déduite de la paie suivante
CREATE TABLE avances (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employe_id        INT UNSIGNED NOT NULL,
    etablissement_id  INT UNSIGNED NOT NULL,
    date_avance       DATETIME     NOT NULL,
    motif             VARCHAR(255) NULL,
    statut            ENUM('a_deduire','deduite','annulee') NOT NULL DEFAULT 'a_deduire',
    bulletin_id       INT UNSIGNED NULL,             -- bulletin validé qui l'a déduite
    cree_par          INT UNSIGNED NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    annule_par        INT UNSIGNED NULL,
    annule_le         DATETIME     NULL,
    motif_annulation  VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_avance_employe (employe_id, statut),
    CONSTRAINT fk_avance_employe FOREIGN KEY (employe_id) REFERENCES employes(id),
    CONSTRAINT fk_avance_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_avance_bulletin FOREIGN KEY (bulletin_id) REFERENCES bulletins(id) ON DELETE SET NULL,
    CONSTRAINT fk_avance_cree_par FOREIGN KEY (cree_par) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_avance_annule_par FOREIGN KEY (annule_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lignes du bulletin, chacune dans une devise. Gains : base, heures_sup, ferie, commission, prime, pourboire.
-- Retenues : absence, sans_solde, avance, dette (dette du personnel), manquant (manquant de caisse), retenue.
-- Dette et manquant : source_devise_id / source_montant = ce qui est retenu dans la devise de la dette ou du
-- manquant (taux = unités de la devise du bulletin pour 1 unité de la source) ; source_du = ce qui restait dû.
CREATE TABLE bulletin_lignes (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bulletin_id       INT UNSIGNED NOT NULL,
    type              ENUM('base','heures_sup','ferie','commission','prime','pourboire','absence','sans_solde','avance','dette','manquant','retenue') NOT NULL,
    libelle           VARCHAR(255) NOT NULL,
    quantite          DECIMAL(10,2) NULL,
    taux              DECIMAL(18,6) NULL,
    devise_id         INT UNSIGNED NOT NULL,
    montant           DECIMAL(18,3) NOT NULL,        -- toujours positif ; le type dit si c'est un gain ou une retenue
    avance_id         INT UNSIGNED NULL,
    session_id        INT UNSIGNED NULL,
    source_devise_id  INT UNSIGNED NULL,
    source_montant    DECIMAL(18,3) NULL,
    source_du         DECIMAL(18,3) NULL,
    auto              TINYINT(1)   NOT NULL DEFAULT 1,  -- calculée (0 : ajoutée à la main)
    position          SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_ligne_bulletin (bulletin_id),
    KEY idx_ligne_session (session_id),
    KEY idx_ligne_avance (avance_id),
    CONSTRAINT fk_ligne_bulletin FOREIGN KEY (bulletin_id) REFERENCES bulletins(id) ON DELETE CASCADE,
    CONSTRAINT fk_ligne_devise FOREIGN KEY (devise_id) REFERENCES devises(id),
    CONSTRAINT fk_ligne_source_devise FOREIGN KEY (source_devise_id) REFERENCES devises(id),
    CONSTRAINT fk_ligne_avance FOREIGN KEY (avance_id) REFERENCES avances(id) ON DELETE SET NULL,
    CONSTRAINT fk_ligne_session FOREIGN KEY (session_id) REFERENCES sessions_caisse(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dettes du personnel réduites par un bulletin validé (sans mouvement de trésorerie : l'argent est gardé
-- sur le salaire), montant dans la devise de chaque dette ; DetteRepository::retenirSurPaie()
CREATE TABLE bulletin_dettes (
    bulletin_id       INT UNSIGNED NOT NULL,
    dette_id          INT UNSIGNED NOT NULL,
    montant           DECIMAL(18,3) NOT NULL,
    annule            TINYINT(1)   NOT NULL DEFAULT 0,  -- bulletin annulé : la dette a retrouvé ce montant
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bulletin_id, dette_id),
    KEY idx_bd_dette (dette_id),
    CONSTRAINT fk_bd_bulletin FOREIGN KEY (bulletin_id) REFERENCES bulletins(id),
    CONSTRAINT fk_bd_dette FOREIGN KEY (dette_id) REFERENCES dettes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paiement d'un salaire (bulletin validé) ou d'une avance : une ou plusieurs lignes (compte, devise, montant),
-- chacune sortie du compte choisi (mouvements_tresorerie : nature « salaire » ou « avance », source = id)
CREATE TABLE paiements_personnel (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type              ENUM('salaire','avance') NOT NULL,
    employe_id        INT UNSIGNED NOT NULL,
    etablissement_id  INT UNSIGNED NOT NULL,
    bulletin_id       INT UNSIGNED NULL,
    avance_id         INT UNSIGNED NULL,
    date_paiement     DATETIME     NOT NULL,
    note              VARCHAR(255) NULL,
    statut            ENUM('valide','annule') NOT NULL DEFAULT 'valide',
    utilisateur_id    INT UNSIGNED NULL,
    annule_par        INT UNSIGNED NULL,
    annule_le         DATETIME     NULL,
    motif_annulation  VARCHAR(255) NULL,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pp_employe (employe_id, date_paiement),
    KEY idx_pp_date (date_paiement),
    KEY idx_pp_bulletin (bulletin_id),
    CONSTRAINT fk_pp_employe FOREIGN KEY (employe_id) REFERENCES employes(id),
    CONSTRAINT fk_pp_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_pp_bulletin FOREIGN KEY (bulletin_id) REFERENCES bulletins(id),
    CONSTRAINT fk_pp_avance FOREIGN KEY (avance_id) REFERENCES avances(id),
    CONSTRAINT fk_pp_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_pp_annule_par FOREIGN KEY (annule_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE paiement_personnel_lignes (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    paiement_id       INT UNSIGNED NOT NULL,
    compte_id         INT UNSIGNED NOT NULL,
    devise_id         INT UNSIGNED NOT NULL,
    montant           DECIMAL(18,3) NOT NULL,
    position          SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ppl (paiement_id, compte_id, devise_id),
    CONSTRAINT fk_ppl_paiement FOREIGN KEY (paiement_id) REFERENCES paiements_personnel(id) ON DELETE CASCADE,
    CONSTRAINT fk_ppl_compte FOREIGN KEY (compte_id) REFERENCES comptes_tresorerie(id),
    CONSTRAINT fk_ppl_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Réglages et droits ----------

-- Secret de signature des codes QR de pointage (propre à l'instance) et règles de paie
-- (la journée d'exploitation suit etablissements.fin_journee, App\Core\Journee)
INSERT IGNORE INTO parametres (cle, valeur) VALUES
    ('pointage.secret', SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256)),
    ('personnel.tolerance_retard', '5'),         -- minutes de retard tolérées
    ('personnel.majoration_heures_sup', '1.3'),  -- heures supplémentaires payées 130 %
    ('personnel.majoration_ferie', '2');         -- heures un jour férié payées 200 %

-- Droits des rôles modèles (Permissions::parModule) :
-- personnel.gerer (employés, plannings, congés, bornes et réglages), personnel.presence (corriger les présences,
-- valider le pointage de secours), personnel.payer (payer salaires et avances depuis la trésorerie)
INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission FROM roles r
  JOIN (SELECT 'gerant' AS role, 'personnel.gerer' AS permission UNION ALL SELECT 'gerant', 'personnel.presence'
        UNION ALL SELECT 'rh', 'personnel.gerer' UNION ALL SELECT 'rh', 'personnel.presence' UNION ALL SELECT 'rh', 'personnel.payer'
        UNION ALL SELECT 'superviseur', 'personnel.voir' UNION ALL SELECT 'superviseur', 'personnel.presence'
        UNION ALL SELECT 'comptable', 'personnel.paie' UNION ALL SELECT 'comptable', 'personnel.payer') p ON p.role = r.code;
