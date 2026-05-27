<?php
// add_ticket.php - Standalone manual ticket creation (cash/offline)
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Safe autoloader
$autoload_paths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    '/home1/keychele/public_html/msanii/vendor/autoload.php'
];

$autoload_loaded = false;
foreach ($autoload_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoload_loaded = true;
        break;
    }
}

if (!$autoload_loaded) {
    die("<h1>Composer autoloader missing - run: composer require phpmailer/phpmailer</h1>");
}

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';

if (!file_exists($connect_path)) {
    die("<h1>Database connection file missing.</h1>");
}

require_once $connect_path;

if (file_exists('vendor/fpdf.php')) {
    require_once 'vendor/fpdf.php';
}

// Auth
if (!isset($_SESSION['creator_id']) || !is_numeric($_SESSION['creator_id'])) {
    header("Location: login.php");
    exit;
}

$creator_id = (int)$_SESSION['creator_id'];

// Load events
$stmt = $pdo->prepare("
    SELECT id, title, start_date, start_time, venue
    FROM tickets
    WHERE creator_id = ?
    ORDER BY start_date DESC
");
$stmt->execute([$creator_id]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$has_events = count($events) > 0;

// Selected event (prefer POST > GET > first event)
$selected_event_id = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? 0);
$event = null;

if ($selected_event_id > 0) {
    foreach ($events as $e) {
        if ($e['id'] === $selected_event_id) {
            $event = $e;
            break;
        }
    }
} elseif ($has_events) {
    $event = $events[0];
    $selected_event_id = $event['id'];
}

// Load ticket types
$ticket_types = [];
$has_types = false;
$selected_type_id = (int)($_POST['ticket_type'] ?? 0); // preserve after reload

if ($event) {
    $stmt = $pdo->prepare("
        SELECT id, type_name, price, quantity_available, quantity_sold
        FROM event_ticket_types
        WHERE event_id = ? AND is_active = 1
        ORDER BY price ASC
    ");
    $stmt->execute([$selected_event_id]);
    $ticket_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $has_types = count($ticket_types) > 0;
}

// Form processing
$success = false;
$error = '';
$created_ticket_number = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event && $has_types) {
    $buyer_name     = trim($_POST['buyer_name'] ?? '');
    $buyer_email    = trim($_POST['buyer_email'] ?? '');
    $buyer_phone    = preg_replace('/\D/', '', $_POST['buyer_phone'] ?? '');
    $ticket_type_id = (int)($_POST['ticket_type'] ?? 0);
    $quantity       = max(1, (int)($_POST['quantity'] ?? 1));
    $mpesa_receipt  = trim($_POST['mpesa_receipt'] ?? '');

    // Phone normalization
    if (strlen($buyer_phone) === 10 && in_array(substr($buyer_phone, 0, 2), ['01','07'])) {
        $buyer_phone = '254' . substr($buyer_phone, 1);
    } elseif (strlen($buyer_phone) === 9) {
        $buyer_phone = '254' . $buyer_phone;
    }

    // Find selected type
    $selected_type = null;
    foreach ($ticket_types as $t) {
        if ($t['id'] === $ticket_type_id) {
            $selected_type = $t;
            break;
        }
    }

    if (!$selected_type) {
        $error = "Please select a valid ticket type for this event.";
    } elseif ($selected_type['quantity_available'] < $quantity) {
        $error = "Not enough tickets left: only {$selected_type['quantity_available']} available.";
    } elseif (empty($buyer_name) || empty($buyer_email) || !filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Full name and valid email are required.";
    } else {
        $amount_paid    = $selected_type['price'] * $quantity;
        $ticket_number  = strtoupper('TKT-' . date('ymd') . '-' . substr(md5(uniqid()), 0, 6));
        $transaction_id = 'OFFLINE-' . date('ymdHis') . '-' . substr(md5(uniqid()), -6);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO ticket_purchases
                (ticket_id, ticket_type_id, buyer_name, buyer_email, buyer_phone,
                 ticket_number, amount_paid, quantity, status, transaction_id,
                 mpesa_receipt, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $selected_event_id,
                $ticket_type_id,
                $buyer_name,
                $buyer_email,
                $buyer_phone,
                $ticket_number,
                $amount_paid,
                $quantity,
                $transaction_id,
                $mpesa_receipt ?: null
            ]);

            $pdo->prepare("
                UPDATE event_ticket_types 
                SET quantity_available = quantity_available - ?,
                    quantity_sold = quantity_sold + ?
                WHERE id = ?
            ")->execute([$quantity, $quantity, $ticket_type_id]);

            $pdo->commit();

            // ── Improved PDF with logo ───────────────────────────────────────
            $pdf = new FPDF('P', 'mm', 'A5');
            $pdf->AddPage();

            // Header background
            $pdf->SetFillColor(184, 18, 54);
            $pdf->Rect(0, 0, 148, 40, 'F');

            // Logo (adjust path to your actual logo location)
            $logoPath = __DIR__ . '/images/logo/KECHELE LOGO.png';
            if (file_exists($logoPath)) {
                $pdf->Image($logoPath, 54, 8, 40); // centered
            }

            $pdf->SetY(45);
            $pdf->SetFont('Arial', 'B', 20);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 12, 'TICKET CONFIRMED', 0, 1, 'C');

            $pdf->Ln(8);
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->SetTextColor(184, 18, 54);
            $pdf->MultiCell(0, 8, $event['title'], 0, 'C');

            $pdf->Ln(10);
            $pdf->SetFont('Arial', '', 12);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 8, "Ticket #: $ticket_number", 0, 1, 'C');
            $pdf->Cell(0, 8, "Buyer: $buyer_name", 0, 1, 'C');
            $pdf->Cell(0, 8, "Quantity: $quantity × " . $selected_type['type_name'], 0, 1, 'C');
            $pdf->Cell(0, 8, "Paid: KES " . number_format($amount_paid), 0, 1, 'C');

            $pdf->Ln(12);
            $pdf->SetFont('Arial', 'B', 13);
            $pdf->Cell(0, 10, "Event Details", 0, 1, 'C');
            $pdf->SetFont('Arial', '', 11);
            $pdf->Cell(0, 8, date('l, d F Y • g:i A', strtotime($event['start_date'] . ' ' . $event['start_time'])), 0, 1, 'C');
            $pdf->MultiCell(0, 8, $event['venue'] ?: 'Venue To Be Announced', 0, 'C');

            $pdf->Ln(15);
            $pdf->SetFont('Arial', 'I', 9);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->MultiCell(0, 6, "Present this ticket (digital or printed) at the entrance.\nThank you for choosing Keychele!", 0, 'C');

            $pdf_file = sys_get_temp_dir() . "/keychele_manual_{$ticket_number}.pdf";
            $pdf->Output('F', $pdf_file);

            // ── Send email ───────────────────────────────────────────────────
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'mail.keychele.co.ke';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'tickets@keychele.co.ke';
            $mail->Password   = 'King20241064.';
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('tickets@keychele.co.ke', 'Keychele Tickets');
            $mail->addAddress($buyer_email, $buyer_name);
            $mail->addAttachment($pdf_file);
            $mail->isHTML(true);
            $mail->Subject = "Your Ticket – " . htmlspecialchars($event['title']);
            $mail->Body = "
                <h2 style='color:#b81236;'>Hello {$buyer_name},</h2>
                <p>Your ticket has been issued manually (cash/offline payment).</p>
                <p><strong>Event:</strong> " . htmlspecialchars($event['title']) . "</p>
                <p><strong>Ticket Number:</strong> <strong>{$ticket_number}</strong></p>
                <p><strong>Quantity:</strong> {$quantity}</p>
                <p><strong>Type:</strong> " . htmlspecialchars($selected_type['type_name']) . "</p>
                <p><strong>Amount Paid:</strong> KES " . number_format($amount_paid) . "</p>
                <p><strong>Date & Time:</strong> " . date('D d M Y • g:i A', strtotime($event['start_date'].' '.$event['start_time'])) . "</p>
                <p><strong>Venue:</strong> " . htmlspecialchars($event['venue'] ?? 'TBA') . "</p>
                <p style='margin-top:20px;'><strong>Important:</strong> Attached is your PDF ticket. Please present it at the entrance (digital or printed).</p>
                <p>Enjoy the event!<br><strong>Team Keychele</strong></p>
            ";

            $mail->send();
            @unlink($pdf_file);

            $success = true;
            $created_ticket_number = $ticket_number;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Failed to create ticket: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Add Manual / Cash Ticket | Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <!-- Toastr -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
  <style>
    :root { --primary: #b81236; }
    .ticket-btn {
      background: var(--primary);
      color: white;
      transition: all 0.3s ease;
    }
    .ticket-btn:hover {
      background: #9f0f2e;
      transform: translateY(-1px);
    }
  </style>
</head>
<body class="bg-gray-50 min-h-screen">

  <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8">

    <div class="mb-8">
      <a href="index.php?page=ticket_purchases"
         class="inline-flex items-center text-blue-600 hover:text-blue-800 font-medium text-lg">
        <i class="fas fa-arrow-left mr-2"></i> Back to My Events
      </a>
    </div>

    <div class="bg-gradient-to-r from-[#032d5d] to-var(--primary) rounded-2xl text-white p-6 md:p-8 mb-8 shadow-lg">
      <h1 class="text-2xl md:text-3xl font-bold">Add Manual / Cash / Offline Ticket</h1>
      <p class="mt-2 opacity-90">Issue ticket for cash payments or offline M-Pesa transactions</p>
    </div>

    <?php if ($success): ?>
      <script>
        toastr.success(
          "Ticket #<?= htmlspecialchars($created_ticket_number) ?> created and emailed successfully!",
          "Success",
          { timeOut: 8000, closeButton: true, progressBar: true }
        );
      </script>
    <?php endif; ?>

    <?php if ($error): ?>
      <script>
        toastr.error(
          "<?= addslashes($error) ?>",
          "Error",
          { timeOut: 10000, closeButton: true, progressBar: true }
        );
      </script>
    <?php endif; ?>

    <?php if (!$has_events): ?>
      <div class="bg-yellow-50 border-l-4 border-yellow-400 p-8 rounded-xl shadow text-center">
        <i class="fas fa-calendar-times text-6xl text-yellow-500 mb-4"></i>
        <h2 class="text-2xl font-bold text-gray-800 mb-4">No Events Found</h2>
        <p class="text-lg text-gray-700 mb-6">You don't have any events yet.</p>
        <a href="index.php?page=create_event" 
           class="inline-block bg-[#b81236] text-white px-10 py-5 rounded-xl font-bold text-lg hover:bg-red-800 transition">
          Create New Event
        </a>
      </div>
    <?php else: ?>

      <form method="POST" class="bg-white rounded-2xl shadow-xl p-6 md:p-10">

        <div class="mb-8">
          <label class="block text-gray-700 font-medium mb-2 text-lg">
            Select Event <span class="text-red-500">*</span>
          </label>
          <select name="event_id" required onchange="this.form.submit()"
                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-[var(--primary)] focus:border-[var(--primary)] outline-none text-base">
            <?php foreach ($events as $e): ?>
              <option value="<?= $e['id'] ?>" <?= $e['id'] === $selected_event_id ? 'selected' : '' ?>>
                <?= htmlspecialchars($e['title']) ?> 
                (<?= $e['start_date'] ? date('d M Y', strtotime($e['start_date'])) : 'No date' ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if (!$has_types): ?>
          <div class="bg-yellow-50 border-l-4 border-yellow-400 p-6 rounded-lg mb-8">
            <p class="font-medium text-lg">No active ticket types for this event.</p>
            <p class="mt-2">Please add at least one ticket type first.</p>
          </div>
        <?php else: ?>

          <div class="grid md:grid-cols-2 gap-6 mb-8">
            <div>
              <label class="block text-gray-700 font-medium mb-2">Buyer Full Name <span class="text-red-500">*</span></label>
              <input type="text" name="buyer_name" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-[var(--primary)] focus:border-[var(--primary)] outline-none" placeholder="John Doe">
            </div>

            <div>
              <label class="block text-gray-700 font-medium mb-2">Email Address <span class="text-red-500">*</span></label>
              <input type="email" name="buyer_email" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-[var(--primary)] focus:border-[var(--primary)] outline-none" placeholder="john@example.com">
            </div>

            <div>
              <label class="block text-gray-700 font-medium mb-2">Phone Number</label>
              <input type="tel" name="buyer_phone" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-[var(--primary)] focus:border-[var(--primary)] outline-none" placeholder="0712345678 or 2547...">
            </div>

            <div>
              <label class="block text-gray-700 font-medium mb-2">Ticket Type <span class="text-red-500">*</span></label>
              <select name="ticket_type" required
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-[var(--primary)] focus:border-[var(--primary)] outline-none">
                <option value="">Select type...</option>
                <?php foreach ($ticket_types as $type): ?>
                  <option value="<?= $type['id'] ?>" <?= $type['id'] === $selected_type_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($type['type_name']) ?> - KSh <?= number_format($type['price']) ?>
                    (<?= $type['quantity_available'] ?> left)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="grid md:grid-cols-2 gap-6 mb-8">
            <div>
              <label class="block text-gray-700 font-medium mb-2">Quantity <span class="text-red-500">*</span></label>
              <input type="number" name="quantity" min="1" value="1" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-[var(--primary)] focus:border-[var(--primary)] outline-none">
            </div>

            <div>
              <label class="block text-gray-700 font-medium mb-2">M-Pesa Receipt (optional)</label>
              <input type="text" name="mpesa_receipt" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-[var(--primary)] focus:border-[var(--primary)] outline-none" placeholder="e.g. QER123ABC456">
            </div>
          </div>

          <button type="submit" class="w-full ticket-btn text-xl font-bold py-5 rounded-xl shadow-xl hover:shadow-2xl transition">
            <i class="fas fa-ticket mr-2"></i> Create Ticket & Send Email
          </button>

        <?php endif; ?>

      </form>

    <?php endif; ?>

  </div>

  <script>
    toastr.options = {
      "closeButton": true,
      "progressBar": true,
      "positionClass": "toast-top-right",
      "timeOut": "7000",
      "extendedTimeOut": "2000"
    };
  </script>

</body>
</html>
<?php ob_end_flush(); ?>