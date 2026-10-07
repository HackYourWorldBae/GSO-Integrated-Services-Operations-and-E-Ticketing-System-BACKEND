-- Add director escalation tracking columns to tickets table
-- Supports approval process overhaul (Unit Head direct approval vs Director escalation)
ALTER TABLE `tickets`
ADD COLUMN IF NOT EXISTS `is_escalated_to_director` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_approval_delayed`,
ADD COLUMN IF NOT EXISTS `escalation_reason` TEXT DEFAULT NULL AFTER `is_escalated_to_director`,
ADD COLUMN IF NOT EXISTS `escalated_at` DATETIME DEFAULT NULL AFTER `escalation_reason`,
ADD COLUMN IF NOT EXISTS `escalated_by` VARCHAR(36) DEFAULT NULL AFTER `escalated_at`;
