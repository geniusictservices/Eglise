#!/bin/sh
# Copie d'intégration remise à neuf depuis la démo fraîche (jamais rbp_demo lui-même)
# Usage : restaurer.sh [sans-garant]
I=/home/user/Eglise/restau-bar-pos/outils/tests
mysql -e "DROP DATABASE IF EXISTS rbp_integ; CREATE DATABASE rbp_integ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON rbp_integ.* TO 'rbp_integ'@'localhost'; GRANT ALL ON rbp_integ.* TO 'rbp_integ'@'127.0.0.1';"
mysql rbp_integ < $I/pristine.sql
if [ "$1" = "sans-garant" ]; then mysql rbp_integ -e "UPDATE dettes SET garant_statut = 'refuse' WHERE garant_statut = 'en_attente'"; fi
rm -rf /home/user/Eglise/restau-bar-pos/public/uploads/integ && cp -a $I/uploads-pristine /home/user/Eglise/restau-bar-pos/public/uploads/integ
exit 0
