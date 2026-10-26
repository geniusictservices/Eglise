-- Catégorie archivée : elle ne contient plus que des produits désactivés, qu'on garde pour l'historique
ALTER TABLE categories ADD COLUMN actif TINYINT(1) NOT NULL DEFAULT 1 AFTER visible_public;
