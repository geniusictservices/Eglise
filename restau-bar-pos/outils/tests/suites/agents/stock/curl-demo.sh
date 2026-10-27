#!/bin/sh
# Connexion de l'administrateur à essaistock ; cookies dans jar.txt
B=http://demo.localhost:8190
rm -f jar.txt
CSRF=$(curl -s -c jar.txt -b jar.txt $B/gestion/connexion | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -c jar.txt -b jar.txt -o /dev/null -w "%{http_code}\n" -d "csrf=$CSRF&email=${1:-admin@demo.test}&mot_de_passe=${2:-Essai-Integ-2026}" $B/gestion/connexion
