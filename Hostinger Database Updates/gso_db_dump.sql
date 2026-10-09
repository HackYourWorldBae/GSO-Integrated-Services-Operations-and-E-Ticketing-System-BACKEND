-- ============================================================================
-- GSO Integrated Services Operations and E-Ticketing System
-- Production Database Dump & Schema Updater
-- Compatible with MySQL 8.0+ and MariaDB 10.4+
-- 
-- SAFE UPDATER GUARANTEE:
-- 1. NEVER drops existing tables holding application or alpha test data.
-- 2. Uses CREATE TABLE IF NOT EXISTS for all 33 system entities.
-- 3. Automatically detects and adds missing columns/indexes via sp_gso_upgrade_schema.
-- 4. Uses INSERT IGNORE & ON DUPLICATE KEY UPDATE to protect existing accounts & settings.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- PHASE 1: IDEMPOTENT LIVE SCHEMA MIGRATION / COLUMN PATCHER
-- ============================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `sp_gso_upgrade_schema` $$

CREATE PROCEDURE `sp_gso_upgrade_schema`()
BEGIN
    DECLARE current_db VARCHAR(128);
    SELECT DATABASE() INTO current_db;

    -- ------------------------------------------------------------------------
    -- 1. USERS TABLE UPGRADES
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'users') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'users' AND column_name = 'student_type') THEN
            ALTER TABLE `users` ADD COLUMN `student_type` VARCHAR(50) NULL AFTER `student_id_number`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'users' AND column_name = 'employee_type') THEN
            ALTER TABLE `users` ADD COLUMN `employee_type` VARCHAR(100) NULL AFTER `student_type`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'users' AND column_name = 'college') THEN
            ALTER TABLE `users` ADD COLUMN `college` VARCHAR(150) NULL AFTER `organization_name`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'users' AND column_name = 'id_selfie_image') THEN
            ALTER TABLE `users` ADD COLUMN `id_selfie_image` TEXT NULL AFTER `id_card_image`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'users' AND column_name = 'failed_login_attempts') THEN
            ALTER TABLE `users` ADD COLUMN `failed_login_attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_verified`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'users' AND column_name = 'lockout_until') THEN
            ALTER TABLE `users` ADD COLUMN `lockout_until` DATETIME NULL DEFAULT NULL AFTER `failed_login_attempts`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'users' AND column_name = 'last_login_at') THEN
            ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME NULL DEFAULT NULL AFTER `lockout_until`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = current_db AND table_name = 'users' AND index_name = 'idx_users_last_login') THEN
            ALTER TABLE `users` ADD INDEX `idx_users_last_login` (`last_login_at`);
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'users' AND column_name = 'email_notifications_enabled') THEN
            ALTER TABLE `users` ADD COLUMN `email_notifications_enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Per-account opt-in for ticket/request email updates' AFTER `is_verified`;
        END IF;

        -- Update Role ENUM to include 'staff' and 'worker'
        ALTER TABLE `users` MODIFY COLUMN `role` ENUM('student','employee','admin','staff','director','superadmin','worker') NOT NULL DEFAULT 'student';
        
        -- Update Status ENUM to standard system values including 'Archived' (Supports 6-month inactivity archiving)
        ALTER TABLE `users` MODIFY COLUMN `status` ENUM('Active','Pending','Rejected','Suspended','Archived') NOT NULL DEFAULT 'Active';
    END IF;

    -- ------------------------------------------------------------------------
    -- 2. PERSONNEL TABLE UPGRADES
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'personnel') THEN
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'personnel' AND column_name = 'contact_number') THEN
            ALTER TABLE `personnel` DROP COLUMN `contact_number`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'personnel' AND column_name = 'user_id') THEN
            ALTER TABLE `personnel` ADD COLUMN `user_id` VARCHAR(36) NULL DEFAULT NULL AFTER `unit_id`;
        END IF;

        -- Auto-heal and bind unlinked personnel records to existing worker accounts
        IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'users') THEN
            UPDATE `personnel` p
            INNER JOIN `users` u ON (
                (p.user_id IS NULL OR p.user_id = '')
                AND (
                    TRIM(LOWER(p.name)) = TRIM(LOWER(CONCAT(u.first_name, ' ', u.last_name)))
                    OR TRIM(LOWER(REPLACE(p.name, ' ', ''))) = TRIM(LOWER(REPLACE(CONCAT(u.first_name, u.last_name), ' ', '')))
                )
                AND u.role IN ('worker', 'personnel', 'employee', 'staff')
            )
            SET p.user_id = u.id;
        END IF;
    END IF;

    -- ------------------------------------------------------------------------
    -- 3. PERSONNEL CATEGORIES TABLE UPGRADES
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'personnel_categories') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'personnel_categories' AND column_name = 'supported_services') THEN
            ALTER TABLE `personnel_categories` ADD COLUMN `supported_services` TEXT NULL AFTER `is_system`;
        END IF;

        -- Ensure Janitor / Janitorial category belongs to FGMU (unit_id 1)
        UPDATE `personnel_categories` 
        SET `unit_id` = 1, `supported_services` = '["Cleaning/ Grubbing", "Disinfection"]' 
        WHERE `name` IN ('Janitor', 'Janitorial') AND `unit_id` = 2;

        -- Ensure Hauler / Hauling category belongs to FGMU (unit_id 1)
        UPDATE `personnel_categories` 
        SET `unit_id` = 1, `name` = 'Hauler / Logistics', `supported_services` = '["Hauling"]' 
        WHERE `name` IN ('Hauler', 'Hauler & Event Setup', 'Garbage Collector') AND `unit_id` = 2;
    END IF;

    -- Ensure Unit descriptions reflect current operational responsibilities
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'units') THEN
        UPDATE `units` SET `description` = 'Manages structure, finishes, utilities, mechanical, electrical, carpentry repairs, janitorial sanitation, disinfection, cleaning/grubbing, and hauling services across campus.' WHERE `code` = 'FGMU';
        UPDATE `units` SET `description` = 'Responsible for campus landscaping, grounds maintenance, mowing, trimming, plant care, event decoration, and equipment/plant borrowing services.' WHERE `code` = 'LEAU';
    END IF;

    -- ------------------------------------------------------------------------
    -- 4. TICKETS TABLE UPGRADES
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'tickets') THEN
        -- Recategorization fields
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'is_recategorized') THEN
            ALTER TABLE `tickets` ADD COLUMN `is_recategorized` TINYINT(1) NOT NULL DEFAULT 0 AFTER `service_type`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'original_service_type') THEN
            ALTER TABLE `tickets` ADD COLUMN `original_service_type` VARCHAR(150) DEFAULT NULL AFTER `is_recategorized`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'recategorized_at') THEN
            ALTER TABLE `tickets` ADD COLUMN `recategorized_at` DATETIME DEFAULT NULL AFTER `original_service_type`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'recategorized_by') THEN
            ALTER TABLE `tickets` ADD COLUMN `recategorized_by` VARCHAR(36) DEFAULT NULL AFTER `recategorized_at`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'recategorization_reason') THEN
            ALTER TABLE `tickets` ADD COLUMN `recategorization_reason` TEXT DEFAULT NULL AFTER `recategorized_by`;
        END IF;

        -- Emergency & approval delay fields
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'is_emergency') THEN
            ALTER TABLE `tickets` ADD COLUMN `is_emergency` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status_label`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'is_approval_delayed') THEN
            ALTER TABLE `tickets` ADD COLUMN `is_approval_delayed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_emergency`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'approval_delay_reason') THEN
            ALTER TABLE `tickets` ADD COLUMN `approval_delay_reason` TEXT DEFAULT NULL AFTER `is_approval_delayed`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'approval_delayed_at') THEN
            ALTER TABLE `tickets` ADD COLUMN `approval_delayed_at` DATETIME DEFAULT NULL AFTER `approval_delay_reason`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'approval_delayed_by') THEN
            ALTER TABLE `tickets` ADD COLUMN `approval_delayed_by` VARCHAR(36) DEFAULT NULL AFTER `approval_delayed_at`;
        END IF;

        -- Director Escalation fields (Unit Head direct approval vs Director escalation)
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'is_escalated_to_director') THEN
            ALTER TABLE `tickets` ADD COLUMN `is_escalated_to_director` TINYINT(1) NOT NULL DEFAULT 0 AFTER `approval_delayed_by`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'escalation_reason') THEN
            ALTER TABLE `tickets` ADD COLUMN `escalation_reason` TEXT DEFAULT NULL AFTER `is_escalated_to_director`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'escalated_at') THEN
            ALTER TABLE `tickets` ADD COLUMN `escalated_at` DATETIME DEFAULT NULL AFTER `escalation_reason`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'escalated_by') THEN
            ALTER TABLE `tickets` ADD COLUMN `escalated_by` VARCHAR(36) DEFAULT NULL AFTER `escalated_at`;
        END IF;

        -- Materials & Labor Only fields
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'is_labor_only') THEN
            ALTER TABLE `tickets` ADD COLUMN `is_labor_only` TINYINT(1) NOT NULL DEFAULT 0 AFTER `materials_logged`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'materials_stage') THEN
            ALTER TABLE `tickets` ADD COLUMN `materials_stage` VARCHAR(20) NOT NULL DEFAULT 'none' AFTER `is_labor_only`;
        END IF;

        -- SSU investigation fields
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'is_under_investigation') THEN
            ALTER TABLE `tickets` ADD COLUMN `is_under_investigation` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'SSU only: 1 when flagged for active investigation' AFTER `materials_stage`;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'tickets' AND column_name = 'ssu_notation') THEN
            ALTER TABLE `tickets` ADD COLUMN `ssu_notation` TEXT DEFAULT NULL COMMENT 'SSU only: staff recommendation/notation communicated to reporter' AFTER `is_under_investigation`;
        END IF;

        -- Indexes
        IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = current_db AND table_name = 'tickets' AND index_name = 'idx_tickets_approval_delayed') THEN
            ALTER TABLE `tickets` ADD INDEX `idx_tickets_approval_delayed` (`is_approval_delayed`);
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = current_db AND table_name = 'tickets' AND index_name = 'idx_tickets_escalated') THEN
            ALTER TABLE `tickets` ADD INDEX `idx_tickets_escalated` (`is_escalated_to_director`);
        END IF;
    END IF;

    -- ------------------------------------------------------------------------
    -- 5. SSU LOOKUPS COMPATIBILITY PATCH (Handles legacy 'name' column if present)
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'ssu_incident_types') THEN
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_types' AND column_name = 'name') 
           AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_types' AND column_name = 'type_name') THEN
            ALTER TABLE `ssu_incident_types` CHANGE COLUMN `name` `type_name` VARCHAR(150) NOT NULL;
        END IF;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'ssu_incident_issues') THEN
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_issues' AND column_name = 'name') 
           AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_issues' AND column_name = 'issue_name') THEN
            ALTER TABLE `ssu_incident_issues` CHANGE COLUMN `name` `issue_name` VARCHAR(150) NOT NULL;
        END IF;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'ssu_incident_roles') THEN
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_roles' AND column_name = 'name') 
           AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_roles' AND column_name = 'role_name') THEN
            ALTER TABLE `ssu_incident_roles` CHANGE COLUMN `name` `role_name` VARCHAR(150) NOT NULL;
        END IF;
    END IF;

    -- ------------------------------------------------------------------------
    -- 6. TICKET FEEDBACKS & DELAY REASONS UPGRADES
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'ticket_feedbacks') THEN
        ALTER TABLE `ticket_feedbacks` 
        MODIFY COLUMN `completion_status` ENUM('early', 'on-time', 'beyond-time', 'not-completed') NOT NULL DEFAULT 'on-time';
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'feedback_delay_reasons') THEN
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'feedback_delay_reasons' AND column_name = 'name') 
           AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'feedback_delay_reasons' AND column_name = 'reason_code') THEN
            ALTER TABLE `feedback_delay_reasons` CHANGE COLUMN `name` `reason_code` VARCHAR(60) NOT NULL;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'feedback_delay_reasons' AND column_name = 'reason_label') THEN
            ALTER TABLE `feedback_delay_reasons` ADD COLUMN `reason_label` VARCHAR(200) NOT NULL DEFAULT '' AFTER `reason_code`;
        END IF;
    END IF;

    -- ------------------------------------------------------------------------
    -- 7. UNIT TICKET DETAILS UPGRADES
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'fgmu_ticket_details') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'fgmu_ticket_details' AND column_name = 'college_building') THEN
            ALTER TABLE `fgmu_ticket_details` ADD COLUMN `college_building` VARCHAR(255) NULL AFTER `ticket_id`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'fgmu_ticket_details' AND column_name = 'office_room') THEN
            ALTER TABLE `fgmu_ticket_details` ADD COLUMN `office_room` VARCHAR(100) NULL AFTER `college_building`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'fgmu_ticket_details' AND column_name = 'source_of_fund') THEN
            ALTER TABLE `fgmu_ticket_details` ADD COLUMN `source_of_fund` VARCHAR(150) NULL DEFAULT NULL AFTER `office_room`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'fgmu_ticket_details' AND column_name = 'jr_no') THEN
            ALTER TABLE `fgmu_ticket_details` ADD COLUMN `jr_no` VARCHAR(60) NULL DEFAULT NULL AFTER `source_of_fund`;
        END IF;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'leau_ticket_details') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'leau_ticket_details' AND column_name = 'college_building') THEN
            ALTER TABLE `leau_ticket_details` ADD COLUMN `college_building` VARCHAR(255) NULL AFTER `ticket_id`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'leau_ticket_details' AND column_name = 'office_room') THEN
            ALTER TABLE `leau_ticket_details` ADD COLUMN `office_room` VARCHAR(100) NULL AFTER `college_building`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'leau_ticket_details' AND column_name = 'source_of_fund') THEN
            ALTER TABLE `leau_ticket_details` ADD COLUMN `source_of_fund` VARCHAR(150) NULL DEFAULT NULL AFTER `office_room`;
        END IF;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'ssu_incident_details') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_details' AND column_name = 'where_occurred') THEN
            ALTER TABLE `ssu_incident_details` ADD COLUMN `where_occurred` TEXT NULL AFTER `ticket_id`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_details' AND column_name = 'when_occurred') THEN
            ALTER TABLE `ssu_incident_details` ADD COLUMN `when_occurred` VARCHAR(150) NULL AFTER `where_occurred`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_details' AND column_name = 'how_narrative') THEN
            ALTER TABLE `ssu_incident_details` ADD COLUMN `how_narrative` TEXT NULL AFTER `when_occurred`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_details' AND column_name = 'reporter_name') THEN
            ALTER TABLE `ssu_incident_details` ADD COLUMN `reporter_name` VARCHAR(255) NULL AFTER `how_narrative`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_details' AND column_name = 'reporter_signature') THEN
            ALTER TABLE `ssu_incident_details` ADD COLUMN `reporter_signature` LONGTEXT NULL AFTER `reporter_name`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_details' AND column_name = 'other_incident') THEN
            ALTER TABLE `ssu_incident_details` ADD COLUMN `other_incident` TEXT NULL AFTER `reporter_signature`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_details' AND column_name = 'other_information') THEN
            ALTER TABLE `ssu_incident_details` ADD COLUMN `other_information` TEXT NULL AFTER `other_incident`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_details' AND column_name = 'follow_up') THEN
            ALTER TABLE `ssu_incident_details` ADD COLUMN `follow_up` TINYINT(1) NOT NULL DEFAULT 0 AFTER `other_information`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ssu_incident_details' AND column_name = 'who_involved') THEN
            ALTER TABLE `ssu_incident_details` ADD COLUMN `who_involved` TEXT NULL AFTER `follow_up`;
        END IF;
    END IF;

    -- ------------------------------------------------------------------------
    -- 8. TICKET ATTACHMENTS UPGRADES
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'ticket_attachments') THEN
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ticket_attachments' AND column_name = 'created_at')
           AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ticket_attachments' AND column_name = 'uploaded_at') THEN
            ALTER TABLE `ticket_attachments` CHANGE COLUMN `created_at` `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ticket_attachments' AND column_name = 'is_encrypted') THEN
            ALTER TABLE `ticket_attachments` ADD COLUMN `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `file_size_bytes`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ticket_attachments' AND column_name = 'encryption_iv') THEN
            ALTER TABLE `ticket_attachments` ADD COLUMN `encryption_iv` VARCHAR(64) NULL DEFAULT NULL AFTER `is_encrypted`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ticket_attachments' AND column_name = 'encryption_tag') THEN
            ALTER TABLE `ticket_attachments` ADD COLUMN `encryption_tag` VARCHAR(64) NULL DEFAULT NULL AFTER `encryption_iv`;
        END IF;
    END IF;

    -- ------------------------------------------------------------------------
    -- 9. TICKET MATERIALS UPGRADES
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'ticket_materials') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'ticket_materials' AND column_name = 'stage') THEN
            ALTER TABLE `ticket_materials` ADD COLUMN `stage` VARCHAR(20) NOT NULL DEFAULT 'assessment' AFTER `total_price`;
        END IF;
    END IF;

    -- ------------------------------------------------------------------------
    -- 10. NOTIFICATIONS & OTP UPGRADES
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'notifications') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'notifications' AND column_name = 'type') THEN
            ALTER TABLE `notifications` ADD COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'info' AFTER `user_id`;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'notifications' AND column_name = 'updated_at') THEN
            ALTER TABLE `notifications` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
        END IF;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'otp_codes') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'otp_codes' AND column_name = 'user_data') THEN
            ALTER TABLE `otp_codes` ADD COLUMN `user_data` TEXT NULL AFTER `created_at`;
        END IF;
    END IF;

    -- ------------------------------------------------------------------------
    -- 11. SYSTEM SETTINGS COMPATIBILITY PATCH (Handles 'setting_key' vs 'key')
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'system_settings') THEN
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'system_settings' AND column_name = 'setting_key') 
           AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'system_settings' AND column_name = 'key') THEN
            ALTER TABLE `system_settings` CHANGE COLUMN `setting_key` `key` VARCHAR(100) NOT NULL;
        END IF;
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'system_settings' AND column_name = 'setting_value') 
           AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'system_settings' AND column_name = 'value') THEN
            ALTER TABLE `system_settings` CHANGE COLUMN `setting_value` `value` TEXT DEFAULT NULL;
        END IF;
    END IF;

    -- ------------------------------------------------------------------------
    -- 12. TICKET ASSIGNMENTS UPGRADES & RE-LINKING
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'ticket_assignments')
       AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'personnel') THEN
        -- Re-link any assignments stored with a user_id to point to canonical personnel.id
        UPDATE `ticket_assignments` ta
        INNER JOIN `personnel` p ON ta.personnel_id = p.user_id
        SET ta.personnel_id = p.id;
    END IF;

    -- ------------------------------------------------------------------------
    -- 13. USER SESSIONS TABLE UPGRADES (Single Active Session & Real-Time Presence)
    -- ------------------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_db AND table_name = 'user_sessions') THEN
        -- Safely migrate legacy token_id to session_id if present
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'user_sessions' AND column_name = 'token_id')
           AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'user_sessions' AND column_name = 'session_id') THEN
            ALTER TABLE `user_sessions` CHANGE COLUMN `token_id` `session_id` VARCHAR(64) NOT NULL;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'user_sessions' AND column_name = 'session_id') THEN
            ALTER TABLE `user_sessions` ADD COLUMN `session_id` VARCHAR(64) NOT NULL AFTER `user_id`;
        END IF;

        -- Safely migrate legacy last_active to last_activity if present
        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'user_sessions' AND column_name = 'last_active')
           AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'user_sessions' AND column_name = 'last_activity') THEN
            ALTER TABLE `user_sessions` CHANGE COLUMN `last_active` `last_activity` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_db AND table_name = 'user_sessions' AND column_name = 'last_activity') THEN
            ALTER TABLE `user_sessions` ADD COLUMN `last_activity` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
        END IF;

        -- Ensure user_sessions.id is VARCHAR(36) to support UUIDs
        ALTER TABLE `user_sessions` MODIFY COLUMN `id` VARCHAR(36) NOT NULL;

        -- Enforce UNIQUE on user_id (One Active Session Per User)
        IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = current_db AND table_name = 'user_sessions' AND index_name = 'uq_user_sessions_user') THEN
            -- Deduplicate existing rows keeping newest prior to adding UNIQUE constraint
            DELETE s1 FROM `user_sessions` s1
            INNER JOIN `user_sessions` s2 
            WHERE s1.user_id = s2.user_id AND s1.created_at < s2.created_at;
            ALTER TABLE `user_sessions` ADD UNIQUE KEY `uq_user_sessions_user` (`user_id`);
        END IF;

        -- Index on session_id for fast JWT sid validation
        IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = current_db AND table_name = 'user_sessions' AND index_name = 'idx_user_sessions_sid') THEN
            ALTER TABLE `user_sessions` ADD INDEX `idx_user_sessions_sid` (`session_id`);
        END IF;
    END IF;

    -- ------------------------------------------------------------------------
    -- 14. DROP DEPRECATED TABLES (Safe - only obsolete session/RBAC tables)
    -- ------------------------------------------------------------------------
    DROP TABLE IF EXISTS `ci_sessions`;
    DROP TABLE IF EXISTS `role_permissions`;

END $$

DELIMITER ;

CALL `sp_gso_upgrade_schema`();
DROP PROCEDURE IF EXISTS `sp_gso_upgrade_schema`;

-- ============================================================================
-- PHASE 2: TABLE CREATIONS (CREATE TABLE IF NOT EXISTS)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. CORE & REFERENCE ENTITIES
-- ----------------------------------------------------------------------------

-- Table structure for table `units`
CREATE TABLE IF NOT EXISTS `units` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL UNIQUE,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `units` (`id`, `code`, `name`, `description`) VALUES
(1, 'FGMU', 'Facilities and Grounds Management Unit', 'Manages structure, finishes, utilities, mechanical, electrical, carpentry repairs, janitorial sanitation, disinfection, cleaning/grubbing, and hauling services across campus.'),
(2, 'LEAU', 'Landscape and Environment Aesthetics Unit', 'Responsible for campus landscaping, grounds maintenance, mowing, trimming, plant care, event decoration, and equipment/plant borrowing services.'),
(3, 'SSU', 'Security Service Unit', 'Coordinates campus security personnel, incident response, investigation, and physical campus safety.');

-- Table structure for table `users`
CREATE TABLE IF NOT EXISTS `users` (
  `id` varchar(36) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` text NOT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `role` enum('student','employee','admin','staff','director','superadmin','worker') NOT NULL DEFAULT 'student',
  `unit_id` int(11) UNSIGNED DEFAULT NULL,
  `student_id_number` varchar(50) DEFAULT NULL,
  `student_type` varchar(50) DEFAULT NULL,
  `employee_type` varchar(100) DEFAULT NULL,
  `organization_name` varchar(150) DEFAULT NULL,
  `college` varchar(150) DEFAULT NULL,
  `id_card_image` text DEFAULT NULL,
  `id_selfie_image` text DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `status` enum('Active','Pending','Rejected','Suspended','Archived') NOT NULL DEFAULT 'Active',
  `is_verified` tinyint(1) NOT NULL DEFAULT 1,
  `email_notifications_enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Per-account opt-in for ticket/request email updates (SSU alerts, dispatch, etc.)',
  `failed_login_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `lockout_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_unit` (`unit_id`),
  KEY `idx_users_last_login` (`last_login_at`),
  CONSTRAINT `fk_users_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Institutional Seed Users (Default password: 'password') - INSERT IGNORE preserves existing user accounts
INSERT IGNORE INTO `users` (`id`, `first_name`, `last_name`, `email`, `password_hash`, `contact_number`, `role`, `unit_id`, `student_id_number`, `student_type`, `employee_type`, `organization_name`, `college`, `id_card_image`, `avatar_path`, `status`, `is_verified`, `failed_login_attempts`, `lockout_until`) VALUES
('a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', 'Super', 'Administrator', 'superadmin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', '09123456789', 'superadmin', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', 1, 0, NULL),
('f12d1cfd-a338-41ca-88de-b29ea8e71f33', 'GSO', 'Director', 'director@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'director', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', 1, 0, NULL),
('cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'FGMU', 'Admin', 'fgmu-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', 1, 0, NULL),
('5322c820-a591-452a-be5c-cedb24b45f71', 'LEAU', 'Admin', 'leau-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', 2, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', 1, 0, NULL),
('e6f927fb-aec7-4ab9-85a9-9af83f1d4dc9', 'SSU', 'Admin', 'ssu-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', 1, 0, NULL),
('3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'University', 'Requestor', 'enduser@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', '09171234567', 'employee', NULL, 'EMP-2301', NULL, 'Teaching Staff', NULL, 'College of Information Sciences (CIS)', NULL, NULL, 'Active', 1, 0, NULL);

-- Table structure for table `personnel`
CREATE TABLE IF NOT EXISTS `personnel` (
  `id` varchar(36) NOT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `unit_id` int(11) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `specialty` varchar(100) NOT NULL,
  `status` enum('available','working','on_leave') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_personnel_user` (`user_id`),
  KEY `idx_personnel_unit_status` (`unit_id`,`status`),
  CONSTRAINT `fk_personnel_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `personnel_categories`
CREATE TABLE IF NOT EXISTS `personnel_categories` (
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

INSERT IGNORE INTO `personnel_categories` (`id`, `unit_id`, `name`, `is_system`, `supported_services`) VALUES
(1, 1, 'Plumber', 1, '["Plumbing & Sanitary Works"]'),
(2, 1, 'Electrician', 1, '["Electrical Work", "Electronics & Communication Works"]'),
(3, 1, 'Carpenter', 1, '["Carpentry & Joinery"]'),
(4, 1, 'Mason', 1, '["Masonry Works", "Concrete Works"]'),
(5, 1, 'Painter', 1, '["Painting Works"]'),
(6, 1, 'Welder', 1, '["Welding & Tinsmith Works"]'),
(7, 1, 'Refrigeration & Aircon Technician', 1, '["Mechanical Works"]'),
(8, 2, 'Landscaper', 1, '["Planting/ Landscaping", "Borrowing of plants"]'),
(9, 2, 'Groundskeeper', 1, '["Mowing/ Weeding", "Pruning/ Cutting"]'),
(10, 1, 'Janitor', 1, '["Cleaning/ Grubbing", "Disinfection"]'),
(11, 1, 'Hauler / Logistics', 1, '["Hauling"]'),
(12, 2, 'Stage & Hall Decorator', 1, '["Stage & Hall Decoration"]'),
(13, 2, 'Tool & Equipment Custodian', 1, '["Borrowing of tools/ equipment", "Borrowing of plants"]');

-- ----------------------------------------------------------------------------
-- 2. BORROWING SYSTEM TABLES (LEAU)
-- ----------------------------------------------------------------------------

-- Table structure for table `inventory_items`
CREATE TABLE IF NOT EXISTS `inventory_items` (
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
CREATE TABLE IF NOT EXISTS `borrowing_requests` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) NOT NULL,
  `borrower_name` varchar(255) NOT NULL,
  `borrower_type` enum('student','employee','unit_staff','external') NOT NULL DEFAULT 'student',
  `contact_number` varchar(50) DEFAULT NULL,
  `borrow_date` date NOT NULL,
  `return_due_date` date NOT NULL,
  `purpose` text NOT NULL,
  `item_id` int(11) UNSIGNED DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `quantity_requested` int(11) UNSIGNED NOT NULL DEFAULT 1,
  `status` enum('pending_director','approved_director','declined_director','inventory_assigned','ready_for_pickup','picked_up','returned','overdue','cancelled') NOT NULL DEFAULT 'pending_director',
  `released_at` datetime DEFAULT NULL,
  `released_by` varchar(36) DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `received_by` varchar(36) DEFAULT NULL,
  `condition_on_return` enum('excellent','good','fair','damaged','lost') DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_borrowing_ticket` (`ticket_id`),
  KEY `idx_borrowing_item` (`item_id`),
  KEY `idx_borrowing_status` (`status`),
  CONSTRAINT `fk_borrowing_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `borrowing_attachments`
CREATE TABLE IF NOT EXISTS `borrowing_attachments` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` int(11) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size_bytes` int(11) UNSIGNED DEFAULT NULL,
  `stage` enum('request','release','return') NOT NULL DEFAULT 'request',
  `uploaded_by` varchar(36) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_b_att_request` (`request_id`),
  CONSTRAINT `fk_b_att_request` FOREIGN KEY (`request_id`) REFERENCES `borrowing_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `borrowing_history`
CREATE TABLE IF NOT EXISTS `borrowing_history` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` int(11) UNSIGNED NOT NULL,
  `actor_id` varchar(36) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_b_hist_request` (`request_id`),
  CONSTRAINT `fk_b_hist_request` FOREIGN KEY (`request_id`) REFERENCES `borrowing_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. TICKETING ENGINE & ATTACHMENTS
-- ----------------------------------------------------------------------------

-- Table structure for table `tickets`
CREATE TABLE IF NOT EXISTS `tickets` (
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
  `is_escalated_to_director` tinyint(1) NOT NULL DEFAULT 0,
  `escalation_reason` text DEFAULT NULL,
  `escalated_at` datetime DEFAULT NULL,
  `escalated_by` varchar(36) DEFAULT NULL,
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
  KEY `idx_tickets_escalated` (`is_escalated_to_director`),
  KEY `idx_tickets_submitted` (`submitted_at`),
  CONSTRAINT `fk_tickets_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tickets_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tickets_verified_by` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_delayed_by` FOREIGN KEY (`approval_delayed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_escalated_by` FOREIGN KEY (`escalated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ticket_attachments`
CREATE TABLE IF NOT EXISTS `ticket_attachments` (
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

-- Table structure for table `fgmu_ticket_details`
CREATE TABLE IF NOT EXISTS `fgmu_ticket_details` (
  `ticket_id` varchar(60) NOT NULL,
  `college_building` varchar(255) NOT NULL,
  `office_room` varchar(100) NOT NULL,
  `source_of_fund` varchar(150) DEFAULT NULL,
  `jr_no` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  CONSTRAINT `fk_fgmu_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `leau_ticket_details`
CREATE TABLE IF NOT EXISTS `leau_ticket_details` (
  `ticket_id` varchar(60) NOT NULL,
  `college_building` varchar(255) NOT NULL,
  `office_room` varchar(100) NOT NULL,
  `source_of_fund` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  CONSTRAINT `fk_leau_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ssu_incident_details`
CREATE TABLE IF NOT EXISTS `ssu_incident_details` (
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
  CONSTRAINT `fk_ssu_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- SSU Lookups and Bridge Tables (Exact schema matching CI4 migrations)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ssu_incident_types` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type_name` varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ssu_incident_type_name` (`type_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ssu_incident_types` (`id`, `type_name`) VALUES
(1, 'Theft / Robbery'),
(2, 'Vandalism / Property Damage'),
(3, 'Physical Assault / Altercation'),
(4, 'Trespassing / Unauthorized Entry'),
(5, 'Road Accident / Vehicular Collision'),
(6, 'Medical Emergency / Injury'),
(7, 'Fire / Hazard Alert'),
(8, 'Other Security Concern');

CREATE TABLE IF NOT EXISTS `ssu_incident_type_items` (
  `ticket_id` varchar(60) NOT NULL,
  `incident_type_id` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`ticket_id`, `incident_type_id`),
  KEY `idx_ssu_iti_type` (`incident_type_id`),
  CONSTRAINT `fk_ssu_iti_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ssu_iti_type` FOREIGN KEY (`incident_type_id`) REFERENCES `ssu_incident_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ssu_incident_issues` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue_name` varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ssu_incident_issue_name` (`issue_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ssu_incident_issues` (`id`, `issue_name`) VALUES
(1, 'Lost / Stolen Personal Belongings'),
(2, 'Damaged University Facilities / Equipment'),
(3, 'Safety Policy Violation'),
(4, 'Traffic Regulation Violation'),
(5, 'Suspicious Activity Observed');

CREATE TABLE IF NOT EXISTS `ssu_incident_issue_items` (
  `ticket_id` varchar(60) NOT NULL,
  `issue_id` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`ticket_id`, `issue_id`),
  KEY `idx_ssu_iii_issue` (`issue_id`),
  CONSTRAINT `fk_ssu_iii_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ssu_iii_issue` FOREIGN KEY (`issue_id`) REFERENCES `ssu_incident_issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ssu_incident_roles` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_name` varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ssu_incident_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ssu_incident_roles` (`id`, `role_name`) VALUES
(1, 'Victim / Complainant'),
(2, 'Eyewitness'),
(3, 'Security Officer on Duty'),
(4, 'Responding Personnel');

CREATE TABLE IF NOT EXISTS `ssu_incident_role_items` (
  `ticket_id` varchar(60) NOT NULL,
  `role_id` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`ticket_id`, `role_id`),
  KEY `idx_ssu_iri_role` (`role_id`),
  CONSTRAINT `fk_ssu_iri_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ssu_iri_role` FOREIGN KEY (`role_id`) REFERENCES `ssu_incident_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. DISPATCHING, ASSIGNMENTS & MATERIALS
-- ----------------------------------------------------------------------------

-- Table structure for table `ticket_collaborations`
CREATE TABLE IF NOT EXISTS `ticket_collaborations` (
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

-- Table structure for table `personnel`
CREATE TABLE IF NOT EXISTS `personnel` (
  `id` varchar(36) NOT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `unit_id` int(11) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `specialty` varchar(100) NOT NULL,
  `status` enum('available','working','on_leave') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_personnel_user` (`user_id`),
  KEY `idx_personnel_unit_status` (`unit_id`,`status`),
  CONSTRAINT `fk_personnel_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ticket_assignments`
CREATE TABLE IF NOT EXISTS `ticket_assignments` (
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
CREATE TABLE IF NOT EXISTS `ticket_materials` (
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
-- 5. FEEDBACK, QUALITY ASSURANCE & AUDIT LOGS
-- ----------------------------------------------------------------------------

-- Table structure for table `ticket_feedbacks`
CREATE TABLE IF NOT EXISTS `ticket_feedbacks` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `completion_status` enum('early','on-time','beyond-time','not-completed') NOT NULL DEFAULT 'on-time',
  `courtesy_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `quality_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `efficiency_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `timeliness_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `cleanliness_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_feedback_ticket` (`ticket_id`),
  KEY `idx_feedbacks_user` (`user_id`),
  CONSTRAINT `fk_feedback_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `feedback_delay_reasons`
CREATE TABLE IF NOT EXISTS `feedback_delay_reasons` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reason_code` varchar(60) NOT NULL,
  `reason_label` varchar(200) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delay_reason_code` (`reason_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `feedback_delay_reasons` (`id`, `reason_code`, `reason_label`) VALUES
(1, 'personnelAbsent', 'Assigned personnel was absent or unavailable'),
(2, 'extendedBreak', 'Personnel took extended breaks during the repair/task'),
(3, 'additionalWork', 'Unexpected additional work or complications arose'),
(4, 'lackDays', 'Insufficient number of days allotted for the job scope'),
(5, 'lackMaterials', 'Delay due to lack of replacement parts or materials'),
(6, 'lackSkills', 'Required specialized tools or external expertise');

-- Table structure for table `ticket_feedback_delay_items`
CREATE TABLE IF NOT EXISTS `ticket_feedback_delay_items` (
  `feedback_id` int(11) UNSIGNED NOT NULL,
  `delay_reason_id` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`feedback_id`, `delay_reason_id`),
  KEY `idx_tfdi_reason` (`delay_reason_id`),
  CONSTRAINT `fk_tfdi_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `ticket_feedbacks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tfdi_reason` FOREIGN KEY (`delay_reason_id`) REFERENCES `feedback_delay_reasons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ticket_logs`
CREATE TABLE IF NOT EXISTS `ticket_logs` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` varchar(60) NOT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_logs_ticket` (`ticket_id`),
  KEY `idx_logs_user` (`user_id`),
  CONSTRAINT `fk_logs_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `account_activity_logs`
CREATE TABLE IF NOT EXISTS `account_activity_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` varchar(36) DEFAULT NULL,
  `target_user_id` varchar(36) DEFAULT NULL,
  `event_type` varchar(64) NOT NULL,
  `severity` enum('info','notice','warning','critical') NOT NULL DEFAULT 'info',
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `device_summary` varchar(128) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_aal_actor` (`actor_id`),
  KEY `idx_aal_target` (`target_user_id`),
  KEY `idx_aal_event` (`event_type`),
  KEY `idx_aal_created` (`created_at`),
  CONSTRAINT `fk_aal_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_aal_target` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. SYSTEM, AUTHENTICATION & SECURITY
-- ----------------------------------------------------------------------------

-- Table structure for table `user_sessions`
CREATE TABLE IF NOT EXISTS `user_sessions` (
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
  CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `notifications`
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` varchar(36) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `otp_codes`
CREATE TABLE IF NOT EXISTS `otp_codes` (
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
CREATE TABLE IF NOT EXISTS `system_settings` (
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed system configuration defaults - ON DUPLICATE KEY UPDATE protects live configured values
INSERT INTO `system_settings` (`key`, `value`, `description`) VALUES
('jwt_access_ttl', '3600', 'Access token lifetime in seconds (default: 1 hour)'),
('jwt_refresh_ttl', '604800', 'Refresh token lifetime in seconds (default: 7 days)'),
('resend_api_key', '', 'API Key for Resend email notification service'),
('resend_from_email', 'GSO E-Ticketing <onboarding@resend.dev>', 'Sender email address for outgoing system emails'),
('resend_notifications_enabled', '1', 'Global toggle for email notification dispatch (1 = active, 0 = paused)'),
('google_drive_folder_id', NULL, 'Target Google Drive folder ID for cloud database backups'),
('google_drive_credentials_json', NULL, 'Google Cloud Service Account JSON credentials')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Table structure for table `password_resets`
CREATE TABLE IF NOT EXISTS `password_resets` (
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
CREATE TABLE IF NOT EXISTS `system_backups` (
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

-- ============================================================================
-- COMPLETION
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
