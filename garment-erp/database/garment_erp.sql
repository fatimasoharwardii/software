-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 15, 2026 at 08:02 AM
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
-- Database: `garment_erp`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `account_name` varchar(100) DEFAULT NULL,
  `account_type` varchar(50) DEFAULT NULL,
  `balance` decimal(12,2) DEFAULT 0.00,
  `company_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `accounts`
--

INSERT INTO `accounts` (`id`, `account_name`, `account_type`, `balance`, `company_id`) VALUES
(1, 'rgdfbsv', 'customer', 2537.00, 1),
(2, 'OSAMA', 'embroidery_vendor', 503167.00, 1),
(3, 'fatimaaaaa', 'handwork', 4167.00, 1),
(4, 'osama', 'embroidery_vendor', 499625.00, 1),
(5, 'bilal', 'stitching_vendor', 0.00, 1),
(6, 'owais', 'master', 5621.00, 1),
(7, 'alisha', 'croping', 240550.00, 1),
(8, 'abid accountent', 'vendor', 116.00, 1),
(9, 'stich', 'stitching_vendor', 1750.00, 1),
(10, 'vv', 'stitching_vendor', 0.00, 1),
(11, 'fahad', 'pressman', 79938.00, 1),
(12, 'zainab', 'material', 16.00, 1),
(13, 'manzoor', 'fabric_supplier', 0.00, 1),
(14, 'tyu', 'stitching_vendor', 75.00, 1),
(15, 'ayesha', 'vendor', 525.00, 1),
(16, 'amir ', 'vendor', 6108.00, 1),
(17, 'aqib', 'vendor', 0.00, 1),
(18, 'htegf', 'customer', -745.00, 1),
(19, 'yrthergefd', 'employee', 56.00, 1),
(20, 'yrthergefd', 'employee', 56.00, 1),
(21, 'yiutfydgfdvsc', 'customer', 56.00, 1);

-- --------------------------------------------------------

--
-- Table structure for table `checking_entries`
--

CREATE TABLE `checking_entries` (
  `id` int(11) NOT NULL,
  `job_no` varchar(50) NOT NULL,
  `vendor_name` varchar(100) DEFAULT NULL,
  `pieces` int(11) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `total_cost` decimal(12,2) DEFAULT NULL,
  `entry_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `claims`
--

CREATE TABLE `claims` (
  `id` int(11) NOT NULL,
  `job_id` int(11) DEFAULT NULL,
  `job_no` varchar(100) DEFAULT NULL,
  `serial_no` varchar(100) DEFAULT NULL,
  `claim_item` varchar(150) DEFAULT NULL,
  `qty` decimal(10,2) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `claim_date` date DEFAULT curdate(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `claim_type` varchar(55) NOT NULL,
  `description` varchar(55) NOT NULL,
  `emp_name` varchar(150) DEFAULT NULL,
  `status` varchar(55) NOT NULL,
  `company_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `embroidery_entries`
--

CREATE TABLE `embroidery_entries` (
  `id` int(11) NOT NULL,
  `entry_date` date DEFAULT curdate(),
  `machine_id` int(11) DEFAULT NULL,
  `machine_no` varchar(50) DEFAULT NULL,
  `shift` enum('day','night') DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `job_no` varchar(100) DEFAULT NULL,
  `design_name` varchar(150) DEFAULT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `vendor_name` varchar(150) DEFAULT NULL,
  `part` varchar(150) DEFAULT NULL,
  `stitch_done` decimal(12,2) DEFAULT 0.00,
  `per_round` int(11) DEFAULT NULL,
  `rounds` decimal(10,2) DEFAULT 0.00,
  `op_rate` decimal(10,2) DEFAULT NULL,
  `operator_id` int(11) DEFAULT NULL,
  `operator_name` varchar(150) DEFAULT NULL,
  `helper_id` int(11) DEFAULT NULL,
  `helper_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `design_no` varchar(150) DEFAULT NULL,
  `machine_bonus` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabric_issue`
--

CREATE TABLE `fabric_issue` (
  `id` int(11) NOT NULL,
  `job_id` int(11) DEFAULT NULL,
  `job_no` varchar(100) DEFAULT NULL,
  `lot_no` varchar(100) DEFAULT NULL,
  `fabric_name` varchar(150) DEFAULT NULL,
  `color` varchar(100) DEFAULT NULL,
  `emb_issue` decimal(10,2) DEFAULT NULL,
  `back_issue` decimal(10,2) DEFAULT NULL,
  `extra_issue` decimal(10,2) DEFAULT NULL,
  `adjust_rate` decimal(10,2) DEFAULT NULL,
  `issue_date` date DEFAULT curdate(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `color_number` decimal(12,2) DEFAULT 1.00,
  `total_meter_with_color` decimal(12,2) DEFAULT NULL,
  `company_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabric_purchase`
--

CREATE TABLE `fabric_purchase` (
  `id` int(11) NOT NULL,
  `party_id` int(11) DEFAULT NULL,
  `party_name` varchar(150) DEFAULT NULL,
  `fabric_name` varchar(150) DEFAULT NULL,
  `color` varchar(100) DEFAULT NULL,
  `lot_no` varchar(100) DEFAULT NULL,
  `bill_no` varchar(100) DEFAULT NULL,
  `challan_no` varchar(100) DEFAULT NULL,
  `bundle_no` varchar(100) DEFAULT NULL,
  `total_meter` decimal(10,2) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `adjust_rate` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `built_num` int(11) NOT NULL,
  `remaining_meter` varchar(55) NOT NULL,
  `used_meter` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sold_meter` decimal(12,2) DEFAULT 0.00,
  `remaining_meter_sold` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fabric_sale`
--

CREATE TABLE `fabric_sale` (
  `id` int(11) NOT NULL,
  `lot_no` varchar(100) NOT NULL,
  `fabric_name` varchar(255) DEFAULT NULL,
  `color` varchar(100) DEFAULT NULL,
  `party_name` varchar(255) NOT NULL,
  `bill_no` varchar(100) DEFAULT NULL,
  `sale_date` date NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `rate` decimal(12,2) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `purchase_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `color_count` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` int(11) NOT NULL,
  `job_no` varchar(100) NOT NULL,
  `serial_no` varchar(50) DEFAULT NULL,
  `design_name` varchar(150) NOT NULL,
  `brand_name` varchar(150) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `fabric_name` varchar(150) DEFAULT NULL,
  `embroidery_rate` decimal(10,2) DEFAULT NULL,
  `embroidery_vendor_id` int(11) DEFAULT NULL,
  `embroidery_vendor_name` varchar(150) DEFAULT NULL,
  `status` varchar(55) DEFAULT 'Pending',
  `delivery_date` date DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `manual_embroidery_rate` decimal(12,2) DEFAULT 0.00,
  `manual_fabric_cost` decimal(12,2) DEFAULT 0.00,
  `use_manual_costing` tinyint(4) DEFAULT 0,
  `cmt_party` varchar(255) DEFAULT NULL,
  `job_date` date DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `company_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jobs`
--

INSERT INTO `jobs` (`id`, `job_no`, `serial_no`, `design_name`, `brand_name`, `size`, `quantity`, `fabric_name`, `embroidery_rate`, `embroidery_vendor_id`, `embroidery_vendor_name`, `status`, `delivery_date`, `image`, `created_at`, `manual_embroidery_rate`, `manual_fabric_cost`, `use_manual_costing`, `cmt_party`, `job_date`, `updated_at`, `company_id`) VALUES
(26, '1', NULL, 'ZF 212', 'zohra\'s fashion', '3 pieces', 180, 'printed lawn', 312.00, NULL, 'y.s emb', 'Embroidery', NULL, '1775389567_20240216_082310_0000.jpg', '2026-04-05 11:39:54', 0.00, 0.00, 0, '', '2026-04-05', '2026-04-05 13:46:07', 1);

-- --------------------------------------------------------

--
-- Table structure for table `ledger`
--

CREATE TABLE `ledger` (
  `id` int(11) NOT NULL,
  `entry_date` date DEFAULT curdate(),
  `party_id` int(11) DEFAULT NULL,
  `party_name` varchar(150) DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `job_no` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `debit` decimal(12,2) DEFAULT 0.00,
  `credit` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ledger_transactions`
--

CREATE TABLE `ledger_transactions` (
  `id` int(11) NOT NULL,
  `date` date DEFAULT NULL,
  `from_account` varchar(55) DEFAULT NULL,
  `to_account` varchar(55) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `transaction_type` varchar(55) NOT NULL,
  `created_at` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `machines`
--

CREATE TABLE `machines` (
  `id` int(11) NOT NULL,
  `machine_no` varchar(50) NOT NULL,
  `day_operator_id` int(11) DEFAULT NULL,
  `day_operator_name` varchar(150) DEFAULT NULL,
  `night_operator_id` int(11) DEFAULT NULL,
  `night_operator_name` varchar(150) DEFAULT NULL,
  `day_helper_id` int(11) DEFAULT NULL,
  `day_helper_name` varchar(150) DEFAULT NULL,
  `night_helper_id` int(11) DEFAULT NULL,
  `night_helper_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `day_operator_rate` int(11) NOT NULL,
  `night_operator_rate` int(11) NOT NULL,
  `head` int(11) NOT NULL,
  `machine_rate` decimal(10,2) DEFAULT NULL,
  `bonus_stitch_1` int(11) DEFAULT 0,
  `bonus_amount_1` decimal(10,2) DEFAULT 0.00,
  `bonus_stitch_2` int(11) DEFAULT 0,
  `bonus_amount_2` decimal(10,2) DEFAULT 0.00,
  `bonus_stitch_3` int(11) DEFAULT 0,
  `bonus_amount_3` decimal(10,2) DEFAULT 0.00,
  `bonus_stitch_4` int(11) DEFAULT 0,
  `bonus_amount_4` decimal(10,2) DEFAULT 0.00,
  `bonus_stitch_5` int(11) DEFAULT 0,
  `bonus_amount_5` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `manual_costing`
--

CREATE TABLE `manual_costing` (
  `id` int(11) NOT NULL,
  `job_no` varchar(50) DEFAULT NULL,
  `cost_type` varchar(50) DEFAULT NULL,
  `manual_rate` decimal(12,2) DEFAULT 0.00,
  `auto_rate` decimal(12,2) DEFAULT 0.00,
  `difference` decimal(12,2) DEFAULT 0.00,
  `is_edited` tinyint(4) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parties`
--

CREATE TABLE `parties` (
  `id` int(11) NOT NULL,
  `party_name` varchar(150) NOT NULL,
  `party_type` varchar(100) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `place` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salaries`
--

CREATE TABLE `salaries` (
  `id` int(11) NOT NULL,
  `party_id` int(11) DEFAULT NULL,
  `party_name` varchar(150) DEFAULT NULL,
  `role_type` enum('operator','helper') DEFAULT NULL,
  `month` varchar(20) DEFAULT NULL,
  `total_stitches` int(11) DEFAULT NULL,
  `total_days` int(11) DEFAULT NULL,
  `sunday_stitches` int(11) DEFAULT NULL,
  `bonus` decimal(12,2) DEFAULT NULL,
  `allowance` decimal(12,2) DEFAULT NULL,
  `total_salary` decimal(12,2) DEFAULT NULL,
  `status` enum('pending','posted') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `operator_name` varchar(55) NOT NULL,
  `sunday_count` int(11) NOT NULL,
  `rate_per_1000` int(11) NOT NULL,
  `base_salary` int(11) NOT NULL,
  `sunday_bonus` int(11) NOT NULL,
  `attendance_bonus` int(11) NOT NULL,
  `attendance_percentage` int(11) NOT NULL,
  `machine_bonus` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stitching_bill_items`
--

CREATE TABLE `stitching_bill_items` (
  `id` int(11) NOT NULL,
  `job_no` varchar(50) DEFAULT NULL,
  `tab_type` varchar(50) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `party_name` varchar(255) DEFAULT NULL,
  `lot_no` varchar(50) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `qty` decimal(10,2) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `kurti_qty` decimal(10,2) DEFAULT NULL,
  `kurti_rate` decimal(10,2) DEFAULT NULL,
  `shalwar_qty` decimal(10,2) DEFAULT NULL,
  `shalwar_rate` decimal(10,2) DEFAULT NULL,
  `dupatta_qty` decimal(10,2) DEFAULT NULL,
  `dupatta_rate` decimal(10,2) DEFAULT NULL,
  `part_name` varchar(100) DEFAULT NULL,
  `stitch` decimal(10,2) DEFAULT NULL,
  `round_qty` decimal(10,2) DEFAULT NULL,
  `sub_total` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `emb_issue` int(11) DEFAULT NULL,
  `back_issue` int(11) DEFAULT NULL,
  `extra_issue` int(11) DEFAULT NULL,
  `adjust_rate` decimal(10,2) DEFAULT NULL,
  `department` varchar(55) NOT NULL,
  `manual_cost` decimal(12,2) DEFAULT 0.00,
  `auto_cost` decimal(12,2) DEFAULT 0.00,
  `cost_difference` decimal(12,2) DEFAULT 0.00,
  `use_manual_cost` tinyint(4) DEFAULT 0,
  `head` int(11) NOT NULL,
  `billed_quantity` int(11) DEFAULT 0,
  `remaining_quantity` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stitching_entries`
--

CREATE TABLE `stitching_entries` (
  `id` int(11) NOT NULL,
  `job_id` int(11) DEFAULT NULL,
  `job_no` varchar(100) DEFAULT NULL,
  `depart_name` varchar(150) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `name` varchar(150) DEFAULT NULL,
  `kurti_qty` int(11) DEFAULT NULL,
  `kurti_rate` decimal(10,2) DEFAULT NULL,
  `shalwar_qty` int(11) DEFAULT NULL,
  `shalwar_rate` decimal(10,2) DEFAULT NULL,
  `dupatta_qty` int(11) DEFAULT NULL,
  `dupatta_rate` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stitching_posted_bills`
--

CREATE TABLE `stitching_posted_bills` (
  `id` int(11) NOT NULL,
  `job_no` varchar(50) DEFAULT NULL,
  `serial_no` varchar(50) DEFAULT NULL,
  `emp_name` varchar(100) DEFAULT NULL,
  `claim_item` varchar(255) DEFAULT NULL,
  `qty` decimal(10,2) DEFAULT NULL,
  `rate` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(12,2) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `claim_type` varchar(50) DEFAULT NULL,
  `claim_date` date DEFAULT NULL,
  `design_name` varchar(255) DEFAULT NULL,
  `fabric_name` varchar(255) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `brand_name` varchar(255) DEFAULT NULL,
  `post_date` date DEFAULT NULL,
  `status` varchar(50) DEFAULT 'un_Posted',
  `manual_total` decimal(12,2) DEFAULT 0.00,
  `auto_total` decimal(12,2) DEFAULT 0.00,
  `difference_total` decimal(12,2) DEFAULT 0.00,
  `head` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stitching_posted_bills`
--

INSERT INTO `stitching_posted_bills` (`id`, `job_no`, `serial_no`, `emp_name`, `claim_item`, `qty`, `rate`, `total_amount`, `description`, `claim_type`, `claim_date`, `design_name`, `fabric_name`, `size`, `brand_name`, `post_date`, `status`, `manual_total`, `auto_total`, `difference_total`, `head`) VALUES
(142, 'PURCHASE-17', 'FP-20260405-593', 'manzoor', 'Fabric Purchase', 12543.00, 390.00, 4891770.00, 'Fabric Purchase - Party: manzoor, Fabric: printed lawn, Lot: LOT-001, Meter: 12543', 'fabric_purchase', '2026-04-05', NULL, 'printed lawn', NULL, NULL, NULL, 'pending', 0.00, 0.00, 0.00, 0);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `password`, `role`, `status`, `created_at`) VALUES
(1, 'fatima', 'fatima', 'b5d5f67b30809413156655abdda382a3', 'admin', 'active', '2026-03-01 16:25:40'),
(2, 'abid', 'abid', 'abid', 'admin', 'active', '2026-03-03 06:38:45'),
(3, '', '', '', 'admin', 'active', '2026-03-03 06:38:45');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `checking_entries`
--
ALTER TABLE `checking_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_no` (`job_no`);

--
-- Indexes for table `claims`
--
ALTER TABLE `claims`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_id` (`job_id`);

--
-- Indexes for table `embroidery_entries`
--
ALTER TABLE `embroidery_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `machine_id` (`machine_id`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `vendor_id` (`vendor_id`);

--
-- Indexes for table `fabric_issue`
--
ALTER TABLE `fabric_issue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_id` (`job_id`);

--
-- Indexes for table `fabric_purchase`
--
ALTER TABLE `fabric_purchase`
  ADD PRIMARY KEY (`id`),
  ADD KEY `party_id` (`party_id`);

--
-- Indexes for table `fabric_sale`
--
ALTER TABLE `fabric_sale`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `job_no` (`job_no`),
  ADD KEY `embroidery_vendor_id` (`embroidery_vendor_id`);

--
-- Indexes for table `ledger`
--
ALTER TABLE `ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `party_id` (`party_id`),
  ADD KEY `job_id` (`job_id`);

--
-- Indexes for table `ledger_transactions`
--
ALTER TABLE `ledger_transactions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `machines`
--
ALTER TABLE `machines`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `manual_costing`
--
ALTER TABLE `manual_costing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_job_type` (`job_no`,`cost_type`);

--
-- Indexes for table `parties`
--
ALTER TABLE `parties`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `salaries`
--
ALTER TABLE `salaries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `party_id` (`party_id`);

--
-- Indexes for table `stitching_bill_items`
--
ALTER TABLE `stitching_bill_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `stitching_entries`
--
ALTER TABLE `stitching_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `party_id` (`party_id`);

--
-- Indexes for table `stitching_posted_bills`
--
ALTER TABLE `stitching_posted_bills`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `checking_entries`
--
ALTER TABLE `checking_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `claims`
--
ALTER TABLE `claims`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `embroidery_entries`
--
ALTER TABLE `embroidery_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `fabric_issue`
--
ALTER TABLE `fabric_issue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `fabric_purchase`
--
ALTER TABLE `fabric_purchase`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `fabric_sale`
--
ALTER TABLE `fabric_sale`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `ledger`
--
ALTER TABLE `ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ledger_transactions`
--
ALTER TABLE `ledger_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

--
-- AUTO_INCREMENT for table `machines`
--
ALTER TABLE `machines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `manual_costing`
--
ALTER TABLE `manual_costing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `parties`
--
ALTER TABLE `parties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `salaries`
--
ALTER TABLE `salaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `stitching_bill_items`
--
ALTER TABLE `stitching_bill_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- AUTO_INCREMENT for table `stitching_entries`
--
ALTER TABLE `stitching_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stitching_posted_bills`
--
ALTER TABLE `stitching_posted_bills`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=143;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `checking_entries`
--
ALTER TABLE `checking_entries`
  ADD CONSTRAINT `checking_entries_ibfk_1` FOREIGN KEY (`job_no`) REFERENCES `jobs` (`job_no`);

--
-- Constraints for table `claims`
--
ALTER TABLE `claims`
  ADD CONSTRAINT `claims_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`);

--
-- Constraints for table `embroidery_entries`
--
ALTER TABLE `embroidery_entries`
  ADD CONSTRAINT `embroidery_entries_ibfk_1` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`),
  ADD CONSTRAINT `embroidery_entries_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`),
  ADD CONSTRAINT `embroidery_entries_ibfk_3` FOREIGN KEY (`vendor_id`) REFERENCES `parties` (`id`);

--
-- Constraints for table `fabric_issue`
--
ALTER TABLE `fabric_issue`
  ADD CONSTRAINT `fabric_issue_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`);

--
-- Constraints for table `fabric_purchase`
--
ALTER TABLE `fabric_purchase`
  ADD CONSTRAINT `fabric_purchase_ibfk_1` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`);

--
-- Constraints for table `fabric_sale`
--
ALTER TABLE `fabric_sale`
  ADD CONSTRAINT `fabric_sale_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `fabric_purchase` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `jobs`
--
ALTER TABLE `jobs`
  ADD CONSTRAINT `jobs_ibfk_1` FOREIGN KEY (`embroidery_vendor_id`) REFERENCES `parties` (`id`);

--
-- Constraints for table `ledger`
--
ALTER TABLE `ledger`
  ADD CONSTRAINT `ledger_ibfk_1` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`),
  ADD CONSTRAINT `ledger_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`);

--
-- Constraints for table `salaries`
--
ALTER TABLE `salaries`
  ADD CONSTRAINT `salaries_ibfk_1` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`);

--
-- Constraints for table `stitching_entries`
--
ALTER TABLE `stitching_entries`
  ADD CONSTRAINT `stitching_entries_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`),
  ADD CONSTRAINT `stitching_entries_ibfk_2` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
