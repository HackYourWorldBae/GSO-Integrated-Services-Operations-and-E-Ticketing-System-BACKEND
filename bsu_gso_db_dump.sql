-- Database: bsu_gso_db 
-- (Database creation removed for Hostinger compatibility)

SET FOREIGN_KEY_CHECKS=0;


-- Table structure for table `feedback_delay_reasons`
DROP TABLE IF EXISTS `feedback_delay_reasons`;
CREATE TABLE `feedback_delay_reasons` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `reason_code` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_label` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reason_code` (`reason_code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `feedback_delay_reasons`
INSERT INTO `feedback_delay_reasons` VALUES ('1', 'personnelAbsent', 'Assigned personnel was absent or unavailable'),
('2', 'extendedBreak', 'Personnel took extended breaks during the repair/task'),
('3', 'additionalWork', 'Unexpected additional work or complications arose'),
('4', 'lackDays', 'Insufficient number of days allotted for the job scope'),
('5', 'lackMaterials', 'Delay due to lack of replacement parts or materials'),
('6', 'lackSkills', 'Required specialized tools or external expertise');


-- Table structure for table `fgmu_ticket_details`
DROP TABLE IF EXISTS `fgmu_ticket_details`;
CREATE TABLE `fgmu_ticket_details` (
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `college_building` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `office_room` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_of_fund` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jr_no` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  CONSTRAINT `fk_fgmu_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `fgmu_ticket_details`


-- Table structure for table `leau_ticket_details`
DROP TABLE IF EXISTS `leau_ticket_details`;
CREATE TABLE `leau_ticket_details` (
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `college_building` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `office_room` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_of_fund` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  CONSTRAINT `fk_leau_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table structure for table `migrations`
DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) NOT NULL,
  `class` varchar(255) NOT NULL,
  `group` varchar(255) NOT NULL,
  `namespace` varchar(255) NOT NULL,
  `time` int(11) NOT NULL,
  `batch` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4;

-- Dumping data for table `migrations`
INSERT INTO `migrations` VALUES ('1', '2026_07_17_000001', 'App\\Database\\Migrations\\CreateUnitsTable', 'default', 'App', '1784812908', '1'),
('2', '2026_07_17_000002', 'App\\Database\\Migrations\\CreateUsersTable', 'default', 'App', '1784812909', '1'),
('3', '2026_07_17_000003', 'App\\Database\\Migrations\\CreatePersonnelTable', 'default', 'App', '1784812909', '1'),
('4', '2026_07_17_000005', 'App\\Database\\Migrations\\CreateTicketsTable', 'default', 'App', '1784812909', '1'),
('5', '2026_07_17_000006', 'App\\Database\\Migrations\\CreateTicketAttachmentsTable', 'default', 'App', '1784812909', '1'),
('6', '2026_07_17_000007', 'App\\Database\\Migrations\\CreateUnitTicketDetailTables', 'default', 'App', '1784812909', '1'),
('7', '2026_07_17_000008', 'App\\Database\\Migrations\\CreateSsuLookupAndBridgeTables', 'default', 'App', '1784812910', '1'),
('8', '2026_07_17_000009', 'App\\Database\\Migrations\\CreateTicketAssignmentsAndMaterialsTables', 'default', 'App', '1784812910', '1'),
('9', '2026_07_17_000010', 'App\\Database\\Migrations\\CreateFeedbackAndDelayReasonTables', 'default', 'App', '1784812910', '1'),
('10', '2026_07_17_000011', 'App\\Database\\Migrations\\CreateTicketLogsTable', 'default', 'App', '1784812910', '1'),
('11', '2026_07_17_000012', 'App\\Database\\Migrations\\CreateNotificationsTable', 'default', 'App', '1784812911', '1'),
('12', '2026_07_17_000013', 'App\\Database\\Migrations\\CreateOtpCodesTable', 'default', 'App', '1784812912', '1'),
('13', '2026_08_04_000001', 'App\\Database\\Migrations\\CreateCiSessionsTable', 'default', 'App', '1784812913', '1'),
('14', '2026_08_16_000002', 'App\\Database\\Migrations\\CreatePersonnelCategoriesTable', 'default', 'App', '1784812914', '1'),
('15', '2026_09_08_000001', 'App\\Database\\Migrations\\CreateUserSessionsTable', 'default', 'App', '1784812915', '1'),
('16', '2026_09_08_000002', 'App\\Database\\Migrations\\CreateRolePermissionsTable', 'default', 'App', '1784812916', '1');


-- Table structure for table `personnel`
DROP TABLE IF EXISTS `personnel`;
CREATE TABLE `personnel` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` int(11) unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `specialty` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('available','working','on_leave','on_trip') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_personnel_unit_status` (`unit_id`,`status`),
  CONSTRAINT `fk_personnel_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No data seeded. Personnel are managed via the Admin UI.


-- Table structure for table `personnel_categories`
DROP TABLE IF EXISTS `personnel_categories`;
CREATE TABLE `personnel_categories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `unit_id` int(11) unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_unit` (`unit_id`,`name`),
  KEY `idx_categories_unit` (`unit_id`),
  CONSTRAINT `fk_categories_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




-- Table structure for table `ssu_incident_details`
DROP TABLE IF EXISTS `ssu_incident_details`;
CREATE TABLE `ssu_incident_details` (
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `other_incident` text COLLATE utf8mb4_unicode_ci,
  `other_information` text COLLATE utf8mb4_unicode_ci,
  `follow_up` tinyint(1) NOT NULL DEFAULT '0',
  `who_involved` text COLLATE utf8mb4_unicode_ci,
  `where_occurred` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `when_occurred` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `how_narrative` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `reporter_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reporter_signature` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`ticket_id`),
  CONSTRAINT `fk_ssu_incident_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table structure for table `ssu_incident_issue_items`
DROP TABLE IF EXISTS `ssu_incident_issue_items`;
CREATE TABLE `ssu_incident_issue_items` (
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `issue_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`ticket_id`,`issue_id`),
  KEY `fk_issue_item_issue` (`issue_id`),
  CONSTRAINT `fk_issue_item_issue` FOREIGN KEY (`issue_id`) REFERENCES `ssu_incident_issues` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_issue_item_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table structure for table `ssu_incident_issues`
DROP TABLE IF EXISTS `ssu_incident_issues`;
CREATE TABLE `ssu_incident_issues` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `issue_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `issue_name` (`issue_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `ssu_incident_issues`
INSERT INTO `ssu_incident_issues` VALUES ('2', 'Damaged University Facilities / Equipment'),
('1', 'Lost / Stolen Personal Belongings'),
('3', 'Safety Policy Violation'),
('5', 'Suspicious Activity Observed'),
('4', 'Traffic Regulation Violation');


-- Table structure for table `ssu_incident_role_items`
DROP TABLE IF EXISTS `ssu_incident_role_items`;
CREATE TABLE `ssu_incident_role_items` (
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`ticket_id`,`role_id`),
  KEY `fk_role_item_role` (`role_id`),
  CONSTRAINT `fk_role_item_role` FOREIGN KEY (`role_id`) REFERENCES `ssu_incident_roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_role_item_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table structure for table `ssu_incident_roles`
DROP TABLE IF EXISTS `ssu_incident_roles`;
CREATE TABLE `ssu_incident_roles` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `ssu_incident_roles`
INSERT INTO `ssu_incident_roles` VALUES ('2', 'Eyewitness'),
('4', 'Responding Personnel'),
('3', 'Security Officer on Duty'),
('1', 'Victim / Complainant');


-- Table structure for table `ssu_incident_type_items`
DROP TABLE IF EXISTS `ssu_incident_type_items`;
CREATE TABLE `ssu_incident_type_items` (
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `incident_type_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`ticket_id`,`incident_type_id`),
  KEY `fk_incident_item_type` (`incident_type_id`),
  CONSTRAINT `fk_incident_item_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_incident_item_type` FOREIGN KEY (`incident_type_id`) REFERENCES `ssu_incident_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table structure for table `ssu_incident_types`
DROP TABLE IF EXISTS `ssu_incident_types`;
CREATE TABLE `ssu_incident_types` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `type_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_name` (`type_name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `ssu_incident_types`
INSERT INTO `ssu_incident_types` VALUES ('7', 'Fire / Hazard Alert'),
('6', 'Medical Emergency / Injury'),
('8', 'Other Security Concern'),
('3', 'Physical Assault / Altercation'),
('5', 'Road Accident / Vehicular Collision'),
('1', 'Theft / Robbery'),
('4', 'Trespassing / Unauthorized Entry'),
('2', 'Vandalism / Property Damage');





-- Table structure for table `ticket_assignments`
DROP TABLE IF EXISTS `ticket_assignments`;
CREATE TABLE `ticket_assignments` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `personnel_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `implementation_date` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `working_days` int(11) DEFAULT NULL,
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `is_reassigned` tinyint(1) NOT NULL DEFAULT '0',
  `reassigned_from_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reassigned_reason` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dispatcher_notes` text COLLATE utf8mb4_unicode_ci,
  `task_notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_emergency` tinyint(1) NOT NULL DEFAULT '0',
  `queue_order` int(11) NOT NULL DEFAULT '1',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assignments_ticket` (`ticket_id`),
  KEY `idx_assignments_personnel` (`personnel_id`),
  KEY `idx_assignments_reassigned_from` (`reassigned_from_id`),
  CONSTRAINT `fk_assignment_personnel` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assignment_reassigned_from` FOREIGN KEY (`reassigned_from_id`) REFERENCES `personnel` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_assignment_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `ticket_assignments`


-- Table structure for table `ticket_attachments`
DROP TABLE IF EXISTS `ticket_attachments`;
CREATE TABLE `ticket_attachments` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size_bytes` bigint(20) unsigned DEFAULT NULL,
  `is_encrypted` tinyint(1) NOT NULL DEFAULT '0',
  `encryption_iv` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `encryption_tag` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attachments_ticket` (`ticket_id`),
  CONSTRAINT `fk_attachments_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `ticket_attachments`


-- Table structure for table `ticket_feedback_delay_items`
DROP TABLE IF EXISTS `ticket_feedback_delay_items`;
CREATE TABLE `ticket_feedback_delay_items` (
  `feedback_id` int(11) unsigned NOT NULL,
  `delay_reason_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`feedback_id`,`delay_reason_id`),
  KEY `fk_delay_item_reason` (`delay_reason_id`),
  CONSTRAINT `fk_delay_item_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `ticket_feedbacks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_delay_item_reason` FOREIGN KEY (`delay_reason_id`) REFERENCES `feedback_delay_reasons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table structure for table `ticket_feedbacks`
DROP TABLE IF EXISTS `ticket_feedbacks`;
CREATE TABLE `ticket_feedbacks` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `completion_status` enum('on-time','beyond-time','not-completed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `courtesy_rating` tinyint(3) unsigned NOT NULL DEFAULT '5',
  `quality_rating` tinyint(3) unsigned NOT NULL DEFAULT '5',
  `efficiency_rating` tinyint(3) unsigned NOT NULL DEFAULT '5',
  `timeliness_rating` tinyint(3) unsigned NOT NULL DEFAULT '5',
  `cleanliness_rating` tinyint(3) unsigned NOT NULL DEFAULT '5',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_id` (`ticket_id`),
  KEY `idx_feedbacks_user` (`user_id`),
  CONSTRAINT `fk_feedback_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `ticket_feedbacks`


-- Table structure for table `ticket_logs`
DROP TABLE IF EXISTS `ticket_logs`;
CREATE TABLE `ticket_logs` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_logs_user` (`user_id`),
  KEY `idx_logs_ticket` (`ticket_id`),
  KEY `idx_logs_created` (`created_at`),
  CONSTRAINT `fk_logs_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `ticket_logs`


-- Table structure for table `ticket_materials`
DROP TABLE IF EXISTS `ticket_materials`;
CREATE TABLE `ticket_materials` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assignment_id` int(11) unsigned DEFAULT NULL,
  `material_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `unit_measurement` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_materials_ticket` (`ticket_id`),
  KEY `idx_materials_assignment` (`assignment_id`),
  CONSTRAINT `fk_materials_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_materials_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `ticket_assignments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table structure for table `tickets`
DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
  `id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` int(11) unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_type` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','approved','processing','resolved','closed','declined','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `status_label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending Approval',
  `verification_status` enum('pending_report','pending_verification','verified_closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_report',
  `accomplishment_report_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accomplishment_notes` text COLLATE utf8mb4_unicode_ci,
  `verified_by_user_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `decline_reason` text COLLATE utf8mb4_unicode_ci,
  `current_step` int(1) NOT NULL DEFAULT '1',
  `eodb_tier` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eodb_days` int(11) DEFAULT 3,
  `target_completion_date` datetime DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `office_room` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT '0',
  `materials_logged` tinyint(1) NOT NULL DEFAULT '0',
  `is_under_investigation` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'SSU only: 1 when flagged for active investigation',
  `ssu_notation` text COLLATE utf8mb4_unicode_ci COMMENT 'SSU only: staff recommendation/notation communicated to reporter',
  `submitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_project` tinyint(1) NOT NULL DEFAULT '0',
  `project_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_target_duration` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_target_date` date DEFAULT NULL,
  `project_manpower` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_remarks` text COLLATE utf8mb4_unicode_ci,
  `project_actual_start` date DEFAULT NULL,
  `project_actual_completion` date DEFAULT NULL,
  `project_working_days` int(11) DEFAULT NULL,
  `extension_days` int(11) NOT NULL DEFAULT '0',
  `extended_completion_date` date DEFAULT NULL,
  `extension_reason` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `fk_tickets_reviewer` (`reviewed_by`),
  KEY `idx_tickets_verified_by` (`verified_by_user_id`),
  KEY `idx_tickets_user` (`user_id`),
  KEY `idx_tickets_unit_status` (`unit_id`,`status`),
  KEY `idx_tickets_archived` (`is_archived`),
  KEY `idx_tickets_investigating` (`unit_id`,`is_under_investigation`,`is_archived`),
  KEY `idx_tickets_submitted` (`submitted_at`),
  CONSTRAINT `fk_tickets_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL,
  CONSTRAINT `fk_tickets_verified_by` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `tickets`


-- Table structure for table `units`
DROP TABLE IF EXISTS `units`;
CREATE TABLE `units` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `units`
INSERT INTO `units` VALUES ('1', 'FGMU', 'Facilities and Grounds Management Unit', 'Manages structure, finishes, utilities, mechanical, and carpentry repairs across BSU campus.', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('2', 'LEAU', 'Landscape and Environment Aesthetics Unit', 'Responsible for campus landscaping, janitorial services, lawn mowing, and disinfection operations.', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('3', 'SSU', 'Security Service Unit', 'Handles university security, campus safety coordination, and campus incident reporting.', '2026-07-23 21:21:51', '2026-07-23 21:21:51');


-- Table structure for table `users`
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('student','employee','admin','dispatcher','director','worker','superadmin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'student',
  `unit_id` int(11) unsigned DEFAULT NULL,
  `student_id_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_card_image` text COLLATE utf8mb4_unicode_ci,
  `avatar_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Pending','Rejected','Suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `is_verified` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_unit` (`unit_id`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`),
  CONSTRAINT `fk_users_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `users`
INSERT INTO `users` (`id`, `first_name`, `last_name`, `email`, `password_hash`, `contact_number`, `role`, `unit_id`, `student_id_number`, `id_card_image`, `avatar_path`, `status`, `is_verified`, `created_at`, `updated_at`) VALUES
('3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'End User', 'Test', 'enduser@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'student', NULL, NULL, NULL, NULL, 'Active', '1', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('4ad09d8a-975f-4ad9-8481-ff8b45781998', 'FGMU', 'Dispatcher', 'fgmu-dispatcher@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'dispatcher', '1', NULL, NULL, NULL, 'Active', '1', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('5322c820-a591-452a-be5c-cedb24b45f71', 'LEAU', 'Admin', 'leau-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', '2', NULL, NULL, NULL, 'Active', '1', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'FGMU', 'Admin', 'fgmu-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', '1', NULL, NULL, NULL, 'Active', '1', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('d4a8de41-bf10-495d-93e8-e2480d5d78be', 'LEAU', 'Dispatcher', 'leau-dispatcher@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'dispatcher', '2', NULL, NULL, NULL, 'Active', '1', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('e6f927fb-aec7-4ab9-85a9-9af83f1d4dc9', 'SSU', 'Admin', 'ssu-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', '3', NULL, NULL, NULL, 'Active', '1', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('f12d1cfd-a338-41ca-88de-b29ea8e71f33', 'GSO', 'Director', 'director@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'director', NULL, NULL, NULL, NULL, 'Active', '1', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', 'Super', 'Administrator', 'superadmin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', '09123456789', 'superadmin', NULL, NULL, NULL, NULL, 'Active', '1', '2026-07-23 21:21:51', '2026-07-23 21:21:51');




-- Table structure for table `notifications`
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `otp_codes`
DROP TABLE IF EXISTS `otp_codes`;
CREATE TABLE `otp_codes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_data` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ci_sessions`
DROP TABLE IF EXISTS `ci_sessions`;
CREATE TABLE `ci_sessions` (
  `id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` int(10) unsigned NOT NULL DEFAULT 0,
  `data` blob NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table structure for table `user_sessions`
DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_sessions_user` (`user_id`),
  KEY `idx_user_sessions_sid` (`session_id`),
  CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Table structure for table `role_permissions`
DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `feature_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_feature` (`role`,`feature_key`)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `role_permissions`
INSERT INTO `role_permissions` (`id`, `role`, `feature_key`, `is_enabled`, `created_at`, `updated_at`) VALUES
(1, 'superadmin', 'tickets.create', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(2, 'superadmin', 'tickets.view_all', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(3, 'superadmin', 'tickets.approve_decline', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(4, 'superadmin', 'tickets.dispatch', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(5, 'superadmin', 'tickets.assign_worker', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(6, 'superadmin', 'tickets.complete_work', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(7, 'superadmin', 'tickets.verify_close', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(8, 'superadmin', 'personnel.manage', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(9, 'superadmin', 'reports.view', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(10, 'superadmin', 'users.provision', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(11, 'superadmin', 'system.matrix_control', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(12, 'admin', 'tickets.create', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(13, 'admin', 'tickets.view_all', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(14, 'admin', 'tickets.approve_decline', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(15, 'admin', 'tickets.dispatch', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(16, 'admin', 'tickets.assign_worker', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(17, 'admin', 'tickets.complete_work', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(18, 'admin', 'tickets.verify_close', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(19, 'admin', 'personnel.manage', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(20, 'admin', 'reports.view', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(21, 'admin', 'users.provision', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(22, 'admin', 'system.matrix_control', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(23, 'dispatcher', 'tickets.create', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(24, 'dispatcher', 'tickets.view_all', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(25, 'dispatcher', 'tickets.approve_decline', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(26, 'dispatcher', 'tickets.dispatch', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(27, 'dispatcher', 'tickets.assign_worker', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(28, 'dispatcher', 'tickets.complete_work', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(29, 'dispatcher', 'tickets.verify_close', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(30, 'dispatcher', 'personnel.manage', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(31, 'dispatcher', 'reports.view', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(32, 'dispatcher', 'users.provision', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(33, 'dispatcher', 'system.matrix_control', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(34, 'director', 'tickets.create', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(35, 'director', 'tickets.view_all', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(36, 'director', 'tickets.approve_decline', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(37, 'director', 'tickets.dispatch', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(38, 'director', 'tickets.assign_worker', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(39, 'director', 'tickets.complete_work', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(40, 'director', 'tickets.verify_close', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(41, 'director', 'personnel.manage', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(42, 'director', 'reports.view', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(43, 'director', 'users.provision', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(44, 'director', 'system.matrix_control', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(45, 'worker', 'tickets.create', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(46, 'worker', 'tickets.view_all', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(47, 'worker', 'tickets.approve_decline', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(48, 'worker', 'tickets.dispatch', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(49, 'worker', 'tickets.assign_worker', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(50, 'worker', 'tickets.complete_work', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(51, 'worker', 'tickets.verify_close', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(52, 'worker', 'personnel.manage', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(53, 'worker', 'reports.view', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(54, 'worker', 'users.provision', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(55, 'worker', 'system.matrix_control', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(56, 'employee', 'tickets.create', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(57, 'employee', 'tickets.view_all', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(58, 'employee', 'tickets.approve_decline', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(59, 'employee', 'tickets.dispatch', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(60, 'employee', 'tickets.assign_worker', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(61, 'employee', 'tickets.complete_work', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(62, 'employee', 'tickets.verify_close', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(63, 'employee', 'personnel.manage', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(64, 'employee', 'reports.view', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(65, 'employee', 'users.provision', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(66, 'employee', 'system.matrix_control', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(67, 'student', 'tickets.create', 1, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(68, 'student', 'tickets.view_all', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(69, 'student', 'tickets.approve_decline', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(70, 'student', 'tickets.dispatch', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(71, 'student', 'tickets.assign_worker', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(72, 'student', 'tickets.complete_work', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(73, 'student', 'tickets.verify_close', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(74, 'student', 'personnel.manage', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(75, 'student', 'reports.view', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(76, 'student', 'users.provision', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00'),
(77, 'student', 'system.matrix_control', 0, '2026-09-08 00:00:00', '2026-09-08 00:00:00');

SET FOREIGN_KEY_CHECKS=1;
