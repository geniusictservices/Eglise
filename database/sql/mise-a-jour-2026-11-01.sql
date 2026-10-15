-- Waumini : mise à jour de la base pour l'archive du 1er novembre 2026 (corrections de l'audit).
-- Migration appliquée : 2026_11_01_000100_add_code_to_finance_categories.
--
-- Pour une base DÉJÀ EN SERVICE, à l'état de la version précédente (dernière migration :
-- 2026_10_31_000100_document_layouts). À importer UNE SEULE FOIS dans phpMyAdmin (onglet Importer),
-- après une sauvegarde. Une seconde importation s'arrête sur « Duplicate column name 'code' » sans rien abîmer.
-- Avec un terminal, « php artisan migrate --force » fait la même chose et reste préférable.
--
-- Ce qu'elle fait : les catégories que Waumini utilise lui-même (salaires, retours sur avance,
-- promesses, projets, quotes-parts, versements au siège) reçoivent un code stable, pour que les
-- renommer ne casse plus le contrôle du budget de la paie ni la consolidation.

SET NAMES utf8mb4;

ALTER TABLE `finance_categories` ADD `code` varchar(40) NULL AFTER `type`;
ALTER TABLE `finance_categories` ADD UNIQUE `finance_categories_organization_id_code_unique` (`organization_id`, `code`);

UPDATE `finance_categories` SET `code` = 'salaries' WHERE `type` = 'expense' AND `name` = 'Rémunérations et motivations' AND `code` IS NULL;
UPDATE `finance_categories` SET `code` = 'projects' WHERE `type` = 'expense' AND `name` = 'Projets et travaux' AND `code` IS NULL;
UPDATE `finance_categories` SET `code` = 'quota_sent' WHERE `type` = 'expense' AND `name` = 'Quote-part versée au niveau supérieur' AND `code` IS NULL;
UPDATE `finance_categories` SET `code` = 'project_remittance' WHERE `type` = 'expense' AND `name` = 'Versements au siège pour les projets' AND `code` IS NULL;
UPDATE `finance_categories` SET `code` = 'pledges' WHERE `type` = 'income' AND `name` = 'Promesses et projets' AND `code` IS NULL;
UPDATE `finance_categories` SET `code` = 'advance_return' WHERE `type` = 'income' AND `name` = 'Retour sur avance' AND `code` IS NULL;
UPDATE `finance_categories` SET `code` = 'quota_received' WHERE `type` = 'income' AND `name` = 'Quotes-parts reçues' AND `code` IS NULL;

-- L'ancien libellé de la quote-part versée, là où le nouveau n'existe pas déjà.
-- La table dérivée (DISTINCT) est matérialisée : MySQL accepte alors de relire la table modifiée.
UPDATE `finance_categories` AS `c`
LEFT JOIN (SELECT DISTINCT `organization_id` FROM `finance_categories` WHERE `code` = 'quota_sent') AS `deja` ON `deja`.`organization_id` = `c`.`organization_id`
SET `c`.`code` = 'quota_sent'
WHERE `c`.`type` = 'expense' AND `c`.`name` = 'Quote-part au niveau supérieur' AND `c`.`code` IS NULL AND `deja`.`organization_id` IS NULL;

-- La migration est notée comme faite : « php artisan migrate » ne la rejouera pas.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_01_000100_add_code_to_finance_categories', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
