-- ============================================================================
-- GSO Integrated Services Operations and E-Ticketing System
-- Production Database Dump & Schema Definition
-- Compatible with MySQL 8.0+ and MariaDB 10.4+
-- Clean Fresh Reimport Baseline
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. CORE & REFERENCE ENTITIES
-- ----------------------------------------------------------------------------

-- Table structure for table `units`
DROP TABLE IF EXISTS `units`;
CREATE TABLE `units` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL UNIQUE,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `units` (`id`, `code`, `name`, `description`) VALUES
(1, 'FGMU', 'Facilities and Grounds Management Unit', 'Manages structure, finishes, utilities, mechanical, electrical, and carpentry repairs across campus.'),
(2, 'LEAU', 'Landscape and Environment Aesthetics Unit', 'Responsible for campus landscaping, grounds maintenance, janitorial operations, and environmental disinfection.'),
(3, 'SSU', 'Security Service Unit', 'Coordinates campus security personnel, incident response, investigation, and physical campus safety.');

-- Table structure for table `users`
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` text NOT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `role` enum('student','employee','admin','staff','director','superadmin') NOT NULL DEFAULT 'student',
  `unit_id` int(11) UNSIGNED DEFAULT NULL,
  `student_id_number` varchar(50) DEFAULT NULL,
  `student_type` varchar(50) DEFAULT NULL,
  `employee_type` varchar(100) DEFAULT NULL,
  `organization_name` varchar(150) DEFAULT NULL,
  `college` varchar(150) DEFAULT NULL,
  `id_card_image` text DEFAULT NULL,
  `id_selfie_image` text DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `status` enum('Active','Pending','Rejected','Suspended') NOT NULL DEFAULT 'Active',
  `is_verified` tinyint(1) NOT NULL DEFAULT 1,
  `email_notifications_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Per-account opt-in for ticket/request email updates (SSU alerts, dispatch, etc.)',
  `failed_login_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `lockout_until` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_unit` (`unit_id`),
  CONSTRAINT `fk_users_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Institutional Seed Users (Default password for administrative mock testing: 'password')
INSERT INTO `users` (`id`, `first_name`, `last_name`, `email`, `password_hash`, `contact_number`, `role`, `unit_id`, `student_id_number`, `student_type`, `employee_type`, `organization_name`, `college`, `id_card_image`, `avatar_path`, `status`, `is_verified`, `failed_login_attempts`, `lockout_until`) VALUES
('a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', 'Super', 'Administrator', 'superadmin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', '09123456789', 'superadmin', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', 1, 0, NULL),
('f12d1cfd-a338-41ca-88de-b29ea8e71f33', 'GSO', 'Director', 'director@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'director', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', 1, 0, NULL),
('cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'FGMU', 'Admin', 'fgmu-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', 1, 0, NULL),
('5322c820-a591-452a-be5c-cedb24b45f71', 'LEAU', 'Admin', 'leau-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', 1, 0, NULL),
('e6f927fb-aec7-4ab9-85a9-9af83f1d4dc9', 'SSU', 'Admin', 'ssu-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', 1, 0, NULL),
('3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'University', 'Requestor', 'enduser@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', '09171234567', 'employee', NULL, 'EMP-2301', NULL, 'Teaching Staff', NULL, 'College of Information Sciences (CIS)', NULL, NULL, 'Active', 1, 0, NULL);

-- Table structure for table `personnel`
DROP TABLE IF EXISTS `personnel`;
CREATE TABLE `personnel` (
  `id` varchar(36) NOT NULL,
  `unit_id` int(11) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `specialty` varchar(100) NOT NULL,
  `status` enum('available','working','on_leave') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_personnel_unit_status` (`unit_id`,`status`),
  CONSTRAINT `fk_personnel_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `personnel_categories`
DROP TABLE IF EXISTS `personnel_categories`;
CREATE TABLE `personnel_categories` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id` int(11) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `supported_services` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_unit` (`unit_id`,`name`),
  CONSTRAINT `fk_categories_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `personnel_categories` (`id`, `unit_id`, `name`, `is_system`, `supported_services`) VALUES
(1, 1, 'Plumber', 1, '["Plumbing & Sanitary Works"]'),
(2, 1, 'Electrician', 1, '["Electrical Work", "Electronics & Communication Works"]'),
(3, 1, 'Carpenter', 1, '["Carpentry & Joinery"]'),
(4, 1, 'Mason', 1, '["Masonry Works", "Concrete Works"]'),
(5, 1, 'Painter', 1, '["Painting Works"]'),
(6, 1, 'Welder', 1, '["Welding & Tinsmith Works"]'),
(7, 1, 'Refrigeration & Aircon Technician', 1, '["Mechanical Works"]'),
(8, 2, 'Landscaper', 1, '["Planting/ Landscaping", "Borrowing of plants"]'),
(9, 2, 'Groundskeeper', 1, '["Mowing/ Weeding", "Pruning/ Cutting"]'),
(10, 2, 'Janitor', 1, '["Cleaning/ Grubbing", "Disinfection"]'),
(11, 2, 'Garbage Collector', 1, '["Hauling", "Cleaning/ Grubbing"]');

-- ----------------------------------------------------------------------------
-- 5. BORROWING SYSTEM TABLES (LEAU)
-- ----------------------------------------------------------------------------

-- Table structure for table `inventory_items`
DROP TABLE IF EXISTS `inventory_items`;
CREATE TABLE `inventory_items` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id` int(11) UNSIGNED NOT NULL DEFAULT 2,
  `name` varchar(255) NOT NULL,
  `model` varchar(255) DEFAULT NULL,
  `category` enum('tools','equipment','plants','materials','others') NOT NULL DEFAULT 'tools',
  `serial_number` varchar(150) DEFAULT NULL,
  `quantity_total` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `quantity_available` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `condition_status` enum('excellent','good','fair','needs_repair','retired') NOT NULL DEFAULT 'good',
  `location` varchar(255) DEFAULT NULL COMMENT 'Storage location within LEAU',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inventory_unit` (`unit_id`),
  KEY `idx_inventory_category` (`category`),
  KEY `idx_inventory_available` (`quantity_available`),
  CONSTRAINT `fk_inventory_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventory_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `borrowing_requests`
DROP TABLE IF EXISTS `borrowing_requests`;
CREATE TABLE `borrowing_requests` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) NOT NULL,
  `borrower_name` varchar(255) NOT NULL,
  `borrower_id_number` varchar(50) NOT NULL,
  `borrower_type` enum('student','faculty','staff') NOT NULL,
  `department_major` varchar(150) DEFAULT NULL,
  `borrower_email` varchar(255) NOT NULL,
  `borrower_contact` varchar(30) NOT NULL,
  `item_name_requested` varchar(255) NOT NULL,
  `item_model_requested` varchar(255) DEFAULT NULL,
  `quantity_needed` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `purpose_project` text NOT NULL,
  `date_needed` date NOT NULL,
  `expected_return_date` date NOT NULL,
  `terms_agreed` tinyint(1) NOT NULL DEFAULT 0,
  `terms_agreed_at` datetime DEFAULT NULL,
  `status` enum('pending_director','approved_director','inventory_assigned','ready_for_pickup','picked_up','overdue','returned','cancelled') NOT NULL DEFAULT 'pending_director',
  `assigned_inventory_id` int(11) UNSIGNED DEFAULT NULL,
  `assigned_quantity` int(11) UNSIGNED DEFAULT NULL,
  `picked_up_at` datetime DEFAULT NULL,
  `picked_up_by` varchar(36) DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `returned_by` varchar(36) DEFAULT NULL,
  `return_condition` enum('excellent','good','fair','damaged','lost') DEFAULT NULL,
  `return_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_borrowing_ticket` (`ticket_id`),
  KEY `idx_borrowing_status` (`status`),
  KEY `idx_borrowing_dates` (`date_needed`,`expected_return_date`),
  KEY `idx_borrowing_inventory` (`assigned_inventory_id`),
  CONSTRAINT `fk_borrowing_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_borrowing_inventory` FOREIGN KEY (`assigned_inventory_id`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_borrowing_picked_by` FOREIGN KEY (`picked_up_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_borrowing_returned_by` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `borrowing_attachments`
DROP TABLE IF EXISTS `borrowing_attachments`;
CREATE TABLE `borrowing_attachments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `borrowing_request_id` int(11) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` text NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size_bytes` bigint(20) UNSIGNED DEFAULT NULL,
  `attachment_type` enum('usage_photos','event_documents','project_docs','others') NOT NULL DEFAULT 'others',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_borrowing_attach_request` (`borrowing_request_id`),
  CONSTRAINT `fk_borrowing_attach_request` FOREIGN KEY (`borrowing_request_id`) REFERENCES `borrowing_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `borrowing_history`
DROP TABLE IF EXISTS `borrowing_history`;
CREATE TABLE `borrowing_history` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `borrowing_request_id` int(11) UNSIGNED NOT NULL,
  `action` varchar(50) NOT NULL COMMENT 'created, director_approved, inventory_assigned, ready_for_pickup, picked_up, overdue, returned, cancelled',
  `performed_by` varchar(36) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_history_request` (`borrowing_request_id`),
  KEY `idx_history_action` (`action`),
  KEY `idx_history_performed_by` (`performed_by`),
  CONSTRAINT `fk_history_request` FOREIGN KEY (`borrowing_request_id`) REFERENCES `borrowing_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_history_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. CORE TICKETING ENGINE & ATTACHMENTS
-- ----------------------------------------------------------------------------

-- Table structure for table `tickets`
DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
  `id` varchar(60) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `unit_id` int(11) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `service_type` varchar(150) NOT NULL,
  `is_recategorized` tinyint(1) NOT NULL DEFAULT 0,
  `original_service_type` varchar(150) DEFAULT NULL,
  `recategorized_at` datetime DEFAULT NULL,
  `recategorized_by` varchar(36) DEFAULT NULL,
  `recategorization_reason` text DEFAULT NULL,
  `description` text NOT NULL,
  `status` enum('pending','approved','processing','resolved','closed','declined','cancelled') NOT NULL DEFAULT 'pending',
  `status_label` varchar(100) NOT NULL DEFAULT 'Pending Approval',
  `is_emergency` tinyint(1) NOT NULL DEFAULT 0,
  `is_approval_delayed` tinyint(1) NOT NULL DEFAULT 0,
  `approval_delay_reason` text DEFAULT NULL,
  `approval_delayed_at` datetime DEFAULT NULL,
  `approval_delayed_by` varchar(36) DEFAULT NULL,
  `verification_status` enum('pending_report','pending_verification','verified_closed') NOT NULL DEFAULT 'pending_report',
  `accomplishment_report_path` varchar(255) DEFAULT NULL,
  `accomplishment_notes` text DEFAULT NULL,
  `verified_by_user_id` varchar(36) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `decline_reason` text DEFAULT NULL,
  `current_step` int(1) NOT NULL DEFAULT 1,
  `eodb_tier` varchar(50) DEFAULT NULL,
  `eodb_days` int(11) DEFAULT 3,
  `target_completion_date` datetime DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `office_room` varchar(100) DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `materials_logged` tinyint(1) NOT NULL DEFAULT 0,
  `is_labor_only` tinyint(1) NOT NULL DEFAULT 0,
  `materials_stage` varchar(20) NOT NULL DEFAULT 'none',
  `is_under_investigation` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'SSU only: 1 when flagged for active investigation',
  `ssu_notation` text DEFAULT NULL COMMENT 'SSU only: staff recommendation/notation communicated to reporter',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` varchar(36) DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_project` tinyint(1) NOT NULL DEFAULT 0,
  `project_title` varchar(255) DEFAULT NULL,
  `project_target_duration` varchar(100) DEFAULT NULL,
  `project_target_date` date DEFAULT NULL,
  `project_manpower` varchar(255) DEFAULT NULL,
  `project_remarks` text DEFAULT NULL,
  `project_actual_start` date DEFAULT NULL,
  `project_actual_completion` date DEFAULT NULL,
  `project_working_days` int(11) DEFAULT NULL,
  `extension_days` int(11) NOT NULL DEFAULT 0,
  `extended_completion_date` date DEFAULT NULL,
  `extension_reason` text DEFAULT NULL,
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_tickets_user` (`user_id`),
  KEY `idx_tickets_unit_status` (`unit_id`,`status`),
  KEY `idx_tickets_archived` (`is_archived`),
  KEY `idx_tickets_approval_delayed` (`is_approval_delayed`),
  KEY `idx_tickets_submitted` (`submitted_at`),
  CONSTRAINT `fk_tickets_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tickets_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tickets_verified_by` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_delayed_by` FOREIGN KEY (`approval_delayed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ticket_attachments`
DROP TABLE IF EXISTS `ticket_attachments`;
CREATE TABLE `ticket_attachments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` text NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size_bytes` bigint(20) UNSIGNED DEFAULT NULL,
  `is_encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `encryption_iv` varchar(64) DEFAULT NULL,
  `encryption_tag` varchar(64) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attachments_ticket` (`ticket_id`),
  CONSTRAINT `fk_attachments_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. UNIT-SPECIFIC EXTENSION DETAILS
-- ----------------------------------------------------------------------------

-- Table structure for table `fgmu_ticket_details`
DROP TABLE IF EXISTS `fgmu_ticket_details`;
CREATE TABLE `fgmu_ticket_details` (
  `ticket_id` varchar(60) NOT NULL,
  `college_building` varchar(255) NOT NULL,
  `office_room` varchar(100) NOT NULL,
  `source_of_fund` varchar(150) DEFAULT NULL,
  `jr_no` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  CONSTRAINT `fk_fgmu_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `leau_ticket_details`
DROP TABLE IF EXISTS `leau_ticket_details`;
CREATE TABLE `leau_ticket_details` (
  `ticket_id` varchar(60) NOT NULL,
  `college_building` varchar(255) NOT NULL,
  `office_room` varchar(100) NOT NULL,
  `source_of_fund` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  CONSTRAINT `fk_leau_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ssu_incident_details`
DROP TABLE IF EXISTS `ssu_incident_details`;
CREATE TABLE `ssu_incident_details` (
  `ticket_id` varchar(60) NOT NULL,
  `other_incident` text DEFAULT NULL,
  `other_information` text DEFAULT NULL,
  `follow_up` tinyint(1) NOT NULL DEFAULT 0,
  `who_involved` text DEFAULT NULL,
  `where_occurred` text NOT NULL,
  `when_occurred` varchar(150) NOT NULL,
  `how_narrative` text NOT NULL,
  `reporter_name` varchar(255) NOT NULL,
  `reporter_signature` longtext DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  CONSTRAINT `fk_ssu_incident_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lookup and bridge tables for SSU
DROP TABLE IF EXISTS `ssu_incident_types`;
CREATE TABLE `ssu_incident_types` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type_name` varchar(150) NOT NULL UNIQUE,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ssu_incident_types` (`id`, `type_name`) VALUES
(1, 'Theft / Robbery'),
(2, 'Vandalism / Property Damage'),
(3, 'Physical Assault / Altercation'),
(4, 'Trespassing / Unauthorized Entry'),
(5, 'Road Accident / Vehicular Collision'),
(6, 'Medical Emergency / Injury'),
(7, 'Fire / Hazard Alert'),
(8, 'Other Security Concern');

DROP TABLE IF EXISTS `ssu_incident_type_items`;
CREATE TABLE `ssu_incident_type_items` (
  `ticket_id` varchar(60) NOT NULL,
  `incident_type_id` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`ticket_id`,`incident_type_id`),
  KEY `fk_incident_item_type` (`incident_type_id`),
  CONSTRAINT `fk_incident_item_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_incident_item_type` FOREIGN KEY (`incident_type_id`) REFERENCES `ssu_incident_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ssu_incident_issues`;
CREATE TABLE `ssu_incident_issues` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue_name` varchar(150) NOT NULL UNIQUE,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ssu_incident_issues` (`id`, `issue_name`) VALUES
(1, 'Lost / Stolen Personal Belongings'),
(2, 'Damaged University Facilities / Equipment'),
(3, 'Safety Policy Violation'),
(4, 'Traffic Regulation Violation'),
(5, 'Suspicious Activity Observed');

DROP TABLE IF EXISTS `ssu_incident_issue_items`;
CREATE TABLE `ssu_incident_issue_items` (
  `ticket_id` varchar(60) NOT NULL,
  `issue_id` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`ticket_id`,`issue_id`),
  KEY `fk_issue_item_issue` (`issue_id`),
  CONSTRAINT `fk_issue_item_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_issue_item_issue` FOREIGN KEY (`issue_id`) REFERENCES `ssu_incident_issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ssu_incident_roles`;
CREATE TABLE `ssu_incident_roles` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_name` varchar(150) NOT NULL UNIQUE,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ssu_incident_roles` (`id`, `role_name`) VALUES
(1, 'Victim / Complainant'),
(2, 'Eyewitness'),
(3, 'Security Officer on Duty'),
(4, 'Responding Personnel');

DROP TABLE IF EXISTS `ssu_incident_role_items`;
CREATE TABLE `ssu_incident_role_items` (
  `ticket_id` varchar(60) NOT NULL,
  `role_id` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`ticket_id`,`role_id`),
  KEY `fk_role_item_role` (`role_id`),
  CONSTRAINT `fk_role_item_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_item_role` FOREIGN KEY (`role_id`) REFERENCES `ssu_incident_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. DISPATCHING, ASSIGNMENTS & MATERIALS
-- ----------------------------------------------------------------------------

-- Table structure for table `ticket_collaborations`
DROP TABLE IF EXISTS `ticket_collaborations`;
CREATE TABLE `ticket_collaborations` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) NOT NULL,
  `requesting_unit_id` int(11) UNSIGNED NOT NULL,
  `collaborating_unit_id` int(11) UNSIGNED NOT NULL,
  `requested_by` varchar(36) DEFAULT NULL,
  `reason` text NOT NULL,
  `scope_of_work` text DEFAULT NULL,
  `status` enum('pending','accepted','declined','completed','cancelled') NOT NULL DEFAULT 'pending',
  `response_notes` text DEFAULT NULL,
  `responded_by` varchar(36) DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_collab_ticket` (`ticket_id`),
  KEY `idx_collab_collab_unit` (`collaborating_unit_id`, `status`),
  KEY `idx_collab_req_unit` (`requesting_unit_id`, `status`),
  CONSTRAINT `fk_collab_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_collab_req_unit` FOREIGN KEY (`requesting_unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_collab_collab_unit` FOREIGN KEY (`collaborating_unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ticket_assignments`
DROP TABLE IF EXISTS `ticket_assignments`;
CREATE TABLE `ticket_assignments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) NOT NULL,
  `personnel_id` varchar(36) NOT NULL,
  `implementation_date` varchar(100) DEFAULT NULL,
  `working_days` int(11) DEFAULT NULL,
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT 0.00,
  `is_reassigned` tinyint(1) NOT NULL DEFAULT 0,
  `reassigned_from_id` varchar(36) DEFAULT NULL,
  `reassigned_reason` text DEFAULT NULL,
  `dispatcher_notes` text DEFAULT NULL,
  `task_notes` varchar(255) DEFAULT NULL,
  `is_emergency` tinyint(1) NOT NULL DEFAULT 0,
  `queue_order` int(11) NOT NULL DEFAULT 1,
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assignments_ticket` (`ticket_id`),
  KEY `idx_assignments_personnel` (`personnel_id`),
  KEY `idx_assignments_reassigned_from` (`reassigned_from_id`),
  CONSTRAINT `fk_assignment_personnel` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignment_reassigned_from` FOREIGN KEY (`reassigned_from_id`) REFERENCES `personnel` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assignment_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ticket_materials`
DROP TABLE IF EXISTS `ticket_materials`;
CREATE TABLE `ticket_materials` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) NOT NULL,
  `assignment_id` int(11) UNSIGNED DEFAULT NULL,
  `material_name` varchar(255) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_measurement` varchar(50) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stage` varchar(20) NOT NULL DEFAULT 'assessment',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_materials_ticket` (`ticket_id`),
  KEY `idx_materials_assignment` (`assignment_id`),
  CONSTRAINT `fk_materials_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_materials_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `ticket_assignments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. FEEDBACK, EVALUATION & DELAY REASONS
-- ----------------------------------------------------------------------------

-- Table structure for table `ticket_feedbacks`
DROP TABLE IF EXISTS `ticket_feedbacks`;
CREATE TABLE `ticket_feedbacks` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) NOT NULL UNIQUE,
  `user_id` varchar(36) NOT NULL,
  `completion_status` enum('early','on-time','beyond-time','not-completed') NOT NULL,
  `courtesy_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `quality_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `efficiency_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `timeliness_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `cleanliness_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_feedbacks_user` (`user_id`),
  CONSTRAINT `fk_feedback_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `feedback_delay_reasons`
DROP TABLE IF EXISTS `feedback_delay_reasons`;
CREATE TABLE `feedback_delay_reasons` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reason_code` varchar(60) NOT NULL UNIQUE,
  `reason_label` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `feedback_delay_reasons` (`id`, `reason_code`, `reason_label`) VALUES
(1, 'personnelAbsent', 'Assigned personnel was absent or unavailable'),
(2, 'extendedBreak', 'Personnel took extended breaks during the repair/task'),
(3, 'additionalWork', 'Unexpected additional work or complications arose'),
(4, 'lackDays', 'Insufficient number of days allotted for the job scope'),
(5, 'lackMaterials', 'Delay due to lack of replacement parts or materials'),
(6, 'lackSkills', 'Required specialized tools or external expertise');

-- Table structure for table `ticket_feedback_delay_items`
DROP TABLE IF EXISTS `ticket_feedback_delay_items`;
CREATE TABLE `ticket_feedback_delay_items` (
  `feedback_id` int(11) UNSIGNED NOT NULL,
  `delay_reason_id` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`feedback_id`,`delay_reason_id`),
  KEY `fk_delay_item_reason` (`delay_reason_id`),
  CONSTRAINT `fk_delay_item_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `ticket_feedbacks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_delay_item_reason` FOREIGN KEY (`delay_reason_id`) REFERENCES `feedback_delay_reasons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. AUDIT TRAILS & ACTIVITY LOGS (SEPARATION OF CONCERNS)
-- ----------------------------------------------------------------------------

-- A. Business Process Audit Trail (Ticket lifecycle operations & dispatches)
DROP TABLE IF EXISTS `ticket_logs`;
CREATE TABLE `ticket_logs` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) NOT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_logs_ticket` (`ticket_id`),
  KEY `idx_logs_created` (`created_at`),
  KEY `idx_logs_user` (`user_id`),
  CONSTRAINT `fk_logs_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B. Account Activities Audit Trail (Security & Authentication events, RA 10173 compliant)
DROP TABLE IF EXISTS `account_activity_logs`;
CREATE TABLE `account_activity_logs` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` varchar(36) DEFAULT NULL,
  `target_user_id` varchar(36) DEFAULT NULL,
  `event_type` varchar(60) NOT NULL,
  `severity` enum('info','notice','warning','critical') NOT NULL DEFAULT 'info',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `device_summary` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_act_logs_actor` (`actor_id`),
  KEY `idx_act_logs_target` (`target_user_id`),
  KEY `idx_act_logs_event` (`event_type`),
  KEY `idx_act_logs_severity` (`severity`),
  KEY `idx_act_logs_created` (`created_at`),
  CONSTRAINT `fk_act_logs_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_act_logs_target` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. SYSTEM, AUTHENTICATION & SECURITY SESSIONS
-- ----------------------------------------------------------------------------

-- Table structure for table `user_sessions`
DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `session_id` varchar(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_sessions_user` (`user_id`),
  KEY `idx_user_sessions_sid` (`session_id`),
  CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `notifications`
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` varchar(36) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `otp_codes`
DROP TABLE IF EXISTS `otp_codes`;
CREATE TABLE `otp_codes` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `code` varchar(50) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_data` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_otp_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `system_settings`
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`key`, `value`, `description`) VALUES
('resend_api_key', NULL, 'API Key from Resend.com for transactional email delivery'),
('resend_from_email', 'GSO E-Ticketing <onboarding@resend.dev>', 'Sender email address for outgoing system emails'),
('resend_notifications_enabled', '1', 'Global toggle for email notification dispatch (1 = active, 0 = paused)'),
('google_drive_folder_id', NULL, 'Target Google Drive folder ID for cloud database backups'),
('google_drive_credentials_json', NULL, 'Google Cloud Service Account JSON credentials');

-- Table structure for table `password_resets`
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_password_resets_email` (`email`),
  KEY `idx_password_resets_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `system_backups`
DROP TABLE IF EXISTS `system_backups`;
CREATE TABLE `system_backups` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size_bytes` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `backup_type` enum('manual','scheduled') NOT NULL DEFAULT 'manual',
  `tables_included` text DEFAULT NULL,
  `google_drive_file_id` varchar(255) DEFAULT NULL,
  `google_drive_link` text DEFAULT NULL,
  `google_drive_status` enum('not_configured','pending','uploaded','failed') NOT NULL DEFAULT 'not_configured',
  `google_drive_error` text DEFAULT NULL,
  `status` enum('completed','in_progress','failed') NOT NULL DEFAULT 'completed',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` varchar(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_backups_created` (`created_at`),
  KEY `idx_backups_status` (`status`),
  KEY `fk_backups_user` (`created_by`),
  CONSTRAINT `fk_backups_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;


/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
