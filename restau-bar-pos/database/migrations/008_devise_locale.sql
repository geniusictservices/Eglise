-- Devise locale : les tickets et factures sont dans cette devise, avec l'équivalent en dollars en deuxième position.
-- Ordre d'affichage partout : devise locale, puis le dollar américain, puis les autres.
ALTER TABLE devises ADD COLUMN est_locale TINYINT(1) NOT NULL DEFAULT 0 AFTER est_reference;
-- Proposition à l'installation : le franc congolais s'il est configuré, sinon la première devise active autre que le dollar
UPDATE devises SET est_locale = 1 WHERE code = 'CDF' LIMIT 1;
UPDATE devises SET est_locale = 1
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM devises WHERE est_locale = 1) AS deja)
   AND code <> 'USD' AND active = 1
 ORDER BY position, code LIMIT 1;
