#!/bin/bash
# Relances automatiques avec le faux service (port 8198) ; la base doit être restaurée ensuite
D=/home/user/Eglise/restau-bar-pos/outils/tests/suites/dettes
cd /home/user/Eglise/restau-bar-pos
q() { mysql -N --default-character-set=utf8mb4 rbp_integ -e "$1"; }
nb=0; ko=0; v() { nb=$((nb+1)); if [ "$1" = "$2" ]; then echo "ok    $3"; else ko=$((ko+1)); echo "ÉCHEC $3 (attendu « $2 », obtenu « $1 »)"; fi; }
: > $D/mock.log
# Depuis la décision « messagerie commune réglée dans la console » (cahier des charges, section 17), les clés SMS et
# WhatsApp ne sont plus dans l'instance : une console d'essai (rbp_integ_console) les porte ; sans elle, tout est « simulé ».
export RBP_CONSOLE_CONFIG=$D/console-integ.php
cat > $RBP_CONSOLE_CONFIG <<'PHP'
<?php return ['db' => ['hote' => 'localhost', 'port' => 3306, 'nom' => 'rbp_integ_console', 'utilisateur' => 'rbp_demo', 'mot_de_passe' => '6e9bb19352b399f128f1fcf86faf02c6'], 'jeton_installation' => '', 'cache_licence_secondes' => 0];
PHP
mysql -e "DROP DATABASE IF EXISTS rbp_integ_console; CREATE DATABASE rbp_integ_console CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON rbp_integ_console.* TO 'rbp_demo'@'localhost';"
php bin/console-admin.php installer > /dev/null || echo "console d'essai : installation impossible"
c() { mysql -N --default-character-set=utf8mb4 rbp_integ_console -e "$1"; }
# Les ardoises de la démo (44 ouvertes) sont mises de côté : seules les trois dettes du test sont relancées
q "UPDATE dettes SET statut = 'remise' WHERE statut = 'ouverte'"
q "DELETE FROM messages"
# Client relancé par SMS, dette échue demain ; client WhatsApp, dette en retard de 8 jours (étape J+7)
q "INSERT INTO clients (nom, telephone, langue, canal) VALUES ('Furaha Neema', '+243970000001', 'fr', 'sms'), ('John Doe', '+243970000002', 'en', 'whatsapp'), ('Sans Relance', '+243970000003', 'fr', 'sms')"
q "UPDATE clients SET relances = 0 WHERE telephone = '+243970000003'"
J=2026-11-10
q "INSERT INTO dettes (client_id, etablissement_id, devise_id, montant, reste, echeance, servi_par, accorde_par, responsable_id, garant_id, garant_statut)
   SELECT id, 1, 2, 45000, 45000, '2026-11-11', 4, 8, 4, 2, 'en_attente' FROM clients WHERE telephone = '+243970000001'"
q "INSERT INTO dettes (client_id, etablissement_id, devise_id, montant, reste, echeance, servi_par, accorde_par, responsable_id)
   SELECT id, 2, 1, 12.5, 12.5, '2026-11-02', 6, 2, 6 FROM clients WHERE telephone = '+243970000002'"
q "INSERT INTO dettes (client_id, etablissement_id, devise_id, montant, reste, echeance, servi_par, accorde_par, responsable_id)
   SELECT id, 1, 2, 5000, 5000, '2026-11-09', 5, 8, 5 FROM clients WHERE telephone = '+243970000003'"

# 1. Sans messagerie réglée : tout est seulement inscrit
c "DELETE FROM parametres WHERE cle LIKE 'sms.%' OR cle LIKE 'whatsapp.%'"
php bin/relances.php integ --date=$J > $D/r1.txt; cat $D/r1.txt
v "$(q "SELECT COUNT(*) FROM messages WHERE statut='simule'")" "6" "sans réglage : 6 messages inscrits (garant, 2 clients, 3 responsables), aucun envoi"
v "$(wc -l < $D/mock.log | tr -d ' ')" "0" "sans réglage : aucune requête vers les services"
v "$(q "SELECT COUNT(*) FROM messages WHERE destinataire='+243970000003'")" "0" "client « sans relance » : rien"
v "$(q "SELECT etape FROM messages WHERE evenement='rappel_client' AND destinataire='+243970000001'")" "J-1" "client SMS : étape J-1 (veille)"
v "$(q "SELECT etape FROM messages WHERE evenement='rappel_client' AND destinataire='+243970000002'")" "J+7" "client en retard de 8 jours : étape J+7"
v "$(q "SELECT texte FROM messages WHERE evenement='rappel_client' AND destinataire='+243970000002'")" "Volcano Club: hello John, your tab of \$12.50 has been due since Nov 2, 2026 (8 days late). Please come and settle it." "message dans la langue du client (anglais), prénom, montant en dollars"
v "$(q "SELECT texte FROM messages WHERE evenement='rappel_client' AND destinataire='+243970000001'")" "Le Comptoir du Lac : bonjour Furaha, rappel : votre ardoise de 45 000 FC est à régler avant le 11/11/2026. Merci !" "message français, espaces simples (SMS en 7 bits)"
v "$(q "SELECT COUNT(*) FROM messages WHERE evenement='rappel_responsable' AND utilisateur_id=4")" "1" "la serveuse responsable (Aline) est prévenue"
v "$(q "SELECT COUNT(*) FROM messages WHERE evenement='garant_demande' AND utilisateur_id=2")" "1" "le garant désigné (Patrick) est prévenu"
php bin/relances.php integ --date=$J > /dev/null
v "$(q "SELECT COUNT(*) FROM messages")" "6" "deuxième passage le même jour : rien de plus"

# 2. Avec Africa's Talking et WhatsApp (faux services)
q "DELETE FROM messages"
c "INSERT INTO parametres (cle, valeur) VALUES ('sms.utilisateur','sandbox'),('sms.cle','atsk_cle_secrete_1234'),('sms.expediteur','COMPTOIR'),('sms.url','http://127.0.0.1:8198/sms'),
   ('whatsapp.numero_id','1098765'),('whatsapp.jeton','EAAG_jeton'),('whatsapp.url','http://127.0.0.1:8198/wa'),('whatsapp.modele_rappel_client','rappel_ardoise'),('whatsapp.langue_en','en_US')
   ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)"
php bin/relances.php integ --date=$J > $D/r2.txt; cat $D/r2.txt
v "$(q "SELECT CONCAT(canal,':',statut) FROM messages WHERE evenement='rappel_client' AND destinataire='+243970000001'")" "sms:envoye" "client SMS : envoyé par Africa's Talking"
v "$(q "SELECT CONCAT(canal,':',statut,':',LEFT(fournisseur_id,5)) FROM messages WHERE evenement='rappel_client' AND destinataire='+243970000002'")" "whatsapp:envoye:wamid" "client WhatsApp : envoyé par l'API Cloud (identifiant wamid…)"
v "$(q "SELECT CONCAT(canal,':',statut) FROM messages WHERE evenement='rappel_responsable' AND utilisateur_id=6")" "sms:envoye" "responsable : pas de modèle WhatsApp pour lui, SMS"
v "$(grep -c '"/sms"' $D/mock.log)" "5" "5 SMS demandés (garant, client SMS, 3 responsables) ; le client WhatsApp par WhatsApp"
v "$(grep '"/sms"' $D/mock.log | head -1 | grep -c 'atsk_cle_secrete_1234')" "1" "clé d'API dans l'en-tête apiKey"
v "$(grep '"/sms"' $D/mock.log | grep -c 'from=COMPTOIR')" "5" "expéditeur (sender ID) envoyé"
v "$(grep '"/wa"' $D/mock.log | grep -c 'rappel_ardoise')" "1" "WhatsApp : modèle « rappel_ardoise »"
v "$(grep '"/wa"' $D/mock.log | grep -c 'en_US')" "1" "WhatsApp : langue du modèle en_US pour un client anglais"
v "$(grep '"/wa"' $D/mock.log | grep -c 'Bearer EAAG_jeton')" "1" "WhatsApp : jeton dans Authorization"
v "$(grep "\"/wa\"" $D/mock.log | grep -c "243970000002")" "1" "WhatsApp : numéro sans + (243…)"

# 3. Échec de WhatsApp : le SMS prend le relais ; échec des deux : statut echec, code 2
q "DELETE FROM messages"; : > $D/mock.log
c "UPDATE parametres SET valeur='http://127.0.0.1:8198/wa-ko' WHERE cle='whatsapp.url'"
php bin/relances.php integ --date=$J > /dev/null
v "$(q "SELECT CONCAT(canal,':',statut) FROM messages WHERE evenement='rappel_client' AND destinataire='+243970000002'")" "sms:envoye" "WhatsApp refusé (modèle inconnu) : SMS de secours"
q "DELETE FROM messages"
c "UPDATE parametres SET valeur='http://127.0.0.1:8198/sms-ko' WHERE cle='sms.url'"
php bin/relances.php integ --date=$J > $D/r3.txt; code=$?
v "$code" "2" "échecs : le script sort avec le code 2 (alerte du planificateur)"
v "$(q "SELECT statut FROM messages WHERE evenement='rappel_client' AND destinataire='+243970000002'")" "echec" "les deux canaux en échec : statut echec"
v "$(q "SELECT erreur FROM messages WHERE evenement='rappel_client' AND destinataire='+243970000002'" | grep -c 'InsufficientBalance')" "1" "erreur gardée (solde Africa's Talking insuffisant)"

# 4. Réglages
q "UPDATE parametres SET valeur='0' WHERE cle='relances.actives'"; q "DELETE FROM messages"
php bin/relances.php integ --date=$J > /dev/null
v "$(q "SELECT COUNT(*) FROM messages")" "0" "relances désactivées : rien"
q "UPDATE parametres SET valeur='1' WHERE cle='relances.actives'"; q "UPDATE parametres SET valeur='0' WHERE cle='relances.responsable'"
c "DELETE FROM parametres WHERE cle LIKE 'sms.%' OR cle LIKE 'whatsapp.%'"
php bin/relances.php integ --date=$J > /dev/null
v "$(q "SELECT COUNT(*) FROM messages WHERE evenement='rappel_responsable'")" "0" "responsables non prévenus si le réglage est coupé"
mysql -e "DROP DATABASE IF EXISTS rbp_integ_console"; rm -f $RBP_CONSOLE_CONFIG
echo; echo "$((nb-ko))/$nb"
