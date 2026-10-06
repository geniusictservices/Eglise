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
rm -rf "$WORK/tests" "$WORK/node_modules" "$WORK/.github" "$WORK/docs" "$WORK/branding/generate.py" "$WORK/Hekalu - Pitch deck.pdf"
(cd "$WORK" && zip -qr "$OLDPWD/$OUT" . -x '.env')
rm -rf "$WORK"
echo "Archive prête : $OUT"
