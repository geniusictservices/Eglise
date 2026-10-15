#!/usr/bin/env bash
# Prépare une archive prête à envoyer sur l'hébergement mutualisé (FTP ou gestionnaire de fichiers).
# Contient le code, les dépendances PHP de production et les fichiers compilés.
# Usage : scripts/build-release.sh [--sans-captures] [--sans-vendor]   →  waumini-release-AAAAMMJJ-HHMM.zip
# --sans-captures : sans les captures du manuel (35 Mo), pour une mise à jour où elles sont déjà sur le serveur.
# --sans-vendor   : sans les dépendances PHP, pour une mise à jour où composer.lock n'a changé aucune version
#                   (le dossier vendor du serveur reste alors valable). L'archive ne pèse plus que quelques Mo.
set -euo pipefail
cd "$(dirname "$0")/.."

SANS_CAPTURES=0; SANS_VENDOR=0
for arg in "$@"; do
  case "$arg" in
    --sans-captures) SANS_CAPTURES=1 ;;
    --sans-vendor) SANS_VENDOR=1 ;;
    *) echo "Option inconnue : $arg" >&2; exit 1 ;;
  esac
done

STAMP=$(date +%Y%m%d-%H%M)
WORK=$(mktemp -d)
OUT="waumini-release-${STAMP}.zip"

npm ci && npm run build
git archive --format=tar HEAD | tar -x -C "$WORK"
cp -r public/build "$WORK/public/build"
(cd "$WORK" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist)
# Rien d'inutile sur le serveur : historiques Git, tests et cartes de sources des bibliothèques.
find "$WORK/vendor" -name .git -type d -prune -exec rm -rf {} +
find "$WORK/vendor" -type d \( -name tests -o -name Tests -o -name .github \) -prune -exec rm -rf {} +
find "$WORK/vendor" -type f -name '*.map' -delete
if [[ $SANS_CAPTURES == 1 ]]; then rm -rf "$WORK/docs/manuel/captures"; fi
if [[ $SANS_VENDOR == 1 ]]; then rm -rf "$WORK/vendor"; fi
rm -rf "$WORK/docs/livret" "$WORK/tests" "$WORK/node_modules" "$WORK/.github" "$WORK/docs/feuille-de-route.html" "$WORK/branding/generate.py" "$WORK/Hekalu - Pitch deck.pdf"
(cd "$WORK" && zip -qr "$OLDPWD/$OUT" . -x '.env')
rm -rf "$WORK"
echo "Archive prête : $OUT"
