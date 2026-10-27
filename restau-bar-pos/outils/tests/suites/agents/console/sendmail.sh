#!/bin/sh
# Faux sendmail : garde chaque e-mail dans mails.log
{ echo "=== $(date +%H:%M:%S) $*"; cat; echo; } >> /home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/console/mails.log
