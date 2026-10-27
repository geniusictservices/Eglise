#!/bin/sh
# Démo reconstruite de zéro sous le nom « integ » (bin/creer-instance.php --demo), mot de passe du propriétaire
# fixé (Essai-Integ-2026, aussi dans instance.txt), puis base et photos gardées comme état de départ de chaque suite (restaurer.sh)
I=/home/user/Eglise/restau-bar-pos/outils/tests
cd /home/user/Eglise/restau-bar-pos
mysql -e "DROP DATABASE IF EXISTS rbp_integ; DROP USER IF EXISTS 'rbp_integ'@'localhost'; DROP USER IF EXISTS 'rbp_integ'@'127.0.0.1';"
rm -f config/instances/integ.php; rm -rf public/uploads/integ
php bin/creer-instance.php integ --email=admin@demo.test --demo --db-hote=localhost > $I/creer-integ.txt 2>&1 || { cat $I/creer-integ.txt; exit 1; }
# Mot de passe fixe du propriétaire, connu des suites
H=$(php -r 'echo password_hash("Essai-Integ-2026", PASSWORD_DEFAULT);')
mysql rbp_integ -e "UPDATE utilisateurs SET mot_de_passe = '$H' WHERE id = 1"
echo "Mot de passe : Essai-Integ-2026" > $I/instance.txt; cp $I/instance.txt $I/suites/instance.txt
mysqldump --single-transaction --routines rbp_integ > $I/pristine.sql
rm -rf $I/uploads-pristine; cp -a public/uploads/integ $I/uploads-pristine
grep -iE "erreur|non cré|échou|fatal|warning" $I/creer-integ.txt
echo "démo integ reconstruite"
