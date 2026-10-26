-- Crédit des photos (auteur, licence, source), affiché sous la photo quand la licence l'exige
ALTER TABLE produits ADD COLUMN photo_credit VARCHAR(255) NULL AFTER photo;
ALTER TABLE etablissements ADD COLUMN couverture_credit VARCHAR(255) NULL AFTER couverture;
