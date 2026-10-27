#!/bin/bash
# Usage: smoke.sh email mdp chemin...
B=http://integ.localhost:8250; J=$(mktemp)
csrf=$(curl -s -c $J -b $J $B/gestion/connexion | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -c $J -b $J -o /dev/null -d "csrf=$csrf&email=$1&mot_de_passe=$2" $B/gestion/connexion
shift 2
for p in "$@"; do
  out=$(curl -s -c $J -b $J -w "\n%{http_code}" "$B$p")
  code=$(echo "$out" | tail -1)
  err=$(echo "$out" | grep -oiE "(fatal error|warning|notice|deprecated|uncaught)[^<]{0,200}" | head -3)
  raw=$(echo "$out" | sed 's/<[^>]*>//g' | grep -oE "\b(gclients|gdettes|gremb|garanties|messagerie|rcreances|gventes|ecran|etab)\.[a-z_]{3,}" | sort -u | head -5 | tr '\n' ' ')
  echo "$code $p $err $raw"
done
rm -f $J
