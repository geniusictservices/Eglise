#!/bin/sh
# Copie fraîche de la démo pour les captures du manuel (rbp_demo n'est jamais modifiée)
set -e
mysql -e "DROP DATABASE IF EXISTS rbp_manuel; CREATE DATABASE rbp_manuel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON rbp_manuel.* TO 'rbp_demo'@'localhost';"
mysqldump --single-transaction --routines rbp_demo | mysql rbp_manuel
echo "rbp_manuel prête"
# Soirée de ce soir au Volcano Club, avec une liste d'invités (pointage à la porte)
mysql --default-character-set=utf8mb4 rbp_manuel <<'SQL'
INSERT INTO evenements (etablissement_id, titre, theme, artistes, debut, fin, budget, budget_devise_id, description, statut, cree_par, cree_le)
VALUES (2, 'Ladies Night', 'Entrée gratuite pour les dames avant minuit', 'DJ Neema', CONCAT(CURDATE(), ' 22:00:00'), CONCAT(CURDATE() + INTERVAL 1 DAY, ' 05:00:00'), 500, 1, 'Soirée de démonstration du manuel.', 'prevu', 1, NOW());
SET @e = LAST_INSERT_ID();
INSERT INTO invites (evenement_id, promoteur_id, nom, telephone, accompagnants, entree, tarif_id, arrives, arrive_le, pointe_par, note, cree_par, cree_le)
SELECT @e, promoteur_id, nom, telephone, accompagnants, 'gratuite', NULL, 0, NULL, NULL, note, cree_par, NOW() FROM invites WHERE evenement_id = 4;
SQL
