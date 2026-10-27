#!/bin/bash
# usage: login.sh email mdp cookiejar
H=http://127.0.0.1:8220
rm -f $3
csrf=$(curl -s -c $3 -b $3 "$H/gestion/connexion?lang=fr" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -c $3 -b $3 -o /dev/null -w '%{http_code}\n' -X POST "$H/gestion/connexion" --data-urlencode "csrf=$csrf" --data-urlencode "email=$1" --data-urlencode "mot_de_passe=$2"
