#!/usr/bin/env bash
# Prépare une archive prête à envoyer sur l'hébergement mutualisé (FTP ou gestionnaire de fichiers).
# Contient le code, les dépendances PHP de production et les fichiers compilés.
# Usage : scripts/build-release.sh   →  waumini-release-AAAAMMJJ-HHMM.zip
set -euo pipefail
cd "$(dirname "$0")/.."

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
rm -rf "$WORK/tests" "$WORK/node_modules" "$WORK/.github" "$WORK/docs/feuille-de-route.html" "$WORK/branding/generate.py" "$WORK/Hekalu - Pitch deck.pdf"
(cd "$WORK" && zip -qr "$OLDPWD/$OUT" . -x '.env')
rm -rf "$WORK"
echo "Archive prête : $OUT"
