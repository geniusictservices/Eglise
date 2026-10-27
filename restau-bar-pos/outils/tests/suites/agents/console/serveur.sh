#!/bin/sh
# Serveur de test de la console (port 8180), avec la console d'essai et le faux sendmail
cd /home/user/Eglise/restau-bar-pos
[ -f /home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/console/serveur.pid ] && kill $(cat /home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/console/serveur.pid) 2>/dev/null
for pid in $(ss -ltnp | grep ":8180 " | grep -o "pid=[0-9]*" | cut -d= -f2); do kill $pid; done
sleep 0.3
RBP_CONSOLE_CONFIG=/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/console/console.php setsid nohup php -d sendmail_path=/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/console/sendmail.sh -S 127.0.0.1:8180 -t public public/index.php > /home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/console/serveur.log 2>&1 < /dev/null &
echo $! > /home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/console/serveur.pid
sleep 0.7
