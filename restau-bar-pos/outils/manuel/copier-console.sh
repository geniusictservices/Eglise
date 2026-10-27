#!/bin/sh
# Écrans de la console super admin : captures de la documentation (docs/captures/console), recompressées pour le manuel
cd /home/user/Eglise/restau-bar-pos
for n in connexion tableau-de-bord tableau-de-bord-telephone clients nouveau-client installation-instance fiche-abonnement fiche-paiements fiche-statut fiche-instance fiche-assistance formules formule messagerie-africastalking messagerie-whatsapp messagerie-usage messagerie-essai reglages journal installation-console installation-console-admin instance-assistance instance-lecture-seule instance-caisse-fermee; do
  convert docs/captures/console/$n.jpg -strip -quality 72 docs/manuel/captures/console-$n.jpg
done
