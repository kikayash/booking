<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../index.php');
    exit;
}

// Message variable for notifications
$message = '';
$alertType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add or edit building
    if (isset($_POST['save_building'])) {
        $buildingId = $_POST['id_gedung'] ?? '';
        $buildingName = trim($_POST['nama_gedung'] ?? '');
        
        // Validate inputs
        if (empty($buildingName)) {
            $message = 'Nama gedung harus diisi.';
            $alertType = 'danger';
        } else {
            try {
                // Check if it's an edit or add
                if ($buildingId) {
                    // Update existing building
                    $stmt = $conn->prepare("UPDATE tbl_gedung SET nama_gedung = ? WHERE id_gedung = ?");
                    $result = $stmt->execute([$buildingName, $buildingId]);
                    
                    if ($result) {
                        $message = 'Gedung berhasil diperbarui.';
                        $alertType = 'success';
                    } else {
                        $message = 'Gagal memperbarui gedung.';
                        $alertType = 'danger';
                    }
                } else {
                    // Add new building
                    $stmt = $conn->prepare("INSERT INTO tbl_gedung (nama_gedung) VALUES (?)");
                    $result = $stmt->execute([$buildingName]);
                    
                    if ($result) {
                        $message = 'Gedung berhasil ditambahkan.';
                        $alertType = 'success';
                    } else {
                        $message = 'Gagal menambahkan gedung.';
                        $alertType = 'danger';
                    }
                }
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
    
    // Delete building
    if (isset($_POST['delete_building'])) {
        $buildingId = $_POST['building_id'] ?? '';
        
        if ($buildingId) {
            try {
                // Check if building has rooms
                $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_ruang WHERE id_gedung = ?");
                $stmt->execute([$buildingId]);
                $hasRooms = $stmt->fetchColumn() > 0;
                
                if ($hasRooms) {
                    $message = 'Tidak dapat menghapus gedung karena masih ada ruangan yang terkait.';
                    $alertType = 'warning';
                } else {
                    // Delete building
                    $stmt = $conn->prepare("DELETE FROM tbl_gedung WHERE id_gedung = ?");
                    $result = $stmt->execute([$buildingId]);
                    
                    if ($result) {
                        $message = 'Gedung berhasil dihapus.';
                        $alertType = 'success';
                    } else {
                        $message = 'Gagal menghapus gedung.';
                        $alertType = 'danger';
                    }
                }
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
}

// Get all buildings
$stmt = $conn->prepare("SELECT * FROM tbl_gedung ORDER BY nama_gedung");
$stmt->execute();
$buildings = $stmt->fetchAll();

// Set back path for header
$backPath = '../';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Gedung - <?= $config['site_name'] ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
    <header>
        <?php include '../header.php'; ?>
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
                        <a href="rooms.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-door-open me-2"></i> Kelola Ruangan
                        </a>
                        <a href="buildings.php" class="list-group-item list-group-item-action active">
                            <i class="fas fa-door-open me-2"></i> Kelola Gedung
                        </a>
                        <a href="holidays.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-alt me-2"></i> Kelola Hari Libur
                        </a>
                        <a href="../index.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar me-2"></i> Kembali ke Kalender
                        </a>
                        <a href="admin_add_booking.php" class="list-group-item list-group-item-action <?= basename($_SERVER['PHP_SELF']) == 'admin_add_booking.php' ? 'active' : '' ?>">
                            <i class="fas fa-plus-circle me-2"></i> Tambah Booking Manual
                        </a>
                        <a href="rooms_locks.php" class="list-group-item list-group-item-action <?= basename($_SERVER['PHP_SELF']) == 'rooms_locks.php' ? 'active' : '' ?>">
                            <i class="fas fa-lock me-2"></i> Kelola Lock Ruangan
                        </a>
                        <a href="../logout.php" class="list-group-item list-group-item-action text-danger">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Kelola Gedung</h4>
                        <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#addBuildingModal">
                            <i class="fas fa-plus me-1"></i> Tambah Gedung
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $alertType ?> alert-dismissible fade show" role="alert">
                                <?= $message ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Buildings Table -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Nama Gedung</th>
                                        <th>Jumlah Ruangan</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($buildings) > 0): ?>
                                        <?php foreach ($buildings as $building): ?>
                                            <?php
                                            // Get room count for this building
                                            $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_ruang WHERE id_gedung = ?");
                                            $stmt->execute([$building['id_gedung']]);
                                            $roomCount = $stmt->fetchColumn();
                                            ?>
                                            <tr>
                                                <td><?= $building['id_gedung'] ?></td>
                                                <td><?= htmlspecialchars($building['nama_gedung']) ?></td>
                                                <td><?= $roomCount ?> ruangan</td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary mb-1" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editBuildingModal<?= $building['id_gedung'] ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    
                                                    <button type="button" class="btn btn-sm btn-danger mb-1" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#deleteBuildingModal<?= $building['id_gedung'] ?>">
                                                        <i class="fas fa-trash"></i> Hapus
                                                    </button>
                                                </td>
                                            </tr>
                                            
                                            <!-- Edit Building Modal -->
                                            <div class="modal fade" id="editBuildingModal<?= $building['id_gedung'] ?>" tabindex="-1" aria-labelledby="editBuildingModalLabel<?= $building['id_gedung'] ?>" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-primary text-white">
                                                            <h5 class="modal-title" id="editBuildingModalLabel<?= $building['id_gedung'] ?>">
                                                                Edit Gedung
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <form method="post">
                                                                <input type="hidden" name="building_id" value="<?= $building['id_gedung'] ?>">
                                                                
                                                                <div class="mb-3">
                                                                    <label for="nama_gedung<?= $building['id_gedung'] ?>" class="form-label">Nama Gedung</label>
                                                                    <input type="text" class="form-control" id="nama_gedung<?= $building['id_gedung'] ?>" name="nama_gedung" value="<?= htmlspecialchars($building['nama_gedung']) ?>" required>
                                                                </div>
                                                                
                                                                <div class="d-grid">
                                                                    <button type="submit" name="save_building" class="btn btn-primary">Simpan Perubahan</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Delete Building Modal -->
                                            <div class="modal fade" id="deleteBuildingModal<?= $building['id_gedung'] ?>" tabindex="-1" aria-labelledby="deleteBuildingModalLabel<?= $building['id_gedung'] ?>" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-danger text-white">
                                                            <h5 class="modal-title" id="deleteBuildingModalLabel<?= $building['id_gedung'] ?>">
                                                                Konfirmasi Hapus
                                                            </h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Apakah Anda yakin ingin menghapus gedung <strong><?= htmlspecialchars($building['nama_gedung']) ?></strong>?</p>
                                                            <p class="text-danger">
                                                                <i class="fas fa-exclamation-triangle me-1"></i> 
                                                                Gedung hanya dapat dihapus jika tidak ada ruangan yang terkait.
                                                            </p>
                                                            <form method="post">
                                                                <input type="hidden" name="building_id" value="<?= $building['id_gedung'] ?>">
                                                                <div class="d-grid">
                                                                    <button type="submit" name="delete_building" class="btn btn-danger">Hapus Gedung</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-3">Tidak ada data gedung yang ditemukan.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Building Modal -->
    <div class="modal fade" id="addBuildingModal" tabindex="-1" aria-labelledby="addBuildingModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addBuildingModalLabel">Tambah Gedung</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post">
                        <div class="mb-3">
                            <label for="nama_gedung_new" class="form-label">Nama Gedung</label>
                            <input type="text" class="form-control" id="nama_gedung_new" name="nama_gedung" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="save_building" class="btn btn-primary">Tambah Gedung</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <footer class="mt-5 py-3 bg-light">
        <?php include '../footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</body>
</html>