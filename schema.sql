-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 27, 2026 at 08:05 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `micrologs`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `action` varchar(100) NOT NULL,
  `actor` varchar(255) NOT NULL DEFAULT '',
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `devices`
--

CREATE TABLE `devices` (
  `id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `device_type` enum('desktop','mobile','tablet','unknown') NOT NULL DEFAULT 'unknown',
  `os` varchar(50) NOT NULL DEFAULT '',
  `browser` varchar(50) NOT NULL DEFAULT '',
  `browser_version` varchar(20) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `error_events`
--

CREATE TABLE `error_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `location_id` int(10) UNSIGNED DEFAULT NULL,
  `device_id` int(10) UNSIGNED DEFAULT NULL,
  `stack_trace` text DEFAULT NULL,
  `url` varchar(2048) NOT NULL DEFAULT '',
  `environment` enum('production','staging','development','unknown') NOT NULL DEFAULT 'production',
  `severity` enum('info','warning','error','critical') NOT NULL DEFAULT 'error',
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `error_groups`
--

CREATE TABLE `error_groups` (
  `id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `error_type` varchar(100) NOT NULL DEFAULT '',
  `message` varchar(1024) NOT NULL DEFAULT '',
  `file` varchar(512) NOT NULL DEFAULT '',
  `line` int(10) UNSIGNED DEFAULT NULL,
  `severity` enum('info','warning','error','critical') NOT NULL DEFAULT 'error',
  `environment` enum('production','staging','development','unknown') NOT NULL DEFAULT 'production',
  `status` enum('open','resolved','ignored') NOT NULL DEFAULT 'open',
  `occurrence_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `link_clicks`
--

CREATE TABLE `link_clicks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `link_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `location_id` int(10) UNSIGNED DEFAULT NULL,
  `device_id` int(10) UNSIGNED DEFAULT NULL,
  `referrer_url` varchar(2048) NOT NULL DEFAULT '',
  `referrer_category` enum('direct','organic_search','social','referral','email','unknown') NOT NULL DEFAULT 'unknown',
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `locations`
--

CREATE TABLE `locations` (
  `id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `country` varchar(100) NOT NULL DEFAULT '',
  `country_code` char(2) NOT NULL DEFAULT '',
  `region` varchar(100) NOT NULL DEFAULT '',
  `city` varchar(100) NOT NULL DEFAULT '',
  `is_vpn` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pageviews`
--

CREATE TABLE `pageviews` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `session_id` bigint(20) UNSIGNED NOT NULL,
  `visitor_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` int(10) UNSIGNED DEFAULT NULL,
  `device_id` int(10) UNSIGNED DEFAULT NULL,
  `url` varchar(2048) NOT NULL,
  `page_title` varchar(512) NOT NULL DEFAULT '',
  `referrer_url` varchar(2048) NOT NULL DEFAULT '',
  `referrer_category` enum('direct','organic_search','social','referral','email','unknown') NOT NULL DEFAULT 'unknown',
  `utm_source` varchar(255) NOT NULL DEFAULT '',
  `utm_medium` varchar(255) NOT NULL DEFAULT '',
  `utm_campaign` varchar(255) NOT NULL DEFAULT '',
  `utm_content` varchar(255) NOT NULL DEFAULT '',
  `utm_term` varchar(255) NOT NULL DEFAULT '',
  `screen_resolution` varchar(20) NOT NULL DEFAULT '',
  `timezone` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `secret_key` char(64) NOT NULL,
  `public_key` char(32) NOT NULL,
  `allowed_domains` text NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `visitor_id` bigint(20) UNSIGNED NOT NULL,
  `session_token` char(64) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_activity` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_bounced` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracked_links`
--

CREATE TABLE `tracked_links` (
  `id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `code` varchar(12) NOT NULL,
  `destination_url` varchar(2048) NOT NULL,
  `label` varchar(255) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `visitors`
--

CREATE TABLE `visitors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `visitor_hash` char(64) NOT NULL,
  `fingerprint_hash` char(64) NOT NULL DEFAULT '',
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_action` (`project_id`,`action`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_device` (`project_id`,`device_type`,`os`,`browser`,`browser_version`),
  ADD KEY `idx_project_id` (`project_id`);

--
-- Indexes for table `error_events`
--
ALTER TABLE `error_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group_id` (`group_id`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `error_groups`
--
ALTER TABLE `error_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fingerprint` (`project_id`,`fingerprint`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_status` (`project_id`,`status`),
  ADD KEY `idx_severity` (`project_id`,`severity`),
  ADD KEY `idx_last_seen` (`project_id`,`last_seen`);

--
-- Indexes for table `link_clicks`
--
ALTER TABLE `link_clicks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_link_id` (`link_id`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `locations`
--
ALTER TABLE `locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_location` (`project_id`,`country_code`,`region`,`city`),
  ADD KEY `idx_project_id` (`project_id`);

--
-- Indexes for table `pageviews`
--
ALTER TABLE `pageviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_session_id` (`session_id`),
  ADD KEY `idx_visitor_id` (`visitor_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_url` (`project_id`,`url`(255)),
  ADD KEY `idx_referrer_category` (`project_id`,`referrer_category`),
  ADD KEY `idx_project_created` (`project_id`,`created_at`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `secret_key` (`secret_key`),
  ADD UNIQUE KEY `public_key` (`public_key`),
  ADD KEY `idx_secret_key` (`secret_key`),
  ADD KEY `idx_public_key` (`public_key`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_visitor_id` (`visitor_id`),
  ADD KEY `idx_session_token` (`session_token`),
  ADD KEY `idx_started_at` (`started_at`),
  ADD KEY `idx_project_started` (`project_id`,`started_at`);

--
-- Indexes for table `tracked_links`
--
ALTER TABLE `tracked_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`),
  ADD KEY `idx_project_id` (`project_id`);

--
-- Indexes for table `visitors`
--
ALTER TABLE `visitors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_visitor` (`project_id`,`visitor_hash`),
  ADD KEY `idx_fingerprint` (`project_id`,`fingerprint_hash`),
  ADD KEY `idx_project_id` (`project_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `error_events`
--
ALTER TABLE `error_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `error_groups`
--
ALTER TABLE `error_groups`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `link_clicks`
--
ALTER TABLE `link_clicks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `locations`
--
ALTER TABLE `locations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pageviews`
--
ALTER TABLE `pageviews`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tracked_links`
--
ALTER TABLE `tracked_links`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `visitors`
--
ALTER TABLE `visitors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_al_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `devices`
--
ALTER TABLE `devices`
  ADD CONSTRAINT `fk_devices_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `error_events`
--
ALTER TABLE `error_events`
  ADD CONSTRAINT `fk_ee_group` FOREIGN KEY (`group_id`) REFERENCES `error_groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ee_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `error_groups`
--
ALTER TABLE `error_groups`
  ADD CONSTRAINT `fk_eg_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `locations`
--
ALTER TABLE `locations`
  ADD CONSTRAINT `fk_locations_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `fk_sessions_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sessions_visitor` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tracked_links`
--
ALTER TABLE `tracked_links`
  ADD CONSTRAINT `fk_links_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `visitors`
--
ALTER TABLE `visitors`
  ADD CONSTRAINT `fk_visitors_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
