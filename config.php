
<?php
$host = 'localhost';
$db = 'db_booking';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $conn = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Site configuration
$config = [
    'site_name' => 'Sistem Peminjaman Ruangan STIE MCE',
    'site_url' => 'http://localhost/booking-system',
    'admin_email' => 'admin@stie-mce.ac.id',
    'max_booking_hours' => 4, // Maximum hours for a single booking
    'min_booking_hours' => 0.5, // Minimum hours for a single booking
    'booking_start_hour' => 7, // Earliest booking time (24h format)
    'booking_end_hour' => 17, // Latest booking time (24h format)
    'auto_checkout_minutes' => 15, // Auto checkout after booking end time + minutes
];

// Time settings
date_default_timezone_set('Asia/Jakarta');