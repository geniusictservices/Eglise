-- Devise de référence au moment de la saisie d'un taux, pour garder un historique juste
-- si la référence change (NULL = saisi avant cette colonne : référence actuelle)
ALTER TABLE taux_change ADD COLUMN reference_id INT UNSIGNED NULL AFTER taux;
ALTER TABLE taux_change ADD CONSTRAINT fk_taux_reference FOREIGN KEY (reference_id) REFERENCES devises(id);
UPDATE taux_change SET reference_id = (SELECT id FROM devises WHERE est_reference = 1 ORDER BY id LIMIT 1);
