#!/bin/bash
# Retire tout ce que les tests de la console ont créé (bases, utilisateurs MySQL, fichiers d'instance, serveurs, hosts)
D=/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/console
cd /home/user/Eglise/restau-bar-pos
for f in serveur faux-services serveur-ko serveur-web serveur-libre; do [ -f $D/$f.pid ] && kill $(cat $D/$f.pid) 2>/dev/null; rm -f $D/$f.pid; done
for port in 8180 8181 8182 8183 8199; do for pid in $(ss -ltnp | grep ":$port " | grep -o "pid=[0-9]*" | cut -d= -f2); do kill $pid; done; done
mysql -e "DROP DATABASE IF EXISTS rbp_console_essai; DROP DATABASE IF EXISTS rbp_cnsl_neuf; DROP DATABASE IF EXISTS rbp_cnslbar; DROP DATABASE IF EXISTS rbp_console_web; DROP DATABASE IF EXISTS rbp_cnsl_restaure;
  DROP USER IF EXISTS 'rbp_cnslbar'@'localhost'; DROP USER IF EXISTS 'rbp_cnslbar'@'127.0.0.1'; DROP USER IF EXISTS 'rbp_cnsl'@'localhost'; DROP USER IF EXISTS 'rbp_cnsl'@'127.0.0.1';"
rm -f config/instances/cnslneuf.php config/instances/cnslbar.php storage/console-installation.code storage/installation-*.txt public/uploads/cnslbar -r
sed -i '/admin.localhost cnslbar.localhost cnslneuf.localhost/d' /etc/hosts
echo "nettoyé"
