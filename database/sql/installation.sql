-- Waumini : base de données vide, prête pour une première installation.
-- Toutes les migrations sont appliquées, jusqu'à 2026_11_01_000100_add_code_to_finance_categories.
-- À importer UNE SEULE FOIS dans une base vide (phpMyAdmin › Importer), en utf8mb4.
-- Ne pas importer sur une base déjà en service : utiliser plutôt le fichier de mise à jour.


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `audience` varchar(12) NOT NULL DEFAULT 'all',
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `group_id` bigint(20) unsigned DEFAULT NULL,
  `event_id` bigint(20) unsigned DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `expires_on` date DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `recipients` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `announcements_organization_id_foreign` (`organization_id`),
  KEY `announcements_department_id_foreign` (`department_id`),
  KEY `announcements_group_id_foreign` (`group_id`),
  KEY `announcements_event_id_foreign` (`event_id`),
  KEY `announcements_created_by_foreign` (`created_by`),
  CONSTRAINT `announcements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `announcements_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `announcements_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `announcements_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `announcements_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
DROP TABLE IF EXISTS `attachment_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attachment_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `message` text DEFAULT NULL,
  `decision_note` text DEFAULT NULL,
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `decided_by` bigint(20) unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attachment_requests_organization_id_foreign` (`organization_id`),
  KEY `attachment_requests_target_id_foreign` (`target_id`),
  KEY `attachment_requests_requested_by_foreign` (`requested_by`),
  KEY `attachment_requests_decided_by_foreign` (`decided_by`),
  CONSTRAINT `attachment_requests_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attachment_requests_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attachment_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attachment_requests_target_id_foreign` FOREIGN KEY (`target_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `attachment_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `attachment_requests` ENABLE KEYS */;
DROP TABLE IF EXISTS `attendance_checkins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_checkins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attendance_record_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_checkins_attendance_record_id_member_id_unique` (`attendance_record_id`,`member_id`),
  KEY `attendance_checkins_member_id_foreign` (`member_id`),
  CONSTRAINT `attendance_checkins_attendance_record_id_foreign` FOREIGN KEY (`attendance_record_id`) REFERENCES `attendance_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_checkins_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `attendance_checkins` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_checkins` ENABLE KEYS */;
DROP TABLE IF EXISTS `attendance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `event_id` bigint(20) unsigned NOT NULL,
  `occurs_on` date NOT NULL,
  `men` int(10) unsigned DEFAULT NULL,
  `women` int(10) unsigned DEFAULT NULL,
  `children` int(10) unsigned DEFAULT NULL,
  `visitors` int(10) unsigned DEFAULT NULL,
  `total` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_records_event_id_occurs_on_unique` (`event_id`,`occurs_on`),
  KEY `attendance_records_organization_id_foreign` (`organization_id`),
  KEY `attendance_records_recorded_by_foreign` (`recorded_by`),
  CONSTRAINT `attendance_records_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_records_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_records_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `attendance_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_records` ENABLE KEYS */;
DROP TABLE IF EXISTS `attendance_visitors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_visitors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attendance_record_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `invited_by` varchar(255) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `followed_up_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_visitors_attendance_record_id_foreign` (`attendance_record_id`),
  CONSTRAINT `attendance_visitors_attendance_record_id_foreign` FOREIGN KEY (`attendance_record_id`) REFERENCES `attendance_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `attendance_visitors` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_visitors` ENABLE KEYS */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chain` varchar(10) NOT NULL DEFAULT 'main',
  `organization_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(60) NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `previous_hash` char(64) DEFAULT NULL,
  `hash` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `audit_logs_user_id_foreign` (`user_id`),
  KEY `audit_logs_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `audit_logs_organization_id_created_at_index` (`organization_id`,`created_at`),
  KEY `audit_logs_chain_index` (`chain`),
  CONSTRAINT `audit_logs_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
DROP TABLE IF EXISTS `budget_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `budget_id` bigint(20) unsigned NOT NULL,
  `type` varchar(8) NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `proposed_amount` decimal(14,2) DEFAULT NULL,
  `proposal_line_id` bigint(20) unsigned DEFAULT NULL,
  `payee_id` bigint(20) unsigned DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(12) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `budget_lines_budget_id_foreign` (`budget_id`),
  KEY `budget_lines_department_id_foreign` (`department_id`),
  KEY `budget_lines_category_id_foreign` (`category_id`),
  KEY `budget_lines_proposal_line_id_foreign` (`proposal_line_id`),
  KEY `budget_lines_payee_id_foreign` (`payee_id`),
  KEY `budget_lines_project_id_foreign` (`project_id`),
  CONSTRAINT `budget_lines_budget_id_foreign` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_lines_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `finance_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_lines_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_lines_payee_id_foreign` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_lines_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_lines_proposal_line_id_foreign` FOREIGN KEY (`proposal_line_id`) REFERENCES `budget_proposal_lines` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `budget_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `budget_lines` ENABLE KEYS */;
DROP TABLE IF EXISTS `budget_overruns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_overruns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `fiscal_year` smallint(5) unsigned NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `source` varchar(16) NOT NULL,
  `source_department_id` bigint(20) unsigned DEFAULT NULL,
  `source_category_id` bigint(20) unsigned DEFAULT NULL,
  `source_detail` varchar(255) DEFAULT NULL,
  `reason` text NOT NULL,
  `expense_request_id` bigint(20) unsigned DEFAULT NULL,
  `pay_run_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `decided_by` bigint(20) unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `decision_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `budget_overruns_department_id_foreign` (`department_id`),
  KEY `budget_overruns_category_id_foreign` (`category_id`),
  KEY `budget_overruns_source_department_id_foreign` (`source_department_id`),
  KEY `budget_overruns_source_category_id_foreign` (`source_category_id`),
  KEY `budget_overruns_expense_request_id_foreign` (`expense_request_id`),
  KEY `budget_overruns_requested_by_foreign` (`requested_by`),
  KEY `budget_overruns_decided_by_foreign` (`decided_by`),
  KEY `budget_overruns_organization_id_fiscal_year_status_index` (`organization_id`,`fiscal_year`,`status`),
  KEY `budget_overruns_pay_run_id_foreign` (`pay_run_id`),
  CONSTRAINT `budget_overruns_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `finance_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_overruns_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_overruns_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_overruns_expense_request_id_foreign` FOREIGN KEY (`expense_request_id`) REFERENCES `expense_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_overruns_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_overruns_pay_run_id_foreign` FOREIGN KEY (`pay_run_id`) REFERENCES `pay_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_overruns_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_overruns_source_category_id_foreign` FOREIGN KEY (`source_category_id`) REFERENCES `finance_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budget_overruns_source_department_id_foreign` FOREIGN KEY (`source_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `budget_overruns` DISABLE KEYS */;
/*!40000 ALTER TABLE `budget_overruns` ENABLE KEYS */;
DROP TABLE IF EXISTS `budget_proposal_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_proposal_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `budget_proposal_id` bigint(20) unsigned NOT NULL,
  `type` varchar(8) NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `original_amount` decimal(16,2) DEFAULT NULL,
  `original_currency` char(3) DEFAULT NULL,
  `justification` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `budget_proposal_lines_budget_proposal_id_foreign` (`budget_proposal_id`),
  KEY `budget_proposal_lines_category_id_foreign` (`category_id`),
  CONSTRAINT `budget_proposal_lines_budget_proposal_id_foreign` FOREIGN KEY (`budget_proposal_id`) REFERENCES `budget_proposals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_proposal_lines_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `finance_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `budget_proposal_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `budget_proposal_lines` ENABLE KEYS */;
DROP TABLE IF EXISTS `budget_proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget_proposals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `fiscal_year` smallint(5) unsigned NOT NULL,
  `department_id` bigint(20) unsigned NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'draft',
  `note` text DEFAULT NULL,
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `return_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `budget_proposals_unique` (`organization_id`,`fiscal_year`,`department_id`),
  KEY `budget_proposals_department_id_foreign` (`department_id`),
  KEY `budget_proposals_submitted_by_foreign` (`submitted_by`),
  CONSTRAINT `budget_proposals_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_proposals_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budget_proposals_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `budget_proposals` DISABLE KEYS */;
/*!40000 ALTER TABLE `budget_proposals` ENABLE KEYS */;
DROP TABLE IF EXISTS `budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `budgets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `fiscal_year` smallint(5) unsigned NOT NULL,
  `version` smallint(5) unsigned NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'draft',
  `reason` varchar(255) DEFAULT NULL,
  `prepared_by` bigint(20) unsigned DEFAULT NULL,
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approval_note` varchar(255) DEFAULT NULL,
  `return_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `budgets_version_unique` (`organization_id`,`fiscal_year`,`version`),
  KEY `budgets_prepared_by_foreign` (`prepared_by`),
  KEY `budgets_submitted_by_foreign` (`submitted_by`),
  KEY `budgets_approved_by_foreign` (`approved_by`),
  CONSTRAINT `budgets_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budgets_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `budgets_prepared_by_foreign` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `budgets_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `budgets` DISABLE KEYS */;
/*!40000 ALTER TABLE `budgets` ENABLE KEYS */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
DROP TABLE IF EXISTS `cash_account_currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_account_currencies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cash_account_id` bigint(20) unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `opening_balance` decimal(20,2) NOT NULL DEFAULT 0.00,
  `opened_on` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cash_account_currencies_cash_account_id_currency_unique` (`cash_account_id`,`currency`),
  CONSTRAINT `cash_account_currencies_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `cash_account_currencies` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_account_currencies` ENABLE KEYS */;
DROP TABLE IF EXISTS `cash_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'cash',
  `provider` varchar(60) DEFAULT NULL,
  `account_number` varchar(60) DEFAULT NULL,
  `holder` varchar(120) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cash_accounts_organization_id_foreign` (`organization_id`),
  CONSTRAINT `cash_accounts_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `cash_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_accounts` ENABLE KEYS */;
DROP TABLE IF EXISTS `collection_envelopes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collection_envelopes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `collection_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `payer_name` varchar(150) DEFAULT NULL,
  `currency` char(3) NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `collection_envelopes_collection_id_foreign` (`collection_id`),
  KEY `collection_envelopes_category_id_foreign` (`category_id`),
  KEY `collection_envelopes_member_id_foreign` (`member_id`),
  CONSTRAINT `collection_envelopes_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `finance_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collection_envelopes_collection_id_foreign` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collection_envelopes_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `collection_envelopes` DISABLE KEYS */;
/*!40000 ALTER TABLE `collection_envelopes` ENABLE KEYS */;
DROP TABLE IF EXISTS `collection_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collection_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `collection_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `collection_lines_collection_id_foreign` (`collection_id`),
  KEY `collection_lines_category_id_foreign` (`category_id`),
  CONSTRAINT `collection_lines_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `finance_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collection_lines_collection_id_foreign` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `collection_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `collection_lines` ENABLE KEYS */;
DROP TABLE IF EXISTS `collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `service_date` date NOT NULL,
  `service_label` varchar(120) NOT NULL,
  `cash_account_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `counters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counters`)),
  `counts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counts`)),
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `validated_by` bigint(20) unsigned DEFAULT NULL,
  `validated_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `collections_cash_account_id_foreign` (`cash_account_id`),
  KEY `collections_created_by_foreign` (`created_by`),
  KEY `collections_validated_by_foreign` (`validated_by`),
  KEY `collections_organization_id_service_date_index` (`organization_id`,`service_date`),
  CONSTRAINT `collections_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collections_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `collections_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collections_validated_by_foreign` FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `collections` DISABLE KEYS */;
/*!40000 ALTER TABLE `collections` ENABLE KEYS */;
DROP TABLE IF EXISTS `demo_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `community` varchar(150) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `members` varchar(30) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `demo_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `demo_requests` ENABLE KEYS */;
DROP TABLE IF EXISTS `department_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `department_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'member',
  `joined_on` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `department_members_department_id_member_id_unique` (`department_id`,`member_id`),
  KEY `department_members_member_id_foreign` (`member_id`),
  CONSTRAINT `department_members_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `department_members_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `department_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `department_members` ENABLE KEYS */;
DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'ministry',
  `description` text DEFAULT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'ink',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `departments_organization_id_foreign` (`organization_id`),
  CONSTRAINT `departments_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
DROP TABLE IF EXISTS `document_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `document_type_id` bigint(20) unsigned NOT NULL,
  `message` varchar(500) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `issued_document_id` bigint(20) unsigned DEFAULT NULL,
  `refusal_reason` varchar(255) DEFAULT NULL,
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `handled_by` bigint(20) unsigned DEFAULT NULL,
  `handled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_requests_organization_id_foreign` (`organization_id`),
  KEY `document_requests_member_id_foreign` (`member_id`),
  KEY `document_requests_document_type_id_foreign` (`document_type_id`),
  KEY `document_requests_issued_document_id_foreign` (`issued_document_id`),
  KEY `document_requests_requested_by_foreign` (`requested_by`),
  KEY `document_requests_handled_by_foreign` (`handled_by`),
  CONSTRAINT `document_requests_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_requests_handled_by_foreign` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_requests_issued_document_id_foreign` FOREIGN KEY (`issued_document_id`) REFERENCES `issued_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_requests_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_requests_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `document_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `document_requests` ENABLE KEYS */;
DROP TABLE IF EXISTS `document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `replaces_id` bigint(20) unsigned DEFAULT NULL,
  `key` varchar(40) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `title` varchar(160) NOT NULL,
  `subject` varchar(10) NOT NULL DEFAULT 'member',
  `life_event_type` varchar(30) DEFAULT NULL,
  `body` text NOT NULL,
  `fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fields`)),
  `code` varchar(12) NOT NULL,
  `number_format` varchar(60) NOT NULL DEFAULT '{CODE}/{SIGLE}/{ANNEE}/{NUMERO}',
  `signatory_title` varchar(80) DEFAULT NULL,
  `show_photo` tinyint(1) NOT NULL DEFAULT 0,
  `orientation` varchar(10) NOT NULL DEFAULT 'portrait',
  `style` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_types_organization_id_foreign` (`organization_id`),
  KEY `document_types_replaces_id_foreign` (`replaces_id`),
  CONSTRAINT `document_types_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_types_replaces_id_foreign` FOREIGN KEY (`replaces_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `document_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `document_types` ENABLE KEYS */;
DROP TABLE IF EXISTS `event_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_registrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint(20) unsigned NOT NULL,
  `occurs_on` date NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `registered_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_registrations_member_id_foreign` (`member_id`),
  KEY `event_registrations_registered_by_foreign` (`registered_by`),
  KEY `event_registrations_event_id_occurs_on_index` (`event_id`,`occurs_on`),
  CONSTRAINT `event_registrations_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_registrations_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_registrations_registered_by_foreign` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `event_registrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `event_registrations` ENABLE KEYS */;
DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'service',
  `starts_on` date NOT NULL,
  `ends_on` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `place` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `audience` varchar(12) NOT NULL DEFAULT 'all',
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `group_id` bigint(20) unsigned DEFAULT NULL,
  `repeats` varchar(20) NOT NULL DEFAULT 'none',
  `repeat_until` date DEFAULT NULL,
  `skipped_dates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`skipped_dates`)),
  `registration` tinyint(1) NOT NULL DEFAULT 0,
  `capacity` int(10) unsigned DEFAULT NULL,
  `tracks_attendance` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `events_organization_id_foreign` (`organization_id`),
  KEY `events_department_id_foreign` (`department_id`),
  KEY `events_group_id_foreign` (`group_id`),
  KEY `events_created_by_foreign` (`created_by`),
  CONSTRAINT `events_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `events_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `events_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `events_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `events` DISABLE KEYS */;
/*!40000 ALTER TABLE `events` ENABLE KEYS */;
DROP TABLE IF EXISTS `exchange_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exchange_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `rate` decimal(20,8) NOT NULL,
  `effective_on` date NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exchange_rates_organization_id_currency_effective_on_unique` (`organization_id`,`currency`,`effective_on`),
  KEY `exchange_rates_created_by_foreign` (`created_by`),
  CONSTRAINT `exchange_rates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exchange_rates_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `exchange_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `exchange_rates` ENABLE KEYS */;
DROP TABLE IF EXISTS `expense_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_approvals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `expense_request_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `decision` varchar(10) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_approvals_expense_request_id_foreign` (`expense_request_id`),
  KEY `expense_approvals_user_id_foreign` (`user_id`),
  CONSTRAINT `expense_approvals_expense_request_id_foreign` FOREIGN KEY (`expense_request_id`) REFERENCES `expense_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expense_approvals_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `expense_approvals` DISABLE KEYS */;
/*!40000 ALTER TABLE `expense_approvals` ENABLE KEYS */;
DROP TABLE IF EXISTS `expense_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `expense_request_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'invoice',
  `path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_attachments_expense_request_id_foreign` (`expense_request_id`),
  KEY `expense_attachments_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `expense_attachments_expense_request_id_foreign` FOREIGN KEY (`expense_request_id`) REFERENCES `expense_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expense_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `expense_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `expense_attachments` ENABLE KEYS */;
DROP TABLE IF EXISTS `expense_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `number` varchar(30) NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `budget_line_id` bigint(20) unsigned DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `is_unforeseen` tinyint(1) NOT NULL DEFAULT 0,
  `unforeseen_reason` varchar(255) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(20,2) NOT NULL,
  `currency` char(3) NOT NULL,
  `is_advance` tinyint(1) NOT NULL DEFAULT 0,
  `beneficiary_member_id` bigint(20) unsigned DEFAULT NULL,
  `beneficiary_name` varchar(150) DEFAULT NULL,
  `needed_on` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'submitted',
  `approvals_required` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `checked_by` bigint(20) unsigned DEFAULT NULL,
  `checked_at` timestamp NULL DEFAULT NULL,
  `check_note` varchar(255) DEFAULT NULL,
  `cash_account_id` bigint(20) unsigned DEFAULT NULL,
  `finance_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `disbursed_by` bigint(20) unsigned DEFAULT NULL,
  `disbursed_at` timestamp NULL DEFAULT NULL,
  `justify_by` date DEFAULT NULL,
  `justified_amount` decimal(20,2) DEFAULT NULL,
  `return_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `justified_by` bigint(20) unsigned DEFAULT NULL,
  `justified_at` timestamp NULL DEFAULT NULL,
  `justification_note` varchar(255) DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `expense_requests_organization_id_number_unique` (`organization_id`,`number`),
  KEY `expense_requests_department_id_foreign` (`department_id`),
  KEY `expense_requests_category_id_foreign` (`category_id`),
  KEY `expense_requests_beneficiary_member_id_foreign` (`beneficiary_member_id`),
  KEY `expense_requests_requested_by_foreign` (`requested_by`),
  KEY `expense_requests_checked_by_foreign` (`checked_by`),
  KEY `expense_requests_cash_account_id_foreign` (`cash_account_id`),
  KEY `expense_requests_finance_transaction_id_foreign` (`finance_transaction_id`),
  KEY `expense_requests_disbursed_by_foreign` (`disbursed_by`),
  KEY `expense_requests_return_transaction_id_foreign` (`return_transaction_id`),
  KEY `expense_requests_justified_by_foreign` (`justified_by`),
  KEY `expense_requests_organization_id_status_index` (`organization_id`,`status`),
  KEY `expense_requests_budget_line_id_foreign` (`budget_line_id`),
  KEY `expense_requests_project_id_foreign` (`project_id`),
  CONSTRAINT `expense_requests_beneficiary_member_id_foreign` FOREIGN KEY (`beneficiary_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_budget_line_id_foreign` FOREIGN KEY (`budget_line_id`) REFERENCES `budget_lines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `finance_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_checked_by_foreign` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_disbursed_by_foreign` FOREIGN KEY (`disbursed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_finance_transaction_id_foreign` FOREIGN KEY (`finance_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_justified_by_foreign` FOREIGN KEY (`justified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expense_requests_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_requests_return_transaction_id_foreign` FOREIGN KEY (`return_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `expense_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `expense_requests` ENABLE KEYS */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
DROP TABLE IF EXISTS `finance_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `type` varchar(10) NOT NULL,
  `code` varchar(40) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `nature` varchar(20) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_categories_organization_id_code_unique` (`organization_id`,`code`),
  CONSTRAINT `finance_categories_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `finance_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_categories` ENABLE KEYS */;
DROP TABLE IF EXISTS `finance_closings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_closings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `year` smallint(5) unsigned NOT NULL,
  `month` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `status` varchar(12) NOT NULL DEFAULT 'closed',
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`snapshot`)),
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `reopened_by` bigint(20) unsigned DEFAULT NULL,
  `reopened_at` timestamp NULL DEFAULT NULL,
  `reopen_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_closings_organization_id_year_month_unique` (`organization_id`,`year`,`month`),
  KEY `finance_closings_closed_by_foreign` (`closed_by`),
  KEY `finance_closings_reopened_by_foreign` (`reopened_by`),
  CONSTRAINT `finance_closings_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_closings_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finance_closings_reopened_by_foreign` FOREIGN KEY (`reopened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `finance_closings` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_closings` ENABLE KEYS */;
DROP TABLE IF EXISTS `finance_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `cash_account_id` bigint(20) unsigned NOT NULL,
  `type` varchar(20) NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `currency` char(3) NOT NULL,
  `rate` decimal(20,8) NOT NULL,
  `usd_amount` decimal(20,2) NOT NULL,
  `occurred_on` date NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `payer_name` varchar(150) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `external_reference` varchar(100) DEFAULT NULL,
  `receipt_number` varchar(40) DEFAULT NULL,
  `group_uuid` uuid DEFAULT NULL,
  `collection_id` bigint(20) unsigned DEFAULT NULL,
  `pledge_id` bigint(20) unsigned DEFAULT NULL,
  `pledge_amount` decimal(20,2) DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `expense_request_id` bigint(20) unsigned DEFAULT NULL,
  `pay_slip_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_transactions_organization_id_receipt_number_unique` (`organization_id`,`receipt_number`),
  KEY `finance_transactions_cash_account_id_foreign` (`cash_account_id`),
  KEY `finance_transactions_category_id_foreign` (`category_id`),
  KEY `finance_transactions_member_id_foreign` (`member_id`),
  KEY `finance_transactions_department_id_foreign` (`department_id`),
  KEY `finance_transactions_created_by_foreign` (`created_by`),
  KEY `finance_transactions_cancelled_by_foreign` (`cancelled_by`),
  KEY `finance_transactions_organization_id_occurred_on_index` (`organization_id`,`occurred_on`),
  KEY `finance_transactions_group_uuid_index` (`group_uuid`),
  KEY `finance_transactions_collection_id_foreign` (`collection_id`),
  KEY `finance_transactions_pledge_id_foreign` (`pledge_id`),
  KEY `finance_transactions_expense_request_id_foreign` (`expense_request_id`),
  KEY `finance_transactions_pay_slip_id_foreign` (`pay_slip_id`),
  KEY `finance_transactions_project_id_foreign` (`project_id`),
  CONSTRAINT `finance_transactions_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finance_transactions_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `finance_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_collection_id_foreign` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_expense_request_id_foreign` FOREIGN KEY (`expense_request_id`) REFERENCES `expense_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finance_transactions_pay_slip_id_foreign` FOREIGN KEY (`pay_slip_id`) REFERENCES `pay_slips` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_pledge_id_foreign` FOREIGN KEY (`pledge_id`) REFERENCES `pledges` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `finance_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `finance_transactions` ENABLE KEYS */;
DROP TABLE IF EXISTS `group_attendances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `group_attendances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_meeting_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `status` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_attendances_group_meeting_id_member_id_unique` (`group_meeting_id`,`member_id`),
  KEY `group_attendances_member_id_foreign` (`member_id`),
  CONSTRAINT `group_attendances_group_meeting_id_foreign` FOREIGN KEY (`group_meeting_id`) REFERENCES `group_meetings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_attendances_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `group_attendances` DISABLE KEYS */;
/*!40000 ALTER TABLE `group_attendances` ENABLE KEYS */;
DROP TABLE IF EXISTS `group_dues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `group_dues` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `period` char(7) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` char(3) NOT NULL,
  `paid_on` date NOT NULL,
  `recorded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_dues_group_id_member_id_period_unique` (`group_id`,`member_id`,`period`),
  KEY `group_dues_member_id_foreign` (`member_id`),
  KEY `group_dues_recorded_by_foreign` (`recorded_by`),
  CONSTRAINT `group_dues_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_dues_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_dues_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `group_dues` DISABLE KEYS */;
/*!40000 ALTER TABLE `group_dues` ENABLE KEYS */;
DROP TABLE IF EXISTS `group_meetings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `group_meetings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `group_id` bigint(20) unsigned NOT NULL,
  `held_on` date NOT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `visitors` smallint(5) unsigned NOT NULL DEFAULT 0,
  `recorded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_meetings_group_id_held_on_unique` (`group_id`,`held_on`),
  KEY `group_meetings_organization_id_foreign` (`organization_id`),
  KEY `group_meetings_recorded_by_foreign` (`recorded_by`),
  CONSTRAINT `group_meetings_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_meetings_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_meetings_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `group_meetings` DISABLE KEYS */;
/*!40000 ALTER TABLE `group_meetings` ENABLE KEYS */;
DROP TABLE IF EXISTS `group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `group_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `role` varchar(10) NOT NULL DEFAULT 'member',
  `joined_on` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_members_group_id_member_id_unique` (`group_id`,`member_id`),
  KEY `group_members_member_id_foreign` (`member_id`),
  CONSTRAINT `group_members_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `group_members_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `group_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `group_members` ENABLE KEYS */;
DROP TABLE IF EXISTS `groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'cell',
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `leader_member_id` bigint(20) unsigned NOT NULL,
  `description` text DEFAULT NULL,
  `schedule` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`schedule`)),
  `place` varchar(255) DEFAULT NULL,
  `dues_amount` decimal(12,2) DEFAULT NULL,
  `dues_currency` char(3) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `groups_organization_id_foreign` (`organization_id`),
  KEY `groups_department_id_foreign` (`department_id`),
  KEY `groups_leader_member_id_foreign` (`leader_member_id`),
  CONSTRAINT `groups_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `groups_leader_member_id_foreign` FOREIGN KEY (`leader_member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `groups_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `groups` ENABLE KEYS */;
DROP TABLE IF EXISTS `households`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `households` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `district` varchar(100) DEFAULT NULL,
  `street` varchar(120) DEFAULT NULL,
  `house_number` varchar(30) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `head_member_id` bigint(20) unsigned DEFAULT NULL,
  `import_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `households_organization_id_foreign` (`organization_id`),
  KEY `households_head_member_id_foreign` (`head_member_id`),
  KEY `households_import_id_foreign` (`import_id`),
  CONSTRAINT `households_head_member_id_foreign` FOREIGN KEY (`head_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `households_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `member_imports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `households_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `households` DISABLE KEYS */;
/*!40000 ALTER TABLE `households` ENABLE KEYS */;
DROP TABLE IF EXISTS `issued_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `issued_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `document_type_id` bigint(20) unsigned NOT NULL,
  `number` varchar(60) NOT NULL,
  `year` smallint(5) unsigned NOT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `register_entry_id` bigint(20) unsigned DEFAULT NULL,
  `beneficiary` varchar(200) NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` longtext NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `signatory` varchar(120) DEFAULT NULL,
  `signatory_title` varchar(80) DEFAULT NULL,
  `issued_on` date NOT NULL,
  `token` char(32) NOT NULL,
  `issued_by` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `issued_documents_organization_id_number_unique` (`organization_id`,`number`),
  UNIQUE KEY `issued_documents_token_unique` (`token`),
  KEY `issued_documents_member_id_foreign` (`member_id`),
  KEY `issued_documents_issued_by_foreign` (`issued_by`),
  KEY `issued_documents_cancelled_by_foreign` (`cancelled_by`),
  KEY `issued_documents_document_type_id_year_sequence_index` (`document_type_id`,`year`,`sequence`),
  KEY `issued_documents_register_entry_id_foreign` (`register_entry_id`),
  CONSTRAINT `issued_documents_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `issued_documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `issued_documents_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `issued_documents_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `issued_documents_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `issued_documents_register_entry_id_foreign` FOREIGN KEY (`register_entry_id`) REFERENCES `register_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `issued_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `issued_documents` ENABLE KEYS */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
DROP TABLE IF EXISTS `legal_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `legal_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(30) NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` longtext NOT NULL,
  `summary` varchar(255) DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `legal_documents_key_version_unique` (`key`,`version`),
  KEY `legal_documents_created_by_foreign` (`created_by`),
  CONSTRAINT `legal_documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `legal_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `legal_documents` ENABLE KEYS */;
DROP TABLE IF EXISTS `life_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `life_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `type` varchar(30) NOT NULL,
  `label` varchar(80) DEFAULT NULL,
  `occurred_on` date DEFAULT NULL,
  `place` varchar(150) DEFAULT NULL,
  `officiant` varchar(120) DEFAULT NULL,
  `witnesses` varchar(255) DEFAULT NULL,
  `register_number` varchar(60) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `life_events_member_id_foreign` (`member_id`),
  CONSTRAINT `life_events_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `life_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `life_events` ENABLE KEYS */;
DROP TABLE IF EXISTS `meeting_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `meeting_decisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `meeting_id` bigint(20) unsigned NOT NULL,
  `text` text NOT NULL,
  `responsible` varchar(255) DEFAULT NULL,
  `due_on` date DEFAULT NULL,
  `plan_action_id` bigint(20) unsigned DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meeting_decisions_meeting_id_foreign` (`meeting_id`),
  KEY `meeting_decisions_plan_action_id_foreign` (`plan_action_id`),
  KEY `meeting_decisions_project_id_foreign` (`project_id`),
  CONSTRAINT `meeting_decisions_meeting_id_foreign` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meeting_decisions_plan_action_id_foreign` FOREIGN KEY (`plan_action_id`) REFERENCES `plan_actions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `meeting_decisions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `meeting_decisions` DISABLE KEYS */;
/*!40000 ALTER TABLE `meeting_decisions` ENABLE KEYS */;
DROP TABLE IF EXISTS `meeting_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `meeting_participants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `meeting_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `attendance` varchar(12) NOT NULL DEFAULT 'present',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meeting_participants_meeting_id_foreign` (`meeting_id`),
  KEY `meeting_participants_member_id_foreign` (`member_id`),
  CONSTRAINT `meeting_participants_meeting_id_foreign` FOREIGN KEY (`meeting_id`) REFERENCES `meetings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meeting_participants_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `meeting_participants` DISABLE KEYS */;
/*!40000 ALTER TABLE `meeting_participants` ENABLE KEYS */;
DROP TABLE IF EXISTS `meetings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `meetings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'council',
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `held_at` datetime NOT NULL,
  `place` varchar(255) DEFAULT NULL,
  `chair` varchar(255) DEFAULT NULL,
  `secretary` varchar(255) DEFAULT NULL,
  `agenda` text DEFAULT NULL,
  `minutes` longtext DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'planned',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meetings_organization_id_foreign` (`organization_id`),
  KEY `meetings_department_id_foreign` (`department_id`),
  KEY `meetings_created_by_foreign` (`created_by`),
  CONSTRAINT `meetings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `meetings_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `meetings_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `meetings` DISABLE KEYS */;
/*!40000 ALTER TABLE `meetings` ENABLE KEYS */;
DROP TABLE IF EXISTS `member_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `key` varchar(60) NOT NULL,
  `label` varchar(80) NOT NULL,
  `type` varchar(20) NOT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `sensitive` tinyint(1) NOT NULL DEFAULT 0,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_fields_organization_id_key_unique` (`organization_id`,`key`),
  CONSTRAINT `member_fields_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `member_fields` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_fields` ENABLE KEYS */;
DROP TABLE IF EXISTS `member_function_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_function_terms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `function_id` bigint(20) unsigned NOT NULL,
  `started_on` date DEFAULT NULL,
  `ended_on` date DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_function_terms_member_id_foreign` (`member_id`),
  KEY `member_function_terms_function_id_foreign` (`function_id`),
  CONSTRAINT `member_function_terms_function_id_foreign` FOREIGN KEY (`function_id`) REFERENCES `member_functions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_function_terms_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `member_function_terms` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_function_terms` ENABLE KEYS */;
DROP TABLE IF EXISTS `member_functions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_functions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(80) NOT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_functions_organization_id_foreign` (`organization_id`),
  CONSTRAINT `member_functions_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `member_functions` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_functions` ENABLE KEYS */;
DROP TABLE IF EXISTS `member_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'analysed',
  `total_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `valid_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `error_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `duplicate_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `imported_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `households_created` int(10) unsigned NOT NULL DEFAULT 0,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `imported_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_imports_organization_id_foreign` (`organization_id`),
  KEY `member_imports_user_id_foreign` (`user_id`),
  CONSTRAINT `member_imports_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_imports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `member_imports` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_imports` ENABLE KEYS */;
DROP TABLE IF EXISTS `member_status_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_status_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `from_status_id` bigint(20) unsigned DEFAULT NULL,
  `to_status_id` bigint(20) unsigned DEFAULT NULL,
  `changed_on` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_status_changes_member_id_foreign` (`member_id`),
  KEY `member_status_changes_from_status_id_foreign` (`from_status_id`),
  KEY `member_status_changes_to_status_id_foreign` (`to_status_id`),
  KEY `member_status_changes_user_id_foreign` (`user_id`),
  CONSTRAINT `member_status_changes_from_status_id_foreign` FOREIGN KEY (`from_status_id`) REFERENCES `member_statuses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `member_status_changes_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_status_changes_to_status_id_foreign` FOREIGN KEY (`to_status_id`) REFERENCES `member_statuses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `member_status_changes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `member_status_changes` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_status_changes` ENABLE KEYS */;
DROP TABLE IF EXISTS `member_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_statuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(60) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'ink',
  `counts_as_member` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `needs_harmonization` tinyint(1) NOT NULL DEFAULT 0,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_statuses_organization_id_foreign` (`organization_id`),
  CONSTRAINT `member_statuses_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `member_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_statuses` ENABLE KEYS */;
DROP TABLE IF EXISTS `member_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) unsigned NOT NULL,
  `from_organization_id` bigint(20) unsigned NOT NULL,
  `to_organization_id` bigint(20) unsigned NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `reason` varchar(255) DEFAULT NULL,
  `decision_note` varchar(255) DEFAULT NULL,
  `old_number` varchar(40) DEFAULT NULL,
  `new_number` varchar(40) DEFAULT NULL,
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `decided_by` bigint(20) unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_transfers_member_id_foreign` (`member_id`),
  KEY `member_transfers_from_organization_id_foreign` (`from_organization_id`),
  KEY `member_transfers_to_organization_id_foreign` (`to_organization_id`),
  KEY `member_transfers_requested_by_foreign` (`requested_by`),
  KEY `member_transfers_decided_by_foreign` (`decided_by`),
  CONSTRAINT `member_transfers_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `member_transfers_from_organization_id_foreign` FOREIGN KEY (`from_organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_transfers_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_transfers_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `member_transfers_to_organization_id_foreign` FOREIGN KEY (`to_organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `member_transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `member_transfers` ENABLE KEYS */;
DROP TABLE IF EXISTS `members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `number` varchar(60) DEFAULT NULL,
  `number_year` smallint(5) unsigned DEFAULT NULL,
  `number_sequence` int(10) unsigned DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `first_name` varchar(80) DEFAULT NULL,
  `gender` char(1) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `birth_place` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone2` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `street` varchar(120) DEFAULT NULL,
  `house_number` varchar(30) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `profession` varchar(100) DEFAULT NULL,
  `marital_status` varchar(20) DEFAULT NULL,
  `education_level` varchar(60) DEFAULT NULL,
  `origin_church` varchar(150) DEFAULT NULL,
  `emergency_contact_name` varchar(120) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `preferred_language` varchar(5) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `card_token` varchar(40) DEFAULT NULL,
  `joined_on` date DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `household_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `household_role` varchar(20) DEFAULT NULL,
  `custom` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`custom`)),
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `import_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `members_organization_id_number_unique` (`organization_id`,`number`),
  UNIQUE KEY `members_card_token_unique` (`card_token`),
  UNIQUE KEY `members_organization_id_user_id_unique` (`organization_id`,`user_id`),
  KEY `members_status_id_foreign` (`status_id`),
  KEY `members_household_id_foreign` (`household_id`),
  KEY `members_created_by_foreign` (`created_by`),
  KEY `members_organization_id_last_name_index` (`organization_id`,`last_name`),
  KEY `members_phone_index` (`phone`),
  KEY `members_import_id_foreign` (`import_id`),
  KEY `members_user_id_foreign` (`user_id`),
  CONSTRAINT `members_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `members_household_id_foreign` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE SET NULL,
  CONSTRAINT `members_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `member_imports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `members_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `members_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `member_statuses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `members` DISABLE KEYS */;
/*!40000 ALTER TABLE `members` ENABLE KEYS */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES
(1,'0001_01_01_000000_create_users_table',1),
(2,'0001_01_01_000001_create_cache_table',1),
(3,'0001_01_01_000002_create_jobs_table',1),
(4,'2026_10_06_000100_create_organizations_tables',1),
(5,'2026_10_06_000200_create_roles_tables',1),
(6,'2026_10_06_000300_create_audit_logs_table',1),
(7,'2026_10_06_000400_create_currency_tables',1),
(8,'2026_10_06_105401_create_passkeys_table',1),
(9,'2026_10_07_000100_create_demo_requests_table',1),
(10,'2026_10_07_000200_add_demo_columns',1),
(11,'2026_10_08_000100_create_member_registry_tables',1),
(12,'2026_10_09_000100_create_member_imports_table',1),
(13,'2026_10_09_000200_add_card_token_to_members',1),
(14,'2026_10_10_000100_create_platform_tables',1),
(15,'2026_10_11_000100_create_finance_tables',1),
(16,'2026_10_11_000200_add_legal_identity_to_organizations',1),
(17,'2026_10_12_000100_create_collection_tables',1),
(18,'2026_10_12_000200_create_pledge_tables',1),
(19,'2026_10_12_000300_create_payment_declarations_table',1),
(20,'2026_10_12_000400_create_expense_tables',1),
(21,'2026_10_13_000100_create_finance_closings_table',1),
(22,'2026_10_14_000100_add_user_id_to_members',1),
(23,'2026_10_14_000200_create_budget_tables',1),
(24,'2026_10_14_000300_create_budget_overruns_table',1),
(25,'2026_10_15_000100_create_plan_and_meeting_tables',1),
(26,'2026_10_16_000100_create_payroll_tables',1),
(27,'2026_10_16_000200_create_pay_run_tables',1),
(28,'2026_10_17_000100_link_payroll_to_budget',1),
(29,'2026_10_18_000100_create_notification_tables',1),
(30,'2026_10_18_000200_create_group_tables',1),
(31,'2026_10_18_000300_create_calendar_tables',1),
(32,'2026_10_18_000400_create_announcements_table',1),
(33,'2026_10_19_000100_create_document_types_table',1),
(34,'2026_10_19_000200_create_issued_documents_table',1),
(35,'2026_10_19_000300_create_register_tables',1),
(36,'2026_10_20_000100_create_pastoral_tables',1),
(37,'2026_10_20_000200_create_document_requests_table',1),
(38,'2026_10_20_000300_create_group_dues',1),
(39,'2026_10_21_000100_create_quota_and_transfer_tables',1),
(40,'2026_10_22_000100_create_website_tables',1),
(41,'2026_10_23_000100_create_support_tables',1),
(42,'2026_10_24_000100_add_photo_to_users',1),
(43,'2026_10_25_000100_enrich_websites',1),
(44,'2026_10_26_000100_group_meeting_schedule',1),
(45,'2026_10_26_000200_link_expenses_to_budget',1),
(46,'2026_10_27_000100_member_photo_on_documents',1),
(47,'2026_10_28_000100_create_projects',1),
(48,'2026_10_29_000100_link_budget_to_projects',1),
(49,'2026_10_30_000100_create_project_remittances',1),
(50,'2026_10_31_000100_document_layouts',1),
(51,'2026_11_01_000100_add_code_to_finance_categories',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` uuid NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `organization_id` bigint(20) unsigned DEFAULT NULL,
  `key` varchar(255) DEFAULT NULL,
  `path` varchar(255) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`),
  KEY `notifications_organization_id_foreign` (`organization_id`),
  KEY `notifications_notifiable_type_notifiable_id_read_at_index` (`notifiable_type`,`notifiable_id`,`read_at`),
  KEY `notifications_key_index` (`key`),
  KEY `notifications_path_index` (`path`),
  CONSTRAINT `notifications_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
DROP TABLE IF EXISTS `organization_currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `organization_currencies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `organization_currencies_organization_id_currency_unique` (`organization_id`,`currency`),
  CONSTRAINT `organization_currencies_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `organization_currencies` DISABLE KEYS */;
/*!40000 ALTER TABLE `organization_currencies` ENABLE KEYS */;
DROP TABLE IF EXISTS `organizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `organizations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `path` varchar(500) NOT NULL DEFAULT '',
  `depth` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `level_label` varchar(60) NOT NULL,
  `name` varchar(255) NOT NULL,
  `short_name` varchar(60) DEFAULT NULL,
  `slug` varchar(255) NOT NULL,
  `country` varchar(2) NOT NULL DEFAULT 'CD',
  `province` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `legal` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`legal`)),
  `locale` varchar(5) NOT NULL DEFAULT 'fr',
  `timezone` varchar(64) NOT NULL DEFAULT 'Africa/Lubumbashi',
  `terminology` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`terminology`)),
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `status` varchar(20) NOT NULL DEFAULT 'trial',
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `demo_expires_at` timestamp NULL DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `support_access_until` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `organizations_slug_unique` (`slug`),
  KEY `organizations_parent_id_foreign` (`parent_id`),
  KEY `organizations_created_by_foreign` (`created_by`),
  KEY `organizations_path_index` (`path`),
  KEY `organizations_is_demo_index` (`is_demo`),
  CONSTRAINT `organizations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `organizations_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `organizations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `organizations` DISABLE KEYS */;
/*!40000 ALTER TABLE `organizations` ENABLE KEYS */;
DROP TABLE IF EXISTS `passkeys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `passkeys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `credential` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`credential`)),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `passkeys_credential_id_unique` (`credential_id`),
  KEY `passkeys_user_id_index` (`user_id`),
  CONSTRAINT `passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `passkeys` DISABLE KEYS */;
/*!40000 ALTER TABLE `passkeys` ENABLE KEYS */;
DROP TABLE IF EXISTS `pastoral_cases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pastoral_cases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `person_name` varchar(150) DEFAULT NULL,
  `person_phone` varchar(30) DEFAULT NULL,
  `kind` varchar(20) NOT NULL,
  `title` varchar(160) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'open',
  `source` varchar(10) NOT NULL DEFAULT 'app',
  `opened_on` date NOT NULL,
  `next_on` date DEFAULT NULL,
  `closed_on` date DEFAULT NULL,
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pastoral_cases_member_id_foreign` (`member_id`),
  KEY `pastoral_cases_assigned_to_foreign` (`assigned_to`),
  KEY `pastoral_cases_created_by_foreign` (`created_by`),
  KEY `pastoral_cases_organization_id_status_index` (`organization_id`,`status`),
  CONSTRAINT `pastoral_cases_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pastoral_cases_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pastoral_cases_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pastoral_cases_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pastoral_cases` DISABLE KEYS */;
/*!40000 ALTER TABLE `pastoral_cases` ENABLE KEYS */;
DROP TABLE IF EXISTS `pastoral_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pastoral_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pastoral_case_id` bigint(20) unsigned NOT NULL,
  `author_id` bigint(20) unsigned DEFAULT NULL,
  `kind` varchar(10) NOT NULL DEFAULT 'note',
  `happened_on` date NOT NULL,
  `body` text NOT NULL,
  `is_confidential` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pastoral_notes_pastoral_case_id_foreign` (`pastoral_case_id`),
  KEY `pastoral_notes_author_id_foreign` (`author_id`),
  CONSTRAINT `pastoral_notes_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pastoral_notes_pastoral_case_id_foreign` FOREIGN KEY (`pastoral_case_id`) REFERENCES `pastoral_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pastoral_notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `pastoral_notes` ENABLE KEYS */;
DROP TABLE IF EXISTS `pay_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `kind` varchar(10) NOT NULL,
  `calculation` varchar(16) NOT NULL,
  `default_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `applies_to_all` tinyint(1) NOT NULL DEFAULT 0,
  `is_statutory` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pay_items_organization_id_foreign` (`organization_id`),
  CONSTRAINT `pay_items_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pay_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `pay_items` ENABLE KEYS */;
DROP TABLE IF EXISTS `pay_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `pay_schedule_id` bigint(20) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'draft',
  `prepared_by` bigint(20) unsigned DEFAULT NULL,
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approval_note` varchar(255) DEFAULT NULL,
  `return_note` varchar(255) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pay_runs_organization_id_foreign` (`organization_id`),
  KEY `pay_runs_pay_schedule_id_foreign` (`pay_schedule_id`),
  KEY `pay_runs_prepared_by_foreign` (`prepared_by`),
  KEY `pay_runs_submitted_by_foreign` (`submitted_by`),
  KEY `pay_runs_approved_by_foreign` (`approved_by`),
  CONSTRAINT `pay_runs_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pay_runs_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pay_runs_pay_schedule_id_foreign` FOREIGN KEY (`pay_schedule_id`) REFERENCES `pay_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pay_runs_prepared_by_foreign` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pay_runs_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pay_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `pay_runs` ENABLE KEYS */;
DROP TABLE IF EXISTS `pay_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `every` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `service_label` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pay_schedules_organization_id_foreign` (`organization_id`),
  CONSTRAINT `pay_schedules_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pay_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `pay_schedules` ENABLE KEYS */;
DROP TABLE IF EXISTS `pay_slips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_slips` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pay_run_id` bigint(20) unsigned NOT NULL,
  `payee_id` bigint(20) unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `quantity` decimal(8,2) NOT NULL DEFAULT 1.00,
  `adjustments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`adjustments`)),
  `base` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gross` decimal(14,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(14,2) NOT NULL DEFAULT 0.00,
  `advance_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net` decimal(14,2) NOT NULL DEFAULT 0.00,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `cash_account_id` bigint(20) unsigned DEFAULT NULL,
  `paid_currency` char(3) DEFAULT NULL,
  `paid_amount` decimal(16,2) DEFAULT NULL,
  `finance_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pay_slips_pay_run_id_foreign` (`pay_run_id`),
  KEY `pay_slips_payee_id_foreign` (`payee_id`),
  KEY `pay_slips_cash_account_id_foreign` (`cash_account_id`),
  KEY `pay_slips_finance_transaction_id_foreign` (`finance_transaction_id`),
  CONSTRAINT `pay_slips_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pay_slips_finance_transaction_id_foreign` FOREIGN KEY (`finance_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pay_slips_pay_run_id_foreign` FOREIGN KEY (`pay_run_id`) REFERENCES `pay_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pay_slips_payee_id_foreign` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pay_slips` DISABLE KEYS */;
/*!40000 ALTER TABLE `pay_slips` ENABLE KEYS */;
DROP TABLE IF EXISTS `payee_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payee_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payee_id` bigint(20) unsigned NOT NULL,
  `pay_item_id` bigint(20) unsigned NOT NULL,
  `value` decimal(12,2) DEFAULT NULL,
  `is_excluded` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payee_items_payee_id_pay_item_id_unique` (`payee_id`,`pay_item_id`),
  KEY `payee_items_pay_item_id_foreign` (`pay_item_id`),
  CONSTRAINT `payee_items_pay_item_id_foreign` FOREIGN KEY (`pay_item_id`) REFERENCES `pay_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payee_items_payee_id_foreign` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `payee_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `payee_items` ENABLE KEYS */;
DROP TABLE IF EXISTS `payees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `pay_schedule_id` bigint(20) unsigned NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `base_amount` decimal(14,2) NOT NULL,
  `payment_method` varchar(8) NOT NULL DEFAULT 'cash',
  `payment_number` varchar(255) DEFAULT NULL,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payees_organization_id_foreign` (`organization_id`),
  KEY `payees_member_id_foreign` (`member_id`),
  KEY `payees_department_id_foreign` (`department_id`),
  KEY `payees_pay_schedule_id_foreign` (`pay_schedule_id`),
  CONSTRAINT `payees_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payees_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payees_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payees_pay_schedule_id_foreign` FOREIGN KEY (`pay_schedule_id`) REFERENCES `pay_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `payees` DISABLE KEYS */;
/*!40000 ALTER TABLE `payees` ENABLE KEYS */;
DROP TABLE IF EXISTS `payment_declarations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_declarations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `declarant_name` varchar(150) DEFAULT NULL,
  `declarant_phone` varchar(20) DEFAULT NULL,
  `amount` decimal(20,2) NOT NULL,
  `currency` char(3) NOT NULL,
  `operator` varchar(40) NOT NULL,
  `transaction_reference` varchar(100) NOT NULL,
  `paid_on` date NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `pledge_id` bigint(20) unsigned DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `screenshot_path` varchar(255) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'staff',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `cash_account_id` bigint(20) unsigned DEFAULT NULL,
  `finance_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_declarations_member_id_foreign` (`member_id`),
  KEY `payment_declarations_category_id_foreign` (`category_id`),
  KEY `payment_declarations_pledge_id_foreign` (`pledge_id`),
  KEY `payment_declarations_cash_account_id_foreign` (`cash_account_id`),
  KEY `payment_declarations_finance_transaction_id_foreign` (`finance_transaction_id`),
  KEY `payment_declarations_reviewed_by_foreign` (`reviewed_by`),
  KEY `payment_declarations_created_by_foreign` (`created_by`),
  KEY `payment_declarations_organization_id_status_index` (`organization_id`,`status`),
  KEY `payment_declarations_organization_id_transaction_reference_index` (`organization_id`,`transaction_reference`),
  CONSTRAINT `payment_declarations_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_declarations_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `finance_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_declarations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_declarations_finance_transaction_id_foreign` FOREIGN KEY (`finance_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_declarations_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_declarations_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_declarations_pledge_id_foreign` FOREIGN KEY (`pledge_id`) REFERENCES `pledges` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_declarations_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `payment_declarations` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_declarations` ENABLE KEYS */;
DROP TABLE IF EXISTS `plan_action_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_action_updates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_action_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `progress` tinyint(3) unsigned NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `plan_action_updates_plan_action_id_foreign` (`plan_action_id`),
  KEY `plan_action_updates_user_id_foreign` (`user_id`),
  CONSTRAINT `plan_action_updates_plan_action_id_foreign` FOREIGN KEY (`plan_action_id`) REFERENCES `plan_actions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plan_action_updates_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `plan_action_updates` DISABLE KEYS */;
/*!40000 ALTER TABLE `plan_action_updates` ENABLE KEYS */;
DROP TABLE IF EXISTS `plan_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `plan_objective_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `responsible_member_id` bigint(20) unsigned DEFAULT NULL,
  `responsible_name` varchar(255) DEFAULT NULL,
  `starts_on` date DEFAULT NULL,
  `due_on` date DEFAULT NULL,
  `estimated_cost` decimal(14,2) DEFAULT NULL,
  `progress` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `status` varchar(12) NOT NULL DEFAULT 'planned',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `plan_actions_organization_id_foreign` (`organization_id`),
  KEY `plan_actions_plan_objective_id_foreign` (`plan_objective_id`),
  KEY `plan_actions_department_id_foreign` (`department_id`),
  KEY `plan_actions_responsible_member_id_foreign` (`responsible_member_id`),
  CONSTRAINT `plan_actions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `plan_actions_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plan_actions_plan_objective_id_foreign` FOREIGN KEY (`plan_objective_id`) REFERENCES `plan_objectives` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plan_actions_responsible_member_id_foreign` FOREIGN KEY (`responsible_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `plan_actions` DISABLE KEYS */;
/*!40000 ALTER TABLE `plan_actions` ENABLE KEYS */;
DROP TABLE IF EXISTS `plan_objectives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_objectives` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `vision_id` bigint(20) unsigned DEFAULT NULL,
  `fiscal_year` smallint(5) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `indicator` varchar(255) DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `plan_objectives_vision_id_foreign` (`vision_id`),
  KEY `plan_objectives_department_id_foreign` (`department_id`),
  KEY `plan_objectives_organization_id_fiscal_year_index` (`organization_id`,`fiscal_year`),
  CONSTRAINT `plan_objectives_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `plan_objectives_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plan_objectives_vision_id_foreign` FOREIGN KEY (`vision_id`) REFERENCES `visions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `plan_objectives` DISABLE KEYS */;
/*!40000 ALTER TABLE `plan_objectives` ENABLE KEYS */;
DROP TABLE IF EXISTS `plan_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` bigint(20) unsigned NOT NULL,
  `tier` varchar(20) NOT NULL,
  `monthly_usd` decimal(10,2) NOT NULL,
  `effective_from` date NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `plan_prices_created_by_foreign` (`created_by`),
  KEY `plan_prices_plan_id_tier_effective_from_index` (`plan_id`,`tier`,`effective_from`),
  CONSTRAINT `plan_prices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `plan_prices_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `plan_prices` DISABLE KEYS */;
/*!40000 ALTER TABLE `plan_prices` ENABLE KEYS */;
DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(30) NOT NULL,
  `name` varchar(60) NOT NULL,
  `meaning` varchar(60) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `modules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`modules`)),
  `featured` tinyint(1) NOT NULL DEFAULT 0,
  `quote_only` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plans_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `plans` ENABLE KEYS */;
DROP TABLE IF EXISTS `platform_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `platform_settings` (
  `key` varchar(60) NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`value`)),
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`key`),
  KEY `platform_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `platform_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `platform_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `platform_settings` ENABLE KEYS */;
DROP TABLE IF EXISTS `pledge_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pledge_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pledge_id` bigint(20) unsigned NOT NULL,
  `received_on` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `value` decimal(20,2) NOT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pledge_deliveries_pledge_id_foreign` (`pledge_id`),
  KEY `pledge_deliveries_received_by_foreign` (`received_by`),
  CONSTRAINT `pledge_deliveries_pledge_id_foreign` FOREIGN KEY (`pledge_id`) REFERENCES `pledges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pledge_deliveries_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pledge_deliveries` DISABLE KEYS */;
/*!40000 ALTER TABLE `pledge_deliveries` ENABLE KEYS */;
DROP TABLE IF EXISTS `pledge_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pledge_reminders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pledge_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `channel` varchar(20) NOT NULL DEFAULT 'whatsapp',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pledge_reminders_pledge_id_foreign` (`pledge_id`),
  KEY `pledge_reminders_user_id_foreign` (`user_id`),
  CONSTRAINT `pledge_reminders_pledge_id_foreign` FOREIGN KEY (`pledge_id`) REFERENCES `pledges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pledge_reminders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pledge_reminders` DISABLE KEYS */;
/*!40000 ALTER TABLE `pledge_reminders` ENABLE KEYS */;
DROP TABLE IF EXISTS `pledges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pledges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `household_id` bigint(20) unsigned DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `pledger_name` varchar(150) DEFAULT NULL,
  `pledger_phone` varchar(20) DEFAULT NULL,
  `kind` varchar(10) NOT NULL DEFAULT 'money',
  `amount` decimal(20,2) NOT NULL,
  `currency` char(3) NOT NULL,
  `in_kind_description` varchar(255) DEFAULT NULL,
  `frequency` varchar(10) NOT NULL DEFAULT 'once',
  `installments` smallint(5) unsigned NOT NULL DEFAULT 1,
  `pledged_on` date NOT NULL,
  `first_due_on` date DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pledges_campaign_id_foreign` (`project_id`),
  KEY `pledges_member_id_foreign` (`member_id`),
  KEY `pledges_household_id_foreign` (`household_id`),
  KEY `pledges_department_id_foreign` (`department_id`),
  KEY `pledges_created_by_foreign` (`created_by`),
  KEY `pledges_organization_id_status_index` (`organization_id`,`status`),
  CONSTRAINT `pledges_campaign_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pledges_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pledges_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pledges_household_id_foreign` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pledges_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pledges_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `pledges` DISABLE KEYS */;
/*!40000 ALTER TABLE `pledges` ENABLE KEYS */;
DROP TABLE IF EXISTS `prayer_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `prayer_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `requester_name` varchar(150) DEFAULT NULL,
  `requester_phone` varchar(30) DEFAULT NULL,
  `subject` varchar(160) NOT NULL,
  `body` text DEFAULT NULL,
  `is_private` tinyint(1) NOT NULL DEFAULT 1,
  `source` varchar(10) NOT NULL DEFAULT 'app',
  `status` varchar(10) NOT NULL DEFAULT 'open',
  `answer` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `handled_by` bigint(20) unsigned DEFAULT NULL,
  `handled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `prayer_requests_organization_id_foreign` (`organization_id`),
  KEY `prayer_requests_member_id_foreign` (`member_id`),
  KEY `prayer_requests_created_by_foreign` (`created_by`),
  KEY `prayer_requests_handled_by_foreign` (`handled_by`),
  CONSTRAINT `prayer_requests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prayer_requests_handled_by_foreign` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prayer_requests_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prayer_requests_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `prayer_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `prayer_requests` ENABLE KEYS */;
DROP TABLE IF EXISTS `project_indicator_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_indicator_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_indicator_id` bigint(20) unsigned NOT NULL,
  `value` decimal(14,2) NOT NULL,
  `measured_on` date NOT NULL,
  `note` text DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_indicator_values_project_indicator_id_foreign` (`project_indicator_id`),
  KEY `project_indicator_values_user_id_foreign` (`user_id`),
  CONSTRAINT `project_indicator_values_project_indicator_id_foreign` FOREIGN KEY (`project_indicator_id`) REFERENCES `project_indicators` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_indicator_values_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `project_indicator_values` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_indicator_values` ENABLE KEYS */;
DROP TABLE IF EXISTS `project_indicators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_indicators` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `unit` varchar(30) DEFAULT NULL,
  `baseline` decimal(14,2) NOT NULL DEFAULT 0.00,
  `target` decimal(14,2) DEFAULT NULL,
  `current` decimal(14,2) DEFAULT NULL,
  `weight` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `due_on` date DEFAULT NULL,
  `reached_on` date DEFAULT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_indicators_project_id_foreign` (`project_id`),
  CONSTRAINT `project_indicators_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `project_indicators` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_indicators` ENABLE KEYS */;
DROP TABLE IF EXISTS `project_remittances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_remittances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `parent_project_id` bigint(20) unsigned NOT NULL,
  `from_organization_id` bigint(20) unsigned NOT NULL,
  `to_organization_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `currency` char(3) NOT NULL,
  `usd_amount` decimal(14,2) NOT NULL,
  `paid_on` date NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'sent',
  `expense_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `income_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `sent_by` bigint(20) unsigned DEFAULT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_remittances_project_id_foreign` (`project_id`),
  KEY `project_remittances_parent_project_id_foreign` (`parent_project_id`),
  KEY `project_remittances_from_organization_id_foreign` (`from_organization_id`),
  KEY `project_remittances_expense_transaction_id_foreign` (`expense_transaction_id`),
  KEY `project_remittances_income_transaction_id_foreign` (`income_transaction_id`),
  KEY `project_remittances_sent_by_foreign` (`sent_by`),
  KEY `project_remittances_received_by_foreign` (`received_by`),
  KEY `project_remittances_to_organization_id_status_index` (`to_organization_id`,`status`),
  CONSTRAINT `project_remittances_expense_transaction_id_foreign` FOREIGN KEY (`expense_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_remittances_from_organization_id_foreign` FOREIGN KEY (`from_organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_remittances_income_transaction_id_foreign` FOREIGN KEY (`income_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_remittances_parent_project_id_foreign` FOREIGN KEY (`parent_project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_remittances_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_remittances_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_remittances_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `project_remittances_to_organization_id_foreign` FOREIGN KEY (`to_organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `project_remittances` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_remittances` ENABLE KEYS */;
DROP TABLE IF EXISTS `project_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_updates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `progress` tinyint(3) unsigned NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_updates_project_id_foreign` (`project_id`),
  KEY `project_updates_user_id_foreign` (`user_id`),
  CONSTRAINT `project_updates_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_updates_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `project_updates` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_updates` ENABLE KEYS */;
DROP TABLE IF EXISTS `project_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_years` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `fiscal_year` smallint(5) unsigned NOT NULL,
  `income_planned` decimal(14,2) NOT NULL DEFAULT 0.00,
  `expense_planned` decimal(14,2) NOT NULL DEFAULT 0.00,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_years_project_id_fiscal_year_unique` (`project_id`,`fiscal_year`),
  CONSTRAINT `project_years_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `project_years` DISABLE KEYS */;
/*!40000 ALTER TABLE `project_years` ENABLE KEYS */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `parent_project_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'project',
  `description` text DEFAULT NULL,
  `theme` varchar(150) DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `responsible_member_id` bigint(20) unsigned DEFAULT NULL,
  `responsible_name` varchar(150) DEFAULT NULL,
  `goal_amount` decimal(20,2) DEFAULT NULL,
  `goal_currency` char(3) NOT NULL DEFAULT 'USD',
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `cash_account_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `progress` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `legacy_plan_action_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `campaigns_organization_id_foreign` (`organization_id`),
  KEY `campaigns_category_id_foreign` (`category_id`),
  KEY `projects_department_id_foreign` (`department_id`),
  KEY `projects_responsible_member_id_foreign` (`responsible_member_id`),
  KEY `projects_cash_account_id_foreign` (`cash_account_id`),
  KEY `projects_parent_project_id_foreign` (`parent_project_id`),
  CONSTRAINT `campaigns_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `finance_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `campaigns_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `projects_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_parent_project_id_foreign` FOREIGN KEY (`parent_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_responsible_member_id_foreign` FOREIGN KEY (`responsible_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `projects` DISABLE KEYS */;
/*!40000 ALTER TABLE `projects` ENABLE KEYS */;
DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `endpoint` text NOT NULL,
  `endpoint_hash` char(64) NOT NULL,
  `public_key` varchar(255) NOT NULL,
  `auth_token` varchar(255) NOT NULL,
  `content_encoding` varchar(20) NOT NULL DEFAULT 'aes128gcm',
  `device` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `push_subscriptions_endpoint_hash_unique` (`endpoint_hash`),
  KEY `push_subscriptions_user_id_foreign` (`user_id`),
  CONSTRAINT `push_subscriptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `push_subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `push_subscriptions` ENABLE KEYS */;
DROP TABLE IF EXISTS `quota_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quota_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_organization_id` bigint(20) unsigned NOT NULL,
  `to_organization_id` bigint(20) unsigned NOT NULL,
  `period` char(7) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `currency` char(3) NOT NULL,
  `usd_amount` decimal(14,2) NOT NULL,
  `paid_on` date NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'sent',
  `expense_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `income_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `sent_by` bigint(20) unsigned DEFAULT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quota_payments_from_organization_id_foreign` (`from_organization_id`),
  KEY `quota_payments_expense_transaction_id_foreign` (`expense_transaction_id`),
  KEY `quota_payments_income_transaction_id_foreign` (`income_transaction_id`),
  KEY `quota_payments_sent_by_foreign` (`sent_by`),
  KEY `quota_payments_received_by_foreign` (`received_by`),
  KEY `quota_payments_to_organization_id_period_index` (`to_organization_id`,`period`),
  CONSTRAINT `quota_payments_expense_transaction_id_foreign` FOREIGN KEY (`expense_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quota_payments_from_organization_id_foreign` FOREIGN KEY (`from_organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quota_payments_income_transaction_id_foreign` FOREIGN KEY (`income_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quota_payments_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quota_payments_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quota_payments_to_organization_id_foreign` FOREIGN KEY (`to_organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `quota_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `quota_payments` ENABLE KEYS */;
DROP TABLE IF EXISTS `quota_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quota_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `mode` varchar(10) NOT NULL,
  `percent` decimal(5,2) DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `currency` char(3) DEFAULT NULL,
  `starts_period` char(7) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quota_rules_organization_id_unique` (`organization_id`),
  CONSTRAINT `quota_rules_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `quota_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `quota_rules` ENABLE KEYS */;
DROP TABLE IF EXISTS `register_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `register_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `register_id` bigint(20) unsigned NOT NULL,
  `entry_number` varchar(20) NOT NULL,
  `page` varchar(20) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `place` varchar(150) DEFAULT NULL,
  `officiant` varchar(120) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `first_name` varchar(80) DEFAULT NULL,
  `gender` char(1) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `birth_place` varchar(100) DEFAULT NULL,
  `father` varchar(150) DEFAULT NULL,
  `mother` varchar(150) DEFAULT NULL,
  `partner_name` varchar(200) DEFAULT NULL,
  `witnesses` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `register_entries_member_id_foreign` (`member_id`),
  KEY `register_entries_created_by_foreign` (`created_by`),
  KEY `register_entries_register_id_entry_number_index` (`register_id`,`entry_number`),
  KEY `register_entries_organization_id_last_name_index` (`organization_id`,`last_name`),
  CONSTRAINT `register_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `register_entries_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `register_entries_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `register_entries_register_id_foreign` FOREIGN KEY (`register_id`) REFERENCES `registers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `register_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `register_entries` ENABLE KEYS */;
DROP TABLE IF EXISTS `registers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `registers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `from_year` smallint(5) unsigned DEFAULT NULL,
  `to_year` smallint(5) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `registers_organization_id_foreign` (`organization_id`),
  CONSTRAINT `registers_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `registers` DISABLE KEYS */;
/*!40000 ALTER TABLE `registers` ENABLE KEYS */;
DROP TABLE IF EXISTS `role_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `organization_id` bigint(20) unsigned NOT NULL,
  `includes_descendants` tinyint(1) NOT NULL DEFAULT 0,
  `granted_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_assignments_user_id_role_id_organization_id_unique` (`user_id`,`role_id`,`organization_id`),
  KEY `role_assignments_role_id_foreign` (`role_id`),
  KEY `role_assignments_organization_id_foreign` (`organization_id`),
  KEY `role_assignments_granted_by_foreign` (`granted_by`),
  CONSTRAINT `role_assignments_granted_by_foreign` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `role_assignments_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_assignments_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_assignments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `role_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_assignments` ENABLE KEYS */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `key` varchar(60) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`permissions`)),
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_organization_id_name_unique` (`organization_id`,`name`),
  CONSTRAINT `roles_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
DROP TABLE IF EXISTS `salary_advance_repayments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `salary_advance_repayments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `salary_advance_id` bigint(20) unsigned NOT NULL,
  `pay_slip_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `salary_advance_repayments_salary_advance_id_foreign` (`salary_advance_id`),
  KEY `salary_advance_repayments_pay_slip_id_foreign` (`pay_slip_id`),
  CONSTRAINT `salary_advance_repayments_pay_slip_id_foreign` FOREIGN KEY (`pay_slip_id`) REFERENCES `pay_slips` (`id`) ON DELETE SET NULL,
  CONSTRAINT `salary_advance_repayments_salary_advance_id_foreign` FOREIGN KEY (`salary_advance_id`) REFERENCES `salary_advances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `salary_advance_repayments` DISABLE KEYS */;
/*!40000 ALTER TABLE `salary_advance_repayments` ENABLE KEYS */;
DROP TABLE IF EXISTS `salary_advances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `salary_advances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `payee_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `currency` char(3) NOT NULL,
  `installments` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `reason` text DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'requested',
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `decision_note` varchar(255) DEFAULT NULL,
  `cash_account_id` bigint(20) unsigned DEFAULT NULL,
  `finance_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `salary_advances_organization_id_foreign` (`organization_id`),
  KEY `salary_advances_payee_id_foreign` (`payee_id`),
  KEY `salary_advances_requested_by_foreign` (`requested_by`),
  KEY `salary_advances_approved_by_foreign` (`approved_by`),
  KEY `salary_advances_cash_account_id_foreign` (`cash_account_id`),
  KEY `salary_advances_finance_transaction_id_foreign` (`finance_transaction_id`),
  CONSTRAINT `salary_advances_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `salary_advances_cash_account_id_foreign` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `salary_advances_finance_transaction_id_foreign` FOREIGN KEY (`finance_transaction_id`) REFERENCES `finance_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `salary_advances_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `salary_advances_payee_id_foreign` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `salary_advances_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `salary_advances` DISABLE KEYS */;
/*!40000 ALTER TABLE `salary_advances` ENABLE KEYS */;
DROP TABLE IF EXISTS `sermons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sermons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `preacher` varchar(255) DEFAULT NULL,
  `preached_on` date NOT NULL,
  `passage` varchar(255) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `video_url` varchar(500) DEFAULT NULL,
  `audio_path` varchar(255) DEFAULT NULL,
  `audio_size` int(10) unsigned DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sermons_created_by_foreign` (`created_by`),
  KEY `sermons_organization_id_preached_on_index` (`organization_id`,`preached_on`),
  CONSTRAINT `sermons_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sermons_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `sermons` DISABLE KEYS */;
/*!40000 ALTER TABLE `sermons` ENABLE KEYS */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
DROP TABLE IF EXISTS `subscription_declarations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_declarations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned NOT NULL,
  `tier` varchar(20) NOT NULL,
  `cycle` varchar(10) NOT NULL,
  `expected_usd` decimal(10,2) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'USD',
  `method` varchar(30) NOT NULL,
  `reference` varchar(100) NOT NULL,
  `paid_on` date NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `subscription_id` bigint(20) unsigned DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `declared_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscription_declarations_organization_id_foreign` (`organization_id`),
  KEY `subscription_declarations_plan_id_foreign` (`plan_id`),
  KEY `subscription_declarations_subscription_id_foreign` (`subscription_id`),
  KEY `subscription_declarations_declared_by_foreign` (`declared_by`),
  KEY `subscription_declarations_reviewed_by_foreign` (`reviewed_by`),
  KEY `subscription_declarations_status_created_at_index` (`status`,`created_at`),
  CONSTRAINT `subscription_declarations_declared_by_foreign` FOREIGN KEY (`declared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_declarations_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscription_declarations_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`),
  CONSTRAINT `subscription_declarations_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscription_declarations_subscription_id_foreign` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `subscription_declarations` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscription_declarations` ENABLE KEYS */;
DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned NOT NULL,
  `tier` varchar(20) NOT NULL,
  `cycle` varchar(10) NOT NULL,
  `months` smallint(5) unsigned NOT NULL,
  `monthly_usd` decimal(10,2) NOT NULL,
  `amount_usd` decimal(10,2) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `payment_method` varchar(30) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscriptions_plan_id_foreign` (`plan_id`),
  KEY `subscriptions_recorded_by_foreign` (`recorded_by`),
  KEY `subscriptions_organization_id_ends_on_index` (`organization_id`,`ends_on`),
  CONSTRAINT `subscriptions_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscriptions_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`),
  CONSTRAINT `subscriptions_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscriptions` ENABLE KEYS */;
DROP TABLE IF EXISTS `support_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `support_ticket_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `from_staff` tinyint(1) NOT NULL DEFAULT 0,
  `body` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_messages_support_ticket_id_foreign` (`support_ticket_id`),
  KEY `support_messages_user_id_foreign` (`user_id`),
  CONSTRAINT `support_messages_support_ticket_id_foreign` FOREIGN KEY (`support_ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `support_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `support_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_messages` ENABLE KEYS */;
DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `number` varchar(20) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `category` varchar(20) NOT NULL DEFAULT 'question',
  `status` varchar(12) NOT NULL DEFAULT 'open',
  `opened_by` bigint(20) unsigned DEFAULT NULL,
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `support_tickets_number_unique` (`number`),
  KEY `support_tickets_organization_id_foreign` (`organization_id`),
  KEY `support_tickets_opened_by_foreign` (`opened_by`),
  KEY `support_tickets_assigned_to_foreign` (`assigned_to`),
  KEY `support_tickets_status_last_activity_at_index` (`status`,`last_activity_at`),
  CONSTRAINT `support_tickets_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_tickets_opened_by_foreign` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `support_tickets_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `support_tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_tickets` ENABLE KEYS */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `locale` varchar(5) NOT NULL DEFAULT 'fr',
  `is_platform_staff` tinyint(1) NOT NULL DEFAULT 0,
  `platform_role` varchar(20) DEFAULT NULL,
  `terms_version` int(10) unsigned DEFAULT NULL,
  `terms_accepted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `current_organization_id` bigint(20) unsigned DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_current_organization_id_foreign` (`current_organization_id`),
  KEY `users_is_demo_index` (`is_demo`),
  CONSTRAINT `users_current_organization_id_foreign` FOREIGN KEY (`current_organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
DROP TABLE IF EXISTS `visions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `visions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `statement` text DEFAULT NULL,
  `starts_year` smallint(5) unsigned NOT NULL,
  `ends_year` smallint(5) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `visions_organization_id_foreign` (`organization_id`),
  CONSTRAINT `visions_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `visions` DISABLE KEYS */;
/*!40000 ALTER TABLE `visions` ENABLE KEYS */;
DROP TABLE IF EXISTS `website_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `website_photos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `path` varchar(255) NOT NULL,
  `thumb_path` varchar(255) NOT NULL,
  `caption` varchar(160) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `website_photos_created_by_foreign` (`created_by`),
  KEY `website_photos_organization_id_created_at_index` (`organization_id`,`created_at`),
  CONSTRAINT `website_photos_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `website_photos_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `website_photos` DISABLE KEYS */;
/*!40000 ALTER TABLE `website_photos` ENABLE KEYS */;
DROP TABLE IF EXISTS `websites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `websites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` bigint(20) unsigned NOT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `theme` varchar(20) NOT NULL DEFAULT 'chaleureux',
  `pages` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pages`)),
  `tagline` varchar(255) DEFAULT NULL,
  `welcome_title` varchar(255) DEFAULT NULL,
  `welcome_text` text DEFAULT NULL,
  `verse_text` text DEFAULT NULL,
  `verse_reference` varchar(80) DEFAULT NULL,
  `cover_path` varchar(255) DEFAULT NULL,
  `about_text` text DEFAULT NULL,
  `beliefs_text` text DEFAULT NULL,
  `pastor_name` varchar(255) DEFAULT NULL,
  `pastor_message` text DEFAULT NULL,
  `leaders` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`leaders`)),
  `giving_text` text DEFAULT NULL,
  `giving_accounts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`giving_accounts`)),
  `giving_categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`giving_categories`)),
  `public_groups` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`public_groups`)),
  `whatsapp` varchar(20) DEFAULT NULL,
  `map_url` varchar(500) DEFAULT NULL,
  `facebook_url` varchar(300) DEFAULT NULL,
  `youtube_url` varchar(300) DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `websites_organization_id_unique` (`organization_id`),
  CONSTRAINT `websites_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `websites` DISABLE KEYS */;
/*!40000 ALTER TABLE `websites` ENABLE KEYS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

