-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 03, 2025 at 03:49 AM
-- Server version: 10.4.27-MariaDB
-- PHP Version: 8.2.0

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_booking`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_auto_checkout_expired_bookings` ()   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE booking_id INT;
    DECLARE booking_name VARCHAR(200);
    DECLARE room_name VARCHAR(100);
    DECLARE user_email VARCHAR(100);
    
    DECLARE cur CURSOR FOR 
        SELECT b.id_booking, b.nama_acara, r.nama_ruang, u.email
        FROM tbl_booking b
        JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
        JOIN tbl_users u ON b.id_user = u.id_user
        WHERE b.status = 'active' 
        AND (
            (b.tanggal < CURDATE()) OR 
            (b.tanggal = CURDATE() AND b.jam_selesai < CURTIME())
        );
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur;
    
    read_loop: LOOP
        FETCH cur INTO booking_id, booking_name, room_name, user_email;
        
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Update booking to done status with auto-checkout
        UPDATE tbl_booking 
        SET status = 'done',
            checkout_status = 'auto_completed',
            checkout_time = NOW(),
            checked_out_by = 'SYSTEM_AUTO',
            completion_note = 'Ruangan selesai dipakai tanpa checkout manual dari mahasiswa'
        WHERE id_booking = booking_id;
        
        -- Log the auto-checkout
        INSERT INTO tbl_room_availability_log (id_ruang, date, start_time, end_time, reason, original_booking_id)
        SELECT id_ruang, tanggal, jam_mulai, jam_selesai, 'completion', id_booking
        FROM tbl_booking 
        WHERE id_booking = booking_id;
        
    END LOOP;
    
    CLOSE cur;
    
    SELECT ROW_COUNT() as auto_checkout_count;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_booking`
--

CREATE TABLE `tbl_booking` (
  `id_booking` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  `id_ruang` int(11) NOT NULL,
  `nama_acara` varchar(200) NOT NULL,
  `tanggal` date NOT NULL,
  `jam_mulai` time NOT NULL,
  `jam_selesai` time NOT NULL,
  `keterangan` text NOT NULL,
  `nama_penanggungjawab` varchar(100) NOT NULL,
  `no_penanggungjawab` int(15) NOT NULL,
  `status` enum('pending','approve','rejected','cancelled','active','done') NOT NULL DEFAULT 'pending',
  `checked_out_by` varchar(50) DEFAULT NULL,
  `checkout_status` enum('pending','manual_checkout','auto_completed','force_checkout') NOT NULL DEFAULT 'pending',
  `checkout_time` datetime DEFAULT NULL,
  `completion_note` varchar(255) DEFAULT NULL,
  `is_external` tinyint(1) NOT NULL DEFAULT 0,
  `user_can_activate` tinyint(1) DEFAULT 0 COMMENT 'User dapat mengaktifkan booking tanpa persetujuan admin',
  `activated_by_user` tinyint(1) DEFAULT 0 COMMENT 'Booking diaktifkan oleh user',
  `activated_at` datetime DEFAULT NULL,
  `activated_by` varchar(50) DEFAULT NULL,
  `activation_note` text DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(50) DEFAULT NULL,
  `approval_reason` text DEFAULT NULL,
  `cancelled_by` varchar(50) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `auto_approved` tinyint(1) DEFAULT 0 COMMENT 'Was this booking auto-approved?',
  `auto_approval_reason` varchar(255) DEFAULT NULL COMMENT 'Reason for auto-approval',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `id_recurring_schedule` int(11) DEFAULT NULL COMMENT 'Jika booking ini dari jadwal kuliah berulang',
  `booking_type` enum('manual','recurring','external') DEFAULT 'manual' COMMENT 'Jenis booking'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_booking`
--

INSERT INTO `tbl_booking` (`id_booking`, `id_user`, `id_ruang`, `nama_acara`, `tanggal`, `jam_mulai`, `jam_selesai`, `keterangan`, `nama_penanggungjawab`, `no_penanggungjawab`, `status`, `checked_out_by`, `checkout_status`, `checkout_time`, `completion_note`, `is_external`, `user_can_activate`, `activated_by_user`, `activated_at`, `activated_by`, `activation_note`, `approved_at`, `approved_by`, `approval_reason`, `cancelled_by`, `cancelled_at`, `cancellation_reason`, `auto_approved`, `auto_approval_reason`, `created_at`, `id_recurring_schedule`, `booking_type`) VALUES
(1, 1, 1, 'tentir', '2025-05-22', '09:00:00', '12:00:00', 'tentir rutin ukm wappim', 'kikan', 2147483647, 'cancelled', NULL, 'pending', NULL, NULL, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual'),
(2, 1, 1, 'rapat', '2025-05-22', '12:00:00', '14:00:00', 'rapat ukm', 'kikan', 12933823, 'done', 'SYSTEM_AUTO', '', '2025-05-30 11:53:28', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual'),
(3, 1, 2, 'rapat', '2025-05-22', '10:00:00', '11:00:00', 'rapat ukm', 'kikan', 12345, '', NULL, 'pending', NULL, NULL, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual'),
(4, 1, 1, 'tentir', '2025-05-23', '15:30:00', '16:30:00', 'ukm wappim', 'kikan', 12345654, 'done', 'SYSTEM_AUTO', '', '2025-05-30 11:53:28', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual'),
(5, 2, 1, 'seminar', '2025-05-26', '12:30:00', '13:00:00', 'seminar satgas', 'pak agus', 85363723, 'done', 'SYSTEM_AUTO', '', '2025-05-30 11:53:28', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual'),
(6, 1, 1, 'tentir', '2025-05-26', '13:00:00', '14:00:00', 'ukm', 'kina', 3374, 'done', 'SYSTEM_AUTO', '', '2025-05-30 11:53:28', 'Booking expired - Disetujui tapi tidak diaktifkan', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual'),
(7, 1, 2, 'rapat', '2025-05-27', '08:00:00', '09:00:00', 'rapat ukm', 'kina', 846383, 'done', 'SYSTEM_AUTO', '', '2025-05-28 20:59:52', 'Ruangan selesai dipakai tanpa checkout dari mahasiswa', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:13:25', NULL, 'manual'),
(8, 1, 1, 'rapat', '2025-05-30', '12:00:00', '13:00:00', 'rapat ukm wappim', 'ayasha', 547834875, 'done', 'SYSTEM_AUTO', '', '2025-05-31 17:09:09', 'Ruangan selesai dipakai tanpa checkout dari mahasiswa', 0, 0, 0, '2025-05-30 12:03:14', 'SYSTEM_AUTO', 'Auto-activated: Waktu booking telah tiba', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-30 04:14:30', NULL, 'manual'),
(9, 1, 1, 'Demo Checkout Manual', '2025-05-30', '10:00:00', '11:00:00', 'Demo booking dengan checkout manual', 'Demo User', 2147483647, 'done', 'USER_MANUAL', 'manual_checkout', '2025-05-30 11:00:00', 'Ruangan sudah selesai dipakai dengan checkout mahasiswa', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-31 16:20:12', NULL, 'manual'),
(10, 1, 2, 'Demo Auto Checkout', '2025-05-30', '14:00:00', '15:00:00', 'Demo booking dengan auto checkout', 'Demo User', 2147483647, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-05-30 15:05:00', 'Ruangan selesai dipakai tanpa checkout manual dari mahasiswa', 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-05-31 16:20:12', NULL, 'manual'),
(11, 2, 1, 'diesn', '2025-06-01', '08:30:00', '09:30:00', 'diesnatalis ukm kesenian', 'kinoy', 847549374, 'rejected', NULL, 'pending', NULL, NULL, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'salah input', 0, NULL, '2025-06-01 01:09:33', NULL, 'manual'),
(12, 1, 1, 'rapat', '2025-06-02', '15:30:00', '16:30:00', 'ukm wappim', 'ayasha', 2147483647, 'done', 'SYSTEM_AUTO', 'auto_completed', '2025-06-02 17:32:30', 'Ruangan selesai dipakai tanpa checkout manual dari mahasiswa', 0, 0, 0, '2025-06-02 15:30:04', 'SYSTEM_AUTO', 'Auto-activated: Waktu booking telah tiba', '2025-06-02 15:15:36', 'admin@stie-mce.ac.id', NULL, NULL, NULL, NULL, 0, NULL, '2025-06-02 08:13:50', NULL, 'manual'),
(13, 1, 3, 'tentir', '2025-06-03', '12:00:00', '13:00:00', 'tentir ki', 'kina', 99587485, 'pending', NULL, 'pending', NULL, NULL, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2025-06-03 01:39:21', NULL, 'manual');

--
-- Triggers `tbl_booking`
--
DELIMITER $$
CREATE TRIGGER `tr_booking_status_change` AFTER UPDATE ON `tbl_booking` FOR EACH ROW BEGIN
    -- Log when booking is cancelled or completed
    IF (OLD.status != NEW.status) AND NEW.status IN ('cancelled', 'done') THEN
        INSERT INTO tbl_room_availability_log (
            id_ruang, 
            date, 
            start_time, 
            end_time, 
            reason, 
            original_booking_id
        ) VALUES (
            NEW.id_ruang,
            NEW.tanggal,
            NEW.jam_mulai,
            NEW.jam_selesai,
            CASE 
                WHEN NEW.status = 'cancelled' THEN 'cancellation'
                WHEN NEW.status = 'done' THEN 'completion'
                ELSE 'completion'
            END,
            NEW.id_booking
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_booking_notifications`
--

CREATE TABLE `tbl_booking_notifications` (
  `id_notification` int(11) NOT NULL,
  `id_booking` int(11) NOT NULL,
  `recipient_email` varchar(100) NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_status` enum('pending','sent','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_gedung`
--

CREATE TABLE `tbl_gedung` (
  `id_gedung` int(11) NOT NULL,
  `nama_gedung` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_gedung`
--

INSERT INTO `tbl_gedung` (`id_gedung`, `nama_gedung`) VALUES
(1, 'Gedung K'),
(2, 'Gedung L'),
(3, 'Gedung M');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_harilibur`
--

CREATE TABLE `tbl_harilibur` (
  `tanggal` date NOT NULL,
  `keterangan` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_harilibur`
--

INSERT INTO `tbl_harilibur` (`tanggal`, `keterangan`) VALUES
('2025-06-06', 'Hari Raya Idul Adha');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_recurring_schedules`
--

CREATE TABLE `tbl_recurring_schedules` (
  `id_schedule` int(11) NOT NULL,
  `id_ruang` int(11) NOT NULL,
  `nama_matakuliah` varchar(200) NOT NULL,
  `kelas` varchar(50) NOT NULL,
  `dosen_pengampu` varchar(200) NOT NULL,
  `hari` enum('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
  `jam_mulai` time NOT NULL,
  `jam_selesai` time NOT NULL,
  `semester` varchar(20) NOT NULL,
  `tahun_akademik` varchar(20) NOT NULL,
  `start_date` date NOT NULL COMMENT 'Tanggal mulai perkuliahan',
  `end_date` date NOT NULL COMMENT 'Tanggal selesai perkuliahan',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_recurring_schedules`
--

INSERT INTO `tbl_recurring_schedules` (`id_schedule`, `id_ruang`, `nama_matakuliah`, `kelas`, `dosen_pengampu`, `hari`, `jam_mulai`, `jam_selesai`, `semester`, `tahun_akademik`, `start_date`, `end_date`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 3, 'MACRO ECONOMICS', 'B', 'Pak Didik', 'monday', '12:00:00', '14:30:00', 'Genap', '2024/2025', '2025-03-01', '2025-09-30', 'active', 2, '2025-06-02 14:39:25', '2025-06-02 14:39:25');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_room_availability_log`
--

CREATE TABLE `tbl_room_availability_log` (
  `id_log` int(11) NOT NULL,
  `id_ruang` int(11) NOT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `became_available_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reason` enum('cancellation','completion','rejection') NOT NULL,
  `original_booking_id` int(11) DEFAULT NULL,
  `notified_users` text DEFAULT NULL COMMENT 'JSON array of notified user IDs'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_room_availability_log`
--

INSERT INTO `tbl_room_availability_log` (`id_log`, `id_ruang`, `date`, `start_time`, `end_time`, `became_available_at`, `reason`, `original_booking_id`, `notified_users`) VALUES
(1, 1, '2025-06-02', '15:30:00', '16:30:00', '2025-06-02 10:32:30', 'completion', 12, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_room_locks`
--

CREATE TABLE `tbl_room_locks` (
  `id` int(11) NOT NULL,
  `id_ruang` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` varchar(255) NOT NULL,
  `locked_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'active',
  `unlocked_by` int(11) DEFAULT NULL,
  `unlocked_at` timestamp NULL DEFAULT NULL,
  `unlock_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_room_locks`
--

INSERT INTO `tbl_room_locks` (`id`, `id_ruang`, `start_date`, `end_date`, `reason`, `locked_by`, `created_at`, `status`, `unlocked_by`, `unlocked_at`, `unlock_reason`) VALUES
(1, 2, '2025-05-23', '2025-05-24', 'UTS', 2, '2025-05-23 09:12:18', 'unlocked', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_ruang`
--

CREATE TABLE `tbl_ruang` (
  `id_ruang` int(11) NOT NULL,
  `id_gedung` int(11) NOT NULL,
  `nama_ruang` varchar(100) NOT NULL,
  `kapasitas` int(11) NOT NULL,
  `lokasi` varchar(50) NOT NULL,
  `fasilitas` text DEFAULT NULL COMMENT 'Daftar fasilitas ruangan',
  `allowed_roles` set('admin','mahasiswa','dosen','karyawan') DEFAULT 'admin,mahasiswa,dosen,karyawan' COMMENT 'Role yang boleh booking ruangan ini',
  `description` text DEFAULT NULL COMMENT 'Deskripsi ruangan'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_ruang`
--

INSERT INTO `tbl_ruang` (`id_ruang`, `id_gedung`, `nama_ruang`, `kapasitas`, `lokasi`, `fasilitas`, `allowed_roles`, `description`) VALUES
(1, 1, 'K-1', 40, 'Lantai 1', '[\"AC\",\"Proyektor\",\"WiFi\"]', 'admin,mahasiswa,dosen,karyawan', ''),
(2, 3, 'M1-8', 40, 'Lantai 1', NULL, 'admin,mahasiswa,dosen,karyawan', NULL),
(3, 1, 'K-4', 40, 'Lantai 2', '[\"AC\",\"Whiteboard\",\"Meja\",\"LCD TV\"]', 'mahasiswa,dosen,karyawan', ''),
(4, 3, 'M-1', 40, 'Lantai 1', '[\"AC\",\"WiFi\",\"LCD TV\"]', 'admin,mahasiswa,dosen,karyawan', '');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_schedule_exceptions`
--

CREATE TABLE `tbl_schedule_exceptions` (
  `id_exception` int(11) NOT NULL,
  `id_schedule` int(11) NOT NULL,
  `exception_date` date NOT NULL,
  `exception_type` enum('holiday','cancelled','moved','special') NOT NULL,
  `new_date` date DEFAULT NULL COMMENT 'Jika jadwal dipindah ke tanggal lain',
  `new_time_start` time DEFAULT NULL,
  `new_time_end` time DEFAULT NULL,
  `new_room_id` int(11) DEFAULT NULL,
  `reason` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_users`
--

CREATE TABLE `tbl_users` (
  `id_user` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` int(8) NOT NULL,
  `role` enum('admin','mahasiswa','dosen','karyawan','cs','satpam') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_users`
--

INSERT INTO `tbl_users` (`id_user`, `email`, `password`, `role`) VALUES
(1, '36288@mhs.stie-mce.ac.id', 20051029, 'mahasiswa'),
(2, 'admin@stie-mce.ac.id', 12345678, 'admin'),
(3, 'cs@stie-mce.ac.id', 12345678, 'cs'),
(4, 'satpam@stie-mce.ac.id', 12345678, 'satpam');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_user_preferences`
--

CREATE TABLE `tbl_user_preferences` (
  `id_preference` int(11) NOT NULL,
  `id_user` int(11) NOT NULL,
  `preferred_rooms` text DEFAULT NULL COMMENT 'JSON array of preferred room IDs',
  `notification_preferences` text DEFAULT NULL COMMENT 'JSON object with notification settings',
  `waitlist_slots` text DEFAULT NULL COMMENT 'JSON array of desired time slots',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_booking_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_booking_summary` (
`id_booking` int(11)
,`nama_acara` varchar(200)
,`tanggal` date
,`jam_mulai` time
,`jam_selesai` time
,`status` enum('pending','approve','rejected','cancelled','active','done')
,`checkout_status` enum('pending','manual_checkout','auto_completed','force_checkout')
,`email` varchar(100)
,`user_role` enum('admin','mahasiswa','dosen','karyawan','cs','satpam')
,`nama_ruang` varchar(100)
,`nama_gedung` varchar(100)
,`slot_available` int(1)
,`checkout_description` varchar(30)
);

-- --------------------------------------------------------

--
-- Structure for view `v_booking_summary`
--
DROP TABLE IF EXISTS `v_booking_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_booking_summary`  AS SELECT `b`.`id_booking` AS `id_booking`, `b`.`nama_acara` AS `nama_acara`, `b`.`tanggal` AS `tanggal`, `b`.`jam_mulai` AS `jam_mulai`, `b`.`jam_selesai` AS `jam_selesai`, `b`.`status` AS `status`, `b`.`checkout_status` AS `checkout_status`, `u`.`email` AS `email`, `u`.`role` AS `user_role`, `r`.`nama_ruang` AS `nama_ruang`, `g`.`nama_gedung` AS `nama_gedung`, CASE WHEN `b`.`status` = 'done' THEN 1 WHEN `b`.`status` = 'cancelled' THEN 1 WHEN `b`.`status` = 'rejected' THEN 1 ELSE 0 END AS `slot_available`, CASE WHEN `b`.`checkout_status` = 'manual_checkout' THEN 'Manual Checkout oleh Mahasiswa' WHEN `b`.`checkout_status` = 'auto_completed' THEN 'Auto-Completed oleh Sistem' WHEN `b`.`checkout_status` = 'force_checkout' THEN 'Force Checkout oleh Admin' ELSE 'Belum Checkout' END AS `checkout_description` FROM (((`tbl_booking` `b` join `tbl_users` `u` on(`b`.`id_user` = `u`.`id_user`)) join `tbl_ruang` `r` on(`b`.`id_ruang` = `r`.`id_ruang`)) left join `tbl_gedung` `g` on(`r`.`id_gedung` = `g`.`id_gedung`))  ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tbl_booking`
--
ALTER TABLE `tbl_booking`
  ADD PRIMARY KEY (`id_booking`),
  ADD KEY `id_user` (`id_user`),
  ADD KEY `id_ruang` (`id_ruang`),
  ADD KEY `id_recurring_schedule` (`id_recurring_schedule`),
  ADD KEY `idx_booking_status_date` (`status`,`tanggal`),
  ADD KEY `idx_booking_room_date` (`id_ruang`,`tanggal`),
  ADD KEY `idx_booking_checkout_status` (`checkout_status`),
  ADD KEY `idx_booking_user_status` (`id_user`,`status`);

--
-- Indexes for table `tbl_booking_notifications`
--
ALTER TABLE `tbl_booking_notifications`
  ADD PRIMARY KEY (`id_notification`),
  ADD KEY `idx_booking_notifications_booking` (`id_booking`),
  ADD KEY `idx_booking_notifications_email` (`recipient_email`),
  ADD KEY `idx_booking_notifications_type` (`notification_type`);

--
-- Indexes for table `tbl_gedung`
--
ALTER TABLE `tbl_gedung`
  ADD PRIMARY KEY (`id_gedung`);

--
-- Indexes for table `tbl_harilibur`
--
ALTER TABLE `tbl_harilibur`
  ADD PRIMARY KEY (`tanggal`);

--
-- Indexes for table `tbl_recurring_schedules`
--
ALTER TABLE `tbl_recurring_schedules`
  ADD PRIMARY KEY (`id_schedule`),
  ADD KEY `id_ruang` (`id_ruang`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `tbl_room_availability_log`
--
ALTER TABLE `tbl_room_availability_log`
  ADD PRIMARY KEY (`id_log`),
  ADD KEY `idx_room_availability_room_date` (`id_ruang`,`date`),
  ADD KEY `idx_room_availability_time` (`date`,`start_time`,`end_time`);

--
-- Indexes for table `tbl_room_locks`
--
ALTER TABLE `tbl_room_locks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_ruang` (`id_ruang`),
  ADD KEY `locked_by` (`locked_by`);

--
-- Indexes for table `tbl_ruang`
--
ALTER TABLE `tbl_ruang`
  ADD PRIMARY KEY (`id_ruang`),
  ADD KEY `id_gedung` (`id_gedung`);

--
-- Indexes for table `tbl_schedule_exceptions`
--
ALTER TABLE `tbl_schedule_exceptions`
  ADD PRIMARY KEY (`id_exception`),
  ADD KEY `id_schedule` (`id_schedule`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `tbl_users`
--
ALTER TABLE `tbl_users`
  ADD PRIMARY KEY (`id_user`);

--
-- Indexes for table `tbl_user_preferences`
--
ALTER TABLE `tbl_user_preferences`
  ADD PRIMARY KEY (`id_preference`),
  ADD UNIQUE KEY `idx_user_preferences_user` (`id_user`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbl_booking`
--
ALTER TABLE `tbl_booking`
  MODIFY `id_booking` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `tbl_booking_notifications`
--
ALTER TABLE `tbl_booking_notifications`
  MODIFY `id_notification` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_gedung`
--
ALTER TABLE `tbl_gedung`
  MODIFY `id_gedung` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_recurring_schedules`
--
ALTER TABLE `tbl_recurring_schedules`
  MODIFY `id_schedule` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_room_availability_log`
--
ALTER TABLE `tbl_room_availability_log`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_room_locks`
--
ALTER TABLE `tbl_room_locks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_ruang`
--
ALTER TABLE `tbl_ruang`
  MODIFY `id_ruang` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_schedule_exceptions`
--
ALTER TABLE `tbl_schedule_exceptions`
  MODIFY `id_exception` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_users`
--
ALTER TABLE `tbl_users`
  MODIFY `id_user` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tbl_user_preferences`
--
ALTER TABLE `tbl_user_preferences`
  MODIFY `id_preference` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tbl_booking`
--
ALTER TABLE `tbl_booking`
  ADD CONSTRAINT `tbl_booking_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `tbl_users` (`id_user`),
  ADD CONSTRAINT `tbl_booking_ibfk_2` FOREIGN KEY (`id_ruang`) REFERENCES `tbl_ruang` (`id_ruang`);

--
-- Constraints for table `tbl_booking_notifications`
--
ALTER TABLE `tbl_booking_notifications`
  ADD CONSTRAINT `fk_booking_notifications_booking` FOREIGN KEY (`id_booking`) REFERENCES `tbl_booking` (`id_booking`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_recurring_schedules`
--
ALTER TABLE `tbl_recurring_schedules`
  ADD CONSTRAINT `tbl_recurring_schedules_ibfk_1` FOREIGN KEY (`id_ruang`) REFERENCES `tbl_ruang` (`id_ruang`),
  ADD CONSTRAINT `tbl_recurring_schedules_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `tbl_users` (`id_user`);

--
-- Constraints for table `tbl_room_availability_log`
--
ALTER TABLE `tbl_room_availability_log`
  ADD CONSTRAINT `fk_room_availability_room` FOREIGN KEY (`id_ruang`) REFERENCES `tbl_ruang` (`id_ruang`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_room_locks`
--
ALTER TABLE `tbl_room_locks`
  ADD CONSTRAINT `tbl_room_locks_ibfk_1` FOREIGN KEY (`id_ruang`) REFERENCES `tbl_ruang` (`id_ruang`),
  ADD CONSTRAINT `tbl_room_locks_ibfk_2` FOREIGN KEY (`locked_by`) REFERENCES `tbl_users` (`id_user`);

--
-- Constraints for table `tbl_ruang`
--
ALTER TABLE `tbl_ruang`
  ADD CONSTRAINT `tbl_ruang_ibfk_1` FOREIGN KEY (`id_gedung`) REFERENCES `tbl_gedung` (`id_gedung`);

--
-- Constraints for table `tbl_schedule_exceptions`
--
ALTER TABLE `tbl_schedule_exceptions`
  ADD CONSTRAINT `tbl_schedule_exceptions_ibfk_1` FOREIGN KEY (`id_schedule`) REFERENCES `tbl_recurring_schedules` (`id_schedule`),
  ADD CONSTRAINT `tbl_schedule_exceptions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `tbl_users` (`id_user`);

--
-- Constraints for table `tbl_user_preferences`
--
ALTER TABLE `tbl_user_preferences`
  ADD CONSTRAINT `fk_user_preferences_user` FOREIGN KEY (`id_user`) REFERENCES `tbl_users` (`id_user`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
