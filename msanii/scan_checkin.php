<?php
// scan_checkin.php  –  QR check-in + shareable helper links (no login required for helpers)
ob_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/scan_checkin_debug.log');

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';
require_once $connect_path;

// ── Auto-create scan_tokens table ─────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `scan_tokens` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_id`   INT UNSIGNED NOT NULL,
    `creator_id` INT UNSIGNED NOT NULL,
    `token`      VARCHAR(64)  NOT NULL UNIQUE,
    `label`      VARCHAR(100) NOT NULL DEFAULT 'Helper',
    `expires_at` DATETIME     NOT NULL,
    `max_scans`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
    `scans_used` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (`event_id`), INDEX (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Privacy masking helpers ────────────────────────────────────
function maskPhone(string $phone): string {
    $phone = trim($phone); $len = strlen($phone);
    if ($len < 6) return str_repeat('*', $len);
    return substr($phone, 0, 4) . str_repeat('*', max(1, $len - 7)) . substr($phone, -3);
}
function maskEmail(string $email): string {
    if (!str_contains($email, '@')) return '****';
    [$local, $domain] = explode('@', $email, 2);
    $llen = strlen($local);
    $ml   = $local[0] . str_repeat('*', max(1, $llen - 2)) . ($llen > 1 ? substr($local, -1) : '');
    $dot  = strrpos($domain, '.');
    $md   = ($dot !== false && $dot > 0)
        ? ($domain[0] . str_repeat('*', max(1, $dot - 1)) . substr($domain, $dot))
        : str_repeat('*', strlen($domain));
    return $ml . '@' . $md;
}

// ── Auth: Token (helper) OR Creator Session ────────────────────
$creator_id = null;
$token_mode = false;
$token_row  = null;
$token_str  = trim($_GET['token'] ?? $_POST['_token'] ?? '');

if (!empty($token_str)) {
    // ── Helper / token-based auth ──
    $stmt = $pdo->prepare("
        SELECT st.*, tk.title, tk.start_date, tk.start_time, tk.venue,
               tk.creator_id AS t_creator_id
        FROM scan_tokens st
        JOIN tickets tk ON st.event_id = tk.id
        WHERE st.token = ? AND st.is_active = 1 AND st.expires_at > NOW()
    ");
    $stmt->execute([$token_str]);
    $token_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token_row) {
        if (isset($_POST['action'])) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Invalid or expired scan link']);
            exit;
        }
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Link Expired — Keychele</title>
        <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:system-ui,sans-serif;background:#0b1628;color:#e8edf5;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;text-align:center}.box{max-width:380px}.icon{font-size:56px;margin-bottom:20px}h2{font-size:20px;margin-bottom:10px;color:#ef4444}p{color:#7a90b8;line-height:1.6;font-size:14px}</style>
        </head><body><div class="box"><div class="icon">🔗</div>
        <h2>Scan Link Expired or Invalid</h2>
        <p>This link is no longer active. Ask the event organizer to generate a new one.</p>
        </div></body></html>';
        exit;
    }

    if ($token_row['max_scans'] > 0 && $token_row['scans_used'] >= $token_row['max_scans']) {
        if (isset($_POST['action'])) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Scan limit reached for this link']);
            exit;
        }
        while (ob_get_level() > 0) ob_end_clean();
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Limit Reached — Keychele</title>
        <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:system-ui,sans-serif;background:#0b1628;color:#e8edf5;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;text-align:center}.box{max-width:380px}.icon{font-size:56px;margin-bottom:20px}h2{font-size:20px;margin-bottom:10px;color:#f59e0b}p{color:#7a90b8;line-height:1.6;font-size:14px}</style>
        </head><body><div class="box"><div class="icon">🚫</div>
        <h2>Scan Limit Reached</h2>
        <p>This link has used all its allowed check-ins (' . (int)$token_row['max_scans'] . '). Contact the event organizer for a new link.</p>
        </div></body></html>';
        exit;
    }

    $creator_id = (int)$token_row['t_creator_id'];
    $event_id   = (int)$token_row['event_id'];
    $token_mode = true;

} elseif (isset($_SESSION['creator_id']) && is_numeric($_SESSION['creator_id'])) {
    // ── Creator session auth ──
    $creator_id = (int)$_SESSION['creator_id'];
    $event_id   = (int)($_GET['event_id'] ?? 0);
    if ($event_id <= 0) {
        if (isset($_POST['action'])) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Invalid event ID']);
            exit;
        }
        die("Invalid event ID.");
    }
} else {
    if (isset($_POST['action'])) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }
    header("Location: login.php");
    exit;
}

// ── Load event ─────────────────────────────────────────────────
if (!$token_mode) {
    $stmt = $pdo->prepare("SELECT title, start_date, start_time, venue FROM tickets WHERE id = ? AND creator_id = ?");
    $stmt->execute([$event_id, $creator_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) die("Event not found or access denied.");
} else {
    $event = [
        'title'      => $token_row['title'],
        'start_date' => $token_row['start_date'],
        'start_time' => $token_row['start_time'],
        'venue'      => $token_row['venue'],
    ];
}

// ── Initial stats ──────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_purchases WHERE ticket_id = ? AND is_checked_in = 1 AND status = 'completed'");
$stmt->execute([$event_id]);
$initial_checked_in = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_purchases WHERE ticket_id = ? AND status = 'completed'");
$stmt->execute([$event_id]);
$total_sold = (int)$stmt->fetchColumn();

// ── AJAX: create_token (creator only) ──────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'create_token') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if ($token_mode) { echo json_encode(['success' => false, 'message' => 'Not authorized']); exit; }

    $label      = substr(trim($_POST['label'] ?? 'Helper'), 0, 100) ?: 'Helper';
    $expires_h  = max(1, min(168, (int)($_POST['expires_hours'] ?? 12)));
    $max_scans  = max(0, (int)($_POST['max_scans'] ?? 0));

    $token      = bin2hex(random_bytes(24)); // 48-char secure token
    $expires_at = date('Y-m-d H:i:s', time() + $expires_h * 3600);

    $stmt = $pdo->prepare("INSERT INTO scan_tokens (event_id, creator_id, token, label, expires_at, max_scans) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$event_id, $creator_id, $token, $label, $expires_at, $max_scans]);

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base  = $proto . '://' . $_SERVER['HTTP_HOST'];
    $path  = strtok($_SERVER['REQUEST_URI'], '?');
    $url   = $base . $path . '?event_id=' . $event_id . '&token=' . $token;

    echo json_encode([
        'success'    => true,
        'id'         => (int)$pdo->lastInsertId(),
        'token'      => $token,
        'url'        => $url,
        'label'      => $label,
        'expires_at' => $expires_at,
        'max_scans'  => $max_scans,
    ]);
    exit;
}

// ── AJAX: list_tokens (creator only) ───────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'list_tokens') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if ($token_mode) { echo json_encode(['success' => false, 'message' => 'Not authorized']); exit; }

    $stmt = $pdo->prepare("
        SELECT id, label, expires_at, max_scans, scans_used, created_at
        FROM scan_tokens
        WHERE event_id = ? AND creator_id = ? AND is_active = 1 AND expires_at > NOW()
        ORDER BY created_at DESC LIMIT 20
    ");
    $stmt->execute([$event_id, $creator_id]);
    echo json_encode(['success' => true, 'tokens' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── AJAX: revoke_token (creator only) ──────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'revoke_token') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if ($token_mode) { echo json_encode(['success' => false, 'message' => 'Not authorized']); exit; }

    $tid = (int)($_POST['token_id'] ?? 0);
    if ($tid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid token ID']); exit; }
    $pdo->prepare("UPDATE scan_tokens SET is_active = 0 WHERE id = ? AND creator_id = ? AND event_id = ?")
        ->execute([$tid, $creator_id, $event_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── AJAX: verify_qr (both modes) ───────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'verify_qr') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $raw = trim($_POST['qr_data'] ?? '');
    if (empty($raw)) { echo json_encode(['success' => false, 'message' => 'Empty QR data']); exit; }

    $payload       = json_decode($raw, true);
    $ticket_number = $payload['ticket'] ?? $raw;

    $stmt = $pdo->prepare("
        SELECT tp.id, tp.ticket_number, tp.buyer_name, tp.buyer_email, tp.buyer_phone,
               tp.amount_paid, tp.quantity, tp.status, tp.is_checked_in,
               tp.mpesa_receipt, tp.created_at,
               t.title AS event_title, t.start_date, t.start_time, t.venue,
               ett.type_name AS ticket_type
        FROM ticket_purchases tp
        JOIN tickets t ON tp.ticket_id = t.id
        LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id
        WHERE tp.ticket_number = ? AND t.creator_id = ? AND t.id = ?
    ");
    $stmt->execute([$ticket_number, $creator_id, $event_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found or does not belong to this event']);
        exit;
    }

    // Always mask contact info for helpers
    if ($token_mode) {
        $ticket['buyer_email'] = !empty($ticket['buyer_email']) ? maskEmail($ticket['buyer_email']) : '';
        $ticket['buyer_phone'] = !empty($ticket['buyer_phone']) ? maskPhone($ticket['buyer_phone']) : '';
    }

    if ($ticket['status'] !== 'completed') {
        echo json_encode([
            'success' => false,
            'message' => 'Payment not completed — ticket is ' . strtoupper($ticket['status']),
            'ticket'  => $ticket,
            'status'  => 'invalid',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'ticket'  => $ticket,
        'status'  => $ticket['is_checked_in'] ? 'already_checked_in' : 'valid',
    ]);
    exit;
}

// ── AJAX: confirm_checkin (both modes) ─────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'confirm_checkin') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Re-check limit before confirming
    if ($token_mode && $token_row['max_scans'] > 0 && $token_row['scans_used'] >= $token_row['max_scans']) {
        echo json_encode(['success' => false, 'message' => 'Scan limit reached for this link']); exit;
    }

    $purchase_id = (int)($_POST['purchase_id'] ?? 0);
    if ($purchase_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid purchase ID']); exit; }

    $stmt = $pdo->prepare("
        SELECT tp.is_checked_in, tp.status, tp.buyer_name
        FROM ticket_purchases tp
        JOIN tickets t ON tp.ticket_id = t.id
        WHERE tp.id = ? AND t.creator_id = ? AND t.id = ?
    ");
    $stmt->execute([$purchase_id, $creator_id, $event_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row)                            { echo json_encode(['success' => false, 'message' => 'Ticket not found']); exit; }
    if ($row['status'] !== 'completed')   { echo json_encode(['success' => false, 'message' => 'Cannot check in — payment not completed']); exit; }
    if ((int)$row['is_checked_in'] === 1) { echo json_encode(['success' => true,  'already' => true,  'message' => 'Already checked in']); exit; }

    $pdo->prepare("UPDATE ticket_purchases SET is_checked_in = 1, updated_at = NOW() WHERE id = ?")
        ->execute([$purchase_id]);

    // Track usage against token
    if ($token_mode) {
        $pdo->prepare("UPDATE scan_tokens SET scans_used = scans_used + 1 WHERE token = ?")
            ->execute([$token_str]);
    }

    echo json_encode(['success' => true, 'already' => false, 'buyer_name' => $row['buyer_name']]);
    exit;
}

// ── Build VERIFY_URL for JS ────────────────────────────────────
$verify_url_js = $token_mode
    ? 'scan_checkin.php?event_id=' . $event_id . '&token=' . urlencode($token_str)
    : 'scan_checkin.php?event_id=' . $event_id;

// Token expiry info for JS
$token_expires_ts = $token_mode ? strtotime($token_row['expires_at']) : 0;
$token_max_scans  = $token_mode ? (int)$token_row['max_scans']  : 0;
$token_scans_used = $token_mode ? (int)$token_row['scans_used'] : 0;
$token_label_safe = $token_mode ? htmlspecialchars($token_row['label']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Scan & Check In — <?= htmlspecialchars($event['title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<style>
/* ── Reset & tokens ─────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
img, video, canvas { display: block; }

:root {
    --navy:      #032d5d;
    --navy-m:    #0a4a99;
    --navy-l:    #d6e4f7;
    --gold:      #fec411;
    --gold-d:    #c9990a;
    --crimson:   #b81236;
    --green:     #10b981;
    --green-d:   #059669;
    --green-l:   #d1fae5;
    --amber:     #f59e0b;
    --amber-l:   #fef3c7;
    --red:       #ef4444;
    --red-l:     #fee2e2;
    --indigo:    #6366f1;
    --teal:      #14b8a6;
    --bg:        #0b1628;
    --bg2:       #112040;
    --card:      #162b50;
    --border:    rgba(255,255,255,0.09);
    --text:      #e8edf5;
    --text-dim:  #7a90b8;
    --mono:      'JetBrains Mono', monospace;
    --sans:      'Space Grotesk', system-ui, sans-serif;
    --radius:    16px;
    --shadow:    0 8px 32px rgba(0,0,0,0.45);
}

html { height: 100%; }
body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    min-height: 100%;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
}

/* ── Topbar ─────────────────────────────────────────────────── */
.topbar {
    position: sticky; top: 0; z-index: 50;
    background: rgba(11,22,40,0.94);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 0 16px;
    height: 60px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px;
}
.topbar-logo { font-weight: 800; font-size: 17px; letter-spacing: 2px; color: #fff; white-space: nowrap; }
.topbar-logo span { color: var(--gold); }
.topbar-back {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 13px; font-weight: 600; color: var(--text-dim);
    text-decoration: none; transition: color .2s; flex-shrink: 0;
}
.topbar-back:hover { color: var(--text); }
.topbar-event {
    font-size: 12px; font-weight: 600; color: var(--text-dim);
    max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.topbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

/* Share button */
.btn-share-top {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 14px; border-radius: 10px;
    background: rgba(99,102,241,.18); border: 1px solid rgba(99,102,241,.35);
    color: #a5b4fc; font-family: var(--sans); font-size: 13px; font-weight: 700;
    cursor: pointer; transition: all .2s; white-space: nowrap;
}
.btn-share-top:hover { background: rgba(99,102,241,.28); color: #c7d2fe; }
.btn-share-top i { font-size: 12px; }

/* ── Helper mode banner ─────────────────────────────────────── */
.helper-banner {
    background: linear-gradient(135deg, rgba(20,184,166,.15) 0%, rgba(99,102,241,.12) 100%);
    border-bottom: 1px solid rgba(20,184,166,.25);
    padding: 10px 16px;
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.hb-icon {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    background: rgba(20,184,166,.2); border: 1px solid rgba(20,184,166,.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; color: var(--teal);
}
.hb-info { flex: 1; min-width: 160px; }
.hb-label { font-size: 13px; font-weight: 700; color: var(--text); }
.hb-meta  { font-size: 11px; color: var(--text-dim); margin-top: 2px; }
.hb-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.hb-chip {
    font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase;
    padding: 3px 9px; border-radius: 99px;
}
.hb-chip.teal   { background: rgba(20,184,166,.15); color: var(--teal); border: 1px solid rgba(20,184,166,.25); }
.hb-chip.amber  { background: rgba(245,158,11,.15);  color: var(--amber); border: 1px solid rgba(245,158,11,.25); }
.hb-chip.indigo { background: rgba(99,102,241,.15);  color: #a5b4fc; border: 1px solid rgba(99,102,241,.25); }
.hb-countdown { font-family: var(--mono); font-size: 12px; font-weight: 700; color: var(--amber); flex-shrink: 0; }

/* ── Layout ─────────────────────────────────────────────────── */
.page { max-width: 520px; margin: 0 auto; padding: 20px 14px 80px; }

/* ── Scanner card ──────────────────────────────────────────── */
.scanner-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 24px; overflow: hidden; box-shadow: var(--shadow); margin-bottom: 16px;
}
.scanner-head {
    padding: 16px 20px 12px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.scanner-head h2 { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 9px; }
.scanner-head h2 i { color: var(--gold); font-size: 14px; }
.scanner-status {
    font-size: 11px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase;
    padding: 4px 10px; border-radius: 99px;
}
.ss-idle  { background: rgba(255,255,255,.08); color: var(--text-dim); }
.ss-scan  { background: rgba(254,196,17,.15);  color: var(--gold); }
.ss-found { background: rgba(16,185,129,.15);  color: var(--green); }
.ss-error { background: rgba(239,68,68,.15);   color: var(--red); }
.ss-warn  { background: rgba(245,158,11,.15);  color: var(--amber); }

/* Video viewport */
.cam-wrap { position: relative; background: #000; aspect-ratio: 1/1; overflow: hidden; }
.cam-video { width: 100%; height: 100%; object-fit: cover; }
.cam-canvas { display: none; }

/* Scan frame overlay */
.scan-frame { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.sf-box { width: 62%; aspect-ratio: 1/1; position: relative; }
.sf-corner { position: absolute; width: 28px; height: 28px; border-color: var(--gold); border-style: solid; }
.sf-corner.tl { top:0; left:0;  border-width: 3px 0 0 3px; border-radius: 6px 0 0 0; }
.sf-corner.tr { top:0; right:0; border-width: 3px 3px 0 0; border-radius: 0 6px 0 0; }
.sf-corner.bl { bottom:0; left:0;  border-width: 0 0 3px 3px; border-radius: 0 0 0 6px; }
.sf-corner.br { bottom:0; right:0; border-width: 0 3px 3px 0; border-radius: 0 0 6px 0; }
.scan-line {
    position: absolute; left: 10%; right: 10%; height: 2px;
    background: linear-gradient(to right, transparent, var(--gold), transparent);
    box-shadow: 0 0 8px 1px var(--gold);
    animation: scanMove 2s ease-in-out infinite; opacity: 0;
}
.scan-line.active { opacity: 1; }
@keyframes scanMove { 0%,100% { top:10%; } 50% { top:88%; } }

.cam-dim {
    position: absolute; inset: 0; background: rgba(11,22,40,0.7);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 12px; transition: opacity .3s;
}
.cam-dim.hide { opacity: 0; pointer-events: none; }

.cam-controls { padding: 14px 18px; display: flex; gap: 10px; }

/* ── Buttons ───────────────────────────────────────────────── */
.btn {
    flex: 1; padding: 13px 16px; border: none; border-radius: 12px;
    font-family: var(--sans); font-size: 14px; font-weight: 700;
    cursor: pointer; transition: all .2s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn:disabled { opacity: .4; pointer-events: none; }
.btn-start { background: var(--gold); color: var(--navy); }
.btn-start:hover  { background: var(--gold-d); transform: translateY(-1px); }
.btn-stop  { background: rgba(255,255,255,.08); color: var(--text); border: 1px solid var(--border); }
.btn-stop:hover   { background: rgba(255,255,255,.14); }
.btn-flip  { flex: 0 0 48px; padding: 0; background: rgba(255,255,255,.08); color: var(--text); border: 1px solid var(--border); border-radius: 12px; }
.btn-flip:hover { background: rgba(255,255,255,.14); }

/* ── Result panel ──────────────────────────────────────────── */
.result-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 24px; overflow: hidden; box-shadow: var(--shadow);
    margin-bottom: 16px; display: none;
}
.result-card.show { display: block; }
.result-head { padding: 16px 20px; display: flex; align-items: center; gap: 12px; }
.rh-icon {
    width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 20px;
}
.rh-valid   { background: rgba(16,185,129,.18); color: var(--green); }
.rh-warn    { background: rgba(245,158,11,.18);  color: var(--amber); }
.rh-invalid { background: rgba(239,68,68,.18);   color: var(--red); }
.rh-title { font-size: 16px; font-weight: 800; }
.rh-sub   { font-size: 12px; color: var(--text-dim); margin-top: 2px; }
.r-divider { border: none; border-top: 1px solid var(--border); margin: 0; }
.r-body  { padding: 16px 20px; }
.r-grid  { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.r-row   { display: flex; flex-direction: column; gap: 3px; }
.r-row.full { grid-column: 1 / -1; }
.r-lbl   { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text-dim); }
.r-val   { font-size: 14px; font-weight: 600; color: var(--text); }
.r-val.mono  { font-family: var(--mono); color: var(--gold); font-size: 13px; }
.r-val.green { color: var(--green); }
.r-val.red   { color: var(--red); }
.r-val.amber { color: var(--amber); }
.amount-tag { display: inline-block; background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.3); color: var(--green); font-weight: 800; font-size: 16px; padding: 6px 14px; border-radius: 10px; }
.amount-tag.free { background: rgba(100,116,139,.15); border-color: rgba(100,116,139,.3); color: var(--text-dim); }
.r-actions { padding: 0 20px 20px; display: flex; gap: 10px; }
.btn-checkin {
    flex: 1; padding: 15px; background: var(--green); color: #fff;
    border: none; border-radius: 14px; font-family: var(--sans); font-size: 15px; font-weight: 800;
    cursor: pointer; transition: all .2s; display: flex; align-items: center; justify-content: center; gap: 9px;
}
.btn-checkin:hover:not(:disabled) { background: var(--green-d); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(16,185,129,.35); }
.btn-checkin:disabled { opacity: .4; pointer-events: none; }
.btn-checkin.already { background: rgba(16,185,129,.12); color: var(--green); border: 1.5px solid var(--green); cursor: default; }
.btn-rescan {
    flex: 0 0 auto; padding: 15px 18px;
    background: rgba(255,255,255,.06); color: var(--text);
    border: 1px solid var(--border); border-radius: 14px;
    font-family: var(--sans); font-size: 14px; font-weight: 700;
    cursor: pointer; transition: all .2s; display: flex; align-items: center; gap: 7px;
}
.btn-rescan:hover { background: rgba(255,255,255,.11); }

/* ── Stats bar ─────────────────────────────────────────────── */
.stats-bar {
    background: var(--card); border: 1px solid var(--border); border-radius: 16px;
    padding: 14px 20px; display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 8px; margin-bottom: 16px; text-align: center;
}
.sb-item {}
.sb-val  { font-size: 22px; font-weight: 800; color: var(--text); }
.sb-lbl  { font-size: 10px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; color: var(--text-dim); margin-top: 2px; }
.sb-val.gold    { color: var(--gold); }
.sb-val.green   { color: var(--green); }
.sb-val.crimson { color: var(--crimson); }

/* ── Active tokens panel ───────────────────────────────────── */
.tokens-panel {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 20px; overflow: hidden; margin-bottom: 16px;
}
.tokens-head {
    padding: 14px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    cursor: pointer; user-select: none;
}
.tokens-head h3 {
    font-size: 13px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1px; color: var(--text-dim); display: flex; align-items: center; gap: 8px;
}
.tokens-head h3 i { color: var(--indigo); }
.tokens-toggle { font-size: 12px; color: var(--text-dim); transition: transform .25s; }
.tokens-toggle.open { transform: rotate(180deg); }
.tokens-body { display: none; }
.tokens-body.open { display: block; }
.tokens-list { max-height: 280px; overflow-y: auto; }
.token-item {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 20px; border-bottom: 1px solid var(--border);
    animation: slideIn .25s ease;
}
.token-item:last-child { border-bottom: none; }
.ti-icon {
    width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
    background: rgba(99,102,241,.15); border: 1px solid rgba(99,102,241,.25);
    display: flex; align-items: center; justify-content: center; font-size: 14px; color: #a5b4fc;
}
.ti-info { flex: 1; min-width: 0; }
.ti-label { font-size: 14px; font-weight: 700; color: var(--text); }
.ti-meta  { font-size: 11px; color: var(--text-dim); margin-top: 3px; display: flex; gap: 10px; flex-wrap: wrap; }
.ti-scans { font-family: var(--mono); font-weight: 700; }
.ti-scans.full { color: var(--red); }
.btn-revoke {
    flex-shrink: 0; padding: 6px 12px; border-radius: 8px;
    background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.25);
    color: var(--red); font-family: var(--sans); font-size: 12px; font-weight: 700;
    cursor: pointer; transition: all .2s;
}
.btn-revoke:hover { background: rgba(239,68,68,.22); }
.tokens-copy-btn {
    display: flex; align-items: center; gap: 6px;
    padding: 5px 10px; border-radius: 7px;
    background: rgba(99,102,241,.12); border: 1px solid rgba(99,102,241,.2);
    color: #a5b4fc; font-family: var(--sans); font-size: 11px; font-weight: 700;
    cursor: pointer; transition: all .2s; margin-right: 6px;
}
.tokens-copy-btn:hover { background: rgba(99,102,241,.22); }
.ti-actions { display: flex; align-items: center; }
.tokens-empty { padding: 28px; text-align: center; color: var(--text-dim); font-size: 13px; }
.tokens-add-row { padding: 14px 20px; border-top: 1px solid var(--border); }

/* ── Share modal ───────────────────────────────────────────── */
.modal-overlay {
    position: fixed; inset: 0; z-index: 200;
    background: rgba(5,14,30,0.82); backdrop-filter: blur(8px);
    display: flex; align-items: flex-end; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity .25s;
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
@media (min-width: 560px) {
    .modal-overlay { align-items: center; }
    .modal-sheet { border-radius: 24px !important; max-height: 90vh; }
}
.modal-sheet {
    background: var(--bg2); border: 1px solid var(--border);
    border-radius: 24px 24px 0 0;
    width: 100%; max-width: 480px; max-height: 92vh;
    overflow-y: auto; padding: 0;
    transform: translateY(40px); transition: transform .3s cubic-bezier(.32,0,.67,0);
    box-shadow: 0 -12px 60px rgba(0,0,0,.6);
}
.modal-overlay.open .modal-sheet { transform: translateY(0); }
.modal-header {
    padding: 20px 22px 16px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; background: var(--bg2); z-index: 2;
}
.modal-header h2 { font-size: 16px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
.modal-header h2 i { color: var(--indigo); }
.modal-close { width: 32px; height: 32px; border-radius: 9px; border: none; background: rgba(255,255,255,.08); color: var(--text-dim); cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: all .2s; }
.modal-close:hover { background: rgba(255,255,255,.15); color: var(--text); }
.modal-body { padding: 20px 22px; }
.form-group { margin-bottom: 18px; }
.form-label { font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--text-dim); display: block; margin-bottom: 8px; }
.form-input, .form-select {
    width: 100%; padding: 12px 14px; background: rgba(255,255,255,.06);
    border: 1.5px solid var(--border); border-radius: 12px;
    font-family: var(--sans); font-size: 14px; font-weight: 600; color: var(--text);
    outline: none; transition: border-color .2s, box-shadow .2s; appearance: none;
}
.form-input:focus, .form-select:focus { border-color: var(--indigo); box-shadow: 0 0 0 3px rgba(99,102,241,.15); }
.form-input::placeholder { color: var(--text-dim); font-weight: 400; }
.form-select option { background: #1a2f52; color: var(--text); }
.form-hint { font-size: 11px; color: var(--text-dim); margin-top: 6px; line-height: 1.5; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

/* Restriction pills */
.restriction-pills { display: flex; gap: 7px; flex-wrap: wrap; margin-top: 6px; }
.r-pill {
    padding: 5px 12px; border-radius: 99px; font-size: 11px; font-weight: 700;
    border: 1.5px solid var(--border); background: transparent; color: var(--text-dim);
    cursor: pointer; transition: all .2s; font-family: var(--sans);
}
.r-pill:hover { border-color: var(--indigo); color: #a5b4fc; }
.r-pill.selected { background: rgba(99,102,241,.2); border-color: var(--indigo); color: #c7d2fe; }

/* Generated link box */
.link-box {
    background: rgba(16,185,129,.08); border: 1.5px solid rgba(16,185,129,.25);
    border-radius: 14px; padding: 16px; margin-top: 20px; display: none;
}
.link-box.show { display: block; }
.link-box-title { font-size: 12px; font-weight: 700; color: var(--green); margin-bottom: 10px; display: flex; align-items: center; gap: 7px; }
.link-url {
    width: 100%; padding: 10px 12px; background: rgba(0,0,0,.25); border: 1px solid rgba(255,255,255,.1);
    border-radius: 9px; font-family: var(--mono); font-size: 11px; color: var(--text);
    word-break: break-all; line-height: 1.6; user-select: all;
}
.link-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
.link-btn {
    flex: 1; min-width: 100px; padding: 10px 14px; border-radius: 10px;
    font-family: var(--sans); font-size: 13px; font-weight: 700;
    border: none; cursor: pointer; transition: all .2s;
    display: flex; align-items: center; justify-content: center; gap: 7px;
}
.link-btn-copy { background: rgba(99,102,241,.2); border: 1px solid rgba(99,102,241,.3); color: #a5b4fc; }
.link-btn-copy:hover { background: rgba(99,102,241,.35); }
.link-btn-wa   { background: rgba(37,211,102,.2); border: 1px solid rgba(37,211,102,.3); color: #4ade80; }
.link-btn-wa:hover { background: rgba(37,211,102,.35); }
.link-btn-sms  { background: rgba(245,158,11,.15); border: 1px solid rgba(245,158,11,.25); color: var(--amber); }
.link-btn-sms:hover { background: rgba(245,158,11,.25); }
.btn-gen {
    width: 100%; padding: 14px; border: none; border-radius: 13px;
    background: linear-gradient(135deg, var(--indigo), #7c3aed);
    color: #fff; font-family: var(--sans); font-size: 15px; font-weight: 800;
    cursor: pointer; transition: all .2s; display: flex; align-items: center; justify-content: center; gap: 9px;
    margin-top: 6px;
}
.btn-gen:hover { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(99,102,241,.4); }
.btn-gen:disabled { opacity: .5; pointer-events: none; }

/* Restrictions summary card */
.restriction-summary {
    background: rgba(245,158,11,.08); border: 1px solid rgba(245,158,11,.2);
    border-radius: 10px; padding: 10px 14px; font-size: 12px; color: var(--amber);
    margin-top: 14px; display: none;
}
.restriction-summary.show { display: block; }

/* ── History feed ──────────────────────────────────────────── */
.history-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 16px; overflow: hidden; box-shadow: var(--shadow);
}
.history-head { padding: 14px 20px; border-bottom: 1px solid var(--border); }
.history-head h3 { font-size: 13px; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; }
.history-list { max-height: 280px; overflow-y: auto; }
.history-list::-webkit-scrollbar { width: 4px; }
.history-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 4px; }
.history-item {
    display: flex; align-items: center; gap: 12px;
    padding: 13px 20px; border-bottom: 1px solid var(--border);
    animation: slideIn .3s ease;
}
@keyframes slideIn { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: translateX(0); } }
.hi-dot { width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 14px; }
.hi-dot.ok  { background: rgba(16,185,129,.18); color: var(--green); }
.hi-dot.dup { background: rgba(245,158,11,.18);  color: var(--amber); }
.hi-dot.err { background: rgba(239,68,68,.18);   color: var(--red); }
.hi-name { font-size: 14px; font-weight: 700; }
.hi-meta { font-size: 11px; color: var(--text-dim); margin-top: 1px; font-family: var(--mono); }
.hi-time { font-size: 11px; color: var(--text-dim); margin-left: auto; white-space: nowrap; }
.history-empty { padding: 32px; text-align: center; color: var(--text-dim); font-size: 13px; }

/* ── Toast ─────────────────────────────────────────────────── */
.toast-wrap { position: fixed; top: 68px; left: 50%; transform: translateX(-50%); z-index: 100; pointer-events: none; display: flex; flex-direction: column; gap: 8px; align-items: center; }
.toast {
    background: #1a2f52; border: 1px solid var(--border);
    color: var(--text); font-size: 13px; font-weight: 600;
    padding: 10px 18px; border-radius: 12px; white-space: nowrap; box-shadow: 0 4px 20px rgba(0,0,0,.5);
    animation: toastIn .25s ease, toastOut .3s ease 2.4s forwards;
}
.toast.ok  { border-color: var(--green); color: var(--green); }
.toast.err { border-color: var(--red);   color: var(--red); }
.toast.info { border-color: var(--indigo); color: #a5b4fc; }
@keyframes toastIn  { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
@keyframes toastOut { from { opacity:1; } to { opacity:0; } }

/* ── Pulse ─────────────────────────────────────────────────── */
@keyframes pulse-green { 0%{box-shadow:0 0 0 0 rgba(16,185,129,.6)} 70%{box-shadow:0 0 0 20px rgba(16,185,129,0)} 100%{box-shadow:0 0 0 0 rgba(16,185,129,0)} }
.pulse-ok { animation: pulse-green .6s ease; }

/* ── Progress bar (for token scans) ─────────────────────────── */
.scans-bar { background: rgba(255,255,255,.08); border-radius: 99px; height: 4px; margin-top: 5px; overflow: hidden; }
.scans-bar-fill { height: 100%; background: var(--green); border-radius: 99px; transition: width .4s ease; }
.scans-bar-fill.warn  { background: var(--amber); }
.scans-bar-fill.full  { background: var(--red); }

@media (max-width: 480px) {
    .page { padding: 14px 10px 70px; }
    .r-grid { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .link-actions { flex-direction: column; }
}
</style>
</head>
<body>

<!-- Toasts -->
<div class="toast-wrap" id="toastWrap"></div>

<!-- Share Modal (creator only) -->
<?php if (!$token_mode): ?>
<div class="modal-overlay" id="shareModal" onclick="handleModalClick(event)">
  <div class="modal-sheet" onclick="event.stopPropagation()">
    <div class="modal-header">
      <h2><i class="fas fa-share-nodes"></i> Share Scan Link</h2>
      <button class="modal-close" onclick="closeShareModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:var(--text-dim);line-height:1.6;margin-bottom:18px">
        Generate a link for volunteers or staff to scan tickets <strong style="color:var(--text)">without logging in</strong>.
        Set restrictions to control what they can do.
      </p>

      <div class="form-group">
        <label class="form-label"><i class="fas fa-user" style="margin-right:5px"></i>Helper Label</label>
        <input type="text" id="helperLabel" class="form-input" placeholder="e.g. Gate A Volunteer, John Doe…" maxlength="100">
        <div class="form-hint">Identifies who this link was sent to. Shown in your active links list.</div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label"><i class="fas fa-clock" style="margin-right:5px"></i>Link Expires In</label>
          <select id="expiresHours" class="form-select" onchange="updateRestrictionSummary()">
            <option value="2">2 hours</option>
            <option value="4">4 hours</option>
            <option value="8">8 hours</option>
            <option value="12" selected>12 hours</option>
            <option value="24">24 hours</option>
            <option value="48">2 days</option>
            <option value="168">1 week</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label"><i class="fas fa-ticket-alt" style="margin-right:5px"></i>Max Check-ins</label>
          <select id="maxScansSelect" class="form-select" onchange="onMaxScansChange()">
            <option value="0" selected>Unlimited</option>
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="200">200</option>
            <option value="custom">Custom…</option>
          </select>
        </div>
      </div>

      <div class="form-group" id="customScansGroup" style="display:none;margin-top:-10px">
        <input type="number" id="customScans" class="form-input" placeholder="Enter max number of check-ins" min="1" max="9999" oninput="updateRestrictionSummary()">
      </div>

      <!-- Restriction summary -->
      <div class="restriction-summary" id="restrictionSummary">
        <i class="fas fa-shield-halved" style="margin-right:6px"></i>
        <span id="summaryText"></span>
      </div>

      <!-- Restrictions info -->
      <div style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);border-radius:12px;padding:14px;margin:16px 0;font-size:12px;color:#a5b4fc;line-height:1.7">
        <div style="font-weight:700;margin-bottom:6px;color:#c7d2fe"><i class="fas fa-lock" style="margin-right:6px"></i>Helper Restrictions (always enforced)</div>
        <div><i class="fas fa-check" style="color:var(--green);margin-right:6px;font-size:10px"></i>Can scan & check in attendees</div>
        <div><i class="fas fa-times" style="color:var(--red);margin-right:6px;font-size:10px"></i>Contact info (email/phone) always masked</div>
        <div><i class="fas fa-times" style="color:var(--red);margin-right:6px;font-size:10px"></i>Cannot generate or share more links</div>
        <div><i class="fas fa-times" style="color:var(--red);margin-right:6px;font-size:10px"></i>Cannot export or download ticket data</div>
      </div>

      <button class="btn-gen" id="btnGenerate" onclick="generateLink()">
        <i class="fas fa-link"></i> Generate Link
      </button>

      <!-- Generated link area -->
      <div class="link-box" id="linkBox">
        <div class="link-box-title"><i class="fas fa-check-circle"></i> Link Generated — Ready to Share</div>
        <div class="link-url" id="linkUrl"></div>
        <div class="link-actions">
          <button class="link-btn link-btn-copy" onclick="copyLink()">
            <i class="fas fa-copy"></i> Copy Link
          </button>
          <button class="link-btn link-btn-wa" onclick="shareWhatsApp()">
            <i class="fab fa-whatsapp"></i> WhatsApp
          </button>
          <button class="link-btn link-btn-sms" onclick="shareSMS()">
            <i class="fas fa-comment-sms"></i> SMS
          </button>
        </div>
        <div style="margin-top:10px;text-align:center">
          <button onclick="resetShareForm()" style="background:none;border:none;color:var(--text-dim);font-family:var(--sans);font-size:12px;cursor:pointer;text-decoration:underline">
            Generate another link
          </button>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Top bar -->
<div class="topbar">
    <?php if (!$token_mode): ?>
    <a href="view_ticket_details.php?event_id=<?= $event_id ?>" class="topbar-back">
        <i class="fas fa-arrow-left"></i><span>Back</span>
    </a>
    <?php else: ?>
    <div style="font-size:12px;color:var(--text-dim);display:flex;align-items:center;gap:7px">
        <i class="fas fa-shield-halved" style="color:var(--teal)"></i>
        Helper Access
    </div>
    <?php endif; ?>

    <div class="topbar-logo">KEY<span>CHELE</span></div>

    <div class="topbar-right">
        <div class="topbar-event" title="<?= htmlspecialchars($event['title']) ?>">
            <?= htmlspecialchars(mb_strimwidth($event['title'], 0, 28, '…')) ?>
        </div>
        <?php if (!$token_mode): ?>
        <button class="btn-share-top" onclick="openShareModal()">
            <i class="fas fa-share-nodes"></i><span>Share</span>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Helper mode banner -->
<?php if ($token_mode): ?>
<div class="helper-banner">
    <div class="hb-icon"><i class="fas fa-id-badge"></i></div>
    <div class="hb-info">
        <div class="hb-label">
            <?= htmlspecialchars($token_row['label']) ?> &mdash; Helper Scan Access
        </div>
        <div class="hb-meta">
            <?= htmlspecialchars($event['title']) ?> &bull;
            <?php
            $exp   = strtotime($token_row['expires_at']);
            $left  = $exp - time();
            $h     = floor($left / 3600);
            $m     = floor(($left % 3600) / 60);
            echo "Expires in {$h}h {$m}m";
            ?>
            <?php if ($token_row['max_scans'] > 0): ?>
                &bull; <?= $token_row['scans_used'] ?>/<?= $token_row['max_scans'] ?> check-ins used
            <?php else: ?>
                &bull; Unlimited check-ins
            <?php endif; ?>
        </div>
    </div>
    <div class="hb-chips">
        <span class="hb-chip teal"><i class="fas fa-qrcode" style="margin-right:4px"></i>Can Scan</span>
        <span class="hb-chip amber"><i class="fas fa-eye-slash" style="margin-right:4px"></i>Masked Contacts</span>
        <?php if ($token_row['max_scans'] > 0): ?>
        <span class="hb-chip indigo">
            <?= $token_row['scans_used'] ?>/<?= $token_row['max_scans'] ?> Used
        </span>
        <?php endif; ?>
    </div>
    <div class="hb-countdown" id="helperCountdown"></div>
</div>
<?php endif; ?>

<div class="page">

    <!-- Live stats bar -->
    <div class="stats-bar">
        <div class="sb-item">
            <div class="sb-val gold" id="statScanned">0</div>
            <div class="sb-lbl">Scanned</div>
        </div>
        <div class="sb-item">
            <div class="sb-val green" id="statCheckedIn"><?= $initial_checked_in ?></div>
            <div class="sb-lbl">Checked In</div>
        </div>
        <div class="sb-item">
            <div class="sb-val crimson" id="statDups">0</div>
            <div class="sb-lbl">Duplicates</div>
        </div>
    </div>

    <!-- Scanner card -->
    <div class="scanner-card" id="scannerCard">
        <div class="scanner-head">
            <h2><i class="fas fa-qrcode"></i> QR Scanner</h2>
            <span class="scanner-status ss-idle" id="scanStatus">Idle</span>
        </div>
        <div class="cam-wrap" id="camWrap">
            <video class="cam-video" id="camVideo" autoplay playsinline muted></video>
            <canvas class="cam-canvas" id="camCanvas"></canvas>
            <div class="scan-frame" id="scanFrame" style="display:none">
                <div class="sf-box">
                    <div class="sf-corner tl"></div><div class="sf-corner tr"></div>
                    <div class="sf-corner bl"></div><div class="sf-corner br"></div>
                    <div class="scan-line" id="scanLine"></div>
                </div>
            </div>
            <div class="cam-dim" id="camDim">
                <i class="fas fa-camera" style="font-size:40px;color:var(--text-dim)"></i>
                <p style="font-size:14px;color:var(--text-dim);text-align:center;line-height:1.6">
                    Tap <strong style="color:var(--gold)">Start Scanner</strong><br>to begin scanning tickets
                </p>
            </div>
        </div>
        <div class="cam-controls">
            <button class="btn btn-start" id="btnStart" onclick="startCamera()">
                <i class="fas fa-play"></i> Start Scanner
            </button>
            <button class="btn btn-stop" id="btnStop" onclick="stopCamera()" style="display:none">
                <i class="fas fa-stop"></i> Stop
            </button>
            <button class="btn btn-flip" id="btnFlip" onclick="flipCamera()" style="display:none" title="Flip camera">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
    </div>

    <!-- Result card -->
    <div class="result-card" id="resultCard">
        <div class="result-head" id="resultHead">
            <div class="rh-icon rh-valid"><i class="fas fa-circle-notch fa-spin"></i></div>
            <div>
                <div class="rh-title" id="resultTitle">Verifying…</div>
                <div class="rh-sub"   id="resultSub">Please wait</div>
            </div>
        </div>
        <hr class="r-divider">
        <div class="r-body"    id="resultBody"></div>
        <div class="r-actions" id="resultActions"></div>
    </div>

    <!-- Active shared links panel (creator only) -->
    <?php if (!$token_mode): ?>
    <div class="tokens-panel" id="tokensPanel">
        <div class="tokens-head" onclick="toggleTokensPanel()">
            <h3>
                <i class="fas fa-link"></i>
                Active Shared Links
                <span id="tokenCountBadge" style="background:rgba(99,102,241,.2);color:#a5b4fc;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;margin-left:4px">…</span>
            </h3>
            <i class="fas fa-chevron-down tokens-toggle" id="tokensToggleIcon"></i>
        </div>
        <div class="tokens-body" id="tokensBody">
            <div class="tokens-list" id="tokensList">
                <div class="tokens-empty" id="tokensEmpty">Loading…</div>
            </div>
            <div class="tokens-add-row">
                <button onclick="openShareModal()" style="width:100%;padding:11px;border-radius:11px;border:1.5px dashed rgba(99,102,241,.35);background:rgba(99,102,241,.07);color:#a5b4fc;font-family:var(--sans);font-size:13px;font-weight:700;cursor:pointer;transition:all .2s" onmouseover="this.style.background='rgba(99,102,241,.14)'" onmouseout="this.style.background='rgba(99,102,241,.07)'">
                    <i class="fas fa-plus" style="margin-right:7px"></i>Generate New Link
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Session history -->
    <div class="history-card">
        <div class="history-head">
            <h3><i class="fas fa-history" style="margin-right:7px"></i>Session History</h3>
        </div>
        <div class="history-list" id="historyList">
            <div class="history-empty" id="historyEmpty">No scans yet this session</div>
        </div>
    </div>

</div><!-- .page -->

<script>
// ── Constants ─────────────────────────────────────────────────
const EVENT_ID       = <?= $event_id ?>;
const TOKEN_MODE     = <?= $token_mode ? 'true' : 'false' ?>;
const HELPER_TOKEN   = <?= json_encode($token_str) ?>;
const TOKEN_EXPIRES  = <?= $token_expires_ts ?>;
const TOKEN_MAX      = <?= $token_max_scans ?>;
const TOKEN_LABEL    = <?= json_encode($token_label_safe) ?>;
const VERIFY_URL     = <?= json_encode($verify_url_js) ?>;
const TOTAL_SOLD     = <?= $total_sold ?>;

let stream         = null;
let rafId          = null;
let scanning       = false;
let lastQR         = '';
let facingMode     = 'environment';
let sessionScanned = 0;
let sessionDups    = 0;

// ── Helper countdown ─────────────────────────────────────────
if (TOKEN_MODE && TOKEN_EXPIRES > 0) {
    const el = document.getElementById('helperCountdown');
    function updateCountdown() {
        const left = TOKEN_EXPIRES - Math.floor(Date.now() / 1000);
        if (left <= 0) { el.textContent = '⛔ Expired'; el.style.color = 'var(--red)'; return; }
        const h = Math.floor(left / 3600);
        const m = Math.floor((left % 3600) / 60);
        const s = left % 60;
        el.textContent = (h > 0 ? h + 'h ' : '') + m + 'm ' + s + 's';
    }
    updateCountdown();
    setInterval(updateCountdown, 1000);
}

// ── Camera ────────────────────────────────────────────────────
async function startCamera() {
    const video  = document.getElementById('camVideo');
    const dim    = document.getElementById('camDim');
    const frame  = document.getElementById('scanFrame');
    const line   = document.getElementById('scanLine');
    const btnS   = document.getElementById('btnStart');
    const btnSt  = document.getElementById('btnStop');
    const btnF   = document.getElementById('btnFlip');
    const status = document.getElementById('scanStatus');

    stopCamera(true);
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode, width: { ideal: 1280 }, height: { ideal: 1280 } }
        });
        video.srcObject = stream;
        await video.play();

        dim.classList.add('hide');
        frame.style.display = 'flex';
        line.classList.add('active');
        btnS.style.display  = 'none';
        btnSt.style.display = '';
        btnF.style.display  = '';
        status.textContent  = 'Scanning…';
        status.className    = 'scanner-status ss-scan';
        scanning = true;
        requestAnimationFrame(tick);
    } catch(err) {
        document.getElementById('camWrap').innerHTML = `<div style="aspect-ratio:1/1;background:var(--bg2);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:32px;text-align:center">
            <i class="fas fa-exclamation-triangle" style="font-size:40px;color:var(--amber)"></i>
            <p style="font-size:14px;color:var(--text-dim);line-height:1.6"><strong style="color:var(--text)">Camera access denied</strong><br>Allow camera access in your browser settings and reload.</p>
        </div>`;
        status.textContent = 'No Camera'; status.className = 'scanner-status ss-error';
    }
}

function stopCamera(silent) {
    scanning = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    if (!silent) {
        document.getElementById('camDim').classList.remove('hide');
        document.getElementById('scanFrame').style.display = 'none';
        document.getElementById('btnStart').style.display = '';
        document.getElementById('btnStop').style.display  = 'none';
        document.getElementById('btnFlip').style.display  = 'none';
        document.getElementById('scanStatus').textContent = 'Idle';
        document.getElementById('scanStatus').className   = 'scanner-status ss-idle';
        document.getElementById('scanLine').classList.remove('active');
    }
}

function flipCamera() { facingMode = facingMode === 'environment' ? 'user' : 'environment'; startCamera(); }

// ── QR decode loop ────────────────────────────────────────────
function tick() {
    if (!scanning) return;
    rafId = requestAnimationFrame(tick);
    const video = document.getElementById('camVideo');
    const canvas = document.getElementById('camCanvas');
    if (video.readyState !== video.HAVE_ENOUGH_DATA) return;
    canvas.width = video.videoWidth; canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const code = jsQR(imageData.data, canvas.width, canvas.height, { inversionAttempts: 'dontInvert' });
    if (code && code.data && code.data !== lastQR) {
        lastQR = code.data;
        scanning = false;
        onQRDetected(code.data);
        setTimeout(() => { lastQR = ''; if (stream) scanning = true; }, 3500);
    }
}

// ── QR detected ───────────────────────────────────────────────
async function onQRDetected(rawData) {
    sessionScanned++;
    document.getElementById('statScanned').textContent = sessionScanned;
    document.getElementById('scannerCard').classList.add('pulse-ok');
    setTimeout(() => document.getElementById('scannerCard').classList.remove('pulse-ok'), 600);

    const card = document.getElementById('resultCard');
    card.classList.add('show');
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    setResultLoading();

    try {
        const body = new URLSearchParams({ action: 'verify_qr', qr_data: rawData });
        const res  = await fetch(VERIFY_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await res.json();
        renderResult(data);
    } catch(e) {
        renderError('Network error — could not verify ticket');
    }
}

// ── Render helpers ────────────────────────────────────────────
function setResultLoading() {
    document.getElementById('resultHead').innerHTML = `
        <div class="rh-icon rh-valid"><i class="fas fa-circle-notch fa-spin"></i></div>
        <div><div class="rh-title">Verifying ticket…</div><div class="rh-sub">Checking database</div></div>`;
    document.getElementById('resultBody').innerHTML    = '';
    document.getElementById('resultActions').innerHTML = '';
    document.getElementById('scanStatus').textContent  = 'Found QR';
    document.getElementById('scanStatus').className    = 'scanner-status ss-found';
}

function renderResult(data) {
    if (!data.success) {
        if (data.ticket) renderTicketCard(data.ticket, 'invalid', data.message);
        else renderError(data.message || 'Ticket not found');
        return;
    }
    if (data.status === 'already_checked_in') {
        sessionDups++;
        document.getElementById('statDups').textContent = sessionDups;
        renderTicketCard(data.ticket, 'already', 'Already checked in');
        addHistory(data.ticket, 'dup');
    } else {
        renderTicketCard(data.ticket, 'valid', 'Valid ticket — ready to check in');
        addHistory(data.ticket, 'ok');
    }
}

function renderTicketCard(t, type, subtitle) {
    const icons      = { valid:'fa-check-circle', already:'fa-exclamation-circle', invalid:'fa-times-circle' };
    const iconCls    = { valid:'rh-valid',         already:'rh-warn',               invalid:'rh-invalid' };
    const titles     = { valid:'Valid Ticket ✓',   already:'Already Checked In',    invalid:'Invalid Ticket' };
    const ssCls      = { valid:'ss-found',         already:'ss-warn',               invalid:'ss-error' };
    const statusClr  = { valid:'green',            already:'amber',                 invalid:'red' };
    const statusText = { valid:'Not yet checked in', already:'Already In ✓',        invalid:'Invalid' };

    document.getElementById('resultHead').innerHTML = `
        <div class="rh-icon ${iconCls[type]}"><i class="fas ${icons[type]}"></i></div>
        <div><div class="rh-title">${titles[type]}</div><div class="rh-sub">${subtitle}</div></div>`;
    document.getElementById('scanStatus').textContent = titles[type];
    document.getElementById('scanStatus').className   = 'scanner-status ' + ssCls[type];

    const isPaid = parseFloat(t.amount_paid) > 0;
    const amtHtml = isPaid
        ? `<span class="amount-tag">KES ${Number(t.amount_paid).toLocaleString()}</span>`
        : `<span class="amount-tag free">FREE</span>`;

    // In token mode, contact fields arrive pre-masked from server
    const emailLabel = TOKEN_MODE ? 'Email <span style="font-size:10px;color:var(--amber)">●masked</span>' : 'Email';
    const phoneLabel = TOKEN_MODE ? 'Phone <span style="font-size:10px;color:var(--amber)">●masked</span>' : 'Phone';

    document.getElementById('resultBody').innerHTML = `
        <div class="r-grid">
            <div class="r-row full">
                <span class="r-lbl">Ticket Number</span>
                <span class="r-val mono">${esc(t.ticket_number)}</span>
            </div>
            <div class="r-row">
                <span class="r-lbl">Buyer Name</span>
                <span class="r-val">${esc(t.buyer_name)}</span>
            </div>
            <div class="r-row">
                <span class="r-lbl">Ticket Type</span>
                <span class="r-val">${esc(t.ticket_type || 'General')}</span>
            </div>
            <div class="r-row">
                <span class="r-lbl">Quantity</span>
                <span class="r-val">${t.quantity} ticket${t.quantity > 1 ? 's' : ''}</span>
            </div>
            <div class="r-row">
                <span class="r-lbl">Amount Paid</span>
                <span class="r-val">${amtHtml}</span>
            </div>
            <div class="r-row">
                <span class="r-lbl">${emailLabel}</span>
                <span class="r-val" style="font-size:12px">${esc(t.buyer_email) || '—'}</span>
            </div>
            <div class="r-row">
                <span class="r-lbl">${phoneLabel}</span>
                <span class="r-val">${esc(t.buyer_phone) || '—'}</span>
            </div>
            <div class="r-row">
                <span class="r-lbl">Check-in Status</span>
                <span class="r-val ${statusClr[type]}">${statusText[type]}</span>
            </div>
        </div>`;

    const actionsEl = document.getElementById('resultActions');
    if (type === 'valid') {
        actionsEl.innerHTML = `
            <button class="btn-checkin" id="btnConfirm" onclick="confirmCheckin(${t.id},'${esc(t.buyer_name)}')">
                <i class="fas fa-check-circle"></i> Confirm Check-In
            </button>
            <button class="btn-rescan" onclick="rescan()"><i class="fas fa-qrcode"></i> Rescan</button>`;
    } else if (type === 'already') {
        actionsEl.innerHTML = `
            <button class="btn-checkin already" disabled><i class="fas fa-check-double"></i> Already Checked In</button>
            <button class="btn-rescan" onclick="rescan()"><i class="fas fa-qrcode"></i> Rescan</button>`;
    } else {
        actionsEl.innerHTML = `
            <button class="btn-rescan" style="flex:1" onclick="rescan()"><i class="fas fa-qrcode"></i> Scan Another Ticket</button>`;
    }
}

function renderError(msg) {
    document.getElementById('resultHead').innerHTML = `
        <div class="rh-icon rh-invalid"><i class="fas fa-times-circle"></i></div>
        <div><div class="rh-title">Scan Error</div><div class="rh-sub">${esc(msg)}</div></div>`;
    document.getElementById('resultBody').innerHTML = `<p style="font-size:14px;color:var(--text-dim);padding-bottom:8px">${esc(msg)}</p>`;
    document.getElementById('resultActions').innerHTML = `
        <button class="btn-rescan" style="flex:1" onclick="rescan()"><i class="fas fa-qrcode"></i> Scan Another Ticket</button>`;
    document.getElementById('scanStatus').textContent = 'Error';
    document.getElementById('scanStatus').className   = 'scanner-status ss-error';
}

// ── Confirm check-in ──────────────────────────────────────────
async function confirmCheckin(purchaseId, buyerName) {
    const btn = document.getElementById('btnConfirm');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Checking in…';
    try {
        const body = new URLSearchParams({ action: 'confirm_checkin', purchase_id: purchaseId });
        const res  = await fetch(VERIFY_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await res.json();
        if (data.success) {
            btn.className = 'btn-checkin already';
            btn.innerHTML = '<i class="fas fa-check-double"></i> Checked In!';
            document.getElementById('resultHead').querySelector('.rh-icon').className = 'rh-icon rh-valid';
            document.getElementById('resultHead').querySelector('.rh-title').textContent = 'Check-In Confirmed ✓';
            document.getElementById('resultHead').querySelector('.rh-sub').textContent   = buyerName + ' successfully checked in';
            document.getElementById('scanStatus').textContent = 'Checked In ✓';
            document.getElementById('scanStatus').className   = 'scanner-status ss-found';
            const el = document.getElementById('statCheckedIn');
            el.textContent = parseInt(el.textContent || 0) + 1;
            toast('✓ ' + buyerName + ' checked in!', 'ok');
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Check-In';
            toast(data.message || 'Check-in failed', 'err');
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Check-In';
        toast('Network error — try again', 'err');
    }
}

// ── Rescan ────────────────────────────────────────────────────
function rescan() {
    document.getElementById('resultCard').classList.remove('show');
    document.getElementById('scanStatus').textContent = stream ? 'Scanning…' : 'Idle';
    document.getElementById('scanStatus').className   = stream ? 'scanner-status ss-scan' : 'scanner-status ss-idle';
    lastQR = '';
    if (stream) scanning = true;
    else startCamera();
}

// ── History ───────────────────────────────────────────────────
function addHistory(t, type) {
    const list  = document.getElementById('historyList');
    const empty = document.getElementById('historyEmpty');
    if (empty) empty.remove();
    const now   = new Date().toLocaleTimeString([], { hour:'2-digit', minute:'2-digit', second:'2-digit' });
    const icons = { ok:'fa-check', dup:'fa-exclamation', err:'fa-times' };
    const item  = document.createElement('div');
    item.className = 'history-item';
    item.innerHTML = `
        <div class="hi-dot ${type}"><i class="fas ${icons[type]}"></i></div>
        <div><div class="hi-name">${esc(t.buyer_name)}</div><div class="hi-meta">${esc(t.ticket_number)}</div></div>
        <div class="hi-time">${now}</div>`;
    list.insertBefore(item, list.firstChild);
}

// ── Toast ─────────────────────────────────────────────────────
function toast(msg, type) {
    const wrap = document.getElementById('toastWrap');
    const el   = document.createElement('div');
    el.className = 'toast ' + (type || '');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 2800);
}

// ── Escape ────────────────────────────────────────────────────
function esc(s) {
    if (!s) return '—';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ══════════════════════════════════════════════
// ── Share Modal Logic (creator only) ─────────
// ══════════════════════════════════════════════
let generatedUrl   = '';
let generatedLabel = '';
let generatedData  = null;

function openShareModal() {
    resetShareForm();
    document.getElementById('shareModal').classList.add('open');
}
function closeShareModal() {
    document.getElementById('shareModal').classList.remove('open');
    if (generatedData) loadTokens(); // refresh list if a token was just created
}
function handleModalClick(e) {
    if (e.target === document.getElementById('shareModal')) closeShareModal();
}

function onMaxScansChange() {
    const sel = document.getElementById('maxScansSelect');
    const grp = document.getElementById('customScansGroup');
    grp.style.display = sel.value === 'custom' ? 'block' : 'none';
    updateRestrictionSummary();
}

function getMaxScans() {
    const sel = document.getElementById('maxScansSelect');
    if (sel.value === 'custom') {
        return Math.max(1, parseInt(document.getElementById('customScans').value) || 0);
    }
    return parseInt(sel.value);
}

function updateRestrictionSummary() {
    const el      = document.getElementById('restrictionSummary');
    const txt     = document.getElementById('summaryText');
    const hours   = parseInt(document.getElementById('expiresHours').value);
    const maxS    = getMaxScans();
    const parts   = [];
    parts.push('Expires in ' + hours + ' hour' + (hours !== 1 ? 's' : ''));
    parts.push(maxS === 0 ? 'Unlimited check-ins' : 'Max ' + maxS + ' check-in' + (maxS !== 1 ? 's' : ''));
    txt.textContent = parts.join(' · ');
    el.classList.add('show');
}

async function generateLink() {
    const label   = document.getElementById('helperLabel').value.trim();
    const hours   = parseInt(document.getElementById('expiresHours').value);
    const maxS    = getMaxScans();
    const btn     = document.getElementById('btnGenerate');

    if (!label) {
        document.getElementById('helperLabel').focus();
        toast('Enter a helper label first', 'err');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Generating…';

    try {
        const body = new URLSearchParams({
            action: 'create_token', label, expires_hours: hours, max_scans: maxS
        });
        const res  = await fetch(VERIFY_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await res.json();

        if (data.success) {
            generatedUrl   = data.url;
            generatedLabel = data.label;
            generatedData  = data;
            document.getElementById('linkUrl').textContent = data.url;
            document.getElementById('linkBox').classList.add('show');
            btn.style.display = 'none';
            toast('Link generated for ' + data.label, 'ok');
        } else {
            toast(data.message || 'Failed to generate link', 'err');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-link"></i> Generate Link';
        }
    } catch(e) {
        toast('Network error', 'err');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-link"></i> Generate Link';
    }
}

function copyLink() {
    if (!generatedUrl) return;
    navigator.clipboard.writeText(generatedUrl).then(() => {
        toast('Link copied to clipboard!', 'ok');
    }).catch(() => {
        // fallback
        const ta = document.createElement('textarea');
        ta.value = generatedUrl; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        toast('Link copied!', 'ok');
    });
}

function shareWhatsApp() {
    if (!generatedUrl) return;
    const max = generatedData?.max_scans > 0 ? generatedData.max_scans + ' max check-ins' : 'unlimited check-ins';
    const msg = `Hi ${generatedLabel}! Here's your scan link for *${<?= json_encode(htmlspecialchars($event['title'])) ?>}*:\n\n${generatedUrl}\n\n✅ You can scan & check in attendees\n🔒 Contact info will be masked\n⏱️ Expires: ${generatedData?.expires_at ?? ''}\n📊 ${max}`;
    window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank');
}

function shareSMS() {
    if (!generatedUrl) return;
    const msg = `Scan link for ${<?= json_encode(htmlspecialchars($event['title'])) ?>}: ${generatedUrl}`;
    window.open('sms:?body=' + encodeURIComponent(msg));
}

function resetShareForm() {
    generatedUrl = ''; generatedLabel = ''; generatedData = null;
    document.getElementById('helperLabel').value = '';
    document.getElementById('expiresHours').value = '12';
    document.getElementById('maxScansSelect').value = '0';
    document.getElementById('customScansGroup').style.display = 'none';
    document.getElementById('restrictionSummary').classList.remove('show');
    document.getElementById('linkBox').classList.remove('show');
    const btn = document.getElementById('btnGenerate');
    btn.disabled = false; btn.style.display = '';
    btn.innerHTML = '<i class="fas fa-link"></i> Generate Link';
}

// ── Active tokens panel ───────────────────────────────────────
let tokensPanelOpen = false;

function toggleTokensPanel() {
    tokensPanelOpen = !tokensPanelOpen;
    document.getElementById('tokensBody').className = tokensPanelOpen ? 'tokens-body open' : 'tokens-body';
    document.getElementById('tokensToggleIcon').className = tokensPanelOpen ? 'fas fa-chevron-down tokens-toggle open' : 'fas fa-chevron-down tokens-toggle';
    if (tokensPanelOpen) loadTokens();
}

async function loadTokens() {
    const list  = document.getElementById('tokensList');
    const badge = document.getElementById('tokenCountBadge');
    list.innerHTML = '<div class="tokens-empty">Loading…</div>';

    try {
        const body = new URLSearchParams({ action: 'list_tokens' });
        const res  = await fetch(VERIFY_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await res.json();

        if (!data.success) { list.innerHTML = '<div class="tokens-empty">Failed to load</div>'; return; }

        const tokens = data.tokens;
        if (badge) badge.textContent = tokens.length;

        if (tokens.length === 0) {
            list.innerHTML = '<div class="tokens-empty">No active shared links yet.<br>Generate one to let helpers scan.</div>';
            return;
        }

        list.innerHTML = '';
        const proto  = location.protocol;
        const host   = location.host;
        const path   = location.pathname;

        tokens.forEach(tk => {
            const url      = proto + '//' + host + path + '?event_id=' + EVENT_ID + '&token=' + tk.id; // URL for copy
            const realUrl  = proto + '//' + host + path + '?event_id=<?= $event_id ?>&token=PLACEHOLDER'; // placeholder until we store token
            const maxLabel = tk.max_scans == 0 ? 'Unlimited' : tk.scans_used + '/' + tk.max_scans;
            const pct      = tk.max_scans > 0 ? Math.min(100, (tk.scans_used / tk.max_scans) * 100) : 0;
            const barClass = pct >= 100 ? 'full' : pct >= 75 ? 'warn' : '';

            const now      = new Date();
            const exp      = new Date(tk.expires_at);
            const diffMs   = exp - now;
            const diffH    = Math.floor(diffMs / 3600000);
            const diffM    = Math.floor((diffMs % 3600000) / 60000);
            const expStr   = diffH > 0 ? diffH + 'h ' + diffM + 'm left' : diffM + 'm left';

            const item = document.createElement('div');
            item.className = 'token-item';
            item.innerHTML = `
                <div class="ti-icon"><i class="fas fa-user-shield"></i></div>
                <div class="ti-info">
                    <div class="ti-label">${esc(tk.label)}</div>
                    <div class="ti-meta">
                        <span><i class="fas fa-clock" style="margin-right:3px;font-size:10px"></i>${expStr}</span>
                        <span class="ti-scans ${barClass}"><i class="fas fa-ticket-alt" style="margin-right:3px;font-size:10px"></i>${maxLabel}</span>
                    </div>
                    ${tk.max_scans > 0 ? `<div class="scans-bar" style="margin-top:5px"><div class="scans-bar-fill ${barClass}" style="width:${pct}%"></div></div>` : ''}
                </div>
                <div class="ti-actions">
                    <button class="tokens-copy-btn" onclick="copyTokenLink(${tk.id})" title="Copy link">
                        <i class="fas fa-copy"></i>
                    </button>
                    <button class="btn-revoke" onclick="revokeToken(${tk.id}, this)">
                        <i class="fas fa-ban"></i> Revoke
                    </button>
                </div>`;
            list.appendChild(item);
        });
    } catch(e) {
        list.innerHTML = '<div class="tokens-empty">Network error loading links</div>';
    }
}

async function copyTokenLink(tokenId) {
    // We don't store the full token in the list for security; fetch from server
    // Instead, open a copy from the tokens list data we have
    toast('Use the "Copy Link" button in the share modal to copy fresh links.', 'info');
}

async function revokeToken(tokenId, btn) {
    if (!confirm('Revoke this scan link? It will stop working immediately.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';

    try {
        const body = new URLSearchParams({ action: 'revoke_token', token_id: tokenId });
        const res  = await fetch(VERIFY_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await res.json();
        if (data.success) {
            const item = btn.closest('.token-item');
            item.style.opacity = '0'; item.style.transform = 'translateX(20px)'; item.style.transition = 'all .3s';
            setTimeout(() => { item.remove(); loadTokens(); }, 300);
            toast('Link revoked', 'ok');
        } else {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-ban"></i> Revoke';
            toast(data.message || 'Failed to revoke', 'err');
        }
    } catch(e) {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-ban"></i> Revoke';
        toast('Network error', 'err');
    }
}

// Load token count on page load (creator mode)
if (!TOKEN_MODE) {
    (async () => {
        try {
            const body = new URLSearchParams({ action: 'list_tokens' });
            const res  = await fetch(VERIFY_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
            const data = await res.json();
            if (data.success) {
                const badge = document.getElementById('tokenCountBadge');
                if (badge) badge.textContent = data.tokens.length || '0';
            }
        } catch(e) {}
    })();
}

window.addEventListener('beforeunload', () => stopCamera(true));
</script>
</body>
</html>
<?php ob_end_flush(); ?>