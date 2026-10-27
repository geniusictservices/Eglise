-- Nouveaux modules dans les formules de départ (si elles portent toujours leur nom d'origine) :
-- Essentiel reçoit « salle » (tables et notes ouvertes) ; Pro reçoit « salle », « cuisine » et « personnel » ;
-- Club reçoit tous les modules. Les formules créées par le super admin ne changent pas.
UPDATE formules SET modules = JSON_ARRAY_APPEND(modules, '$', 'salle')
 WHERE nom IN ('Essentiel', 'Pro', 'Club') AND NOT JSON_CONTAINS(modules, '"salle"');
UPDATE formules SET modules = JSON_ARRAY_APPEND(modules, '$', 'cuisine')
 WHERE nom IN ('Pro', 'Club') AND NOT JSON_CONTAINS(modules, '"cuisine"');
UPDATE formules SET modules = JSON_ARRAY_APPEND(modules, '$', 'personnel')
 WHERE nom IN ('Pro', 'Club') AND NOT JSON_CONTAINS(modules, '"personnel"');
UPDATE formules SET modules = '["caisse","ventes","salle","cuisine","achats","stock","personnel","finance","rapports","evenements","nightclub","cashless","carte"]'
 WHERE nom = 'Club';
