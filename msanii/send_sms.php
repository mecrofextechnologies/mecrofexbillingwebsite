<?php
// send_sms.php
ob_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/send_sms_debug.log');

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';
if (!file_exists($connect_path)) die("Database connection file missing.");
require_once $connect_path;

// ── Session & auth check ──
if (!isset($_SESSION['creator_id']) || !is_numeric($_SESSION['creator_id'])) {
    if (isset($_POST['action']) || isset($_GET['action'])) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }
    header("Location: login.php");
    exit;
}

$creator_id = (int)$_SESSION['creator_id'];
$event_id   = (int)($_GET['event_id'] ?? 0);
if ($event_id <= 0) die("Invalid event ID.");

// ── Get event & verify ownership ──
$stmt = $pdo->prepare("SELECT title, start_date, start_time, venue FROM tickets WHERE id = ? AND creator_id = ?");
$stmt->execute([$event_id, $creator_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) die("Event not found or access denied.");

// ── Get creator name ──
$creator_name = 'Event Organizer';
try {
    // Try common table names
    foreach (['creators', 'users', 'organizers'] as $tbl) {
        $tables = $pdo->query("SHOW TABLES LIKE '$tbl'")->fetchAll();
        if ($tables) {
            $row = $pdo->prepare("SELECT name FROM `$tbl` WHERE id = ? LIMIT 1");
            $row->execute([$creator_id]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if ($r && !empty($r['name'])) { $creator_name = $r['name']; break; }
        }
    }
} catch (Exception $e) { /* fallback */ }

// ── Get SMS settings ──
$sms_settings = null;
try {
    $sms_settings = $pdo->query("SELECT * FROM admin_sms_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* no settings */ }

// ── Ensure scheduled_sms table exists ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS scheduled_sms (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        event_id     INT NOT NULL,
        creator_id   INT NOT NULL,
        recipients   TEXT NOT NULL,
        message      TEXT NOT NULL,
        scheduled_at DATETIME NOT NULL,
        status       ENUM('pending','sent','failed') DEFAULT 'pending',
        sent_at      DATETIME NULL,
        error_msg    TEXT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event (event_id),
        INDEX idx_creator (creator_id),
        INDEX idx_status_scheduled (status, scheduled_at)
    )");
} catch (Exception $e) { /* already exists */ }

// ── Get ticket buyers (completed, with phone) ──
$stmt = $pdo->prepare("
    SELECT tp.id, tp.buyer_name, tp.buyer_phone, tp.ticket_number,
           COALESCE(ett.type_name, 'General') AS ticket_type
    FROM ticket_purchases tp
    JOIN tickets t ON tp.ticket_id = t.id
    LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id
    WHERE t.id = ? AND t.creator_id = ?
      AND tp.status = 'completed'
      AND tp.buyer_phone IS NOT NULL AND tp.buyer_phone != ''
    ORDER BY tp.buyer_name ASC
");
$stmt->execute([$event_id, $creator_id]);
$buyers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Helper: normalize phone ──
function normalizePhone(string $phone): string {
    $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
    if (str_starts_with($phone, '+')) $phone = substr($phone, 1);
    if (str_starts_with($phone, '0'))  $phone = '254' . substr($phone, 1);
    return $phone;
}

// ── Helper: send single SMS via TalkSasa ──
function sendTalkSasaSMS(array $settings, string $phone, string $message): array {
    // TalkSasa v3 uses Laravel Sanctum Bearer token auth — api_id/api_password go in header, not body
    // TalkSasa v3 — JSON body with Bearer auth
    $payload = json_encode([
        'sender_id' => $settings['sender_name'],
        'recipient' => $phone,
        'message'   => $message,
        'type'      => 'plain',
    ]);

    $ch = curl_init(rtrim($settings['api_endpoint'], '/') . '/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $settings['api_token'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // Log every attempt — shows full TalkSasa response including all validation errors
    $decoded = json_decode($resp, true);
    $full_errors = isset($decoded['errors']) ? json_encode($decoded['errors']) : ($decoded['message'] ?? 'no message');
    error_log("TalkSasa SMS [{$phone}] HTTP {$httpCode} | payload: {$payload} | response: {$resp} | errors: {$full_errors}");

    if ($curlErr) return ['success' => false, 'message' => 'Connection error: ' . $curlErr];
    $result = json_decode($resp, true);
    // TalkSasa v3 returns {"status":"success"} or {"status":"error","message":"..."}
    $ok = $httpCode === 200 && isset($result['status']) && strtolower($result['status']) === 'success';
    return [
        'success' => $ok,
        'message' => $result['message'] ?? ($ok ? 'Sent' : 'HTTP ' . $httpCode),
        'raw'     => $resp,
    ];
}

// ═══════════════════════════════════
// AJAX: Send SMS now
// ═══════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'send_sms') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!$sms_settings) {
        echo json_encode(['success' => false, 'message' => 'SMS settings not configured in admin panel']); exit;
    }

    $send_all      = (int)($_POST['send_all'] ?? 0);
    $recipient_ids = json_decode($_POST['recipient_ids'] ?? '[]', true);
    $message       = trim($_POST['message'] ?? '');
    $scheduled_at  = trim($_POST['scheduled_at'] ?? '');

    if (strlen($message) < 3) {
        echo json_encode(['success' => false, 'message' => 'Message is too short']); exit;
    }

    // Fetch recipients
    if ($send_all) {
        $stmt = $pdo->prepare("
            SELECT tp.id, tp.buyer_name, tp.buyer_phone FROM ticket_purchases tp
            JOIN tickets t ON tp.ticket_id = t.id
            WHERE t.id = ? AND t.creator_id = ? AND tp.status = 'completed'
              AND tp.buyer_phone IS NOT NULL AND tp.buyer_phone != ''
        ");
        $stmt->execute([$event_id, $creator_id]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        if (empty($recipient_ids)) {
            echo json_encode(['success' => false, 'message' => 'No recipients selected']); exit;
        }
        $placeholders = implode(',', array_fill(0, count($recipient_ids), '?'));
        $params       = array_merge(array_map('intval', $recipient_ids), [$creator_id, $event_id]);
        $stmt         = $pdo->prepare("
            SELECT tp.id, tp.buyer_name, tp.buyer_phone FROM ticket_purchases tp
            JOIN tickets t ON tp.ticket_id = t.id
            WHERE tp.id IN ($placeholders) AND t.creator_id = ? AND t.id = ? AND tp.status = 'completed'
        ");
        $stmt->execute($params);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($recipients)) {
        echo json_encode(['success' => false, 'message' => 'No valid recipients found']); exit;
    }

    // ── Scheduled ──
    if (!empty($scheduled_at)) {
        $ts = strtotime($scheduled_at);
        if (!$ts || $ts <= time()) {
            echo json_encode(['success' => false, 'message' => 'Scheduled time must be in the future']); exit;
        }
        $phones = array_map(fn($r) => normalizePhone($r['buyer_phone']), $recipients);
        $rec_json = json_encode(array_values(array_unique($phones)));
        $stmt = $pdo->prepare("INSERT INTO scheduled_sms (event_id, creator_id, recipients, message, scheduled_at) VALUES (?,?,?,?,?)");
        $stmt->execute([$event_id, $creator_id, $rec_json, $message, date('Y-m-d H:i:s', $ts)]);
        echo json_encode([
            'success'  => true,
            'message'  => 'SMS scheduled for ' . date('M j, Y g:i A', $ts),
            'count'    => count($phones),
            'schedule' => true,
        ]);
        exit;
    }

    // ── Send immediately ──
    $success = $failed = 0;
    $errors  = [];
    foreach ($recipients as $r) {
        $phone  = normalizePhone($r['buyer_phone']);
        $result = sendTalkSasaSMS($sms_settings, $phone, $message);
        if ($result['success']) {
            $success++;
        } else {
            $failed++;
            $errors[] = ($r['buyer_name'] ?: $phone) . ': ' . $result['message'];
            error_log("SMS send failed [{$phone}]: " . $result['message'] . ' | raw: ' . ($result['raw'] ?? ''));
        }
    }

    $msg = $success > 0 ? "Delivered to $success recipient(s)" : "No messages were sent";
    if ($failed > 0) $msg .= " ($failed failed)";
    echo json_encode(['success' => $success > 0, 'message' => $msg, 'errors' => $errors, 'sent' => $success, 'failed' => $failed]);
    exit;
}

// ═══════════════════════════════════
// AJAX: Cancel scheduled SMS
// ═══════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'cancel_scheduled') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $sms_id = (int)($_POST['sms_id'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM scheduled_sms WHERE id = ? AND creator_id = ? AND event_id = ? AND status = 'pending'");
    $stmt->execute([$sms_id, $creator_id, $event_id]);
    echo json_encode(['success' => (bool)$stmt->rowCount()]);
    exit;
}

// ── Fetch scheduled list for display ──
$stmt = $pdo->prepare("SELECT * FROM scheduled_sms WHERE event_id = ? AND creator_id = ? ORDER BY scheduled_at DESC LIMIT 20");
$stmt->execute([$event_id, $creator_id]);
$scheduled_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$event_date_formatted = date('l, d M Y', strtotime($event['start_date']));
$total_buyers         = count($buyers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Send SMS — <?= htmlspecialchars($event['title']) ?> | Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* ── Design Tokens (matches view_ticket_details) ── */
    :root {
      --navy:        #032d5d;
      --navy-light:  #0a3d7a;
      --crimson:     #b81236;
      --surface:     #ffffff;
      --bg:          #f0f4fa;
      --border:      #e2e8f0;
      --text-pri:    #0f172a;
      --text-sec:    #475569;
      --text-muted:  #94a3b8;
      --green:       #059669;
      --green-bg:    #d1fae5;
      --blue:        #2563eb;
      --blue-bg:     #dbeafe;
      --indigo:      #4f46e5;
      --indigo-bg:   #e0e7ff;
      --amber:       #d97706;
      --amber-bg:    #fef3c7;
      --red:         #dc2626;
      --red-bg:      #fee2e2;
      --purple:      #7c3aed;
      --purple-bg:   #ede9fe;
      --teal:        #0d9488;
      --gold:        #f59e0b;
      --radius-sm:   8px;
      --radius-md:   14px;
      --radius-lg:   20px;
      --shadow-sm:   0 1px 4px rgba(3,45,93,.07);
      --shadow-md:   0 4px 20px rgba(3,45,93,.10);
      --shadow-lg:   0 12px 40px rgba(3,45,93,.14);
      --transition:  all .22s cubic-bezier(.4,0,.2,1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Sora', sans-serif;
      background: var(--bg);
      color: var(--text-pri);
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
    }

    /* ── Preloader ── */
    #preloader {
      position: fixed; inset: 0; z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      background: rgba(3,45,93,.55); backdrop-filter: blur(6px);
      transition: opacity .4s ease;
    }
    .preloader-box {
      background: #fff; border-radius: var(--radius-lg);
      padding: 28px 40px; display: flex; align-items: center; gap: 14px;
      box-shadow: var(--shadow-lg);
    }
    .spin { width: 22px; height: 22px; border: 3px solid var(--border); border-top-color: var(--crimson); border-radius: 50%; animation: spin .75s linear infinite; flex-shrink: 0; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .preloader-box span { font-size: .9rem; font-weight: 600; color: var(--text-sec); }

    /* ── Topbar ── */
    .topbar {
      background: rgba(255,255,255,.97); backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border); padding: 12px 24px;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 40; box-shadow: var(--shadow-sm);
      gap: 12px; flex-wrap: wrap;
    }
    .back-btn { display: inline-flex; align-items: center; gap: 8px; color: var(--text-sec); text-decoration: none; font-size: .85rem; font-weight: 600; transition: var(--transition); }
    .back-btn:hover { color: var(--navy); }
    .back-icon { width: 34px; height: 34px; border-radius: 10px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: .75rem; transition: var(--transition); }
    .back-btn:hover .back-icon { background: #e2e8f0; }
    .topbar-title { font-size: .9rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 8px; }
    .topbar-title i { color: var(--crimson); }

    /* ── Page Wrap ── */
    .page-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px 100px; }
    @media (min-width: 768px) { .page-wrap { padding: 28px 28px 56px; } }

    /* ── Action Buttons ── */
    .action-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 16px; border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .78rem; font-weight: 600;
      cursor: pointer; border: none; text-decoration: none;
      transition: var(--transition); white-space: nowrap;
    }
    .btn-navy    { background: var(--navy);   color: #fff; }
    .btn-navy:hover    { background: var(--navy-light); box-shadow: 0 4px 14px rgba(3,45,93,.3); transform: translateY(-1px); }
    .btn-green   { background: var(--green);  color: #fff; }
    .btn-green:hover   { background: #047857; box-shadow: 0 4px 14px rgba(5,150,105,.3); transform: translateY(-1px); }
    .btn-crimson { background: var(--crimson); color: #fff; }
    .btn-crimson:hover { background: #9a0f2e; box-shadow: 0 4px 14px rgba(184,18,54,.3); transform: translateY(-1px); }
    .btn-teal    { background: var(--teal);   color: #fff; }
    .btn-teal:hover    { background: #0f766e; box-shadow: 0 4px 14px rgba(13,148,136,.3); transform: translateY(-1px); }
    .btn-amber   { background: var(--amber);  color: #fff; }
    .btn-amber:hover   { background: #b45309; box-shadow: 0 4px 14px rgba(217,119,6,.3); transform: translateY(-1px); }
    .btn-outline {
      background: transparent; color: var(--text-sec);
      border: 1.5px solid var(--border);
    }
    .btn-outline:hover { border-color: var(--navy); color: var(--navy); background: #f0f4fa; }
    .btn-disabled { background: #e2e8f0; color: var(--text-muted); cursor: not-allowed; pointer-events: none; }

    /* ── Hero ── */
    .hero {
      position: relative; overflow: hidden;
      background: linear-gradient(130deg, var(--navy) 0%, #0a3d7a 45%, var(--crimson) 100%);
      border-radius: var(--radius-lg); padding: 28px 32px; margin-bottom: 24px;
      box-shadow: var(--shadow-lg);
    }
    .hero::before {
      content: ''; position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
      pointer-events: none;
    }
    .hero-inner { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px; }
    .hero-eyebrow {
      display: inline-flex; align-items: center; gap: 7px;
      background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.2);
      backdrop-filter: blur(8px); border-radius: 999px;
      padding: 4px 14px; font-size: .72rem; font-weight: 600;
      color: #fff; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 8px;
    }
    .hero-title { font-size: clamp(1.1rem, 2.5vw, 1.7rem); font-weight: 800; color: #fff; letter-spacing: -.02em; }
    .hero-meta  { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 10px; }
    .hero-meta-item { display: flex; align-items: center; gap: 6px; font-size: .8rem; color: rgba(255,255,255,.75); }
    .hero-meta-item i { font-size: .68rem; opacity: .7; }
    .hero-stat { text-align: right; }
    .hero-stat-val { font-family: 'JetBrains Mono', monospace; font-size: 1.8rem; font-weight: 700; color: #fff; line-height: 1; }
    .hero-stat-lbl { font-size: .72rem; color: rgba(255,255,255,.55); margin-top: 4px; }

    /* ── No SMS Settings Warning ── */
    .warn-banner {
      background: linear-gradient(135deg, #78350f, #92400e);
      border-radius: var(--radius-md); padding: 16px 22px;
      display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;
      border: 1px solid rgba(251,191,36,.3);
    }
    .warn-banner-icon { width: 40px; height: 40px; border-radius: 11px; background: rgba(251,191,36,.15); border: 1.5px solid rgba(251,191,36,.35); display: flex; align-items: center; justify-content: center; font-size: 1rem; color: #fbbf24; flex-shrink: 0; }
    .warn-banner h4 { font-size: .88rem; font-weight: 700; color: #fff; margin-bottom: 3px; }
    .warn-banner p  { font-size: .76rem; color: rgba(255,255,255,.7); }

    /* ── Main Grid ── */
    .sms-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 20px;
    }
    @media (min-width: 1024px) {
      .sms-grid { grid-template-columns: 1fr 380px; }
    }

    /* ── Panel ── */
    .panel { background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-md); overflow: hidden; }
    .panel-header {
      padding: 18px 24px; border-bottom: 1px solid var(--border);
      background: #fafbfe; display: flex; align-items: center;
      justify-content: space-between; flex-wrap: wrap; gap: 10px;
    }
    .panel-title { font-size: .95rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 9px; }
    .panel-title i { color: var(--navy); }
    .panel-body { padding: 22px 24px; }

    /* ── Compose Area ── */
    .field-group { margin-bottom: 20px; }
    .field-label { font-size: .75rem; font-weight: 700; color: var(--text-sec); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
    .field-label i { font-size: .7rem; color: var(--text-muted); }

    /* Sender badge */
    .sender-badge {
      display: flex; align-items: center; gap: 10px;
      background: linear-gradient(135deg, #f0f4fa, #e8eef8);
      border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      padding: 11px 16px;
    }
    .sender-avatar {
      width: 36px; height: 36px; border-radius: 10px;
      background: linear-gradient(135deg, var(--navy), var(--crimson));
      display: flex; align-items: center; justify-content: center;
      font-size: .85rem; font-weight: 800; color: #fff; flex-shrink: 0;
      font-family: 'JetBrains Mono', monospace;
    }
    .sender-info { flex: 1; }
    .sender-name { font-size: .85rem; font-weight: 700; color: var(--text-pri); }
    .sender-sub  { font-size: .7rem; color: var(--text-muted); margin-top: 1px; }
    .sender-id-chip {
      background: var(--navy); color: #fff;
      font-family: 'JetBrains Mono', monospace; font-size: .68rem; font-weight: 600;
      padding: 3px 9px; border-radius: 6px; letter-spacing: .03em;
    }

    /* SMS Textarea */
    .sms-textarea {
      width: 100%; min-height: 130px; resize: vertical;
      padding: 13px 16px; border: 1.5px solid var(--border);
      border-radius: var(--radius-sm); font-family: 'Sora', sans-serif;
      font-size: .87rem; color: var(--text-pri); line-height: 1.6;
      background: var(--surface); outline: none; transition: border-color .2s, box-shadow .2s;
    }
    .sms-textarea:focus { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(3,45,93,.07); }
    .sms-textarea::placeholder { color: var(--text-muted); }

    /* Char counter */
    .char-counter-wrap { display: flex; align-items: center; justify-content: space-between; margin-top: 7px; flex-wrap: wrap; gap: 6px; }
    .char-counter { font-family: 'JetBrains Mono', monospace; font-size: .72rem; font-weight: 600; color: var(--text-muted); }
    .char-counter.warn    { color: var(--amber); }
    .char-counter.danger  { color: var(--red); }
    .sms-parts-info { font-size: .68rem; color: var(--text-muted); background: #f1f5f9; border-radius: 5px; padding: 2px 8px; }

    /* ── Schedule Toggle ── */
    .schedule-toggle-row {
      display: flex; align-items: center; gap: 12px;
      padding: 14px 16px; border-radius: var(--radius-sm);
      background: #f8fafc; border: 1.5px solid var(--border);
      cursor: pointer; transition: var(--transition); user-select: none;
    }
    .schedule-toggle-row:hover { border-color: var(--navy); background: #f0f4fa; }
    .toggle-switch {
      width: 42px; height: 24px; border-radius: 999px;
      background: #cbd5e1; position: relative; transition: background .2s; flex-shrink: 0;
    }
    .toggle-switch.on { background: var(--navy); }
    .toggle-switch::after {
      content: ''; position: absolute; top: 3px; left: 3px;
      width: 18px; height: 18px; border-radius: 50%;
      background: #fff; transition: transform .2s; box-shadow: 0 1px 4px rgba(0,0,0,.2);
    }
    .toggle-switch.on::after { transform: translateX(18px); }
    .schedule-toggle-label { flex: 1; }
    .schedule-toggle-label strong { font-size: .85rem; font-weight: 700; color: var(--text-pri); display: block; }
    .schedule-toggle-label span   { font-size: .73rem; color: var(--text-muted); }

    /* Schedule datetime input */
    .schedule-panel {
      margin-top: 12px; padding: 16px; border-radius: var(--radius-sm);
      background: var(--indigo-bg); border: 1.5px solid rgba(99,102,241,.25);
      display: none; animation: fadeUp .3s ease both;
    }
    .schedule-panel.open { display: block; }
    .datetime-input {
      width: 100%; padding: 11px 14px; border: 1.5px solid rgba(99,102,241,.35);
      border-radius: var(--radius-sm); font-family: 'Sora', sans-serif;
      font-size: .85rem; color: var(--text-pri); background: #fff;
      outline: none; transition: border-color .2s;
    }
    .datetime-input:focus { border-color: var(--indigo); box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
    .schedule-hint { font-size: .72rem; color: #4338ca; margin-top: 8px; display: flex; align-items: center; gap: 5px; }

    /* ── Send Button ── */
    .send-btn {
      width: 100%; padding: 14px; border-radius: var(--radius-md);
      font-family: 'Sora', sans-serif; font-size: .95rem; font-weight: 700;
      cursor: pointer; border: none; display: flex; align-items: center;
      justify-content: center; gap: 9px; transition: var(--transition);
      letter-spacing: .01em; position: relative; overflow: hidden;
    }
    .send-btn-immediate {
      background: linear-gradient(135deg, var(--navy) 0%, var(--crimson) 100%);
      color: #fff; box-shadow: 0 4px 20px rgba(184,18,54,.35);
    }
    .send-btn-immediate:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(184,18,54,.45); }
    .send-btn-schedule {
      background: linear-gradient(135deg, var(--indigo) 0%, #7c3aed 100%);
      color: #fff; box-shadow: 0 4px 20px rgba(79,70,229,.35);
    }
    .send-btn-schedule:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(79,70,229,.45); }
    .send-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }

    /* ── Recipients Panel ── */
    .rec-search-box { position: relative; margin-bottom: 12px; }
    .rec-search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: .78rem; }
    .rec-search-input {
      width: 100%; padding: 9px 14px 9px 34px;
      border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .82rem;
      background: var(--surface); outline: none; transition: border-color .2s;
    }
    .rec-search-input:focus { border-color: var(--navy); }

    /* Select All row */
    .select-all-row {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 14px; background: #f8fafc; border-radius: var(--radius-sm);
      border: 1.5px solid var(--border); margin-bottom: 10px; cursor: pointer;
      transition: var(--transition);
    }
    .select-all-row:hover { border-color: var(--navy); background: #f0f4fa; }
    .select-all-label { font-size: .82rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 8px; }
    .selected-count { font-family: 'JetBrains Mono', monospace; font-size: .72rem; font-weight: 700; color: var(--navy); background: var(--blue-bg); border-radius: 5px; padding: 2px 8px; }

    /* Recipient list */
    .rec-list { max-height: 340px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; }
    .rec-list::-webkit-scrollbar { width: 4px; }
    .rec-list::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 99px; }
    .rec-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

    .rec-item {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; border-radius: var(--radius-sm);
      border: 1.5px solid var(--border); cursor: pointer;
      transition: var(--transition); background: var(--surface);
    }
    .rec-item:hover  { border-color: var(--navy); background: #f8faff; }
    .rec-item.chosen { border-color: var(--navy); background: var(--blue-bg); }

    /* Custom checkbox */
    .rec-check {
      width: 18px; height: 18px; border-radius: 5px; flex-shrink: 0;
      border: 2px solid var(--border); background: var(--surface);
      display: flex; align-items: center; justify-content: center;
      transition: var(--transition); font-size: .65rem; color: transparent;
    }
    .rec-item.chosen .rec-check { background: var(--navy); border-color: var(--navy); color: #fff; }

    .rec-avatar {
      width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0;
      background: linear-gradient(135deg, var(--blue-bg), var(--indigo-bg));
      display: flex; align-items: center; justify-content: center;
      font-size: .75rem; font-weight: 800; color: var(--navy);
    }
    .rec-item.chosen .rec-avatar { background: linear-gradient(135deg, var(--navy), var(--navy-light)); color: #fff; }

    .rec-info { flex: 1; min-width: 0; }
    .rec-name { font-size: .82rem; font-weight: 600; color: var(--text-pri); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rec-sub  { font-size: .68rem; color: var(--text-muted); font-family: 'JetBrains Mono', monospace; margin-top: 1px; }
    .rec-type-badge { font-size: .63rem; font-weight: 700; padding: 2px 7px; border-radius: 999px; background: #f1f5f9; color: var(--text-sec); white-space: nowrap; }

    /* Empty recipients */
    .no-buyers { text-align: center; padding: 36px 20px; }
    .no-buyers i { font-size: 1.8rem; color: var(--text-muted); display: block; margin-bottom: 10px; }
    .no-buyers p { font-size: .82rem; color: var(--text-muted); }

    /* No search results */
    #noRecResults { display: none; text-align: center; padding: 24px; }
    #noRecResults i { font-size: 1.4rem; color: var(--text-muted); display: block; margin-bottom: 8px; }
    #noRecResults p { font-size: .8rem; color: var(--text-muted); }

    /* ── Scheduled SMS section ── */
    .sched-list { display: flex; flex-direction: column; gap: 10px; }
    .sched-card {
      background: var(--surface); border: 1.5px solid var(--border);
      border-radius: var(--radius-md); padding: 14px 16px;
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 12px; transition: var(--transition); position: relative; overflow: hidden;
    }
    .sched-card::before { content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 4px; }
    .sched-card.pending::before  { background: var(--indigo); }
    .sched-card.sent::before     { background: var(--green); }
    .sched-card.failed::before   { background: var(--red); }
    .sched-card:hover { box-shadow: var(--shadow-md); }
    .sched-card-left { flex: 1; min-width: 0; }
    .sched-time { font-family: 'JetBrains Mono', monospace; font-size: .75rem; font-weight: 600; color: var(--indigo); margin-bottom: 4px; display: flex; align-items: center; gap: 5px; }
    .sched-msg { font-size: .82rem; color: var(--text-pri); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 480px; margin-bottom: 5px; }
    .sched-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .sched-badge { font-size: .65rem; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
    .sched-badge.pending { background: var(--indigo-bg); color: #3730a3; }
    .sched-badge.sent    { background: var(--green-bg);  color: #14532d; }
    .sched-badge.failed  { background: var(--red-bg);    color: #7f1d1d; }
    .sched-recip { font-size: .7rem; color: var(--text-muted); }
    .sched-cancel-btn {
      width: 30px; height: 30px; border-radius: 8px; border: none;
      background: var(--red-bg); color: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-size: .68rem; cursor: pointer; flex-shrink: 0; transition: var(--transition);
    }
    .sched-cancel-btn:hover { background: #fecaca; }

    /* ── Stats row ── */
    .mini-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px; }
    .mini-stat { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; text-align: center; box-shadow: var(--shadow-sm); transition: var(--transition); }
    .mini-stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .mini-stat-val { font-size: 1.5rem; font-weight: 800; letter-spacing: -.03em; color: var(--text-pri); }
    .mini-stat-lbl { font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-top: 4px; }

    /* ── Preview Modal ── */
    .preview-overlay {
      position: fixed; inset: 0; z-index: 9000;
      background: rgba(3,45,93,.55); backdrop-filter: blur(6px);
      display: flex; align-items: center; justify-content: center;
      padding: 20px; opacity: 0; pointer-events: none; transition: opacity .25s;
    }
    .preview-overlay.open { opacity: 1; pointer-events: all; }
    .preview-modal {
      background: var(--surface); border-radius: var(--radius-lg);
      width: 100%; max-width: 520px; box-shadow: var(--shadow-lg);
      transform: translateY(20px); transition: transform .25s;
      overflow: hidden;
    }
    .preview-overlay.open .preview-modal { transform: none; }
    .preview-header { padding: 18px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .preview-header h3 { font-size: .95rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 8px; }
    .preview-header h3 i { color: var(--crimson); }
    .preview-close { width: 30px; height: 30px; border-radius: 8px; border: none; background: #f1f5f9; color: var(--text-sec); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: var(--transition); }
    .preview-close:hover { background: #e2e8f0; }
    .preview-body { padding: 22px; }
    .preview-phone-frame {
      background: #1c1c1e; border-radius: 20px; padding: 14px 16px;
      max-width: 300px; margin: 0 auto 18px;
    }
    .preview-sms-bubble {
      background: #4a8cf7; border-radius: 16px 16px 4px 16px;
      padding: 10px 14px; color: #fff; font-size: .85rem; line-height: 1.5;
      word-wrap: break-word;
    }
    .preview-sender-line { font-size: .68rem; color: #6b7280; margin-top: 8px; text-align: right; font-family: 'JetBrains Mono', monospace; }
    .preview-info { background: #f8fafc; border-radius: var(--radius-sm); padding: 12px 14px; font-size: .8rem; color: var(--text-sec); display: flex; flex-direction: column; gap: 6px; }
    .preview-info span strong { color: var(--text-pri); }
    .preview-footer { padding: 16px 22px; border-top: 1px solid var(--border); display: flex; gap: 10px; }

    /* ── Bottom Nav ── */
    .bottom-nav {
      position: fixed; bottom: 0; left: 0; right: 0;
      background: rgba(255,255,255,.96); backdrop-filter: blur(12px);
      border-top: 1px solid var(--border);
      display: flex; justify-content: space-around; align-items: center;
      padding: 8px 0 12px; z-index: 50;
    }
    @media (min-width: 768px) { .bottom-nav { display: none; } }
    .nav-item { display: flex; flex-direction: column; align-items: center; gap: 3px; text-decoration: none; color: var(--text-muted); font-size: .62rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; transition: color .2s; padding: 4px 12px; }
    .nav-item i { font-size: 1.1rem; }
    .nav-item.active { color: var(--crimson); }
    .nav-item:hover  { color: var(--navy); }

    /* ── Misc ── */
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
    .animate-up { animation: fadeUp .4s ease both; }
    .animate-up:nth-child(1) { animation-delay: .05s; }
    .animate-up:nth-child(2) { animation-delay: .10s; }
    .animate-up:nth-child(3) { animation-delay: .15s; }

    [data-tip] { position: relative; }
    [data-tip]:hover::after {
      content: attr(data-tip); position: absolute; bottom: 125%; left: 50%;
      transform: translateX(-50%); background: var(--text-pri); color: #fff;
      font-size: .68rem; padding: 4px 8px; border-radius: 6px; white-space: nowrap;
      z-index: 20; pointer-events: none;
    }

    .desk-only { display: none; } @media (min-width: 768px) { .desk-only { display: block; } }
    .divider { height: 1px; background: var(--border); margin: 18px 0; }
  </style>
</head>
<body>

<!-- Preloader -->
<div id="preloader">
  <div class="preloader-box">
    <div class="spin"></div>
    <span>Loading SMS composer…</span>
  </div>
</div>

<!-- ── Top Navigation Bar ── -->
<div class="topbar">
  <a href="view_ticket_details.php?event_id=<?= $event_id ?>" class="back-btn">
    <span class="back-icon"><i class="fas fa-arrow-left"></i></span>
    <span>Back to Tickets</span>
  </a>
  <div class="topbar-title">
    <i class="fas fa-comment-sms"></i>
    Send SMS — <?= htmlspecialchars($event['title']) ?>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="export_tickets_excel.php?event_id=<?= $event_id ?>" class="action-btn btn-outline" style="font-size:.75rem;padding:7px 13px">
      <i class="fas fa-file-excel"></i>
      <span class="desk-only" style="display:inline">Export Excel</span>
    </a>
    <a href="view_ticket_details.php?event_id=<?= $event_id ?>" class="action-btn btn-navy" style="font-size:.75rem;padding:7px 13px">
      <i class="fas fa-ticket-alt"></i>
      <span class="desk-only" style="display:inline">View Tickets</span>
    </a>
  </div>
</div>

<div class="page-wrap">

  <?php if (!$sms_settings): ?>
  <!-- No settings warning -->
  <div class="warn-banner animate-up">
    <div class="warn-banner-icon"><i class="fas fa-triangle-exclamation"></i></div>
    <div>
      <h4>SMS Provider Not Configured</h4>
      <p>No credentials found in <code>admin_sms_settings</code>. Please add your TalkSasa (or other provider) API credentials in the admin panel before sending SMS messages.</p>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Hero ── -->
  <div class="hero animate-up" style="margin-bottom:20px">
    <div class="hero-inner">
      <div>
        <div class="hero-eyebrow">
          <i class="fas fa-comment-sms" style="color:#fbbf24"></i>
          SMS Broadcast
        </div>
        <div class="hero-title"><?= htmlspecialchars($event['title']) ?></div>
        <div class="hero-meta">
          <?php if ($event['start_date']): ?>
          <span class="hero-meta-item"><i class="fas fa-calendar"></i><?= date('d M Y', strtotime($event['start_date'])) ?></span>
          <?php endif; ?>
          <span class="hero-meta-item"><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($event['venue'] ?: 'Venue TBA') ?></span>
          <?php if ($sms_settings): ?>
          <span class="hero-meta-item"><i class="fas fa-satellite-dish"></i><?= htmlspecialchars(ucfirst($sms_settings['provider'])) ?> &mdash; <?= htmlspecialchars($sms_settings['sender_name']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="hero-stat desk-only">
        <div class="hero-stat-val"><?= $total_buyers ?></div>
        <div class="hero-stat-lbl">SMS Recipients Available</div>
      </div>
    </div>
  </div>

  <!-- ── Mini Stats ── -->
  <div class="mini-stats">
    <div class="mini-stat animate-up">
      <div class="mini-stat-val"><?= $total_buyers ?></div>
      <div class="mini-stat-lbl">Available Recipients</div>
    </div>
    <div class="mini-stat animate-up">
      <div class="mini-stat-val" id="selectedCount">0</div>
      <div class="mini-stat-lbl">Selected</div>
    </div>
    <div class="mini-stat animate-up">
      <div class="mini-stat-val"><?= count($scheduled_list) ?></div>
      <div class="mini-stat-lbl">Scheduled</div>
    </div>
  </div>

  <!-- ── Main Grid ── -->
  <div class="sms-grid">

    <!-- LEFT: Compose -->
    <div>
      <div class="panel animate-up" style="margin-bottom:20px">
        <div class="panel-header">
          <div class="panel-title"><i class="fas fa-pen-to-square"></i> Compose Message</div>
        </div>
        <div class="panel-body">

          <!-- Sender -->
          <div class="field-group">
            <div class="field-label"><i class="fas fa-user-tie"></i> Sending From</div>
            <div class="sender-badge">
              <div class="sender-avatar"><?= strtoupper(substr($creator_name, 0, 2)) ?></div>
              <div class="sender-info">
                <div class="sender-name"><?= htmlspecialchars($creator_name) ?></div>
                <div class="sender-sub">via Keychele Ticketing</div>
              </div>
              <?php if ($sms_settings): ?>
              <div class="sender-id-chip"><?= htmlspecialchars($sms_settings['sender_name']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Message -->
          <div class="field-group">
            <div class="field-label"><i class="fas fa-message"></i> SMS Message</div>
            <textarea id="smsMessage" class="sms-textarea"
              placeholder="Type your message here… e.g. Hi [Name], don't forget <?= htmlspecialchars($event['title']) ?> is happening soon! See you there 🎉"></textarea>
            <div class="char-counter-wrap">
              <span class="char-counter" id="charCounter">0 / 160 characters</span>
              <span class="sms-parts-info" id="smsParts">1 SMS part</span>
            </div>
          </div>

          <!-- Schedule Toggle -->
          <div class="field-group">
            <div class="field-label"><i class="fas fa-clock"></i> Delivery</div>
            <div class="schedule-toggle-row" id="scheduleToggleRow" onclick="toggleSchedule()">
              <div class="toggle-switch" id="toggleSwitch"></div>
              <div class="schedule-toggle-label">
                <strong>Schedule for later</strong>
                <span>Toggle to pick a future date &amp; time</span>
              </div>
              <i class="fas fa-calendar-clock" style="color:var(--text-muted);font-size:.9rem"></i>
            </div>
            <div class="schedule-panel" id="schedulePanel">
              <label style="font-size:.75rem;font-weight:700;color:#3730a3;display:block;margin-bottom:8px">
                <i class="fas fa-calendar-check" style="margin-right:4px"></i>Select Date &amp; Time
              </label>
              <input type="datetime-local" id="scheduledAt" class="datetime-input"
                     min="<?= date('Y-m-d\TH:i', strtotime('+5 minutes')) ?>">
              <p class="schedule-hint">
                <i class="fas fa-circle-info"></i>
                Scheduled SMS will be queued and sent at the selected time. You can cancel before it sends.
              </p>
            </div>
          </div>

          <!-- Action Buttons -->
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button class="action-btn btn-outline" id="previewBtn" onclick="openPreview()" style="flex:1">
              <i class="fas fa-eye"></i> Preview
            </button>
            <button class="send-btn send-btn-immediate" id="sendBtn" onclick="confirmAndSend()" style="flex:2" <?= !$sms_settings ? 'disabled title="SMS settings not configured"' : '' ?>>
              <i class="fas fa-paper-plane"></i>
              <span id="sendBtnLabel">Send SMS Now</span>
            </button>
          </div>

        </div>
      </div>

      <!-- ── Scheduled SMS ── -->
      <?php if (!empty($scheduled_list)): ?>
      <div class="panel animate-up">
        <div class="panel-header">
          <div class="panel-title"><i class="fas fa-clock-rotate-left"></i> Scheduled SMS</div>
          <span style="font-size:.72rem;color:var(--text-muted);font-weight:600"><?= count($scheduled_list) ?> item(s)</span>
        </div>
        <div class="panel-body" style="padding:16px">
          <div class="sched-list">
            <?php foreach ($scheduled_list as $s):
              $phones = json_decode($s['recipients'], true);
              $recip_count = is_array($phones) ? count($phones) : '?';
              $st = $s['status'];
            ?>
            <div class="sched-card <?= $st ?>" id="scard-<?= $s['id'] ?>">
              <div class="sched-card-left">
                <div class="sched-time">
                  <i class="fas fa-calendar-clock"></i>
                  <?= date('M j, Y g:i A', strtotime($s['scheduled_at'])) ?>
                </div>
                <div class="sched-msg"><?= htmlspecialchars($s['message']) ?></div>
                <div class="sched-meta">
                  <span class="sched-badge <?= $st ?>"><?= ucfirst($st) ?></span>
                  <span class="sched-recip"><i class="fas fa-users" style="font-size:.62rem;margin-right:3px"></i><?= $recip_count ?> recipient(s)</span>
                  <span class="sched-recip"><i class="fas fa-clock" style="font-size:.62rem;margin-right:3px"></i>Created <?= date('M j, g:i A', strtotime($s['created_at'])) ?></span>
                </div>
              </div>
              <?php if ($st === 'pending'): ?>
              <button class="sched-cancel-btn" onclick="cancelScheduled(<?= $s['id'] ?>)"
                      data-tip="Cancel this scheduled SMS">
                <i class="fas fa-trash-can"></i>
              </button>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: Recipients -->
    <div>
      <div class="panel animate-up" style="position:sticky;top:74px">
        <div class="panel-header">
          <div class="panel-title"><i class="fas fa-users"></i> Recipients</div>
          <span class="selected-count" id="recPanelCount">0 selected</span>
        </div>
        <div class="panel-body" style="padding:16px">

          <?php if (empty($buyers)): ?>
          <div class="no-buyers">
            <i class="fas fa-user-slash"></i>
            <p>No completed ticket buyers with phone numbers found for this event.</p>
          </div>

          <?php else: ?>

          <!-- Search recipients -->
          <div class="rec-search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="recSearch" class="rec-search-input"
                   placeholder="Search by name or ticket #…" oninput="filterRecipients()">
          </div>

          <!-- Select All -->
          <div class="select-all-row" onclick="toggleSelectAll()">
            <div class="select-all-label">
              <div class="rec-check" id="selectAllCheck"><i class="fas fa-check"></i></div>
              Select All Attendees
            </div>
            <span style="font-size:.72rem;color:var(--text-muted)"><?= $total_buyers ?> people</span>
          </div>

          <!-- List -->
          <div class="rec-list" id="recList">
            <?php foreach ($buyers as $b):
              $initials = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice(explode(' ', trim($b['buyer_name'])), 0, 2))));
              $phone_masked = substr($b['buyer_phone'], 0, 4) . '****' . substr($b['buyer_phone'], -3);
            ?>
            <div class="rec-item" id="rec-<?= $b['id'] ?>"
                 data-id="<?= $b['id'] ?>"
                 data-name="<?= htmlspecialchars(strtolower($b['buyer_name']), ENT_QUOTES) ?>"
                 data-ticket="<?= htmlspecialchars(strtolower($b['ticket_number']), ENT_QUOTES) ?>"
                 onclick="toggleRecipient(this)">
              <div class="rec-check"><i class="fas fa-check"></i></div>
              <div class="rec-avatar"><?= htmlspecialchars($initials ?: '?') ?></div>
              <div class="rec-info">
                <div class="rec-name"><?= htmlspecialchars($b['buyer_name']) ?></div>
                <div class="rec-sub"><?= $phone_masked ?> &middot; #<?= htmlspecialchars($b['ticket_number']) ?></div>
              </div>
              <span class="rec-type-badge"><?= htmlspecialchars($b['ticket_type']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>

          <div id="noRecResults">
            <i class="fas fa-search-minus"></i>
            <p>No attendees match your search.</p>
          </div>

          <?php endif; ?>

        </div>
      </div>
    </div>

  </div><!-- /sms-grid -->
</div><!-- /page-wrap -->

<!-- ── Preview Modal ── -->
<div class="preview-overlay" id="previewOverlay" onclick="closePreviewOnBg(event)">
  <div class="preview-modal">
    <div class="preview-header">
      <h3><i class="fas fa-mobile-screen"></i> SMS Preview</h3>
      <button class="preview-close" onclick="closePreview()"><i class="fas fa-times"></i></button>
    </div>
    <div class="preview-body">
      <div class="preview-phone-frame">
        <div class="preview-sms-bubble" id="previewMsgText">Your message will appear here.</div>
        <div class="preview-sender-line" id="previewSenderLine">From: KEYCHELE</div>
      </div>
      <div class="preview-info">
        <span><strong>To:</strong> <span id="previewTo">—</span></span>
        <span><strong>Message length:</strong> <span id="previewLen">0</span> characters (<span id="previewParts">1</span> SMS part)</span>
        <span><strong>Sender ID:</strong> <?= htmlspecialchars($sms_settings['sender_name'] ?? 'KEYCHELE') ?></span>
        <span><strong>Delivery:</strong> <span id="previewDelivery">Immediately</span></span>
      </div>
    </div>
    <div class="preview-footer">
      <button class="action-btn btn-outline" onclick="closePreview()" style="flex:1"><i class="fas fa-arrow-left"></i> Edit</button>
      <button class="send-btn send-btn-immediate" onclick="closePreview();confirmAndSend()" style="flex:2;padding:11px" <?= !$sms_settings ? 'disabled' : '' ?>>
        <i class="fas fa-paper-plane"></i> Confirm &amp; Send
      </button>
    </div>
  </div>
</div>

<!-- Mobile Bottom Nav -->
<nav class="bottom-nav">
  <a href="index.php?page=dashboard" class="nav-item"><i class="fas fa-home"></i>Home</a>
  <a href="index.php?page=ticket_purchases" class="nav-item"><i class="fas fa-ticket-alt"></i>Tickets</a>
  <a href="view_ticket_details.php?event_id=<?= $event_id ?>" class="nav-item"><i class="fas fa-users"></i>Buyers</a>
  <a href="#" class="nav-item active"><i class="fas fa-comment-sms"></i>SMS</a>
  <a href="index.php?page=my_wallet" class="nav-item"><i class="fas fa-wallet"></i>Wallet</a>
</nav>

<script>
// ── Preloader ──
window.addEventListener('load', () => {
  const p = document.getElementById('preloader');
  p.style.opacity = '0';
  setTimeout(() => p.style.display = 'none', 400);
});

// ── State ──
let selectedIds  = new Set();
let isScheduled  = false;
const totalBuyers = <?= $total_buyers ?>;
const senderName  = <?= json_encode($sms_settings['sender_name'] ?? 'KEYCHELE') ?>;
const creatorName = <?= json_encode($creator_name) ?>;
const eventId     = <?= $event_id ?>;

// ── Character Counter ──
const msgTA    = document.getElementById('smsMessage');
const charCtr  = document.getElementById('charCounter');
const smsParts = document.getElementById('smsParts');

msgTA?.addEventListener('input', updateCounter);
function updateCounter() {
  const len   = msgTA.value.length;
  const parts = Math.ceil(len / 160) || 1;
  charCtr.textContent = `${len} / ${len > 160 ? (parts * 160) : 160} characters`;
  charCtr.className   = 'char-counter' + (len > 320 ? ' danger' : len > 160 ? ' warn' : '');
  smsParts.textContent = `${parts} SMS part${parts > 1 ? 's' : ''}`;
}

// ── Schedule Toggle ──
function toggleSchedule() {
  isScheduled = !isScheduled;
  const sw     = document.getElementById('toggleSwitch');
  const panel  = document.getElementById('schedulePanel');
  const btn    = document.getElementById('sendBtn');
  const lbl    = document.getElementById('sendBtnLabel');
  sw.classList.toggle('on', isScheduled);
  panel.classList.toggle('open', isScheduled);
  if (isScheduled) {
    btn.className  = 'send-btn send-btn-schedule';
    lbl.textContent = 'Schedule SMS';
  } else {
    btn.className  = 'send-btn send-btn-immediate';
    lbl.textContent = 'Send SMS Now';
  }
  <?php if (!$sms_settings): ?>
  btn.disabled = true;
  <?php endif; ?>
}

// ── Recipient toggle ──
function toggleRecipient(el) {
  const id = el.dataset.id;
  if (selectedIds.has(id)) { selectedIds.delete(id); el.classList.remove('chosen'); }
  else                     { selectedIds.add(id);    el.classList.add('chosen');    }
  syncSelectAll();
  updateSelectedCount();
}

function toggleSelectAll() {
  const items = document.querySelectorAll('#recList .rec-item:not([style*="none"])');
  const allChosen = [...items].every(i => i.classList.contains('chosen'));
  items.forEach(i => {
    const id = i.dataset.id;
    if (allChosen) { selectedIds.delete(id); i.classList.remove('chosen'); }
    else           { selectedIds.add(id);    i.classList.add('chosen'); }
  });
  syncSelectAll();
  updateSelectedCount();
}

function syncSelectAll() {
  const items    = document.querySelectorAll('#recList .rec-item:not([style*="none"])');
  const allChosen = items.length > 0 && [...items].every(i => i.classList.contains('chosen'));
  const saChk    = document.getElementById('selectAllCheck');
  if (saChk) {
    saChk.parentElement.parentElement.classList.toggle('chosen', allChosen);
    saChk.style.background   = allChosen ? 'var(--navy)' : '';
    saChk.style.borderColor  = allChosen ? 'var(--navy)' : '';
    saChk.style.color        = allChosen ? '#fff' : 'transparent';
  }
}

function updateSelectedCount() {
  const n = selectedIds.size;
  document.getElementById('selectedCount').textContent = n;
  document.getElementById('recPanelCount').textContent = n + ' selected';
}

// ── Filter recipients ──
function filterRecipients() {
  const q     = document.getElementById('recSearch').value.trim().toLowerCase();
  const items = document.querySelectorAll('#recList .rec-item');
  let vis = 0;
  items.forEach(el => {
    const match = !q || el.dataset.name.includes(q) || el.dataset.ticket.includes(q);
    el.style.display = match ? '' : 'none';
    if (match) vis++;
  });
  const noRes = document.getElementById('noRecResults');
  if (noRes) noRes.style.display = vis === 0 ? 'block' : 'none';
  syncSelectAll();
}

// ── Preview ──
function openPreview() {
  const msg  = msgTA?.value.trim() || '';
  if (!msg)  { Swal.fire({ icon: 'warning', title: 'No Message', text: 'Please type your SMS message first.', confirmButtonColor: '#b81236' }); return; }
  const n    = selectedIds.size;
  const sched = document.getElementById('scheduledAt')?.value;
  document.getElementById('previewMsgText').textContent   = msg;
  document.getElementById('previewSenderLine').textContent = 'From: ' + senderName;
  document.getElementById('previewTo').textContent        = n === 0 ? 'All ' + totalBuyers + ' attendees' : n + ' selected attendee(s)';
  document.getElementById('previewLen').textContent       = msg.length;
  document.getElementById('previewParts').textContent     = Math.ceil(msg.length / 160) || 1;
  document.getElementById('previewDelivery').textContent  = isScheduled && sched ? new Date(sched).toLocaleString() : 'Immediately';
  document.getElementById('previewOverlay').classList.add('open');
}
function closePreview()              { document.getElementById('previewOverlay').classList.remove('open'); }
function closePreviewOnBg(e)         { if (e.target === document.getElementById('previewOverlay')) closePreview(); }

// ── Confirm & Send ──
async function confirmAndSend() {
  const msg     = msgTA?.value.trim() || '';
  const sendAll = selectedIds.size === 0;
  const recList = sendAll ? [] : [...selectedIds];
  const sched   = isScheduled ? (document.getElementById('scheduledAt')?.value || '') : '';

  if (!msg) { Swal.fire({ icon: 'warning', title: 'Empty Message', text: 'Please type your SMS message.', confirmButtonColor: '#b81236' }); return; }
  if (totalBuyers === 0) { Swal.fire({ icon: 'error', title: 'No Recipients', text: 'There are no buyers with phone numbers for this event.', confirmButtonColor: '#b81236' }); return; }
  if (isScheduled && !sched) { Swal.fire({ icon: 'warning', title: 'No Schedule Time', text: 'Please select a date and time to schedule the SMS.', confirmButtonColor: '#b81236' }); return; }

  const toCount = sendAll ? totalBuyers : recList.length;
  if (toCount === 0) { Swal.fire({ icon: 'warning', title: 'No Recipients', text: 'Select at least one recipient or leave all unselected to send to everyone.', confirmButtonColor: '#b81236' }); return; }

  const confirm = await Swal.fire({
    icon:  isScheduled ? 'info' : 'question',
    title: isScheduled ? '📅 Schedule SMS?' : '📲 Send SMS Now?',
    html:  `<b>${msg.length > 80 ? msg.substr(0,80)+'…' : msg}</b><br><br>` +
           `<span style="font-size:.85rem;color:#475569">To: <b>${sendAll ? 'All ' + totalBuyers + ' attendees' : toCount + ' selected'}</b>` +
           (isScheduled ? `<br>Scheduled: <b>${new Date(sched).toLocaleString()}</b>` : '') + '</span>',
    showCancelButton: true,
    confirmButtonColor: isScheduled ? '#4f46e5' : '#b81236',
    confirmButtonText:  isScheduled ? '<i class="fas fa-calendar-check"></i> Schedule' : '<i class="fas fa-paper-plane"></i> Send Now',
    cancelButtonText:   'Cancel',
  });
  if (!confirm.isConfirmed) return;

  const btn = document.getElementById('sendBtn');
  btn.disabled  = true;
  btn.innerHTML = '<div class="spin" style="width:18px;height:18px;border-width:2px"></div> Sending…';

  try {
    const body = new URLSearchParams({
      action:        'send_sms',
      message:       msg,
      send_all:      sendAll ? '1' : '0',
      recipient_ids: JSON.stringify(recList),
      scheduled_at:  sched,
    });
    const res  = await fetch(location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    const data = await res.json();

    if (data.success) {
      if (data.schedule) {
        Swal.fire({
          icon: 'success',
          title: '📅 SMS Scheduled!',
          html: `<b>${data.message}</b><br><span style="font-size:.85rem;color:#475569">Recipients: ${data.count}</span>`,
          confirmButtonColor: '#4f46e5',
        }).then(() => location.reload());
      } else {
        Swal.fire({
          icon: 'success', title: '✅ SMS Sent!',
          html: `<b>${data.message}</b>` + (data.failed > 0 ? `<br><span style="font-size:.82rem;color:#d97706">${data.failed} failed</span>` : ''),
          confirmButtonColor: '#059669',
        });
        msgTA.value = '';
        updateCounter();
      }
    } else {
      const errHtml = data.errors?.length
        ? `<br><small style="color:#ef4444">${data.errors.slice(0,3).join('<br>')}</small>`
        : '';
      Swal.fire({ icon: 'error', title: 'Failed to Send', html: data.message + errHtml, confirmButtonColor: '#b81236' });
    }
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Request Failed', text: err.message, confirmButtonColor: '#b81236' });
  } finally {
    btn.disabled  = false;
    btn.innerHTML = `<i class="fas fa-${isScheduled ? 'calendar-check' : 'paper-plane'}"></i> <span id="sendBtnLabel">${isScheduled ? 'Schedule SMS' : 'Send SMS Now'}</span>`;
  }
}

// ── Cancel Scheduled SMS ──
async function cancelScheduled(id) {
  const confirm = await Swal.fire({
    icon: 'warning', title: 'Cancel Scheduled SMS?',
    text: 'This scheduled SMS will be removed and not sent.',
    showCancelButton: true,
    confirmButtonColor: '#dc2626', confirmButtonText: 'Yes, Cancel It',
    cancelButtonText: 'Keep It',
  });
  if (!confirm.isConfirmed) return;
  try {
    const res  = await fetch(location.href, {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'cancel_scheduled', sms_id: id }),
    });
    const data = await res.json();
    if (data.success) {
      const card = document.getElementById('scard-' + id);
      if (card) { card.style.opacity = '0'; card.style.transform = 'translateX(30px)'; setTimeout(() => card.remove(), 300); }
      Swal.fire({ icon: 'success', title: 'Cancelled', toast: true, position: 'top-end', timer: 2000, showConfirmButton: false });
    } else {
      Swal.fire({ icon: 'error', title: 'Could not cancel', text: 'It may have already been sent.', confirmButtonColor: '#b81236' });
    }
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Error', text: err.message, confirmButtonColor: '#b81236' });
  }
}

// Set datetime min to now + 5 min
(function () {
  const dt = document.getElementById('scheduledAt');
  if (dt) {
    const now = new Date(); now.setMinutes(now.getMinutes() + 5);
    dt.min = now.toISOString().slice(0, 16);
  }
})();
</script>
</body>
</html>
<?php ob_end_flush(); ?>