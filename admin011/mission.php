<?php
// mission.php or inside index.php?page=mission
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/connect.php';

$message = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description']);
    $how = trim($_POST['how_we_achieve_our_mission']);

    // Check if a mission record already exists
    $check = $pdo->query("SELECT id FROM mission LIMIT 1");
    if ($check->rowCount() > 0) {
        $row = $check->fetch();
        $stmt = $pdo->prepare("UPDATE mission SET description = ?, how_we_achieve_our_mission = ? WHERE id = ?");
        $stmt->execute([$description, $how, $row['id']]);
        $message = "Mission updated successfully.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO mission (description, how_we_achieve_our_mission) VALUES (?, ?)");
        $stmt->execute([$description, $how]);
        $message = "Mission added successfully.";
    }
}

// Handle delete (only if needed, optional)
if (isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM mission WHERE id = ?");
    $stmt->execute([$_GET['delete_id']]);
    $message = "Mission deleted.";
}

// Load current mission (if exists)
$current = $pdo->query("SELECT * FROM mission LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
    <h3>Mission Management</h3>

    <?php if (!empty($message)): ?>
        <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>

    <form method="POST" class="border p-3 mb-4">
        <div class="form-group">
            <label>Mission Description</label>
            <textarea class="form-control" name="description" required><?php echo $current['description'] ?? ''; ?></textarea>
        </div>
        <div class="form-group">
            <label>How We Achieve Our Mission</label>
            <textarea class="form-control" name="how_we_achieve_our_mission" required><?php echo $current['how_we_achieve_our_mission'] ?? ''; ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo $current ? 'Update' : 'Add'; ?> Mission</button>
        <?php if ($current): ?>
            <a href="index.php?page=mission&delete_id=<?php echo $current['id']; ?>" onclick="return confirm('Are you sure you want to delete this mission?')" class="btn btn-danger ml-2">Delete</a>
        <?php endif; ?>
    </form>

    <?php if ($current): ?>
        <h5>Current Mission Record</h5>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>How We Achieve Our Mission</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo htmlspecialchars($current['description']); ?></td>
                    <td><?php echo htmlspecialchars($current['how_we_achieve_our_mission']); ?></td>
                </tr>
            </tbody>
        </table>
    <?php else: ?>
        <p>No mission record found yet.</p>
    <?php endif; ?>
</div>
