-- Données de démonstration de la trésorerie : comptes, transferts et dépenses d'octobre 2026 à Goma.
-- À charger après demo.sql (bin/creer-instance.php --demo), avant les ventes (bin/demo-ventes.php).
-- Trois TYPES de comptes (caisse, mobile money, banque) ; un compte tient une ou plusieurs devises
-- (compte_devises) et a un solde par devise, calculé depuis le journal (mouvements_tresorerie.devise_id).
-- Taux : 1 USD = 2 850 CDF (devise 2), référence USD (devise 1). Contre-valeurs en USD arrondies au centime.
-- Soldes avant les ventes, au 26 octobre :
--   Caisse du Comptoir 1 864 500 FC et 590 $     Caisse du Club 1 056 500 FC et 400 $
--   M-Pesa 359 000 FC et 380 $                    Airtel Money 507 500 FC
--   Rawbank 7 700 $                               Equity BCDC 4 560 000 FC et 1 500 $

SET NAMES utf8mb4;

INSERT INTO comptes_tresorerie (id, nom, type, operateur, numero, etablissement_id, solde_initial_le, position) VALUES
 (1, 'Caisse du Comptoir', 'caisse',       NULL,             NULL,                1,    '2026-10-01 00:00:00', 1),
 (2, 'Caisse du Club',     'caisse',       NULL,             NULL,                2,    '2026-10-01 00:00:00', 2),
 (3, 'M-Pesa',             'mobile_money', 'Vodacom M-Pesa', '+243 81 555 0101',  NULL, '2026-10-01 00:00:00', 1),
 (4, 'Airtel Money',       'mobile_money', 'Airtel Money',   '+243 99 555 0202',  2,    '2026-10-01 00:00:00', 2),
 (5, 'Rawbank',            'banque',       'Rawbank',        '05100-01234567-89', NULL, '2026-10-01 00:00:00', 1),
 (6, 'Equity BCDC',        'banque',       'Equity BCDC',    '1290-0045678-01',   NULL, '2026-10-01 00:00:00', 2);

-- Devises tenues et soldes d'ouverture (au 1er octobre)
INSERT INTO compte_devises (compte_id, devise_id, solde_initial) VALUES
 (1, 2, 1850000), (1, 1, 1450),
 (2, 2, 1200000), (2, 1, 900),
 (3, 2, 1200000), (3, 1, 380),
 (4, 2, 650000),
 (5, 1, 8400),
 (6, 2, 5700000), (6, 1, 1500);

-- Transferts : un dépôt en banque, un change dans la caisse du Club (100 $ → 284 000 FC), un retrait M-Pesa
INSERT INTO transferts_tresorerie (id, compte_source_id, compte_destination_id, devise_source_id, devise_destination_id,
                                   montant_source, montant_destination, taux, date_transfert, motif) VALUES
 (1, 1, 5, 1, 1, 800,    800,    1,    '2026-10-05 18:30:00', 'Dépôt de la recette du week-end'),
 (2, 2, 2, 1, 2, 100,    284000, 2840, '2026-10-12 10:00:00', 'Change de dollars en francs pour la monnaie'),
 (3, 3, 1, 2, 2, 500000, 500000, 1,    '2026-10-20 09:15:00', 'Retrait M-Pesa pour la caisse');

INSERT INTO depenses (id, date_depense, categorie_id, etablissement_id, libelle, beneficiaire, notes) VALUES
 (1,  '2026-10-02 10:00:00', 1,  1,    'Loyer d''octobre du Comptoir du Lac', 'M. Bahati Kambale (propriétaire)', NULL),
 (2,  '2026-10-03 16:20:00', 3,  2,    'Gasoil du groupe électrogène, 200 litres', 'Station Engen, Birere', 'Payé moitié en dollars, moitié en francs, depuis la caisse du Club.'),
 (3,  '2026-10-04 07:45:00', 9,  1,    'Tilapia, légumes et charbon au marché Virunga', 'Marché Virunga', NULL),
 (4,  '2026-10-07 11:30:00', 2,  1,    'Recharge prépayée SNEL', 'SNEL', NULL),
 (5,  '2026-10-10 23:00:00', 7,  2,    'Cachet de DJ Kizz, soirée du samedi', 'DJ Kizz', NULL),
 (6,  '2026-10-14 09:00:00', 4,  1,    'Facture REGIDESO de septembre', 'REGIDESO', NULL),
 (7,  '2026-10-16 15:00:00', 6,  NULL, 'Gardiennage d''octobre, deux agents', 'Kivu Sécurité', NULL),
 (8,  '2026-10-18 13:10:00', 5,  1,    'Réparation du congélateur', 'Atelier Froid Plus, Katindo', 'Pièce payée en dollars, main-d''œuvre en francs.'),
 (9,  '2026-10-21 08:30:00', 10, 1,    'Taxi-moto pour les courses du marché', NULL, NULL),
 (10, '2026-10-24 12:00:00', 11, 2,    'Taxe communale d''affichage', 'Mairie de Goma', NULL);

-- Paiements : compte + devise + montant (deux devises depuis la même caisse pour le gasoil et le congélateur)
INSERT INTO depense_paiements (depense_id, compte_id, devise_id, montant, position) VALUES
 (1, 5, 1, 1500, 0),
 (2, 2, 1, 150, 0), (2, 2, 2, 427500, 1),
 (3, 1, 2, 385000, 0),
 (4, 3, 2, 245000, 0),
 (5, 2, 1, 250, 0),
 (6, 3, 2, 96000, 0),
 (7, 6, 2, 1140000, 0),
 (8, 1, 1, 60, 0), (8, 1, 2, 85500, 1),
 (9, 1, 2, 15000, 0),
 (10, 4, 2, 142500, 0);

-- Journal, écrit depuis les lignes ci-dessus : taux du 1er octobre (USD 1, CDF 2 850), contre-valeur en USD
CREATE TEMPORARY TABLE demo_taux (devise_id INT UNSIGNED PRIMARY KEY, taux DECIMAL(18,6) NOT NULL);
INSERT INTO demo_taux VALUES (1, 1), (2, 2850);

-- Soldes initiaux (source_id = le compte)
INSERT INTO mouvements_tresorerie (compte_id, sens, montant, devise_id, taux, reference_id, contre_valeur, nature, source_id, date_operation, libelle)
SELECT cd.compte_id, 'entree', cd.solde_initial, cd.devise_id, x.taux, 1, ROUND(cd.solde_initial / x.taux, 2), 'solde_initial', cd.compte_id, c.solde_initial_le, NULL
  FROM compte_devises cd JOIN comptes_tresorerie c ON c.id = cd.compte_id JOIN demo_taux x ON x.devise_id = cd.devise_id
 WHERE cd.solde_initial > 0
 ORDER BY cd.compte_id, cd.devise_id;

-- Transferts : sortie de la source dans sa devise, entrée sur la destination dans la sienne
INSERT INTO mouvements_tresorerie (compte_id, sens, montant, devise_id, taux, reference_id, contre_valeur, nature, source_id, date_operation, libelle)
SELECT t.compte_source_id, 'sortie', t.montant_source, t.devise_source_id, x.taux, 1, ROUND(t.montant_source / x.taux, 2), 'transfert', t.id, t.date_transfert, t.motif
  FROM transferts_tresorerie t JOIN demo_taux x ON x.devise_id = t.devise_source_id
 ORDER BY t.id;
INSERT INTO mouvements_tresorerie (compte_id, sens, montant, devise_id, taux, reference_id, contre_valeur, nature, source_id, date_operation, libelle)
SELECT t.compte_destination_id, 'entree', t.montant_destination, t.devise_destination_id, x.taux, 1, ROUND(t.montant_destination / x.taux, 2), 'transfert', t.id, t.date_transfert, t.motif
  FROM transferts_tresorerie t JOIN demo_taux x ON x.devise_id = t.devise_destination_id
 ORDER BY t.id;

-- Dépenses : une sortie par paiement
INSERT INTO mouvements_tresorerie (compte_id, sens, montant, devise_id, taux, reference_id, contre_valeur, nature, source_id, date_operation, libelle)
SELECT p.compte_id, 'sortie', p.montant, p.devise_id, x.taux, 1, ROUND(p.montant / x.taux, 2), 'depense', d.id, d.date_depense, d.libelle
  FROM depense_paiements p JOIN depenses d ON d.id = p.depense_id JOIN demo_taux x ON x.devise_id = p.devise_id
 ORDER BY d.id, p.position;

DROP TEMPORARY TABLE demo_taux;
