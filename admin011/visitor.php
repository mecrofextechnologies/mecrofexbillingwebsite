<?php
// visitor.php - Show visitor details

ini_set('display_errors', 1);
error_reporting(E_ALL);

include '../includes/connect.php';

// Force Nairobi timezone
date_default_timezone_set('Africa/Nairobi');

$visitor = null;
$error = '';

// Get visitor ID from URL
$visitorId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($visitorId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, user_agent, ip_address, city, country, visit_time 
                               FROM website_views 
                               WHERE id = ?");
        $stmt->execute([$visitorId]);
        $visitor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visitor) {
            $error = "Visitor not found.";
        }
    } catch (Exception $e) {
        $error = "Database error: " . $e->getMessage();
    }
} else {
    $error = "Invalid visitor ID.";
}

/**
 * Convert datetime into human-readable "time ago"
 */
function timeAgo($datetime) {
    if (empty($datetime)) return "No time recorded";

    $now = new DateTime('now', new DateTimeZone('Africa/Nairobi'));
    $time = new DateTime($datetime, new DateTimeZone('Africa/Nairobi'));
    $diff = $now->getTimestamp() - $time->getTimestamp();

    if ($diff < 60) {
        return "just now";
    }

    $units = [
        31536000 => "year",
        2592000  => "month",
        604800   => "week",
        86400    => "day",
        3600     => "hour",
        60       => "minute"
    ];

    foreach ($units as $secs => $label) {
        if ($diff >= $secs) {
            $value = floor($diff / $secs);
            return $value . " " . $label . ($value > 1 ? "s" : "") . " ago";
        }
    }

    return "just now";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visitor Details</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- mobile responsive -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f5f6fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            max-width: 700px;
            width: 100%;
        }
        .card h3 {
            font-weight: 600;
            color: #3083B5;
        }
        .info-label {
            font-weight: 600;
            color: #555;
        }
        #clock {
            font-weight: bold;
            color: #3083B5;
        }
        @media (max-width: 767.98px) {
            #clock-container {
                text-align: center;
                margin-top: 15px;
            }
        }
    </style>
</head>
<body>

<div class="card p-4 bg-white">
    <?php if ($error): ?>
        <div class="alert alert-danger text-center">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php elseif ($visitor): ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <h3 class="mb-3">👤 Visitor Details</h3>
            <div id="clock-container">
                <span id="clock"></span>
            </div>
        </div>

        <ul class="list-group list-group-flush">
            <li class="list-group-item"><span class="info-label">IP Address:</span> <?= htmlspecialchars($visitor['ip_address']) ?></li>
            <li class="list-group-item"><span class="info-label">Location:</span> <?= htmlspecialchars($visitor['city']) ?>, <?= htmlspecialchars($visitor['country']) ?></li>
            <li class="list-group-item"><span class="info-label">User Agent:</span> <?= htmlspecialchars($visitor['user_agent']) ?></li>
            <li class="list-group-item">
                <span class="info-label">Visit Time:</span>
                <?= htmlspecialchars($visitor['visit_time']) ?> (<?= timeAgo($visitor['visit_time']) ?>)
            </li>
        </ul>
        <div class="text-center mt-4">
            <a href="stats.php" class="btn btn-primary">⬅ Back to Visitors</a>
        </div>
    <?php endif; ?>
</div>

<script>
// Live Nairobi clock
function updateClock() {
    const options = {
        timeZone: "Africa/Nairobi",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit"
    };
    const formatter = new Intl.DateTimeFormat([], options);
    document.getElementById("clock").textContent = "⏰ " + formatter.format(new Date());
}
setInterval(updateClock, 1000);
updateClock();
</script>

</body>
</html>
