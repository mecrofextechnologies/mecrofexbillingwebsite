<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$connect_path = file_exists('msanii/includes/connect.php') 
    ? 'msanii/includes/connect.php' 
    : 'includes/connect.php';
require_once $connect_path;

if (!isset($_SESSION['creator_id'])) {
    header("Location: login.php");
    exit;
}

$creator_id = $_SESSION['creator_id'];
$success = $error = '';

// Process withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = trim($_POST['amount'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    // Validation
    if (!is_numeric($amount) || $amount < 50) {
        $error = "Minimum withdrawal is KSh 50";
    } elseif (!preg_match('/^07[0-9]{8}$/', $phone)) {
        $error = "Enter a valid Kenyan M-Pesa number (07xxxxxxxx)";
    } else {
        // Get current balance
        $stmt = $pdo->prepare("SELECT COALESCE(amount, 0) FROM my_balance WHERE creator_id = ?");
        $stmt->execute([$creator_id]);
        $current_balance = (float)$stmt->fetchColumn();

        if ($amount > $current_balance) {
            $error = "Insufficient balance. You only have KSh " . number_format($current_balance, 2);
        } else {
            // Generate unique reference
            $reference = 'WD' . strtoupper(substr(uniqid(), -8));

            // Insert withdrawal request
            try {
                $stmt = $pdo->prepare("INSERT INTO withdrawals (creator_id, amount, phone, reference, status) VALUES (?, ?, ?, ?, 'pending')");
                $stmt->execute([$creator_id, $amount, $phone, $reference]);
                $success = "Withdrawal request of KSh " . number_format($amount, 2) . " sent successfully!";
            } catch (Exception $e) {
                $error = "Request failed. Try again.";
            }
        }
    }
}

// Fetch wallet data
$stmt = $pdo->prepare("SELECT COALESCE(amount, 0) FROM my_balance WHERE creator_id = ?");
$stmt->execute([$creator_id]);
$wallet_balance = number_format((float)$stmt->fetchColumn(), 2);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE creator_id = ? AND status = 'completed'");
$stmt->execute([$creator_id]);
$withdrawn = number_format((float)$stmt->fetchColumn(), 2);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE creator_id = ? AND status = 'pending'");
$stmt->execute([$creator_id]);
$pending = number_format((float)$stmt->fetchColumn(), 2);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM support WHERE creator_id = ?");
$stmt->execute([$creator_id]);
$supporters_count = $stmt->fetchColumn();

// History
$stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE creator_id = ? ORDER BY requested_at DESC LIMIT 50");
$stmt->execute([$creator_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Wallet | Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://kit.fontawesome.com/a2d9d5a04b.js" crossorigin="anonymous"></script>
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body { -webkit-font-smoothing: antialiased; }
    @media (max-width: 640px) {
      .text-2xl { font-size: 1.15rem !important; }
      .p-6 { padding: 1rem !important; }
      .pb-20 { padding-bottom: 5.5rem !important; }
    }
  </style>
</head>
<body class="bg-gray-50">

  <div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
      <div class="h-5 w-5 border-2 border-green-500 border-t-transparent rounded-full animate-spin"></div>
      <span class="text-gray-700 font-medium">Loading Wallet...</span>
    </div>
  </div>

  <div class="p-4 md:p-6 min-h-screen pb-20">

    <div class="bg-gradient-to-r from-indigo-600 to-blue-600 rounded-2xl text-white p-6 mb-6 shadow-lg text-center md:text-left">
      <h2 class="text-2xl font-bold mb-1">My Wallet</h2>
      <p class="opacity-90">Minimum withdrawal: KSh 50 • Processed within 24hrs</p>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-white rounded-xl shadow-md p-5 border-l-4 border-green-500">
        <p class="text-gray-500 text-sm">Available Balance</p>
        <h3 class="text-2xl font-bold text-gray-800 mt-1">KSh <?= $wallet_balance ?></h3>
        <i class="fas fa-coins text-green-500 text-3xl mt-2"></i>
      </div>
      <div class="bg-white rounded-xl shadow-md p-5 border-l-4 border-sky-500">
        <p class="text-gray-500 text-sm">Total Withdrawn</p>
        <h3 class="text-2xl font-bold text-gray-800 mt-1">KSh <?= $withdrawn ?></h3>
        <i class="fas fa-hand-holding-dollar text-sky-500 text-3xl mt-2"></i>
      </div>
      <div class="bg-white rounded-xl shadow-md p-5 border-l-4 border-yellow-400">
        <p class="text-gray-500 text-sm">Pending</p>
        <h3 class="text-2xl font-bold text-gray-800 mt-1">KSh <?= $pending ?></h3>
        <i class="fas fa-clock text-yellow-400 text-3xl mt-2"></i>
      </div>
      <div class="bg-white rounded-xl shadow-md p-5 border-l-4 border-red-500">
        <p class="text-gray-500 text-sm">Supporters</p>
        <h3 class="text-2xl font-bold text-gray-800 mt-1"><?= $supporters_count ?></h3>
        <i class="fas fa-heart text-red-500 text-3xl mt-2"></i>
      </div>
    </div>

    <!-- Withdrawal Form -->
    <div class="bg-white rounded-2xl p-6 shadow-md mb-6">
      <h3 class="text-lg font-bold text-gray-700 mb-4">Withdraw to M-Pesa</h3>
      
      <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form id="withdrawForm" method="POST" class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="block text-gray-600 font-medium mb-1">Amount (KSh)</label>
          <input type="number" name="amount" min="50" max="<?= floor(str_replace(',', '', $wallet_balance)) ?>" 
                 placeholder="Min 50" required class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-green-500" />
          <small class="text-gray-500">Available: KSh <?= $wallet_balance ?></small>
        </div>
        <div>
          <label class="block text-gray-600 font-medium mb-1">M-Pesa Number</label>
          <input type="text" name="phone" required placeholder="0712345678" pattern="07[0-9]{8}" 
                 class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-green-500" />
        </div>
        <div class="md:col-span-2 text-right">
          <button type="submit" class="bg-green-600 text-white px-8 py-4 rounded-lg font-bold hover:bg-green-700 transition shadow-lg">
            Withdraw Now
          </button>
        </div>
      </form>
    </div>

    <!-- History -->
    <div class="bg-white rounded-2xl p-6 shadow-md">
      <h3 class="text-lg font-bold text-gray-700 mb-4">Withdrawal History</h3>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[600px] text-sm text-left text-gray-600">
          <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
            <tr>
              <th class="px-4 py-3">#</th>
              <th class="px-4 py-3">Date</th>
              <th class="px-4 py-3">Amount</th>
              <th class="px-4 py-3">Phone</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Ref</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($history)): ?>
              <tr><td colspan="6" class="text-center py-10 text-gray-500">No withdrawals yet</td></tr>
            <?php else: foreach ($history as $i => $w): ?>
              <tr class="border-b hover:bg-gray-50">
                <td class="px-4 py-3"><?= $i + 1 ?></td>
                <td class="px-4 py-3"><?= date('M j, Y g:i A', strtotime($w['requested_at'])) ?></td>
                <td class="px-4 py-3 font-bold text-green-600">KSh <?= number_format($w['amount'], 2) ?></td>
                <td class="px-4 py-3"><?= htmlspecialchars($w['phone']) ?></td>
                <td class="px-4 py-3">
                  <span class="px-3 py-1 rounded-full text-xs font-medium <?= $w['status']=='completed' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' ?>">
                    <?= ucfirst($w['status']) ?>
                  </span>
                </td>
                <td class="px-4 py-3 text-xs font-mono"><?= $w['reference'] ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Mobile Nav -->
  <nav class="fixed bottom-0 left-0 right-0 bg-white border-t shadow-lg flex justify-around items-center py-3 md:hidden z-50">
    <a href="dashboard.php" class="text-gray-600"><i class="fas fa-home text-xl"></i><span class="text-xs block">Home</span></a>
    <a href="transactions.php" class="text-gray-600"><i class="fas fa-receipt text-xl"></i><span class="text-xs block">Trans</span></a>
    <a href="my_wallet.php" class="text-blue-600"><i class="fas fa-wallet text-xl"></i><span class="text-xs block">Wallet</span></a>
    <a href="profile.php" class="text-gray-600"><i class="fas fa-user text-xl"></i><span class="text-xs block">Profile</span></a>
  </nav>

  <script>
    window.addEventListener("load", () => {
      document.getElementById("preloader").style.display = "none";
    });

    <?php if ($success): ?>
    Swal.fire({
      icon: 'success',
      title: 'Withdrawal Requested!',
      text: '<?= $success ?>',
      confirmButtonColor: '#10b981',
      timer: 5000
    });
    <?php endif; ?>

    <?php if ($error && !$success): ?>
    Swal.fire({
      icon: 'error',
      title: 'Oops...',
      text: '<?= $error ?>',
      confirmButtonColor: '#ef4444'
    });
    <?php endif; ?>
  </script>
</body>
</html>