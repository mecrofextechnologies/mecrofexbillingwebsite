<?php
// surveys_analytics.php
include '../msanii/includes/connect.php';

// Access control (same as your other admin pages)
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

// Safe defaults
$total_surveys       = 0;
$active_surveys      = 0;
$total_responses     = 0;
$survey_data         = [];     // per-survey stats
$daily_responses     = [];     // trend
$all_surveys         = [];
$error               = null;

try {
    // 1. Fetch all surveys
    $stmt = $pdo->prepare("
        SELECT id, creator_id, title, description, created_at, is_active
        FROM surveys
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $all_surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_surveys = count($all_surveys);

    $active_surveys = count(array_filter($all_surveys, fn($s) => $s['is_active'] == 1));

    // 2. Fetch all responses + count per survey + daily trend
    $stmt = $pdo->prepare("
        SELECT 
            sr.id, sr.survey_id, sr.respondent_ip, sr.respondent_email,
            sr.answers, sr.submitted_at,
            s.title
        FROM survey_responses sr
        LEFT JOIN surveys s ON sr.survey_id = s.id
        ORDER BY sr.submitted_at DESC
    ");
    $stmt->execute();
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_responses = count($responses);

    // Group by survey & build daily trend
    foreach ($responses as $r) {
        $sid = $r['survey_id'];
        $title = $r['title'] ?: 'Untitled Survey #' . $sid;

        if (!isset($survey_data[$sid])) {
            $survey_data[$sid] = [
                'title'         => $title,
                'responses'     => 0,
                'response_list' => [],   // for modal
            ];
        }

        $survey_data[$sid]['responses']++;
        $survey_data[$sid]['response_list'][] = [
            'email'     => $r['respondent_email'] ?: '—',
            'ip'        => $r['respondent_ip']    ?: '—',
            'submitted' => date('d M Y • H:i', strtotime($r['submitted_at'])),
            'answers'   => $r['answers']          // JSON string
        ];

        // Daily trend
        $date_key = date('Y-m-d', strtotime($r['submitted_at']));
        if (!isset($daily_responses[$date_key])) {
            $daily_responses[$date_key] = [
                'date'     => date('d M Y', strtotime($r['submitted_at'])),
                'count'    => 0
            ];
        }
        $daily_responses[$date_key]['count']++;
    }

    // Sort surveys by response count descending
    uasort($survey_data, fn($a, $b) => $b['responses'] <=> $a['responses']);

    // Prepare chart data
    ksort($daily_responses);
    $chart_labels = array_column($daily_responses, 'date');
    $chart_counts = array_column($daily_responses, 'count');

    $survey_labels = array_map(fn($d) => $d['title'], $survey_data);
    $survey_counts = array_column($survey_data, 'responses');

} catch (PDOException $e) {
    $error = "Database error: " . htmlspecialchars($e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Surveys Analytics</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    body { background-color: #f8f9fc; font-family: system-ui, -apple-system, sans-serif; }
    .card { border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
    .card-header { background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; }
    .kpi-card:hover { transform: translateY(-4px); transition: 0.25s; }
    .table th { background: #f1f5f9; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.4px; }
    .modal-body { max-height: 70vh; overflow-y: auto; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 6px; font-size: 0.9rem; }
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="fw-bold mb-0">
      <i class="fas fa-poll me-2"></i> Surveys Analytics
    </h3>
    <small class="text-muted">Overview of created surveys and collected responses</small>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
  <?php endif; ?>

  <!-- KPI Cards -->
  <div class="row g-4 mb-5">
    <div class="col-xl-3 col-md-6">
      <div class="card kpi-card h-100">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Total Surveys</h6>
          <h3 class="fw-bold text-primary"><?= number_format($total_surveys) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6">
      <div class="card kpi-card h-100">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Active Surveys</h6>
          <h3 class="fw-bold text-success"><?= number_format($active_surveys) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6">
      <div class="card kpi-card h-100">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Total Responses</h6>
          <h3 class="fw-bold text-info"><?= number_format($total_responses) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-3 col-md-6">
      <div class="card kpi-card h-100">
        <div class="card-body text-center">
          <h6 class="text-muted mb-1">Avg Responses/Survey</h6>
          <h3 class="fw-bold text-secondary">
            <?= $total_surveys > 0 ? round($total_responses / $total_surveys, 1) : 0 ?>
          </h3>
        </div>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row g-4 mb-5">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Responses Trend</h5></div>
        <div class="card-body p-3">
          <?php if (!empty($chart_labels)): ?>
            <canvas id="trendChart" height="140"></canvas>
          <?php else: ?>
            <div class="text-center py-5 text-muted">No responses yet</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Responses per Survey</h5></div>
        <div class="card-body p-3">
          <?php if (!empty($survey_data)): ?>
            <canvas id="surveyChart" height="200"></canvas>
          <?php else: ?>
            <div class="text-center py-5 text-muted">
              <i class="fas fa-poll fa-2x mb-3 opacity-50"></i><br>No responses collected
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Surveys Table -->
  <div class="card mb-5">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">All Surveys</h5>
      <small><?= count($all_surveys) ?> surveys • <?= $total_responses ?> responses</small>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Title</th>
            <th>Status</th>
            <th>Created</th>
            <th class="text-end">Responses</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($all_surveys)): ?>
            <?php foreach ($all_surveys as $survey): 
              $sid = $survey['id'];
              $stats = $survey_data[$sid] ?? ['responses' => 0, 'response_list' => []];
            ?>
              <tr>
                <td class="fw-bold"><?= htmlspecialchars($survey['title'] ?: 'Untitled') ?></td>
                <td>
                  <?php if ($survey['is_active']): ?>
                    <span class="badge bg-success">Active</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Inactive</span>
                  <?php endif; ?>
                </td>
                <td><?= date('d M Y', strtotime($survey['created_at'])) ?></td>
                <td class="text-end fw-bold"><?= number_format($stats['responses']) ?></td>
                <td class="text-end">
                  <?php if (!empty($stats['response_list'])): ?>
                    <button class="btn btn-sm btn-outline-primary view-responses"
                            data-title="<?= htmlspecialchars($stats['title']) ?>"
                            data-responses='<?= htmlspecialchars(json_encode($stats['response_list'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                      <i class="fas fa-list me-1"></i> View Responses
                    </button>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5" class="text-center py-5 text-muted">
                <i class="fas fa-poll fa-2x mb-3 opacity-50"></i><br>No surveys found.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Responses Modal -->
<div class="modal fade" id="responsesModal" tabindex="-1" aria-labelledby="responsesModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="responsesModalLabel">Responses for: <span id="modalSurveyTitle"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm table-bordered">
          <thead>
            <tr>
              <th>Email</th>
              <th>IP Address</th>
              <th>Submitted</th>
              <th>Answers</th>
            </tr>
          </thead>
          <tbody id="modalResponsesBody"></tbody>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Trend Chart - Responses over time
const trendCtx = document.getElementById('trendChart')?.getContext('2d');
if (trendCtx && <?= json_encode($chart_labels) ?>.length) {
  new Chart(trendCtx, {
    type: 'line',
    data: {
      labels: <?= json_encode($chart_labels) ?>,
      datasets: [{
        label: 'Responses',
        data: <?= json_encode($chart_counts) ?>,
        borderColor: '#10b981',
        backgroundColor: 'rgba(16, 185, 129, 0.2)',
        tension: 0.3,
        fill: true
      }]
    },
    options: { responsive: true }
  });
}

// Survey Responses Bar Chart
const surveyCtx = document.getElementById('surveyChart')?.getContext('2d');
if (surveyCtx && <?= json_encode($survey_labels) ?>.length) {
  new Chart(surveyCtx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($survey_labels) ?>,
      datasets: [{
        label: 'Responses',
        data: <?= json_encode($survey_counts) ?>,
        backgroundColor: '#8b5cf6'
      }]
    },
    options: {
      responsive: true,
      indexAxis: 'y',
      plugins: { legend: { display: false } },
      scales: { x: { title: { display: true, text: 'Number of Responses' } } }
    }
  });
}

// View Responses Modal
document.querySelectorAll('.view-responses').forEach(btn => {
  btn.addEventListener('click', function() {
    const title = this.getAttribute('data-title');
    const responses = JSON.parse(this.getAttribute('data-responses'));

    document.getElementById('modalSurveyTitle').textContent = title;

    const tbody = document.getElementById('modalResponsesBody');
    tbody.innerHTML = '';

    if (responses.length === 0) {
      tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4">No responses yet</td></tr>';
      return;
    }

    responses.forEach(r => {
      const row = document.createElement('tr');
      let answersDisplay = '—';
      try {
        const ans = JSON.parse(r.answers);
        answersDisplay = '<pre>' + JSON.stringify(ans, null, 2) + '</pre>';
      } catch (e) {
        answersDisplay = '<pre>' + r.answers + '</pre>';
      }

      row.innerHTML = `
        <td>${r.email}</td>
        <td><code>${r.ip}</code></td>
        <td>${r.submitted}</td>
        <td>${answersDisplay}</td>
      `;
      tbody.appendChild(row);
    });

    new bootstrap.Modal(document.getElementById('responsesModal')).show();
  });
});
</script>
</body>
</html>