-- Sous-catégories et tailles.
-- Sous-catégories : la colonne categories.parent_id existe depuis le schéma de départ ; elles vont sur
-- deux niveaux au plus (catégorie > sous-catégorie), règle vérifiée par l'espace de gestion.
-- Tailles : une taille (Petite 33 cl, Grande 72 cl…) est une bouteille physiquement différente, avec son
-- stock, ses formats et ses prix. C'est un produit « variante » rattaché à un produit principal
-- (parent_id), rangé dans la même catégorie. Le produit principal peut n'avoir aucun format à lui :
-- il sert alors de groupe de tailles. Les formats en cl d'une même bouteille (dose, double, bouteille)
-- restent des conditionnements du même produit.
ALTER TABLE produits
    ADD COLUMN parent_id  INT UNSIGNED NULL AFTER categorie_id,
    ADD COLUMN taille_fr  VARCHAR(64) NULL AFTER nom_en,
    ADD COLUMN taille_en  VARCHAR(64) NULL AFTER taille_fr,
    ADD COLUMN contenance VARCHAR(32) NULL AFTER taille_en,
    ADD KEY idx_produits_parent (parent_id, position),
    ADD CONSTRAINT fk_produit_parent FOREIGN KEY (parent_id) REFERENCES produits(id) ON DELETE CASCADE;

-- Sous-catégories rangées dans l'ordre de leur catégorie parente
ALTER TABLE categories ADD KEY idx_categories_parent (parent_id, position);
