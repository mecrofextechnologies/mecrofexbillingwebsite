<?php
include '../msanii/includes/connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only allow admins
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

try {
    // Fetch ALL support transactions
    $query = "
        SELECT 
            id,
            checkout_request_id,
            transaction_id,
            supporter_name,
            creator_id,
            amount,
            mpesa_receipt,
            phone,
            transaction_date,
            status,
            created_at
        FROM support
        ORDER BY created_at DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $all_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ────────────────────────────────────────────────
    // Analytics — based ONLY on whether mpesa_receipt exists
    // ────────────────────────────────────────────────
    $total_amount_success = 0;
    $success_count        = 0;
    $failed_count         = 0;

    $daily_data = []; // trend chart - only success

    foreach ($all_transactions as $row) {
        $receipt = trim($row['mpesa_receipt'] ?? '');
        $amount  = floatval($row['amount'] ?? 0);

        $is_success = !empty($receipt);

        if ($is_success) {
            $success_count++;
            $total_amount_success += $amount;

            // Daily trend (only success)
            $date_key = date('Y-m-d', strtotime($row['created_at']));
            if (!isset($daily_data[$date_key])) {
                $daily_data[$date_key] = [
                    'date'   => date('d M Y', strtotime($row['created_at'])),
                    'amount' => 0,
                    'count'  => 0
                ];
            }
            $daily_data[$date_key]['amount'] += $amount;
            $daily_data[$date_key]['count']++;
        } else {
            $failed_count++;
        }
    }

    $total_transactions = count($all_transactions);
    $success_rate = $total_transactions > 0 ? round(($success_count / $total_transactions) * 100, 1) : 0;

    // Prepare chart data
    ksort($daily_data);
    $chart_labels = array_column($daily_data, 'date');
    $chart_amount = array_column($daily_data, 'amount');
    $chart_count  = array_column($daily_data, 'count');

    // ────────────────────────────────────────────────
    // Pagination
    // ────────────────────────────────────────────────
    $per_page = 20;
    $current_page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($current_page - 1) * $per_page;
    $total_pages = ceil($total_transactions / $per_page);

    $transactions = array_slice($all_transactions, $offset, $per_page);

} catch (PDOException $e) {
    $error = "Database error: " . htmlspecialchars($e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Support Transactions • Analytics</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    body {
      background-color: #f8f9fc;
      font-family: system-ui, -apple-system, sans-serif;
    }
    .card {
      border: none;
      box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    }
    .card-header {
      background: linear-gradient(135deg, #1e40af, #3b82f6);
      color: white;
    }
    .kpi-card {
      transition: transform 0.25s ease;
    }
    .kpi-card:hover {
      transform: translateY(-5px);
    }
    .status-success { background:#d1fae5; color:#065f46; font-weight:500; }
    .status-failed  { background:#fee2e2; color:#991b1b; }
    .table th {
      background:#f1f5f9;
      font-size: 0.84rem;
      text-transform: uppercase;
      letter-spacing: 0.4px;
    }
    .pagination .page-link {
      color: #1e40af;
    }
    .pagination .page-item.active .page-link {
      background-color: #1e40af;
      border-color: #1e40af;
    }
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="fw-bold mb-0">
      <i class="fas fa-hand-holding-heart me-2"></i> Support Transactions
    </h3>
    <small class="text-muted">Success = has M-Pesa Receipt • Failure = no M-Pesa Receipt</small>
  </div>

  <?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= $error ?></div>
  <?php endif; ?>

  <!-- KPI Cards -->
  <div class="row g-4 mb-5">
    <div class="col-xl-3 col-md-6">
      <div class="card kpi-card h-100 border-0">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Total Amount (Success)</h6>
          <h3 class="fw-bold text-primary">KSh <?= number_format($total_amount_success) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6">
      <div class="card kpi-card h-100 border-0">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Successful Transactions</h6>
          <h3 class="fw-bold text-success"><?= number_format($success_count) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6">
      <div class="card kpi-card h-100 border-0">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Total Transactions</h6>
          <h3 class="fw-bold"><?= number_format($total_transactions) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6">
      <div class="card kpi-card h-100 border-0">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Success Rate</h6>
          <h3 class="fw-bold text-info"><?= $success_rate ?>%</h3>
        </div>
      </div>
    </div>
  </div>

  <!-- Status Breakdown -->
  <div class="row g-3 mb-5">
    <div class="col-md-6">
      <div class="card border-0 h-100 text-center py-4">
        <h5 class="text-success mb-1">Success (has Receipt)</h5>
        <h3 class="fw-bold"><?= number_format($success_count) ?></h3>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card border-0 h-100 text-center py-4">
        <h5 class="text-danger mb-1">Failed (no Receipt)</h5>
        <h3 class="fw-bold"><?= number_format($failed_count) ?></h3>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row g-4 mb-5">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Amount & Transactions Trend (Success Only)</h5>
        </div>
        <div class="card-body p-3">
          <canvas id="trendChart" height="130"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="mb-0">Success vs Failed</h5>
        </div>
        <div class="card-body d-flex align-items-center justify-content-center p-4">
          <canvas id="statusChart" height="180"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">All Support Transactions (<?= number_format($total_transactions) ?> total)</h5>
      <div style="max-width:320px;">
        <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search name, phone, receipt...">
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover mb-0" id="transactionsTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Supporter</th>
            <th>Phone</th>
            <th>Amount</th>
            <th>M-Pesa Receipt</th>
            <th>Transaction ID</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($transactions)): ?>
            <?php foreach ($transactions as $row): ?>
              <?php
                $receipt = trim($row['mpesa_receipt'] ?? '');
                $is_success = !empty($receipt);

                $badge_class = $is_success ? 'status-success' : 'status-failed';
                $display_status = $is_success ? 'Success' : 'Failed';
              ?>
              <tr>
                <td><?= date('d M Y • H:i', strtotime($row['created_at'])) ?></td>
                <td>
                  <strong><?= htmlspecialchars($row['supporter_name'] ?? '-') ?></strong>
                </td>
                <td><?= htmlspecialchars($row['phone'] ?? '-') ?></td>
                <td class="fw-bold text-success">
                  KSh <?= number_format($row['amount'] ?? 0) ?>
                </td>
                <td>
                  <code><?= htmlspecialchars($row['mpesa_receipt'] ?? '-') ?></code>
                </td>
                <td><?= htmlspecialchars($row['transaction_id'] ?? '-') ?></td>
                <td>
                  <span class="badge px-3 py-2 <?= $badge_class ?>">
                    <?= $display_status ?>
                  </span>
                </td>
                <td class="text-end">
                  <a href="view_support.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-eye"></i> View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="8" class="text-center py-5 text-muted">
                <i class="fas fa-hand-holding-heart fa-2x mb-3 d-block opacity-50"></i>
                No support transactions found.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <div class="text-muted">Page <?= $current_page ?> of <?= $total_pages ?></div>
      <nav aria-label="Pagination">
        <ul class="pagination mb-0">
          <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $current_page - 1 ?>">Previous</a>
          </li>
          <?php
            $start = max(1, $current_page - 2);
            $end   = min($total_pages, $current_page + 2);
            for ($i = $start; $i <= $end; $i++):
          ?>
            <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
              <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link" href="?page=<?= $current_page + 1 ?>">Next</a>
          </li>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Client-side search
document.getElementById('searchInput')?.addEventListener('input', function(e) {
  const term = e.target.value.toLowerCase();
  document.querySelectorAll('#transactionsTable tbody tr').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
  });
});

// Trend Chart (Amount + Count) - only successful
const trendCtx = document.getElementById('trendChart')?.getContext('2d');
if (trendCtx) {
  new Chart(trendCtx, {
    type: 'line',
    data: {
      labels: <?= json_encode($chart_labels) ?>,
      datasets: [
        { label: 'Amount (KSh)', data: <?= json_encode($chart_amount) ?>, borderColor: '#3b82f6', tension: 0.3, fill: true },
        { label: 'Transactions', data: <?= json_encode($chart_count) ?>, borderColor: '#10b981', tension: 0.3, yAxisID: 'y1' }
      ]
    },
    options: {
      responsive: true,
      scales: {
        y: { position: 'left', title: { display: true, text: 'Amount (KSh)' } },
        y1: { position: 'right', title: { display: true, text: 'Count' }, grid: { drawOnChartArea: false } }
      }
    }
  });
}

// Success vs Failed Doughnut
const statusCtx = document.getElementById('statusChart')?.getContext('2d');
if (statusCtx) {
  new Chart(statusCtx, {
    type: 'doughnut',
    data: {
      labels: ['Success', 'Failed'],
      datasets: [{
        data: [<?= $success_count ?>, <?= $failed_count ?>],
        backgroundColor: ['#10b981', '#ef4444']
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'bottom' }
      }
    }
  });
}
</script>
</body>
</html>