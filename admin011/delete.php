<?php 
// Include the database connection file
include '../includes/connect.php'; // Adjust the path if necessary

// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in and has the correct role
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], [1, 2])) {
    echo "You do not have permission to perform this action.";
    exit;
}

// Check if the request method is POST and the ID is set and valid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id']) && is_numeric($_POST['id'])) {
    $admin_id = (int)$_POST['id'];

    try {
        // Prepare SQL query to delete the record
        $stmt = $pdo->prepare("DELETE FROM admin WHERE admin_id = :admin_id");
        $stmt->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
        $stmt->execute();

        // Check if any rows were affected (i.e., if the deletion was successful)
        if ($stmt->rowCount() > 0) {
            echo 'success';
        } else {
            echo 'error'; // Record not found or could not be deleted
        }
    } catch (PDOException $e) {
        echo 'error'; // Error occurred
        error_log("PDO Exception: " . $e->getMessage()); // Log error message
    }
} else {
    echo 'error'; // Invalid request
}
?>
