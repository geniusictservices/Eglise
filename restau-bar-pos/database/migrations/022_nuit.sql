-- Lounge et nightclub (cahier des charges, sections 4, 6, 13.5 et 17) : remises et offerts à la caisse, bouteilles
-- avec softs et formules, minimum de consommation VIP, entrées et billetterie (billets à code QR, contrôle à la
-- porte), listes d'invités et promoteurs (commissions), vestiaire, cashless (carte ou bracelet à code QR, solde par
-- devise), fidélité. Nouveaux rôles modèles : agent d'entrée, vestiaire, promoteur.

-- ---------- Remises et offerts ----------

-- Remise maximale qu'un rôle accorde seul (en %, avec « caisse.remise ») ; NULL = sans limite.
-- Au-delà, un supérieur tape son PIN sur l'appareil (le journal garde les deux noms).
ALTER TABLE roles ADD COLUMN remise_max DECIMAL(5,2) NULL AFTER tout;
UPDATE roles SET remise_max = 10 WHERE code = 'superviseur';

-- Lignes de vente : nature (article, formule et ses composants, minimum non atteint, entrée, vestiaire),
-- remise de la ligne (part de la remise du ticket comprise), offert (100 % : perte dans le stock)
ALTER TABLE vente_lignes
    ADD COLUMN nature ENUM('article','formule','composant','minimum','entree','vestiaire') NOT NULL DEFAULT 'article' AFTER vente_id,
    ADD COLUMN parent_id INT UNSIGNED NULL AFTER nature,
    ADD COLUMN remise DECIMAL(18,3) NOT NULL DEFAULT 0 AFTER montant,
    ADD COLUMN offert TINYINT(1) NOT NULL DEFAULT 0 AFTER remise,
    ADD KEY idx_lignes_parent (parent_id);

-- Vente : total avant remises et total des remises (devise du ticket) ; client de la fidélité
ALTER TABLE ventes
    ADD COLUMN brut DECIMAL(18,3) NULL AFTER total,
    ADD COLUMN remise DECIMAL(18,3) NOT NULL DEFAULT 0 AFTER brut,
    ADD COLUMN client_id INT UNSIGNED NULL AFTER session_id,
    ADD KEY idx_vente_client (client_id);

-- Chaque remise accordée : sur une ligne (vente_ligne_id) ou sur tout le ticket (NULL), qui l'a demandée,
-- qui l'a validée avec son PIN (au-delà de la limite), motif
CREATE TABLE vente_remises (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vente_id       INT UNSIGNED NOT NULL,
    vente_ligne_id INT UNSIGNED NULL,
    type           ENUM('pourcent','montant','offert','fidelite') NOT NULL,
    valeur         DECIMAL(18,3) NOT NULL,                -- 10 (%), 5000 (montant dans devise_id) ou 100 (offert)
    devise_id      INT UNSIGNED NULL,                     -- devise du montant saisi
    taux           DECIMAL(9,6) NOT NULL,                 -- part retirée : 0,1 = 10 %
    montant        DECIMAL(18,3) NOT NULL,                -- remise dans la devise du ticket
    motif          VARCHAR(40)  NOT NULL,
    precision_motif VARCHAR(160) NULL,
    accorde_par    INT UNSIGNED NULL,
    valide_par     INT UNSIGNED NULL,                     -- supérieur qui a tapé son PIN (NULL : dans la limite)
    cree_le        DATETIME     NOT NULL,
    KEY idx_remise_vente (vente_id),
    KEY idx_remise_date (cree_le),
    CONSTRAINT fk_remise_vente FOREIGN KEY (vente_id) REFERENCES ventes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Bouteilles avec softs et formules ----------

-- Un produit du catalogue devient une formule (prix selon la grille, comme tout produit) : ses groupes de
-- composants, chacun avec le nombre à choisir (« 4 softs ») parmi des formats (Coca-Cola canette, Fanta…).
-- Un groupe d'une seule option est un composant fixe (la bouteille 70 cl).
CREATE TABLE formules (
    produit_id INT UNSIGNED PRIMARY KEY,
    actif      TINYINT(1) NOT NULL DEFAULT 1,
    cree_le    DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_formule_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE formule_groupes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produit_id INT UNSIGNED NOT NULL,
    nom_fr     VARCHAR(64) NOT NULL,                      -- « Bouteille », « Softs au choix »
    nom_en     VARCHAR(64) NOT NULL,
    choix      SMALLINT UNSIGNED NOT NULL DEFAULT 1,      -- nombre d'articles à choisir (répétition permise)
    position   SMALLINT NOT NULL DEFAULT 0,
    KEY idx_fg_produit (produit_id, position),
    CONSTRAINT fk_fg_formule FOREIGN KEY (produit_id) REFERENCES formules(produit_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE formule_options (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    groupe_id          INT UNSIGNED NOT NULL,
    conditionnement_id INT UNSIGNED NOT NULL,
    position           SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_fo (groupe_id, conditionnement_id),
    CONSTRAINT fk_fo_groupe FOREIGN KEY (groupe_id) REFERENCES formule_groupes(id) ON DELETE CASCADE,
    CONSTRAINT fk_fo_cond FOREIGN KEY (conditionnement_id) REFERENCES conditionnements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Une formule envoyée sur une note garde les composants choisis (JSON : [{"id": format, "qte": 2, "libelle": "…"}])
ALTER TABLE note_lignes ADD COLUMN composants TEXT NULL AFTER allergie;

-- ---------- Minimum de consommation (carré VIP, table) ----------

-- Par zone ou par table (la table l'emporte), pour une note (une soirée) ; montants dans une ou plusieurs devises.
-- action : « ajouter » la différence (ligne « minimum non atteint ») ou seulement « avertir ».
CREATE TABLE minimums_conso (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    zone_id          INT UNSIGNED NULL,
    table_id         INT UNSIGNED NULL,
    action           ENUM('ajouter','avertir') NOT NULL DEFAULT 'ajouter',
    actif            TINYINT(1) NOT NULL DEFAULT 1,
    cree_par         INT UNSIGNED NULL,
    cree_le          DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_min_etab (etablissement_id, actif),
    CONSTRAINT fk_min_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_min_zone FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    CONSTRAINT fk_min_table FOREIGN KEY (table_id) REFERENCES salle_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE minimum_montants (
    minimum_id INT UNSIGNED NOT NULL,
    devise_id  INT UNSIGNED NOT NULL,
    montant    DECIMAL(18,3) NOT NULL,
    PRIMARY KEY (minimum_id, devise_id),
    CONSTRAINT fk_minm_min FOREIGN KEY (minimum_id) REFERENCES minimums_conso(id) ON DELETE CASCADE,
    CONSTRAINT fk_minm_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Minimum levé pour une note (PIN d'un gérant) ; promoteur et invité de la note (commission sur la note)
ALTER TABLE notes
    ADD COLUMN minimum_leve_par INT UNSIGNED NULL AFTER version,
    ADD COLUMN minimum_leve_le DATETIME NULL AFTER minimum_leve_par,
    ADD COLUMN invite_id INT UNSIGNED NULL AFTER minimum_leve_le;

-- ---------- Entrées et billetterie ----------

-- Prix d'entrée par soirée (evenement_id) ou par défaut (NULL), par catégorie ; « early bird » jusqu'à fin_vente
CREATE TABLE entree_tarifs (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    evenement_id     INT UNSIGNED NULL,
    categorie        ENUM('normal','vip','early','gratuit','reduit') NOT NULL DEFAULT 'normal',
    nom_fr           VARCHAR(64) NOT NULL,
    nom_en           VARCHAR(64) NOT NULL,
    fin_vente        DATETIME   NULL,                      -- early bird : vendu jusqu'à ce moment
    actif            TINYINT(1) NOT NULL DEFAULT 1,
    position         SMALLINT   NOT NULL DEFAULT 0,
    KEY idx_tarif_etab (etablissement_id, evenement_id, actif),
    CONSTRAINT fk_tarif_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_tarif_evenement FOREIGN KEY (evenement_id) REFERENCES evenements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE entree_tarif_prix (
    tarif_id  INT UNSIGNED NOT NULL,
    devise_id INT UNSIGNED NOT NULL,
    montant   DECIMAL(18,3) NOT NULL,
    PRIMARY KEY (tarif_id, devise_id),
    CONSTRAINT fk_tp_tarif FOREIGN KEY (tarif_id) REFERENCES entree_tarifs(id) ON DELETE CASCADE,
    CONSTRAINT fk_tp_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un billet par entrée vendue (ou offerte à un invité) ; code QR = « B<id>-<jeton> », jamais devinable
CREATE TABLE billets (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jeton            CHAR(10)   NOT NULL,
    etablissement_id INT UNSIGNED NOT NULL,
    evenement_id     INT UNSIGNED NULL,
    journee          DATE       NOT NULL,                  -- soirée (journée d'exploitation)
    tarif_id         INT UNSIGNED NULL,
    categorie        ENUM('normal','vip','early','gratuit','reduit') NOT NULL,
    vente_id         INT UNSIGNED NULL,
    vente_ligne_id   INT UNSIGNED NULL,
    invite_id        INT UNSIGNED NULL,
    prix             DECIMAL(18,3) NOT NULL DEFAULT 0,     -- devise du ticket
    devise_id        INT UNSIGNED NULL,
    statut           ENUM('valide','utilise','annule') NOT NULL DEFAULT 'valide',
    utilise_le       DATETIME   NULL,
    utilise_par      INT UNSIGNED NULL,
    cree_par         INT UNSIGNED NULL,
    cree_le          DATETIME   NOT NULL,
    KEY idx_billet_soiree (etablissement_id, journee),
    KEY idx_billet_evenement (evenement_id),
    KEY idx_billet_vente (vente_id),
    CONSTRAINT fk_billet_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_billet_evenement FOREIGN KEY (evenement_id) REFERENCES evenements(id) ON DELETE SET NULL,
    CONSTRAINT fk_billet_tarif FOREIGN KEY (tarif_id) REFERENCES entree_tarifs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chaque contrôle à la porte (valide, déjà utilisé, inconnu, annulé, autre soirée)
CREATE TABLE billet_controles (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    billet_id        INT UNSIGNED NULL,
    code_lu          VARCHAR(64) NOT NULL,
    resultat         ENUM('ok','deja','invalide','annule','autre_soiree') NOT NULL,
    utilisateur_id   INT UNSIGNED NULL,
    appareil_id      INT UNSIGNED NULL,
    cree_le          DATETIME   NOT NULL,
    KEY idx_controle_etab (etablissement_id, cree_le)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Listes d'invités et promoteurs ----------

CREATE TABLE promoteurs (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id    INT UNSIGNED NULL,                   -- son compte (rôle « promoteur ») : il voit ses invités et ses commissions
    nom               VARCHAR(128) NOT NULL,
    telephone         VARCHAR(32)  NULL,
    etablissement_id  INT UNSIGNED NULL,                   -- NULL : tous les établissements
    devise_id         INT UNSIGNED NOT NULL,               -- devise des commissions
    par_entree        DECIMAL(18,3) NULL,                  -- commission par entrée de ses invités
    pourcent_notes    DECIMAL(5,2)  NULL,                  -- % des notes (bouteilles) de ses invités
    actif             TINYINT(1)   NOT NULL DEFAULT 1,
    cree_le           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_promoteur_utilisateur (utilisateur_id),
    CONSTRAINT fk_promoteur_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_promoteur_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE SET NULL,
    CONSTRAINT fk_promoteur_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invité d'une soirée : +N accompagnants, entrée gratuite ou au tarif réduit ; pointé à la porte
CREATE TABLE invites (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    evenement_id   INT UNSIGNED NOT NULL,
    promoteur_id   INT UNSIGNED NULL,
    nom            VARCHAR(128) NOT NULL,
    telephone      VARCHAR(32)  NULL,
    accompagnants  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    entree         ENUM('gratuite','reduite') NOT NULL DEFAULT 'gratuite',
    tarif_id       INT UNSIGNED NULL,                      -- entrée réduite : le tarif à payer
    arrives        TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- personnes entrées (invité compris)
    arrive_le      DATETIME     NULL,
    pointe_par     INT UNSIGNED NULL,
    note           VARCHAR(160) NULL,
    cree_par       INT UNSIGNED NULL,
    cree_le        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invite_soiree (evenement_id, nom),
    KEY idx_invite_promoteur (promoteur_id),
    CONSTRAINT fk_invite_evenement FOREIGN KEY (evenement_id) REFERENCES evenements(id) ON DELETE CASCADE,
    CONSTRAINT fk_invite_promoteur FOREIGN KEY (promoteur_id) REFERENCES promoteurs(id) ON DELETE SET NULL,
    CONSTRAINT fk_invite_tarif FOREIGN KEY (tarif_id) REFERENCES entree_tarifs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paiement des commissions d'une période : une dépense (catégorie « Commissions », compte et devise)
CREATE TABLE commission_paiements (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promoteur_id  INT UNSIGNED NOT NULL,
    du            DATE NOT NULL,
    au            DATE NOT NULL,
    devise_id     INT UNSIGNED NOT NULL,
    montant       DECIMAL(18,3) NOT NULL,
    depense_id    INT UNSIGNED NULL,
    cle           CHAR(36) NULL,
    cree_par      INT UNSIGNED NULL,
    cree_le       DATETIME NOT NULL,
    UNIQUE KEY uq_compaie_cle (cle),
    CONSTRAINT fk_compaie_promoteur FOREIGN KEY (promoteur_id) REFERENCES promoteurs(id),
    CONSTRAINT fk_compaie_depense FOREIGN KEY (depense_id) REFERENCES depenses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commissions dues : par entrée (invite_id) ou sur une note payée (note_id) ; payées par un paiement
CREATE TABLE commissions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promoteur_id   INT UNSIGNED NOT NULL,
    evenement_id   INT UNSIGNED NULL,
    journee        DATE NOT NULL,
    type           ENUM('entree','note') NOT NULL,
    invite_id      INT UNSIGNED NULL,
    note_id        INT UNSIGNED NULL,
    base           DECIMAL(18,3) NOT NULL,                 -- entrées, ou montant de la note (devise de la commission)
    devise_id      INT UNSIGNED NOT NULL,
    montant        DECIMAL(18,3) NOT NULL,
    statut         ENUM('due','payee','annulee') NOT NULL DEFAULT 'due',
    paiement_id    INT UNSIGNED NULL,
    cree_le        DATETIME NOT NULL,
    UNIQUE KEY uq_commission_invite (type, invite_id),
    UNIQUE KEY uq_commission_note (type, note_id),
    KEY idx_commission_promoteur (promoteur_id, journee),
    CONSTRAINT fk_commission_promoteur FOREIGN KEY (promoteur_id) REFERENCES promoteurs(id),
    CONSTRAINT fk_commission_paiement FOREIGN KEY (paiement_id) REFERENCES commission_paiements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categories_depenses (nom_fr, nom_en, position)
SELECT 'Commissions', 'Commissions', (SELECT COALESCE(MAX(c.position), 0) + 1 FROM categories_depenses c) FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM categories_depenses WHERE nom_fr = 'Commissions');

-- ---------- Vestiaire ----------

CREATE TABLE vestiaire_tickets (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id INT UNSIGNED NOT NULL,
    journee          DATE       NOT NULL,
    numero           SMALLINT UNSIGNED NOT NULL,           -- numéro de la soirée (1, 2, 3…) écrit sur le ticket
    jeton            CHAR(10)   NOT NULL,
    description      VARCHAR(160) NULL,                    -- « veste noire + casque »
    objets           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    vente_id         INT UNSIGNED NULL,                    -- frais de vestiaire payés
    statut           ENUM('depose','rendu') NOT NULL DEFAULT 'depose',
    depose_par       INT UNSIGNED NULL,
    depose_le        DATETIME   NOT NULL,
    rendu_par        INT UNSIGNED NULL,
    rendu_le         DATETIME   NULL,
    cle              CHAR(36)   NULL,
    UNIQUE KEY uq_vestiaire_numero (etablissement_id, journee, numero),
    UNIQUE KEY uq_vestiaire_cle (cle),
    KEY idx_vestiaire_statut (etablissement_id, statut),
    CONSTRAINT fk_vestiaire_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Frais de vestiaire par établissement et par devise (aucune ligne : gratuit)
CREATE TABLE vestiaire_frais (
    etablissement_id INT UNSIGNED NOT NULL,
    devise_id        INT UNSIGNED NOT NULL,
    montant          DECIMAL(18,3) NOT NULL,
    PRIMARY KEY (etablissement_id, devise_id),
    CONSTRAINT fk_vf_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_vf_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Cashless ----------

-- Carte ou bracelet : code QR « C<numero>-<jeton> » (jeton aléatoire de 10 caractères, jamais devinable)
CREATE TABLE cartes_cashless (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero           INT UNSIGNED NOT NULL,
    jeton            CHAR(10)   NOT NULL,
    support          ENUM('carte','bracelet') NOT NULL DEFAULT 'carte',
    porteur          VARCHAR(128) NULL,
    client_id        INT UNSIGNED NULL,
    statut           ENUM('active','bloquee') NOT NULL DEFAULT 'active',
    bloquee_le       DATETIME   NULL,
    bloquee_par      INT UNSIGNED NULL,
    motif_blocage    VARCHAR(160) NULL,
    cree_par         INT UNSIGNED NULL,
    cree_le          DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_carte_numero (numero),
    CONSTRAINT fk_carte_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE carte_soldes (
    carte_id  INT UNSIGNED NOT NULL,
    devise_id INT UNSIGNED NOT NULL,
    solde     DECIMAL(18,3) NOT NULL DEFAULT 0,
    PRIMARY KEY (carte_id, devise_id),
    CONSTRAINT fk_cs_carte FOREIGN KEY (carte_id) REFERENCES cartes_cashless(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique : recharge (l'argent entre dans la trésorerie), paiement (aucun mouvement de trésorerie),
-- remboursement du solde (sortie de trésorerie), annulation d'une vente payée par la carte
CREATE TABLE carte_mouvements (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    carte_id         INT UNSIGNED NOT NULL,
    devise_id        INT UNSIGNED NOT NULL,
    type             ENUM('recharge','paiement','remboursement','annulation') NOT NULL,
    montant          DECIMAL(18,3) NOT NULL,               -- signé : + recharge, − paiement
    solde_apres      DECIMAL(18,3) NOT NULL,
    vente_id         INT UNSIGNED NULL,
    etablissement_id INT UNSIGNED NULL,
    utilisateur_id   INT UNSIGNED NULL,
    appareil_id      INT UNSIGNED NULL,
    session_id       INT UNSIGNED NULL,
    rendu            DECIMAL(18,3) NOT NULL DEFAULT 0,     -- recharge payée en espèces : monnaie rendue
    rendu_devise_id  INT UNSIGNED NULL,
    cle              CHAR(36)   NULL,
    cree_le          DATETIME   NOT NULL,
    UNIQUE KEY uq_cm_cle (cle),
    KEY idx_cm_carte (carte_id, id),
    KEY idx_cm_vente (vente_id),
    CONSTRAINT fk_cm_carte FOREIGN KEY (carte_id) REFERENCES cartes_cashless(id),
    CONSTRAINT fk_cm_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paiements d'une recharge (compte, devise, montant reçu), comme ceux d'une vente
CREATE TABLE carte_paiements (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mouvement_id  INT UNSIGNED NOT NULL,
    compte_id     INT UNSIGNED NOT NULL,
    devise_id     INT UNSIGNED NOT NULL,
    recu          DECIMAL(18,3) NOT NULL,
    taux          DECIMAL(18,6) NULL,
    CONSTRAINT fk_cp_mouvement FOREIGN KEY (mouvement_id) REFERENCES carte_mouvements(id) ON DELETE CASCADE,
    CONSTRAINT fk_cp_compte FOREIGN KEY (compte_id) REFERENCES comptes_tresorerie(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Part d'une vente payée avec une carte (dans la devise de la carte)
CREATE TABLE vente_cashless (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vente_id      INT UNSIGNED NOT NULL,
    carte_id      INT UNSIGNED NOT NULL,
    devise_id     INT UNSIGNED NOT NULL,
    montant       DECIMAL(18,3) NOT NULL,
    taux          DECIMAL(18,6) NULL,
    contre_valeur DECIMAL(18,3) NULL,
    KEY idx_vc_vente (vente_id),
    CONSTRAINT fk_vc_vente FOREIGN KEY (vente_id) REFERENCES ventes(id) ON DELETE CASCADE,
    CONSTRAINT fk_vc_carte FOREIGN KEY (carte_id) REFERENCES cartes_cashless(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mouvements_tresorerie
    MODIFY nature ENUM('solde_initial','transfert','depense','vente','achat','remboursement','ajustement','salaire','avance','cashless') NOT NULL;

-- ---------- Fidélité (au choix de chaque établissement) ----------

CREATE TABLE fidelite_reglages (
    etablissement_id INT UNSIGNED PRIMARY KEY,
    actif            TINYINT(1) NOT NULL DEFAULT 0,
    montant_point    DECIMAL(18,3) NOT NULL DEFAULT 1,     -- 1 point par tranche de ce montant…
    devise_id        INT UNSIGNED NOT NULL,                -- …dans cette devise (le dollar par défaut)
    CONSTRAINT fk_fr_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_fr_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Récompenses : une remise (en %) ou un article offert, contre des points
CREATE TABLE fidelite_recompenses (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    etablissement_id   INT UNSIGNED NOT NULL,
    nom_fr             VARCHAR(80) NOT NULL,
    nom_en             VARCHAR(80) NOT NULL,
    points             INT UNSIGNED NOT NULL,
    type               ENUM('remise','offert') NOT NULL,
    pourcent           DECIMAL(5,2) NULL,
    conditionnement_id INT UNSIGNED NULL,
    actif              TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_frec_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements(id) ON DELETE CASCADE,
    CONSTRAINT fk_frec_cond FOREIGN KEY (conditionnement_id) REFERENCES conditionnements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fidelite_mouvements (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id        INT UNSIGNED NOT NULL,
    etablissement_id INT UNSIGNED NOT NULL,
    vente_id         INT UNSIGNED NULL,
    type             ENUM('gain','utilisation','annulation','ajustement') NOT NULL,
    points           INT NOT NULL,                          -- signé
    recompense_id    INT UNSIGNED NULL,
    utilisateur_id   INT UNSIGNED NULL,
    cree_le          DATETIME NOT NULL,
    KEY idx_fm_client (client_id, cree_le),
    KEY idx_fm_vente (vente_id),
    CONSTRAINT fk_fm_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Rôles modèles et droits ----------

INSERT IGNORE INTO roles (code, nom_fr, nom_en, description_fr, description_en, tout, remise_max, position) VALUES
 ('agent_entree', 'Agent d''entrée', 'Door staff', 'Vend les entrées, contrôle les billets et pointe les invités à la porte.', 'Sells entries, checks tickets and checks in guests at the door.', 0, NULL, 13),
 ('vestiaire', 'Vestiaire', 'Cloakroom', 'Tickets de vestiaire : dépôt et retour des objets.', 'Cloakroom tickets: drop-off and return of items.', 0, NULL, 14),
 ('promoteur', 'Promoteur', 'Promoter', 'Voit ses invités et ses commissions, ajoute des invités à ses listes.', 'Sees their guests and commissions, adds guests to their lists.', 0, NULL, 15);

INSERT IGNORE INTO role_permissions (role_id, permission)
SELECT r.id, p.permission FROM roles r JOIN (
    SELECT 'gerant' AS code, 'caisse.minimum' AS permission UNION ALL SELECT 'gerant', 'nuit.voir' UNION ALL SELECT 'gerant', 'nuit.parametrer'
    UNION ALL SELECT 'gerant', 'entrees.vendre' UNION ALL SELECT 'gerant', 'entrees.controler' UNION ALL SELECT 'gerant', 'invites.gerer'
    UNION ALL SELECT 'gerant', 'invites.pointer' UNION ALL SELECT 'gerant', 'promoteurs.gerer' UNION ALL SELECT 'gerant', 'vestiaire.gerer'
    UNION ALL SELECT 'gerant', 'cashless.recharger' UNION ALL SELECT 'gerant', 'cashless.gerer' UNION ALL SELECT 'gerant', 'fidelite.parametrer'
    UNION ALL SELECT 'superviseur', 'nuit.voir' UNION ALL SELECT 'superviseur', 'entrees.controler' UNION ALL SELECT 'superviseur', 'invites.pointer'
    UNION ALL SELECT 'superviseur', 'cashless.recharger'
    UNION ALL SELECT 'caissier', 'cashless.recharger' UNION ALL SELECT 'barman', 'cashless.recharger'
    UNION ALL SELECT 'comptable', 'nuit.voir'
    UNION ALL SELECT 'agent_entree', 'entrees.vendre' UNION ALL SELECT 'agent_entree', 'entrees.controler'
    UNION ALL SELECT 'agent_entree', 'invites.pointer' UNION ALL SELECT 'agent_entree', 'cashless.recharger'
    UNION ALL SELECT 'vestiaire', 'vestiaire.gerer'
    UNION ALL SELECT 'promoteur', 'promoteur.espace'
) p ON p.code = r.code;
