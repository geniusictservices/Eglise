#!/bin/bash
# Toute la suite de tests de la console, depuis zéro (bases d'essai recréées ; rbp_demo n'est jamais touchée)
D=/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/console
P=/home/user/Eglise/restau-bar-pos
cd $P
export RBP_CONSOLE_CONFIG=$D/console.php
grep -q "admin.localhost cnslbar.localhost" /etc/hosts || echo "127.0.0.1 admin.localhost cnslbar.localhost cnslneuf.localhost" >> /etc/hosts
mysql -e "CREATE USER IF NOT EXISTS 'rbp_cnsl'@'localhost' IDENTIFIED BY 'Essai-Console-2026'; CREATE USER IF NOT EXISTS 'rbp_cnsl'@'127.0.0.1' IDENTIFIED BY 'Essai-Console-2026';"
mysql -e "DROP DATABASE IF EXISTS rbp_console_essai; DROP DATABASE IF EXISTS rbp_cnsl_neuf; DROP DATABASE IF EXISTS rbp_cnslbar; DROP USER IF EXISTS 'rbp_cnslbar'@'localhost'; DROP USER IF EXISTS 'rbp_cnslbar'@'127.0.0.1';
  CREATE DATABASE rbp_console_essai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE rbp_cnsl_neuf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL ON rbp_console_essai.* TO 'rbp_cnsl'@'localhost'; GRANT ALL ON rbp_console_essai.* TO 'rbp_cnsl'@'127.0.0.1';
  GRANT ALL ON rbp_cnsl_neuf.* TO 'rbp_cnsl'@'localhost'; GRANT ALL ON rbp_cnsl_neuf.* TO 'rbp_cnsl'@'127.0.0.1';"
rm -f config/instances/cnslneuf.php config/instances/cnslbar.php
php bin/console-admin.php installer > /dev/null && php bin/console-admin.php admin --email=super@plateforme.test --nom="Super Admin" --mot-de-passe='Console-Goma-2026!' > /dev/null
php bin/creer-instance.php cnslbar --email=patron@cnslbar.test --nom="Bar Essai Console" --demo --db-hote=localhost > $D/creer-cnslbar.txt 2>&1 || { echo "creer-instance a échoué"; cat $D/creer-cnslbar.txt; exit 1; }
$D/serveur.sh
[ -f $D/faux-services.pid ] && kill $(cat $D/faux-services.pid) 2>/dev/null
(cd $D && setsid nohup php -S 127.0.0.1:8199 faux-services.php > $D/faux-services-serveur.log 2>&1 < /dev/null & echo $! > $D/faux-services.pid); sleep 0.5
echo '{}' > $D/etat.json
total=0; echecs=0
for t in t1-connexion-creation t2-abonnement t3-messagerie t4-instances t5-relances-installation t6-affichage t7-sans-console; do
  node $D/$t.js > $D/$t.log 2>&1; code=$?
  bilan=$(tail -1 $D/$t.log)
  echo "$t : $bilan"; grep -E "^ÉCHEC|Error|erreur JS" $D/$t.log | grep -v "aucune erreur JS" | head -5
  [ $code -ne 0 ] && echecs=$((echecs+1))
done
echo "Erreurs PHP du serveur :"; grep -iE "warning|fatal|notice|deprecated" $D/serveur.log | head -5
echo "Suites en échec : $echecs"
