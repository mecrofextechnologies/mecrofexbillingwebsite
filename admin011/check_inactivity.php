<?php
// all_creators.php  — with inactivity management
include '../msanii/includes/connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

try {
    $query = "
        SELECT id, full_name, username, phone, email,
               date_created, must_change_password, updated_at,
               last_login, inactivity_warning_sent_at, scheduled_delete_date
        FROM creators
        ORDER BY date_created DESC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $all_creators = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_creators   = count($all_creators);
    $must_change_count = 0;
    $warned_count      = 0;
    $due_deletion_count = 0;

    // Load inactivity settings
    $settings = $pdo->query("SELECT * FROM inactivity_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        // defaults if table not yet created
        $settings = ['inactivity_days'=>30,'grace_period_days'=>7,'auto_delete_enabled'=>1,
                     'talksasa_api_key'=>'','talksasa_sender_id'=>'KEYCHELE',
                     'smtp_from_email'=>'noreply@keychele.com','smtp_from_name'=>'Keychele Platform',
                     'warning_email_subject'=>'Action Required: Your account will be deleted'];
    }

    foreach ($all_creators as $c) {
        if ($c['must_change_password'] == 1) $must_change_count++;
        if ($c['inactivity_warning_sent_at'])  $warned_count++;
        if ($c['scheduled_delete_date'] && $c['scheduled_delete_date'] <= date('Y-m-d')) $due_deletion_count++;
    }

    // Pagination
    $per_page     = 20;
    $current_page = max(1, (int)($_GET['internal_page'] ?? 1));
    $offset       = ($current_page - 1) * $per_page;
    $total_pages  = ceil($total_creators / $per_page);
    $creators     = array_slice($all_creators, $offset, $per_page);

    // Fetch recent inactivity log
    $recent_log = $pdo->query("SELECT * FROM inactivity_log ORDER BY actioned_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Database error: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>All Creators • Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    body { background:#f8f9fc; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
    .card { border:none; box-shadow:0 4px 20px rgba(0,0,0,0.06); }
    .card-header { background:linear-gradient(135deg,#1e40af,#3b82f6); color:#fff; }
    .card-header.danger-header { background:linear-gradient(135deg,#991b1b,#dc2626); }
    .card-header.warning-header { background:linear-gradient(135deg,#92400e,#d97706); }
    .card-header.success-header { background:linear-gradient(135deg,#065f46,#059669); }
    .kpi-card { transition:transform .25s; }
    .kpi-card:hover { transform:translateY(-5px); }
    .status-yes { background:#d1fae5;color:#065f46;font-weight:500; }
    .status-no  { background:#e5e7eb;color:#374151; }
    .status-warned { background:#fef3c7;color:#92400e;font-weight:500; }
    .status-due    { background:#fee2e2;color:#991b1b;font-weight:bold; }
    .table th { background:#f1f5f9;font-size:.84rem;text-transform:uppercase;letter-spacing:.4px; }
    .pagination .page-link { color:#1e40af; }
    .pagination .page-item.active .page-link { background-color:#1e40af;border-color:#1e40af; }
    .nav-tabs .nav-link { color:#374151; }
    .nav-tabs .nav-link.active { color:#1e40af;font-weight:600;border-bottom:2px solid #1e40af; }
    .inactive-badge { font-size:.7rem; }
    .settings-card label { font-weight:500; font-size:.9rem; }
    .log-badge-sent    { background:#d1fae5;color:#065f46; }
    .log-badge-failed  { background:#fee2e2;color:#991b1b; }
    .log-badge-skipped { background:#e5e7eb;color:#374151; }
    .timeline-dot { width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px; }
  </style>
</head>
<body>
<div class="container-fluid py-4 px-4">

  <!-- Page Header -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="fw-bold mb-0"><i class="fas fa-users-cog me-2"></i>All Creators</h3>
    <a href="index.php?page=create_creator" class="btn btn-primary">
      <i class="fas fa-plus me-2"></i>Add New Creator
    </a>
  </div>

  <?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= $error ?></div>
  <?php endif; ?>

  <!-- KPI Cards -->
  <div class="row g-3 mb-4">
    <div class="col-xl-2 col-md-4">
      <div class="card kpi-card h-100">
        <div class="card-body text-center py-3">
          <h6 class="text-muted mb-1 small">Total Creators</h6>
          <h3 class="fw-bold text-primary mb-0"><?= number_format($total_creators) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-4">
      <div class="card kpi-card h-100">
        <div class="card-body text-center py-3">
          <h6 class="text-muted mb-1 small">Active Accounts</h6>
          <h3 class="fw-bold text-success mb-0"><?= number_format($total_creators - $must_change_count) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-4">
      <div class="card kpi-card h-100">
        <div class="card-body text-center py-3">
          <h6 class="text-muted mb-1 small">Must Change Password</h6>
          <h3 class="fw-bold text-warning mb-0"><?= number_format($must_change_count) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-4">
      <div class="card kpi-card h-100">
        <div class="card-body text-center py-3">
          <h6 class="text-muted mb-1 small">Warned (Inactive)</h6>
          <h3 class="fw-bold text-warning mb-0"><?= number_format($warned_count) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-4">
      <div class="card kpi-card h-100">
        <div class="card-body text-center py-3">
          <h6 class="text-muted mb-1 small">Due Deletion</h6>
          <h3 class="fw-bold text-danger mb-0"><?= number_format($due_deletion_count) ?></h3>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-4">
      <div class="card kpi-card h-100">
        <div class="card-body text-center py-3">
          <h6 class="text-muted mb-1 small">Inactivity Threshold</h6>
          <h3 class="fw-bold text-info mb-0"><?= $settings['inactivity_days'] ?><span class="fs-6 fw-normal">d</span></h3>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3" id="mainTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-creators"><i class="fas fa-list me-1"></i>Creators</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-inactivity"><i class="fas fa-user-clock me-1"></i>Inactivity Manager</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-settings"><i class="fas fa-cog me-1"></i>Settings</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-log"><i class="fas fa-history me-1"></i>Audit Log</a></li>
  </ul>

  <div class="tab-content">

    <!-- ══ TAB 1: Creators Table ══════════════════════════════ -->
    <div class="tab-pane fade show active" id="tab-creators">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">All Creators (<?= number_format($total_creators) ?> total)</h5>
          <input type="text" id="searchInput" class="form-control form-control-sm" style="max-width:280px" placeholder="Search name, email, phone…">
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="creatorsTable">
            <thead>
              <tr>
                <th>Date Created</th>
                <th>Full Name</th>
                <th>Username</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Last Login</th>
                <th>Must Change PW</th>
                <th>Inactivity Status</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($creators)): ?>
                <?php foreach ($creators as $row): ?>
                  <?php
                    // Determine inactivity display
                    $inactivity_class = '';
                    $inactivity_label = '<span class="badge status-no">Normal</span>';
                    if ($row['scheduled_delete_date'] && $row['scheduled_delete_date'] <= date('Y-m-d')) {
                        $inactivity_class = 'table-danger';
                        $inactivity_label = '<span class="badge status-due">⚠ Due Deletion</span>';
                    } elseif ($row['scheduled_delete_date']) {
                        $inactivity_class = 'table-warning';
                        $del = date('d M Y', strtotime($row['scheduled_delete_date']));
                        $inactivity_label = '<span class="badge status-warned">⚠ Delete '.$del.'</span>';
                    } elseif ($row['inactivity_warning_sent_at']) {
                        $inactivity_label = '<span class="badge status-warned">Warned</span>';
                    }
                  ?>
                  <tr class="<?= $inactivity_class ?>">
                    <td><?= date('d M Y', strtotime($row['date_created'])) ?></td>
                    <td><strong><?= htmlspecialchars($row['full_name'] ?? '-') ?></strong></td>
                    <td><?= htmlspecialchars($row['username'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['phone'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                    <td><?= $row['last_login'] ? date('d M Y', strtotime($row['last_login'])) : '<span class="text-muted">Never</span>' ?></td>
                    <td>
                      <?php if ($row['must_change_password'] == 1): ?>
                        <span class="badge px-2 py-1 status-yes">Yes</span>
                      <?php else: ?>
                        <span class="badge px-2 py-1 status-no">No</span>
                      <?php endif; ?>
                    </td>
                    <td><?= $inactivity_label ?></td>
                    <td class="text-end">
                      <a href="index.php?page=view_creator&id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info me-1"><i class="fas fa-eye"></i></a>
                      <a href="index.php?page=edit_creator&id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-secondary me-1"><i class="fas fa-edit"></i></a>
                      <?php if ($row['scheduled_delete_date']): ?>
                        <button class="btn btn-sm btn-outline-warning me-1 cancel-deletion-btn"
                                data-id="<?= $row['id'] ?>" data-name="<?= htmlspecialchars($row['full_name']) ?>">
                          <i class="fas fa-ban"></i>
                        </button>
                      <?php else: ?>
                        <button class="btn btn-sm btn-outline-warning me-1 force-warn-btn"
                                data-id="<?= $row['id'] ?>" data-name="<?= htmlspecialchars($row['full_name']) ?>">
                          <i class="fas fa-bell"></i>
                        </button>
                      <?php endif; ?>
                      <button class="btn btn-sm btn-outline-danger delete-creator-btn"
                              data-id="<?= $row['id'] ?>"
                              data-name="<?= htmlspecialchars($row['full_name'] ?? 'this creator') ?>">
                        <i class="fas fa-trash-alt"></i>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="9" class="text-center py-5 text-muted"><i class="fas fa-users-slash fa-2x mb-3 d-block opacity-50"></i>No creators found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
          <div class="text-muted small">Page <?= $current_page ?> of <?= $total_pages ?></div>
          <nav><ul class="pagination mb-0 pagination-sm">
            <li class="page-item <?= $current_page<=1?'disabled':'' ?>"><a class="page-link" href="?internal_page=<?= $current_page-1 ?>">Previous</a></li>
            <?php for ($i=max(1,$current_page-2);$i<=min($total_pages,$current_page+2);$i++): ?>
              <li class="page-item <?= $i==$current_page?'active':'' ?>"><a class="page-link" href="?internal_page=<?= $i ?>"><?= $i ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $current_page>=$total_pages?'disabled':'' ?>"><a class="page-link" href="?internal_page=<?= $current_page+1 ?>">Next</a></li>
          </ul></nav>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ TAB 2: Inactivity Manager ═════════════════════════ -->
    <div class="tab-pane fade" id="tab-inactivity">
      <div class="card mb-4">
        <div class="card-header warning-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-user-clock me-2"></i>Inactive Creators Preview</h5>
          <button class="btn btn-light btn-sm" id="runPreviewBtn">
            <i class="fas fa-search me-1"></i>Scan Now
          </button>
        </div>
        <div class="card-body">
          <div class="alert alert-info small mb-3">
            <i class="fas fa-info-circle me-1"></i>
            Activity is measured from: <strong>Last Login</strong>, Last Ticket Created, Last Completed Sale.
            Creators inactive for <strong><?= $settings['inactivity_days'] - $settings['grace_period_days'] ?>+ days</strong> appear here.
            At <strong><?= $settings['inactivity_days'] ?> days</strong> they are auto-deleted (after <?= $settings['grace_period_days'] ?>-day warning grace period).
          </div>
          <div id="previewLoading" class="text-center py-4 d-none">
            <div class="spinner-border text-warning" role="status"></div>
            <div class="mt-2 text-muted small">Scanning all creators…</div>
          </div>
          <div id="previewResults" class="d-none">
            <div class="table-responsive">
              <table class="table table-sm table-hover" id="previewTable">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Days Inactive</th>
                    <th>Last Activity</th>
                    <th>Status</th>
                    <th>Delete Date</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="previewTbody"></tbody>
              </table>
            </div>
            <div id="previewEmpty" class="text-center py-4 text-muted d-none">
              <i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>
              No creators are currently inactive beyond the threshold.
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ TAB 3: Settings ═══════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-settings">
      <div class="row g-4">
        <!-- Inactivity Rules -->
        <div class="col-lg-6">
          <div class="card settings-card h-100">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>Inactivity Rules</h5></div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label">Inactivity Threshold (days)</label>
                <input type="number" class="form-control" id="s_inactivity_days" min="1" max="365" value="<?= $settings['inactivity_days'] ?>">
                <div class="form-text">Creator is deleted after this many days of inactivity.</div>
              </div>
              <div class="mb-3">
                <label class="form-label">Grace Period (days)</label>
                <input type="number" class="form-control" id="s_grace_period_days" min="1" max="60" value="<?= $settings['grace_period_days'] ?>">
                <div class="form-text">Warning is sent this many days before deletion. Must be less than threshold.</div>
              </div>
              <div class="mb-3">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="s_auto_delete_enabled" <?= $settings['auto_delete_enabled'] ? 'checked' : '' ?>>
                  <label class="form-check-label" for="s_auto_delete_enabled">Enable Auto-Deletion</label>
                </div>
                <div class="form-text">If off, warnings are sent but accounts are NOT automatically deleted.</div>
              </div>
              <div class="alert alert-warning small p-2">
                <i class="fas fa-calculator me-1"></i>
                With current settings: warnings sent at day <strong id="warnDay"><?= $settings['inactivity_days'] - $settings['grace_period_days'] ?></strong>,
                deletion at day <strong id="deleteDay"><?= $settings['inactivity_days'] ?></strong>.
              </div>
            </div>
          </div>
        </div>

        <!-- Notification Settings -->
        <div class="col-lg-6">
          <div class="card settings-card h-100">
            <div class="card-header success-header"><h5 class="mb-0"><i class="fas fa-bell me-2"></i>Notification Settings</h5></div>
            <div class="card-body">
              <h6 class="fw-bold text-muted mb-3">📱 Talk Sasa SMS</h6>
              <div class="mb-3">
                <label class="form-label">API Key</label>
                <input type="password" class="form-control" id="s_talksasa_api_key" value="<?= htmlspecialchars($settings['talksasa_api_key'] ?? '') ?>" placeholder="Your Talk Sasa API key">
              </div>
              <div class="mb-3">
                <label class="form-label">Sender ID</label>
                <input type="text" class="form-control" id="s_talksasa_sender_id" value="<?= htmlspecialchars($settings['talksasa_sender_id'] ?? 'KEYCHELE') ?>" maxlength="11">
                <div class="form-text">Max 11 characters. Must be registered with Talk Sasa.</div>
              </div>
              <hr>
              <h6 class="fw-bold text-muted mb-3">📧 Email</h6>
              <div class="mb-3">
                <label class="form-label">From Email</label>
                <input type="email" class="form-control" id="s_smtp_from_email" value="<?= htmlspecialchars($settings['smtp_from_email'] ?? '') ?>">
              </div>
              <div class="mb-3">
                <label class="form-label">From Name</label>
                <input type="text" class="form-control" id="s_smtp_from_name" value="<?= htmlspecialchars($settings['smtp_from_name'] ?? '') ?>">
              </div>
              <div class="mb-3">
                <label class="form-label">Warning Email Subject</label>
                <input type="text" class="form-control" id="s_warning_email_subject" value="<?= htmlspecialchars($settings['warning_email_subject'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- Save + Cron info -->
        <div class="col-12">
          <div class="card">
            <div class="card-body d-flex justify-content-between align-items-start">
              <div>
                <h6 class="fw-bold">⚙️ Cron Job Setup</h6>
                <p class="mb-1 small text-muted">Add this to your server's crontab to run daily at 6 AM:</p>
                <code class="small bg-light d-block p-2 rounded">0 6 * * * /usr/bin/php <?= dirname(__DIR__) ?>/admin/cron_inactivity.php >> /var/log/inactivity_cron.log 2>&1</code>
                <p class="mt-2 mb-0 small text-muted">Also add this to your login handler to track <code>last_login</code>:</p>
                <code class="small bg-light d-block p-2 rounded mt-1">$pdo->prepare("UPDATE creators SET last_login = NOW() WHERE id = ?")->execute([$creator_id]);</code>
              </div>
              <button class="btn btn-primary px-4 ms-4 flex-shrink-0" id="saveSettingsBtn">
                <i class="fas fa-save me-2"></i>Save Settings
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ TAB 4: Audit Log ═══════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-log">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-history me-2"></i>Inactivity Audit Log (Last 20)</h5>
          <a href="?page=inactivity_log_full" class="btn btn-light btn-sm">View All</a>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Creator</th>
                <th>Action</th>
                <th>Email</th>
                <th>SMS</th>
                <th>Last Activity</th>
                <th>Days Inactive</th>
                <th>Delete Date</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($recent_log)): ?>
                <?php foreach ($recent_log as $log): ?>
                  <tr>
                    <td class="small"><?= date('d M Y H:i', strtotime($log['actioned_at'])) ?></td>
                    <td>
                      <strong class="small"><?= htmlspecialchars($log['creator_name']) ?></strong><br>
                      <span class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($log['creator_email']) ?></span>
                    </td>
                    <td>
                      <?php
                        $ac = $log['action'];
                        $badge = match($ac) {
                          'warning_sent'       => 'bg-warning text-dark',
                          'deleted'            => 'bg-danger',
                          'deletion_cancelled' => 'bg-success',
                          default              => 'bg-secondary'
                        };
                        $label = str_replace('_', ' ', ucfirst($ac));
                      ?>
                      <span class="badge <?= $badge ?> small"><?= $label ?></span>
                    </td>
                    <td><span class="badge inactive-badge log-badge-<?= $log['email_status'] ?>"><?= ucfirst($log['email_status']) ?></span></td>
                    <td><span class="badge inactive-badge log-badge-<?= $log['sms_status'] ?>"><?= ucfirst($log['sms_status']) ?></span></td>
                    <td class="small"><?= $log['last_activity_date'] ? date('d M Y', strtotime($log['last_activity_date'])) : '-' ?></td>
                    <td class="text-center"><?= $log['inactivity_days_at_action'] ?? '-' ?></td>
                    <td class="small"><?= $log['scheduled_delete_date'] ? date('d M Y', strtotime($log['scheduled_delete_date'])) : '-' ?></td>
                    <td><span class="badge bg-light text-dark"><?= ucfirst($log['actioned_by']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="9" class="text-center py-4 text-muted">No log entries yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- /tab-content -->
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">PERMANENT ACCOUNT DELETION</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <p class="lead fw-bold text-danger mb-3">This action is <u>IRREVERSIBLE</u></p>
        <p class="fw-bold mb-3" id="deleteCreatorName"></p>
        <ul class="text-start text-danger small mb-0 mx-auto" style="max-width:380px">
          <li>All tickets &amp; events</li>
          <li>All ticket sales &amp; purchases</li>
          <li>Withdrawals &amp; transaction history</li>
          <li>Balance, surveys, support, page data</li>
        </ul>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger px-5" id="confirmDelete">Delete Permanently</button>
      </div>
    </div>
  </div>
</div>

<!-- Force Warn Modal -->
<div class="modal fade" id="forceWarnModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title">Send Inactivity Warning</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Send an inactivity warning (email + SMS) to <strong id="warnCreatorName"></strong>?</p>
        <p class="text-muted small mb-0">This will also set their scheduled deletion date to <?= $settings['grace_period_days'] ?> days from now.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning" id="confirmWarn">Send Warning</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Search ───────────────────────────────────────────────────
document.getElementById('searchInput')?.addEventListener('input', function(e) {
  const t = e.target.value.toLowerCase();
  document.querySelectorAll('#creatorsTable tbody tr').forEach(r => {
    r.style.display = r.textContent.toLowerCase().includes(t) ? '' : 'none';
  });
});

// ── Settings: live warning day calc ─────────────────────────
function updateWarnCalc() {
  const days  = parseInt(document.getElementById('s_inactivity_days').value) || 30;
  const grace = parseInt(document.getElementById('s_grace_period_days').value) || 7;
  document.getElementById('warnDay').textContent   = days - grace;
  document.getElementById('deleteDay').textContent = days;
}
document.getElementById('s_inactivity_days')?.addEventListener('input', updateWarnCalc);
document.getElementById('s_grace_period_days')?.addEventListener('input', updateWarnCalc);

// ── Save Settings ────────────────────────────────────────────
document.getElementById('saveSettingsBtn')?.addEventListener('click', async function() {
  const btn = this;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving…';

  const fd = new FormData();
  fd.append('action', 'save_settings');
  fd.append('inactivity_days',      document.getElementById('s_inactivity_days').value);
  fd.append('grace_period_days',    document.getElementById('s_grace_period_days').value);
  fd.append('talksasa_api_key',     document.getElementById('s_talksasa_api_key').value);
  fd.append('talksasa_sender_id',   document.getElementById('s_talksasa_sender_id').value);
  fd.append('smtp_from_email',      document.getElementById('s_smtp_from_email').value);
  fd.append('smtp_from_name',       document.getElementById('s_smtp_from_name').value);
  fd.append('warning_email_subject',document.getElementById('s_warning_email_subject').value);
  if (document.getElementById('s_auto_delete_enabled').checked) {
    fd.append('auto_delete_enabled', '1');
  }

  try {
    const r = await fetch('inactivity_action.php', { method:'POST', body:fd });
    const d = await r.json();
    alert(d.message);
    if (d.success) location.reload();
  } catch(e) {
    alert('Network error saving settings.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save me-2"></i>Save Settings';
  }
});

// ── Inactivity Preview ────────────────────────────────────────
document.getElementById('runPreviewBtn')?.addEventListener('click', async function() {
  const btn = this;
  btn.disabled = true;
  document.getElementById('previewLoading').classList.remove('d-none');
  document.getElementById('previewResults').classList.add('d-none');

  try {
    const fd = new FormData();
    fd.append('action', 'preview');
    const r = await fetch('inactivity_action.php', { method:'POST', body:fd });
    const d = await r.json();

    document.getElementById('previewLoading').classList.add('d-none');
    document.getElementById('previewResults').classList.remove('d-none');

    const tbody = document.getElementById('previewTbody');
    tbody.innerHTML = '';

    if (!d.success || !d.creators || d.creators.length === 0) {
      document.getElementById('previewEmpty').classList.remove('d-none');
      return;
    }

    document.getElementById('previewEmpty').classList.add('d-none');

    d.creators.forEach(c => {
      const statusMap = {
        warn_pending: '<span class="badge bg-warning text-dark">Warn Pending</span>',
        warned:       '<span class="badge bg-orange text-dark" style="background:#f97316">Warned</span>',
        due_deletion: '<span class="badge bg-danger">Due Deletion</span>',
      };
      const actionsHtml = c.status === 'due_deletion'
        ? `<button class="btn btn-sm btn-danger preview-delete-btn" data-id="${c.id}" data-name="${escHtml(c.name)}">Delete Now</button>`
        : c.status === 'warned'
          ? `<button class="btn btn-sm btn-outline-success preview-cancel-btn" data-id="${c.id}" data-name="${escHtml(c.name)}">Cancel Deletion</button>`
          : `<button class="btn btn-sm btn-warning preview-warn-btn" data-id="${c.id}" data-name="${escHtml(c.name)}">Send Warning</button>`;

      tbody.innerHTML += `
        <tr>
          <td><strong>${escHtml(c.name)}</strong></td>
          <td>${escHtml(c.email)}</td>
          <td>${escHtml(c.phone || '-')}</td>
          <td><span class="badge bg-secondary">${c.days_inactive}d</span></td>
          <td class="small">${c.last_activity ? new Date(c.last_activity).toLocaleDateString('en-GB') : '-'}</td>
          <td>${statusMap[c.status] || c.status}</td>
          <td class="small">${c.scheduled_delete ? new Date(c.scheduled_delete).toLocaleDateString('en-GB') : '-'}</td>
          <td>${actionsHtml}</td>
        </tr>`;
    });
  } catch(e) {
    alert('Error running preview.');
    document.getElementById('previewLoading').classList.add('d-none');
  } finally {
    btn.disabled = false;
  }
});

// ── Preview table button delegation ─────────────────────────
document.getElementById('previewTbody')?.addEventListener('click', async function(e) {
  const warnBtn   = e.target.closest('.preview-warn-btn');
  const cancelBtn = e.target.closest('.preview-cancel-btn');
  const delBtn    = e.target.closest('.preview-delete-btn');

  if (warnBtn)   await doAction('force_warn',       warnBtn.dataset.id,   warnBtn.dataset.name,   warnBtn);
  if (cancelBtn) await doAction('cancel_deletion',  cancelBtn.dataset.id, cancelBtn.dataset.name, cancelBtn);
  if (delBtn) {
    if (!confirm(`Permanently delete ${delBtn.dataset.name}?`)) return;
    if (!confirm('FINAL: This cannot be undone.')) return;
    await doAction('force_delete', delBtn.dataset.id, delBtn.dataset.name, delBtn);
  }
});

// ── Main table: Force Warn button ────────────────────────────
let currentWarnId = null;
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.force-warn-btn');
  if (!btn) return;
  currentWarnId = btn.dataset.id;
  document.getElementById('warnCreatorName').textContent = btn.dataset.name;
  new bootstrap.Modal(document.getElementById('forceWarnModal')).show();
});
document.getElementById('confirmWarn')?.addEventListener('click', async function() {
  if (!currentWarnId) return;
  await doAction('force_warn', currentWarnId, '', this);
  bootstrap.Modal.getInstance(document.getElementById('forceWarnModal'))?.hide();
  location.reload();
});

// ── Main table: Cancel deletion ──────────────────────────────
document.addEventListener('click', async function(e) {
  const btn = e.target.closest('.cancel-deletion-btn');
  if (!btn) return;
  if (!confirm(`Cancel scheduled deletion for ${btn.dataset.name}?`)) return;
  await doAction('cancel_deletion', btn.dataset.id, btn.dataset.name, btn);
  location.reload();
});

// ── Main table: Delete ───────────────────────────────────────
let currentDeleteId = null;
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.delete-creator-btn');
  if (!btn) return;
  currentDeleteId = btn.dataset.id;
  document.getElementById('deleteCreatorName').textContent = btn.dataset.name;
  new bootstrap.Modal(document.getElementById('deleteModal')).show();
});
document.getElementById('confirmDelete')?.addEventListener('click', async function() {
  if (!currentDeleteId) return;
  if (!confirm('This will permanently delete this creator and ALL their data. Continue?')) return;
  if (!confirm('FINAL WARNING: Irreversible. Are you 100% sure?')) return;

  const btn = this;
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting…';
  const fd = new FormData();
  fd.append('action', 'force_delete');
  fd.append('creator_id', currentDeleteId);
  try {
    const r = await fetch('inactivity_action.php', { method:'POST', body:fd });
    const d = await r.json();
    alert(d.success ? 'Creator permanently deleted.' : 'Error: ' + (d.message || 'Unknown'));
    if (d.success) { bootstrap.Modal.getInstance(document.getElementById('deleteModal'))?.hide(); location.reload(); }
  } catch(err) { alert('Network error.'); }
  finally { btn.disabled=false; btn.innerHTML='Delete Permanently'; }
});

// ── Shared action helper ─────────────────────────────────────
async function doAction(action, id, name, btn) {
  if (btn) { btn.disabled=true; }
  const fd = new FormData();
  fd.append('action', action);
  fd.append('creator_id', id);
  try {
    const r = await fetch('inactivity_action.php', { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) { alert(d.message || 'Done.'); }
    else { alert('Error: ' + (d.message || 'Unknown error')); }
  } catch(e) { alert('Network error.'); }
  finally { if (btn) btn.disabled=false; }
}

function escHtml(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}
</script>
</body>
</html>
