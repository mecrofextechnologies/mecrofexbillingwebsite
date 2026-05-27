<?php
/**
 * cron_inactivity.php
 * ─────────────────────────────────────────────────────────────
 * Runs daily via cron. Handles TWO passes:
 *   Pass 1 — Send inactivity warnings (email + SMS) to creators
 *            who have been inactive for (inactivity_days - grace_period_days) days
 *            and have NOT yet been warned.
 *   Pass 2 — Permanently delete creators whose scheduled_delete_date has passed
 *            and auto_delete_enabled = 1.
 *
 * Cron entry (runs every day at 06:00):
 *   0 6 * * * /usr/bin/php /var/www/html/admin/cron_inactivity.php >> /var/log/inactivity_cron.log 2>&1
 *
 * Make sure this file is NOT web-accessible (block via .htaccess or place outside web root).
 */

// ── CLI-only guard ────────────────────────────────────────────
// Remove the lines below if you want to allow web-triggered runs (not recommended for production)
// if (php_sapi_name() !== 'cli') {
//     http_response_code(403);
//     exit('CLI only');
// }

define('CRON_START', microtime(true));
define('PLATFORM_NAME', 'Keychele');

// ── Bootstrap ─────────────────────────────────────────────────
$baseDir = dirname(__DIR__); // project root (one level above admin/)
require_once $baseDir . '/vendor/autoload.php';
require_once $baseDir . '/msanii/includes/connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Logging helper ────────────────────────────────────────────
function clog(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    error_log($line);
}

clog('═══════ Inactivity Cron Started ═══════');

// ── Load settings ─────────────────────────────────────────────
$settings = $pdo->query("SELECT * FROM inactivity_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$settings) {
    $settings = [
        'inactivity_days'         => 30,
        'grace_period_days'       => 7,
        'auto_delete_enabled'     => 1,
        'talksasa_api_key'        => '',
        'talksasa_sender_id'      => 'KEYCHELE',
        'smtp_from_email'         => 'noreply@keychele.com',
        'smtp_from_name'          => PLATFORM_NAME,
        'warning_email_subject'   => 'Action Required: Your account will be deleted',
    ];
    clog('WARN: No settings row found — using defaults');
}

$inactivityDays = (int)$settings['inactivity_days'];
$graceDays      = (int)$settings['grace_period_days'];
$warnAfterDays  = $inactivityDays - $graceDays;
$autoDeleteOn   = (bool)$settings['auto_delete_enabled'];
$platformName   = $settings['smtp_from_name'] ?? PLATFORM_NAME;

clog("Settings: inactivity={$inactivityDays}d, grace={$graceDays}d, warn_at={$warnAfterDays}d, auto_delete=" . ($autoDeleteOn?'YES':'NO'));

// ════════════════════════════════════════════════════════════
// PASS 1 — SEND WARNINGS
// ════════════════════════════════════════════════════════════
clog('--- Pass 1: Warning Scan ---');

$warnRows = $pdo->query("
    SELECT id, full_name, email, phone, last_login,
           inactivity_warning_sent_at, scheduled_delete_date
    FROM creators
    WHERE inactivity_warning_sent_at IS NULL
      AND scheduled_delete_date IS NULL
      AND (
            last_login IS NULL
         OR last_login = '0000-00-00 00:00:00'
         OR DATEDIFF(NOW(), last_login) >= {$warnAfterDays}
      )
")->fetchAll(PDO::FETCH_ASSOC);

clog('Creators qualifying for warning: ' . count($warnRows));

$warned = 0;
$warnFailed = 0;

foreach ($warnRows as $creator) {
    $ll           = $creator['last_login'];
    $daysInactive = ($ll && $ll !== '0000-00-00 00:00:00')
        ? max(1, (int)floor((time() - strtotime($ll)) / 86400))
        : $inactivityDays;

    $deleteDate   = date('Y-m-d', strtotime("+{$graceDays} days"));

    clog("  Sending warning → #{$creator['id']} {$creator['full_name']} ({$daysInactive}d inactive)");

    // Send email
    $emailResult = sendEmail(
        $creator['email'],
        $creator['full_name'],
        $settings['warning_email_subject'],
        buildWarningEmail($creator['full_name'], $daysInactive, $graceDays, $platformName),
        $settings
    );
    clog("    Email: " . ($emailResult['success'] ? 'OK' : 'FAIL — ' . $emailResult['detail']));

    // Send SMS
    $smsResult = sendSMS(
        $creator['phone'] ?? '',
        buildWarningSMS($creator['full_name'], $daysInactive, $graceDays, $platformName),
        $settings
    );
    clog("    SMS:   " . ($smsResult['success'] ? 'OK' : 'FAIL — ' . $smsResult['detail']));

    // Update DB
    $pdo->prepare("
        UPDATE creators
        SET inactivity_warning_sent_at = NOW(),
            scheduled_delete_date      = ?
        WHERE id = ?
    ")->execute([$deleteDate, $creator['id']]);

    // Log
    $creator['scheduled_delete_date'] = $deleteDate;
    logToDb($pdo, $creator, 'warning_sent',
        $emailResult['success'] ? 'sent' : 'failed',
        $smsResult['success']   ? 'sent' : 'failed',
        'cron');

    ($emailResult['success'] && $smsResult['success']) ? $warned++ : $warnFailed++;
}

clog("Pass 1 done: {$warned} warned, {$warnFailed} with partial/full failure");

// ════════════════════════════════════════════════════════════
// PASS 2 — AUTO DELETE
// ════════════════════════════════════════════════════════════
clog('--- Pass 2: Auto-Delete Scan ---');

if (!$autoDeleteOn) {
    clog('Auto-delete DISABLED — skipping.');
} else {
    $deleteRows = $pdo->query("
        SELECT * FROM creators
        WHERE scheduled_delete_date IS NOT NULL
          AND scheduled_delete_date <= CURDATE()
    ")->fetchAll(PDO::FETCH_ASSOC);

    clog('Creators due deletion: ' . count($deleteRows));

    $deleted = 0;
    $deleteFailed = 0;

    foreach ($deleteRows as $creator) {
        clog("  Deleting → #{$creator['id']} {$creator['full_name']} (due {$creator['scheduled_delete_date']})");

        // Log BEFORE deletion
        logToDb($pdo, $creator, 'deleted', 'skipped', 'skipped', 'cron');

        // Delete related data
        $relatedTables = [
            'tickets'         => 'creator_id',
            'events'          => 'creator_id',
            'ticket_sales'    => 'creator_id',
            'withdrawals'     => 'creator_id',
            'transactions'    => 'creator_id',
            'creator_balance' => 'creator_id',
            'creator_surveys' => 'creator_id',
            'support_tickets' => 'creator_id',
            'creator_pages'   => 'creator_id',
        ];
        foreach ($relatedTables as $table => $col) {
            try {
                $pdo->prepare("DELETE FROM {$table} WHERE {$col} = ?")->execute([$creator['id']]);
            } catch (PDOException $e) {
                error_log("cron delete related {$table}: " . $e->getMessage());
            }
        }

        try {
            $pdo->prepare("DELETE FROM creators WHERE id = ?")->execute([$creator['id']]);
            clog("    Deleted OK");
            $deleted++;
        } catch (PDOException $e) {
            clog("    FAILED: " . $e->getMessage());
            $deleteFailed++;
        }
    }

    clog("Pass 2 done: {$deleted} deleted, {$deleteFailed} failed");
}

$elapsed = round(microtime(true) - CRON_START, 2);
clog("═══════ Cron Finished in {$elapsed}s ═══════");

// ════════════════════════════════════════════════════════════
// HELPERS (duplicated from inactivity_action.php so this file is standalone)
// ════════════════════════════════════════════════════════════

function sendEmail(string $to, string $toName, string $subject, string $htmlBody, array $settings): array {
    $fromEmail = $settings['smtp_from_email'] ?? 'notifications@keychele.co.ke';
    $fromName  = $settings['smtp_from_name']  ?? PLATFORM_NAME;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host        = 'mail.keychele.co.ke';
        $mail->SMTPAuth    = true;
        $mail->Username    = 'notifications@keychele.co.ke';
        $mail->Password    = 'King20241064.';
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = 587;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        $mail->send();
        return ['success' => true, 'detail' => 'OK'];
    } catch (Exception $e) {
        return ['success' => false, 'detail' => 'PHPMailer: ' . $mail->ErrorInfo];
    }
}

function sendSMS(string $phone, string $message, array $settings): array {
    global $pdo;

    try {
        $cfg = $pdo->query("SELECT * FROM admin_sms_settings WHERE provider = 'talksasa' LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['success' => false, 'detail' => 'DB error: ' . $e->getMessage()];
    }

    if (!$cfg)                       return ['success' => false, 'detail' => "No talksasa row in admin_sms_settings"];
    $token      = trim($cfg['api_token']   ?? '');
    $senderName = trim($cfg['sender_name'] ?? '');
    if (!$token)      return ['success' => false, 'detail' => 'api_token empty'];
    if (!$senderName) return ['success' => false, 'detail' => 'sender_name empty'];

    $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
    if (substr($phone, 0, 1) === '0')       $phone = '254' . substr($phone, 1);
    elseif (substr($phone, 0, 4) === '+254') $phone = substr($phone, 1);

    $ch = curl_init('https://bulksms.talksasa.com/api/v3/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['recipient' => $phone, 'sender_id' => $senderName, 'type' => 'plain', 'message' => $message]),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw      = curl_exec($ch);
    $err      = curl_error($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return ['success' => false, 'detail' => 'cURL: ' . $err];
    $data = json_decode($raw, true);
    $ok   = isset($data['status']) && strtolower((string)$data['status']) === 'success';
    return ['success' => $ok, 'detail' => $ok ? 'OK' : "HTTP {$code}: " . ($data['message'] ?? $raw)];
}

function buildWarningEmail(string $name, int $daysInactive, int $graceDays, string $platformName): string {
    $deleteDate = date('d F Y', strtotime("+{$graceDays} days"));
    return '<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account Inactivity Warning</title></head>
<body style="margin:0;padding:0;background:#f0f2f8;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f8;padding:40px 20px;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(13,27,62,.1);">
  <tr><td style="background:linear-gradient(135deg,#0d1b3e,#2563eb);padding:32px 40px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:800;">⚠️ Account Activity Notice</h1>
    <p style="color:#bfdbfe;margin:8px 0 0;font-size:13px;">'.htmlspecialchars($platformName).'</p>
  </td></tr>
  <tr><td style="padding:36px 40px;">
    <p style="font-size:16px;font-weight:700;color:#0d1b3e;margin:0 0 16px;">Dear '.htmlspecialchars($name).',</p>
    <p style="font-size:14px;color:#4b5580;line-height:1.7;margin:0 0 16px;">
      Your account has been <strong style="color:#d97706;">dormant for '.$daysInactive.' day'.($daysInactive!==1?'s':'').'</strong>.
      Kindly log in or create activity to avoid auto-deletion.
    </p>
    <div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:18px 22px;margin:0 0 22px;">
      <p style="margin:0;font-size:13px;font-weight:700;color:#92400e;">
        ⏰ Your account is scheduled for permanent deletion on <span style="font-size:15px;">'.$deleteDate.'</span>.
      </p>
    </div>
    <p style="font-size:14px;color:#4b5580;line-height:1.7;margin:0 0 22px;">
      All your events, tickets, transactions, and account data will be <strong style="color:#dc2626;">permanently erased</strong> if no activity is detected.
    </p>
    <div style="text-align:center;margin:0 0 28px;">
      <a href="https://keychele.com/msanii/login.php"
         style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;
                padding:14px 36px;border-radius:10px;font-size:15px;font-weight:700;">
        Log In Now →
      </a>
    </div>
    <p style="font-size:12px;color:#8b95b8;line-height:1.6;margin:0;">This is an automated message — please do not reply directly to this email.</p>
  </td></tr>
  <tr><td style="background:#f7f8fc;border-top:1px solid #e4e8f0;padding:18px 40px;text-align:center;">
    <p style="margin:0;font-size:11px;color:#8b95b8;">© '.date('Y').' '.htmlspecialchars($platformName).'</p>
  </td></tr>
</table></td></tr></table>
</body></html>';
}

function buildWarningSMS(string $name, int $daysInactive, int $graceDays, string $platformName): string {
    $deleteDate = date('d M Y', strtotime("+{$graceDays} days"));
    return "Dear {$name}, your {$platformName} account has been dormant for {$daysInactive} day(s). "
         . "Kindly log in or create activity to avoid auto-deletion scheduled for {$deleteDate}. "
         . "Visit: keychele.com";
}

function logToDb(PDO $pdo, array $creator, string $action, string $emailStatus, string $smsStatus, string $actionedBy): void {
    try {
        $ll   = $creator['last_login'] ?? null;
        $days = ($ll && $ll !== '0000-00-00 00:00:00') ? (int)floor((time()-strtotime($ll))/86400) : null;
        $pdo->prepare("
            INSERT INTO inactivity_log
                (creator_id, creator_name, creator_email, action,
                 email_status, sms_status, last_activity_date,
                 inactivity_days_at_action, scheduled_delete_date, actioned_by, actioned_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $creator['id'], $creator['full_name'], $creator['email'], $action,
            $emailStatus, $smsStatus, $ll, $days,
            $creator['scheduled_delete_date'] ?? null, $actionedBy,
        ]);
    } catch (PDOException $e) {
        error_log('logToDb: ' . $e->getMessage());
    }
}