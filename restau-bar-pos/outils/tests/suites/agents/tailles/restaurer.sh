#!/bin/sh
# Remet rbp_integ exactement dans l'état de apres-demo.sql et vide public/uploads (sauf .gitkeep).
ICI=$(dirname "$0")
mysql -u root -e "DROP DATABASE rbp_integ; CREATE DATABASE rbp_integ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root --default-character-set=utf8mb4 rbp_integ < "$ICI/apres-demo.sql"
find /home/user/Eglise/restau-bar-pos/public/uploads -mindepth 1 ! -name .gitkeep -delete
mysqldump -u root --skip-dump-date rbp_integ > "$ICI/verif.sql"
cmp -s "$ICI/apres-demo.sql" "$ICI/verif.sql" && echo "BASE IDENTIQUE A apres-demo.sql" || echo "BASE DIFFERENTE"
ls -A /home/user/Eglise/restau-bar-pos/public/uploads
