-- Photo de profil de chaque personne (écran « Qui êtes-vous ? », caisse, gestion),
-- et réglages des caisses partagées, choisis par établissement dans Paramètres > Utilisateurs.
ALTER TABLE utilisateurs ADD COLUMN avatar VARCHAR(255) NULL AFTER poste;

ALTER TABLE etablissements
    ADD COLUMN verrou_apres_encaissement TINYINT(1) NOT NULL DEFAULT 1,   -- retour à « Qui êtes-vous ? » après chaque encaissement
    ADD COLUMN verrou_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,        -- après N minutes sans toucher l'écran (0 = jamais)
    ADD COLUMN message_verrou_fr VARCHAR(255) NULL,                        -- message de l'écran de verrouillage
    ADD COLUMN message_verrou_en VARCHAR(255) NULL,
    ADD COLUMN photo_a_la_caisse TINYINT(1) NOT NULL DEFAULT 1;            -- chacun peut changer sa photo depuis la caisse
