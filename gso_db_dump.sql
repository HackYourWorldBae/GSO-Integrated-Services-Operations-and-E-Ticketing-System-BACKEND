-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Sep 11, 2026 at 02:18 PM
-- Server version: 11.8.9-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u661776288_GSO_Backend`
--

-- --------------------------------------------------------

--
-- Table structure for table `ci_sessions`
--

CREATE TABLE `ci_sessions` (
  `id` varchar(128) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `timestamp` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `data` blob NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback_delay_reasons`
--

CREATE TABLE `feedback_delay_reasons` (
  `id` int(11) UNSIGNED NOT NULL,
  `reason_code` varchar(60) NOT NULL,
  `reason_label` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `feedback_delay_reasons`
--

INSERT INTO `feedback_delay_reasons` (`id`, `reason_code`, `reason_label`) VALUES
(1, 'personnelAbsent', 'Assigned personnel was absent or unavailable'),
(2, 'extendedBreak', 'Personnel took extended breaks during the repair/task'),
(3, 'additionalWork', 'Unexpected additional work or complications arose'),
(4, 'lackDays', 'Insufficient number of days allotted for the job scope'),
(5, 'lackMaterials', 'Delay due to lack of replacement parts or materials'),
(6, 'lackSkills', 'Required specialized tools or external expertise');

-- --------------------------------------------------------

--
-- Table structure for table `fgmu_ticket_details`
--

CREATE TABLE `fgmu_ticket_details` (
  `ticket_id` varchar(60) NOT NULL,
  `college_building` varchar(255) NOT NULL,
  `office_room` varchar(100) NOT NULL,
  `source_of_fund` varchar(150) DEFAULT NULL,
  `jr_no` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fgmu_ticket_details`
--

INSERT INTO `fgmu_ticket_details` (`ticket_id`, `college_building`, `office_room`, `source_of_fund`, `jr_no`) VALUES
('FGMU-TIC-1-2026', 'College of Agriculture (CA)', 'CA Faculty Room', '', NULL),
('FGMU-TIC-2-2026', 'College of Agriculture (CA)', 'CA Faculty Room', '', NULL),
('FGMU-TIC-3-2026', 'College of Engineering Complex (CE)', 'Engineering Computer Lab', '', NULL),
('FGMU-TIC-4-2026', 'College of Engineering Complex (CE)', 'Engineering Computer Lab', '', NULL),
('FGMU-TIC-5-2026', 'College of Engineering Complex (CE)', 'Engineering Computer Lab', '', NULL),
('FGMU-TIC-6-2026', 'College of Engineering Complex (CE)', 'Dean\'s Office', '', NULL),
('FGMU-TIC-7-2026', 'College of Agriculture (CA)', 'Agri-Science Laboratory', '', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `leau_ticket_details`
--

CREATE TABLE `leau_ticket_details` (
  `ticket_id` varchar(60) NOT NULL,
  `college_building` varchar(255) NOT NULL,
  `office_room` varchar(100) NOT NULL,
  `source_of_fund` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leau_ticket_details`
--

INSERT INTO `leau_ticket_details` (`ticket_id`, `college_building`, `office_room`, `source_of_fund`) VALUES
('LEAU-TIC-1-2026', 'College of Agriculture (CA)', 'CA Faculty Room', ''),
('LEAU-TIC-2-2026', 'College of Engineering Complex (CE)', 'Engineering Computer Lab', ''),
('LEAU-TIC-3-2026', 'College of Engineering Complex (CE)', 'Dean\'s Office', '');

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `version` varchar(255) NOT NULL,
  `class` varchar(255) NOT NULL,
  `group` varchar(255) NOT NULL,
  `namespace` varchar(255) NOT NULL,
  `time` int(11) NOT NULL,
  `batch` int(11) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `version`, `class`, `group`, `namespace`, `time`, `batch`) VALUES
(1, '2026_07_17_000001', 'App\\Database\\Migrations\\CreateUnitsTable', 'default', 'App', 1784812908, 1),
(2, '2026_07_17_000002', 'App\\Database\\Migrations\\CreateUsersTable', 'default', 'App', 1784812909, 1),
(3, '2026_07_17_000003', 'App\\Database\\Migrations\\CreatePersonnelTable', 'default', 'App', 1784812909, 1),
(4, '2026_07_17_000005', 'App\\Database\\Migrations\\CreateTicketsTable', 'default', 'App', 1784812909, 1),
(5, '2026_07_17_000006', 'App\\Database\\Migrations\\CreateTicketAttachmentsTable', 'default', 'App', 1784812909, 1),
(6, '2026_07_17_000007', 'App\\Database\\Migrations\\CreateUnitTicketDetailTables', 'default', 'App', 1784812909, 1),
(7, '2026_07_17_000008', 'App\\Database\\Migrations\\CreateSsuLookupAndBridgeTables', 'default', 'App', 1784812910, 1),
(8, '2026_07_17_000009', 'App\\Database\\Migrations\\CreateTicketAssignmentsAndMaterialsTables', 'default', 'App', 1784812910, 1),
(9, '2026_07_17_000010', 'App\\Database\\Migrations\\CreateFeedbackAndDelayReasonTables', 'default', 'App', 1784812910, 1),
(10, '2026_07_17_000011', 'App\\Database\\Migrations\\CreateTicketLogsTable', 'default', 'App', 1784812910, 1),
(11, '2026_07_17_000012', 'App\\Database\\Migrations\\CreateNotificationsTable', 'default', 'App', 1784812911, 1),
(12, '2026_07_17_000013', 'App\\Database\\Migrations\\CreateOtpCodesTable', 'default', 'App', 1784812912, 1),
(13, '2026_08_04_000001', 'App\\Database\\Migrations\\CreateCiSessionsTable', 'default', 'App', 1784812913, 1),
(14, '2026_08_16_000002', 'App\\Database\\Migrations\\CreatePersonnelCategoriesTable', 'default', 'App', 1784812914, 1),
(15, '2026_09_08_000001', 'App\\Database\\Migrations\\CreateUserSessionsTable', 'default', 'App', 1784812915, 1),
(16, '2026_09_08_000002', 'App\\Database\\Migrations\\CreateRolePermissionsTable', 'default', 'App', 1784812916, 1),
(17, '2026_09_10_000001', 'App\\Database\\Migrations\\MakeUserEmailNullable', 'default', 'App', 1784812917, 1);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) UNSIGNED NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `is_read`, `created_at`, `updated_at`) VALUES
(1, '4ad09d8a-975f-4ad9-8481-ff8b45781998', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-1-2026 for Concrete Works requires review.', 0, '2026-09-10 14:02:33', '2026-09-10 14:02:33'),
(2, 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-1-2026 for Concrete Works requires review.', 1, '2026-09-10 14:02:33', '2026-09-10 14:05:26'),
(3, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'success', 'Ticket #FGMU-TIC-1-2026 Approved', 'Your request for Concrete Works has been approved.', 0, '2026-09-10 14:03:37', '2026-09-10 14:03:37'),
(4, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'info', 'Ticket #FGMU-TIC-1-2026 Dispatched', 'Your ticket has been scheduled for 2026-09-10.', 1, '2026-09-10 14:06:20', '2026-09-11 03:13:11'),
(5, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'success', 'Ticket #FGMU-TIC-1-2026 Completed', 'Your request for Concrete Works has been marked as completed.', 1, '2026-09-10 14:12:51', '2026-09-11 03:13:06'),
(6, '4ad09d8a-975f-4ad9-8481-ff8b45781998', 'info', 'Feedback Received', 'User has submitted feedback for Ticket #FGMU-TIC-1-2026.', 0, '2026-09-10 14:13:32', '2026-09-10 14:13:32'),
(7, 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'info', 'Feedback Received', 'User has submitted feedback for Ticket #FGMU-TIC-1-2026.', 1, '2026-09-10 14:13:32', '2026-09-10 14:15:12'),
(8, '4ad09d8a-975f-4ad9-8481-ff8b45781998', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-2-2026 for Masonry Works requires review.', 0, '2026-09-10 14:15:53', '2026-09-10 14:15:53'),
(9, 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-2-2026 for Masonry Works requires review.', 1, '2026-09-10 14:15:53', '2026-09-10 14:17:45'),
(10, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'success', 'Ticket #FGMU-TIC-2-2026 Approved', 'Your request for Masonry Works has been approved.', 1, '2026-09-10 14:16:18', '2026-09-11 03:12:58'),
(11, '4ad09d8a-975f-4ad9-8481-ff8b45781998', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-3-2026 for Concrete Works requires review.', 0, '2026-09-10 15:06:44', '2026-09-10 15:06:44'),
(12, 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-3-2026 for Concrete Works requires review.', 1, '2026-09-10 15:06:44', '2026-09-11 03:21:39'),
(13, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'info', 'Ticket #FGMU-TIC-2-2026 Dispatched', 'Your ticket has been scheduled for 2026-09-30.', 0, '2026-09-11 04:31:17', '2026-09-11 04:31:17'),
(14, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'success', 'Ticket #FGMU-TIC-3-2026 Approved', 'Your request for Concrete Works has been approved.', 0, '2026-09-11 05:07:03', '2026-09-11 05:07:03'),
(15, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'info', 'Ticket #FGMU-TIC-3-2026 Dispatched', 'Your ticket has been scheduled for 2026-09-11.', 0, '2026-09-11 07:54:19', '2026-09-11 07:54:19'),
(16, '4ad09d8a-975f-4ad9-8481-ff8b45781998', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-4-2026 for Carpentry & Joinery requires review.', 0, '2026-09-11 08:27:56', '2026-09-11 08:27:56'),
(17, 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-4-2026 for Carpentry & Joinery requires review.', 0, '2026-09-11 08:27:56', '2026-09-11 08:27:56'),
(18, '4ad09d8a-975f-4ad9-8481-ff8b45781998', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-5-2026 for Glass & Glazing Works requires review.', 0, '2026-09-11 08:28:09', '2026-09-11 08:28:09'),
(19, 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-5-2026 for Glass & Glazing Works requires review.', 0, '2026-09-11 08:28:09', '2026-09-11 08:28:09'),
(20, '4ad09d8a-975f-4ad9-8481-ff8b45781998', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-6-2026 for Painting Works requires review.', 0, '2026-09-11 08:28:29', '2026-09-11 08:28:29'),
(21, 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-6-2026 for Painting Works requires review.', 0, '2026-09-11 08:28:29', '2026-09-11 08:28:29'),
(22, '5322c820-a591-452a-be5c-cedb24b45f71', 'info', 'New Ticket Submitted', 'Ticket #LEAU-TIC-1-2026 for Disinfection requires review.', 0, '2026-09-11 08:35:54', '2026-09-11 08:35:54'),
(23, 'd4a8de41-bf10-495d-93e8-e2480d5d78be', 'info', 'New Ticket Submitted', 'Ticket #LEAU-TIC-1-2026 for Disinfection requires review.', 0, '2026-09-11 08:35:54', '2026-09-11 08:35:54'),
(24, '5322c820-a591-452a-be5c-cedb24b45f71', 'info', 'New Ticket Submitted', 'Ticket #LEAU-TIC-2-2026 for Cleaning/ Grubbing requires review.', 0, '2026-09-11 08:36:10', '2026-09-11 08:36:10'),
(25, 'd4a8de41-bf10-495d-93e8-e2480d5d78be', 'info', 'New Ticket Submitted', 'Ticket #LEAU-TIC-2-2026 for Cleaning/ Grubbing requires review.', 0, '2026-09-11 08:36:10', '2026-09-11 08:36:10'),
(26, '5322c820-a591-452a-be5c-cedb24b45f71', 'info', 'New Ticket Submitted', 'Ticket #LEAU-TIC-3-2026 for Stage & Hall Decoration requires review.', 0, '2026-09-11 08:36:29', '2026-09-11 08:36:29'),
(27, 'd4a8de41-bf10-495d-93e8-e2480d5d78be', 'info', 'New Ticket Submitted', 'Ticket #LEAU-TIC-3-2026 for Stage & Hall Decoration requires review.', 0, '2026-09-11 08:36:29', '2026-09-11 08:36:29'),
(28, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'success', 'Ticket #FGMU-TIC-4-2026 Approved', 'Your request for Carpentry & Joinery has been approved.', 0, '2026-09-11 08:41:10', '2026-09-11 08:41:10'),
(29, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'info', 'Ticket #FGMU-TIC-4-2026 Dispatched', 'Your ticket has been scheduled for 2026-09-14.', 0, '2026-09-11 08:45:33', '2026-09-11 08:45:33'),
(30, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'success', 'Ticket #FGMU-TIC-5-2026 Approved (Emergency Priority)', 'Your request for Glass & Glazing Works has been approved as an EMERGENCY request by the Director.', 0, '2026-09-11 09:20:56', '2026-09-11 09:20:56'),
(31, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'info', 'Ticket #FGMU-TIC-5-2026 Dispatched', 'Your ticket has been scheduled for 2026-09-16.', 0, '2026-09-11 10:16:18', '2026-09-11 10:16:18'),
(32, 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'info', 'New Ticket Submitted', 'Ticket #FGMU-TIC-7-2026 for Concrete Works requires review.', 1, '2026-09-11 13:22:05', '2026-09-11 13:36:13');

-- --------------------------------------------------------

--
-- Table structure for table `otp_codes`
--

CREATE TABLE `otp_codes` (
  `id` int(11) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `code` varchar(50) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personnel`
--

CREATE TABLE `personnel` (
  `id` varchar(36) NOT NULL,
  `unit_id` int(11) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `specialty` varchar(100) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `status` enum('available','working','on_leave','on_trip') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personnel`
--

INSERT INTO `personnel` (`id`, `unit_id`, `name`, `specialty`, `contact_number`, `status`, `created_at`, `updated_at`) VALUES
('35761db5-4002-441c-a37e-8f1f91e647a6', 1, 'Juan C. Cruz', 'Carpenter', NULL, 'working', '2026-09-10 13:39:39', '2026-09-11 10:16:18');

-- --------------------------------------------------------

--
-- Table structure for table `personnel_categories`
--

CREATE TABLE `personnel_categories` (
  `id` int(11) UNSIGNED NOT NULL,
  `unit_id` int(11) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personnel_categories`
--

INSERT INTO `personnel_categories` (`id`, `unit_id`, `name`, `is_system`, `created_at`) VALUES
(1, 1, 'Carpenter', 0, '2026-09-10 13:39:13');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(11) UNSIGNED NOT NULL,
  `role` varchar(50) NOT NULL,
  `feature_key` varchar(100) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

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

-- --------------------------------------------------------

--
-- Table structure for table `ssu_incident_details`
--

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
  `reporter_signature` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ssu_incident_issues`
--

CREATE TABLE `ssu_incident_issues` (
  `id` int(11) UNSIGNED NOT NULL,
  `issue_name` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ssu_incident_issues`
--

INSERT INTO `ssu_incident_issues` (`id`, `issue_name`) VALUES
(2, 'Damaged University Facilities / Equipment'),
(1, 'Lost / Stolen Personal Belongings'),
(3, 'Safety Policy Violation'),
(5, 'Suspicious Activity Observed'),
(4, 'Traffic Regulation Violation');

-- --------------------------------------------------------

--
-- Table structure for table `ssu_incident_issue_items`
--

CREATE TABLE `ssu_incident_issue_items` (
  `ticket_id` varchar(60) NOT NULL,
  `issue_id` int(11) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ssu_incident_roles`
--

CREATE TABLE `ssu_incident_roles` (
  `id` int(11) UNSIGNED NOT NULL,
  `role_name` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ssu_incident_roles`
--

INSERT INTO `ssu_incident_roles` (`id`, `role_name`) VALUES
(2, 'Eyewitness'),
(4, 'Responding Personnel'),
(3, 'Security Officer on Duty'),
(1, 'Victim / Complainant');

-- --------------------------------------------------------

--
-- Table structure for table `ssu_incident_role_items`
--

CREATE TABLE `ssu_incident_role_items` (
  `ticket_id` varchar(60) NOT NULL,
  `role_id` int(11) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ssu_incident_types`
--

CREATE TABLE `ssu_incident_types` (
  `id` int(11) UNSIGNED NOT NULL,
  `type_name` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ssu_incident_types`
--

INSERT INTO `ssu_incident_types` (`id`, `type_name`) VALUES
(7, 'Fire / Hazard Alert'),
(6, 'Medical Emergency / Injury'),
(8, 'Other Security Concern'),
(3, 'Physical Assault / Altercation'),
(5, 'Road Accident / Vehicular Collision'),
(1, 'Theft / Robbery'),
(4, 'Trespassing / Unauthorized Entry'),
(2, 'Vandalism / Property Damage');

-- --------------------------------------------------------

--
-- Table structure for table `ssu_incident_type_items`
--

CREATE TABLE `ssu_incident_type_items` (
  `ticket_id` varchar(60) NOT NULL,
  `incident_type_id` int(11) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `id` varchar(60) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `unit_id` int(11) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `service_type` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `status` enum('pending','approved','processing','resolved','closed','declined','cancelled') NOT NULL DEFAULT 'pending',
  `status_label` varchar(100) NOT NULL DEFAULT 'Pending Approval',
  `is_emergency` tinyint(1) NOT NULL DEFAULT 0,
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
  `overtime_hours` decimal(6,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`id`, `user_id`, `unit_id`, `title`, `service_type`, `description`, `status`, `status_label`, `is_emergency`, `verification_status`, `accomplishment_report_path`, `accomplishment_notes`, `verified_by_user_id`, `verified_at`, `decline_reason`, `current_step`, `eodb_tier`, `eodb_days`, `target_completion_date`, `location`, `office_room`, `is_archived`, `materials_logged`, `is_under_investigation`, `ssu_notation`, `submitted_at`, `reviewed_at`, `reviewed_by`, `completed_at`, `updated_at`, `is_project`, `project_title`, `project_target_duration`, `project_target_date`, `project_manpower`, `project_remarks`, `project_actual_start`, `project_actual_completion`, `project_working_days`, `extension_days`, `extended_completion_date`, `extension_reason`, `overtime_hours`) VALUES
('FGMU-TIC-1-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 1, 'Znsjsjddnfh', 'Concrete Works', 'Znsjsjddnfh', 'closed', 'Closed', 0, 'verified_closed', NULL, NULL, '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', '2026-09-10 14:13:32', NULL, 6, 'simple_3d', 3, '2026-09-15 00:00:00', 'College of Agriculture (CA)', 'CA Faculty Room', 1, 1, 0, NULL, '2026-09-10 14:02:33', '2026-09-10 14:03:37', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', '2026-09-10 14:12:51', '2026-09-10 14:13:32', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, 0, NULL, NULL, 0.00),
('FGMU-TIC-2-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 1, 'Sggmsgngdmdg', 'Masonry Works', 'Sggmsgngdmdg', 'processing', 'Dispatched / Scheduled', 0, 'pending_report', NULL, NULL, NULL, NULL, NULL, 4, 'simple_3d', 5, '2026-10-07 00:00:00', 'College of Agriculture (CA)', 'CA Faculty Room', 0, 0, 0, NULL, '2026-09-10 14:15:53', '2026-09-10 14:16:18', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', NULL, '2026-09-11 04:31:17', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5, 0, NULL, NULL, 0.00),
('FGMU-TIC-3-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 1, 'Jzsjsjsjhs', 'Concrete Works', 'Jzsjsjsjhs', 'processing', 'Job Started', 0, 'pending_report', NULL, NULL, NULL, NULL, NULL, 5, 'simple_3d', 5, '2026-09-18 00:00:00', 'College of Engineering Complex (CE)', 'Engineering Computer Lab', 0, 0, 0, NULL, '2026-09-10 15:06:44', '2026-09-11 05:07:03', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', NULL, '2026-09-11 07:54:19', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5, 0, NULL, NULL, 0.00),
('FGMU-TIC-4-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 1, 'Jhhfxyuhhbdd', 'Carpentry & Joinery', 'Jhhfxyuhhbdd', 'processing', 'Dispatched / Scheduled', 0, 'pending_report', NULL, NULL, NULL, NULL, NULL, 4, 'simple_3d', 3, '2026-09-17 00:00:00', 'College of Engineering Complex (CE)', 'Engineering Computer Lab', 0, 0, 0, NULL, '2026-09-11 08:27:56', '2026-09-11 08:41:10', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', NULL, '2026-09-11 08:45:33', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, 0, NULL, NULL, 0.00),
('FGMU-TIC-5-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 1, 'Nbxfjhcdsgg', 'Glass & Glazing Works', 'Nbxfjhcdsgg', 'processing', '🚨 Emergency / In Progress', 1, 'pending_report', NULL, NULL, NULL, NULL, NULL, 5, 'simple_3d', 5, '2026-09-23 00:00:00', 'College of Engineering Complex (CE)', 'Engineering Computer Lab', 0, 0, 0, NULL, '2026-09-11 08:28:09', '2026-09-11 09:20:56', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', NULL, '2026-09-11 10:16:18', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5, 0, NULL, NULL, 0.00),
('FGMU-TIC-6-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 1, 'Hhddftcsetb', 'Painting Works', 'Hhddftcsetb', 'pending', 'Pending Approval', 0, 'pending_report', NULL, NULL, NULL, NULL, NULL, 2, NULL, 3, NULL, 'College of Engineering Complex (CE)', 'Dean\'s Office', 0, 0, 0, NULL, '2026-09-11 08:28:29', NULL, NULL, NULL, '2026-09-11 08:28:29', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0.00),
('FGMU-TIC-7-2026', '5d2a234e-14c6-42e9-b91f-95072c2e6842', 1, 'lalallalalalalalalala', 'Concrete Works', 'lalallalalalalalalala', 'pending', 'Pending Approval', 0, 'pending_report', NULL, NULL, NULL, NULL, NULL, 2, NULL, 3, NULL, 'College of Agriculture (CA)', 'Agri-Science Laboratory', 0, 0, 0, NULL, '2026-09-11 13:22:05', NULL, NULL, NULL, '2026-09-11 13:22:05', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0.00),
('LEAU-TIC-1-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 2, 'Dhdhadhdamad', 'Disinfection', 'Dhdhadhdamad', 'pending', 'Pending Approval', 0, 'pending_report', NULL, NULL, NULL, NULL, NULL, 2, NULL, 3, NULL, 'College of Agriculture (CA)', 'CA Faculty Room', 0, 0, 0, NULL, '2026-09-11 08:35:54', NULL, NULL, NULL, '2026-09-11 08:35:54', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0.00),
('LEAU-TIC-2-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 2, 'Dadadahfsfwm', 'Cleaning/ Grubbing', 'Dadadahfsfwm', 'pending', 'Pending Approval', 0, 'pending_report', NULL, NULL, NULL, NULL, NULL, 2, NULL, 3, NULL, 'College of Engineering Complex (CE)', 'Engineering Computer Lab', 0, 0, 0, NULL, '2026-09-11 08:36:10', NULL, NULL, NULL, '2026-09-11 08:36:10', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0.00),
('LEAU-TIC-3-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 2, 'Cn.fcs.bsmhfshsfh', 'Stage & Hall Decoration', 'Cn.fcs.bsmhfshsfh', 'pending', 'Pending Approval', 0, 'pending_report', NULL, NULL, NULL, NULL, NULL, 2, NULL, 3, NULL, 'College of Engineering Complex (CE)', 'Dean\'s Office', 0, 0, 0, NULL, '2026-09-11 08:36:29', NULL, NULL, NULL, '2026-09-11 08:36:29', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `ticket_assignments`
--

CREATE TABLE `ticket_assignments` (
  `id` int(11) UNSIGNED NOT NULL,
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
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_assignments`
--

INSERT INTO `ticket_assignments` (`id`, `ticket_id`, `personnel_id`, `implementation_date`, `working_days`, `overtime_hours`, `is_reassigned`, `reassigned_from_id`, `reassigned_reason`, `dispatcher_notes`, `task_notes`, `is_emergency`, `queue_order`, `status`, `assigned_at`, `dispatched_at`, `completed_at`) VALUES
(2, 'FGMU-TIC-1-2026', '35761db5-4002-441c-a37e-8f1f91e647a6', '2026-09-10', 3, 0.00, 0, NULL, NULL, '', 'Znsjsjddnfh', 0, 1, 'queued', '2026-09-10 14:06:20', '2026-09-10 14:06:20', '2026-09-10 14:12:51'),
(3, 'FGMU-TIC-2-2026', '35761db5-4002-441c-a37e-8f1f91e647a6', '2026-09-30', 5, 0.00, 0, NULL, NULL, '', 'Sggmsgngdmdg', 0, 1, 'queued', '2026-09-11 04:31:17', NULL, NULL),
(4, 'FGMU-TIC-3-2026', '35761db5-4002-441c-a37e-8f1f91e647a6', '2026-09-11', 5, 0.00, 0, NULL, NULL, '', 'Concrete Works', 0, 1, 'queued', '2026-09-11 07:54:19', '2026-09-11 07:54:19', NULL),
(5, 'FGMU-TIC-4-2026', '35761db5-4002-441c-a37e-8f1f91e647a6', '2026-09-14', 3, 0.00, 0, NULL, NULL, '', 'Carpentry & Joinery', 0, 1, 'queued', '2026-09-11 08:45:33', NULL, NULL),
(6, 'FGMU-TIC-5-2026', '35761db5-4002-441c-a37e-8f1f91e647a6', '2026-09-16', 5, 0.00, 0, NULL, NULL, '', 'Glass & Glazing Works', 1, 0, 'active', '2026-09-11 10:16:18', '2026-09-11 10:16:18', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `ticket_attachments`
--

CREATE TABLE `ticket_attachments` (
  `id` int(11) UNSIGNED NOT NULL,
  `ticket_id` varchar(60) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` text NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size_bytes` bigint(20) UNSIGNED DEFAULT NULL,
  `is_encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `encryption_iv` varchar(64) DEFAULT NULL,
  `encryption_tag` varchar(64) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_attachments`
--

INSERT INTO `ticket_attachments` (`id`, `ticket_id`, `file_name`, `file_path`, `file_type`, `file_size_bytes`, `is_encrypted`, `encryption_iv`, `encryption_tag`, `uploaded_at`) VALUES
(2, 'FGMU-TIC-1-2026', 'IMG_20260907_160707_271.jpg', 'tickets/2026/FGMU-TIC-1-2026/1789048954_e90cc89c675626b4d082.jpg', 'image/jpeg', 4391702, 1, 'f24b2381ab17b5ed88e222ed', '517f1c6df7cde0e7d01b77cb5c8e8c12', '2026-09-10 14:02:34'),
(3, 'FGMU-TIC-7-2026', 'Screenshot 2026-01-12 000101.png', 'tickets/2026/FGMU-TIC-7-2026/1789132927_cc04cef4e31b8a72d306.png', 'image/png', 80105, 1, '32955bc8ca6fa8f916e7c534', 'a7aa5f691d6bcab3a5f47ef9fca9387b', '2026-09-11 13:22:07');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_feedbacks`
--

CREATE TABLE `ticket_feedbacks` (
  `id` int(11) UNSIGNED NOT NULL,
  `ticket_id` varchar(60) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `completion_status` enum('on-time','beyond-time','not-completed') NOT NULL,
  `courtesy_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `quality_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `efficiency_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `timeliness_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `cleanliness_rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_feedbacks`
--

INSERT INTO `ticket_feedbacks` (`id`, `ticket_id`, `user_id`, `completion_status`, `courtesy_rating`, `quality_rating`, `efficiency_rating`, `timeliness_rating`, `cleanliness_rating`, `remarks`, `created_at`) VALUES
(2, 'FGMU-TIC-1-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'on-time', 5, 5, 5, 5, 5, 'Issjsj', '2026-09-10 14:13:32');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_feedback_delay_items`
--

CREATE TABLE `ticket_feedback_delay_items` (
  `feedback_id` int(11) UNSIGNED NOT NULL,
  `delay_reason_id` int(11) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_logs`
--

CREATE TABLE `ticket_logs` (
  `id` int(11) UNSIGNED NOT NULL,
  `ticket_id` varchar(60) NOT NULL,
  `user_id` varchar(36) DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_logs`
--

INSERT INTO `ticket_logs` (`id`, `ticket_id`, `user_id`, `action`, `details`, `created_at`) VALUES
(7, 'FGMU-TIC-1-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'Ticket Submitted', 'FGMU service request: Concrete Works', '2026-09-10 14:02:33'),
(8, 'FGMU-TIC-1-2026', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', 'Status Changed', 'Ticket approved — queued for dispatch.', '2026-09-10 14:03:37'),
(9, 'FGMU-TIC-1-2026', 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'Worker Assigned', 'Assigned to Juan C. Cruz — scheduled for 2026-09-10 (EODB: 3 working days)', '2026-09-10 14:06:20'),
(10, 'FGMU-TIC-1-2026', 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'Status Changed', 'Ticket marked as completed.', '2026-09-10 14:12:51'),
(11, 'FGMU-TIC-1-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'Feedback Submitted', 'Rating: on-time', '2026-09-10 14:13:32'),
(12, 'FGMU-TIC-2-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'Ticket Submitted', 'FGMU service request: Masonry Works', '2026-09-10 14:15:53'),
(13, 'FGMU-TIC-2-2026', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', 'Status Changed', 'Ticket approved — queued for dispatch.', '2026-09-10 14:16:18'),
(14, 'FGMU-TIC-3-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'Ticket Submitted', 'FGMU service request: Concrete Works', '2026-09-10 15:06:44'),
(15, 'FGMU-TIC-2-2026', 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'Worker Assigned', 'Assigned to Juan C. Cruz — scheduled for 2026-09-30 (EODB: 5 working days)', '2026-09-11 04:31:17'),
(16, 'FGMU-TIC-3-2026', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', 'Status Changed', 'Ticket approved — queued for dispatch.', '2026-09-11 05:07:03'),
(17, 'FGMU-TIC-3-2026', 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'Worker Assigned', 'Assigned to Juan C. Cruz — scheduled for 2026-09-11 (EODB: 5 working days)', '2026-09-11 07:54:19'),
(18, 'FGMU-TIC-4-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'Ticket Submitted', 'FGMU service request: Carpentry & Joinery', '2026-09-11 08:27:56'),
(19, 'FGMU-TIC-5-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'Ticket Submitted', 'FGMU service request: Glass & Glazing Works', '2026-09-11 08:28:09'),
(20, 'FGMU-TIC-6-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'Ticket Submitted', 'FGMU service request: Painting Works', '2026-09-11 08:28:29'),
(21, 'LEAU-TIC-1-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'Ticket Submitted', 'LEAU service request: Disinfection', '2026-09-11 08:35:54'),
(22, 'LEAU-TIC-2-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'Ticket Submitted', 'LEAU service request: Cleaning/ Grubbing', '2026-09-11 08:36:10'),
(23, 'LEAU-TIC-3-2026', '3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'Ticket Submitted', 'LEAU service request: Stage & Hall Decoration', '2026-09-11 08:36:29'),
(24, 'FGMU-TIC-4-2026', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', 'Status Changed', 'Ticket approved — queued for dispatch.', '2026-09-11 08:41:10'),
(25, 'FGMU-TIC-4-2026', 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'Worker Assigned', 'Assigned to Juan C. Cruz — scheduled for 2026-09-14 (EODB: 3 working days)', '2026-09-11 08:45:33'),
(26, 'FGMU-TIC-5-2026', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', 'Status Changed', 'Ticket approved as EMERGENCY PRIORITY by Director — queued for immediate dispatch.', '2026-09-11 09:20:56'),
(27, 'FGMU-TIC-5-2026', 'cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'Worker Assigned', 'EMERGENCY: Assigned to Juan C. Cruz — scheduled for 2026-09-16 (EODB: 5 working days)', '2026-09-11 10:16:18'),
(28, 'FGMU-TIC-7-2026', '5d2a234e-14c6-42e9-b91f-95072c2e6842', 'Ticket Submitted', 'FGMU service request: Concrete Works', '2026-09-11 13:22:05');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_materials`
--

CREATE TABLE `ticket_materials` (
  `id` int(11) UNSIGNED NOT NULL,
  `ticket_id` varchar(60) DEFAULT NULL,
  `assignment_id` int(11) UNSIGNED DEFAULT NULL,
  `material_name` varchar(200) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_measurement` varchar(50) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `id` int(11) UNSIGNED NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `units`
--

INSERT INTO `units` (`id`, `code`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'FGMU', 'Facilities and Grounds Management Unit', 'Manages structure, finishes, utilities, mechanical, and carpentry repairs across BSU campus.', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
(2, 'LEAU', 'Landscape and Environment Aesthetics Unit', 'Responsible for campus landscaping, janitorial services, lawn mowing, and disinfection operations.', '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
(3, 'SSU', 'Security Service Unit', 'Handles university security, campus safety coordination, and campus incident reporting.', '2026-07-23 21:21:51', '2026-07-23 21:21:51');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password_hash` text NOT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `role` enum('student','employee','admin','dispatcher','director','worker','superadmin') NOT NULL DEFAULT 'student',
  `unit_id` int(11) UNSIGNED DEFAULT NULL,
  `student_id_number` varchar(50) DEFAULT NULL,
  `id_card_image` text DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `status` enum('Active','Pending','Rejected','Suspended','Deactivated') NOT NULL DEFAULT 'Active',
  `is_verified` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `email`, `password_hash`, `contact_number`, `role`, `unit_id`, `student_id_number`, `id_card_image`, `avatar_path`, `status`, `is_verified`, `created_at`, `updated_at`) VALUES
('3a0adf0b-a3ee-4e4a-862e-3d9ca05be3e5', 'End User', 'Test', 'enduser@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'student', NULL, NULL, NULL, NULL, 'Active', 1, '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('5322c820-a591-452a-be5c-cedb24b45f71', 'LEAU', 'Admin', 'leau-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', 2, NULL, NULL, NULL, 'Active', 1, '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('5d2a234e-14c6-42e9-b91f-95072c2e6842', 'Dave', 'pilaspilas', NULL, '$2y$10$jMRvZ7i/ia8wGSM0BPjkNuS7NAjowTXwJpxWp2sRGaBTJw9zmIBKG', '09517252737', 'student', NULL, '2800120', 'id_cards/id_card_5d2a234e-14c6-42e9-b91f-95072c2e6842_1789104584.jpg', NULL, 'Active', 1, '2026-09-11 05:29:45', '2026-09-11 06:27:04'),
('a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', 'Super', 'Administrator', 'superadmin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', '09123456789', 'superadmin', NULL, NULL, NULL, NULL, 'Active', 1, '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('cfcb614f-ebd4-43ee-afe8-39fb18516ad3', 'FGMU', 'Admin', 'fgmu-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', 1, NULL, NULL, NULL, 'Active', 1, '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('e6f927fb-aec7-4ab9-85a9-9af83f1d4dc9', 'SSU', 'Admin', 'ssu-admin@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'admin', 3, NULL, NULL, NULL, 'Active', 1, '2026-07-23 21:21:51', '2026-07-23 21:21:51'),
('f12d1cfd-a338-41ca-88de-b29ea8e71f33', 'GSO', 'Director', 'director@email.com', '$2y$10$O75lTE/4N11icQzKanbhfuL2uMlCadbsO1vpA8a3X6a7.BOuQoU8m', NULL, 'director', NULL, NULL, NULL, NULL, 'Active', 1, '2026-07-23 21:21:51', '2026-07-23 21:21:51');

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `session_id` varchar(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_sessions`
--

INSERT INTO `user_sessions` (`id`, `user_id`, `session_id`, `ip_address`, `user_agent`, `created_at`, `last_activity`) VALUES
('a650d5e4-4397-4b8b-ae84-60ad3079f157', 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d', '0f464d9735803191e12a011c9e707017', '120.29.88.203', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', '2026-09-11 13:43:23', '2026-09-11 14:16:33'),
('dd753405-b7bd-4a4e-9ce6-f99663624ef4', 'f12d1cfd-a338-41ca-88de-b29ea8e71f33', 'f2988bddc4a78c6c02f2c47361846a1c', '120.29.89.69', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36 Edg/152.0.0.0', '2026-09-11 13:49:18', '2026-09-11 14:17:38');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ci_sessions`
--
ALTER TABLE `ci_sessions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `feedback_delay_reasons`
--
ALTER TABLE `feedback_delay_reasons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reason_code` (`reason_code`);

--
-- Indexes for table `fgmu_ticket_details`
--
ALTER TABLE `fgmu_ticket_details`
  ADD PRIMARY KEY (`ticket_id`);

--
-- Indexes for table `leau_ticket_details`
--
ALTER TABLE `leau_ticket_details`
  ADD PRIMARY KEY (`ticket_id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `otp_codes`
--
ALTER TABLE `otp_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`);

--
-- Indexes for table `personnel`
--
ALTER TABLE `personnel`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_personnel_unit_status` (`unit_id`,`status`);

--
-- Indexes for table `personnel_categories`
--
ALTER TABLE `personnel_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_category_unit` (`unit_id`,`name`),
  ADD KEY `idx_categories_unit` (`unit_id`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_feature` (`role`,`feature_key`);

--
-- Indexes for table `ssu_incident_details`
--
ALTER TABLE `ssu_incident_details`
  ADD PRIMARY KEY (`ticket_id`);

--
-- Indexes for table `ssu_incident_issues`
--
ALTER TABLE `ssu_incident_issues`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `issue_name` (`issue_name`);

--
-- Indexes for table `ssu_incident_issue_items`
--
ALTER TABLE `ssu_incident_issue_items`
  ADD PRIMARY KEY (`ticket_id`,`issue_id`),
  ADD KEY `fk_issue_item_issue` (`issue_id`);

--
-- Indexes for table `ssu_incident_roles`
--
ALTER TABLE `ssu_incident_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `ssu_incident_role_items`
--
ALTER TABLE `ssu_incident_role_items`
  ADD PRIMARY KEY (`ticket_id`,`role_id`),
  ADD KEY `fk_role_item_role` (`role_id`);

--
-- Indexes for table `ssu_incident_types`
--
ALTER TABLE `ssu_incident_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `type_name` (`type_name`);

--
-- Indexes for table `ssu_incident_type_items`
--
ALTER TABLE `ssu_incident_type_items`
  ADD PRIMARY KEY (`ticket_id`,`incident_type_id`),
  ADD KEY `fk_incident_item_type` (`incident_type_id`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_tickets_reviewer` (`reviewed_by`),
  ADD KEY `idx_tickets_verified_by` (`verified_by_user_id`),
  ADD KEY `idx_tickets_user` (`user_id`),
  ADD KEY `idx_tickets_unit_status` (`unit_id`,`status`),
  ADD KEY `idx_tickets_archived` (`is_archived`),
  ADD KEY `idx_tickets_investigating` (`unit_id`,`is_under_investigation`,`is_archived`),
  ADD KEY `idx_tickets_submitted` (`submitted_at`);

--
-- Indexes for table `ticket_assignments`
--
ALTER TABLE `ticket_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assignments_ticket` (`ticket_id`),
  ADD KEY `idx_assignments_personnel` (`personnel_id`),
  ADD KEY `idx_assignments_reassigned_from` (`reassigned_from_id`);

--
-- Indexes for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_attachments_ticket` (`ticket_id`);

--
-- Indexes for table `ticket_feedbacks`
--
ALTER TABLE `ticket_feedbacks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_id` (`ticket_id`),
  ADD KEY `idx_feedbacks_user` (`user_id`);

--
-- Indexes for table `ticket_feedback_delay_items`
--
ALTER TABLE `ticket_feedback_delay_items`
  ADD PRIMARY KEY (`feedback_id`,`delay_reason_id`),
  ADD KEY `fk_delay_item_reason` (`delay_reason_id`);

--
-- Indexes for table `ticket_logs`
--
ALTER TABLE `ticket_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_logs_user` (`user_id`),
  ADD KEY `idx_logs_ticket` (`ticket_id`),
  ADD KEY `idx_logs_created` (`created_at`);

--
-- Indexes for table `ticket_materials`
--
ALTER TABLE `ticket_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_materials_ticket` (`ticket_id`),
  ADD KEY `idx_materials_assignment` (`assignment_id`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_unit` (`unit_id`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_status` (`status`),
  ADD KEY `idx_users_student_id` (`student_id_number`),
  ADD KEY `idx_users_contact` (`contact_number`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_sessions_user` (`user_id`),
  ADD KEY `idx_user_sessions_sid` (`session_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `feedback_delay_reasons`
--
ALTER TABLE `feedback_delay_reasons`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `otp_codes`
--
ALTER TABLE `otp_codes`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `personnel_categories`
--
ALTER TABLE `personnel_categories`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `ssu_incident_issues`
--
ALTER TABLE `ssu_incident_issues`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `ssu_incident_roles`
--
ALTER TABLE `ssu_incident_roles`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `ssu_incident_types`
--
ALTER TABLE `ssu_incident_types`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `ticket_assignments`
--
ALTER TABLE `ticket_assignments`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `ticket_feedbacks`
--
ALTER TABLE `ticket_feedbacks`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `ticket_logs`
--
ALTER TABLE `ticket_logs`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `ticket_materials`
--
ALTER TABLE `ticket_materials`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `fgmu_ticket_details`
--
ALTER TABLE `fgmu_ticket_details`
  ADD CONSTRAINT `fk_fgmu_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `leau_ticket_details`
--
ALTER TABLE `leau_ticket_details`
  ADD CONSTRAINT `fk_leau_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `personnel`
--
ALTER TABLE `personnel`
  ADD CONSTRAINT `fk_personnel_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `personnel_categories`
--
ALTER TABLE `personnel_categories`
  ADD CONSTRAINT `fk_categories_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ssu_incident_details`
--
ALTER TABLE `ssu_incident_details`
  ADD CONSTRAINT `fk_ssu_incident_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ssu_incident_issue_items`
--
ALTER TABLE `ssu_incident_issue_items`
  ADD CONSTRAINT `fk_issue_item_issue` FOREIGN KEY (`issue_id`) REFERENCES `ssu_incident_issues` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_issue_item_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ssu_incident_role_items`
--
ALTER TABLE `ssu_incident_role_items`
  ADD CONSTRAINT `fk_role_item_role` FOREIGN KEY (`role_id`) REFERENCES `ssu_incident_roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_role_item_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ssu_incident_type_items`
--
ALTER TABLE `ssu_incident_type_items`
  ADD CONSTRAINT `fk_incident_item_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ssu_incident_details` (`ticket_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_incident_item_type` FOREIGN KEY (`incident_type_id`) REFERENCES `ssu_incident_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `fk_tickets_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL,
  ADD CONSTRAINT `fk_tickets_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tickets_verified_by` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `ticket_assignments`
--
ALTER TABLE `ticket_assignments`
  ADD CONSTRAINT `fk_assignment_personnel` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assignment_reassigned_from` FOREIGN KEY (`reassigned_from_id`) REFERENCES `personnel` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assignment_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD CONSTRAINT `fk_attachments_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ticket_feedbacks`
--
ALTER TABLE `ticket_feedbacks`
  ADD CONSTRAINT `fk_feedback_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ticket_feedback_delay_items`
--
ALTER TABLE `ticket_feedback_delay_items`
  ADD CONSTRAINT `fk_delay_item_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `ticket_feedbacks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_delay_item_reason` FOREIGN KEY (`delay_reason_id`) REFERENCES `feedback_delay_reasons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ticket_logs`
--
ALTER TABLE `ticket_logs`
  ADD CONSTRAINT `fk_logs_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL;

--
-- Constraints for table `ticket_materials`
--
ALTER TABLE `ticket_materials`
  ADD CONSTRAINT `fk_materials_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `ticket_assignments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_materials_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE SET NULL;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
