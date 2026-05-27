<?php
// IMPORTANT: Assumes session_start() is called elsewhere (header or main file)
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
// ── Fetch main event/ticket ─────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT * FROM tickets
        WHERE id = ? AND creator_id = ?
    ");
    $stmt->execute([$ticket_id, $creator_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        header("Location: index.php?page=my_tickets");
        exit();
    }
} catch (Exception $e) {
    die("Error loading event: " . htmlspecialchars($e->getMessage()));
}

// ── Helper: convert "HH:MM:SS" → ['h'=>'h','m'=>'mm','ampm'=>'AM/PM'] ──
function parseTime12($timeStr) {
    if (empty($timeStr)) return ['h' => '12', 'm' => '00', 'ampm' => 'AM'];
    $parts = explode(':', $timeStr);
    $h     = (int)($parts[0] ?? 0);
    $m     = str_pad($parts[1] ?? '0', 2, '0', STR_PAD_LEFT);
    $ampm  = $h >= 12 ? 'PM' : 'AM';
    $h12   = $h % 12;
    if ($h12 === 0) $h12 = 12;
    return ['h' => (string)$h12, 'm' => $m, 'ampm' => $ampm];
}
$st = parseTime12($ticket['start_time'] ?? '');
$et = parseTime12($ticket['end_time']   ?? '');

// ── Fetch existing ticket types ────────────────────────────────
$ticket_types = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM event_ticket_types
        WHERE event_id = ?
        ORDER BY id
    ");
    $stmt->execute([$ticket_id]);
    $ticket_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // silent fail — types just won't show
}

// ── Handle form submission ─────────────────────────────────────
$errors  = [];
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $title       = trim($_POST['title']       ?? '');
    $venue       = trim($_POST['venue']       ?? '');
    $category    = trim($_POST['category']    ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date  = $_POST['start_date']  ?? '';
    $start_time  = $_POST['start_time']  ?? '';   // comes as HH:MM from hidden input
    $end_date    = $_POST['end_date']    ?? '';
    $end_time    = $_POST['end_time']    ?? '';   // comes as HH:MM from hidden input
    $deadline    = $_POST['deadline']    ?? null;

    // Basic validation
    if (empty($title))      $errors[] = "Event title is required";
    if (empty($venue))      $errors[] = "Venue is required";
    if (empty($start_date)) $errors[] = "Start date is required";
    if (empty($start_time)) $errors[] = "Start time is required";
    if (empty($end_date))   $errors[] = "End date is required";
    if (empty($end_time))   $errors[] = "End time is required";

    // Banner handling
    $banner = $ticket['banner'] ?? null;
    if (!empty($_FILES['banner']['name']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
        $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $max_size = 5 * 1024 * 1024;
        $ext      = strtolower(pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $errors[] = "Only JPG, JPEG, PNG, GIF, WEBP allowed";
        } elseif ($_FILES['banner']['size'] > $max_size) {
            $errors[] = "Image too large (max 5MB)";
        } else {
            $new_name    = time() . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
            $upload_path = 'uploads/banners/' . $new_name;
            if (move_uploaded_file($_FILES['banner']['tmp_name'], $upload_path)) {
                // Delete old banner if exists
                if ($ticket['banner'] && file_exists('uploads/banners/' . $ticket['banner'])) {
                    @unlink('uploads/banners/' . $ticket['banner']);
                }
                $banner = $new_name;
            } else {
                $errors[] = "Failed to upload banner";
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Update main ticket/event
            $stmt = $pdo->prepare("
                UPDATE tickets SET
                    title = ?, venue = ?, category = ?, description = ?,
                    start_date = ?, start_time = ?, end_date = ?, end_time = ?,
                    deadline = ?,
                    banner = ?
                WHERE id = ? AND creator_id = ?
            ");
            $stmt->execute([
                $title, $venue, $category ?: null, $description ?: null,
                $start_date, $start_time, $end_date, $end_time,
                $deadline,
                $banner,
                $ticket_id, $creator_id
            ]);

            // ── Handle ticket types ─────────────────────────────────
            $pdo->prepare("DELETE FROM event_ticket_types WHERE event_id = ?")
                ->execute([$ticket_id]);

            $types   = $_POST['ticket_type']  ?? [];
            $prices  = $_POST['ticket_price'] ?? [];
            $qtys    = $_POST['ticket_qty']   ?? [];
            $is_free = $_POST['is_free']      ?? [];

            foreach ($types as $i => $type_name) {
                $type_name = trim($type_name);
                if (empty($type_name)) continue;
                $free_flag = isset($is_free[$i]) && $is_free[$i] === '1' ? 1 : 0;
                $price_val = $free_flag ? 0 : (float)($prices[$i] ?? 0);
                $qty_val   = !empty($qtys[$i]) ? (int)$qtys[$i] : 0;

                $stmt = $pdo->prepare("
                    INSERT INTO event_ticket_types
                    (event_id, type_name, price, quantity_available, is_free)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$ticket_id, $type_name, $price_val, $qty_val, $free_flag]);
            }

            $pdo->commit();
            $success = true;

            // Re-fetch updated ticket so values reflect in the form
            $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ? AND creator_id = ?");
            $stmt->execute([$ticket_id, $creator_id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            $st     = parseTime12($ticket['start_time'] ?? '');
            $et     = parseTime12($ticket['end_time']   ?? '');

            // Re-fetch ticket types
            $stmt = $pdo->prepare("SELECT * FROM event_ticket_types WHERE event_id = ? ORDER BY id");
            $stmt->execute([$ticket_id]);
            $ticket_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Event</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
  <style>
    @keyframes bounceIn {
      0%   { transform: scale(0.3); opacity: 0; }
      50%  { transform: scale(1.1); }
      70%  { transform: scale(0.9); }
      100% { transform: scale(1);   opacity: 1; }
    }
    .bounce-check { animation: bounceIn 0.8s ease-out; }

    /* Uniform select arrow on all browsers */
    select { appearance: auto; -webkit-appearance: auto; }
  </style>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- Preloader -->
<div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm transition-opacity duration-300">
  <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
    <div class="h-5 w-5 border-2 border-green-500 border-t-transparent rounded-full animate-spin"></div>
    <span class="text-gray-700 font-medium">Loading... Please wait!</span>
  </div>
</div>

<div class="p-4 md:p-6">
  <!-- Header -->
  <div class="bg-gradient-to-r from-purple-600 to-blue-600 p-6 rounded-2xl shadow-lg text-white mb-8">
    <h1 class="text-2xl md:text-3xl font-bold">Edit Event</h1>
    <p class="opacity-90 mt-1">Update event details &amp; ticket types</p>
  </div>

  <div class="bg-white rounded-2xl shadow-md p-6 md:p-10 max-w-4xl mx-auto">

    <!-- Errors -->
    <?php if (!empty($errors)): ?>
      <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-5 mb-8 rounded-xl shadow-lg">
        <div class="flex items-start gap-3">
          <i class="fa-solid fa-triangle-exclamation text-xl mt-1"></i>
          <div>
            <strong class="block mb-1">Please correct the following:</strong>
            <ul class="list-disc pl-5 space-y-1">
              <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="edit-event-form" class="space-y-8">
      <input type="hidden" name="action" value="update">

      <!-- ══ EVENT INFORMATION ════════════════════════════════════ -->
      <div class="space-y-6">
        <h2 class="text-xl font-semibold text-gray-800 border-b pb-2">Event Information</h2>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Event Title *</label>
          <input type="text" name="title" required
                 value="<?= htmlspecialchars($ticket['title'] ?? '') ?>"
                 class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Venue *</label>
          <input type="text" name="venue" required
                 value="<?= htmlspecialchars($ticket['venue'] ?? '') ?>"
                 class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
          <select name="category" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none bg-white">
            <option value="">Select category</option>
            <?php foreach (['Concert','Comedy','Party','Workshop','Conference','Other'] as $cat):
              $label = $cat === 'Party' ? 'Party / Club' : $cat; ?>
              <option value="<?= $cat ?>" <?= ($ticket['category'] ?? '') === $cat ? 'selected' : '' ?>>
                <?= $label ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
          <textarea name="description" rows="4"
                    class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none"><?= htmlspecialchars($ticket['description'] ?? '') ?></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Event Banner</label>
          <?php if (!empty($ticket['banner'])): ?>
            <div class="mb-4">
              <p class="text-sm text-gray-600 mb-2">Current:</p>
              <img src="uploads/banners/<?= htmlspecialchars($ticket['banner']) ?>"
                   alt="Banner" class="max-h-48 rounded-lg shadow object-cover">
            </div>
          <?php endif; ?>
          <input type="file" name="banner" accept="image/*"
                 class="block w-full text-sm text-gray-700 file:mr-4 file:py-2 file:px-5 file:rounded-lg file:border-0 file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100">
          <p class="mt-1.5 text-xs text-gray-500">Max 5MB — JPG, PNG, WEBP, GIF</p>
        </div>
      </div>

      <!-- ══ DATE & TIME ══════════════════════════════════════════ -->
      <div class="space-y-6">
        <h2 class="text-xl font-semibold text-gray-800 border-b pb-2">Date &amp; Time</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

          <!-- Start Date -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Start Date *</label>
            <input type="date" name="start_date" required
                   value="<?= htmlspecialchars($ticket['start_date'] ?? '') ?>"
                   class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none">
          </div>

          <!-- Start Time — custom AM/PM picker -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Start Time *</label>
            <input type="hidden" name="start_time" id="start_time_val">
            <div class="flex gap-2">
              <!-- Hour -->
              <select id="start_h"
                      class="flex-1 px-3 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none bg-white time-sel"
                      aria-label="Start hour">
                <?php for ($i = 1; $i <= 12; $i++): ?>
                  <option value="<?= $i ?>" <?= $st['h'] === (string)$i ? 'selected' : '' ?>>
                    <?= str_pad($i, 2, '0', STR_PAD_LEFT) ?>
                  </option>
                <?php endfor; ?>
              </select>
              <!-- Minute -->
              <select id="start_m"
                      class="flex-1 px-3 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none bg-white time-sel"
                      aria-label="Start minute">
                <?php foreach (['00','05','10','15','20','25','30','35','40','45','50','55'] as $min): ?>
                  <option value="<?= $min ?>" <?= $st['m'] === $min ? 'selected' : '' ?>><?= $min ?></option>
                <?php endforeach; ?>
              </select>
              <!-- AM / PM -->
              <select id="start_ampm"
                      class="px-3 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none bg-white font-semibold text-purple-700 time-sel"
                      aria-label="Start AM or PM">
                <option value="AM" <?= $st['ampm'] === 'AM' ? 'selected' : '' ?>>AM</option>
                <option value="PM" <?= $st['ampm'] === 'PM' ? 'selected' : '' ?>>PM</option>
              </select>
            </div>
            <p class="mt-1 text-xs text-gray-400">Hour : Minute : AM/PM</p>
          </div>

          <!-- End Date -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">End Date *</label>
            <input type="date" name="end_date" required
                   value="<?= htmlspecialchars($ticket['end_date'] ?? '') ?>"
                   class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none">
          </div>

          <!-- End Time — custom AM/PM picker -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">End Time *</label>
            <input type="hidden" name="end_time" id="end_time_val">
            <div class="flex gap-2">
              <!-- Hour -->
              <select id="end_h"
                      class="flex-1 px-3 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none bg-white time-sel"
                      aria-label="End hour">
                <?php for ($i = 1; $i <= 12; $i++): ?>
                  <option value="<?= $i ?>" <?= $et['h'] === (string)$i ? 'selected' : '' ?>>
                    <?= str_pad($i, 2, '0', STR_PAD_LEFT) ?>
                  </option>
                <?php endfor; ?>
              </select>
              <!-- Minute -->
              <select id="end_m"
                      class="flex-1 px-3 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none bg-white time-sel"
                      aria-label="End minute">
                <?php foreach (['00','05','10','15','20','25','30','35','40','45','50','55'] as $min): ?>
                  <option value="<?= $min ?>" <?= $et['m'] === $min ? 'selected' : '' ?>><?= $min ?></option>
                <?php endforeach; ?>
              </select>
              <!-- AM / PM -->
              <select id="end_ampm"
                      class="px-3 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none bg-white font-semibold text-purple-700 time-sel"
                      aria-label="End AM or PM">
                <option value="AM" <?= $et['ampm'] === 'AM' ? 'selected' : '' ?>>AM</option>
                <option value="PM" <?= $et['ampm'] === 'PM' ? 'selected' : '' ?>>PM</option>
              </select>
            </div>
            <p class="mt-1 text-xs text-gray-400">Hour : Minute : AM/PM</p>
          </div>

          <!-- Ticket Sales Deadline -->
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Ticket Sales Deadline *</label>
            <input type="date" name="deadline" required
                   value="<?= htmlspecialchars($ticket['deadline'] ?? '') ?>"
                   class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-purple-500 outline-none">
            <p class="mt-1.5 text-xs text-gray-500">Last day for ticket purchases (usually before or on event day)</p>
          </div>

        </div>
      </div>

      <!-- ══ TICKET TYPES ═════════════════════════════════════════ -->
      <div class="space-y-6">
        <h2 class="text-xl font-semibold text-gray-800 border-b pb-2">Ticket Types</h2>
        <p class="text-gray-600 text-sm">You can edit, add or remove ticket types. Free and paid tickets supported.</p>

        <div id="ticket-types-container" class="space-y-6">
          <?php if (!empty($ticket_types)): ?>
            <?php foreach ($ticket_types as $idx => $t): ?>
              <div class="ticket-type-block border border-gray-300 rounded-xl p-5 bg-gray-50 relative">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
                  <input type="text" name="ticket_type[]" value="<?= htmlspecialchars($t['type_name']) ?>" required
                         placeholder="e.g. Regular, VIP"
                         class="font-medium text-lg text-gray-800 bg-transparent border-b border-gray-400 focus:border-purple-600 outline-none flex-1">
                  <label class="flex items-center gap-2 cursor-pointer whitespace-nowrap">
                    <input type="checkbox" name="is_free[]" value="1"
                           class="is-free-checkbox h-5 w-5 text-green-600"
                           <?= $t['is_free'] ? 'checked' : '' ?>>
                    <span class="text-green-700 font-medium">Free Ticket</span>
                  </label>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Price (KES)</label>
                    <input type="number" name="ticket_price[]" min="0" step="50"
                           value="<?= $t['price'] ?>"
                           class="mt-1 w-full p-3 border rounded-xl price-input <?= $t['is_free'] ? 'bg-gray-200 cursor-not-allowed' : '' ?>"
                           <?= $t['is_free'] ? 'disabled' : '' ?>
                           placeholder="e.g. 1500">
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Quantity (0 = unlimited)</label>
                    <input type="number" name="ticket_qty[]" min="0"
                           value="<?= $t['quantity_available'] ?>"
                           class="mt-1 w-full p-3 border rounded-xl"
                           placeholder="e.g. 200 or 0">
                  </div>
                </div>
                <button type="button" class="remove-type absolute top-4 right-4 text-red-500 hover:text-red-700 transition">
                  <i class="fa-solid fa-trash-can"></i>
                </button>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <!-- Default empty block when no types exist -->
            <div class="ticket-type-block border border-gray-300 rounded-xl p-5 bg-gray-50 relative">
              <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
                <input type="text" name="ticket_type[]" placeholder="e.g. Regular, VIP" required
                       class="font-medium text-lg text-gray-800 bg-transparent border-b border-gray-400 focus:border-purple-600 outline-none flex-1">
                <label class="flex items-center gap-2 cursor-pointer whitespace-nowrap">
                  <input type="checkbox" name="is_free[]" value="1" class="is-free-checkbox h-5 w-5 text-green-600">
                  <span class="text-green-700 font-medium">Free Ticket</span>
                </label>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700">Price (KES)</label>
                  <input type="number" name="ticket_price[]" min="0" step="50" value="0"
                         class="mt-1 w-full p-3 border rounded-xl price-input" placeholder="e.g. 800">
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700">Quantity (0 = unlimited)</label>
                  <input type="number" name="ticket_qty[]" min="0"
                         class="mt-1 w-full p-3 border rounded-xl" placeholder="e.g. 100 or 0">
                </div>
              </div>
              <button type="button" class="remove-type absolute top-4 right-4 text-red-500 hover:text-red-700 transition hidden">
                <i class="fa-solid fa-trash-can"></i>
              </button>
            </div>
          <?php endif; ?>
        </div>

        <button type="button" id="add-ticket-type"
                class="text-purple-600 hover:text-purple-800 font-medium flex items-center gap-2 transition">
          <i class="fa-solid fa-plus-circle"></i> Add another ticket type
        </button>
      </div>

      <!-- ══ ACTION BUTTONS ═══════════════════════════════════════ -->
      <div class="flex flex-col sm:flex-row justify-end gap-4 pt-8 border-t">
        <a href="index.php?page=my_tickets"
           class="px-8 py-3 border border-gray-300 rounded-xl hover:bg-gray-50 transition text-center font-medium text-gray-700">
          Cancel
        </a>
        <button type="submit"
                class="px-10 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 active:bg-purple-800 transition font-medium shadow-md">
          <i class="fa-solid fa-floppy-disk mr-2"></i> Save Changes
        </button>
      </div>

    </form>
  </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════
//  TIME PICKER — combine 12h selects into hidden HH:MM (24h)
// ═══════════════════════════════════════════════════════════════
function to24(h, m, ampm) {
  let hour = parseInt(h, 10);
  if (ampm === 'AM' && hour === 12) hour = 0;
  if (ampm === 'PM' && hour !== 12) hour += 12;
  return String(hour).padStart(2, '0') + ':' + m;
}

function syncTimes() {
  document.getElementById('start_time_val').value = to24(
    document.getElementById('start_h').value,
    document.getElementById('start_m').value,
    document.getElementById('start_ampm').value
  );
  document.getElementById('end_time_val').value = to24(
    document.getElementById('end_h').value,
    document.getElementById('end_m').value,
    document.getElementById('end_ampm').value
  );
}

// Live sync on any select change
document.querySelectorAll('.time-sel').forEach(function(sel) {
  sel.addEventListener('change', syncTimes);
});

// Safety-net sync on form submit
document.getElementById('edit-event-form').addEventListener('submit', syncTimes);

// Initialise on page load so hidden inputs are never empty
syncTimes();

// ═══════════════════════════════════════════════════════════════
//  TICKET TYPES — dynamic add / remove
// ═══════════════════════════════════════════════════════════════
let typeCount = <?= count($ticket_types) ?: 1 ?>;

document.getElementById('add-ticket-type').addEventListener('click', function() {
  const container = document.getElementById('ticket-types-container');
  const block = document.createElement('div');
  block.className = 'ticket-type-block border border-gray-300 rounded-xl p-5 bg-gray-50 relative';
  block.innerHTML = `
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
      <input type="text" name="ticket_type[]" placeholder="e.g. Early Bird, VIP" required
             class="font-medium text-lg text-gray-800 bg-transparent border-b border-gray-400 focus:border-purple-600 outline-none flex-1">
      <label class="flex items-center gap-2 cursor-pointer whitespace-nowrap">
        <input type="checkbox" name="is_free[]" value="1" class="is-free-checkbox h-5 w-5 text-green-600">
        <span class="text-green-700 font-medium">Free Ticket</span>
      </label>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700">Price (KES)</label>
        <input type="number" name="ticket_price[]" min="0" step="50" value="0"
               class="mt-1 w-full p-3 border rounded-xl price-input" placeholder="e.g. 800">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Quantity (0 = unlimited)</label>
        <input type="number" name="ticket_qty[]" min="0"
               class="mt-1 w-full p-3 border rounded-xl" placeholder="e.g. 100 or 0">
      </div>
    </div>
    <button type="button" class="remove-type absolute top-4 right-4 text-red-500 hover:text-red-700 transition">
      <i class="fa-solid fa-trash-can"></i>
    </button>
  `;
  container.appendChild(block);
  typeCount++;
  // Show all trash icons once there are 2+ blocks
  document.querySelectorAll('.remove-type').forEach(function(btn) {
    btn.classList.remove('hidden');
  });
});

// Remove a ticket type block
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.remove-type');
  if (btn && typeCount > 1) {
    btn.closest('.ticket-type-block').remove();
    typeCount--;
    if (typeCount === 1) {
      document.querySelectorAll('.remove-type').forEach(function(b) {
        b.classList.add('hidden');
      });
    }
  }
});

// ═══════════════════════════════════════════════════════════════
//  FREE TICKET CHECKBOX — disable/enable price input
// ═══════════════════════════════════════════════════════════════
document.addEventListener('change', function(e) {
  if (e.target.classList.contains('is-free-checkbox')) {
    const block      = e.target.closest('.ticket-type-block');
    const priceInput = block.querySelector('.price-input');
    if (e.target.checked) {
      priceInput.value    = 0;
      priceInput.disabled = true;
      priceInput.classList.add('bg-gray-200', 'cursor-not-allowed');
    } else {
      priceInput.disabled = false;
      priceInput.classList.remove('bg-gray-200', 'cursor-not-allowed');
    }
  }
});

// ═══════════════════════════════════════════════════════════════
//  TOASTR SUCCESS
// ═══════════════════════════════════════════════════════════════
<?php if ($success): ?>
  toastr.options = {
    closeButton:    true,
    progressBar:    true,
    positionClass:  "toast-top-center",
    timeOut:        "2200",
    extendedTimeOut:"1000",
    showMethod:     "fadeIn",
    hideMethod:     "fadeOut"
  };
  toastr.success(
    '<div class="flex items-center gap-3">' +
    '<i class="fa-solid fa-circle-check text-2xl text-green-500 bounce-check"></i>' +
    '<span class="font-medium text-base">Event and tickets updated successfully!</span>' +
    '</div>',
    '',
    { iconClass: "hidden" }
  );
  setTimeout(function() {
    window.location.href = "index.php?page=my_tickets";
  }, 2400);
<?php endif; ?>

// ═══════════════════════════════════════════════════════════════
//  PRELOADER
// ═══════════════════════════════════════════════════════════════
window.addEventListener("load", function() {
  const loader = document.getElementById("preloader");
  loader.style.opacity = "0";
  setTimeout(function() { loader.style.display = "none"; }, 400);
});
</script>
</body>
</html>