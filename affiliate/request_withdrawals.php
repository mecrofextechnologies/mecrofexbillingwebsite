<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';
include 'includes/connect.php';

// ────────────────────────────────────────────────
// SESSION CHECK
// ────────────────────────────────────────────────
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    die("Please login first.");
}

$full_name       = '';
$email           = '';
$current_balance = 0.00;
$mpesa_number    = '';
$success = $error = "";

// Safaricom M-Pesa Business withdrawal charges (verify occasionally)
$charge_ranges = [
    ['min' => 1,     'max' => 49,     'charge' => 0],
    ['min' => 50,    'max' => 100,    'charge' => 11],
    ['min' => 101,   'max' => 500,    'charge' => 29],
    ['min' => 501,   'max' => 1000,   'charge' => 29],
    ['min' => 1001,  'max' => 1500,   'charge' => 29],
    ['min' => 1501,  'max' => 2500,   'charge' => 29],
    ['min' => 2501,  'max' => 3500,   'charge' => 52],
    ['min' => 3501,  'max' => 5000,   'charge' => 69],
    ['min' => 5001,  'max' => 7500,   'charge' => 87],
    ['min' => 7501,  'max' => 10000,  'charge' => 115],
    ['min' => 10001, 'max' => 15000,  'charge' => 167],
    ['min' => 15001, 'max' => 20000,  'charge' => 185],
    ['min' => 20001, 'max' => 35000,  'charge' => 197],
    ['min' => 35001, 'max' => 50000,  'charge' => 278],
    ['min' => 50001, 'max' => 250000, 'charge' => 309],
];

function getCharge(float $netAmount, array $ranges): ?float {
    foreach ($ranges as $range) {
        if ($netAmount >= $range['min'] && $netAmount <= $range['max']) {
            return $range['charge'];
        }
    }
    return null;
}

// Fetch creator
try {
    $stmt = $pdo->prepare("SELECT full_name, email FROM creators WHERE id = ?");
    $stmt->execute([$creator_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($creator) {
        $full_name = $creator['full_name'] ?? 'Unknown';
        $email     = $creator['email']     ?? 'Not set';
    } else {
        $error = "Creator profile not found.";
    }
} catch (PDOException $e) {
    $error = "Error fetching creator: " . $e->getMessage();
}

// Fetch balance
try {
    $stmt = $pdo->prepare("SELECT amount FROM my_balance WHERE creator_id = ? LIMIT 1");
    $stmt->execute([$creator_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_balance = $row ? (float)$row['amount'] : 0.00;
} catch (PDOException $e) {
    $error = "Error fetching balance: " . $e->getMessage();
}

// ────────────────────────────────────────────────
// FORM SUBMISSION
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $net_amount   = floatval($_POST['amount'] ?? 0);
    $mpesa_number = trim($_POST['mpesa_number'] ?? '');
    $charge       = getCharge($net_amount, $charge_ranges);

    // Validation
    if (empty($mpesa_number)) {
        $error = "M-Pesa phone number is required.";
    } elseif ($net_amount <= 0) {
        $error = "Amount must be greater than 0.";
    } elseif ($charge === null) {
        $error = "Amount is outside supported withdrawal ranges.";
    } else {
        $total_deduct = $net_amount + $charge;
        if ($total_deduct > $current_balance) {
            $error = "Insufficient balance.<br>" .
                     "You want to receive: <strong>KES " . number_format($net_amount, 2) . "</strong><br>" .
                     "M-Pesa fee: KES " . number_format($charge, 2) . "<br>" .
                     "<strong>Total to deduct: KES " . number_format($total_deduct, 2) . "</strong><br>" .
                     "Available: KES " . number_format($current_balance, 2);
        }
    }

    // No pending requests allowed
    if (empty($error)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM withdrawal_requests WHERE creator_id = ? AND status = 'pending'");
            $stmt->execute([$creator_id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "You already have a pending withdrawal. Please wait until it is processed.";
            }
        } catch (PDOException $e) {
            $error = "Error checking pending requests: " . $e->getMessage();
        }
    }

    // Process
    if (empty($error)) {
        try {
            $pdo->beginTransaction();

            $total_amount = $net_amount + $charge;

            $stmt = $pdo->prepare("
                INSERT INTO withdrawal_requests 
                (creator_id, mpesa_number, amount, charge, total_amount, status, requested_at, notes)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW(), ?)
            ");

            $notes = "Net requested: $net_amount | Fee: $charge";

            $stmt->execute([
                $creator_id,
                $mpesa_number,
                $net_amount,
                $charge,
                $total_amount,
                $notes
            ]);

            $pdo->commit();

            $success = "Withdrawal request submitted!<br>" .
                       "→ You will receive: <strong>KES " . number_format($net_amount, 2) . "</strong><br>" .
                       "→ M-Pesa fee: KES " . number_format($charge, 2) . "<br>" .
                       "→ Total deducted: <strong>KES " . number_format($total_amount, 2) . "</strong>";

            // ───── EMAIL ─────
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'mail.keychele.co.ke';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'notifications@keychele.co.ke';
                $mail->Password   = 'King20241064.';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->SMTPOptions = ['ssl' => ['verify_peer'=>false, 'verify_peer_name'=>false, 'allow_self_signed'=>true]];

                $mail->setFrom('notifications@keychele.co.ke', 'Keychele');
                $mail->CharSet = 'UTF-8';
                $mail->isHTML(true);

                // Creator email
                $creator_html = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:25px;background:#fff;border-radius:8px;'>
                    <h2 style='color:#6d28d9;text-align:center;'>Withdrawal Request Received</h2>
                    <p>Hello <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                    <p>Your request has been received:</p>
                    <ul style='margin:15px 0;padding-left:20px;'>
                        <li><strong>Amount you will receive:</strong> KES " . number_format($net_amount, 2) . "</li>
                        <li><strong>M-Pesa fee:</strong> KES " . number_format($charge, 2) . "</li>
                        <li><strong>Total deducted from balance:</strong> KES " . number_format($total_amount, 2) . "</li>
                        <li><strong>To:</strong> " . htmlspecialchars($mpesa_number) . "</li>
                    </ul>
                    <p style='color:#6d28d9;font-weight:bold;'>Processing within 24 hours.</p>
                    <p style='font-size:13px;color:#555;margin-top:30px;'>Thank you for using Keychele</p>
                </div>";

                $mail->addAddress($email, $full_name);
                $mail->Subject = "Withdrawal Request Received";
                $mail->Body    = $creator_html;
                $mail->send();

                // Admin email
                $mail->clearAddresses();
                $admin_email = $pdo->query("SELECT email FROM admin LIMIT 1")->fetchColumn();
                if ($admin_email) {
                    $admin_html = "
                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:25px;background:#fff;border-radius:8px;'>
                        <h2 style='color:#6d28d9;text-align:center;'>New Withdrawal Request</h2>
                        <p><strong>Creator:</strong> " . htmlspecialchars($full_name) . " ($email)</p>
                        <p><strong>Receive:</strong> KES " . number_format($net_amount, 2) . "</p>
                        <p><strong>Fee:</strong> KES " . number_format($charge, 2) . "</p>
                        <p><strong>Total deduct:</strong> KES " . number_format($total_amount, 2) . "</p>
                        <p><strong>M-Pesa:</strong> " . htmlspecialchars($mpesa_number) . "</p>
                    </div>";
                    $mail->addAddress($admin_email);
                    $mail->Subject = "New Withdrawal - " . htmlspecialchars($full_name);
                    $mail->Body    = $admin_html;
                    $mail->send();
                }
            } catch (Exception $e) {
                error_log("Email failed: " . $mail->ErrorInfo);
                $success .= " (email not sent)";
            }

            // Clear form
            $_POST = [];

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Database error: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Request Withdrawal</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
</head>
<body class="bg-gray-50">

<div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm">
  <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
    <div class="h-5 w-5 border-2 border-green-500 border-t-transparent rounded-full animate-spin"></div>
    <span class="text-gray-700 font-medium">Loading...</span>
  </div>
</div>

<div class="p-4 md:p-6 min-h-screen pb-20">

  <div class="bg-gradient-to-r from-purple-600 to-blue-600 p-6 rounded-2xl shadow-lg text-white mb-6 flex justify-between items-center">
    <div>
      <h2 class="text-2xl font-bold">Request Withdrawal</h2>
      <p class="opacity-90">Withdraw earnings to M-Pesa</p>
    </div>
    <i class="fa-solid fa-money-bill-transfer text-5xl opacity-80"></i>
  </div>

  <?php if ($success): ?>
    <div class="bg-green-100 text-green-800 p-4 rounded-xl mb-6"><?= $success ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="bg-red-100 text-red-800 p-4 rounded-xl mb-6"><?= $error ?></div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow-md p-6">

    <div class="mb-8 p-4 bg-purple-50 rounded-xl border border-purple-100">
      <p class="text-lg font-semibold text-purple-800">
        Available Balance: <span class="text-2xl">KES <?= number_format($current_balance, 2) ?></span>
      </p>
    </div>

    <form method="POST" class="space-y-6">

      <h3 class="text-lg font-semibold text-gray-800 mb-4">Account Information</h3>

      <label class="block mb-4">
        <span class="font-medium text-gray-700">Full Name</span>
        <input type="text" value="<?= htmlspecialchars($full_name) ?>" readonly class="mt-1 w-full p-3 border rounded-xl bg-gray-100 cursor-not-allowed">
      </label>

      <label class="block mb-4">
        <span class="font-medium text-gray-700">Email</span>
        <input type="email" value="<?= htmlspecialchars($email) ?>" readonly class="mt-1 w-full p-3 border rounded-xl bg-gray-100 cursor-not-allowed">
      </label>

      <h3 class="text-lg font-semibold text-gray-800 mt-8 mb-4">Withdrawal Details</h3>

      <label class="block mb-4">
        <span class="font-medium text-gray-700">M-Pesa Phone Number *</span>
        <input name="mpesa_number" required type="tel" pattern="^(?:\+?254|0)([17]\d{8})$"
               class="mt-1 w-full p-3 border rounded-xl focus:border-purple-500 focus:ring-2 focus:ring-purple-200 focus:ring-opacity-50"
               placeholder="e.g. 0712345678 or +254712345678"
               value="<?= htmlspecialchars($_POST['mpesa_number'] ?? '') ?>">
        <p class="text-xs text-gray-500 mt-1">Format: 07XXXXXXXX or +254XXXXXXXXX</p>
      </label>

      <label class="block mb-6">
        <span class="font-medium text-gray-700">Amount you want to receive (KES) *</span>
        <input name="amount" required type="number" min="1" step="1"
               class="mt-1 w-full p-3 border rounded-xl focus:border-purple-500 focus:ring-2 focus:ring-purple-200 focus:ring-opacity-50"
               placeholder="e.g. 5000"
               value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
        <p class="text-sm text-gray-600 mt-1">
          This is the amount you will receive in your M-Pesa.<br>
          Safaricom fee will be added automatically.<br>
          <strong>Max possible (including fee):</strong> KES <?= number_format($current_balance, 2) ?>
        </p>
      </label>

      <button type="submit"
              class="w-full bg-purple-600 hover:bg-purple-700 text-white p-4 rounded-xl font-bold transition flex items-center justify-center gap-2">
        <i class="fa-solid fa-paper-plane"></i>
        Submit Request
      </button>

    </form>

    <!-- Charges Table -->
    <div class="mt-12 pt-8 border-t border-gray-200">
      <h3 class="text-xl font-bold text-gray-800 mb-4">M-Pesa Withdrawal Charges</h3>
      <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-xl mb-6 text-sm text-yellow-800">
        <strong>Note:</strong> These are official Safaricom fees — added automatically.
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full bg-white border border-gray-200 rounded-xl">
          <thead>
            <tr class="bg-gray-100">
              <th class="p-3 text-left font-semibold">From (KES)</th>
              <th class="p-3 text-left font-semibold">To (KES)</th>
              <th class="p-3 text-left font-semibold">Fee (KES)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($charge_ranges as $r): ?>
            <tr class="border-t hover:bg-gray-50">
              <td class="p-3"><?= number_format($r['min']) ?></td>
              <td class="p-3"><?= number_format($r['max']) ?></td>
              <td class="p-3 <?= $r['charge'] == 0 ? 'text-green-600' : 'text-red-600 font-medium' ?>">
                <?= $r['charge'] == 0 ? 'Free' : number_format($r['charge']) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<script>
window.addEventListener("load", () => {
    const loader = document.getElementById("preloader");
    loader.style.opacity = "0";
    loader.style.transition = "opacity 0.3s ease";
    setTimeout(() => loader.style.display = "none", 300);
});
</script>
</body>
</html>