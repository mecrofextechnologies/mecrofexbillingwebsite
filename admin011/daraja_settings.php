<?php
/* Admin01 → Settings → Daraja API Credentials.
   Secrets are write-only from this page: they are encrypted into
   payment_gateway_settings and never rendered back (not even masked
   values), never logged, and never returned in a response. */
if (session_status() === PHP_SESSION_NONE) session_start();
include_once '../msanii/includes/connect.php';
require_once '../msanii/includes/daraja.php';

if (empty($_SESSION['admin_id']) || !in_array((int)($_SESSION['user_role'] ?? 0), [1, 2], true)) {
    echo "<div class='alert alert-danger'>You are not authorised to view this page.</div>";
    return;
}
$canEdit = (int)$_SESSION['user_role'] === 1;  // System Administrator only

if (empty($_SESSION['kc_admin_csrf'])) $_SESSION['kc_admin_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['kc_admin_csrf'];

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['daraja_action'])) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        [$flash, $flashType] = ['Your session expired. Reload the page and try again.', 'danger'];
    } elseif (!$canEdit) {
        [$flash, $flashType] = ['Only a System Administrator can change payment credentials.', 'danger'];
    } elseif ($_POST['daraja_action'] === 'save') {
        $errors = [];
        $save   = [];

        foreach (['shortcode', 'b2c_shortcode'] as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v !== '' && !preg_match('/^\d{5,7}$/', $v)) $errors[] = KC_DARAJA_FIELDS[$k]['label'] . ' must be 5–7 digits.';
            $save[$k] = $v;
        }
        if ($save['shortcode'] === '') $errors[] = 'Shortcode / Paybill is required.';

        $init = trim((string)($_POST['initiator_name'] ?? ''));
        if ($init !== '' && !preg_match('/^[A-Za-z0-9_.\- ]{2,64}$/', $init)) $errors[] = 'Initiator Name contains invalid characters.';
        $save['initiator_name'] = $init;

        $env = $_POST['environment'] ?? '';
        if (!in_array($env, ['sandbox', 'production'], true)) $errors[] = 'Choose an environment.';
        $save['environment'] = $env;

        $url = rtrim(trim((string)($_POST['callback_base_url'] ?? '')), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_QUERY)) {
            $errors[] = 'Callback / Result Base URL must be an https:// URL with no query string.';
        }
        $save['callback_base_url'] = $url;

        // Secrets: blank means "keep the current value".
        foreach (['consumer_key', 'consumer_secret', 'passkey', 'security_credential'] as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v === '') continue;
            if (strlen($v) > 2048 || preg_match('/\s/', $v)) { $errors[] = KC_DARAJA_FIELDS[$k]['label'] . ' looks invalid.'; continue; }
            $save[$k] = $v;
        }

        if ($errors) {
            [$flash, $flashType] = [implode('<br>', array_map('htmlspecialchars', $errors)), 'danger'];
        } else {
            try {
                kc_daraja_save_settings($pdo, $save, (int)$_SESSION['admin_id']);
                kc_daraja_config($pdo, true);
                kc_payment_log('admin_settings', 'Daraja settings updated by admin #' . (int)$_SESSION['admin_id'] . ' fields: ' . implode(',', array_keys($save)));
                [$flash, $flashType] = ['Daraja settings saved.', 'success'];
            } catch (Throwable $e) {
                kc_payment_log('admin_settings', 'Daraja settings save failed: ' . get_class($e));
                [$flash, $flashType] = ['Could not save settings. Please try again.', 'danger'];
            }
        }
    } elseif ($_POST['daraja_action'] === 'test') {
        $cfg = kc_daraja_config($pdo, true);
        $err = null;
        $ok  = kc_daraja_access_token($cfg, $err) !== null;
        kc_payment_log('admin_settings', 'Daraja connection test by admin #' . (int)$_SESSION['admin_id'] . ': ' . ($ok ? 'OK' : 'FAILED'));
        [$flash, $flashType] = $ok
            ? ['Connected: Safaricom accepted the Consumer Key/Secret for the ' . htmlspecialchars($cfg['environment']) . ' environment.', 'success']
            : ['Connection failed: ' . htmlspecialchars((string)$err) . '.', 'danger'];
    }
}

$cfg     = kc_daraja_config($pdo, true);
$sources = kc_daraja_config_sources($pdo);
$stkMissing = kc_daraja_missing($cfg, 'stk');
$b2cMissing = kc_daraja_missing($cfg, 'b2c');
$isSet = fn(string $k) => trim((string)$cfg[$k]) !== '';

$stuck = 0;
try {
    $stuck = (int)$pdo->query("SELECT COUNT(*) FROM withdrawal_requests WHERE payout_method = 'b2c' AND status = 'processing' AND requested_at < NOW() - INTERVAL 30 MINUTE")->fetchColumn();
} catch (Throwable $e) { /* column not created yet — no automatic withdrawals so far */ }

$ro = $canEdit ? '' : 'disabled';
$secretInput = function (string $k) use ($isSet, $ro) {
    $ph = $isSet($k) ? '•••••••••••••••• (set — leave blank to keep)' : 'Not set';
    return "<input type='password' class='form-control' name='{$k}' id='f_{$k}' value='' placeholder='" . htmlspecialchars($ph, ENT_QUOTES) . "' autocomplete='new-password' spellcheck='false' {$ro}>";
};
?>
<div class="col-lg-12">
  <?php if ($flash): ?>
    <div class="alert alert-<?= $flashType ?>"><?= $flash ?></div>
  <?php endif; ?>

  <?php if ($stuck > 0): ?>
    <div class="alert alert-warning">
      <?= $stuck ?> automatic withdrawal<?= $stuck > 1 ? 's have' : ' has' ?> been awaiting M-Pesa confirmation for over 30 minutes.
      <a href="./index.php?page=approve_withdrawal&status=processing">Reconcile now</a>.
    </div>
  <?php endif; ?>

  <div class="card card-outline card-primary">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-key mr-2"></i>Daraja API Credentials</h3></div>
    <div class="card-body">
      <div class="row mb-3">
        <div class="col-md-6">
          <strong>Ticket payments (STK push, Paybill <?= htmlspecialchars($cfg['shortcode']) ?>):</strong>
          <?= $stkMissing ? "<span class='badge badge-danger'>Not ready — missing " . htmlspecialchars(implode(', ', array_map(fn($k) => KC_DARAJA_FIELDS[$k]['label'], $stkMissing))) . "</span>"
                          : "<span class='badge badge-success'>Configured</span>" ?>
        </div>
        <div class="col-md-6">
          <strong>Creator withdrawals (B2C):</strong>
          <?= $b2cMissing ? "<span class='badge badge-danger'>Not ready — missing " . htmlspecialchars(implode(', ', array_map(fn($k) => KC_DARAJA_FIELDS[$k]['label'], $b2cMissing))) . "</span>"
                          : "<span class='badge badge-success'>Configured</span>" ?>
        </div>
      </div>

      <?php if (!$canEdit): ?>
        <div class="alert alert-info">Read-only: only a System Administrator can change these credentials.</div>
      <?php endif; ?>

      <form method="post" action="./index.php?page=daraja_settings" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="daraja_action" value="save">

        <div class="row">
          <div class="col-md-6 form-group">
            <label for="f_consumer_key">Consumer Key</label>
            <?= $secretInput('consumer_key') ?>
            <small class="text-muted">Source: <?= htmlspecialchars($sources['consumer_key']) ?></small>
          </div>
          <div class="col-md-6 form-group">
            <label for="f_consumer_secret">Consumer Secret</label>
            <?= $secretInput('consumer_secret') ?>
            <small class="text-muted">Source: <?= htmlspecialchars($sources['consumer_secret']) ?></small>
          </div>
          <div class="col-md-6 form-group">
            <label for="f_passkey">Passkey <small class="text-muted">(STK push)</small></label>
            <?= $secretInput('passkey') ?>
            <small class="text-muted">Source: <?= htmlspecialchars($sources['passkey']) ?></small>
          </div>
          <div class="col-md-6 form-group">
            <label for="f_shortcode">Shortcode / Paybill</label>
            <input type="text" class="form-control" name="shortcode" id="f_shortcode" inputmode="numeric"
                   value="<?= htmlspecialchars($cfg['shortcode']) ?>" required <?= $ro ?>>
            <small class="text-muted">Receives ticket payments (CustomerPayBillOnline). Source: <?= htmlspecialchars($sources['shortcode']) ?></small>
          </div>
        </div>

        <h5 class="mt-3">Creator withdrawals (B2C)</h5>
        <p class="text-muted small mb-2">
          Payouts use the Daraja B2C API, which needs B2C to be enabled on the shortcode and an API initiator
          created in the M-Pesa org portal. The Security Credential is the initiator password encrypted with
          Safaricom's public certificate (generate it on the Daraja portal).
        </p>
        <div class="row">
          <div class="col-md-6 form-group">
            <label for="f_b2c_shortcode">B2C Shortcode <small class="text-muted">(blank = same as Paybill)</small></label>
            <input type="text" class="form-control" name="b2c_shortcode" id="f_b2c_shortcode" inputmode="numeric"
                   value="<?= htmlspecialchars($sources['b2c_shortcode'] === 'Not set' ? '' : $cfg['b2c_shortcode']) ?>" <?= $ro ?>>
          </div>
          <div class="col-md-6 form-group">
            <label for="f_initiator_name">Initiator Name</label>
            <input type="text" class="form-control" name="initiator_name" id="f_initiator_name"
                   value="<?= htmlspecialchars($cfg['initiator_name']) ?>" autocomplete="off" <?= $ro ?>>
          </div>
          <div class="col-md-12 form-group">
            <label for="f_security_credential">Security Credential</label>
            <?= $secretInput('security_credential') ?>
            <small class="text-muted">Source: <?= htmlspecialchars($sources['security_credential']) ?></small>
          </div>
        </div>

        <h5 class="mt-3">Environment &amp; callbacks</h5>
        <div class="row">
          <div class="col-md-4 form-group">
            <label for="f_environment">Environment</label>
            <select class="form-control" name="environment" id="f_environment" <?= $ro ?>>
              <option value="production" <?= $cfg['environment'] === 'production' ? 'selected' : '' ?>>Production</option>
              <option value="sandbox"    <?= $cfg['environment'] === 'sandbox'    ? 'selected' : '' ?>>Sandbox</option>
            </select>
          </div>
          <div class="col-md-8 form-group">
            <label for="f_callback_base_url">Callback / Result Base URL</label>
            <input type="url" class="form-control" name="callback_base_url" id="f_callback_base_url"
                   value="<?= htmlspecialchars($cfg['callback_base_url']) ?>" required <?= $ro ?>>
          </div>
        </div>
        <table class="table table-sm table-bordered small">
          <tr><th style="width:230px">STK callback (tickets)</th><td><code><?= htmlspecialchars($cfg['callback_base_url']) ?>/ticket_callback.php?key=••••</code></td></tr>
          <tr><th>B2C ResultURL</th><td><code><?= htmlspecialchars($cfg['callback_base_url']) ?>/b2c_result.php?key=••••</code></td></tr>
          <tr><th>B2C QueueTimeOutURL</th><td><code><?= htmlspecialchars($cfg['callback_base_url']) ?>/b2c_timeout.php?key=••••</code></td></tr>
        </table>
        <p class="small text-muted">The <code>key</code> is the webhook secret added automatically to every callback URL.</p>

        <?php if ($canEdit): ?>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save Credentials</button>
        <?php endif; ?>
      </form>

      <?php if ($canEdit): ?>
      <form method="post" action="./index.php?page=daraja_settings" class="d-inline">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="daraja_action" value="test">
        <button type="submit" class="btn btn-outline-secondary mt-2"><i class="fas fa-plug mr-1"></i> Test Connection</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
