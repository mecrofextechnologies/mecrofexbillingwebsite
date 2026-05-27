<?php
// my_withdrawals.php
include 'includes/connect.php';

// Logged in creator ID
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    die("Please login first.");
}

$full_name = $email = '';
try {
    $stmt = $pdo->prepare("SELECT full_name, email FROM creators WHERE id = ?");
    $stmt->execute([$creator_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($creator) {
        $full_name = $creator['full_name'] ?? 'Unknown';
        $email = $creator['email'] ?? 'Not set';
    }
} catch (PDOException $e) {
    $error = "Error fetching profile: " . $e->getMessage();
}

// Fetch all withdrawal requests for this creator
$withdrawals = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            id,
            amount,
            mpesa_number,
            status,
            transaction_code,
            requested_at,
            processed_at
        FROM withdrawal_requests
        WHERE creator_id = ?
        ORDER BY requested_at DESC
    ");
    $stmt->execute([$creator_id]);
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching withdrawal history: " . $e->getMessage();
}

// Current balance (for display)
$current_balance = 0.00;
try {
    $stmt = $pdo->prepare("SELECT amount FROM my_balance WHERE creator_id = ? LIMIT 1");
    $stmt->execute([$creator_id]);
    $balance_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_balance = $balance_row ? (float)$balance_row['amount'] : 0.00;
} catch (PDOException $e) {
    $error = "Error fetching balance: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Withdrawals | Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <!-- SheetJS for Excel export -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>
<body class="bg-gray-50">

<!-- Preloader - same style as request page -->
<div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm">
  <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
    <div class="h-5 w-5 border-2 border-green-500 border-t-transparent rounded-full animate-spin"></div>
    <span class="text-gray-700 font-medium">Loading... Please wait!</span>
  </div>
</div>

<div class="p-4 md:p-6 min-h-screen pb-20">

  <!-- Header - matching request page style -->
  <div class="bg-gradient-to-r from-purple-600 to-blue-600 p-6 rounded-2xl shadow-lg text-white mb-6 flex justify-between items-center">
    <div>
      <h2 class="text-2xl font-bold">My Withdrawals</h2>
      <p class="opacity-90">Track your withdrawal history</p>
    </div>
    <i class="fa-solid fa-history text-5xl opacity-80"></i>
  </div>

  <?php if (!empty($error)): ?>
    <div class="bg-red-100 text-red-800 p-4 rounded-xl mb-6">
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow-md p-6">

    <!-- Balance summary -->
    <div class="mb-8 p-4 bg-purple-50 rounded-xl border border-purple-100 flex justify-between items-center flex-wrap gap-4">
      <p class="text-lg font-semibold text-purple-800">
        Current Balance: <span class="text-2xl">KES <?= number_format($current_balance, 2) ?></span>
      </p>
      <a href="request_withdrawals.php" 
         class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-xl font-medium transition flex items-center gap-2">
        <i class="fa-solid fa-money-bill-transfer"></i>
        New Withdrawal
      </a>
    </div>

    <!-- Export button -->
    <?php if (!empty($withdrawals)): ?>
      <div class="mb-6 flex justify-end">
        <button id="exportExcel" 
                class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-xl font-medium transition flex items-center gap-2 shadow-sm">
          <i class="fa-solid fa-file-excel"></i>
          Export to Excel
        </button>
      </div>
    <?php endif; ?>

    <!-- Table -->
    <?php if (empty($withdrawals)): ?>
      <div class="text-center py-12 text-gray-500">
        <i class="fa-solid fa-inbox text-5xl mb-4 opacity-50"></i>
        <p class="text-lg">No withdrawal requests yet.</p>
        <p class="mt-2">Make your first withdrawal request to see history here.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto rounded-xl border border-gray-200">
        <table id="withdrawalsTable" class="min-w-full bg-white">
          <thead class="bg-gray-100">
            <tr>
              <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Date</th>
              <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Amount (KES)</th>
              <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">M-Pesa Number</th>
              <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Status</th>
              <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Transaction Code</th>
              <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700">Processed</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <?php foreach ($withdrawals as $wd): ?>
              <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-4 text-gray-700">
                  <?= date('d M Y • H:i', strtotime($wd['requested_at'])) ?>
                </td>
                <td class="px-6 py-4 font-medium">
                  <?= number_format($wd['amount'], 2) ?>
                </td>
                <td class="px-6 py-4 text-gray-700">
                  <?= htmlspecialchars($wd['mpesa_number']) ?>
                </td>
                <td class="px-6 py-4">
                  <?php
                  $status = strtolower($wd['status']);
                  $colors = [
                    'pending'    => 'bg-yellow-100 text-yellow-800',
                    'processing' => 'bg-blue-100 text-blue-800',
                    'completed'  => 'bg-green-100 text-green-800',
                    'failed'     => 'bg-red-100 text-red-800',
                    'cancelled'  => 'bg-gray-100 text-gray-800'
                  ];
                  $colorClass = $colors[$status] ?? 'bg-gray-100 text-gray-800';
                  ?>
                  <span class="px-3 py-1 rounded-full text-xs font-medium <?= $colorClass ?>">
                    <?= ucfirst($status) ?>
                  </span>
                </td>
                <td class="px-6 py-4 text-gray-700">
                  <?= $wd['transaction_code'] ? htmlspecialchars($wd['transaction_code']) : '—' ?>
                </td>
                <td class="px-6 py-4 text-gray-700">
                  <?= $wd['processed_at'] ? date('d M Y • H:i', strtotime($wd['processed_at'])) : '—' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

<script>
// Preloader fade-out (same as request page)
window.addEventListener("load", function () {
    const loader = document.getElementById("preloader");
    loader.style.opacity = "0";
    loader.style.transition = "opacity 0.3s ease";
    setTimeout(() => { loader.style.display = "none"; }, 300);
});

// Excel Export
document.getElementById('exportExcel')?.addEventListener('click', function () {
    const table = document.getElementById('withdrawalsTable');
    const wb = XLSX.utils.table_to_book(table, {sheet:"Withdrawals"});
    
    // Optional: customize filename with date
    const today = new Date().toISOString().slice(0,10);
    XLSX.writeFile(wb, `Keychele_Withdrawals_${today}.xlsx`);
});
</script>

</body>
</html>