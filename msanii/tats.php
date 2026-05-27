<?php
// tats.php - Visitor statistics dashboard

ini_set('display_errors', 1);
error_reporting(E_ALL);

include '../includes/connect.php';

// Fetch all visitors
try {
    $stmt = $pdo->prepare("SELECT ip_address, user_agent, visit_time, visit_count, city, region, country 
                           FROM website_views 
                           ORDER BY visit_time DESC");
    $stmt->execute();
    $visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Website Visitors Stats</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- mobile responsive -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f7f9fc;
            padding: 30px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        h3 {
            font-weight: 600;
            color: #3083B5;
        }
        table {
            font-size: 0.9rem;
        }
        th {
            background: #3083B5;
            color: white;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card p-4">
        <h3 class="mb-4 text-center">🌍 Website Visitors Statistics</h3>
        
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>IP Address</th>
                        <th>Country</th>
                        <th>Region</th>
                        <th>City</th>
                        <th>Visit Time</th>
                        <th>Visit Count</th>
                        <th>User Agent</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($visitors): ?>
                    <?php foreach ($visitors as $i => $row): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($row['ip_address']) ?></td>
                            <td><?= htmlspecialchars($row['country']) ?></td>
                            <td><?= htmlspecialchars($row['region']) ?></td>
                            <td><?= htmlspecialchars($row['city']) ?></td>
                            <td><?= date('d M Y, h:i A', strtotime($row['visit_time'])) ?></td>
                            <td><span class="badge bg-info"><?= $row['visit_count'] ?></span></td>
                            <td style="max-width:250px; overflow:auto;"><?= htmlspecialchars($row['user_agent']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">No visitors logged yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
