#!/bin/sh
# Remet la base demo et public/uploads dans l'état d'origine (photographié dans avant.sql).
ICI=$(dirname "$0")
M="mysql -u root --default-character-set=utf8mb4 rbp_integ"
$M <<'SQL'
DELETE FROM produits WHERE categorie_id IN (SELECT id FROM categories WHERE nom_fr = 'Intégration') OR nom_fr = 'Bière Intégration';
DELETE FROM categories WHERE nom_fr = 'Intégration';
DELETE FROM etablissements WHERE slug LIKE 'lounge-integration%';
DELETE FROM taux_change WHERE id > 4;
DELETE FROM qr_scans;
INSERT INTO qr_scans VALUES (4, '2026-10-26', 1);
UPDATE utilisateurs SET derniere_connexion = '2026-10-26 13:10:38' WHERE id = 1;
SQL
# Compteurs AUTO_INCREMENT d'origine
awk '/^CREATE TABLE/ {t=$3} /AUTO_INCREMENT=/ {match($0,/AUTO_INCREMENT=[0-9]+/); print "ALTER TABLE " t " " substr($0,RSTART,RLENGTH) ";"}' "$ICI/avant.sql" | $M
find /home/user/Eglise/restau-bar-pos/public/uploads -mindepth 1 ! -name .gitkeep -delete
mysqldump -u root --skip-dump-date --skip-extended-insert rbp_integ > "$ICI/apres.sql"
diff "$ICI/avant.sql" "$ICI/apres.sql" && echo "BASE IDENTIQUE A L'ORIGINE"
ls -A /home/user/Eglise/restau-bar-pos/public/uploads
