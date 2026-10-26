-- Comptes de trésorerie : trois TYPES (caisse, mobile money, banque) et des comptes qui tiennent
-- une ou plusieurs devises (« Caisse du bar » : un solde en FC et un solde en $, comptés séparément).
-- Le solde de chaque devise d'un compte se calcule depuis le journal (mouvements_tresorerie.devise_id).
-- Les comptes existants gardent leur devise unique ; on peut ensuite leur en ajouter.

-- Devises tenues par chaque compte, avec le solde d'ouverture de chacune
CREATE TABLE compte_devises (
    compte_id     INT UNSIGNED NOT NULL,
    devise_id     INT UNSIGNED NOT NULL,
    solde_initial DECIMAL(18,3) NOT NULL DEFAULT 0,
    PRIMARY KEY (compte_id, devise_id),
    CONSTRAINT fk_cd_compte FOREIGN KEY (compte_id) REFERENCES comptes_tresorerie(id) ON DELETE CASCADE,
    CONSTRAINT fk_cd_devise FOREIGN KEY (devise_id) REFERENCES devises(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO compte_devises (compte_id, devise_id, solde_initial)
SELECT id, devise_id, solde_initial FROM comptes_tresorerie;

-- « Espèces » devient le type « Caisse »
ALTER TABLE comptes_tresorerie MODIFY type ENUM('especes','caisse','mobile_money','banque') NOT NULL;
UPDATE comptes_tresorerie SET type = 'caisse' WHERE type = 'especes';
ALTER TABLE comptes_tresorerie MODIFY type ENUM('caisse','mobile_money','banque') NOT NULL;
ALTER TABLE vente_paiements MODIFY moyen ENUM('especes','caisse','mobile_money','banque') NOT NULL;
UPDATE vente_paiements SET moyen = 'caisse' WHERE moyen = 'especes';
ALTER TABLE vente_paiements MODIFY moyen ENUM('caisse','mobile_money','banque') NOT NULL;   -- type du compte au moment du paiement

-- La devise de chaque paiement de dépense et de chaque côté d'un transfert n'est plus celle « du compte »
ALTER TABLE depense_paiements ADD COLUMN devise_id INT UNSIGNED NULL AFTER compte_id;
UPDATE depense_paiements p JOIN comptes_tresorerie c ON c.id = p.compte_id SET p.devise_id = c.devise_id;
ALTER TABLE depense_paiements MODIFY devise_id INT UNSIGNED NOT NULL,
    ADD UNIQUE KEY uq_paiement_compte_devise (depense_id, compte_id, devise_id),
    ADD CONSTRAINT fk_paiement_devise FOREIGN KEY (devise_id) REFERENCES devises(id);
ALTER TABLE depense_paiements DROP INDEX uq_paiement_compte;

ALTER TABLE transferts_tresorerie
    ADD COLUMN devise_source_id INT UNSIGNED NULL AFTER compte_destination_id,
    ADD COLUMN devise_destination_id INT UNSIGNED NULL AFTER devise_source_id;
UPDATE transferts_tresorerie t
  JOIN comptes_tresorerie s ON s.id = t.compte_source_id
  JOIN comptes_tresorerie d ON d.id = t.compte_destination_id
   SET t.devise_source_id = s.devise_id, t.devise_destination_id = d.devise_id;
ALTER TABLE transferts_tresorerie
    MODIFY devise_source_id INT UNSIGNED NOT NULL,
    MODIFY devise_destination_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_transfert_devise_source FOREIGN KEY (devise_source_id) REFERENCES devises(id),
    ADD CONSTRAINT fk_transfert_devise_dest FOREIGN KEY (devise_destination_id) REFERENCES devises(id);

-- Le compte n'a plus « sa » devise ni « son » solde d'ouverture : ils sont dans compte_devises
ALTER TABLE comptes_tresorerie DROP FOREIGN KEY fk_compte_devise;
ALTER TABLE comptes_tresorerie DROP COLUMN devise_id, DROP COLUMN solde_initial;
