-- Illustrations SVG des données de démonstration (style « Volcan »).
-- Dessins originaux du projet, sans marque : photo_credit et couverture_credit restent vides.
-- À charger après demo.sql : mariadb rbp_demo < database/demo-illustrations.sql

SET NAMES utf8mb4;

UPDATE produits SET photo = '/assets/img/demo/produits/1-primus.svg', photo_credit = NULL WHERE id = 1;
UPDATE produits SET photo = '/assets/img/demo/produits/2-mutzig.svg', photo_credit = NULL WHERE id = 2;
UPDATE produits SET photo = '/assets/img/demo/produits/3-heineken.svg', photo_credit = NULL WHERE id = 3;
UPDATE produits SET photo = '/assets/img/demo/produits/4-guinness.svg', photo_credit = NULL WHERE id = 4;
UPDATE produits SET photo = '/assets/img/demo/produits/5-coca-cola.svg', photo_credit = NULL WHERE id = 5;
UPDATE produits SET photo = '/assets/img/demo/produits/6-eau-minerale.svg', photo_credit = NULL WHERE id = 6;
UPDATE produits SET photo = '/assets/img/demo/produits/7-jus-maracuja.svg', photo_credit = NULL WHERE id = 7;
UPDATE produits SET photo = '/assets/img/demo/produits/8-kivu-sunset.svg', photo_credit = NULL WHERE id = 8;
UPDATE produits SET photo = '/assets/img/demo/produits/9-mojito.svg', photo_credit = NULL WHERE id = 9;
UPDATE produits SET photo = '/assets/img/demo/produits/10-vin-rouge.svg', photo_credit = NULL WHERE id = 10;
UPDATE produits SET photo = '/assets/img/demo/produits/11-champagne.svg', photo_credit = NULL WHERE id = 11;
UPDATE produits SET photo = '/assets/img/demo/produits/12-whisky.svg', photo_credit = NULL WHERE id = 12;
UPDATE produits SET photo = '/assets/img/demo/produits/13-cognac.svg', photo_credit = NULL WHERE id = 13;
UPDATE produits SET photo = '/assets/img/demo/produits/14-tilapia.svg', photo_credit = NULL WHERE id = 14;
UPDATE produits SET photo = '/assets/img/demo/produits/15-poulet-braise.svg', photo_credit = NULL WHERE id = 15;
UPDATE produits SET photo = '/assets/img/demo/produits/16-brochettes.svg', photo_credit = NULL WHERE id = 16;
UPDATE produits SET photo = '/assets/img/demo/produits/17-frites.svg', photo_credit = NULL WHERE id = 17;
UPDATE produits SET photo = '/assets/img/demo/produits/18-ailes-poulet.svg', photo_credit = NULL WHERE id = 18;

UPDATE etablissements SET couverture = '/assets/img/demo/etablissements/comptoir-du-lac.svg', couverture_credit = NULL WHERE id = 1;
UPDATE etablissements SET couverture = '/assets/img/demo/etablissements/volcano-club.svg', couverture_credit = NULL WHERE id = 2;
