<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_dsn = 'mysql:host=localhost;dbname=keychele_website';
$db_user_name = 'keychele_medolist';
$db_user_pass = 'King20241064.';

try {
    $pdo = new PDO($db_dsn, $db_user_name, $db_user_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
    exit;
}
?>