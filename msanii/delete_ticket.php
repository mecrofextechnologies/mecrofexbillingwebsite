<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit();
}

$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($ticket_id <= 0) {
    header("Location: index.php?page=my_tickets");
    exit();
}

// Fetch ticket to show name in confirmation
try {
    $stmt = $pdo->prepare("SELECT title FROM tickets WHERE id = ? AND creator_id = ?");
    $stmt->execute([$ticket_id, $creator_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        header("Location: index.php?page=my_tickets");
        exit();
    }
} catch (Exception $e) {
    die("Error: " . htmlspecialchars($e->getMessage()));
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
    try {
        $pdo->beginTransaction();

        // Delete banner file if exists
        $stmt = $pdo->prepare("SELECT banner FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $banner = $stmt->fetchColumn();
        if ($banner && file_exists("uploads/banners/$banner")) {
            @unlink("uploads/banners/$banner");
        }

        // Delete ticket types
        $pdo->prepare("DELETE FROM event_ticket_types WHERE event_id = ?")->execute([$ticket_id]);

        // Delete ticket
        $pdo->prepare("DELETE FROM tickets WHERE id = ? AND creator_id = ?")
            ->execute([$ticket_id, $creator_id]);

        $pdo->commit();

        // Redirect with success
        header("Location: index.php?page=my_tickets&msg=" . urlencode("Event deleted successfully") . "&type=success");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Failed to delete event: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Delete Event</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">

  <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden">
    <div class="p-8 md:p-10">
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-100 text-red-600 mb-4">
          <i class="fa-solid fa-trash-can text-3xl"></i>
        </div>
        <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-3">Delete Event</h1>
        <p class="text-gray-600">
          Are you sure you want to permanently delete this event?
        </p>
      </div>

      <div class="bg-red-50 border border-red-200 rounded-xl p-5 mb-8">
        <p class="text-red-800 font-medium text-center text-lg break-words">
          "<?= htmlspecialchars($ticket['title'] ?? 'This event') ?>"
        </p>
      </div>

      <?php if (isset($error)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <p class="text-center text-gray-600 mb-8">
        This action <strong>cannot be undone</strong>.<br>
        All related data (ticket types, banner image) will be permanently removed.
      </p>

      <form method="POST" class="flex flex-col sm:flex-row gap-4 justify-center">
        <input type="hidden" name="confirm" value="yes">

        <a href="index.php?page=my_tickets"
           class="px-8 py-4 bg-gray-200 text-gray-800 rounded-xl font-medium hover:bg-gray-300 transition text-center">
          Cancel
        </a>

        <button type="submit"
                class="px-8 py-4 bg-red-600 text-white rounded-xl font-medium hover:bg-red-700 transition shadow-md">
          Yes, Delete Event
        </button>
      </form>
    </div>
  </div>

</body>
</html>