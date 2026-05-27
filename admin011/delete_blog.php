<?php
include '../includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['delete_id'])) {
        $deleteId = $_POST['delete_id'];

        try {
            $stmt = $pdo->prepare("DELETE FROM blogs WHERE id = :id");
            $stmt->bindParam(':id', $deleteId, PDO::PARAM_INT);
            $stmt->execute();

            header("Location: ./index.php?page=media_list"); // Redirect back to the page
            exit;
        } catch (PDOException $e) {
            echo "Error: " . $e->getMessage();
        }
    }
}
?>
