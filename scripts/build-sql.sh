#!/usr/bin/env bash
# Prépare les scripts SQL pour une installation sans terminal (phpMyAdmin) :
#   database/sql/installation.sql : la base complète et vide, toutes migrations appliquées.
# Usage : scripts/build-sql.sh   (MariaDB ou MySQL local, accès root sans mot de passe ; sinon MYSQL="mysql -u… -p…")
set -euo pipefail
cd "$(dirname "$0")/.."

MYSQL=${MYSQL:-mysql -uroot}
MYSQLDUMP=${MYSQLDUMP:-mysqldump -uroot}
DB=waumini_sql_$$
OUT=database/sql/installation.sql

$MYSQL -e "CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
trap '$MYSQL -e "DROP DATABASE IF EXISTS \`$DB\`"' EXIT
DB_DATABASE=$DB APP_ENV=production php artisan migrate --force --no-interaction >/dev/null

LAST=$($MYSQL -N "$DB" -e "SELECT migration FROM migrations ORDER BY id DESC LIMIT 1")
{
  echo "-- Waumini : base de données vide, prête pour une première installation."
  echo "-- Toutes les migrations sont appliquées, jusqu'à $LAST."
  echo "-- À importer UNE SEULE FOIS dans une base vide (phpMyAdmin › Importer), en utf8mb4."
  echo "-- Ne pas importer sur une base déjà en service : utiliser plutôt le fichier de mise à jour."
  echo
  # Sans la ligne « sandbox » des MariaDB récents, que MySQL et les anciennes versions refusent.
  $MYSQLDUMP --skip-comments --skip-dump-date --no-tablespaces --skip-add-locks --skip-lock-tables \
    --single-transaction --default-character-set=utf8mb4 "$DB" | grep -v 'enable the sandbox mode'
} > "$OUT"
echo "Écrit : $OUT ($(grep -c '^CREATE TABLE' "$OUT") tables, dernière migration $LAST)"
