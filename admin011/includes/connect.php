<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$db_dsn = 'mysql:host=localhost;dbname=payroll'; // Database connection string
$db_user_name = 'root'; // Database username
$db_user_pass = ''; // Database password

try {
    // Create a new PDO instance
    $pdo = new PDO($db_dsn, $db_user_name, $db_user_pass);
    // Set PDO error mode to exception for better error handling
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Display error message if connection fails
    echo 'Connection failed: ' . $e->getMessage();
    exit; // Stop the script if connection fails
}
?>
