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
-- Tailles de la Primus et de la Mützig : même illustration que leur produit principal
UPDATE produits SET photo = '/assets/img/demo/produits/1-primus.svg', photo_credit = NULL WHERE id IN (19, 20);
UPDATE produits SET photo = '/assets/img/demo/produits/2-mutzig.svg', photo_credit = NULL WHERE id IN (21, 22);

UPDATE etablissements SET couverture = '/assets/img/demo/etablissements/comptoir-du-lac.svg', couverture_credit = NULL WHERE id = 1;
UPDATE etablissements SET couverture = '/assets/img/demo/etablissements/volcano-club.svg', couverture_credit = NULL WHERE id = 2;

-- Médiathèque (migration 006) : les 20 illustrations y sont enregistrées pour être réutilisables.
-- (La médiathèque compte les utilisations : l'illustration de la Primus sert trois fois, produit principal et tailles.)
-- Une ligne déjà présente (même chemin) garde son nom, ses textes et son crédit ; seuls le type,
-- les dimensions et le poids sont complétés.
INSERT INTO medias (chemin, nom, type_mime, largeur, hauteur, octets) VALUES
    ('/assets/img/demo/produits/1-primus.svg', 'Primus', 'image/svg+xml', 400, 400, 1278),
    ('/assets/img/demo/produits/2-mutzig.svg', 'Mützig', 'image/svg+xml', 400, 400, 2221),
    ('/assets/img/demo/produits/3-heineken.svg', 'Heineken', 'image/svg+xml', 400, 400, 1417),
    ('/assets/img/demo/produits/4-guinness.svg', 'Guinness', 'image/svg+xml', 400, 400, 1172),
    ('/assets/img/demo/produits/5-coca-cola.svg', 'Coca-Cola', 'image/svg+xml', 400, 400, 1177),
    ('/assets/img/demo/produits/6-eau-minerale.svg', 'Eau minérale', 'image/svg+xml', 400, 400, 1730),
    ('/assets/img/demo/produits/7-jus-maracuja.svg', 'Jus de maracuja', 'image/svg+xml', 400, 400, 2006),
    ('/assets/img/demo/produits/8-kivu-sunset.svg', 'Kivu Sunset', 'image/svg+xml', 400, 400, 1526),
    ('/assets/img/demo/produits/9-mojito.svg', 'Mojito', 'image/svg+xml', 400, 400, 2159),
    ('/assets/img/demo/produits/10-vin-rouge.svg', 'Vin rouge de la maison', 'image/svg+xml', 400, 400, 1832),
    ('/assets/img/demo/produits/11-champagne.svg', 'Moët & Chandon Brut', 'image/svg+xml', 400, 400, 2440),
    ('/assets/img/demo/produits/12-whisky.svg', 'Johnnie Walker Black Label', 'image/svg+xml', 400, 400, 1925),
    ('/assets/img/demo/produits/13-cognac.svg', 'Hennessy VS', 'image/svg+xml', 400, 400, 1125),
    ('/assets/img/demo/produits/14-tilapia.svg', 'Tilapia grillé du lac', 'image/svg+xml', 400, 400, 1683),
    ('/assets/img/demo/produits/15-poulet-braise.svg', 'Poulet braisé', 'image/svg+xml', 400, 400, 2044),
    ('/assets/img/demo/produits/16-brochettes.svg', 'Brochettes de chèvre', 'image/svg+xml', 400, 400, 1716),
    ('/assets/img/demo/produits/17-frites.svg', 'Frites', 'image/svg+xml', 400, 400, 1248),
    ('/assets/img/demo/produits/18-ailes-poulet.svg', 'Ailes de poulet', 'image/svg+xml', 400, 400, 1546),
    ('/assets/img/demo/etablissements/comptoir-du-lac.svg', 'Le Comptoir du Lac · couverture', 'image/svg+xml', 1600, 900, 4028),
    ('/assets/img/demo/etablissements/volcano-club.svg', 'Volcano Club · couverture', 'image/svg+xml', 1600, 900, 4445)
ON DUPLICATE KEY UPDATE type_mime = VALUES(type_mime), largeur = VALUES(largeur), hauteur = VALUES(hauteur), octets = VALUES(octets);
