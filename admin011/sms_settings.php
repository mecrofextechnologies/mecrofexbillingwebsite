<?php
/* Admin01 → Settings → SMS Settings.
   Edits the app's existing Talksasa configuration in place — the same rows
   every current SMS sender reads — so no sender changes behaviour:
     • creatorwithdrawal_sms_settings — MecroGuard OTPs, withdrawal SMS
       (including the automatic "withdrawal successful" SMS)
     • admin_sms_settings — platform SMS (registration, broadcasts,
       reminders, inactivity, donation alerts, creator bulk SMS)
   The API token is write-only here: never rendered back into the page. */
if (session_status() === PHP_SESSION_NONE) session_start();
include_once '../msanii/includes/connect.php';
require_once '../msanii/includes/daraja.php';     // kc_payment_log / kc_mask_phone
require_once '../msanii/includes/talksasa.php';

if (empty($_SESSION['admin_id']) || !in_array((int)($_SESSION['user_role'] ?? 0), [1, 2], true)) {
    echo "<div class='alert alert-danger'>You are not authorised to view this page.</div>";
    return;
}

if (empty($_SESSION['kc_admin_csrf'])) $_SESSION['kc_admin_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['kc_admin_csrf'];

$gateways = [
    'creatorwithdrawal_sms_settings' => ['title' => 'Withdrawal & Payout SMS', 'desc' => 'MecroGuard withdrawal codes and withdrawal confirmation SMS.'],
    'admin_sms_settings'             => ['title' => 'Platform SMS',            'desc' => 'Registration, event reminders, broadcasts, inactivity notices, donation alerts and creator bulk SMS.'],
];
$editable = ['api_id' => 'API ID', 'api_token' => 'API Token', 'sender_name' => 'Sender ID', 'api_endpoint' => 'API URL'];

function kc_sms_table_columns(PDO $pdo, string $table): array {
    return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
}

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sms_action'])) {
    $table = (string)($_POST['gateway'] ?? '');
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        [$flash, $flashType] = ['Your session expired. Reload the page and try again.', 'danger'];
    } elseif (!isset($gateways[$table])) {
        [$flash, $flashType] = ['Unknown SMS gateway.', 'danger'];
    } elseif ($_POST['sms_action'] === 'save') {
        try {
            kc_talksasa_ensure_table($pdo, $table);
            $cols = kc_sms_table_columns($pdo, $table);
            $vals = [];
            $errors = [];
            foreach ($editable as $col => $label) {
                if (!in_array($col, $cols, true)) continue;
                $v = trim((string)($_POST[$col] ?? ''));
                if ($col === 'api_token' && $v === '') continue;   // blank = keep current token
                if ($col === 'sender_name' && !preg_match('/^[A-Za-z0-9 _\-]{2,11}$/', $v)) $errors[] = 'Sender ID must be 2–11 letters/digits.';
                if ($col === 'api_endpoint' && $v !== '' && (!filter_var($v, FILTER_VALIDATE_URL) || parse_url($v, PHP_URL_SCHEME) !== 'https')) $errors[] = 'API URL must be an https:// URL.';
                if ($col === 'api_endpoint' && $v === '') $v = KC_TALKSASA_DEFAULT_ENDPOINT;
                if (strlen($v) > 255) $errors[] = "$label is too long.";
                $vals[$col] = $v;
            }
            if ($errors) {
                [$flash, $flashType] = [implode('<br>', array_map('htmlspecialchars', array_unique($errors))), 'danger'];
            } else {
                $existing = $pdo->query("SELECT id FROM `$table` WHERE provider = 'talksasa' LIMIT 1")->fetchColumn();
                if ($existing) {
                    if ($vals) {
                        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($vals)));
                        $pdo->prepare("UPDATE `$table` SET $set WHERE id = ?")->execute([...array_values($vals), $existing]);
                    }
                } else {
                    if (in_array('api_token', $cols, true) && empty($vals['api_token'])) throw new RuntimeException('An API token is required for a new SMS configuration.');
                    $vals = ['provider' => 'talksasa'] + $vals;
                    $pdo->prepare("INSERT INTO `$table` (`" . implode('`,`', array_keys($vals)) . "`) VALUES (" . rtrim(str_repeat('?,', count($vals)), ',') . ")")
                        ->execute(array_values($vals));
                }
                kc_payment_log('admin_settings', "SMS settings ($table) updated by admin #" . (int)$_SESSION['admin_id'] . ' fields: ' . implode(',', array_keys($vals)));
                [$flash, $flashType] = [htmlspecialchars($gateways[$table]['title']) . ' settings saved.', 'success'];
            }
        } catch (RuntimeException $e) {
            [$flash, $flashType] = [htmlspecialchars($e->getMessage()), 'danger'];
        } catch (Throwable $e) {
            kc_payment_log('admin_settings', "SMS settings ($table) save failed: " . get_class($e));
            [$flash, $flashType] = ['Could not save SMS settings. Please try again.', 'danger'];
        }
    } elseif ($_POST['sms_action'] === 'test') {
        $to = kc_normalize_phone((string)($_POST['test_phone'] ?? ''));
        if (!$to) {
            [$flash, $flashType] = ['Enter a valid Kenyan phone number for the test SMS.', 'danger'];
        } else {
            $res = kc_talksasa_send($pdo, $to, 'Keychele test SMS from Admin Settings (' . $gateways[$table]['title'] . ').', $table);
            kc_payment_log('admin_settings', "SMS test ($table) by admin #" . (int)$_SESSION['admin_id'] . ' to ' . kc_mask_phone($to) . ': ' . ($res['ok'] ? 'OK' : $res['reason']));
            [$flash, $flashType] = $res['ok']
                ? ['Test SMS accepted by Talksasa for ' . htmlspecialchars(kc_mask_phone($to)) . '.', 'success']
                : ['Test SMS failed: ' . htmlspecialchars($res['reason']), 'danger'];
        }
    }
}

// SMS delivery problems on automatic withdrawals, for troubleshooting.
$smsFailures = [];
try {
    $smsFailures = $pdo->query("SELECT id, reference, sms_error, completed_at FROM withdrawal_requests WHERE payout_method = 'b2c' AND sms_status = 'failed' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* columns not created yet */ }
?>
<div class="col-lg-12">
  <?php if ($flash): ?>
    <div class="alert alert-<?= $flashType ?>"><?= $flash ?></div>
  <?php endif; ?>

  <?php foreach ($gateways as $table => $g):
      $row  = kc_talksasa_settings($pdo, $table) ?: [];
      $cols = [];
      try { $cols = kc_sms_table_columns($pdo, $table); } catch (Throwable $e) { /* table not created yet */ }
      $tokenSet = trim((string)($row['api_token'] ?? '')) !== '';
  ?>
  <div class="card card-outline card-primary">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-sms mr-2"></i><?= htmlspecialchars($g['title']) ?> — Talksasa</h3>
      <div class="card-tools">
        <?= $row && $tokenSet ? "<span class='badge badge-success'>Configured</span>" : "<span class='badge badge-danger'>Not configured</span>" ?>
      </div>
    </div>
    <div class="card-body">
      <p class="text-muted small"><?= htmlspecialchars($g['desc']) ?></p>
      <form method="post" action="./index.php?page=sms_settings" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="sms_action" value="save">
        <input type="hidden" name="gateway" value="<?= htmlspecialchars($table) ?>">
        <div class="row">
          <?php if (!$cols || in_array('api_id', $cols, true)): ?>
          <div class="col-md-3 form-group">
            <label>API ID</label>
            <input type="text" class="form-control" name="api_id" value="<?= htmlspecialchars((string)($row['api_id'] ?? '')) ?>">
          </div>
          <?php endif; ?>
          <div class="col-md-5 form-group">
            <label>API Token</label>
            <input type="password" class="form-control" name="api_token" value="" autocomplete="new-password" spellcheck="false"
                   placeholder="<?= $tokenSet ? '•••••••••••••••• (set — leave blank to keep)' : 'Not set' ?>">
          </div>
          <div class="col-md-4 form-group">
            <label>Sender ID</label>
            <input type="text" class="form-control" name="sender_name" maxlength="11" required
                   value="<?= htmlspecialchars((string)($row['sender_name'] ?? 'KEYCHELE')) ?>">
          </div>
          <?php if (!$cols || in_array('api_endpoint', $cols, true)): ?>
          <div class="col-md-12 form-group">
            <label>API URL</label>
            <input type="url" class="form-control" name="api_endpoint"
                   value="<?= htmlspecialchars((string)($row['api_endpoint'] ?? KC_TALKSASA_DEFAULT_ENDPOINT)) ?>">
          </div>
          <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save</button>
      </form>

      <form method="post" action="./index.php?page=sms_settings" class="form-inline mt-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="sms_action" value="test">
        <input type="hidden" name="gateway" value="<?= htmlspecialchars($table) ?>">
        <input type="tel" class="form-control mr-2" name="test_phone" placeholder="07XXXXXXXX" required>
        <button type="submit" class="btn btn-outline-secondary"><i class="fas fa-paper-plane mr-1"></i> Send Test SMS</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>

  <p class="small text-muted">
    The Talksasa key/sender fields under <a href="./index.php?page=all_creators&tab=inactivity">Creators → Inactivity settings</a>
    are separate legacy fields; the inactivity cron sends through the Platform SMS gateway above.
  </p>

  <?php if ($smsFailures): ?>
  <div class="card card-outline card-warning">
    <div class="card-header"><h3 class="card-title">Recent withdrawal SMS failures</h3></div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <thead><tr><th>#</th><th>Reference</th><th>Completed</th><th>Error</th></tr></thead>
        <tbody>
        <?php foreach ($smsFailures as $f): ?>
          <tr>
            <td><?= (int)$f['id'] ?></td>
            <td><code><?= htmlspecialchars((string)$f['reference']) ?></code></td>
            <td><?= htmlspecialchars((string)$f['completed_at']) ?></td>
            <td><?= htmlspecialchars((string)$f['sms_error']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
