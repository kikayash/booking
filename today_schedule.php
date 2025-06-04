<?php
// File: today_schedule.php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$userRole = getUserRole();

// Get today's date
$today = date('Y-m-d');
$currentTime = date('H:i:s');

// Get all bookings for today
$stmt = $conn->prepare("SELECT b.*, u.email, r.nama_ruang, g.nama_gedung, r.lokasi, r.kapasitas
                       FROM tbl_booking b 
                       JOIN tbl_users u ON b.id_user = u.id_user 
                       JOIN tbl_ruang r ON b.id_ruang = r.id_ruang
                       JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                       WHERE b.tanggal = ? 
                       ORDER BY b.jam_mulai ASC");
$stmt->execute([$today]);
$todayBookings = $stmt->fetchAll();

// Separate bookings by status and time
$upcomingBookings = [];
$activeBookings = [];
$completedBookings = [];
$otherBookings = [];

foreach ($todayBookings as $booking) {
    if ($booking['status'] === 'active') {
        $activeBookings[] = $booking;
    } elseif ($booking['status'] === 'done') {
        $completedBookings[] = $booking;
    } elseif (in_array($booking['status'], ['pending', 'approve']) && $booking['jam_mulai'] > $currentTime) {
        $upcomingBookings[] = $booking;
    } else {
        $otherBookings[] = $booking;
    }
}

// Get room availability for current time
$stmt = $conn->prepare("SELECT r.*, g.nama_gedung, 
                       CASE 
                           WHEN rl.id IS NOT NULL THEN 'locked'
                           WHEN b.id_booking IS NOT NULL THEN 'occupied'
                           ELSE 'available'
                       END as status,
                       b.nama_acara, b.jam_mulai, b.jam_selesai, b.nama_penanggungjawab,
                       rl.reason as lock_reason
                       FROM tbl_ruang r
                       JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
                       LEFT JOIN tbl_room_locks rl ON (r.id_ruang = rl.id_ruang AND ? BETWEEN rl.start_date AND rl.end_date)
                       LEFT JOIN tbl_booking b ON (r.id_ruang = b.id_ruang AND b.tanggal = ? 
                                                  AND b.status IN ('approve', 'active') 
                                                  AND ? BETWEEN b.jam_mulai AND b.jam_selesai)
                       ORDER BY g.nama_gedung, r.nama_ruang");
$stmt->execute([$today, $today, $currentTime]);
$roomStatus = $stmt->fetchAll();

// Separate rooms by status
$availableRooms = array_filter($roomStatus, function($room) { return $room['status'] === 'available'; });
$occupiedRooms = array_filter($roomStatus, function($room) { return $room['status'] === 'occupied'; });
$lockedRooms = array_filter($roomStatus, function($room) { return $room['status'] === 'locked'; });
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Hari Ini - <?= $config['site_name'] ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .status-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .status-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .room-item {
            border-left: 4px solid #ddd;
            transition: all 0.2s ease;
        }
        .room-item:hover {
            background-color: #f8f9fa;
        }
        .room-available { border-left-color: #28a745; }
        .room-occupied { border-left-color: #dc3545; }
        .room-locked { border-left-color: #ffc107; }
        
        .time-indicator {
            position: relative;
        }
        
        .current-time-line {
            position: absolute;
            width: 100%;
            height: 2px;
            background-color: #dc3545;
            z-index: 10;
        }
        
        .current-time-line::before {
            content: attr(data-time);
            position: absolute;
            left: -60px;
            top: -12px;
            background-color: #dc3545;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
    </style>
</head>
<body class="logged-in">
    <header>
        <?php include 'header.php'; ?>
    </header>

    <div class="container-fluid mt-4">
        <!-- Current Time and Date -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h2 class="mb-1">
                            <i class="fas fa-calendar-day me-2"></i>
                            <?= formatDate($today, 'l, d F Y') ?>
                        </h2>
                        <h4 id="currentTime" class="mb-0"><?= date('H:i:s') ?></h4>
                        <small>Login sebagai: <span class="badge bg-light text-primary"><?= ucfirst($userRole) ?></span></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card status-card bg-danger text-white" data-bs-toggle="collapse" data-bs-target="#activeBookings">
                    <div class="card-body text-center">
                        <i class="fas fa-play fa-2x mb-2"></i>
                        <h3><?= count($activeBookings) ?></h3>
                        <p class="mb-0">Sedang Berlangsung</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card status-card bg-warning text-dark" data-bs-toggle="collapse" data-bs-target="#upcomingBookings">
                    <div class="card-body text-center">
                        <i class="fas fa-clock fa-2x mb-2"></i>
                        <h3><?= count($upcomingBookings) ?></h3>
                        <p class="mb-0">Akan Datang</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card status-card bg-success text-white" data-bs-toggle="collapse" data-bs-target="#availableRooms">
                    <div class="card-body text-center">
                        <i class="fas fa-door-open fa-2x mb-2"></i>
                        <h3><?= count($availableRooms) ?></h3>
                        <p class="mb-0">Ruangan Kosong</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card status-card bg-info text-white" data-bs-toggle="collapse" data-bs-target="#completedBookings">
                    <div class="card-body text-center">
                        <i class="fas fa-check-double fa-2x mb-2"></i>
                        <h3><?= count($completedBookings) ?></h3>
                        <p class="mb-0">Selesai Hari Ini</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Bookings -->
            <div class="col-lg-8">
                <!-- Active Bookings -->
                <div class="collapse show" id="activeBookings">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-play me-2"></i>Sedang Berlangsung (<?= count($activeBookings) ?>)
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($activeBookings) > 0): ?>
                                <?php foreach ($activeBookings as $booking): ?>
                                    <div class="alert alert-danger d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">
                                                <strong><?= htmlspecialchars($booking['nama_acara']) ?></strong>
                                                <span class="badge bg-light text-dark ms-2"><?= htmlspecialchars($booking['nama_ruang']) ?></span>
                                            </div>
                                            <div>
                                                <i class="fas fa-clock me-1"></i><?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?>
                                                <i class="fas fa-building ms-3 me-1"></i><?= htmlspecialchars($booking['nama_gedung']) ?>
                                                <i class="fas fa-map-marker-alt ms-2 me-1"></i><?= htmlspecialchars($booking['lokasi']) ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-user me-1"></i><?= htmlspecialchars($booking['nama_penanggungjawab']) ?>
                                                <i class="fas fa-phone ms-3 me-1"></i><?= htmlspecialchars($booking['no_penanggungjawab']) ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="badge bg-danger fs-6">AKTIF</div>
                                            <?php 
                                            $remaining = strtotime($booking['jam_selesai']) - strtotime($currentTime);
                                            if ($remaining > 0) {
                                                $hours = floor($remaining / 3600);
                                                $minutes = floor(($remaining % 3600) / 60);
                                                echo "<small class='d-block mt-1'>Sisa: {$hours}j {$minutes}m</small>";
                                            }
                                            ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-pause-circle fa-3x mb-3"></i>
                                    <p>Tidak ada peminjaman yang sedang berlangsung saat ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Bookings -->
                <div class="collapse show" id="upcomingBookings">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0">
                                <i class="fas fa-clock me-2"></i>Akan Datang (<?= count($upcomingBookings) ?>)
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($upcomingBookings) > 0): ?>
                                <?php foreach ($upcomingBookings as $booking): ?>
                                    <div class="alert alert-warning d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">
                                                <strong><?= htmlspecialchars($booking['nama_acara']) ?></strong>
                                                <span class="badge bg-primary ms-2"><?= htmlspecialchars($booking['nama_ruang']) ?></span>
                                            </h6>
                                            <div>
                                                <i class="fas fa-clock me-1"></i><?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?>
                                                <i class="fas fa-building ms-3 me-1"></i><?= htmlspecialchars($booking['nama_gedung']) ?>
                                                <i class="fas fa-map-marker-alt ms-2 me-1"></i><?= htmlspecialchars($booking['lokasi']) ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-user me-1"></i><?= htmlspecialchars($booking['nama_penanggungjawab']) ?>
                                                <i class="fas fa-phone ms-3 me-1"></i><?= htmlspecialchars($booking['no_penanggungjawab']) ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <?php if ($booking['status'] === 'pending'): ?>
                                                <div class="badge bg-warning fs-6">PENDING</div>
                                            <?php else: ?>
                                                <div class="badge bg-success fs-6">DISETUJUI</div>
                                            <?php endif; ?>
                                            <?php 
                                            $timeUntil = strtotime($booking['jam_mulai']) - strtotime($currentTime);
                                            if ($timeUntil > 0) {
                                                $hours = floor($timeUntil / 3600);
                                                $minutes = floor(($timeUntil % 3600) / 60);
                                                echo "<small class='d-block mt-1'>Mulai: {$hours}j {$minutes}m lagi</small>";
                                            }
                                            ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-calendar-check fa-3x mb-3"></i>
                                    <p>Tidak ada peminjaman yang akan datang hari ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Completed Bookings -->
                <div class="collapse" id="completedBookings">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-check-double me-2"></i>Selesai Hari Ini (<?= count($completedBookings) ?>)
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($completedBookings) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Waktu</th>
                                                <th>Ruangan</th>
                                                <th>Acara</th>
                                                <th>PIC</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($completedBookings as $booking): ?>
                                                <tr>
                                                    <td><?= formatTime($booking['jam_mulai']) ?> - <?= formatTime($booking['jam_selesai']) ?></td>
                                                    <td><?= htmlspecialchars($booking['nama_ruang']) ?></td>
                                                    <td><?= htmlspecialchars($booking['nama_acara']) ?></td>
                                                    <td><?= htmlspecialchars($booking['nama_penanggungjawab']) ?></td>
                                                    <td>
                                                        <?php if ($booking['checkout_status'] === 'done'): ?>
                                                            <span class="badge bg-success">Manual Checkout</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-info">Auto Selesai</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-calendar-times fa-3x mb-3"></i>
                                    <p>Belum ada peminjaman yang selesai hari ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Room Status -->
            <div class="col-lg-4">
                <!-- Available Rooms -->
                <div class="collapse show" id="availableRooms">
                    <div class="card shadow mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-door-open me-2"></i>Ruangan Kosong
                            </h5>
                        </div>
                        <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                            <?php if (count($availableRooms) > 0): ?>
                                <?php foreach ($availableRooms as $room): ?>
                                    <div class="room-item room-available p-2 mb-2 border rounded">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?= htmlspecialchars($room['nama_ruang']) ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($room['nama_gedung']) ?> - <?= htmlspecialchars($room['lokasi']) ?>
                                                    <br>Kapasitas: <?= $room['kapasitas'] ?> orang
                                                </small>
                                            </div>
                                            <div class="text-success">
                                                <i class="fas fa-check-circle fa-lg"></i>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-door-closed fa-2x mb-2"></i>
                                    <p class="mb-0">Semua ruangan sedang digunakan</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Occupied Rooms -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-door-closed me-2"></i>Ruangan Terpakai
                        </h5>
                    </div>
                    <div class="card-body" style="max-height: 250px; overflow-y: auto;">
                        <?php if (count($occupiedRooms) > 0): ?>
                            <?php foreach ($occupiedRooms as $room): ?>
                                <div class="room-item room-occupied p-2 mb-2 border rounded">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= htmlspecialchars($room['nama_ruang']) ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($room['nama_acara']) ?>
                                                <br><?= formatTime($room['jam_mulai']) ?> - <?= formatTime($room['jam_selesai']) ?>
                                                <br>PIC: <?= htmlspecialchars($room['nama_penanggungjawab']) ?>
                                            </small>
                                        </div>
                                        <div class="text-danger">
                                            <i class="fas fa-times-circle fa-lg"></i>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-smile fa-2x mb-2"></i>
                                <p class="mb-0">Tidak ada ruangan yang terpakai</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Locked Rooms -->
                <?php if (count($lockedRooms) > 0): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0">
                                <i class="fas fa-lock me-2"></i>Ruangan Dikunci
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($lockedRooms as $room): ?>
                                <div class="room-item room-locked p-2 mb-2 border rounded">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= htmlspecialchars($room['nama_ruang']) ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($room['lock_reason']) ?>
                                            </small>
                                        </div>
                                        <div class="text-warning">
                                            <i class="fas fa-lock fa-lg"></i>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="card shadow">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-tools me-2"></i>Quick Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="room_availability.php" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i>Cari Ruangan Kosong
                            </a>
                            <?php if (isAdmin()): ?>
                                <a href="admin/admin-dashboard.php" class="btn btn-info">
                                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard Admin
                                </a>
                            <?php endif; ?>
                            <button class="btn btn-secondary" onclick="window.print()">
                                <i class="fas fa-print me-2"></i>Print Jadwal
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include 'footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update current time every second
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID', {
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('currentTime').textContent = timeString;
        }
        
        // Auto refresh page every 5 minutes to get latest data
        function autoRefresh() {
            setTimeout(function() {
                window.location.reload();
            }, 5 * 60 * 1000); // 5 minutes
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Update time every second
            setInterval(updateCurrentTime, 1000);
            
            // Auto refresh
            autoRefresh();
            
            // Status card click handlers
            document.querySelectorAll('.status-card').forEach(card => {
                card.addEventListener('click', function() {
                    const target = this.getAttribute('data-bs-toggle');
                    if (target === 'collapse') {
                        const targetElement = document.querySelector(this.getAttribute('data-bs-target'));
                        const bsCollapse = new bootstrap.Collapse(targetElement, {
                            toggle: true
                        });
                    }
                });
            });
        });
        
        // Print styles
        window.addEventListener('beforeprint', function() {
            // Hide sidebar and compact layout for printing
            document.body.classList.add('printing');
        });
        
        window.addEventListener('afterprint', function() {
            document.body.classList.remove('printing');
        });
    </script>
    
    <style>
        @media print {
            .navbar, footer, .btn, .card-header {
                display: none !important;
            }
            .card {
                border: 1px solid #000 !important;
                break-inside: avoid;
            }
            .alert {
                border: 1px solid #000 !important;
                background: white !important;
                color: black !important;
            }
        }
    </style>
</body>
</html>