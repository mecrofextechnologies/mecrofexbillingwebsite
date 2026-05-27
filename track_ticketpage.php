<?php
/*
 * ════════════════════════════════════════════════════════════════
 *  track_ticketpage.php  –  Keychele Ticket Page Visit Tracker
 * ════════════════════════════════════════════════════════════════
 *
 *  HOW TO USE  — add ONE line inside ticket.php, right after
 *  the $event fetch succeeds (before any HTML output):
 *
 *      if ($event) include __DIR__ . '/track_ticketpage.php';
 *
 *  REQUIRES in scope:  $pdo, $event_id, $event[]
 *
 * ════════════════════════════════════════════════════════════════
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Guard ────────────────────────────────────────────────────────
if (!isset($pdo, $event_id, $event) || empty($event)) return;

// ── Notification cooldown window (minutes) ───────────────────────
if (!defined('KC_VISIT_NOTIFY_COOLDOWN')) {
    define('KC_VISIT_NOTIFY_COOLDOWN', 15);
}


// ════════════════════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════════════════════

if (!function_exists('kc_get_ip')) {
    function kc_get_ip(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (empty($_SERVER[$h])) continue;
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

if (!function_exists('kc_parse_browser')) {
    function kc_parse_browser(string $ua): string
    {
        $ua = strtolower($ua);
        if (str_contains($ua, 'edg'))                               return 'Edge';
        if (str_contains($ua, 'opr') || str_contains($ua, 'opera')) return 'Opera';
        if (str_contains($ua, 'samsungbrowser'))                    return 'Samsung Browser';
        if (str_contains($ua, 'ucbrowser'))                         return 'UC Browser';
        if (str_contains($ua, 'brave'))                             return 'Brave';
        if (str_contains($ua, 'chrome'))                            return 'Chrome';
        if (str_contains($ua, 'firefox'))                           return 'Firefox';
        if (str_contains($ua, 'safari'))                            return 'Safari';
        if (str_contains($ua, 'msie') || str_contains($ua, 'trident')) return 'IE';
        return 'Unknown';
    }
}

if (!function_exists('kc_parse_device')) {
    function kc_parse_device(string $ua): string
    {
        if (preg_match('/tablet|ipad|playbook|silk/i', $ua))                            return 'Tablet';
        if (preg_match('/mobile|android|iphone|ipod|blackberry|windows phone/i', $ua)) return 'Mobile';
        return 'Desktop';
    }
}

if (!function_exists('kc_parse_os')) {
    function kc_parse_os(string $ua): string
    {
        if (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
            $ver = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'][$m[1]] ?? $m[1];
            return "Windows $ver";
        }
        if (preg_match('/Android ([\d.]+)/i', $ua, $m))      return 'Android ' . $m[1];
        if (preg_match('/OS ([\d_]+) like Mac/i', $ua, $m))  return 'iOS ' . str_replace('_', '.', $m[1]);
        if (preg_match('/Mac OS X ([\d_.]+)/i', $ua, $m))    return 'macOS ' . str_replace('_', '.', $m[1]);
        if (str_contains($ua, 'Linux'))                       return 'Linux';
        return 'Unknown';
    }
}

if (!function_exists('kc_geo_lookup')) {
    function kc_geo_lookup(string $ip): array
    {
        $default = ['country' => 'Unknown', 'city' => 'Unknown', 'region' => ''];
        if ($ip === '0.0.0.0' || $ip === '127.0.0.1') return $default;
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $raw = @file_get_contents(
            "http://ip-api.com/json/{$ip}?fields=status,country,regionName,city",
            false, $ctx
        );
        if (!$raw) return $default;
        $d = json_decode($raw, true);
        if (($d['status'] ?? '') !== 'success') return $default;
        return [
            'country' => $d['country']    ?? 'Unknown',
            'city'    => $d['city']       ?? 'Unknown',
            'region'  => $d['regionName'] ?? '',
        ];
    }
}

if (!function_exists('kc_device_emoji')) {
    function kc_device_emoji(string $device): string
    {
        return match ($device) {
            'Mobile' => '📱',
            'Tablet' => '📟',
            default  => '🖥️',
        };
    }
}


// ════════════════════════════════════════════════════════════════
//  COLLECT VISIT DATA
// ════════════════════════════════════════════════════════════════

$_kc_ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
$_kc_ip      = kc_get_ip();
$_kc_browser = kc_parse_browser($_kc_ua);
$_kc_device  = kc_parse_device($_kc_ua);
$_kc_os      = kc_parse_os($_kc_ua);
$_kc_geo     = kc_geo_lookup($_kc_ip);
$_kc_country = $_kc_geo['country'];
$_kc_city    = $_kc_geo['city'];
$_kc_region  = $_kc_geo['region'];


// ════════════════════════════════════════════════════════════════
//  INSERT VISIT RECORD + UPDATE RUNNING TOTAL
// ════════════════════════════════════════════════════════════════

$_kc_creator_id = $event['creator_id'] ?? $event['creator_id_val'] ?? null;

try {
    // ── Detailed visit log ───────────────────────────────────────
    $pdo->prepare("
        INSERT INTO ticket_page_visits
            (ticket_id, ip_address, country, city, region, device, browser, os, user_agent, visited_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $event_id, $_kc_ip, $_kc_country, $_kc_city, $_kc_region,
        $_kc_device, $_kc_browser, $_kc_os, $_kc_ua
    ]);

    // ── Running total (insert on first visit, increment on subsequent) ──
    // Requires UNIQUE KEY on (creator_id, ticket_id)
    if ($_kc_creator_id) {
        $pdo->prepare("
            INSERT INTO total_ticketpageviews (creator_id, ticket_id, total_views, date_updated)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                total_views  = total_views + 1,
                date_updated = NOW()
        ")->execute([$_kc_creator_id, $event_id]);
    }

} catch (PDOException $e) {
    error_log("[Keychele] ticket visit tracking failed: " . $e->getMessage());
    return;
}


// ════════════════════════════════════════════════════════════════
//  NOTIFICATION LOGIC
// ════════════════════════════════════════════════════════════════

if (!$_kc_creator_id) return;

// ── 1. Check if creator has notifications enabled ────────────────
try {
    $ns_stmt = $pdo->prepare(
        "SELECT notify_ticket_views FROM creator_notification_settings WHERE creator_id = ?"
    );
    $ns_stmt->execute([$_kc_creator_id]);
    $ns_row = $ns_stmt->fetch(PDO::FETCH_ASSOC);
    $notify_enabled = ($ns_row === false) ? true : (bool)$ns_row['notify_ticket_views'];
} catch (PDOException $e) {
    error_log("[Keychele] notification settings fetch failed: " . $e->getMessage());
    return;
}

if (!$notify_enabled) return;

// ── 2. Cooldown check (PHP time used to avoid server timezone mismatch) ──
$_kc_now_ts      = time();
$_kc_cooldown_ts = $_kc_now_ts - (KC_VISIT_NOTIFY_COOLDOWN * 60);
try {
    $cd_stmt = $pdo->prepare("
        SELECT UNIX_TIMESTAMP(last_sent_at) AS sent_ts
        FROM ticket_visit_notification_log
        WHERE ticket_id = ?
    ");
    $cd_stmt->execute([$event_id]);
    $cd_row = $cd_stmt->fetch(PDO::FETCH_ASSOC);
    // Blocked only if the stored timestamp is WITHIN the cooldown window
    $already_notified = $cd_row && ((int)$cd_row['sent_ts'] >= $_kc_cooldown_ts);
} catch (PDOException $e) {
    error_log("[Keychele] notification cooldown check failed: " . $e->getMessage());
    $already_notified = true;
}

if ($already_notified) return;

// ── 3. Total views in the current cooldown window ────────────────
//       (includes the visit we just inserted)
try {
    $cnt_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM ticket_page_visits
        WHERE ticket_id = ?
          AND UNIX_TIMESTAMP(visited_at) >= ?
    ");
    $cnt_stmt->execute([$event_id, $_kc_cooldown_ts]);
    $recent_views = max(1, (int)$cnt_stmt->fetchColumn());
} catch (PDOException $e) {
    error_log("[Keychele] recent visit count failed: " . $e->getMessage());
    $recent_views = 1;
}

// ── 4. Build notification message ────────────────────────────────
$_kc_location = trim(
    implode(', ', array_filter([
        $_kc_city    !== 'Unknown' ? $_kc_city    : '',
        $_kc_country !== 'Unknown' ? $_kc_country : '',
    ])),
    ', '
);
if ($_kc_location === '') $_kc_location = 'an unknown location';

$_kc_event_title      = $event['title'] ?? 'your event';
$_kc_event_title_safe = htmlspecialchars($_kc_event_title);

// Message logic:
// 1 view  → "1 person viewed your ticket: EventName"
// 2 views → "1 person from Location and 1 other viewed: EventName"
// 6 views → "1 person from Location and 5 others viewed: EventName"
if ($recent_views === 1) {
    $msg_plain = "1 person from {$_kc_location} viewed your ticket: {$_kc_event_title}";
    $msg_html  = "1 person from <strong>{$_kc_location}</strong> viewed your ticket: <strong>{$_kc_event_title_safe}</strong>";
    $activity_label = "1 view in this window";
} else {
    $others     = $recent_views - 1;
    $others_lbl = $others === 1 ? '1 other' : "{$others} others";
    $msg_plain  = "1 person from {$_kc_location} and {$others_lbl} viewed your ticket: {$_kc_event_title}";
    $msg_html   = "1 person from <strong>{$_kc_location}</strong> and <strong>{$others_lbl}</strong> viewed your ticket: <strong>{$_kc_event_title_safe}</strong>";
    $activity_label = "{$recent_views} views in the last " . KC_VISIT_NOTIFY_COOLDOWN . " min";
}

// ── 5. Save in-app notification ──────────────────────────────────
try {
    $pdo->prepare("
        INSERT INTO creator_notifications (creator_id, ticket_id, message, type, is_read, created_at)
        VALUES (?, ?, ?, 'ticket_view', 0, NOW())
    ")->execute([$_kc_creator_id, $event_id, $msg_plain]);
} catch (PDOException $e) {
    error_log("[Keychele] creator_notifications insert failed: " . $e->getMessage());
}

// ── 6. Update cooldown log (use PHP time to avoid server timezone drift) ──
try {
    $pdo->prepare("
        INSERT INTO ticket_visit_notification_log (ticket_id, last_sent_at)
        VALUES (?, FROM_UNIXTIME(?))
        ON DUPLICATE KEY UPDATE last_sent_at = FROM_UNIXTIME(?)
    ")->execute([$event_id, $_kc_now_ts, $_kc_now_ts]);
} catch (PDOException $e) {
    error_log("[Keychele] notification cooldown log failed: " . $e->getMessage());
}

// ── 7. Send email notification to creator ────────────────────────
$_kc_creator_email = $event['creator_email'] ?? null;
if (!$_kc_creator_email || !filter_var($_kc_creator_email, FILTER_VALIDATE_EMAIL)) return;

$_kc_dev_icon      = kc_device_emoji($_kc_device);
$_kc_dashboard_url = 'https://keychele.co.ke/msanii/index.php?page=analytics';
$_kc_settings_url  = 'https://keychele.co.ke/msanii/index.php?page=notification_settings';
$_kc_event_url     = 'https://keychele.co.ke/ticket/' . $event_id;
$_kc_now_fmt       = date('D, d M Y · g:i A');

$_kc_email_html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background:#eef2fa;font-family:Montserrat,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#eef2fa;padding:32px 0;">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0"
       style="background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 8px 36px rgba(5,46,100,.18);max-width:100%;">

  <!-- Header -->
  <tr><td style="background:#052e64;padding:0;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="padding:24px 36px 18px;">
        <table width="100%" cellpadding="0" cellspacing="0"><tr>
          <td>
            <p style="margin:0;color:#fff;font-size:22px;font-weight:800;letter-spacing:3px;">KEYCHELE</p>
            <p style="margin:4px 0 0;color:#fec411;font-size:8px;font-weight:700;letter-spacing:2.5px;">CREATOR DASHBOARD ALERT</p>
          </td>
          <td align="right">
            <span style="background:#fec411;color:#052e64;font-size:10px;font-weight:800;padding:5px 14px;border-radius:20px;letter-spacing:1px;">
              👀 PAGE VIEW
            </span>
          </td>
        </tr></table>
      </td></tr>
      <tr><td style="background:#fec411;height:3px;"></td></tr>
    </table>
  </td></tr>

  <!-- Hero message -->
  <tr><td style="padding:30px 36px 18px;">
    <p style="font-size:32px;margin:0 0 12px;">👀</p>
    <p style="font-size:16px;font-weight:700;color:#052e64;margin:0 0 6px;line-height:1.5;">{$msg_html}</p>
    <p style="font-size:12px;color:#8a97b8;margin:0;">{$_kc_now_fmt}</p>
  </td></tr>

  <!-- Stats grid -->
  <tr><td style="padding:0 36px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0"
           style="border:1.5px solid #dde6f5;border-radius:12px;overflow:hidden;">

      <tr style="background:#f5f8ff;">
        <td colspan="2" style="padding:13px 18px;border-bottom:1px solid #dde6f5;">
          <p style="margin:0;font-size:8px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">EVENT</p>
          <p style="margin:5px 0 0;font-size:14px;color:#052e64;font-weight:700;">
            <a href="{$_kc_event_url}" style="color:#052e64;text-decoration:none;">{$_kc_event_title_safe}</a>
          </p>
        </td>
      </tr>

      <tr>
        <td style="padding:12px 18px;border-bottom:1px solid #dde6f5;border-right:1px solid #dde6f5;width:50%;">
          <p style="margin:0;font-size:8px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">LOCATION</p>
          <p style="margin:5px 0 0;font-size:13px;color:#052e64;font-weight:600;">📍 {$_kc_city}, {$_kc_country}</p>
        </td>
        <td style="padding:12px 18px;border-bottom:1px solid #dde6f5;">
          <p style="margin:0;font-size:8px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">DEVICE</p>
          <p style="margin:5px 0 0;font-size:13px;color:#052e64;font-weight:600;">{$_kc_dev_icon} {$_kc_device}</p>
        </td>
      </tr>

      <tr>
        <td style="padding:12px 18px;border-bottom:1px solid #dde6f5;border-right:1px solid #dde6f5;">
          <p style="margin:0;font-size:8px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">BROWSER</p>
          <p style="margin:5px 0 0;font-size:13px;color:#052e64;font-weight:600;">🌐 {$_kc_browser}</p>
        </td>
        <td style="padding:12px 18px;border-bottom:1px solid #dde6f5;">
          <p style="margin:0;font-size:8px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">OPERATING SYSTEM</p>
          <p style="margin:5px 0 0;font-size:13px;color:#052e64;font-weight:600;">💻 {$_kc_os}</p>
        </td>
      </tr>

      <tr style="background:#fffdf0;">
        <td colspan="2" style="padding:12px 18px;">
          <p style="margin:0;font-size:8px;color:#8a9ab8;letter-spacing:2px;font-weight:700;">ACTIVITY WINDOW</p>
          <p style="margin:5px 0 0;font-size:13px;color:#052e64;font-weight:600;">👥 {$activity_label}</p>
        </td>
      </tr>

    </table>
  </td></tr>

  <!-- CTA -->
  <tr><td style="padding:0 36px 30px;text-align:center;">
    <a href="{$_kc_dashboard_url}"
       style="display:inline-block;background:#052e64;color:#fff;padding:14px 34px;
              border-radius:10px;font-weight:800;font-size:13px;text-decoration:none;letter-spacing:.5px;">
      View Full Analytics →
    </a>
  </td></tr>

  <!-- Settings note -->
  <tr><td style="padding:0 36px 20px;">
    <div style="background:#f5f8ff;border-radius:8px;padding:12px 16px;border-left:4px solid #dde6f5;">
      <p style="margin:0;font-size:11px;color:#8a9ab8;line-height:1.6;text-align:center;">
        To stop these alerts, visit
        <a href="{$_kc_settings_url}" style="color:#052e64;font-weight:700;text-decoration:none;">Notification Settings</a>
        and turn off <em>Ticket Page View Alerts</em>.
      </p>
    </div>
  </td></tr>

  <!-- Footer -->
  <tr><td style="background:#052e64;padding:0;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="background:#fec411;height:3px;"></td></tr>
      <tr><td style="padding:16px 36px;text-align:center;">
        <p style="color:#4a6a99;font-size:11px;margin:0;">
          © Keychele · Powered by Mecrofex Technologies ·
          <a href="{$_kc_event_url}" style="color:#7a9acc;text-decoration:none;">View Event</a>
        </p>
      </td></tr>
    </table>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;

try {
    $kc_mail = new PHPMailer(true);
    $kc_mail->isSMTP();
    $kc_mail->Host        = 'mail.keychele.co.ke';
    $kc_mail->SMTPAuth    = true;
    $kc_mail->Username    = 'tickets@keychele.co.ke';
    $kc_mail->Password    = 'King20241064.';
    $kc_mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
    $kc_mail->Port        = 587;
    $kc_mail->SMTPOptions = ['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]];
    $kc_mail->setFrom('tickets@keychele.co.ke', 'Keychele Alerts');
    $kc_mail->addAddress($_kc_creator_email);
    $kc_mail->isHTML(true);
    $kc_mail->Subject = "👀 Someone just viewed: " . mb_strimwidth($_kc_event_title, 0, 50, '…');
    $kc_mail->Body    = $_kc_email_html;
    $kc_mail->AltBody = $msg_plain
        . "\n\nLocation: {$_kc_city}, {$_kc_country}"
        . "\nDevice: {$_kc_device}"
        . "\nBrowser: {$_kc_browser} on {$_kc_os}"
        . "\nTime: {$_kc_now_fmt}"
        . "\n\nDisable alerts: {$_kc_settings_url}";
    $kc_mail->send();
} catch (Exception $e) {
    error_log("[Keychele] Visit notification email failed: " . $kc_mail->ErrorInfo);
}