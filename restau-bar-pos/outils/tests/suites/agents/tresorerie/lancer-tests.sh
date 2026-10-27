#!/bin/sh
# Remet la base démo dans son état de référence (migration + demo-tresorerie), lance le test,
# puis remet la base dans le même état et enregistre la vue de référence apres-demo.sql.
D=$(dirname "$0")
mysql -u root rbp_integ < "$D/avant-tests.sql"
node "$D/e2e.js" > "$D/resultats.txt" 2>&1; statut=$?
mysql -u root rbp_integ < "$D/avant-tests.sql"
mysqldump -u root --skip-dump-date rbp_integ > "$D/apres-demo.sql"
cmp -s "$D/avant-tests.sql" "$D/apres-demo.sql" && echo "base restaurée à l'identique" || echo "ATTENTION : la base diffère de avant-tests.sql"
tail -3 "$D/resultats.txt"; grep ECHEC "$D/resultats.txt"
exit $statut
