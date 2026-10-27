#!/bin/sh
# Toutes les suites de bout en bout du module Nuit, une par une (chacune restaure la base d'essai)
cd "$(dirname "$0")"
export NODE_PATH=$(npm root -g)
mkdir -p resultats
for f in remises formules-minimum entrees-invites cashless-vestiaire-fidelite ecrans; do
  timeout 900 node $f.js > resultats/$f.log 2>&1
  echo "$f : $(grep -E '^[0-9]+/[0-9]+' resultats/$f.log | tail -1) $(grep -c '^ÉCHEC' resultats/$f.log) échecs"
done
