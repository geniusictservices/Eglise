-- Mode hors ligne de la caisse (cahier des charges, sections 15 et 17)
-- Une vente payée sans réseau attend sur l'appareil, puis part au retour du réseau avec sa clé unique (jamais
-- deux ventes) ; elle est enregistrée à l'heure de l'appareil (bornée), et ce que le serveur aurait refusé en
-- ligne (stock bloqué, session fermée, pointage, prix différents…) est noté sur la vente et au journal.

ALTER TABLE ventes
    ADD COLUMN hors_ligne TINYINT(1) NOT NULL DEFAULT 0 AFTER cree_le,
    -- Heure donnée par l'appareil (corrigée de son décalage avec le serveur), même si elle a été bornée
    ADD COLUMN heure_appareil DATETIME NULL AFTER hors_ligne,
    ADD COLUMN synchronisee_le DATETIME NULL AFTER heure_appareil,
    -- Numéro du reçu provisoire imprimé sans réseau (HL-3-0007)
    ADD COLUMN numero_provisoire VARCHAR(40) NULL AFTER synchronisee_le,
    -- Total de l'appareil moins total recalculé par le serveur, dans la devise du ticket (null : aucun écart)
    ADD COLUMN ecart_hors_ligne DECIMAL(18,3) NULL AFTER numero_provisoire,
    -- Ce qui aurait été refusé en ligne : codes séparés par des virgules (stock, session, pointage, prix, remise, heure, droit, abonnement)
    ADD COLUMN notes_hors_ligne VARCHAR(255) NULL AFTER ecart_hors_ligne,
    ADD KEY idx_ventes_hors_ligne (hors_ligne, cree_le);

-- Clé hors ligne d'une personne sur un appareil : un secret aléatoire (jamais le PIN) qui signe ses ventes faites
-- sans réseau. Sur une caisse partagée, l'appareil ne le garde que chiffré avec une clé tirée du PIN (PBKDF2) ;
-- le serveur vérifie la signature à l'envoi. Retirée quand le PIN change, que l'appareil est retiré ou la personne désactivée.
CREATE TABLE IF NOT EXISTS caisse_cles_hors_ligne (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT UNSIGNED NOT NULL,
    appareil_id INT UNSIGNED NULL,
    etablissement_id INT UNSIGNED NOT NULL,
    secret CHAR(64) NOT NULL,
    version_pin CHAR(16) NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dernier_usage DATETIME NULL,
    revoquee_le DATETIME NULL,
    KEY idx_cle_hl_personne (utilisateur_id, appareil_id, revoquee_le),
    CONSTRAINT fk_cle_hl_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id) ON DELETE CASCADE,
    CONSTRAINT fk_cle_hl_appareil FOREIGN KEY (appareil_id) REFERENCES appareils_caisse (id) ON DELETE CASCADE,
    CONSTRAINT fk_cle_hl_etab FOREIGN KEY (etablissement_id) REFERENCES etablissements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
