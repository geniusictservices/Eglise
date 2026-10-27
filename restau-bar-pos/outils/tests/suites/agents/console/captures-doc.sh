#!/bin/bash
# Clients fictifs (pour montrer chaque statut) puis captures de la documentation ; à lancer après tout.sh
G=$(TZ=Africa/Lubumbashi date +%F)
mysql rbp_console_essai -e "
UPDATE parametres SET valeur='restau-bar-pos' WHERE cle='plateforme.nom';
UPDATE parametres SET valeur='http://127.0.0.1:8199/sms' WHERE cle='sms.url';
DELETE FROM clients WHERE sous_domaine IN ('mamakivu','himbi','virunga','nyira','kituku');
INSERT INTO clients (nom, sous_domaine, contact_nom, contact_telephone, contact_email, langue, formule_id, periode, debut, echeance, essai, installe_le) VALUES
('Chez Mama Kivu','mamakivu','Mama Furaha','+243970000201','mama@kivu.test','fr',1,'mois','2026-05-01',DATE_ADD('$G', INTERVAL 4 DAY),0,NOW()),
('Lounge Himbi','himbi','Eric Bisimwa','+243970000202','eric@himbi.test','fr',2,'trimestre','2026-03-01',DATE_SUB('$G', INTERVAL 3 DAY),0,NOW()),
('Club Virunga','virunga','Sarah Mapendo','+243970000203','sarah@virunga.test','en',3,'mois','2026-02-01',DATE_SUB('$G', INTERVAL 12 DAY),0,NOW()),
('Le Petit Nyira','nyira','Jules Kasereka','+243970000204','jules@nyira.test','fr',1,'annee','2025-11-01',DATE_SUB('$G', INTERVAL 40 DAY),0,NOW()),
('Terrasse Kituku','kituku','Annie Neema','+243970000205','annie@kituku.test','fr',2,'mois','2026-10-20',DATE_ADD('$G', INTERVAL 5 DAY),1,NOW());
INSERT INTO paiements (client_id, montant, devise, moyen, reference, paye_le, periode, periodes, echeance_avant, echeance_apres, saisi_par)
SELECT id, 25, 'USD', 'mobile_money', 'MP261003.0915.K77', DATE_SUB('$G', INTERVAL 20 DAY), 'mois', 1, NULL, echeance, 1 FROM clients WHERE sous_domaine='mamakivu';
INSERT INTO paiements (client_id, montant, devise, moyen, reference, paye_le, periode, periodes, echeance_avant, echeance_apres, saisi_par)
SELECT id, 380000, 'CDF', 'especes', NULL, DATE_SUB('$G', INTERVAL 8 DAY), 'trimestre', 1, NULL, echeance, 1 FROM clients WHERE sous_domaine='himbi';"
cd /home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/console && node captures-doc.js
