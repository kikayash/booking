<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

define('BOOKING_SYSTEM_LOADED', true);

// Auto-trigger status update dan completion
if (isLoggedIn()) {
    // Trigger auto-completion setiap 10 menit sekali per session
    $lastCheck = $_SESSION['last_auto_check'] ?? 0;
    $now = time();
    
    if (($now - $lastCheck) >= 600) { // 10 menit
        try {
            $autoResult = forceAutoCheckoutExpiredBookings($conn);
            $_SESSION['last_auto_check'] = $now;
            
            if ($autoResult['completed_count'] > 0) {
                $_SESSION['show_auto_update'] = [
                    'count' => $autoResult['completed_count'],
                    'time' => date('H:i:s')
                ];
                error_log("AUTO-UPDATE: Completed {$autoResult['completed_count']} expired bookings");
            }
        } catch (Exception $e) {
            error_log("Auto-update error: " . $e->getMessage());
        }
    }
}

// Auto-generate recurring schedules jika ada
if (function_exists('autoGenerateUpcomingSchedules')) {
    $lastGenerate = $_SESSION['last_auto_generate'] ?? 0;
    if (($now - $lastGenerate) >= 3600) { // 1 jam sekali
        try {
            $generated = autoGenerateUpcomingSchedules($conn);
            $_SESSION['last_auto_generate'] = $now;
            
            if ($generated > 0) {
                error_log("AUTO-GENERATE: Generated $generated recurring schedules");
            }
        } catch (Exception $e) {
            error_log("Auto-generate error: " . $e->getMessage());
        }
    }
}


// Get the current view (day, week, month)
$view = isset($_GET['view']) ? $_GET['view'] : 'day';

// Get available rooms
$stmt = $conn->prepare("SELECT * FROM tbl_ruang ORDER BY nama_ruang");
$stmt->execute();
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all buildings
$stmt = $conn->prepare("SELECT * FROM tbl_gedung ORDER BY nama_gedung");
$stmt->execute();
$buildings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default selected room (first one if available)
$selectedRoomId = isset($_GET['room_id']) ? $_GET['room_id'] : (count($rooms) > 0 ? $rooms[0]['id_ruang'] : 0);

// Selected building (if any)
$selectedBuildingId = isset($_GET['building_id']) ? $_GET['building_id'] : 0;

// Filter rooms by building if a building is selected
if ($selectedBuildingId) {
    $filteredRooms = array_filter($rooms, function($room) use ($selectedBuildingId) {
        return $room['id_gedung'] == $selectedBuildingId;
    });
} else {
    $filteredRooms = $rooms;
}

// Default date (today if not specified)
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// For week view, calculate the start and end of the week
$weekStart = date('Y-m-d', strtotime('monday this week', strtotime($selectedDate)));
$weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($selectedDate)));

// For month view, calculate the start and end of the month
$monthStart = date('Y-m-01', strtotime($selectedDate));
$monthEnd = date('Y-m-t', strtotime($selectedDate));

// Get bookings based on the selected view
$bookings = [];
if ($selectedRoomId) {
    switch ($view) {
        case 'week':
            $stmt = $conn->prepare("SELECT b.*, u.email 
                                    FROM tbl_booking b 
                                    JOIN tbl_users u ON b.id_user = u.id_user 
                                    WHERE b.id_ruang = ? AND b.tanggal BETWEEN ? AND ?
                                    ORDER BY b.tanggal, b.jam_mulai");
            $stmt->execute([$selectedRoomId, $weekStart, $weekEnd]);
            break;
        case 'month':
            $stmt = $conn->prepare("SELECT b.*, u.email 
                                    FROM tbl_booking b 
                                    JOIN tbl_users u ON b.id_user = u.id_user 
                                    WHERE b.id_ruang = ? AND b.tanggal BETWEEN ? AND ?
                                    ORDER BY b.tanggal, b.jam_mulai");
            $stmt->execute([$selectedRoomId, $monthStart, $monthEnd]);
            break;
        default: // day view
            $stmt = $conn->prepare("SELECT b.*, u.email 
                                    FROM tbl_booking b 
                                    JOIN tbl_users u ON b.id_user = u.id_user 
                                    WHERE b.id_ruang = ? AND b.tanggal = ?
                                    ORDER BY b.jam_mulai");
            $stmt->execute([$selectedRoomId, $selectedDate]);
            break;
    }
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// File: auto_checkout.php (Updated)
// Sistem Auto-Checkout untuk Booking yang Expired

require_once 'config.php';
require_once 'functions.php';

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Fungsi untuk auto-checkout booking yang expired
function autoCheckoutExpiredBookings($conn) {
    $currentDate = date('Y-m-d');
    $currentTime = date('H:i:s');
    $currentDateTime = date('Y-m-d H:i:s');
    
    // Cari booking dengan status 'active' yang sudah melewati waktu selesai
    $sql = "SELECT b.id_booking, b.nama_acara, b.tanggal, b.jam_mulai, b.jam_selesai, 
                   b.nama_penanggungjawab, b.no_penanggungjawab, b.id_user,
                   r.nama_ruang, g.nama_gedung, u.email
            FROM tbl_booking b
            JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
            LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
            JOIN tbl_users u ON b.id_user = u.id_user
            WHERE b.status = 'active' 
            AND (
                (b.tanggal < ?) OR 
                (b.tanggal = ? AND b.jam_selesai < ?)
            )";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$currentDate, $currentDate, $currentTime]);
    $expiredBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $autoCheckedOutCount = 0;
    
    foreach ($expiredBookings as $booking) {
        // Update status menjadi 'done' dengan auto-checkout
        $updateSql = "UPDATE tbl_booking 
                      SET status = 'done',
                          checkout_status = 'auto_completed',
                          checkout_time = ?,
                          completion_note = 'Ruangan selesai dipakai tanpa checkout dari mahasiswa',
                          checked_out_by = 'SYSTEM_AUTO'
                      WHERE id_booking = ?";
        
        $updateStmt = $conn->prepare($updateSql);
        $result = $updateStmt->execute([$currentDateTime, $booking['id_booking']]);
        
        if ($result) {
            $autoCheckedOutCount++;
            
            // Log untuk tracking
            error_log("AUTO-CHECKOUT: Booking ID {$booking['id_booking']} ({$booking['nama_acara']}) - Status: ACTIVE → DONE (Auto-Completed)");
            error_log("REASON: Ruangan selesai dipakai tanpa checkout dari mahasiswa");
            
            // Kirim notifikasi ke mahasiswa dan admin
            sendAutoCheckoutNotification($booking);
            sendAdminAutoCheckoutNotification($booking);
        }
    }
    
    if ($autoCheckedOutCount > 0) {
        error_log("AUTO-CHECKOUT SUMMARY: {$autoCheckedOutCount} booking(s) automatically checked out");
    }
    
    return $autoCheckedOutCount;
}

// Fungsi untuk mengirim notifikasi auto-checkout ke mahasiswa
function sendAutoCheckoutNotification($booking) {
    $subject = "Auto-Checkout: " . $booking['nama_acara'];
    $message = "Halo {$booking['nama_penanggungjawab']},\n\n";
    $message .= "Booking ruangan Anda telah di-checkout secara otomatis karena melewati waktu selesai.\n\n";
    $message .= "Detail Booking:\n";
    $message .= "Nama Acara: {$booking['nama_acara']}\n";
    $message .= "Ruangan: {$booking['nama_ruang']}\n";
    $message .= "Tanggal: " . date('d/m/Y', strtotime($booking['tanggal'])) . "\n";
    $message .= "Waktu: {$booking['jam_mulai']} - {$booking['jam_selesai']}\n";
    $message .= "Status: SELESAI (Auto-Checkout)\n\n";
    $message .= "CATATAN: Untuk masa depan, mohon lakukan checkout manual setelah selesai menggunakan ruangan.\n\n";
    $message .= "Terima kasih.";
    
    // Log notifikasi (implementasi email sesuai kebutuhan)
    error_log("AUTO-CHECKOUT NOTIFICATION: Sent to {$booking['email']} for booking #{$booking['id_booking']}");
    
    return true;
}

// Fungsi untuk mengirim notifikasi ke admin
function sendAdminAutoCheckoutNotification($booking) {
    $subject = "Admin Alert: Auto-Checkout - " . $booking['nama_acara'];
    $message = "SISTEM AUTO-CHECKOUT\n\n";
    $message .= "Booking berikut telah di-checkout secara otomatis karena mahasiswa lupa checkout:\n\n";
    $message .= "Detail Booking:\n";
    $message .= "ID Booking: {$booking['id_booking']}\n";
    $message .= "Nama Acara: {$booking['nama_acara']}\n";
    $message .= "Ruangan: {$booking['nama_ruang']}\n";
    $message .= "Tanggal: " . date('d/m/Y', strtotime($booking['tanggal'])) . "\n";
    $message .= "Waktu: {$booking['jam_mulai']} - {$booking['jam_selesai']}\n";
    $message .= "PIC: {$booking['nama_penanggungjawab']} ({$booking['no_penanggungjawab']})\n";
    $message .= "Email: {$booking['email']}\n\n";
    $message .= "Status: MAHASISWA LUPA CHECKOUT\n";
    $message .= "Auto-checkout time: " . date('d/m/Y H:i:s') . "\n\n";
    $message .= "Silakan tindak lanjuti jika diperlukan.";
    
    // Log notifikasi admin
    error_log("ADMIN AUTO-CHECKOUT ALERT: Booking ID {$booking['id_booking']} - Student forgot to checkout");
    
    return true;
}

// Fungsi untuk mendapatkan statistik checkout
function getCheckoutStatistics($conn, $date = null) {
    $date = $date ?: date('Y-m-d');
    
    try {
        // Manual checkout count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as manual_count 
            FROM tbl_booking 
            WHERE DATE(checkout_time) = ? 
            AND checkout_status = 'manual_checkout'
        ");
        $stmt->execute([$date]);
        $manualCount = $stmt->fetchColumn();
        
        // Auto checkout count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as auto_count 
            FROM tbl_booking 
            WHERE DATE(checkout_time) = ? 
            AND checkout_status = 'auto_completed'
        ");
        $stmt->execute([$date]);
        $autoCount = $stmt->fetchColumn();
        
        return [
            'date' => $date,
            'manual_checkout' => $manualCount,
            'auto_checkout' => $autoCount,
            'total_checkout' => $manualCount + $autoCount,
            'forgot_checkout_rate' => $manualCount + $autoCount > 0 ? 
                round(($autoCount / ($manualCount + $autoCount)) * 100, 2) : 0
        ];
        
    } catch (Exception $e) {
        error_log("Error getting checkout statistics: " . $e->getMessage());
        return null;
    }
}

// Jalankan auto-checkout jika file dipanggil langsung
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $count = autoCheckoutExpiredBookings($conn);
        
        if ($count > 0) {
            echo "AUTO-CHECKOUT: {$count} booking(s) processed successfully\n";
            
            // Show statistics
            $stats = getCheckoutStatistics($conn);
            if ($stats) {
                echo "TODAY'S STATS:\n";
                echo "- Manual Checkout: {$stats['manual_checkout']}\n";
                echo "- Auto Checkout (Forgot): {$stats['auto_checkout']}\n";
                echo "- Forgot Rate: {$stats['forgot_checkout_rate']}%\n";
            }
        } 
        
    } catch (Exception $e) {
        error_log("AUTO-CHECKOUT ERROR: " . $e->getMessage());
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

// Check if the selected date is a holiday
$stmt = $conn->prepare("SELECT * FROM tbl_harilibur WHERE tanggal = ?");
$stmt->execute([$selectedDate]);
$holiday = $stmt->fetch(PDO::FETCH_ASSOC);

// Get selected room details
$selectedRoom = null;
if ($selectedRoomId) {
    $stmt = $conn->prepare("SELECT * FROM tbl_ruang WHERE id_ruang = ?");
    $stmt->execute([$selectedRoomId]);
    $selectedRoom = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get current month for mini calendar
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Definisikan rentang waktu booking (hari ini sampai 1 bulan ke depan)
$todayDate = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+1 month'));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peminjaman Ruangan - STIE MCE</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .calendar-month-event {
    transition: all 0.2s ease;
    border: 1px solid rgba(255,255,255,0.3);
    }

    .calendar-month-event:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    /* Status-specific colors */
    .bg-warning.text-dark {
        background-color: #ffc107 !important;
        color: #000 !important;
    }

    .bg-success {
        background-color: #28a745 !important;
    }

    .bg-danger {
        background-color: #dc3545 !important;
        animation: pulse-red 2s infinite;
    }

    .bg-info {
        background-color: #17a2b8 !important;
    }

    .bg-secondary {
        background-color: #6c757d !important;
    }

    /* Animasi untuk booking yang sedang berlangsung */
    @keyframes pulse-red {
        0% { 
            background-color: #dc3545; 
            box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
        }
        50% { 
            background-color: #c82333; 
            box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
        }
        100% { 
            background-color: #dc3545; 
            box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
        }
    }

    /* Improved calendar cell styling */
    .calendar-month-cell {
        position: relative;
        border: 1px solid #dee2e6;
        padding: 5px;
    }

    .calendar-month-day {
        font-weight: 600;
        margin-bottom: 5px;
        border-bottom: 1px solid #eee;
        padding-bottom: 2px;
    }

    .calendar-month-events {
        font-size: 0.8rem;
        overflow: hidden;
        max-height: 85px;
    }

    /* Weekend styling */
    .calendar-month-cell:nth-child(1),
    .calendar-month-cell:nth-child(7) {
        background-color: #f8f9fa;
    }

    /* Today highlighting */
    .table-primary .calendar-month-day {
        background-color: rgba(0, 123, 255, 0.1);
        border-radius: 3px;
        padding: 2px 4px;
    }
        /* Style untuk tanggal di luar rentang booking */
        .mini-calendar-day.out-of-range {
            opacity: 0.5;
            cursor: not-allowed;
            text-decoration: line-through;
            color: #6c757d;
        }

        /* Pastikan tidak mengganggu tampilan tanggal hari ini */
        .mini-calendar-day.out-of-range.today {
            opacity: 0.7;
            text-decoration: none;
            color: var(--primary-color);
            background-color: #e8f0fe;
        }

        /* Pastikan tanggal weekend di luar rentang tetap merah */
        .mini-calendar-day.out-of-range.weekend {
            color: var(--danger-color);
            opacity: 0.5;
        }
        
        /* Style tambahan untuk view kalender */
        .calendar-week-header {
            text-align: center;
            font-weight: 600;
            padding: 8px;
            background-color: #f8f9fa;
        }
        
        .calendar-month-cell {
            height: 100px;
            vertical-align: top;
        }
        
        .calendar-month-day {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .calendar-month-events {
            font-size: 0.8rem;
            overflow: hidden;
        }
        
        .calendar-month-event {
            margin-bottom: 2px;
            padding: 1px 4px;
            border-radius: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /*buat badges fasilitas*/
        .facility-item {
            display: inline-block;
            margin: 2px;
        }
        .facility-badge {
            background-color: #e3f2fd;
            color: #0277bd;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            border: 1px solid #81d4fa;
        }
        .role-restriction {
            font-size: 0.85rem;
        }
        .room-card {
            transition: all 0.3s ease;
        }
        .room-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="<?= isLoggedIn() ? 'logged-in' : '' ?>">
    <header>
        <?php include 'header.php'; ?>
    </header>

    <div class="container-fluid mt-3">
        <!-- Info Alert 
        <div class="alert alert-info alert-dismissible fade show" role="alert">
        <strong>Informasi Booking!</strong> Anda hanya dapat memesan ruangan dari tanggal <span id="tgl-awal-booking"><?= date('d F Y', strtotime($todayDate)) ?></span> hingga <span id="tgl-akhir-booking"><?= date('d F Y', strtotime($maxDate)) ?></span>.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>-->
        <div class="row">
            <!-- Mini Calendar -->
            <div class="col-md-3">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <button class="btn btn-sm btn-outline-light" onclick="prevMonth()">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <h5 class="mb-0"><?= date('F Y', mktime(0, 0, 0, $month, 1, $year)) ?></h5>
                            <button class="btn btn-sm btn-outline-light" onclick="nextMonth()">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-bordered table-sm mb-0 mini-calendar">
                            <thead>
                                <tr class="text-center">
                                    <th>Sen</th>
                                    <th>Sel</th>
                                    <th>Rab</th>
                                    <th>Kam</th>
                                    <th>Jum</th>
                                    <th style="color: var(--danger-color);">Sab</th>
                                    <th style="color: var(--danger-color);">Min</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
// Get the first day of the month
$firstDayOfMonth = mktime(0, 0, 0, $month, 1, $year);
$numberDays = date('t', $firstDayOfMonth);
$firstDayOfWeek = date('w', $firstDayOfMonth); // 0=Sunday, 1=Monday, ..., 6=Saturday

// Convert to Monday=0 format (Indonesia standard)
$firstDayOfWeek = ($firstDayOfWeek == 0) ? 6 : $firstDayOfWeek - 1;

$day = 1;
$today = date('Y-m-d');

// Generate calendar
for ($i = 0; $i < 6; $i++) {
    echo "<tr>";
    
    for ($j = 0; $j < 7; $j++) {
        if (($i == 0 && $j < $firstDayOfWeek) || ($day > $numberDays)) {
            echo "<td class='text-center text-muted'>&nbsp;</td>";
        } else {
            $currentDate = date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
            $isToday = $currentDate == $today ? 'today' : '';
            $isSelected = $currentDate == $selectedDate ? 'selected' : '';
            
            // FIXED: Correct weekend detection for mini calendar
            // j=0 is Monday, j=1 is Tuesday, ..., j=5 is Saturday, j=6 is Sunday
            $isWeekend = '';
            if ($j == 5) { // Saturday
                $isWeekend = 'weekend';
            } elseif ($j == 6) { // Sunday  
                $isWeekend = 'weekend';
            }
            
            // Check if date is outside booking range
            $isOutOfRange = '';
            if ($currentDate < $todayDate || $currentDate > $maxDate) {
                $isOutOfRange = 'out-of-range';
            }
            
            $dateHasBookings = false;
            if ($selectedRoomId) {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_booking WHERE id_ruang = ? AND tanggal = ?");
                $stmt->execute([$selectedRoomId, $currentDate]);
                $dateHasBookings = $stmt->fetchColumn() > 0;
            }
            
            $isHoliday = false;
            $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_harilibur WHERE tanggal = ?");
            $stmt->execute([$currentDate]);
            $isHoliday = $stmt->fetchColumn() > 0;
            
            $cellClass = "text-center mini-calendar-day $isToday $isSelected $isWeekend $isOutOfRange";
            if ($dateHasBookings) {
                $cellClass .= ' has-bookings';
            }
            if ($isHoliday) {
                $cellClass .= ' holiday';
            }
            
            echo "<td class='$cellClass' data-date='$currentDate' onclick='selectDate(\"$currentDate\")'>{$day}</td>";
            $day++;
        }
    }
    
    echo "</tr>";
    if ($day > $numberDays) {
        break;
    }
}
?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer">
                        <div class="d-flex justify-content-between">
                            <div>
                                <span>Informasi:</span><br>
                                <span class="badge bg-warning me-2">Pending</span>
                                <span class="badge bg-success me-2">Diterima</span>
                                <span class="badge bg-danger">Digunakan</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Room Selection -->
                <div class="card shadow mt-3">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Pilih Lokasi & Ruangan</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="buildingSelector" class="form-label">Gedung</label>
                            <select class="form-select" id="buildingSelector" onchange="filterRooms(this.value)">
                                <option value="">-- Pilih Gedung --</option>
                                <?php foreach ($buildings as $building): ?>
                                    <option value="<?= $building['id_gedung'] ?>" 
                                            <?= ($selectedBuildingId == $building['id_gedung']) ? 'selected' : '' ?>>
                                        <?= $building['nama_gedung'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-0">
                            <label for="roomSelector" class="form-label">Ruangan</label>
                            <select class="form-select" id="roomSelector" onchange="selectRoom(this.value)">
                                <option value="">-- Pilih Ruangan --</option>
                                <?php foreach ($filteredRooms as $room): ?>
                                    <option value="<?= $room['id_ruang'] ?>" 
                                            <?= $room['id_ruang'] == $selectedRoomId ? 'selected' : '' ?>>
                                        <strong><?= $room['nama_ruang'] ?></strong> (Kapasitas: <?= $room['kapasitas'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Jika dipilih ruangan, tampilkan informasi ruangan -->
                <?php if ($selectedRoom): ?>
                <div class="card shadow mt-3">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Informasi Ruangan</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-light p-3 rounded-circle me-3">
                                <i class="fas fa-door-open fa-2x text-primary"></i>
                            </div>
                            <div>
                                <h4 class="mb-0"><?= $selectedRoom['nama_ruang'] ?></h4>
                                <p class="text-muted mb-0">
                                    <?php
                                    // Get building name
                                    $stmt = $conn->prepare("SELECT nama_gedung FROM tbl_gedung WHERE id_gedung = ?");
                                    $stmt->execute([$selectedRoom['id_gedung']]);
                                    $buildingName = $stmt->fetchColumn() ?: 'Unknown Building';
                                    echo $buildingName;
                                    ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-sm-6">
                                <p><i class="fas fa-users me-2 text-secondary"></i> <strong>Kapasitas:</strong> <?= $selectedRoom['kapasitas'] ?> orang</p>
                            </div>
                            <div class="col-sm-6">
                                <p><i class="fas fa-map-marker-alt me-2 text-secondary"></i> <strong>Lokasi:</strong> <?= $selectedRoom['lokasi'] ?></p>
                            </div>
                            <div class="col-sm-6">
                                <p><i class="fas fa-cogs me-2 text-secondary"></i> <strong>Fasilitas:</strong> </p>
                                <span class="facility-badge facility-item"><?= $selectedRoom['fasilitas'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Main Calendar -->
            <div class="col-md-9">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <?php if ($view == 'day'): ?>
                                <button class="btn btn-sm btn-outline-light" onclick="prevDay()">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <h4 class="mb-0">
                                    <?= date('l, d F Y', strtotime($selectedDate)) ?>
                                    <?= $holiday ? '- <span class="badge bg-danger">' . $holiday['keterangan'] . '</span>' : '' ?>
                                </h4>
                                <button class="btn btn-sm btn-outline-light" onclick="nextDay()">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            <?php elseif ($view == 'week'): ?>
                                <button class="btn btn-sm btn-outline-light" onclick="prevWeek()">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <h4 class="mb-0">
                                    <?= date('d M', strtotime($weekStart)) ?> - <?= date('d M Y', strtotime($weekEnd)) ?>
                                </h4>
                                <button class="btn btn-sm btn-outline-light" onclick="nextWeek()">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            <?php else: // month view ?>
                                <button class="btn btn-sm btn-outline-light" onclick="prevMonth()">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <h4 class="mb-0">
                                    <?= date('F Y', strtotime($monthStart)) ?>
                                </h4>
                                <button class="btn btn-sm btn-outline-light" onclick="nextMonth()">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="mt-2 text-center">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-sm btn-outline-light <?= (!isset($_GET['view']) || $_GET['view'] == 'day') ? 'active' : '' ?>" onclick="changeView('day')">Hari</button>
                                <button type="button" class="btn btn-sm btn-outline-light <?= (isset($_GET['view']) && $_GET['view'] == 'week') ? 'active' : '' ?>" onclick="changeView('week')">Minggu</button>
                                <button type="button" class="btn btn-sm btn-outline-light <?= (isset($_GET['view']) && $_GET['view'] == 'month') ? 'active' : '' ?>" onclick="changeView('month')">Bulan</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <?php if ($view == 'day'): ?>
                                <!-- Enhanced Day view dengan Status Workflow -->
                                <table class="table table-bordered table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="15%">Waktu</th>
                                            <th width="85%">
                                                <?= $selectedRoom ? $selectedRoom['nama_ruang'] : 'Pilih Ruangan' ?>
                                                <div class="float-end">
                                                    <small class="text-muted">
                                                        <span class="badge bg-warning me-1">📋 PENDING</span>
                                                        <span class="badge bg-success me-1">✅ APPROVED</span>
                                                        <span class="badge bg-danger me-1">🔴 ONGOING</span>
                                                        <span class="badge bg-info">✅ SELESAI</span>
                                                    </small>
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Time slots from 5:00 to 22:00 with 30-minute intervals
                                        $startTime = strtotime('05:00:00');
                                        $endTime = strtotime('22:00:00');
                                        $interval = 30 * 60; // 30 minutes
                                        
                                        $currentDateTime = new DateTime();
                                        $currentDate = $currentDateTime->format('Y-m-d');
                                        $currentTime = $currentDateTime->format('H:i:s');
                                        
                                        // Check if selected date is within booking range
                                        $isDateInRange = ($selectedDate >= $todayDate && $selectedDate <= $maxDate);
                                        
                                        for ($time = $startTime; $time <= $endTime; $time += $interval) {
                                            $timeSlot = date('H:i', $time);
                                            $nextTimeSlot = date('H:i', $time + $interval);
                                            
                                            // Check if there's a booking for this time slot
                                            $slotBooked = false;
                                            $bookingData = null;
                                            $slotStatus = '';
                                            
                                            foreach ($bookings as $booking) {
                                                $bookingStart = date('H:i', strtotime($booking['jam_mulai']));
                                                $bookingEnd = date('H:i', strtotime($booking['jam_selesai']));
                                                
                                                if ($timeSlot >= $bookingStart && $timeSlot < $bookingEnd) {
                                                    $slotBooked = true;
                                                    $bookingData = $booking;
                                                    $slotStatus = $booking['status'];
                                                    
                                                    // AUTO-ACTIVATION LOGIC: Booking approved -> active saat waktunya tiba
                                                    if ($booking['status'] === 'approve' && 
                                                        $booking['tanggal'] === $currentDate && 
                                                        $currentTime >= $booking['jam_mulai'] && 
                                                        $currentTime <= $booking['jam_selesai']) {
                                                        
                                                        // Auto-activate booking
                                                        $updateStmt = $conn->prepare("
                                                            UPDATE tbl_booking 
                                                            SET status = 'active', 
                                                                activated_at = NOW(),
                                                                activated_by = 'SYSTEM_AUTO',
                                                                activation_note = 'Auto-activated: Waktu booking telah tiba'
                                                            WHERE id_booking = ?
                                                        ");
                                                        $updateStmt->execute([$booking['id_booking']]);
                                                        
                                                        // Update status untuk display
                                                        $slotStatus = 'active';
                                                        $bookingData['status'] = 'active';
                                                        
                                                        error_log("AUTO-ACTIVATION: Booking ID {$booking['id_booking']} auto-activated (ONGOING)");
                                                    }
                                                    
                                                    break;
                                                }
                                            }
                                            
                                            // Determine row class based on booking status
                                            $rowClass = '';
                                            $slotDisabled = false;
                                            
                                            if ($holiday) {
                                                $rowClass = 'table-secondary';
                                                $slotDisabled = true;
                                            } elseif ($slotBooked) {
                                                switch ($slotStatus) {
                                                    case 'pending':
                                                        $rowClass = 'table-warning'; // Yellow - PENDING
                                                        break;
                                                    case 'approve':
                                                        $rowClass = 'table-success'; // Green - APPROVED
                                                        break;
                                                    case 'active':
                                                        $rowClass = 'table-danger'; // Red - ONGOING
                                                        break;
                                                    case 'rejected':
                                                    case 'cancelled':
                                                        $rowClass = 'table-secondary'; // Gray - Cancelled/Rejected
                                                        break;
                                                    case 'done':
                                                        $rowClass = 'table-info'; // Blue - SELESAI
                                                        break;
                                                }
                                            } else {
                                                // Disable past time slots for today
                                                if ($selectedDate == $currentDate && $timeSlot < date('H:i', strtotime($currentTime))) {
                                                    $rowClass = 'table-light';
                                                    $slotDisabled = true;
                                                }
                                                
                                                // Disable slots if date is out of booking range
                                                if (!$isDateInRange) {
                                                    $rowClass = 'table-secondary';
                                                    $slotDisabled = true;
                                                }
                                            }
                                            
                                            echo "<tr class='$rowClass time-slot' data-time='$timeSlot'>";
                                            echo "<td class='fw-bold'>{$timeSlot} - {$nextTimeSlot}</td>";
                                            
                                            echo "<td>";
                                            if ($slotBooked) {
                                                // Display enhanced booking info
                                                echo "<div class='booking-info p-2'>";
                                                echo "<div class='d-flex justify-content-between align-items-start'>";
                                                echo "<div>";
                                                echo "<h6 class='mb-1'><i class='fas fa-calendar-check me-2'></i>{$bookingData['nama_acara']}</h6>";
                                                echo "<p class='mb-1'><i class='fas fa-user me-2'></i>PIC: {$bookingData['nama_penanggungjawab']}</p>";
                                                echo "<p class='mb-1'><i class='fas fa-phone me-2'></i>{$bookingData['no_penanggungjawab']}</p>";
                                                echo "</div>";
                                                echo "<div class='text-end'>";
                                                
                                                // Enhanced status badge
                                                echo "<div class='mb-2'>";
                                                switch ($slotStatus) {
                                                    case 'pending':
                                                        echo "<span class='badge bg-warning text-dark fs-6'>📋 PENDING</span>";
                                                        echo "<br><small class='text-muted'>Menunggu persetujuan admin</small>";
                                                        break;
                                                    case 'approve':
                                                        echo "<span class='badge bg-success fs-6'>✅ APPROVED</span>";
                                                        echo "<br><small class='text-muted'>Siap digunakan</small>";
                                                        break;
                                                    case 'active':
                                                        echo "<span class='badge bg-danger fs-6 blink-badge'>🔴 ONGOING</span>";
                                                        echo "<br><small class='text-white'>Sedang berlangsung</small>";
                                                        break;
                                                    case 'rejected':
                                                        echo "<span class='badge bg-secondary fs-6'>❌ DITOLAK</span>";
                                                        break;
                                                    case 'cancelled':
                                                        echo "<span class='badge bg-secondary fs-6'>❌ DIBATALKAN</span>";
                                                        break;
                                                    case 'done':
                                                        echo "<span class='badge bg-info fs-6'>✅ SELESAI</span>";
                                                        echo "<br><small class='text-muted'>Telah selesai</small>";
                                                        break;
                                                }
                                                echo "</div>";
                                                echo "</div>";
                                                echo "</div>";
                                                
                                                // Action buttons based on status and user ownership
                                                $isUserOwner = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $bookingData['id_user']);
                                                $isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] == 'admin');
                                                
                                                if (isset($_SESSION['user_id'])) {
                                                    echo "<div class='mt-2 d-flex gap-2 flex-wrap'>";
                                                    
                                                    // PENDING status buttons
                                                    if ($slotStatus === 'pending') {
                                                        if ($isUserOwner || $isAdmin) {
                                                            echo "<button class='btn btn-sm btn-danger' onclick='cancelBooking({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-times'></i> Batalkan
                                                                  </button>";
                                                        }
                                                        if ($isAdmin) {
                                                            echo "<button class='btn btn-sm btn-success' onclick='approveBooking({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-check'></i> Approve
                                                                  </button>";
                                                            echo "<button class='btn btn-sm btn-secondary' onclick='rejectBooking({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-times'></i> Tolak
                                                                  </button>";
                                                        }
                                                    }
                                                    
                                                    // APPROVED status buttons
                                                    elseif ($slotStatus === 'approve') {
                                                        if ($isUserOwner || $isAdmin) {
                                                            echo "<button class='btn btn-sm btn-danger' onclick='cancelBooking({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-times'></i> Batalkan
                                                                  </button>";
                                                        }
                                                        
                                                        // Manual activation button for user or admin
                                                        if (($isUserOwner || $isAdmin) && canActivateBooking($bookingData, $currentDate, $currentTime)) {
                                                            echo "<button class='btn btn-sm btn-primary activate-btn' onclick='activateBooking({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-play'></i> Aktifkan Sekarang
                                                                  </button>";
                                                        }
                                                    }
                                                    
                                                    // ONGOING (ACTIVE) status buttons
                                                    elseif ($slotStatus === 'active') {
                                                        if ($isUserOwner || $isAdmin) {
                                                            echo "<button class='btn btn-sm btn-warning checkout-btn fw-bold' onclick='showCheckoutModal({$bookingData['id_booking']})'>
                                                                    <i class='fas fa-sign-out-alt'></i> CHECKOUT
                                                                  </button>";
                                                        }
                                                    }
                                                    
                                                    echo "</div>";
                                                }
                                                
                                                echo "</div>";
                                            } elseif (!$slotDisabled && $selectedRoomId) {
                                                // Show booking button for available slots
                                                if ($isDateInRange) {
                                                    echo "<div class='text-center p-3'>";
                                                    echo "<button class='btn btn-outline-primary book-btn' onclick='bookTimeSlot(\"{$selectedDate}\", \"{$timeSlot}\", {$selectedRoomId})'>
                                                            <i class='fas fa-plus'></i> Pesan Ruangan
                                                          </button>";
                                                    echo "</div>";
                                                } else {
                                                    echo "<div class='text-center p-3 text-muted'>";
                                                    echo "<em>Di luar rentang waktu pemesanan</em>";
                                                    echo "</div>";
                                                }
                                            } elseif ($holiday) {
                                                echo "<div class='text-center p-3 text-muted'>";
                                                echo "<em><i class='fas fa-calendar-times me-2'></i>Hari Libur: {$holiday['keterangan']}</em>";
                                                echo "</div>";
                                            } elseif ($slotDisabled) {
                                                echo "<div class='text-center p-3 text-muted'>";
                                                if (!$isDateInRange) {
                                                    echo "<em>Di luar rentang waktu pemesanan</em>";
                                                } else {
                                                    echo "<em>Waktu sudah berlalu</em>";
                                                }
                                                echo "</div>";
                                            } else {
                                                echo "<div class='text-center p-3 text-muted'>";
                                                echo "<em>Pilih ruangan terlebih dahulu</em>";
                                                echo "</div>";
                                            }
                                            echo "</td>";
                                            echo "</tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            
                                <!-- Enhanced Checkout Modal -->
                                <div class="modal fade" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fas fa-sign-out-alt me-2"></i>Checkout Ruangan
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Status akan berubah menjadi SELESAI</strong><br>
                    Dengan keterangan: "Ruangan sudah di-checkout oleh mahasiswa"
                </div>
                <div id="checkoutDetails">
                    <!-- Details will be filled by JavaScript -->
                </div>
                
                <!-- FIXED: Checkbox with proper styling -->
                <div class="form-check mt-3 p-3 bg-light rounded">
                    <input class="form-check-input" type="checkbox" id="confirmCheckout" required>
                    <label class="form-check-label fw-bold" for="confirmCheckout">
                        <i class="fas fa-check-circle me-2 text-success"></i>
                        Saya konfirmasi bahwa ruangan sudah selesai digunakan dan dalam kondisi bersih
                    </label>
                    <div class="form-text">
                        <i class="fas fa-exclamation-triangle me-1 text-warning"></i>
                        Centang kotak ini untuk mengaktifkan tombol checkout
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Batal
                </button>
                <button type="button" class="btn btn-secondary fw-bold" id="confirmCheckoutBtn" disabled>
                    <i class="fas fa-check me-2"></i>Ya, Checkout Sekarang
                </button>
            </div>
        </div>
    </div>
</div>
                            <?php endif; ?> <?php if ($view == 'week'): ?>
                                <!-- Week view -->
                                <table class="table table-bordered table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="15%">Waktu</th>
                                            <?php 
$currentDay = new DateTime($weekStart);
$endDay = new DateTime($weekEnd);
while ($currentDay <= $endDay) {
    $dayClass = '';
    $dayNum = $currentDay->format('N'); // 1 (Monday) to 7 (Sunday)
    $isWeekend = ($dayNum == 6 || $dayNum == 7); // 6 = Saturday, 7 = Sunday
    
    if ($currentDay->format('Y-m-d') == date('Y-m-d')) {
        $dayClass = 'table-primary';
    } elseif ($isWeekend) {
        $dayClass = 'table-light';
    }
    
    echo '<th class="' . $dayClass . ' text-center" width="' . (85/7) . '%">';
    // Tambahkan class khusus untuk weekend day name
    $dayNameClass = $isWeekend ? 'day-header-weekend' : '';
    echo '<span class="day-name ' . $dayNameClass . '">' . $currentDay->format('D') . '</span><br>' . 
         $currentDay->format('d/m');
    echo '</th>';
    
    $currentDay->modify('+1 day');
}
?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Time slots for week view
                                        $startTime = strtotime('07:00:00');
                                        $endTime = strtotime('17:00:00');
                                        $interval = 60 * 60; // 1 hour for week view
                                        
                                        for ($time = $startTime; $time <= $endTime; $time += $interval) {
                                            $timeSlot = date('H:i', $time);
                                            $nextTimeSlot = date('H:i', $time + $interval);
                                            
                                            echo "<tr class='time-slot' data-time='$timeSlot'>";
                                            echo "<td>{$timeSlot} - {$nextTimeSlot}</td>";
                                            
                                            // For each day of the week
                                            $currentDay = new DateTime($weekStart);
                                            $endDay = new DateTime($weekEnd);
                                            
                                            while ($currentDay <= $endDay) {
                                                $dayDate = $currentDay->format('Y-m-d');
                                                
                                                // Check if it's a holiday
                                                $stmt = $conn->prepare("SELECT * FROM tbl_harilibur WHERE tanggal = ?");
                                                $stmt->execute([$dayDate]);
                                                $dayHoliday = $stmt->fetch(PDO::FETCH_ASSOC);
                                                
                                                // Check if there's a booking for this day and time slot
                                                $cellBooked = false;
                                                $cellBooking = null;
                                                
                                                foreach ($bookings as $booking) {
                                                    if ($booking['tanggal'] == $dayDate) {
                                                        $bookingStart = date('H:i', strtotime($booking['jam_mulai']));
                                                        $bookingEnd = date('H:i', strtotime($booking['jam_selesai']));
                                                        
                                                        if (($timeSlot >= $bookingStart && $timeSlot < $bookingEnd) || 
                                                            ($bookingStart >= $timeSlot && $bookingStart < $nextTimeSlot)) {
                                                            $cellBooked = true;
                                                            $cellBooking = $booking;
                                                            break;
                                                        }
                                                    }
                                                }
                                                
                                                // Determine cell class based on booking status or holiday
                                                $cellClass = '';
                                                $isDisabled = false;
                                                
                                                if ($dayHoliday) {
                                                    $cellClass = 'table-secondary';
                                                    $isDisabled = true;
                                                } elseif ($cellBooked) {
                                                    switch ($cellBooking['status']) {
                                                        case 'pending':
                                                            $cellClass = 'table-warning';
                                                            break;
                                                        case 'approve':
                                                            $cellClass = 'table-success';
                                                            break;
                                                        case 'active':
                                                            $cellClass = 'table-danger';
                                                            break;
                                                        case 'rejected':
                                                        case 'cancelled':
                                                            $cellClass = 'table-secondary';
                                                            break;
                                                        case 'done':
                                                            $cellClass = 'table-info';
                                                            break;
                                                    }
                                                } elseif ($dayDate < $todayDate || $dayDate > $maxDate) {
                                                    $cellClass = 'table-secondary';
                                                    $isDisabled = true;
                                                } elseif ($dayDate == $currentDate && $timeSlot < date('H:i')) {
                                                    $cellClass = 'table-secondary';
                                                    $isDisabled = true;
                                                }
                                                
                                                echo "<td class='$cellClass'>";
                                                if ($cellBooked) {
                                                    echo "<small><strong>{$cellBooking['nama_acara']}</strong></small><br>";
                                                    echo "<span class='badge ";
                                                    
                                                    switch ($cellBooking['status']) {
                                                        case 'pending':
                                                            echo "bg-warning";
                                                            break;
                                                        case 'approve':
                                                            echo "bg-success";
                                                            break;
                                                        case 'active':
                                                            echo "bg-danger";
                                                            break;
                                                        case 'rejected':
                                                        case 'cancelled':
                                                            echo "bg-secondary";
                                                            break;
                                                        case 'done':
                                                            echo "bg-info";
                                                            break;
                                                    }
                                                    
                                                    echo "'>{$cellBooking['status']}</span>";
                                                } elseif ($dayHoliday) {
                                                    echo "<small class='text-muted'>{$dayHoliday['keterangan']}</small>";
                                                } elseif (!$isDisabled && $selectedRoomId) {
                                                    echo "<button class='btn btn-sm btn-outline-primary book-btn' onclick='bookTimeSlot(\"{$dayDate}\", \"{$timeSlot}\", {$selectedRoomId})'>Pesan</button>";
                                                }
                                                echo "</td>";
                                                
                                                $currentDay->modify('+1 day');
                                            }
                                            
                                            echo "</tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            <!-- Enhanced Month View dengan Click Details -->
                            <?php elseif ($view == 'month'): ?>
                                <!-- Month view dengan event yang bisa diklik -->
                                <table class="table table-bordered table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="text-center" style="color: var(--danger-color);">Minggu</th>
                                            <th class="text-center">Senin</th>
                                            <th class="text-center">Selasa</th>
                                            <th class="text-center">Rabu</th>
                                            <th class="text-center">Kamis</th>
                                            <th class="text-center">Jumat</th>
                                            <th class="text-center" style="color: var(--danger-color);">Sabtu</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Get the first day of the month
                                        $firstDayOfMonth = strtotime($monthStart);
                                        $lastDayOfMonth = strtotime($monthEnd);
                                        
                                        // Get the day of week of the first day (0 = Sunday, 6 = Saturday)
                                        $firstDayOfWeek = date('w', $firstDayOfMonth);
                                        
                                        // Calculate the date of the first cell in the calendar (might be in previous month)
                                        $startingDate = strtotime("-{$firstDayOfWeek} day", $firstDayOfMonth);
                                        
                                        // Generate calendar grid
                                        $currentDate = $startingDate;
                                        
                                        // Loop for up to 6 weeks
                                        for ($i = 0; $i < 6; $i++) {
                                            echo "<tr>";
                                            
                                            // Loop for each day of the week
                                            for ($j = 0; $j < 7; $j++) {
                                                $dateString = date('Y-m-d', $currentDate);
                                                $dayOfMonth = date('j', $currentDate);
                                                
                                                // Determine if the date is in the current month
                                                $isCurrentMonth = (date('m Y', $currentDate) == date('m Y', $firstDayOfMonth));
                                                
                                                // Determine if it's today
                                                $isToday = ($dateString == date('Y-m-d'));
                                                
                                                // Get cell class
                                                $cellClass = 'calendar-month-cell';
                                                if (!$isCurrentMonth) {
                                                    $cellClass .= ' table-secondary';
                                                } elseif ($isToday) {
                                                    $cellClass .= ' table-primary';
                                                }
                                                
                                                // Check if it's a holiday
                                                $stmt = $conn->prepare("SELECT * FROM tbl_harilibur WHERE tanggal = ?");
                                                $stmt->execute([$dateString]);
                                                $dateHoliday = $stmt->fetch(PDO::FETCH_ASSOC);
                                                
                                                if ($dateHoliday) {
                                                    $cellClass .= ' table-light';
                                                }
                                                
                                                // Check if room is locked
                                                $roomLocked = false;
                                                if ($selectedRoomId) {
                                                    $roomLocked = isRoomLocked($conn, $selectedRoomId, $dateString);
                                                    if ($roomLocked) {
                                                        $cellClass .= ' table-warning';
                                                    }
                                                }
                                                
                                                echo "<td class='$cellClass' style='height: 120px; vertical-align: top;'>";
                                                
                                                // Date header dengan warna weekend
                                                echo "<div class='calendar-month-day'>";
                                                $dayStyle = ($j == 0 || $j == 6) ? 'color: var(--danger-color);' : '';
                                                echo "<span style='$dayStyle'>{$dayOfMonth}</span>";
                                                if ($dateHoliday) {
                                                    echo " <span class='badge bg-danger'><i class='fas fa-star'></i></span>";
                                                }
                                                if ($roomLocked) {
                                                    echo " <span class='badge bg-warning'><i class='fas fa-lock'></i></span>";
                                                }
                                                echo "</div>";
                                                
                                                // Show events if in current month
                                                if ($isCurrentMonth && $selectedRoomId) {
                                                    echo "<div class='calendar-month-events'>";
                                                    
                                                    // Find bookings for this day
                                                    $dayBookings = [];
                                                    foreach ($bookings as $booking) {
                                                        if ($booking['tanggal'] == $dateString) {
                                                            $displayInfo = updateBookingDisplayStatus($booking);
                                                            $booking['display_info'] = $displayInfo;
                                                            $dayBookings[] = $booking;
                                                        }
                                                    }
                                                    
                                                    // Display bookings
                                                    if (count($dayBookings) > 0) {
                                                        foreach ($dayBookings as $index => $booking) {
                                                            if ($index < 2) { // Show max 2 events per day
                                                                $displayInfo = $booking['display_info'];

                                                                $badgeClass = $displayInfo['display_class'];
                                                                $statusText = $displayInfo['display_text'];

                                                                echo "<div class='calendar-month-event $badgeClass text-white clickable-event' 
                                                                style='cursor: pointer; margin-bottom: 2px; padding: 2px 6px; border-radius: 3px; font-size: 0.75rem;'
                                                                data-booking-id='{$booking['id_booking']}'
                                                                onclick='showEventDetail({$booking['id_booking']})'>";

                                                                $statusIcon = '';
                                                                switch ($displayInfo['display_status']) {
                                                                case 'pending':
                                                                    $statusIcon = '⏳';
                                                                    break;
                                                                case 'approved':
                                                                    $statusIcon = '✅';
                                                                    break;
                                                                case 'ongoing':
                                                                    $statusIcon = '🔴';
                                                                    break;
                                                                case 'completed':
                                                                    $statusIcon = '✅';
                                                                    break;
                                                                case 'cancelled':
                                                                    $statusIcon = '❌';
                                                                    break;
                                                                default:
                                                                    $statusIcon = '📋';
                                                            }
                                                            
                                                            echo $statusIcon . " " . formatTime($booking['jam_mulai']) . " " . 
                                                                (strlen($booking['nama_acara']) > 12 ? substr($booking['nama_acara'], 0, 12) . '...' : $booking['nama_acara']);
                                                            echo "</div>";
                                                            }
                                                        }
                                                        
                                                        if (count($dayBookings) > 2) {
                                                            echo "<div class='calendar-month-event bg-light text-dark' style='cursor: pointer; padding: 2px 6px; border-radius: 3px; font-size: 0.75rem;'
                                                                    onclick='showDayDetail(\"$dateString\")'>";
                                                            echo "+" . (count($dayBookings) - 2) . " lainnya";
                                                            echo "</div>";
                                                        }
                                                    } elseif ($dateString >= $todayDate && $dateString <= $maxDate && !$roomLocked && !$dateHoliday) {
                                                        // Show "Available" for future dates within booking range
                                                        echo "<a href='index.php?date=$dateString&view=day&room_id=$selectedRoomId' class='text-success' style='font-size: 0.75rem;'>
                                                                <i class='fas fa-plus-circle'></i> Tersedia
                                                            </a>";
                                                    }
                                                    
                                                    echo "</div>";
                                                }
                                                
                                                echo "</td>";
                                                
                                                // Move to the next day
                                                $currentDate = strtotime('+1 day', $currentDate);
                                            }
                                            
                                            echo "</tr>";
                                            
                                            // Stop if we've gone past the end of the month
                                            if ($currentDate > $lastDayOfMonth && date('j', $currentDate) > 7) {
                                                break;
                                            }
                                        }
                                        ?>
                                    </tbody>
                                </table>

                                <?php if (isset($_SESSION['auto_completion_info']) && $_SESSION['auto_completion_info']['completed_count'] > 0): ?>
                                <div class="alert alert-info alert-dismissible fade show mt-3" role="alert">
                                    <i class="fas fa-robot me-2"></i>
                                    <strong>Auto-Update:</strong> 
                                    <?= $_SESSION['auto_completion_info']['completed_count'] ?> booking yang sudah expired telah otomatis diselesaikan.
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                                <?php unset($_SESSION['auto_completion_info']); ?>
                            <?php endif; ?>

                                <!-- Event Detail Modal untuk Month View -->
                                <div class="modal fade" id="eventDetailModal" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header bg-info text-white">
                                                <h5 class="modal-title">Detail Peminjaman</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body" id="eventDetailBody">
                                                <!-- Content will be loaded dynamically -->
                                            </div>
                                            <div class="modal-footer" id="eventDetailFooter">
                                                <!-- Action buttons will be loaded dynamically -->
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Day Detail Modal untuk melihat semua event dalam satu hari -->
                                <div class="modal fade" id="dayDetailModal" tabindex="-1">
                                    <div class="modal-dialog modal-xl">
                                        <div class="modal-content">
                                            <div class="modal-header bg-primary text-white">
                                                <h5 class="modal-title">Semua Peminjaman pada <span id="dayDetailDate"></span></h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body" id="dayDetailBody">
                                                <!-- Content will be loaded dynamically -->
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <!-- Tambahkan script untuk handle event clicks -->
                            <script>
                            let eventDetailModal;
                            let dayDetailModal;

                            document.addEventListener('DOMContentLoaded', function() {
                                eventDetailModal = new bootstrap.Modal(document.getElementById('eventDetailModal'));
                                dayDetailModal = new bootstrap.Modal(document.getElementById('dayDetailModal'));
                            });

                            function showEventDetail(bookingId) {
                                // Fetch event details via AJAX
                                fetch(`get_booking_detail.php?id=${bookingId}`)
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            const booking = data.booking;
                                            
                                            // Populate modal content
                                            document.getElementById('eventDetailBody').innerHTML = `
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <h6>Informasi Acara</h6>
                                                        <table class="table table-borderless table-sm">
                                                            <tr><th>Nama Acara:</th><td>${booking.nama_acara}</td></tr>
                                                            <tr><th>Tanggal:</th><td>${booking.formatted_date}</td></tr>
                                                            <tr><th>Waktu:</th><td>${booking.jam_mulai} - ${booking.jam_selesai}</td></tr>
                                                            <tr><th>Status:</th><td>${booking.status_badge}</td></tr>
                                                        </table>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <h6>Informasi Ruangan & PIC</h6>
                                                        <table class="table table-borderless table-sm">
                                                            <tr><th>Ruangan:</th><td>${booking.nama_ruang}</td></tr>
                                                            <tr><th>Gedung:</th><td>${booking.nama_gedung}</td></tr>
                                                            <tr><th>PIC:</th><td>${booking.nama_penanggungjawab}</td></tr>
                                                            <tr><th>No. HP:</th><td>${booking.no_penanggungjawab}</td></tr>
                                                        </table>
                                                    </div>
                                                </div>
                                                <div class="row mt-3">
                                                    <div class="col-12">
                                                        <h6>Keterangan</h6>
                                                        <p class="text-muted">${booking.keterangan || 'Tidak ada keterangan'}</p>
                                                    </div>
                                                </div>
                                            `;
                                            
                                            // Populate footer with action buttons
                                            let footerButtons = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>';
                                            
                                            // Add action buttons based on user role and booking status
                                            if (booking.can_activate) {
                                                footerButtons += `<button type="button" class="btn btn-success ms-2" onclick="activateBooking(${booking.id_booking})">
                                                                    <i class="fas fa-play"></i> Aktifkan Sekarang
                                                                </button>`;
                                            }
                                            
                                            if (booking.can_cancel) {
                                                footerButtons += `<button type="button" class="btn btn-danger ms-2" onclick="cancelBooking(${booking.id_booking})">
                                                                    <i class="fas fa-times"></i> Batalkan
                                                                </button>`;
                                            }
                                            
                                            if (booking.can_checkout) {
                                                footerButtons += `<button type="button" class="btn btn-warning ms-2" onclick="checkoutBooking(${booking.id_booking})">
                                                                    <i class="fas fa-sign-out-alt"></i> Checkout
                                                                </button>`;
                                            }
                                            
                                            document.getElementById('eventDetailFooter').innerHTML = footerButtons;
                                            
                                            eventDetailModal.show();
                                        } else {
                                            alert('Gagal memuat detail peminjaman');
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error:', error);
                                        alert('Terjadi kesalahan saat memuat detail');
                                    });
                            }

                            function showDayDetail(date) {
                                // Show all bookings for a specific day
                                const roomId = new URLSearchParams(window.location.search).get('room_id');
                                
                                fetch(`get_day_bookings.php?date=${date}&room_id=${roomId}`)
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            document.getElementById('dayDetailDate').textContent = data.formatted_date;
                                            
                                            let content = '<div class="table-responsive"><table class="table table-striped">';
                                            content += '<thead><tr><th>Waktu</th><th>Acara</th><th>Status</th><th>PIC</th><th>Aksi</th></tr></thead><tbody>';
                                            
                                            data.bookings.forEach(booking => {
                                                content += `<tr>
                                                    <td>${booking.jam_mulai} - ${booking.jam_selesai}</td>
                                                    <td>${booking.nama_acara}</td>
                                                    <td>${booking.status_badge}</td>
                                                    <td>${booking.nama_penanggungjawab}</td>
                                                    <td>
                                                        <button class="btn btn-sm btn-info" onclick="showEventDetail(${booking.id_booking})">
                                                            <i class="fas fa-eye"></i> Detail
                                                        </button>
                                                    </td>
                                                </tr>`;
                                            });
                                            
                                            content += '</tbody></table></div>';
                                            
                                            document.getElementById('dayDetailBody').innerHTML = content;
                                            dayDetailModal.show();
                                        } else {
                                            alert('Gagal memuat data peminjaman hari ini');
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error:', error);
                                        alert('Terjadi kesalahan saat memuat data');
                                    });
                            }

                            function activateBooking(bookingId) {
                                if (confirm('Apakah Anda yakin ingin mengaktifkan peminjaman ini sekarang?')) {
                                    fetch('activate_booking.php', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/x-www-form-urlencoded',
                                        },
                                        body: `booking_id=${bookingId}`
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            alert(data.message);
                                            location.reload(); // Refresh page to show updated status
                                        } else {
                                            alert(data.message);
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error:', error);
                                        alert('Terjadi kesalahan saat mengaktifkan booking');
                                    });
                                }
                            }
                            </script>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Login Modal -->
    <!-- Updated Login Modal dengan Role Selector -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="loginModalLabel">Login Sistem Peminjaman Ruangan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="loginForm">
                    <div class="mb-3">
                        <label for="login_role" class="form-label">Login Sebagai</label>
                        <select class="form-select" id="login_role" name="login_role" required>
                            <option value="">-- Pilih Role --</option>
                            <option value="mahasiswa">Mahasiswa</option>
                            <option value="dosen">Dosen</option>
                            <option value="karyawan">Karyawan</option>
                            <option value="cs">Customer Service</option>
                            <option value="satpam">Satpam</option>
                            <option value="admin">Administrator</option>
                        </select>
                        <div class="form-text">Pilih sesuai dengan status Anda di kampus</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required 
                               placeholder="contoh: nama@stie-mce.ac.id">
                        <div class="form-text">Gunakan email institusi STIE MCE</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" required>
                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                <i class="fas fa-eye" id="togglePasswordIcon"></i>
                            </button>
                        </div>
                        <div class="form-text">Masukkan password yang telah diberikan</div>
                    </div>
                    
                    <div id="loginError" class="alert alert-danger d-none"></div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-2"></i>Login
                        </button>
                    </div>
                </form>
                
                <hr class="my-4">
                
                <div class="text-center">
                    <h6 class="text-muted">Info Login Demo:</h6>
                    <div class="row">
                        <div class="col-6">
                            <small class="text-muted">
                                <strong>Admin:</strong><br>
                                admin@stie-mce.ac.id<br>
                                Password: 12345678
                            </small>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">
                                <strong>Mahasiswa:</strong><br>
                                36288@mhs.stie-mce.ac.id<br>
                                Password: 20051029
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Script untuk toggle password visibility
document.addEventListener('DOMContentLoaded', function() {
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('togglePasswordIcon');
    
    if (togglePassword) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            if (type === 'password') {
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            } else {
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            }
        });
    }
    
    // Auto-fill email based on role selection
    const roleSelect = document.getElementById('login_role');
    const emailInput = document.getElementById('email');
    
    if (roleSelect && emailInput) {
        roleSelect.addEventListener('change', function() {
            const role = this.value;
            let emailSuggestion = '';
            
            switch (role) {
                case 'mahasiswa':
                    emailSuggestion = '@mhs.stie-mce.ac.id';
                    break;
                case 'dosen':
                case 'karyawan':
                case 'admin':
                case 'cs':
                case 'satpam':
                    emailSuggestion = '@stie-mce.ac.id';
                    break;
            }
            
            if (emailSuggestion && emailInput.value === '') {
                emailInput.placeholder = `contoh: nama${emailSuggestion}`;
            }
        });
    }
});
</script>

    <!-- Booking Modal -->
    <!-- Enhanced Booking Modal dengan Auto-Approval Option -->
<!-- Fixed Booking Modal dengan validasi yang proper -->
<div class="modal fade" id="bookingModal" tabindex="-1" aria-labelledby="bookingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="bookingModalLabel">Form Peminjaman Ruangan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="bookingForm" novalidate>
                    <input type="hidden" id="booking_date" name="tanggal" required>
                    <input type="hidden" id="room_id" name="id_ruang" required>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="jam_mulai" class="form-label">Jam Mulai <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="jam_mulai" name="jam_mulai" required>
                                <div class="invalid-feedback">
                                    Jam mulai harus diisi
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="jam_selesai" class="form-label">Jam Selesai <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="jam_selesai" name="jam_selesai" required>
                                <div class="invalid-feedback">
                                    Jam selesai harus diisi dan lebih dari jam mulai
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="nama_acara" class="form-label">Nama Acara <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="nama_acara" name="nama_acara" required 
                               placeholder="Contoh: Rapat UKM WAPPIM">
                        <div class="invalid-feedback">
                            Nama acara harus diisi
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="keterangan" class="form-label">Keterangan <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="keterangan" name="keterangan" rows="3" required
                                  placeholder="Jelaskan detail acara dan kebutuhan ruangan"></textarea>
                        <div class="invalid-feedback">
                            Keterangan harus diisi
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="nama_penanggungjawab" class="form-label">Nama Penanggung Jawab <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nama_penanggungjawab" name="nama_penanggungjawab" required
                                       placeholder="Nama lengkap PIC">
                                <div class="invalid-feedback">
                                    Nama penanggung jawab harus diisi
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="no_penanggungjawab" class="form-label">No. HP Penanggung Jawab <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" id="no_penanggungjawab" name="no_penanggungjawab" required
                                       placeholder="08xxxxxxxxxx" pattern="[0-9]{10,13}">
                                <div class="invalid-feedback">
                                    No. HP harus diisi (10-13 digit)
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="bookingError" class="alert alert-danger d-none" role="alert"></div>
                    <div id="bookingSuccess" class="alert alert-success d-none" role="alert"></div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary" id="submitBookingBtn">
                            <i class="fas fa-paper-plane me-2"></i>Submit Peminjaman
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Pastikan semua data sudah benar sebelum submit. Peminjaman akan diproses oleh admin.
                </small>
            </div>
        </div>
    </div>
</div>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="confirmationModalLabel">Konfirmasi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="confirmationMessage"></p>
                    <input type="hidden" id="confirmationId">
                    <input type="hidden" id="confirmationType">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tidak</button>
                    <button type="button" class="btn btn-danger" id="confirmButton">Ya</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include 'footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="main.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
    // Initialize checkout modal
    const checkoutModalElement = document.getElementById('checkoutModal');
    if (checkoutModalElement && typeof bootstrap !== 'undefined') {
        checkoutModal = new bootstrap.Modal(checkoutModalElement);
    }
    
    // Ensure checkbox functionality
    const confirmCheckbox = document.getElementById('confirmCheckbox');
    const confirmBtn = document.getElementById('confirmCheckoutBtn');
    
    if (confirmCheckbox && confirmBtn) {
        confirmCheckbox.addEventListener('change', function() {
            if (this.checked) {
                confirmBtn.disabled = false;
                confirmBtn.classList.remove('btn-secondary');
                confirmBtn.classList.add('btn-warning');
                confirmBtn.innerHTML = '<i class="fas fa-check me-2"></i>Ya, Checkout Sekarang';
            } else {
                confirmBtn.disabled = true;
                confirmBtn.classList.remove('btn-warning');
                confirmBtn.classList.add('btn-secondary');
                confirmBtn.innerHTML = '<i class="fas fa-check me-2"></i>Ya, Checkout Sekarang';
            }
        });
        
        // Add click handler for checkout button
        confirmBtn.addEventListener('click', function() {
            if (currentCheckoutBookingId && confirmCheckbox.checked) {
                processEnhancedCheckout(currentCheckoutBookingId);
            } else if (!confirmCheckbox.checked) {
                showAlert('❌ Harap centang konfirmasi terlebih dahulu', 'warning');
                // Add shake animation to checkbox
                confirmCheckbox.parentElement.style.animation = 'shake 0.5s ease-in-out';
                setTimeout(() => {
                    confirmCheckbox.parentElement.style.animation = '';
                }, 500);
            }
        });
    }
});

// Add shake animation CSS
const shakeStyle = document.createElement('style');
shakeStyle.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
        20%, 40%, 60%, 80% { transform: translateX(5px); }
    }
    
    .form-check:hover {
        background-color: #f8f9fa !important;
        border-radius: 8px;
    }
    
    .form-check-input:checked {
        background-color: #28a745;
        border-color: #28a745;
    }
    
    .form-check-input:focus {
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }
`;
document.head.appendChild(shakeStyle);
// Fix untuk tombol pesan ruang - tambahkan sebelum </body> di index.php
function bookTimeSlot(date, time, roomId) {
    // Check if user is logged in
    const isLoggedIn = document.body.classList.contains('logged-in') || 
                      <?= json_encode(isLoggedIn()) ?>;
    
    if (!isLoggedIn) {
        // Store booking data and show login modal
        sessionStorage.setItem('pendingBooking', JSON.stringify({
            date: date,
            time: time,
            roomId: roomId
        }));
        
        if (typeof loginModal !== 'undefined' && loginModal) {
            loginModal.show();
        } else {
            // Fallback jika modal tidak tersedia
            const modal = new bootstrap.Modal(document.getElementById('loginModal'));
            modal.show();
        }
    } else {
        showBookingForm(date, time, roomId);
    }
}

function showBookingForm(date, time, roomId) {
    // Set hidden fields
    document.getElementById('booking_date').value = date;
    document.getElementById('room_id').value = roomId;
    
    // Set time fields
    const [hours, minutes] = time.split(':');
    const endHours = parseInt(hours) + 1;
    const endTime = endHours.toString().padStart(2, '0') + ':' + minutes;
    
    document.getElementById('jam_mulai').value = time;
    document.getElementById('jam_selesai').value = endTime;
    
    // Reset form messages
    const errorDiv = document.getElementById('bookingError');
    const successDiv = document.getElementById('bookingSuccess');
    if (errorDiv) errorDiv.classList.add('d-none');
    if (successDiv) successDiv.classList.add('d-none');
    
    // Show modal
    if (typeof bookingModal !== 'undefined' && bookingModal) {
        bookingModal.show();
    } else {
        const modal = new bootstrap.Modal(document.getElementById('bookingModal'));
        modal.show();
    }
}

// Fix untuk filter lokasi dan ruangan - tambahkan sebelum </body> di index.php
function filterRooms(buildingId) {
    const currentUrl = new URL(window.location);
    if (buildingId) {
        currentUrl.searchParams.set('building_id', buildingId);
    } else {
        currentUrl.searchParams.delete('building_id');
    }
    // Reset room selection when building changes
    currentUrl.searchParams.delete('room_id');
    window.location.href = currentUrl.toString();
}

function selectRoom(roomId) {
    if (roomId) {
        const currentUrl = new URL(window.location);
        currentUrl.searchParams.set('room_id', roomId);
        window.location.href = currentUrl.toString();
    }
}

function selectDate(date) {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('date', date);
    window.location.href = currentUrl.toString();
}

function changeView(view) {
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('view', view);
    window.location.href = currentUrl.toString();
}

function prevDay() {
    const currentDate = new Date('<?= $selectedDate ?>');
    currentDate.setDate(currentDate.getDate() - 1);
    const newDate = currentDate.toISOString().split('T')[0];
    selectDate(newDate);
}

function nextDay() {
    const currentDate = new Date('<?= $selectedDate ?>');
    currentDate.setDate(currentDate.getDate() + 1);
    const newDate = currentDate.toISOString().split('T')[0];
    selectDate(newDate);
}

function prevMonth() {
    const currentUrl = new URL(window.location);
    const currentMonth = <?= $month ?>;
    const currentYear = <?= $year ?>;
    
    let newMonth = currentMonth - 1;
    let newYear = currentYear;
    
    if (newMonth < 1) {
        newMonth = 12;
        newYear--;
    }
    
    currentUrl.searchParams.set('month', newMonth);
    currentUrl.searchParams.set('year', newYear);
    window.location.href = currentUrl.toString();
}

function nextMonth() {
    const currentUrl = new URL(window.location);
    const currentMonth = <?= $month ?>;
    const currentYear = <?= $year ?>;
    
    let newMonth = currentMonth + 1;
    let newYear = currentYear;
    
    if (newMonth > 12) {
        newMonth = 1;
        newYear++;
    }
    
    currentUrl.searchParams.set('month', newMonth);
    currentUrl.searchParams.set('year', newYear);
    window.location.href = currentUrl.toString();
}
</script>
</body>
</html>