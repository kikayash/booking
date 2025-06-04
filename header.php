<?php
// ===== PERBAIKAN HEADER.PHP - FIX NAVBAR LINKS =====

// GANTI bagian navbar yang bermasalah dengan yang ini:

// Pastikan session sudah dimulai
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include functions.php jika belum di-include
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/functions.php';
}

// PERBAIKAN: Deteksi path dengan lebih akurat
$isAdmin = isset($backPath) && $backPath === '../';
$basePath = $isAdmin ? '../' : '';
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Check if we're in admin directory
$inAdminDir = ($currentDir === 'admin') || strpos($_SERVER['REQUEST_URI'], '/admin/') !== false;
?>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= $basePath ?>index.php">
            Booking STIE MCE
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="<?= $basePath ?>index.php">
                        <i class="fas fa-home me-1"></i> Home
                    </a>
                </li>

                <?php if (isLoggedIn()): ?>
                    <!-- Menu untuk semua user yang sudah login -->
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'my_bookings.php' ? 'active' : '' ?>" href="<?= $basePath ?>my_bookings.php">
                            <i class="fas fa-list me-1"></i> Peminjaman Saya
                        </a>
                    </li>
                    
                    <!-- Menu Cari Ruangan Kosong -->
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'room_availability.php' ? 'active' : '' ?>" href="<?= $basePath ?>room_availability.php">
                            <i class="fas fa-search me-1"></i> Cari Ruangan Kosong
                        </a>
                    </li>
                    
                    <!-- Menu khusus Admin dan CS -->
                    <?php if (isAdmin() || isCS()): ?>
                        <!-- PERBAIKAN: Status Ruangan Link -->
                        <li class="nav-item">
                            <?php 
                            // Tentukan link yang benar berdasarkan posisi saat ini
                            if ($inAdminDir) {
                                $statusRoomLink = 'room_status.php';
                            } else {
                                $statusRoomLink = 'admin/room_status.php';
                            }
                            ?>
                            <a class="nav-link <?= $currentPage === 'room_status.php' ? 'active' : '' ?>" 
                               href="<?= $statusRoomLink ?>">
                                <i class="fas fa-tv me-1"></i> Status Ruangan
                            </a>
                        </li>
                        
                        <!-- Menu Admin Dropdown -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle <?= $inAdminDir ? 'active' : '' ?>" 
                               href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-cogs me-1"></i> Menu Admin
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="adminDropdown">
                                <li>
                                    <?php 
                                    $dashboardLink = $inAdminDir ? 'admin-dashboard.php' : 'admin/admin-dashboard.php';
                                    ?>
                                    <a class="dropdown-item" href="<?= $dashboardLink ?>">
                                        <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                                    </a>
                                </li>
                                <li>
                                    <?php 
                                    $recurringLink = $inAdminDir ? 'recurring_schedules.php' : 'admin/recurring_schedules.php';
                                    ?>
                                    <a class="dropdown-item" href="<?= $recurringLink ?>">
                                        <i class="fas fa-calendar-week me-1"></i> Jadwal Perkuliahan
                                    </a>
                                </li>
                                <li>
                                    <?php 
                                    $addBookingLink = $inAdminDir ? 'admin_add_booking.php' : 'admin/admin_add_booking.php';
                                    ?>
                                    <a class="dropdown-item" href="<?= $addBookingLink ?>">
                                        <i class="fas fa-plus-circle me-1"></i> Tambah Booking Manual
                                    </a>
                                </li>
                                <li>
                                    <?php 
                                    $roomsLink = $inAdminDir ? 'rooms.php' : 'admin/rooms.php';
                                    ?>
                                    <a class="dropdown-item" href="<?= $roomsLink ?>">
                                        <i class="fas fa-door-open me-1"></i> Kelola Ruangan
                                    </a>
                                </li>
                                <li>
                                    <?php 
                                    $buildingsLink = $inAdminDir ? 'buildings.php' : 'admin/buildings.php';
                                    ?>
                                    <a class="dropdown-item" href="<?= $buildingsLink ?>">
                                        <i class="fas fa-building me-1"></i> Kelola Gedung
                                    </a>
                                </li>
                                <li>
                                    <?php 
                                    $holidaysLink = $inAdminDir ? 'holidays.php' : 'admin/holidays.php';
                                    ?>
                                    <a class="dropdown-item" href="<?= $holidaysLink ?>">
                                        <i class="fas fa-calendar-alt me-1"></i> Kelola Hari Libur
                                    </a>
                                </li>
                                <li>
                                    <?php 
                                    $locksLink = $inAdminDir ? 'rooms_locks.php' : 'admin/rooms_locks.php';
                                    ?>
                                    <a class="dropdown-item" href="<?= $locksLink ?>">
                                        <i class="fas fa-lock me-1"></i> Kelola Lock Ruangan
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <?php 
                                    $exportLink = $inAdminDir ? '../export_bookings.php' : 'export_bookings.php';
                                    ?>
                                    <a class="dropdown-item" href="<?= $exportLink ?>">
                                        <i class="fas fa-file-pdf me-1"></i> Export Data PDF
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>
                    
                    <!-- Menu khusus CS dan Satpam -->
                    <?php if (isCS() || isSatpam()): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="staffDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user-tie me-1"></i> Menu Staff
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="staffDropdown">
                                <li>
                                    <a class="dropdown-item" href="<?= $basePath ?>room_availability.php">
                                        <i class="fas fa-search me-1"></i> Cari Ruangan Kosong
                                    </a>
                                </li>
                                <li>
                                    <?php 
                                    $todayScheduleLink = $inAdminDir ? '../today_schedule.php' : 'today_schedule.php';
                                    ?>
                                    <a class="dropdown-item" href="<?= $todayScheduleLink ?>">
                                        <i class="fas fa-calendar-day me-1"></i> Jadwal Hari Ini
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
            
            <div class="d-flex align-items-center">
                <?php if (isLoggedIn()): ?>
                    <div id="userInfo" class="text-light d-flex align-items-center">
                        <span class="me-2 d-none d-md-inline">
                            <?= $_SESSION['email'] ?> 
                            <span class="badge bg-info ms-1"><?= ucfirst($_SESSION['role']) ?></span>
                        </span>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li>
                                    <span class="dropdown-item-text">
                                        <small class="text-muted">Login sebagai:</small><br>
                                        <strong><?= $_SESSION['email'] ?></strong>
                                        <span class="badge bg-info ms-1"><?= ucfirst($_SESSION['role']) ?></span>
                                    </span>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= $basePath ?>my_bookings.php">
                                    <i class="fas fa-list me-2"></i>Peminjaman Saya</a></li>
                                
                                <?php if (isAdmin()): ?>
                                    <li><a class="dropdown-item" href="<?= $inAdminDir ? 'admin-dashboard.php' : 'admin/admin-dashboard.php' ?>">
                                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard Admin</a></li>
                                <?php endif; ?>
                                
                                <li><a class="dropdown-item" href="<?= $basePath ?>room_availability.php">
                                    <i class="fas fa-search me-2"></i>Cari Ruangan Kosong</a></li>
                                
                                <?php if (isAdmin() || isCS() || isSatpam()): ?>
                                    <li><a class="dropdown-item" href="<?= $inAdminDir ? '../today_schedule.php' : 'today_schedule.php' ?>">
                                        <i class="fas fa-calendar-day me-2"></i>Jadwal Hari Ini</a></li>
                                    
                                    <li><a class="dropdown-item" href="<?= $inAdminDir ? 'room_status.php' : 'admin/room_status.php' ?>">
                                        <i class="fas fa-tv me-2"></i>Status Ruangan</a></li>
                                <?php endif; ?>
                                
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= $basePath ?>logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#loginModal">
                        <i class="fas fa-sign-in-alt me-1"></i> Login
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>