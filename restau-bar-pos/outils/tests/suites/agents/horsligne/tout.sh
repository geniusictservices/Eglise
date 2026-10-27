#!/bin/sh
# Toutes les suites : régression de la caisse (Salle + reg), Nuit de bout en bout, puis le mode hors ligne
cd "$(dirname "$0")"
export NODE_PATH=$(npm root -g)
echo "== Régression caisse (salle/tests + salle/reg)"; ./reg/tout.sh resultats-final
echo "== Nuit (e2e)"; ./e2e/tout.sh
mkdir -p resultats
echo "== Mode hors ligne"
for f in personnel partagee nuit; do
  timeout 900 node $f.js > resultats/$f.log 2>&1
  echo "$f : $(grep -E '^[0-9]+/[0-9]+' resultats/$f.log | tail -1) $(grep -c '^ÉCHEC' resultats/$f.log) échecs"
done
