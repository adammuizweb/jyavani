<?php
// /lpmi-cfg/db.php
try {
    $dsn = "mysql:host=" . env('DB_HOST') .
           ";dbname=" . env('DB_NAME') .
           ";port=" . env('DB_PORT', 3306) .
           ";charset=utf8mb4";

    $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}
