<?php
// vision.php or inside index.php?page=vision
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/connect.php';

$message = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description']);
    $how = trim($_POST['how_we_achieve_our_vision']);

    // Check if a vision record already exists
    $check = $pdo->query("SELECT id FROM vision LIMIT 1");
    if ($check->rowCount() > 0) {
        $row = $check->fetch();
        $stmt = $pdo->prepare("UPDATE vision SET description = ?, how_we_achieve_our_vision = ? WHERE id = ?");
        $stmt->execute([$description, $how, $row['id']]);
        $message = "Vision updated successfully.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO vision (description, how_we_achieve_our_vision) VALUES (?, ?)");
        $stmt->execute([$description, $how]);
        $message = "Vision added successfully.";
    }
}

// Handle delete (only if needed, optional)
if (isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM vision WHERE id = ?");
    $stmt->execute([$_GET['delete_id']]);
    $message = "Vision deleted.";
}

// Load current vision (if exists)
$current = $pdo->query("SELECT * FROM vision LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
    <h3>Vision Management</h3>

    <?php if (!empty($message)): ?>
        <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>

    <form method="POST" class="border p-3 mb-4">
        <div class="form-group">
            <label>Vision Description</label>
            <textarea class="form-control" name="description" required><?php echo $current['description'] ?? ''; ?></textarea>
        </div>
        <div class="form-group">
            <label>How We Achieve Our Vision</label>
            <textarea class="form-control" name="how_we_achieve_our_vision" required><?php echo $current['how_we_achieve_our_vision'] ?? ''; ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo $current ? 'Update' : 'Add'; ?> Vision</button>
        <?php if ($current): ?>
            <a href="index.php?page=vision&delete_id=<?php echo $current['id']; ?>" onclick="return confirm('Are you sure you want to delete this vision?')" class="btn btn-danger ml-2">Delete</a>
        <?php endif; ?>
    </form>

    <?php if ($current): ?>
        <h5>Current Vision Record</h5>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>How We Achieve Our Vision</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo htmlspecialchars($current['description']); ?></td>
                    <td><?php echo htmlspecialchars($current['how_we_achieve_our_vision']); ?></td>
                </tr>
            </tbody>
        </table>
    <?php else: ?>
        <p>No vision record found yet.</p>
    <?php endif; ?>
</div>
