<?php
/**
 * inactivity_action.php
 * Handles all AJAX actions for the creators inactivity system.
 * Place this file in the same directory as all_creators.php (the admin panel folder).
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

include '../msanii/includes/connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// ── Auth guard ──────────────────────────────────────────────
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action     = trim($_POST['action'] ?? '');
$creator_id = (int)($_POST['creator_id'] ?? 0);

// ── Load settings ───────────────────────────────────────────
function getSettings(PDO $pdo): array {
    $s = $pdo->query("SELECT * FROM inactivity_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$s) {
        $s = [
            'inactivity_days'       => 30,
            'grace_period_days'     => 7,
            'auto_delete_enabled'   => 1,
            'talksasa_api_key'      => '',
            'talksasa_sender_id'    => 'KEYCHELE',
            'smtp_from_email'       => 'noreply@keychele.com',
            'smtp_from_name'        => 'Keychele Platform',
            'warning_email_subject' => 'Action Required: Your account will be deleted',
        ];
    }
    return $s;
}

// ── Email sender via PHPMailer (keychele.co.ke SMTP) ────────
function sendEmail(string $to, string $toName, string $subject, string $htmlBody, array $settings): array {
    $fromEmail = $settings['smtp_from_email'] ?? 'notifications@keychele.co.ke';
    $fromName  = $settings['smtp_from_name']  ?? 'Keychele Platform';

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
        return ['success' => true, 'detail' => 'Sent via PHPMailer SMTP'];
    } catch (Exception $e) {
        return ['success' => false, 'detail' => 'PHPMailer error: ' . $mail->ErrorInfo];
    }
}

// ── SMS sender via Talk Sasa (Bearer token from admin_sms_settings) ──
function sendSMS(string $phone, string $message, array $settings): array {
    global $pdo;

    try {
        $cfg = $pdo->query("SELECT * FROM admin_sms_settings WHERE provider = 'talksasa' LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['success' => false, 'detail' => 'DB error fetching SMS config: ' . $e->getMessage()];
    }

    if (!$cfg) {
        return ['success' => false, 'detail' => "SMS config not found — add a row with provider='talksasa' in admin_sms_settings"];
    }

    $token      = trim($cfg['api_token']   ?? '');
    $senderName = trim($cfg['sender_name'] ?? '');

    if (!$token)      return ['success' => false, 'detail' => 'SMS config incomplete — api_token is empty'];
    if (!$senderName) return ['success' => false, 'detail' => 'SMS config incomplete — sender_name is empty'];

    // Normalise phone to 254XXXXXXXXX
    $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
    if (substr($phone, 0, 1) === '0')       $phone = '254' . substr($phone, 1);
    elseif (substr($phone, 0, 4) === '+254') $phone = substr($phone, 1);

    $ch = curl_init('https://bulksms.talksasa.com/api/v3/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'recipient'  => $phone,
            'sender_id'  => $senderName,
            'type'       => 'plain',
            'message'    => $message,
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $raw      = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) return ['success' => false, 'detail' => 'cURL error: ' . $curlErr];

    $data = json_decode($raw, true);
    $ok   = isset($data['status']) && strtolower((string)$data['status']) === 'success';
    return [
        'success' => $ok,
        'detail'  => $ok ? 'SMS sent' : 'HTTP ' . $httpCode . ' — ' . ($data['message'] ?? $raw),
    ];
}

// ── Warning email HTML body ──────────────────────────────────
function buildWarningEmail(string $name, int $daysInactive, int $graceDays, string $platformName): string {
    $deleteDate = date('d F Y', strtotime("+{$graceDays} days"));
    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Account Inactivity Warning</title>
</head>
<body style="margin:0;padding:0;background:#f0f2f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f8;padding:40px 20px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(13,27,62,.1);">
        <tr>
          <td style="background:linear-gradient(135deg,#0d1b3e,#2563eb);padding:32px 40px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:22px;font-weight:800;letter-spacing:-.5px;">⚠️ Account Activity Notice</h1>
            <p style="color:#bfdbfe;margin:8px 0 0;font-size:13px;">' . htmlspecialchars($platformName) . '</p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 40px;">
            <p style="font-size:16px;font-weight:700;color:#0d1b3e;margin:0 0 16px;">Dear ' . htmlspecialchars($name) . ',</p>
            <p style="font-size:14px;color:#4b5580;line-height:1.7;margin:0 0 16px;">
              Your account has been <strong style="color:#d97706;">dormant for ' . $daysInactive . ' day' . ($daysInactive !== 1 ? 's' : '') . '</strong>.
              To keep your account active on ' . htmlspecialchars($platformName) . ', please log in or create some activity.
            </p>
            <div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:18px 22px;margin:0 0 22px;">
              <p style="margin:0;font-size:13px;font-weight:700;color:#92400e;">
                ⏰ Your account is scheduled for permanent deletion on <span style="font-size:15px;">' . $deleteDate . '</span>
                unless you log in or create activity before that date.
              </p>
            </div>
            <p style="font-size:14px;color:#4b5580;line-height:1.7;margin:0 0 22px;">
              All your events, tickets, transaction history, and account data will be <strong style="color:#dc2626;">permanently erased</strong> if no activity is detected.
            </p>
            <div style="text-align:center;margin:0 0 28px;">
              <a href="https://keychele.co.ke/login"
                 style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;
                        padding:14px 36px;border-radius:10px;font-size:15px;font-weight:700;
                        letter-spacing:-.2px;box-shadow:0 4px 14px rgba(37,99,235,.35);">
                Log In Now →
              </a>
            </div>
            <p style="font-size:12px;color:#8b95b8;line-height:1.6;margin:0;">
              If you believe this is a mistake or need help, please contact our support team.<br>
              This is an automated message — please do not reply directly to this email.
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#f7f8fc;border-top:1px solid #e4e8f0;padding:18px 40px;text-align:center;">
            <p style="margin:0;font-size:11px;color:#8b95b8;">
              © ' . date('Y') . ' ' . htmlspecialchars($platformName) . ' · You are receiving this because your account is registered on our platform.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
}

// ── Manual reminder email HTML body ─────────────────────────
function buildReminderEmail(string $name, string $customMessage, string $platformName): string {
    $year = date('Y');
    // Escape custom message for safe HTML output, preserve newlines
    $msgHtml = nl2br(htmlspecialchars($customMessage));
    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reminder from ' . htmlspecialchars($platformName) . '</title>
</head>
<body style="margin:0;padding:0;background:#f0f2f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f8;padding:40px 20px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(13,27,62,.1);">
        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#1e3a5f,#2563eb);padding:32px 40px;text-align:center;">
            <h1 style="color:#ffffff;margin:0;font-size:22px;font-weight:800;letter-spacing:-.5px;">
              📢 Message from ' . htmlspecialchars($platformName) . '
            </h1>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:36px 40px;">
            <p style="font-size:16px;font-weight:700;color:#0d1b3e;margin:0 0 16px;">
              Dear ' . htmlspecialchars($name) . ',
            </p>
            <div style="font-size:14px;color:#4b5580;line-height:1.8;margin:0 0 24px;
                        background:#f7f8fc;border-left:4px solid #2563eb;border-radius:0 8px 8px 0;
                        padding:16px 20px;">
              ' . $msgHtml . '
            </div>
            <div style="text-align:center;margin:0 0 28px;">
              <a href="https://keychele.com/msanii/login.php"
                 style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;
                        padding:14px 36px;border-radius:10px;font-size:15px;font-weight:700;
                        letter-spacing:-.2px;box-shadow:0 4px 14px rgba(37,99,235,.35);">
                Log In Now →
              </a>
            </div>
            <p style="font-size:12px;color:#8b95b8;line-height:1.6;margin:0;">
              This is a message from the ' . htmlspecialchars($platformName) . ' admin team.<br>
              Please do not reply directly to this email.
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f7f8fc;border-top:1px solid #e4e8f0;padding:18px 40px;text-align:center;">
            <p style="margin:0;font-size:11px;color:#8b95b8;">
              © ' . $year . ' ' . htmlspecialchars($platformName) . ' · Your account is registered on our platform.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
}

// ── Warning SMS body ─────────────────────────────────────────
function buildWarningSMS(string $name, int $daysInactive, int $graceDays, string $platformName): string {
    $deleteDate = date('d M Y', strtotime("+{$graceDays} days"));
    return "Dear {$name}, your {$platformName} account has been dormant for {$daysInactive} day(s). "
         . "Kindly log in or create activity to avoid auto-deletion scheduled for {$deleteDate}. "
         . "Visit: keychele.com";
}

// ── Log action ───────────────────────────────────────────────
function logAction(PDO $pdo, array $creator, string $action, string $emailStatus, string $smsStatus, string $actionedBy, array $settings): void {
    try {
        $lastActivity = $creator['last_login'] ?? null;
        $daysInactive = $lastActivity ? (int)floor((time() - strtotime($lastActivity)) / 86400) : null;
        $schedDelete  = $creator['scheduled_delete_date'] ?? null;

        $pdo->prepare("
            INSERT INTO inactivity_log
                (creator_id, creator_name, creator_email, action,
                 email_status, sms_status, last_activity_date,
                 inactivity_days_at_action, scheduled_delete_date, actioned_by, actioned_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $creator['id'],
            $creator['full_name'],
            $creator['email'],
            $action,
            $emailStatus,
            $smsStatus,
            $lastActivity,
            $daysInactive,
            $schedDelete,
            $actionedBy,
        ]);
    } catch (PDOException $e) {
        error_log('inactivity_log insert failed: ' . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════
// ROUTE
// ════════════════════════════════════════════════════════════
try {
    $settings = getSettings($pdo);

    // ── SAVE SETTINGS ────────────────────────────────────────
    if ($action === 'save_settings') {
        $pdo->prepare("DELETE FROM inactivity_settings")->execute();
        $pdo->prepare("
            INSERT INTO inactivity_settings
                (inactivity_days, grace_period_days, auto_delete_enabled,
                 talksasa_api_key, talksasa_sender_id,
                 smtp_from_email, smtp_from_name, warning_email_subject)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            max(1, (int)$_POST['inactivity_days']),
            max(1, (int)$_POST['grace_period_days']),
            $_POST['auto_delete_enabled'] === '1' ? 1 : 0,
            trim($_POST['talksasa_api_key']      ?? ''),
            trim($_POST['talksasa_sender_id']    ?? 'KEYCHELE'),
            trim($_POST['smtp_from_email']       ?? ''),
            trim($_POST['smtp_from_name']        ?? ''),
            trim($_POST['warning_email_subject'] ?? ''),
        ]);
        echo json_encode(['success' => true, 'message' => 'Settings saved successfully.']);
        exit();
    }

    // ── PREVIEW ──────────────────────────────────────────────
    if ($action === 'preview') {
        $threshold = (int)$settings['inactivity_days'];
        $graceDays = (int)$settings['grace_period_days'];
        $warnAt    = $threshold - $graceDays;

        $rows = $pdo->query("
            SELECT id, full_name, email, phone, last_login,
                   inactivity_warning_sent_at, scheduled_delete_date
            FROM creators
            WHERE last_login IS NULL
               OR last_login = '0000-00-00 00:00:00'
               OR DATEDIFF(NOW(), last_login) >= {$warnAt}
            ORDER BY last_login ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $ll           = $r['last_login'];
            $daysInactive = ($ll && $ll !== '0000-00-00 00:00:00')
                ? (int)floor((time() - strtotime($ll)) / 86400)
                : $threshold + 1;

            if ($daysInactive < $warnAt) continue;

            if ($r['scheduled_delete_date'] && $r['scheduled_delete_date'] <= date('Y-m-d')) {
                $status = 'due_deletion';
            } elseif ($r['scheduled_delete_date']) {
                $status = 'warned';
            } elseif ($r['inactivity_warning_sent_at']) {
                $status = 'warned';
            } else {
                $status = 'warn_pending';
            }

            $result[] = [
                'id'               => $r['id'],
                'name'             => $r['full_name'],
                'email'            => $r['email'],
                'phone'            => $r['phone'],
                'days_inactive'    => $daysInactive,
                'last_activity'    => $ll,
                'status'           => $status,
                'scheduled_delete' => $r['scheduled_delete_date'],
            ];
        }

        echo json_encode(['success' => true, 'creators' => $result]);
        exit();
    }

    // ── FORCE WARN ───────────────────────────────────────────
    if ($action === 'force_warn') {
        if (!$creator_id) { echo json_encode(['success' => false, 'message' => 'Invalid creator ID']); exit(); }

        $stmt = $pdo->prepare("SELECT * FROM creators WHERE id = ?");
        $stmt->execute([$creator_id]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$creator) { echo json_encode(['success' => false, 'message' => 'Creator not found']); exit(); }

        $graceDays    = (int)$settings['grace_period_days'];
        $deleteDate   = date('Y-m-d', strtotime("+{$graceDays} days"));
        $platformName = $settings['smtp_from_name'] ?? 'Keychele';

        $ll           = $creator['last_login'] ?? null;
        $daysInactive = ($ll && $ll !== '0000-00-00 00:00:00')
            ? max(1, (int)floor((time() - strtotime($ll)) / 86400))
            : (int)$settings['inactivity_days'];

        $emailHtml    = buildWarningEmail($creator['full_name'], $daysInactive, $graceDays, $platformName);
        $emailSubject = $settings['warning_email_subject'] ?? 'Action Required: Your account will be deleted';
        $emailResult  = sendEmail($creator['email'], $creator['full_name'], $emailSubject, $emailHtml, $settings);

        $smsMsg    = buildWarningSMS($creator['full_name'], $daysInactive, $graceDays, $platformName);
        $smsResult = sendSMS($creator['phone'] ?? '', $smsMsg, $settings);

        $pdo->prepare("
            UPDATE creators
            SET inactivity_warning_sent_at = NOW(),
                scheduled_delete_date      = ?
            WHERE id = ?
        ")->execute([$deleteDate, $creator_id]);

        $creator['scheduled_delete_date'] = $deleteDate;
        logAction($pdo, $creator, 'warning_sent',
            $emailResult['success'] ? 'sent' : 'failed',
            $smsResult['success']   ? 'sent' : 'failed',
            'manual', $settings);

        $msg = "Warning sent to {$creator['full_name']}. "
             . "Email: " . ($emailResult['success'] ? '✅ sent' : '❌ failed') . ". "
             . "SMS: "   . ($smsResult['success']   ? '✅ sent' : '❌ failed — ' . $smsResult['detail']) . ". "
             . "Deletion scheduled: {$deleteDate}.";

        echo json_encode([
            'success'      => true,
            'message'      => $msg,
            'email_status' => $emailResult['success'] ? 'sent' : 'failed',
            'sms_status'   => $smsResult['success']   ? 'sent' : 'failed',
            'sms_detail'   => $smsResult['detail'] ?? '',
        ]);
        exit();
    }

    // ── CANCEL DELETION ──────────────────────────────────────
    if ($action === 'cancel_deletion') {
        if (!$creator_id) { echo json_encode(['success' => false, 'message' => 'Invalid creator ID']); exit(); }

        $stmt = $pdo->prepare("SELECT * FROM creators WHERE id = ?");
        $stmt->execute([$creator_id]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$creator) { echo json_encode(['success' => false, 'message' => 'Creator not found']); exit(); }

        $pdo->prepare("
            UPDATE creators
            SET inactivity_warning_sent_at = NULL,
                scheduled_delete_date      = NULL
            WHERE id = ?
        ")->execute([$creator_id]);

        logAction($pdo, $creator, 'deletion_cancelled', 'skipped', 'skipped', 'manual', $settings);

        echo json_encode(['success' => true, 'message' => "Scheduled deletion cancelled for {$creator['full_name']}."]);
        exit();
    }

    // ── FORCE DELETE ─────────────────────────────────────────
    if ($action === 'force_delete') {
        if (!$creator_id) { echo json_encode(['success' => false, 'message' => 'Invalid creator ID']); exit(); }

        $stmt = $pdo->prepare("SELECT * FROM creators WHERE id = ?");
        $stmt->execute([$creator_id]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$creator) { echo json_encode(['success' => false, 'message' => 'Creator not found']); exit(); }

        // Log BEFORE deletion so we retain the record
        logAction($pdo, $creator, 'deleted', 'skipped', 'skipped', 'manual', $settings);

        $tables = [
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
        foreach ($tables as $table => $col) {
            try {
                $pdo->prepare("DELETE FROM {$table} WHERE {$col} = ?")->execute([$creator_id]);
            } catch (PDOException $e) {
                error_log("Delete related {$table}: " . $e->getMessage());
            }
        }

        $pdo->prepare("DELETE FROM creators WHERE id = ?")->execute([$creator_id]);

        echo json_encode(['success' => true, 'message' => "Creator {$creator['full_name']} permanently deleted."]);
        exit();
    }

    // ── MANUAL REMINDER ──────────────────────────────────────
    if ($action === 'manual_reminder') {
        if (!$creator_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid creator ID']);
            exit();
        }

        $viaEmail  = ($_POST['via_email'] ?? '0') === '1';
        $viaSms    = ($_POST['via_sms']   ?? '0') === '1';
        $customMsg = trim($_POST['message'] ?? '');

        if (!$viaEmail && !$viaSms) {
            echo json_encode(['success' => false, 'message' => 'Select at least one channel (Email or SMS).']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM creators WHERE id = ?");
        $stmt->execute([$creator_id]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$creator) {
            echo json_encode(['success' => false, 'message' => 'Creator not found.']);
            exit();
        }

        $platformName = $settings['smtp_from_name'] ?? 'Keychele';

        // Fall back to inactivity warning template text if no custom message given
        $messageBody = $customMsg !== ''
            ? $customMsg
            : ($settings['warning_email_subject'] ?? 'This is a reminder regarding your account on ' . $platformName . '.');

        $emailStatus = 'skipped';
        $smsStatus   = 'skipped';
        $emailDetail = '';
        $smsDetail   = '';

        // ── Send Email ───────────────────────────────────────
        if ($viaEmail) {
            if (empty($creator['email'])) {
                $emailStatus = 'failed';
                $emailDetail = 'No email address on record for this creator.';
            } else {
                // Use styled reminder email if custom message provided,
                // else fall back to the standard inactivity warning template
                $emailHtml = $customMsg !== ''
                    ? buildReminderEmail($creator['full_name'], $customMsg, $platformName)
                    : buildWarningEmail(
                        $creator['full_name'],
                        // Days inactive for the warning email context
                        ($creator['last_login'] && $creator['last_login'] !== '0000-00-00 00:00:00')
                            ? max(1, (int)floor((time() - strtotime($creator['last_login'])) / 86400))
                            : (int)$settings['inactivity_days'],
                        (int)$settings['grace_period_days'],
                        $platformName
                    );

                $emailSubject = $customMsg !== ''
                    ? 'Message from ' . $platformName
                    : ($settings['warning_email_subject'] ?? 'Reminder from ' . $platformName);

                $result      = sendEmail($creator['email'], $creator['full_name'], $emailSubject, $emailHtml, $settings);
                $emailStatus = $result['success'] ? 'sent' : 'failed';
                $emailDetail = $result['detail']  ?? '';
            }
        }

        // ── Send SMS ─────────────────────────────────────────
        if ($viaSms) {
            if (empty($creator['phone'])) {
                $smsStatus = 'failed';
                $smsDetail = 'No phone number on record for this creator.';
            } else {
                // Keep SMS concise — strip HTML tags if message came from email body
                $smsText = $customMsg !== ''
                    ? $customMsg
                    : buildWarningSMS(
                        $creator['full_name'],
                        ($creator['last_login'] && $creator['last_login'] !== '0000-00-00 00:00:00')
                            ? max(1, (int)floor((time() - strtotime($creator['last_login'])) / 86400))
                            : (int)$settings['inactivity_days'],
                        (int)$settings['grace_period_days'],
                        $platformName
                    );

                $result    = sendSMS($creator['phone'], $smsText, $settings);
                $smsStatus = $result['success'] ? 'sent' : 'failed';
                $smsDetail = $result['detail']  ?? '';
            }
        }

        // ── Audit log ─────────────────────────────────────────
        logAction($pdo, $creator, 'manual_reminder', $emailStatus, $smsStatus, 'manual', $settings);

        // ── Build response message ────────────────────────────
        $parts = [];
        if ($viaEmail) $parts[] = 'Email: ' . ($emailStatus === 'sent' ? '✅ sent' : '❌ failed' . ($emailDetail ? " ({$emailDetail})" : ''));
        if ($viaSms)   $parts[] = 'SMS: '   . ($smsStatus   === 'sent' ? '✅ sent' : '❌ failed' . ($smsDetail   ? " ({$smsDetail})"   : ''));

        $overallSuccess = !($emailStatus === 'failed' && $viaSms === false)
                        && !($smsStatus === 'failed' && $viaEmail === false)
                        && ($emailStatus !== 'failed' || !$viaEmail)
                        && ($smsStatus   !== 'failed' || !$viaSms);

        // Simpler: success if at least one channel succeeded (or was skipped by choice)
        $overallSuccess = ($viaEmail ? $emailStatus === 'sent' : true)
                        && ($viaSms  ? $smsStatus   === 'sent' : true);

        echo json_encode([
            'success'      => $overallSuccess,
            'message'      => "Reminder dispatched to {$creator['full_name']}. " . implode('. ', $parts) . '.',
            'email_status' => $emailStatus,
            'sms_status'   => $smsStatus,
            'sms_detail'   => $smsDetail  ?: null,
            'email_detail' => $emailDetail ?: null,
        ]);
        exit();
    }

    // ── RUN CRON (background trigger from page load) ─────────
    if ($action === 'run_cron') {
        $warnThreshold = (int)$settings['inactivity_days'] - (int)$settings['grace_period_days'];

        $due = $pdo->query("
            SELECT COUNT(*) FROM creators
            WHERE (
                inactivity_warning_sent_at IS NULL
                AND (last_login IS NULL OR DATEDIFF(NOW(), last_login) >= {$warnThreshold})
            ) OR (
                scheduled_delete_date IS NOT NULL
                AND scheduled_delete_date <= CURDATE()
            )
        ")->fetchColumn();

        if ($due > 0) {
            $php    = PHP_BINARY ?: '/usr/local/bin/php';
            $script = dirname(__FILE__) . '/cron_inactivity.php';
            if (file_exists($script)) {
                shell_exec("{$php} {$script} > /dev/null 2>&1 &");
            }
        }

        echo json_encode(['success' => true, 'ran' => (bool)$due]);
        exit();
    }

    // ── Unknown action ───────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}