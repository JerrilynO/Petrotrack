-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 16, 2026 at 10:50 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `petroleum_logistics_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `petroleum_logistics_db`;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `petroleum_logistics_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `id` int(11) NOT NULL,
  `truck_id` int(11) NOT NULL,
  `source` varchar(100) DEFAULT NULL,
  `destination` varchar(100) DEFAULT NULL,
  `product_type` enum('PMS','AGO','DPK','ATK') DEFAULT NULL,
  `volume_loaded` int(11) DEFAULT NULL,
  `volume_delivered` int(11) DEFAULT 0,
  `delivery_status` enum('pending','in_transit','awaiting_verification','delivered','cancelled','flagged') DEFAULT 'pending',
  `delivery_date` date DEFAULT NULL,
  `estimated_arrival` time DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `driver_reported_vol` int(11) DEFAULT NULL,
  `station_reported_vol` int(11) DEFAULT NULL,
  `discrepancy_reason` text DEFAULT NULL,
  `supervisor_signature` text DEFAULT NULL,
  `station_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `verified_by` varchar(100) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verified_station` varchar(100) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deliveries`
--

INSERT INTO `deliveries` (`id`, `truck_id`, `source`, `destination`, `product_type`, `volume_loaded`, `volume_delivered`, `delivery_status`, `delivery_date`, `estimated_arrival`, `created_at`, `driver_reported_vol`, `station_reported_vol`, `discrepancy_reason`, `supervisor_signature`, `station_id`, `notes`, `created_by`, `verified_by`, `verified_at`, `verified_station`, `delivered_at`) VALUES
(1, 1, 'Apapa Depot, Lagos', 'Coinoil, Ikeja', 'PMS', 33000, 0, 'cancelled', '2026-05-11', '03:35:00', '2026-05-11 21:35:05', NULL, NULL, NULL, NULL, 2, NULL, 5, NULL, NULL, NULL, NULL),
(2, 1, 'Mosimi Depot, Ogun', 'Coinoil, Ikeja', 'AGO', 33000, 0, 'cancelled', '2026-05-11', '03:41:00', '2026-05-11 21:41:23', NULL, NULL, NULL, NULL, 2, NULL, 5, NULL, NULL, NULL, NULL),
(3, 1, 'Apapa Depot, Lagos', 'Coinoil, Ikeja', 'DPK', 25000, 25000, 'delivered', '2026-05-13', '20:30:00', '2026-05-13 14:30:35', 25000, 24999, '', 'Verified by: Demo Supervisor | PIN-authorised | 14 May 2026, 02:21:41 | Station dip: 24,999L', 2, NULL, 5, NULL, NULL, NULL, NULL),
(4, 1, 'Mosimi Depot, Ogun', 'Coinoil, Ikeja', 'PMS', 25000, 25000, 'delivered', '2026-05-14', '16:47:00', '2026-05-14 10:47:19', 25000, 25000, '', 'Verified by: Demo Supervisor | PIN-authorised | 14 May 2026, 12:48:09 | Station dip: 25,000L', 2, NULL, 5, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `delivery_notes`
--

CREATE TABLE `delivery_notes` (
  `id` int(11) NOT NULL,
  `delivery_id` int(11) DEFAULT NULL,
  `note_type` enum('delay','issue','general') DEFAULT 'general',
  `note_text` text DEFAULT NULL,
  `reported_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `depots`
--

CREATE TABLE `depots` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stations`
--

CREATE TABLE `stations` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stations`
--

INSERT INTO `stations` (`id`, `name`, `location`, `created_at`) VALUES
(1, 'Total, Abuja', 'Gwarinpa, Abuja', '2026-05-11 21:21:43'),
(2, 'Coinoil, Ikeja', 'Ikeja, Lagos', '2026-05-11 21:22:14');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','driver','supervisor') NOT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `status_reason` text DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `station_id` int(11) DEFAULT NULL,
  `verification_pin` varchar(255) DEFAULT NULL,
  `security_question` varchar(255) DEFAULT NULL,
  `security_answer` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password`, `role`, `status`, `status_reason`, `last_login`, `created_at`, `updated_at`, `station_id`, `verification_pin`, `security_question`, `security_answer`) VALUES
(5, 'Demo Admin', 'admin@petrotrack.demo', '+2348000000001', '$2y$10$xTfrQtPGFgxo1/1G/QiQm.6Oc9lCNEVJJS3HkYD9/CD6cIc06jzDy', 'admin', 'active', NULL, NULL, '2026-05-11 20:00:00', '2026-05-11 20:00:00', NULL, NULL, 'What is your favourite colour?', '$2y$10$fWYNrC1jUUWt0733UJtFEOfYbtMWnDB/IN5mDEAMfGDUJ0R4K9oBe'),
(6, 'Demo Supervisor', 'supervisor@petrotrack.demo', '+2348000000002', '$2y$10$SthkMqrDxLafZcrSE1wLtuXBV87voE5/BZfW3mNo/4at0WcSogr3O', 'supervisor', 'active', NULL, NULL, '2026-05-11 20:00:00', '2026-05-11 20:00:00', 2, '$2y$10$L3EPjlUcNfXPjEqw2ZNYgO4t3MI9BcNSj76MglCwWxZShpq0SwZCW', 'What is your favourite colour?', '$2y$10$fWYNrC1jUUWt0733UJtFEOfYbtMWnDB/IN5mDEAMfGDUJ0R4K9oBe'),
(10, 'Demo Driver', 'driver@petrotrack.demo', '+2348000000003', '$2y$10$weIJZAfAmsHQB4alFl3iEO5OvmyFfHEKbIq0I4QRYg2F7i05f//tG', 'driver', 'active', NULL, NULL, '2026-05-11 20:00:00', '2026-05-11 20:00:00', NULL, NULL, 'What is your favourite colour?', '$2y$10$fWYNrC1jUUWt0733UJtFEOfYbtMWnDB/IN5mDEAMfGDUJ0R4K9oBe'),
(11, 'Second Supervisor', 'supervisor2@petrotrack.demo', '+2348000000004', '$2y$10$n2iN7CSJ.vyrdMF68prPxuoXmHkM0ARq0RoqmiMwmMscMsgYOo5Ae', 'supervisor', 'active', NULL, NULL, '2026-05-11 20:00:00', '2026-05-11 20:00:00', 1, '$2y$10$L3EPjlUcNfXPjEqw2ZNYgO4t3MI9BcNSj76MglCwWxZShpq0SwZCW', 'What is your favourite colour?', '$2y$10$fWYNrC1jUUWt0733UJtFEOfYbtMWnDB/IN5mDEAMfGDUJ0R4K9oBe');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `plate_number` varchar(20) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `capacity` int(11) NOT NULL,
  `status` enum('active','inactive','maintenance') DEFAULT 'active',
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `managed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `plate_number`, `driver_id`, `capacity`, `status`, `latitude`, `longitude`, `created_at`, `updated_at`, `managed_by`) VALUES
(1, 'ABC-123-DJ', 10, 33000, 'active', 6.46980000, 3.38550000, '2026-05-11 21:33:04', '2026-05-15 09:59:53', 5);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `truck_id` (`truck_id`);

--
-- Indexes for table `delivery_notes`
--
ALTER TABLE `delivery_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_id` (`delivery_id`),
  ADD KEY `reported_by` (`reported_by`);

--
-- Indexes for table `depots`
--
ALTER TABLE `depots`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sender` (`sender_id`),
  ADD KEY `idx_receiver` (`receiver_id`);

--
-- Indexes for table `stations`
--
ALTER TABLE `stations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plate_number` (`plate_number`),
  ADD KEY `driver_id` (`driver_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `delivery_notes`
--
ALTER TABLE `delivery_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `depots`
--
ALTER TABLE `depots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stations`
--
ALTER TABLE `stations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD CONSTRAINT `deliveries_ibfk_1` FOREIGN KEY (`truck_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_notes`
--
ALTER TABLE `delivery_notes`
  ADD CONSTRAINT `delivery_notes_ibfk_1` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_notes_ibfk_2` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
