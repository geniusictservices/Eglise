-- Données de démonstration : un client avec un restaurant-bar et un nightclub à Goma.
-- À charger après schema.sql, dans une base vide.

SET NAMES utf8mb4;

INSERT INTO parametres (cle, valeur) VALUES
 ('nom_client', 'Groupe Démo'),
 ('langue_defaut', 'fr'),
 ('fuseau_horaire', 'Africa/Lubumbashi');

-- Devises : USD (référence), CDF et RWF actives, EUR configurée mais désactivée
INSERT INTO devises (id, code, symbole, nom_fr, nom_en, decimales, arrondi, symbole_avant, est_reference, active, position) VALUES
 (1, 'USD', '$',   'Dollar américain', 'US dollar',       2, 0,   1, 1, 1, 1),
 (2, 'CDF', 'FC',  'Franc congolais',  'Congolese franc', 0, 100, 0, 0, 1, 2),
 (3, 'RWF', 'FRw', 'Franc rwandais',   'Rwandan franc',   0, 50,  0, 0, 1, 3),
 (4, 'EUR', '€',   'Euro',             'Euro',            2, 0,   0, 0, 0, 4);

INSERT INTO taux_change (devise_id, taux, valable_depuis) VALUES
 (1, 1,       '2026-01-01 00:00:00'),
 (2, 2850,    '2026-10-01 00:00:00'),
 (3, 1450,    '2026-10-01 00:00:00'),
 (4, 0.92,    '2026-10-01 00:00:00');

INSERT INTO grilles_prix (id, code, nom_fr, nom_en) VALUES
 (1, 'normal', 'Prix normal', 'Standard price'),
 (2, 'vip',    'Prix VIP',    'VIP price');

INSERT INTO etablissements (id, slug, nom, type, slogan_fr, slogan_en, description_fr, description_en,
  adresse, ville, lien_carte, telephone, whatsapp, email, instagram, couleur, grille_prix_id, devise_affichage_id, position) VALUES
 (1, 'comptoir-du-lac', 'Le Comptoir du Lac', 'restaurant_bar',
  'Cuisine du lac et bières fraîches, face au Kivu',
  'Lake cuisine and cold beers, facing Lake Kivu',
  'Tilapia grillé, brochettes et poulet braisé le midi, puis bar et terrasse au coucher du soleil.',
  'Grilled tilapia, skewers and braised chicken at lunch, then the bar and terrace at sunset.',
  'Avenue du Lac, Himbi', 'Goma', 'https://www.openstreetmap.org/search?query=Goma',
  '+243 970 000 001', '+243970000001', 'contact@comptoir-du-lac.example', 'https://instagram.com/',
  '#1F6F78', 1, 1, 1),
 (2, 'volcano-club', 'Volcano Club', 'nightclub',
  'Les nuits de Goma, du jeudi au samedi',
  'Goma nights, Thursday to Saturday',
  'DJ résidents, carré VIP avec service bouteille et piste jusqu''à l''aube.',
  'Resident DJs, VIP area with bottle service and a dance floor until dawn.',
  'Boulevard Kanyamuhanga', 'Goma', 'https://www.openstreetmap.org/search?query=Goma',
  '+243 970 000 002', '+243970000002', NULL, 'https://instagram.com/',
  '#B4432F', 1, 1, 2);

INSERT INTO etablissement_devises (etablissement_id, devise_id) VALUES
 (1, 1), (1, 2), (1, 3),
 (2, 1), (2, 2);

-- Horaires : 1 = lundi … 7 = dimanche
INSERT INTO horaires (etablissement_id, service_fr, service_en, jour, ouverture, fermeture) VALUES
 (1, 'Restaurant', 'Restaurant', 1, '11:00', '15:00'), (1, 'Bar & terrasse', 'Bar & terrace', 1, '17:00', '00:00'),
 (1, 'Restaurant', 'Restaurant', 2, '11:00', '15:00'), (1, 'Bar & terrasse', 'Bar & terrace', 2, '17:00', '00:00'),
 (1, 'Restaurant', 'Restaurant', 3, '11:00', '15:00'), (1, 'Bar & terrasse', 'Bar & terrace', 3, '17:00', '00:00'),
 (1, 'Restaurant', 'Restaurant', 4, '11:00', '15:00'), (1, 'Bar & terrasse', 'Bar & terrace', 4, '17:00', '00:00'),
 (1, 'Restaurant', 'Restaurant', 5, '11:00', '15:00'), (1, 'Bar & terrasse', 'Bar & terrace', 5, '17:00', '01:00'),
 (1, 'Restaurant', 'Restaurant', 6, '11:00', '15:00'), (1, 'Bar & terrasse', 'Bar & terrace', 6, '17:00', '01:00'),
 (1, 'Brunch',     'Brunch',     7, '10:00', '16:00'),
 (2, 'Club', 'Club', 4, '22:00', '04:00'),
 (2, 'Club', 'Club', 5, '22:00', '05:00'),
 (2, 'Club', 'Club', 6, '22:00', '05:00');

INSERT INTO zones (id, etablissement_id, code, nom_fr, nom_en, grille_prix_id) VALUES
 (1, 1, 'salle',    'Salle',    'Dining room', NULL),
 (2, 1, 'terrasse', 'Terrasse', 'Terrace',     NULL),
 (3, 2, 'piste',    'Piste',    'Dance floor', NULL),
 (4, 2, 'vip',      'Carré VIP','VIP area',    2);

INSERT INTO categories (id, nom_fr, nom_en, position) VALUES
 (1, 'Bières',            'Beers',              1),
 (2, 'Sucreries et eaux', 'Soft drinks & water', 2),
 (3, 'Cocktails',         'Cocktails',          3),
 (4, 'Vins et champagnes','Wine & champagne',   4),
 (5, 'Spiritueux',        'Spirits',            5),
 (6, 'Plats',             'Mains',              6),
 (7, 'À grignoter',       'Bites',              7);

INSERT INTO produits (id, categorie_id, nom_fr, nom_en, description_fr, description_en, unite_base, badges, allergenes_fr, allergenes_en, disponible, position) VALUES
 (1,  1, 'Primus',        'Primus',        'Blonde locale, 72 cl',                 'Local lager, 72 cl',            'bouteille', 'populaire', NULL, NULL, 1, 1),
 (2,  1, 'Mützig',        'Mützig',        'Blonde premium, 65 cl',                'Premium lager, 65 cl',          'bouteille', NULL,        NULL, NULL, 1, 2),
 (3,  1, 'Heineken',      'Heineken',      'Blonde, 33 cl',                         'Lager, 33 cl',                  'bouteille', NULL,        NULL, NULL, 1, 3),
 (4,  1, 'Guinness',      'Guinness',      'Stout, 33 cl',                          'Stout, 33 cl',                  'bouteille', NULL,        NULL, NULL, 0, 4),
 (5,  2, 'Coca-Cola',     'Coca-Cola',     '33 cl',                                 '33 cl',                         'canette',   NULL,        NULL, NULL, 1, 1),
 (6,  2, 'Eau minérale',  'Mineral water', 'Plate ou gazeuse',                      'Still or sparkling',            'bouteille', NULL,        NULL, NULL, 1, 2),
 (7,  2, 'Jus de maracuja','Passion fruit juice','Pressé maison',                  'Freshly squeezed',              'verre',     'nouveau',   NULL, NULL, 1, 3),
 (8,  3, 'Kivu Sunset',   'Kivu Sunset',   'Rhum, maracuja, grenadine, citron vert','Rum, passion fruit, grenadine, lime','verre','populaire', NULL, NULL, 1, 1),
 (9,  3, 'Mojito',        'Mojito',        'Rhum blanc, menthe fraîche, citron vert','White rum, fresh mint, lime', 'verre',     NULL,        NULL, NULL, 1, 2),
 (10, 4, 'Vin rouge de la maison','House red wine','Afrique du Sud',               'South Africa',                  'cl',        NULL,        'Sulfites', 'Sulphites', 1, 1),
 (11, 4, 'Moët & Chandon Brut','Moët & Chandon Brut','75 cl',                      '75 cl',                         'bouteille', NULL,        'Sulfites', 'Sulphites', 1, 2),
 (12, 5, 'Johnnie Walker Black Label','Johnnie Walker Black Label','Blended Scotch, 70 cl','Blended Scotch, 70 cl', 'cl',        NULL,        NULL, NULL, 1, 1),
 (13, 5, 'Hennessy VS',   'Hennessy VS',   'Cognac, 70 cl',                         'Cognac, 70 cl',                 'cl',        'populaire', NULL, NULL, 1, 2),
 (14, 6, 'Tilapia grillé du lac','Grilled lake tilapia','Entier, bananes plantains et pili-pili','Whole fish, plantains and chilli','piece','populaire,epice','Poisson','Fish', 1, 1),
 (15, 6, 'Poulet braisé', 'Braised chicken','Demi-poulet, frites ou chikwangue',   'Half chicken, fries or cassava bread','piece', NULL,      NULL, NULL, 1, 2),
 (16, 6, 'Brochettes de chèvre','Goat skewers','Trois brochettes, oignons grillés', 'Three skewers, grilled onions', 'piece',     'epice',     NULL, NULL, 1, 3),
 (17, 7, 'Frites',        'Fries',         'Portion',                               'Portion',                       'piece',     NULL,        NULL, NULL, 1, 1),
 (18, 7, 'Ailes de poulet','Chicken wings','Six ailes, sauce piquante',             'Six wings, hot sauce',          'piece',     'epice',     NULL, NULL, 1, 2);

-- Le restaurant-bar propose tout sauf le champagne ; le club ne fait pas de plats
INSERT INTO produit_etablissements (produit_id, etablissement_id) VALUES
 (1,1),(2,1),(3,1),(4,1),(5,1),(6,1),(7,1),(8,1),(9,1),(10,1),(12,1),(13,1),(14,1),(15,1),(16,1),(17,1),
 (1,2),(2,2),(3,2),(4,2),(5,2),(6,2),(8,2),(9,2),(11,2),(12,2),(13,2),(17,2),(18,2);

INSERT INTO conditionnements (id, produit_id, nom_fr, nom_en, quantite_base, vendable, achetable, position) VALUES
 (1,  1,  'Bouteille', 'Bottle', 1,  1, 0, 1),
 (2,  1,  'Casier',    'Crate',  12, 0, 1, 2),
 (3,  2,  'Bouteille', 'Bottle', 1,  1, 0, 1),
 (4,  2,  'Casier',    'Crate',  12, 0, 1, 2),
 (5,  3,  'Bouteille', 'Bottle', 1,  1, 0, 1),
 (6,  3,  'Carton',    'Case',   24, 0, 1, 2),
 (7,  4,  'Bouteille', 'Bottle', 1,  1, 0, 1),
 (8,  5,  'Canette',   'Can',    1,  1, 0, 1),
 (9,  5,  'Pack',      'Pack',   24, 0, 1, 2),
 (10, 6,  'Bouteille', 'Bottle', 1,  1, 0, 1),
 (11, 7,  'Verre',     'Glass',  1,  1, 0, 1),
 (12, 8,  'Verre',     'Glass',  1,  1, 0, 1),
 (13, 9,  'Verre',     'Glass',  1,  1, 0, 1),
 (14, 10, 'Verre 15 cl','Glass 15 cl', 15, 1, 0, 1),
 (15, 10, 'Bouteille 75 cl','Bottle 75 cl', 75, 1, 1, 2),
 (16, 11, 'Bouteille', 'Bottle', 1,  1, 1, 1),
 (17, 12, 'Dose 4 cl', 'Shot 4 cl', 4,  1, 0, 1),
 (18, 12, 'Bouteille 70 cl','Bottle 70 cl', 70, 1, 1, 2),
 (19, 13, 'Dose 4 cl', 'Shot 4 cl', 4,  1, 0, 1),
 (20, 13, 'Bouteille 70 cl','Bottle 70 cl', 70, 1, 1, 2),
 (21, 14, 'Assiette',  'Plate',  1,  1, 0, 1),
 (22, 15, 'Assiette',  'Plate',  1,  1, 0, 1),
 (23, 16, 'Assiette',  'Plate',  1,  1, 0, 1),
 (24, 17, 'Portion',   'Portion',1,  1, 0, 1),
 (25, 18, 'Portion',   'Portion',1,  1, 0, 1);

-- Prix en USD (grille normale). Les autres devises sont calculées avec le taux,
-- sauf la Primus et la Mützig, qui ont un prix fixe en francs congolais.
INSERT INTO prix (conditionnement_id, grille_prix_id, devise_id, montant) VALUES
 (1, 1, 1, 2.00),  (1, 1, 2, 5500),
 (3, 1, 1, 2.50),  (3, 1, 2, 7000),
 (5, 1, 1, 3.00),
 (7, 1, 1, 3.50),
 (8, 1, 1, 1.50),
 (10, 1, 1, 1.00),
 (11, 1, 1, 3.00),
 (12, 1, 1, 8.00),
 (13, 1, 1, 7.00),
 (14, 1, 1, 5.00), (15, 1, 1, 28.00),
 (16, 1, 1, 120.00),
 (17, 1, 1, 5.00), (18, 1, 1, 75.00),
 (19, 1, 1, 6.00), (20, 1, 1, 90.00),
 (21, 1, 1, 15.00),
 (22, 1, 1, 12.00),
 (23, 1, 1, 8.00),
 (24, 1, 1, 4.00),
 (25, 1, 1, 7.00);

-- Prix VIP (carré VIP du club) : seulement là où ils diffèrent du prix normal
INSERT INTO prix (conditionnement_id, grille_prix_id, devise_id, montant) VALUES
 (1, 2, 1, 3.00),
 (3, 2, 1, 3.50),
 (5, 2, 1, 4.00),
 (12, 2, 1, 10.00),
 (16, 2, 1, 150.00),
 (18, 2, 1, 90.00),
 (20, 2, 1, 110.00);

INSERT INTO qr_codes (code, etablissement_id, zone_id, libelle) VALUES
 ('lac-salle',  1, 1,    'Tables de la salle'),
 ('lac-terr',   1, 2,    'Tables de la terrasse'),
 ('volc-entree',2, NULL, 'Entrée du club'),
 ('volc-vip',   2, 4,    'Carré VIP');
