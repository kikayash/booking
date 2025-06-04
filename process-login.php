<?php
session_start();
require_once 'config.php'; // Sesuaikan path jika diperlukan
require_once 'functions.php'; // Sesuaikan path jika diperlukan

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get credentials from POST data
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

// Validate input
if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Email dan password tidak boleh kosong']);
    exit;
}

// Debug info (aktifkan untuk debugging)
// error_log("Login attempt: Email=$email, Password=$password");

// Check if user exists
$stmt = $conn->prepare("SELECT * FROM tbl_users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Email atau password salah']);
    exit;
}

// PERBAIKAN: Konversi password input ke integer karena di database tipe datanya int(8)
$inputPassword = intval($password);

// Bandingkan dengan password dari database
if ($user['password'] == $inputPassword) {
    // Set session variables
    $_SESSION['logged_in'] = true; // Flag penting untuk cek status login
    $_SESSION['user_id'] = $user['id_user'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    
    // Return success response
    echo json_encode([
        'success' => true, 
        'message' => 'Login berhasil', 
        'user' => [
            'id' => $user['id_user'],
            'email' => $user['email'],
            'role' => $user['role']
        ],
        'redirect' => getRedirectUrlByRole($user['role'])
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Email atau password salah']);
}

// Function to determine redirect URL based on user role
function getRedirectUrlByRole($role) {
    switch ($role) {
        case 'admin':
            return 'admin/admin-dashboard.php';
        case 'mahasiswa':
        case 'dosen':
        case 'karyawan':
            return 'index.php';
        default:
            return 'index.php';
    }
}