-- Données de démonstration de la trésorerie : comptes, transferts et dépenses d'octobre 2026 à Goma.
-- À charger après demo.sql (bin/creer-instance.php --demo). Taux : 1 USD = 2 850 CDF (devise 2),
-- référence USD (devise 1). Contre-valeurs en USD arrondies au centime.
-- Soldes au 26 octobre : Caisse USD 990 $, Caisse CDF 3 605 000 FC, M-Pesa USD 380 $, M-Pesa CDF 359 000 FC,
-- Airtel Money CDF 507 500 FC, Rawbank USD 7 700 $, Equity BCDC CDF 4 560 000 FC.

SET NAMES utf8mb4;

INSERT INTO comptes_tresorerie (id, nom, type, devise_id, operateur, numero, etablissement_id, solde_initial, solde_initial_le, position) VALUES
 (1, 'Caisse USD',       'especes',      1, NULL,             NULL,                NULL, 2450,    '2026-10-01 00:00:00', 1),
 (2, 'Caisse CDF',       'especes',      2, NULL,             NULL,                NULL, 3450000, '2026-10-01 00:00:00', 2),
 (3, 'M-Pesa USD',       'mobile_money', 1, 'Vodacom M-Pesa', '+243 81 555 0101',  NULL, 380,     '2026-10-01 00:00:00', 1),
 (4, 'M-Pesa CDF',       'mobile_money', 2, 'Vodacom M-Pesa', '+243 81 555 0101',  NULL, 1200000, '2026-10-01 00:00:00', 2),
 (5, 'Airtel Money CDF', 'mobile_money', 2, 'Airtel Money',   '+243 99 555 0202',  2,    650000,  '2026-10-01 00:00:00', 3),
 (6, 'Rawbank USD',      'banque',       1, 'Rawbank',        '05100-01234567-89', NULL, 8400,    '2026-10-01 00:00:00', 1),
 (7, 'Equity BCDC CDF',  'banque',       2, 'Equity BCDC',    '1290-0045678-01',   NULL, 5700000, '2026-10-01 00:00:00', 2);

INSERT INTO transferts_tresorerie (id, compte_source_id, compte_destination_id, montant_source, montant_destination, taux, date_transfert, motif) VALUES
 (1, 1, 6, 800,    800,    1,    '2026-10-05 18:30:00', 'Dépôt de la recette du week-end'),
 (2, 1, 2, 200,    568000, 2840, '2026-10-12 10:00:00', 'Change en francs au bureau de change de Birere'),
 (3, 4, 2, 500000, 500000, 1,    '2026-10-20 09:15:00', 'Retrait M-Pesa pour la caisse');

INSERT INTO depenses (id, date_depense, categorie_id, etablissement_id, libelle, beneficiaire, notes) VALUES
 (1,  '2026-10-02 10:00:00', 1,  1,    'Loyer d''octobre du Comptoir du Lac', 'M. Bahati Kambale (propriétaire)', NULL),
 (2,  '2026-10-03 16:20:00', 3,  2,    'Gasoil du groupe électrogène, 200 litres', 'Station Engen, Birere', 'Payé moitié en dollars, moitié en francs.'),
 (3,  '2026-10-04 07:45:00', 9,  1,    'Tilapia, légumes et charbon au marché Virunga', 'Marché Virunga', NULL),
 (4,  '2026-10-07 11:30:00', 2,  1,    'Recharge prépayée SNEL', 'SNEL', NULL),
 (5,  '2026-10-10 23:00:00', 7,  2,    'Cachet de DJ Kizz, soirée du samedi', 'DJ Kizz', NULL),
 (6,  '2026-10-14 09:00:00', 4,  1,    'Facture REGIDESO de septembre', 'REGIDESO', NULL),
 (7,  '2026-10-16 15:00:00', 6,  NULL, 'Gardiennage d''octobre, deux agents', 'Kivu Sécurité', NULL),
 (8,  '2026-10-18 13:10:00', 5,  1,    'Réparation du congélateur', 'Atelier Froid Plus, Katindo', 'Pièce payée en dollars, main-d''œuvre en francs.'),
 (9,  '2026-10-21 08:30:00', 10, 1,    'Taxi-moto pour les courses du marché', NULL, NULL),
 (10, '2026-10-24 12:00:00', 11, 2,    'Taxe communale d''affichage', 'Mairie de Goma', NULL);

INSERT INTO depense_paiements (depense_id, compte_id, montant, position) VALUES
 (1, 6, 1500, 0),
 (2, 1, 150, 0), (2, 2, 427500, 1),
 (3, 2, 385000, 0),
 (4, 4, 245000, 0),
 (5, 1, 250, 0),
 (6, 4, 96000, 0),
 (7, 7, 1140000, 0),
 (8, 1, 60, 0), (8, 2, 85500, 1),
 (9, 2, 15000, 0),
 (10, 5, 142500, 0);

INSERT INTO mouvements_tresorerie (compte_id, sens, montant, devise_id, taux, reference_id, contre_valeur, nature, source_id, date_operation, libelle) VALUES
 -- Soldes initiaux
 (1, 'entree', 2450,    1, 1,    1, 2450,    'solde_initial', 1, '2026-10-01 00:00:00', NULL),
 (2, 'entree', 3450000, 2, 2850, 1, 1210.53, 'solde_initial', 2, '2026-10-01 00:00:00', NULL),
 (3, 'entree', 380,     1, 1,    1, 380,     'solde_initial', 3, '2026-10-01 00:00:00', NULL),
 (4, 'entree', 1200000, 2, 2850, 1, 421.05,  'solde_initial', 4, '2026-10-01 00:00:00', NULL),
 (5, 'entree', 650000,  2, 2850, 1, 228.07,  'solde_initial', 5, '2026-10-01 00:00:00', NULL),
 (6, 'entree', 8400,    1, 1,    1, 8400,    'solde_initial', 6, '2026-10-01 00:00:00', NULL),
 (7, 'entree', 5700000, 2, 2850, 1, 2000,    'solde_initial', 7, '2026-10-01 00:00:00', NULL),
 -- Transferts : sortie de la source, entrée sur la destination
 (1, 'sortie', 800,     1, 1,    1, 800,     'transfert', 1, '2026-10-05 18:30:00', 'Dépôt de la recette du week-end'),
 (6, 'entree', 800,     1, 1,    1, 800,     'transfert', 1, '2026-10-05 18:30:00', 'Dépôt de la recette du week-end'),
 (1, 'sortie', 200,     1, 1,    1, 200,     'transfert', 2, '2026-10-12 10:00:00', 'Change en francs au bureau de change de Birere'),
 (2, 'entree', 568000,  2, 2850, 1, 199.30,  'transfert', 2, '2026-10-12 10:00:00', 'Change en francs au bureau de change de Birere'),
 (4, 'sortie', 500000,  2, 2850, 1, 175.44,  'transfert', 3, '2026-10-20 09:15:00', 'Retrait M-Pesa pour la caisse'),
 (2, 'entree', 500000,  2, 2850, 1, 175.44,  'transfert', 3, '2026-10-20 09:15:00', 'Retrait M-Pesa pour la caisse'),
 -- Dépenses : une sortie par paiement
 (6, 'sortie', 1500,    1, 1,    1, 1500,    'depense', 1,  '2026-10-02 10:00:00', 'Loyer d''octobre du Comptoir du Lac'),
 (1, 'sortie', 150,     1, 1,    1, 150,     'depense', 2,  '2026-10-03 16:20:00', 'Gasoil du groupe électrogène, 200 litres'),
 (2, 'sortie', 427500,  2, 2850, 1, 150,     'depense', 2,  '2026-10-03 16:20:00', 'Gasoil du groupe électrogène, 200 litres'),
 (2, 'sortie', 385000,  2, 2850, 1, 135.09,  'depense', 3,  '2026-10-04 07:45:00', 'Tilapia, légumes et charbon au marché Virunga'),
 (4, 'sortie', 245000,  2, 2850, 1, 85.96,   'depense', 4,  '2026-10-07 11:30:00', 'Recharge prépayée SNEL'),
 (1, 'sortie', 250,     1, 1,    1, 250,     'depense', 5,  '2026-10-10 23:00:00', 'Cachet de DJ Kizz, soirée du samedi'),
 (4, 'sortie', 96000,   2, 2850, 1, 33.68,   'depense', 6,  '2026-10-14 09:00:00', 'Facture REGIDESO de septembre'),
 (7, 'sortie', 1140000, 2, 2850, 1, 400,     'depense', 7,  '2026-10-16 15:00:00', 'Gardiennage d''octobre, deux agents'),
 (1, 'sortie', 60,      1, 1,    1, 60,      'depense', 8,  '2026-10-18 13:10:00', 'Réparation du congélateur'),
 (2, 'sortie', 85500,   2, 2850, 1, 30,      'depense', 8,  '2026-10-18 13:10:00', 'Réparation du congélateur'),
 (2, 'sortie', 15000,   2, 2850, 1, 5.26,    'depense', 9,  '2026-10-21 08:30:00', 'Taxi-moto pour les courses du marché'),
 (5, 'sortie', 142500,  2, 2850, 1, 50,      'depense', 10, '2026-10-24 12:00:00', 'Taxe communale d''affichage');
