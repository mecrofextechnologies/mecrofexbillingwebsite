<?php
ob_start();
/**
 * activity_notifications_action.php
 * AJAX handler for all_activity_settings.php
 * Place in the same admin folder.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

include '../msanii/includes/connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
ob_end_clean();
header('Content-Type: application/json');

// ── Auth guard ──────────────────────────────────────────────
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
ob_end_clean(); // ← NEW: discard header.php HTML before we set our own header
header('Content-Type: application/json');

$action = trim($_POST['action'] ?? '');

define('PLATFORM_NAME', 'Keychele');

// ════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════

function getSmtpSettings(PDO $pdo): array {
    $s = $pdo->query("SELECT * FROM inactivity_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return $s ?: [
        'smtp_from_email' => 'notifications@keychele.co.ke',
        'smtp_from_name'  => PLATFORM_NAME,
    ];
}

function sendEmail(string $to, string $toName, string $subject, string $htmlBody, array $smtp): array {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host        = 'mail.keychele.co.ke';
        $mail->SMTPAuth    = true;
        $mail->Username    = 'notifications@keychele.co.ke';
        $mail->Password    = 'King20241064.';
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = 587;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mail->setFrom($smtp['smtp_from_email'] ?? 'notifications@keychele.co.ke', $smtp['smtp_from_name'] ?? PLATFORM_NAME);
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        $mail->send();
        return ['success' => true, 'detail' => 'sent'];
    } catch (MailException $e) {
        return ['success' => false, 'detail' => $mail->ErrorInfo];
    }
}

function sendSMS(string $phone, string $message, PDO $pdo): array {
    try {
        $cfg = $pdo->query("SELECT * FROM admin_sms_settings WHERE provider = 'talksasa' LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['success' => false, 'detail' => 'DB error: ' . $e->getMessage()];
    }
    if (!$cfg) return ['success' => false, 'detail' => 'No talksasa SMS config found'];
    $token      = trim($cfg['api_token']   ?? '');
    $senderName = trim($cfg['sender_name'] ?? '');
    if (!$token || !$senderName) return ['success' => false, 'detail' => 'SMS config incomplete'];

    $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
    if (str_starts_with($phone, '0'))    $phone = '254' . substr($phone, 1);
    elseif (str_starts_with($phone, '+')) $phone = substr($phone, 1);

    $ch = curl_init('https://bulksms.talksasa.com/api/v3/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['recipient' => $phone, 'sender_id' => $senderName, 'type' => 'plain', 'message' => $message]),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) return ['success' => false, 'detail' => 'cURL: ' . $err];
    $data = json_decode($raw, true);
    $ok   = isset($data['status']) && strtolower((string)$data['status']) === 'success';
    return ['success' => $ok, 'detail' => $ok ? 'sent' : "HTTP {$code}: " . ($data['message'] ?? $raw)];
}

/**
 * Replace template placeholders with actual values.
 */
function fillTemplate(string $tpl, array $vars): string {
    foreach ($vars as $key => $val) {
        $tpl = str_replace('{' . $key . '}', (string)$val, $tpl);
    }
    return $tpl;
}

/**
 * Build a styled HTML email wrapper around the body template.
 */
function buildEmailHtml(string $bodyContent, string $platformName): string {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($platformName) . '</title></head>
<body style="margin:0;padding:0;background:#f0f2f8;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f8;padding:40px 20px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0"
       style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(13,27,62,.1);">
  <tr>
    <td style="background:linear-gradient(135deg,#0d1b3e,#2563eb);padding:28px 36px;text-align:center;">
      <h1 style="color:#fff;margin:0;font-size:20px;font-weight:800;">📢 ' . htmlspecialchars($platformName) . '</h1>
    </td>
  </tr>
  <tr>
    <td style="padding:32px 36px;font-size:14px;color:#4b5580;line-height:1.7;">
      ' . $bodyContent . '
    </td>
  </tr>
  <tr>
    <td style="background:#f7f8fc;border-top:1px solid #e4e8f0;padding:16px 36px;text-align:center;">
      <a href="https://keychele.com/msanii/login.php"
         style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;
                padding:12px 30px;border-radius:8px;font-size:14px;font-weight:700;margin-bottom:12px;">
        Log In Now →
      </a>
      <p style="margin:0;font-size:11px;color:#8b95b8;">
        © ' . date('Y') . ' ' . htmlspecialchars($platformName) . ' · This is an automated message.
      </p>
    </td>
  </tr>
</table>
</td></tr></table>
</body></html>';
}

/**
 * Log a sent notification.
 */
function logNotification(PDO $pdo, array $data): void {
    try {
        $pdo->prepare("
            INSERT INTO activity_notification_log
                (creator_id, creator_name, creator_email, creator_phone,
                 source_type, source_id, rule_key,
                 channel, email_status, sms_status, email_detail, sms_detail,
                 message_preview, actioned_by, sent_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $data['creator_id'],
            $data['creator_name']   ?? null,
            $data['creator_email']  ?? null,
            $data['creator_phone']  ?? null,
            $data['source_type']    ?? 'manual',
            $data['source_id']      ?? null,
            $data['rule_key']       ?? null,
            $data['channel']        ?? 'both',
            $data['email_status']   ?? null,
            $data['sms_status']     ?? null,
            $data['email_detail']   ?? null,
            $data['sms_detail']     ?? null,
            substr($data['message_preview'] ?? '', 0, 300),
            $data['actioned_by']    ?? 'manual',
        ]);
    } catch (PDOException $e) {
        error_log('activity_notification_log insert: ' . $e->getMessage());
    }
}

/**
 * Get creators qualifying for a given rule.
 * Returns array of creator rows.
 */
function getQualifyingCreators(PDO $pdo, array $rule): array {
    $ruleKey = $rule['rule_key'];
    $ruleType = $rule['rule_type'];

    switch ($ruleType) {

        // ── Never created a ticket ──────────────────────────
        case 'ticket':
            if ($ruleKey === 'ticket_never_created') {
                $days = (int)$rule['threshold_days'];
                return $pdo->query("
                    SELECT c.id, c.full_name, c.email, c.phone, c.date_created,
                           DATEDIFF(NOW(), c.date_created) AS days_since
                    FROM creators c
                    WHERE NOT EXISTS (SELECT 1 FROM tickets t WHERE t.creator_id = c.id)
                      AND DATEDIFF(NOW(), c.date_created) >= {$days}
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
            // No recent ticket (had one before, gone dormant)
            if ($ruleKey === 'ticket_no_recent') {
                $days = (int)$rule['threshold_days'];
                return $pdo->query("
                    SELECT c.id, c.full_name, c.email, c.phone,
                           MAX(t.created_at) AS last_ticket,
                           DATEDIFF(NOW(), MAX(t.created_at)) AS days_since
                    FROM creators c
                    JOIN tickets t ON t.creator_id = c.id
                    GROUP BY c.id
                    HAVING days_since >= {$days}
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
            return [];

        // ── No purchase rules ───────────────────────────────
        case 'no_purchase':
            if ($ruleKey === 'no_purchase_ever') {
                $days = (int)$rule['threshold_days'];
                return $pdo->query("
                    SELECT c.id, c.full_name, c.email, c.phone, c.date_created,
                           DATEDIFF(NOW(), c.date_created) AS days_since
                    FROM creators c
                    WHERE NOT EXISTS (
                        SELECT 1 FROM ticket_purchases tp
                        JOIN tickets t ON t.id = tp.ticket_id
                        WHERE t.creator_id = c.id AND tp.status = 'completed'
                    )
                    AND DATEDIFF(NOW(), c.date_created) >= {$days}
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
            if ($ruleKey === 'no_purchase_recent') {
                $days = (int)$rule['threshold_days'];
                return $pdo->query("
                    SELECT c.id, c.full_name, c.email, c.phone,
                           MAX(tp.created_at) AS last_purchase,
                           DATEDIFF(NOW(), MAX(tp.created_at)) AS days_since
                    FROM creators c
                    JOIN tickets t ON t.creator_id = c.id
                    JOIN ticket_purchases tp ON tp.ticket_id = t.id AND tp.status = 'completed'
                    GROUP BY c.id
                    HAVING days_since >= {$days}
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
            return [];

        // ── Purchase milestones ─────────────────────────────
        case 'purchase_milestone':
            $milestone = (int)$rule['milestone_value'];
            // Creators whose total completed purchases EQUALS the milestone
            // AND who haven't already been notified for this rule
            return $pdo->query("
                SELECT c.id, c.full_name, c.email, c.phone,
                       COUNT(tp.id) AS purchase_count
                FROM creators c
                JOIN tickets t ON t.creator_id = c.id
                JOIN ticket_purchases tp ON tp.ticket_id = t.id AND tp.status = 'completed'
                GROUP BY c.id
                HAVING purchase_count = {$milestone}
                  AND NOT EXISTS (
                      SELECT 1 FROM activity_notification_log anl
                      WHERE anl.creator_id = c.id
                        AND anl.rule_key = '{$rule['rule_key']}'
                  )
            ")->fetchAll(PDO::FETCH_ASSOC);

        default:
            return [];
    }
}

/**
 * Send a notification to one creator for a rule.
 */
function sendRuleNotification(PDO $pdo, array $creator, array $rule, string $actionedBy, array $smtp): array {
    $platform = PLATFORM_NAME;
    $days     = (int)($creator['days_since'] ?? 0);
    $count    = (int)($creator['purchase_count'] ?? 0);
    $milestone= (int)($rule['milestone_value'] ?? 0);

    $vars = [
        'name'      => $creator['full_name'],
        'platform'  => $platform,
        'days'      => $days,
        'count'     => $count,
        'milestone' => $milestone ?: $count,
        'date'      => date('d F Y'),
    ];

    $emailStatus = 'skipped';
    $smsStatus   = 'skipped';
    $emailDetail = '';
    $smsDetail   = '';

    // ── Email ──────────────────────────────────────────────
    if ($rule['channel_email'] && !empty($creator['email'])) {
        $subject  = fillTemplate($rule['email_subject'] ?? PLATFORM_NAME . ' — Activity Reminder', $vars);
        $bodyRaw  = fillTemplate($rule['email_body'] ?? '<p>Hi {name}, just a reminder from {platform}.</p>', $vars);
        $htmlFull = buildEmailHtml($bodyRaw, $platform);
        $res      = sendEmail($creator['email'], $creator['full_name'], $subject, $htmlFull, $smtp);
        $emailStatus = $res['success'] ? 'sent' : 'failed';
        $emailDetail = $res['detail'];
    }

    // ── SMS ────────────────────────────────────────────────
    if ($rule['channel_sms'] && !empty($creator['phone'])) {
        $smsText = fillTemplate($rule['sms_body'] ?? 'Hi {name}, reminder from {platform}. keychele.com', $vars);
        $res     = sendSMS($creator['phone'], $smsText, $pdo);
        $smsStatus = $res['success'] ? 'sent' : 'failed';
        $smsDetail = $res['detail'];
    }

    // ── Determine channel label ────────────────────────────
    $channelLabel = match(true) {
        $rule['channel_email'] && $rule['channel_sms'] => 'both',
        $rule['channel_email']                         => 'email',
        default                                        => 'sms',
    };

    // ── Log ────────────────────────────────────────────────
    logNotification($pdo, [
        'creator_id'      => $creator['id'],
        'creator_name'    => $creator['full_name'],
        'creator_email'   => $creator['email'],
        'creator_phone'   => $creator['phone'],
        'source_type'     => 'rule',
        'source_id'       => $rule['id'],
        'rule_key'        => $rule['rule_key'],
        'channel'         => $channelLabel,
        'email_status'    => $emailStatus,
        'sms_status'      => $smsStatus,
        'email_detail'    => $emailDetail,
        'sms_detail'      => $smsDetail,
        'message_preview' => strip_tags($rule['sms_body'] ?? ''),
        'actioned_by'     => $actionedBy,
    ]);

    return [
        'email_status' => $emailStatus,
        'sms_status'   => $smsStatus,
    ];
}

// ════════════════════════════════════════════════════════════
// ROUTE
// ════════════════════════════════════════════════════════════
try {
    $smtp = getSmtpSettings($pdo);

    // ── TOGGLE RULE ENABLE ───────────────────────────────────
    if ($action === 'toggle_rule') {
        $ruleId    = (int)$_POST['rule_id'];
        $isEnabled = (int)$_POST['is_enabled'];
        $pdo->prepare("UPDATE activity_notification_rules SET is_enabled = ? WHERE id = ?")
            ->execute([$isEnabled, $ruleId]);
        echo json_encode(['success' => true, 'message' => 'Rule ' . ($isEnabled ? 'enabled' : 'disabled') . '.']);
        exit();
    }

    // ── SAVE RULE ────────────────────────────────────────────
    if ($action === 'save_rule') {
        $ruleId = (int)$_POST['rule_id'];

        $threshold  = isset($_POST['threshold_days'])  && $_POST['threshold_days']  !== '' ? (int)$_POST['threshold_days']  : null;
        $milestone  = isset($_POST['milestone_value']) && $_POST['milestone_value'] !== '' ? (int)$_POST['milestone_value'] : null;
        $repeatDays = isset($_POST['repeat_every_days']) && $_POST['repeat_every_days'] !== '' ? (int)$_POST['repeat_every_days'] : null;

        $pdo->prepare("
            UPDATE activity_notification_rules SET
                threshold_days    = ?,
                milestone_value   = ?,
                frequency         = ?,
                repeat_every_days = ?,
                channel_email     = ?,
                channel_sms       = ?,
                email_subject     = ?,
                email_body        = ?,
                sms_body          = ?,
                updated_at        = NOW()
            WHERE id = ?
        ")->execute([
            $threshold,
            $milestone,
            $_POST['frequency']     ?? 'once',
            $repeatDays,
            (int)($_POST['channel_email'] ?? 0),
            (int)($_POST['channel_sms']   ?? 0),
            trim($_POST['email_subject']  ?? ''),
            trim($_POST['email_body']     ?? ''),
            trim($_POST['sms_body']       ?? ''),
            $ruleId,
        ]);

        echo json_encode(['success' => true, 'message' => 'Rule saved successfully.']);
        exit();
    }

    // ── DELETE RULE ──────────────────────────────────────────
    if ($action === 'delete_rule') {
        $ruleId = (int)$_POST['rule_id'];
        // Only allow deleting custom milestone rules (not default ones)
        $pdo->prepare("DELETE FROM activity_notification_rules WHERE id = ? AND rule_type = 'purchase_milestone'")->execute([$ruleId]);
        echo json_encode(['success' => true, 'message' => 'Milestone rule deleted.']);
        exit();
    }

    // ── ADD MILESTONE ────────────────────────────────────────
    if ($action === 'add_milestone') {
        $val = (int)$_POST['milestone_value'];
        if ($val < 1) { echo json_encode(['success' => false, 'message' => 'Invalid milestone value.']); exit(); }

        // Check not duplicate
        $exists = $pdo->prepare("SELECT COUNT(*) FROM activity_notification_rules WHERE milestone_value = ?");
        $exists->execute([$val]);
        if ((int)$exists->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => "A milestone for {$val} purchases already exists."]);
            exit();
        }

        $label    = trim($_POST['label'] ?? '') ?: "{$val}th Purchase Milestone";
        $ruleKey  = 'milestone_' . $val;

        $pdo->prepare("
            INSERT INTO activity_notification_rules
                (rule_key, rule_type, label, description, is_enabled,
                 milestone_value, frequency, channel_email, channel_sms,
                 email_subject, email_body, sms_body, sort_order)
            VALUES (?,?,?,?,0,?,'once',?,?,?,?,?,10)
        ")->execute([
            $ruleKey,
            'purchase_milestone',
            $label,
            "Fires when a creator reaches {$val} total purchases.",
            $val,
            (int)($_POST['channel_email'] ?? 1),
            (int)($_POST['channel_sms']   ?? 1),
            trim($_POST['email_subject']  ?? "🎉 Congratulations on your {milestone} sale!"),
            trim($_POST['email_body']     ?? "<p>Hi {name},</p><p>You've hit {milestone} purchases on {platform}! Amazing work!</p>"),
            trim($_POST['sms_body']       ?? "Congrats {name}! You've reached {milestone} sales on {platform}! keychele.com"),
        ]);

        echo json_encode(['success' => true, 'message' => "Milestone ({$val} purchases) added successfully."]);
        exit();
    }

    // ── SCAN RULE (preview which creators qualify) ───────────
    if ($action === 'scan_rule') {
        $ruleId = (int)$_POST['rule_id'];
        $rule   = $pdo->prepare("SELECT * FROM activity_notification_rules WHERE id = ?");
        $rule->execute([$ruleId]);
        $rule = $rule->fetch(PDO::FETCH_ASSOC);
        if (!$rule) { echo json_encode(['success' => false, 'message' => 'Rule not found.']); exit(); }

        $creators = getQualifyingCreators($pdo, $rule);

        $result = array_map(fn($c) => [
            'id'    => $c['id'],
            'name'  => $c['full_name'],
            'email' => $c['email'],
            'phone' => $c['phone'],
            'days'  => (int)($c['days_since'] ?? $c['purchase_count'] ?? 0),
        ], $creators);

        echo json_encode(['success' => true, 'creators' => $result, 'total' => count($result)]);
        exit();
    }

    // ── MANUAL TRIGGER RULE ──────────────────────────────────
    if ($action === 'manual_trigger_rule') {
        $ruleId = (int)$_POST['rule_id'];
        $rule   = $pdo->prepare("SELECT * FROM activity_notification_rules WHERE id = ?");
        $rule->execute([$ruleId]);
        $rule = $rule->fetch(PDO::FETCH_ASSOC);
        if (!$rule) { echo json_encode(['success' => false, 'message' => 'Rule not found.']); exit(); }

        $creators = getQualifyingCreators($pdo, $rule);
        $sent = $failed = 0;

        foreach ($creators as $creator) {
            $res = sendRuleNotification($pdo, $creator, $rule, 'manual', $smtp);
            ($res['email_status'] === 'sent' || $res['sms_status'] === 'sent') ? $sent++ : $failed++;
        }

        echo json_encode([
            'success' => true,
            'message' => "Done. {$sent} notification(s) sent" . ($failed ? ", {$failed} failed." : "."),
        ]);
        exit();
    }

    // ── PREVIEW CAMPAIGN RECIPIENTS ──────────────────────────
    if ($action === 'preview_campaign_recipients') {
        $targetType = trim($_POST['target_type'] ?? 'all');

        $creators = getCampaignRecipients($pdo, $targetType);
        $preview  = array_slice($creators, 0, 10); // Show max 10 in preview

        echo json_encode([
            'success'  => true,
            'creators' => array_map(fn($c) => ['id' => $c['id'], 'name' => $c['full_name'], 'email' => $c['email']], $preview),
            'total'    => count($creators),
        ]);
        exit();
    }

    // ── SAVE CAMPAIGN ────────────────────────────────────────
    if ($action === 'save_campaign') {
        $campId = (int)($_POST['campaign_id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        if (!$title) { echo json_encode(['success' => false, 'message' => 'Campaign title is required.']); exit(); }

        $scheduledAt = trim($_POST['scheduled_at'] ?? '');
        $scheduledAt = $scheduledAt ? date('Y-m-d H:i:s', strtotime($scheduledAt)) : null;
        $status = trim($_POST['status'] ?? 'draft');

        $targetType = trim($_POST['target_type'] ?? 'all');
        $recipients = getCampaignRecipients($pdo, $targetType);
        $totalRec   = count($recipients);

        if ($campId) {
            $pdo->prepare("
                UPDATE activity_campaigns SET
                    title=?, target_type=?, channel_email=?, channel_sms=?,
                    email_subject=?, email_body=?, sms_body=?,
                    scheduled_at=?, status=?, total_recipients=?, updated_at=NOW()
                WHERE id=?
            ")->execute([
                $title, $targetType,
                (int)($_POST['channel_email'] ?? 0), (int)($_POST['channel_sms'] ?? 0),
                trim($_POST['email_subject'] ?? ''),
                trim($_POST['email_body']    ?? ''),
                trim($_POST['sms_body']      ?? ''),
                $scheduledAt, $status, $totalRec, $campId,
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO activity_campaigns
                    (title, target_type, channel_email, channel_sms,
                     email_subject, email_body, sms_body,
                     scheduled_at, status, total_recipients, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $title, $targetType,
                (int)($_POST['channel_email'] ?? 0), (int)($_POST['channel_sms'] ?? 0),
                trim($_POST['email_subject'] ?? ''),
                trim($_POST['email_body']    ?? ''),
                trim($_POST['sms_body']      ?? ''),
                $scheduledAt, $status, $totalRec,
                $_SESSION['user_id'] ?? null,
            ]);
            $campId = (int)$pdo->lastInsertId();

            // Pre-populate recipients table for scheduled/immediate
            if ($status === 'scheduled') {
                $ins = $pdo->prepare("
                    INSERT INTO activity_campaign_recipients
                        (campaign_id, creator_id, creator_name, creator_email, creator_phone)
                    VALUES (?,?,?,?,?)
                ");
                foreach ($recipients as $r) {
                    $ins->execute([$campId, $r['id'], $r['full_name'], $r['email'], $r['phone']]);
                }
            }
        }

        echo json_encode(['success' => true, 'message' => "Campaign \"{$title}\" saved (ID: {$campId}). {$totalRec} recipient(s)."]);
        exit();
    }

    // ── SEND CAMPAIGN ────────────────────────────────────────
    if ($action === 'send_campaign') {
        $campId = (int)$_POST['campaign_id'];
        $camp   = $pdo->prepare("SELECT * FROM activity_campaigns WHERE id = ?");
        $camp->execute([$campId]);
        $camp = $camp->fetch(PDO::FETCH_ASSOC);
        if (!$camp) { echo json_encode(['success' => false, 'message' => 'Campaign not found.']); exit(); }
        if ($camp['status'] === 'running') { echo json_encode(['success' => false, 'message' => 'Campaign is already running.']); exit(); }

        // Mark as running
        $pdo->prepare("UPDATE activity_campaigns SET status='running', started_at=NOW() WHERE id=?")
            ->execute([$campId]);

        $recipients = getCampaignRecipients($pdo, $camp['target_type']);
        $sent = $failed = 0;
        $platform = PLATFORM_NAME;

        foreach ($recipients as $creator) {
            $vars = [
                'name'     => $creator['full_name'],
                'platform' => $platform,
                'days'     => '',
                'count'    => '',
                'date'     => date('d F Y'),
            ];

            $emailStatus = 'skipped';
            $smsStatus   = 'skipped';
            $emailDetail = '';
            $smsDetail   = '';

            if ($camp['channel_email'] && !empty($creator['email'])) {
                $subject  = fillTemplate($camp['email_subject'] ?? 'Message from ' . $platform, $vars);
                $bodyRaw  = fillTemplate($camp['email_body'] ?? '', $vars);
                $htmlFull = buildEmailHtml($bodyRaw, $platform);
                $res      = sendEmail($creator['email'], $creator['full_name'], $subject, $htmlFull, $smtp);
                $emailStatus = $res['success'] ? 'sent' : 'failed';
                $emailDetail = $res['detail'];
            }

            if ($camp['channel_sms'] && !empty($creator['phone'])) {
                $smsText = fillTemplate($camp['sms_body'] ?? 'Hi {name}, message from {platform}. keychele.com', $vars);
                $res     = sendSMS($creator['phone'], $smsText, $pdo);
                $smsStatus = $res['success'] ? 'sent' : 'failed';
                $smsDetail = $res['detail'];
            }

            // Log individual notification
            logNotification($pdo, [
                'creator_id'      => $creator['id'],
                'creator_name'    => $creator['full_name'],
                'creator_email'   => $creator['email'],
                'creator_phone'   => $creator['phone'],
                'source_type'     => 'campaign',
                'source_id'       => $campId,
                'rule_key'        => null,
                'channel'         => ($camp['channel_email'] && $camp['channel_sms']) ? 'both' : ($camp['channel_email'] ? 'email' : 'sms'),
                'email_status'    => $emailStatus,
                'sms_status'      => $smsStatus,
                'email_detail'    => $emailDetail,
                'sms_detail'      => $smsDetail,
                'message_preview' => substr(strip_tags($camp['sms_body'] ?? $camp['email_body'] ?? ''), 0, 300),
                'actioned_by'     => 'manual',
            ]);

            ($emailStatus === 'sent' || $smsStatus === 'sent') ? $sent++ : $failed++;

            // Update recipient row
            $pdo->prepare("
                UPDATE activity_campaign_recipients
                SET email_status=?, sms_status=?, email_detail=?, sms_detail=?, sent_at=NOW()
                WHERE campaign_id=? AND creator_id=?
            ")->execute([$emailStatus, $smsStatus, $emailDetail, $smsDetail, $campId, $creator['id']]);
        }

        // Finalise campaign
        $pdo->prepare("
            UPDATE activity_campaigns
            SET status='completed', completed_at=NOW(),
                sent_count=?, failed_count=?, total_recipients=?
            WHERE id=?
        ")->execute([$sent, $failed, count($recipients), $campId]);

        echo json_encode([
            'success' => true,
            'message' => "Campaign complete. {$sent} sent" . ($failed ? ", {$failed} failed" : "") . ".",
        ]);
        exit();
    }

    // ── DELETE CAMPAIGN ──────────────────────────────────────
    if ($action === 'delete_campaign') {
        $campId = (int)$_POST['campaign_id'];
        $pdo->prepare("DELETE FROM activity_campaigns WHERE id = ? AND status != 'running'")->execute([$campId]);
        echo json_encode(['success' => true, 'message' => 'Campaign deleted.']);
        exit();
    }

    // ── RUN CRON (manual trigger) ────────────────────────────
    if ($action === 'run_cron') {
        $php    = PHP_BINARY ?: '/usr/local/bin/php';
        $script = dirname(__FILE__) . '/activity_notifications_cron.php';
        if (file_exists($script)) {
            shell_exec("{$php} {$script} > /dev/null 2>&1 &");
            echo json_encode(['success' => true, 'message' => 'Cron triggered in background. Check the log in a few seconds.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Cron script not found at: ' . $script]);
        }
        exit();
    }

    // ── Unknown ──────────────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

// ════════════════════════════════════════════════════════════
// CAMPAIGN RECIPIENT HELPER
// ════════════════════════════════════════════════════════════
function getCampaignRecipients(PDO $pdo, string $targetType): array {
    return match($targetType) {
        'no_ticket' => $pdo->query("
            SELECT c.id, c.full_name, c.email, c.phone
            FROM creators c
            WHERE NOT EXISTS (SELECT 1 FROM tickets t WHERE t.creator_id = c.id)
        ")->fetchAll(PDO::FETCH_ASSOC),

        'no_purchase' => $pdo->query("
            SELECT c.id, c.full_name, c.email, c.phone
            FROM creators c
            WHERE NOT EXISTS (
                SELECT 1 FROM ticket_purchases tp
                JOIN tickets t ON t.id = tp.ticket_id
                WHERE t.creator_id = c.id AND tp.status = 'completed'
            )
        ")->fetchAll(PDO::FETCH_ASSOC),

        default => $pdo->query("
            SELECT id, full_name, email, phone FROM creators ORDER BY id
        ")->fetchAll(PDO::FETCH_ASSOC),
    };
}