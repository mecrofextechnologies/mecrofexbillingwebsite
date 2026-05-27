<?php
include 'check_user_page.php';
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

// Wallet Summary
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

// Filters
$status_filter = $_GET['status'] ?? 'all';
$search_query  = trim($_GET['search'] ?? '');

// Build query
$sql = "SELECT 
            supporter_name,
            phone,
            amount,
            mpesa_receipt,
            COALESCE(transaction_date, created_at) AS display_date
        FROM support 
        WHERE creator_id = ?";

$params = [$creator_id];

// Status filter based on mpesa_receipt only
if ($status_filter === 'success') {
    $sql .= " AND mpesa_receipt IS NOT NULL AND TRIM(mpesa_receipt) != ''";
} elseif ($status_filter === 'failed') {
    $sql .= " AND (mpesa_receipt IS NULL OR TRIM(mpesa_receipt) = '')";
}

// Search filter
if ($search_query !== '') {
    $sql .= " AND (supporter_name LIKE ? OR phone LIKE ? OR mpesa_receipt LIKE ?)";
    $like = "%$search_query%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY display_date DESC, id DESC LIMIT 300";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Transactions | Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://kit.fontawesome.com/a2d9d5a04b.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body { -webkit-font-smoothing: antialiased; }
    .tab-active { background-color: #2563eb; color: white; border-bottom: 3px solid #1e40af; }
    .tab-btn { padding: 10px 20px; font-medium; border-radius: 8px 8px 0 0; }
    .tab-btn:hover:not(.tab-active) { background-color: #f3f4f6; }
    .table-container { max-height: 520px; overflow-y: auto; }
    @media (max-width: 640px) {
      .text-2xl { font-size: 1.15rem !important; }
      .p-6 { padding: 1rem !important; }
      .pb-20 { padding-bottom: 5.5rem !important; }
    }
  </style>
</head>
<body class="bg-gray-50">

  <!-- Original Preloader -->
  <div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
      <div class="h-5 w-5 border-2 border-green-500 border-t-transparent rounded-full animate-spin"></div>
      <span class="text-gray-700 font-medium">Loading Transactions...</span>
    </div>
  </div>

  <div class="p-4 md:p-6 min-h-screen pb-20">
    <div class="bg-gradient-to-r from-indigo-600 to-blue-600 rounded-2xl text-white p-6 mb-6 shadow-lg text-center md:text-left">
      <h2 class="text-2xl font-bold mb-1">My Transactions</h2>
      <p class="opacity-90">All support payments from your fans</p>
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

    <!-- Transactions Table -->
    <div class="bg-white rounded-2xl p-6 shadow-md">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-bold text-gray-700">Transaction History</h3>
        <div class="relative">
          <input type="text" id="searchInput" placeholder="Search name, phone, receipt..."
                 class="pl-10 pr-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 w-64"
                 value="<?= htmlspecialchars($search_query) ?>">
          <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
        </div>
      </div>

      <!-- Tabs -->
      <div class="flex border-b mb-4">
        <button class="tab-btn <?= $status_filter === 'all' ? 'tab-active' : '' ?>" data-status="all">All</button>
        <button class="tab-btn <?= $status_filter === 'success' ? 'tab-active' : '' ?>" data-status="success">Success</button>
        <button class="tab-btn <?= $status_filter === 'failed' ? 'tab-active' : '' ?>" data-status="failed">Failed</button>
      </div>

      <!-- Table -->
      <div class="overflow-x-auto table-container">
        <table class="w-full min-w-[700px] text-sm text-left text-gray-600">
          <thead class="bg-gray-100 text-gray-700 uppercase text-xs sticky top-0">
            <tr>
              <th class="px-4 py-3">Date</th>
              <th class="px-4 py-3">Supporter</th>
              <th class="px-4 py-3">Amount</th>
              <th class="px-4 py-3">Phone</th>
              <th class="px-4 py-3">M-Pesa Receipt</th>
              <th class="px-4 py-3">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($transactions)): ?>
              <tr><td colspan="6" class="text-center py-10 text-gray-500">No transactions found</td></tr>
            <?php else: ?>
              <?php foreach ($transactions as $t): ?>
                <?php
                  $hasReceipt = !empty(trim($t['mpesa_receipt'] ?? ''));
                  $date = $t['display_date'] ?? null;
                  $dateShow = ($date && $date !== '0000-00-00 00:00:00') 
                      ? date('M j, Y g:i A', strtotime($date))
                      : 'Pending';
                ?>
                <tr class="border-b hover:bg-gray-50">
                  <td class="px-4 py-3"><?= $dateShow ?></td>
                  <td class="px-4 py-3 font-medium"><?= htmlspecialchars($t['supporter_name'] ?? 'Anonymous') ?></td>
                  <td class="px-4 py-3 font-bold text-green-600">KSh <?= number_format($t['amount'] ?? 0, 2) ?></td>
                  <td class="px-4 py-3"><?= htmlspecialchars($t['phone'] ?? '-') ?></td>
                  <td class="px-4 py-3 font-mono text-xs">
                    <?= $hasReceipt ? htmlspecialchars($t['mpesa_receipt']) : '<span class="text-red-600">—</span>' ?>
                  </td>
                  <td class="px-4 py-3">
                    <span class="px-3 py-1 rounded-full text-xs font-bold <?= $hasReceipt ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                      <?= $hasReceipt ? 'Success' : 'Failed' ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Mobile Nav -->
  <nav class="fixed bottom-0 left-0 right-0 bg-white border-t shadow-lg flex justify-around items-center py-3 md:hidden z-50">
    <a href="dashboard.php" class="text-gray-600"><i class="fas fa-home text-xl"></i><span class="text-xs block">Home</span></a>
    <a href="transactions.php" class="text-blue-600"><i class="fas fa-receipt text-xl"></i><span class="text-xs block">Trans</span></a>
    <a href="my_wallet.php" class="text-gray-600"><i class="fas fa-wallet text-xl"></i><span class="text-xs block">Wallet</span></a>
    <a href="profile.php" class="text-gray-600"><i class="fas fa-user text-xl"></i><span class="text-xs block">Profile</span></a>
  </nav>

  <script>
    window.addEventListener("load", () => {
      document.getElementById("preloader").style.display = "none";
    });

    // Tabs
    document.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const status = btn.dataset.status;
        const url = new URL(window.location);
        if (status === 'all') {
          url.searchParams.delete('status');
        } else {
          url.searchParams.set('status', status);
        }
        url.searchParams.delete('search');
        window.location = url;
      });
    });

    // Live search
    let timeout;
    document.getElementById('searchInput').addEventListener('input', function() {
      clearTimeout(timeout);
      timeout = setTimeout(() => {
        const val = this.value.trim();
        const url = new URL(window.location);
        if (val) {
          url.searchParams.set('search', val);
        } else {
          url.searchParams.delete('search');
        }
        window.location = url;
      }, 600);
    });
  </script>
</body>
</html>