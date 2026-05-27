<?php
include '../includes/connect.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_event'])) {
            // Add new event
            $title = $_POST['title'];
            $description = $_POST['description'];
            $date = $_POST['date'];

            $stmt = $pdo->prepare("INSERT INTO all_events (title, description, date) VALUES (:title, :description, :date)");
            $stmt->execute(['title' => $title, 'description' => $description, 'date' => $date]);
            $success_message = "Event added successfully!";
        } elseif (isset($_POST['add_calendar_entry'])) {
            // Add new calendar entry
            $event_name = $_POST['event_name'];
            $event_date = $_POST['event_date'];
            $details = $_POST['details'];

            $stmt = $pdo->prepare("INSERT INTO academic_calendar (event_name, event_date, details) VALUES (:event_name, :event_date, :details)");
            $stmt->execute(['event_name' => $event_name, 'event_date' => $event_date, 'details' => $details]);
            $success_message = "Calendar entry added successfully!";
        }
    } catch (PDOException $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Fetch events and calendar entries
$events = $pdo->query("SELECT * FROM all_events ORDER BY date ASC")->fetchAll(PDO::FETCH_ASSOC);
$calendar = $pdo->query("SELECT * FROM academic_calendar ORDER BY event_date ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Manage Events & Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container my-4">
        <h1 class="text-center">Admin Panel - Manage Events & Calendar</h1>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Add Event Form -->
        <div class="card mb-4">
            <div class="card-header">Add New Event</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="title" class="form-label">Event Title</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Event Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="date" class="form-label">Event Date</label>
                        <input type="date" class="form-control" id="date" name="date" required>
                    </div>
                    <button type="submit" name="add_event" class="btn btn-primary">Add Event</button>
                </form>
            </div>
        </div>

        <!-- Add Calendar Entry Form -->
        <div class="card mb-4">
            <div class="card-header">Add Academic Calendar Entry</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label for="event_name" class="form-label">Event Name</label>
                        <input type="text" class="form-control" id="event_name" name="event_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="event_date" class="form-label">Event Date</label>
                        <input type="date" class="form-control" id="event_date" name="event_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="details" class="form-label">Details</label>
                        <textarea class="form-control" id="details" name="details" rows="3" required></textarea>
                    </div>
                    <button type="submit" name="add_calendar_entry" class="btn btn-primary">Add Calendar Entry</button>
                </form>
            </div>
        </div>

        <!-- Events List -->
        <div class="card mb-4">
            <div class="card-header">Events</div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($event['title']); ?></td>
                                <td><?php echo htmlspecialchars($event['description']); ?></td>
                                <td><?php echo htmlspecialchars($event['date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Academic Calendar List -->
        <div class="card">
            <div class="card-header">Academic Calendar</div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Event Name</th>
                            <th>Date</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calendar as $entry): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($entry['event_name']); ?></td>
                                <td><?php echo htmlspecialchars($entry['event_date']); ?></td>
                                <td><?php echo htmlspecialchars($entry['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
