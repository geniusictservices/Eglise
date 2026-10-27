-- Employés de démonstration (chargés après le compte administrateur par bin/creer-instance.php --demo).
-- PIN de caisse (6 chiffres) et mots de passe de gestion : voir README.md, « Comptes de démonstration ».
INSERT INTO utilisateurs (nom, email, telephone, poste, mot_de_passe, pin, langue) VALUES
 ('Patrick Kambale', 'patrick@demo.test', '+243810000101', 'Gérant', '$2y$10$cQQSXyBPJQ50aQOppmWPF.wPrlmx2hE02h96kLkF4i9fIvllzd/kS', '$2y$10$OrkZTzxDHAaL/SnserdVb.TyzQtsm08L56dpKu7MP.QlMeTA6fLRS', 'fr'),
 ('Grace Mwamini', 'grace@demo.test', '+243810000102', 'Comptable', '$2y$10$IlIcwVQH/sQ8M0Ie/wbL7OhNKzOe2lMCgQnZmPvxi6C6aqzrDDcS.', NULL, 'fr'),
 ('Aline Mukeba', NULL, '+243810000103', 'Serveuse', NULL, '$2y$10$e5bSIJjqYdElZ5MWO8oAYuXo8YRQeDPxw8O1.zs5ODKldNTHpVl/C', 'fr'),
 ('Esther Kahindo', NULL, '+243810000104', 'Caissière', NULL, '$2y$10$gPCPAyOT33OGTcQ.Hje69OcevNfZuiGppW1KiTh1W/pXSEqpqf3Iq', 'fr'),
 ('Jean-Paul Bahati', NULL, '+243810000105', 'Barman', NULL, '$2y$10$23zs2uYytYr00vaCuuBJV.K6PMqKini51.MkT/Ws6tWaEr7GGW8v2', 'fr'),
 ('Christian Mugisho', NULL, '+243810000106', 'Serveur', NULL, '$2y$10$NmffarG0LLy5LuC0RUb24OW88UQZPJ1s1LNPTug3MK8l4ixqjO8GS', 'fr'),
 ('Riziki Amani', 'riziki@demo.test', '+243810000107', 'Superviseure', '$2y$10$pmhrcwXCl82BXM8IW5c43O7HfaCvTl8AK6ipjDBkfG/t4/tizX/pK', '$2y$10$rwQVq/tAq.8w4xE7d8mceeh5VM/zQUM9eA68SxcBnXx70uhL/oilu', 'fr');

INSERT INTO utilisateur_roles (utilisateur_id, role_id, etablissement_id)
SELECT u.id, r.id, x.etab FROM (
SELECT '+243810000101' AS tel, 'gerant' AS role, 1 AS etab
    UNION ALL SELECT '+243810000102' AS tel, 'comptable' AS role, NULL AS etab
    UNION ALL SELECT '+243810000103' AS tel, 'serveur' AS role, 1 AS etab
    UNION ALL SELECT '+243810000104' AS tel, 'caissier' AS role, 1 AS etab
    UNION ALL SELECT '+243810000105' AS tel, 'barman' AS role, 2 AS etab
    UNION ALL SELECT '+243810000106' AS tel, 'serveur' AS role, 2 AS etab
    UNION ALL SELECT '+243810000107' AS tel, 'superviseur' AS role, 2 AS etab
) x JOIN utilisateurs u ON u.telephone = x.tel JOIN roles r ON r.code = x.role;

-- Le propriétaire (identifiant 1) a aussi un PIN : à la caisse, il valide remises, minimum VIP et annulations
-- dans les deux établissements (aucun gérant n'est rattaché au Volcano Club dans la démonstration)
UPDATE utilisateurs SET pin = '$2y$10$bfWePnHQCanY/UIe1ZluqevICP2fFSeVgdinIYcB8CZVEL8K1xc8K' WHERE id = 1 AND pin IS NULL;

-- Caisses partagées : message de l'écran de verrouillage et règles, choisis par établissement
UPDATE etablissements SET verrou_apres_encaissement = 1, verrou_minutes = 2,
    message_verrou_fr = 'Bienvenue au Comptoir du Lac. Touchez votre nom, puis tapez votre PIN.',
    message_verrou_en = 'Welcome to Le Comptoir du Lac. Tap your name, then enter your PIN.'
 WHERE slug = 'comptoir-du-lac';
UPDATE etablissements SET verrou_apres_encaissement = 1, verrou_minutes = 0,
    message_verrou_fr = 'Une vente, un PIN : la caisse se verrouille après chaque encaissement. Bonne soirée au Volcano Club !',
    message_verrou_en = 'One sale, one PIN: the till locks after each payment. Have a great night at Volcano Club!'
 WHERE slug = 'volcano-club';
