<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../index.php');
    exit;
}

$message = '';
$alertType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_schedule') {
        $scheduleData = [
            'id_ruang' => intval($_POST['id_ruang']),
            'nama_matakuliah' => trim($_POST['nama_matakuliah']),
            'kelas' => trim($_POST['kelas']),
            'dosen_pengampu' => trim($_POST['dosen_pengampu']),
            'hari' => $_POST['hari'],
            'jam_mulai' => $_POST['jam_mulai'],
            'jam_selesai' => $_POST['jam_selesai'],
            'semester' => trim($_POST['semester']),
            'tahun_akademik' => trim($_POST['tahun_akademik']),
            'start_date' => $_POST['start_date'],
            'end_date' => $_POST['end_date'],
            'created_by' => $_SESSION['user_id']
        ];
        
        $result = addRecurringSchedule($conn, $scheduleData);
        
        if ($result['success']) {
            $message = "Jadwal perkuliahan berhasil ditambahkan! Generated {$result['generated_bookings']} booking otomatis.";
            $alertType = 'success';
        } else {
            $message = "Gagal menambahkan jadwal: " . $result['message'];
            $alertType = 'danger';
        }
    }
    
    if ($action === 'delete_schedule') {
        $scheduleId = intval($_POST['schedule_id']);
        
        // Hapus booking terkait terlebih dahulu
        $deletedBookings = removeRecurringScheduleBookings($conn, $scheduleId);
        
        // Hapus jadwal
        $stmt = $conn->prepare("DELETE FROM tbl_recurring_schedules WHERE id_schedule = ?");
        $result = $stmt->execute([$scheduleId]);
        
        if ($result) {
            $message = "Jadwal berhasil dihapus! $deletedBookings booking masa depan telah dihapus.";
            $alertType = 'success';
        } else {
            $message = "Gagal menghapus jadwal";
            $alertType = 'danger';
        }
    }
    
    if ($action === 'generate_schedules') {
        $startDate = $_POST['generate_start_date'];
        $endDate = $_POST['generate_end_date'];
        
        $generated = generateScheduleForDateRange($conn, $startDate, $endDate);
        
        $message = "Berhasil generate $generated booking dari jadwal perkuliahan!";
        $alertType = 'success';
    }
}

// Get all recurring schedules
$stmt = $conn->prepare("
    SELECT rs.*, r.nama_ruang, g.nama_gedung, u.email as created_by_email
    FROM tbl_recurring_schedules rs
    JOIN tbl_ruang r ON rs.id_ruang = r.id_ruang
    LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung
    JOIN tbl_users u ON rs.created_by = u.id_user
    ORDER BY rs.hari, rs.jam_mulai
");
$stmt->execute();
$schedules = $stmt->fetchAll();

// Get all rooms for dropdown
$stmt = $conn->prepare("SELECT r.*, g.nama_gedung FROM tbl_ruang r LEFT JOIN tbl_gedung g ON r.id_gedung = g.id_gedung ORDER BY g.nama_gedung, r.nama_ruang");
$stmt->execute();
$rooms = $stmt->fetchAll();

// Mapping hari
$dayMapping = [
    'monday' => 'Senin',
    'tuesday' => 'Selasa', 
    'wednesday' => 'Rabu',
    'thursday' => 'Kamis',
    'friday' => 'Jumat',
    'saturday' => 'Sabtu',
    'sunday' => 'Minggu'
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Jadwal Perkuliahan - STIE MCE</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
    <header>
        <?php $backPath = '../'; include '../header.php'; ?>
    </header>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Menu Admin</h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="admin-dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                        <a href="recurring_schedules.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-calendar-week me-2"></i> Jadwal Perkuliahan
                        </a>
                        <a href="rooms.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-door-open me-2"></i> Kelola Ruangan
                        </a>
                        <a href="buildings.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-building me-2"></i> Kelola Gedung
                        </a>
                        <a href="holidays.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-alt me-2"></i> Kelola Hari Libur
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Add New Schedule -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Tambah Jadwal Perkuliahan Berulang</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="add_schedule">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Mata Kuliah</label>
                                        <input type="text" class="form-control" name="nama_matakuliah" required placeholder="contoh: Financial Accounting 2">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Kelas</label>
                                        <input type="text" class="form-control" name="kelas" required placeholder="contoh: FA2">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Dosen Pengampu</label>
                                        <input type="text" class="form-control" name="dosen_pengampu" required placeholder="Nama dosen">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Ruangan</label>
                                        <select class="form-select" name="id_ruang" required>
                                            <option value="">-- Pilih Ruangan --</option>
                                            <?php foreach ($rooms as $room): ?>
                                                <option value="<?= $room['id_ruang'] ?>">
                                                    <?= $room['nama_ruang'] ?> (<?= $room['nama_gedung'] ?>) - Kapasitas: <?= $room['kapasitas'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Hari</label>
                                        <select class="form-select" name="hari" required>
                                            <option value="">-- Pilih Hari --</option>
                                            <option value="monday">Senin</option>
                                            <option value="tuesday">Selasa</option>
                                            <option value="wednesday">Rabu</option>
                                            <option value="thursday">Kamis</option>
                                            <option value="friday">Jumat</option>
                                            <option value="saturday">Sabtu</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Jam Mulai</label>
                                        <input type="time" class="form-control" name="jam_mulai" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Jam Selesai</label>
                                        <input type="time" class="form-control" name="jam_selesai" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Semester</label>
                                        <select class="form-select" name="semester" required>
                                            <option value="">-- Pilih --</option>
                                            <option value="Ganjil">Ganjil</option>
                                            <option value="Genap">Genap</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Tahun Akademik</label>
                                        <input type="text" class="form-control" name="tahun_akademik" required placeholder="contoh: 2024/2025">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Tanggal Mulai Perkuliahan</label>
                                        <input type="date" class="form-control" name="start_date" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Tanggal Selesai Perkuliahan</label>
                                        <input type="date" class="form-control" name="end_date" required>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Simpan Jadwal
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Generate Schedules Tool -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Generate Booking Otomatis</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="d-flex gap-3 align-items-end">
                            <input type="hidden" name="action" value="generate_schedules">
                            
                            <div>
                                <label class="form-label">Dari Tanggal</label>
                                <input type="date" class="form-control" name="generate_start_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            
                            <div>
                                <label class="form-label">Sampai Tanggal</label>
                                <input type="date" class="form-control" name="generate_end_date" value="<?= date('Y-m-d', strtotime('+1 month')) ?>" required>
                            </div>
                            
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-robot me-2"></i>Generate Booking
                            </button>
                        </form>
                        
                        <div class="mt-2">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Tool ini akan membuat booking otomatis berdasarkan jadwal perkuliahan berulang yang sudah ada, 
                                dengan mempertimbangkan hari libur dan konflik jadwal.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Existing Schedules -->
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Jadwal Perkuliahan Berulang</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($schedules) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Mata Kuliah</th>
                                            <th>Dosen</th>
                                            <th>Hari & Waktu</th>
                                            <th>Ruangan</th>
                                            <th>Periode</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($schedules as $schedule): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($schedule['nama_matakuliah']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($schedule['kelas']) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($schedule['dosen_pengampu']) ?></td>
                                                <td>
                                                    <strong><?= $dayMapping[$schedule['hari']] ?></strong><br>
                                                    <?= date('H:i', strtotime($schedule['jam_mulai'])) ?> - <?= date('H:i', strtotime($schedule['jam_selesai'])) ?>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($schedule['nama_ruang']) ?><br>
                                                    <small class="text-muted"><?= htmlspecialchars($schedule['nama_gedung']) ?></small>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($schedule['semester']) ?> <?= htmlspecialchars($schedule['tahun_akademik']) ?><br>
                                                    <small class="text-muted"><?= date('d/m/Y', strtotime($schedule['start_date'])) ?> - <?= date('d/m/Y', strtotime($schedule['end_date'])) ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($schedule['status'] === 'active'): ?>
                                                        <span class="badge bg-success">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Nonaktif</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Hapus jadwal ini? Semua booking masa depan akan dihapus!')">
                                                        <input type="hidden" name="action" value="delete_schedule">
                                                        <input type="hidden" name="schedule_id" value="<?= $schedule['id_schedule'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                <p class="text-muted">Belum ada jadwal perkuliahan berulang.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include '../footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>