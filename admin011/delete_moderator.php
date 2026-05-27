<?php
include '../includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['deleteadmin_id'])) {
        $deleteadmin_id = $_POST['deleteadmin_id'];

        try {
            $stmt = $pdo->prepare("DELETE FROM admin WHERE admin_id = :admin_id");
            $stmt->bindParam(':admin_id', $deleteadmin_id, PDO::PARAM_INT);
            $stmt->execute();

            header("Location: ./index.php?page=moderators_list"); // Redirect back to the page
            exit;
        } catch (PDOException $e) {
            echo "Error: " . $e->getMessage();
        }
    }
}
?>
