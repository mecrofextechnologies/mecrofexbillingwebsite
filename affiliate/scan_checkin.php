<?php
// scan_checkin.php  –  QR-based ticket check-in for event creators
// ──────────────────────────────────────────────────────────────────
ob_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/scan_checkin_debug.log');

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';
require_once $connect_path;

if (!isset($_SESSION['creator_id']) || !is_numeric($_SESSION['creator_id'])) {
    // AJAX call
    if (isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }
    header("Location: login.php");
    exit;
}

$creator_id = (int)$_SESSION['creator_id'];
$event_id   = (int)($_GET['event_id'] ?? 0);
if ($event_id <= 0) { die("Invalid event ID."); }

// Verify ownership
$stmt = $pdo->prepare("SELECT title, start_date, start_time, venue FROM tickets WHERE id = ? AND creator_id = ?");
$stmt->execute([$event_id, $creator_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) { die("Event not found or access denied."); }

// ──────────────────────────────────────────────────────────────────
// AJAX: verify QR payload → return ticket info
// ──────────────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'verify_qr') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $raw = trim($_POST['qr_data'] ?? '');
    if (empty($raw)) {
        echo json_encode(['success' => false, 'message' => 'Empty QR data']);
        exit;
    }

    // Decode JSON payload baked into QR
    $payload = json_decode($raw, true);
    $ticket_number = $payload['ticket'] ?? $raw; // fallback: raw string = ticket number

    $stmt = $pdo->prepare("
        SELECT
            tp.id, tp.ticket_number, tp.buyer_name, tp.buyer_email, tp.buyer_phone,
            tp.amount_paid, tp.quantity, tp.status, tp.is_checked_in,
            tp.mpesa_receipt, tp.created_at,
            t.title AS event_title, t.start_date, t.start_time, t.venue,
            ett.type_name AS ticket_type
        FROM ticket_purchases tp
        JOIN tickets t ON tp.ticket_id = t.id
        LEFT JOIN event_ticket_types ett ON tp.ticket_type_id = ett.id
        WHERE tp.ticket_number = ?
          AND t.creator_id = ?
          AND t.id = ?
    ");
    $stmt->execute([$ticket_number, $creator_id, $event_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found or does not belong to this event']);
        exit;
    }

    if ($ticket['status'] !== 'completed') {
        echo json_encode([
            'success'  => false,
            'message'  => 'Payment not completed — ticket is ' . strtoupper($ticket['status']),
            'ticket'   => $ticket,
            'status'   => 'invalid'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'ticket'  => $ticket,
        'status'  => $ticket['is_checked_in'] ? 'already_checked_in' : 'valid'
    ]);
    exit;
}

// ──────────────────────────────────────────────────────────────────
// AJAX: confirm check-in
// ──────────────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'confirm_checkin') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $purchase_id = (int)($_POST['purchase_id'] ?? 0);
    if ($purchase_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid purchase ID']);
        exit;
    }

    // Double-check ownership + completed status
    $stmt = $pdo->prepare("
        SELECT tp.is_checked_in, tp.status, tp.buyer_name
        FROM ticket_purchases tp
        JOIN tickets t ON tp.ticket_id = t.id
        WHERE tp.id = ? AND t.creator_id = ? AND t.id = ?
    ");
    $stmt->execute([$purchase_id, $creator_id, $event_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found']);
        exit;
    }
    if ($row['status'] !== 'completed') {
        echo json_encode(['success' => false, 'message' => 'Cannot check in — payment not completed']);
        exit;
    }
    if ((int)$row['is_checked_in'] === 1) {
        echo json_encode(['success' => true, 'already' => true, 'message' => 'Already checked in']);
        exit;
    }

    $pdo->prepare("UPDATE ticket_purchases SET is_checked_in = 1, updated_at = NOW() WHERE id = ?")
        ->execute([$purchase_id]);

    echo json_encode(['success' => true, 'already' => false, 'buyer_name' => $row['buyer_name']]);
    exit;
}
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
<!-- jsQR — lightweight pure-JS QR decoder that works in-browser -->
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
    --crimson-d: #8f0d2a;
    --green:     #10b981;
    --green-d:   #059669;
    --green-l:   #d1fae5;
    --amber:     #f59e0b;
    --amber-l:   #fef3c7;
    --red:       #ef4444;
    --red-l:     #fee2e2;
    --slate:     #64748b;
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

/* ── Layout ────────────────────────────────────────────────── */
.topbar {
    position: sticky; top: 0; z-index: 50;
    background: rgba(11,22,40,0.92);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 0 20px;
    height: 60px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px;
}
.topbar-logo {
    font-weight: 800; font-size: 17px; letter-spacing: 2px; color: #fff;
}
.topbar-logo span { color: var(--gold); }
.topbar-back {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 13px; font-weight: 600; color: var(--text-dim);
    text-decoration: none; transition: color .2s;
}
.topbar-back:hover { color: var(--text); }
.topbar-event {
    font-size: 12px; font-weight: 600; color: var(--text-dim);
    max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.page { max-width: 520px; margin: 0 auto; padding: 24px 16px 80px; }

/* ── Scanner card ──────────────────────────────────────────── */
.scanner-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 24px;
    overflow: hidden;
    box-shadow: var(--shadow);
    margin-bottom: 20px;
}
.scanner-head {
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.scanner-head h2 {
    font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 9px;
}
.scanner-head h2 i { color: var(--gold); font-size: 14px; }
.scanner-status {
    font-size: 11px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase;
    padding: 4px 10px; border-radius: 99px;
}
.ss-idle    { background: rgba(255,255,255,.08); color: var(--text-dim); }
.ss-scan    { background: rgba(254,196,17,.15); color: var(--gold); }
.ss-found   { background: rgba(16,185,129,.15); color: var(--green); }
.ss-error   { background: rgba(239,68,68,.15);  color: var(--red); }
.ss-warn    { background: rgba(245,158,11,.15);  color: var(--amber); }

/* Video viewport */
.cam-wrap {
    position: relative; background: #000;
    aspect-ratio: 1/1; overflow: hidden;
}
.cam-video {
    width: 100%; height: 100%; object-fit: cover;
}
.cam-canvas { display: none; }

/* Scan frame overlay */
.scan-frame {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    pointer-events: none;
}
.sf-box {
    width: 62%; aspect-ratio: 1/1; position: relative;
}
.sf-corner {
    position: absolute; width: 28px; height: 28px;
    border-color: var(--gold); border-style: solid;
}
.sf-corner.tl { top:0; left:0;  border-width: 3px 0 0 3px; border-radius: 6px 0 0 0; }
.sf-corner.tr { top:0; right:0; border-width: 3px 3px 0 0; border-radius: 0 6px 0 0; }
.sf-corner.bl { bottom:0; left:0;  border-width: 0 0 3px 3px; border-radius: 0 0 0 6px; }
.sf-corner.br { bottom:0; right:0; border-width: 0 3px 3px 0; border-radius: 0 0 6px 0; }

/* Scan line animation */
.scan-line {
    position: absolute; left: 10%; right: 10%;
    height: 2px; background: linear-gradient(to right, transparent, var(--gold), transparent);
    box-shadow: 0 0 8px 1px var(--gold);
    animation: scanMove 2s ease-in-out infinite;
    opacity: 0;
}
.scan-line.active { opacity: 1; }
@keyframes scanMove {
    0%   { top: 10%; }
    50%  { top: 88%; }
    100% { top: 10%; }
}

/* Dim overlay when not scanning */
.cam-dim {
    position: absolute; inset: 0;
    background: rgba(11,22,40,0.7);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 12px; transition: opacity .3s;
}
.cam-dim.hide { opacity: 0; pointer-events: none; }

.cam-controls { padding: 16px 20px; display: flex; gap: 10px; }

/* ── Buttons ───────────────────────────────────────────────── */
.btn {
    flex: 1; padding: 13px 16px; border: none; border-radius: 12px;
    font-family: var(--sans); font-size: 14px; font-weight: 700;
    cursor: pointer; transition: all .2s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn:disabled { opacity: .4; pointer-events: none; }
.btn-start  { background: var(--gold); color: var(--navy); }
.btn-start:hover  { background: var(--gold-d); transform: translateY(-1px); }
.btn-stop   { background: rgba(255,255,255,.08); color: var(--text); border: 1px solid var(--border); }
.btn-stop:hover   { background: rgba(255,255,255,.14); }
.btn-flip   {
    flex: 0 0 48px; padding: 0;
    background: rgba(255,255,255,.08); color: var(--text);
    border: 1px solid var(--border); border-radius: 12px;
}
.btn-flip:hover { background: rgba(255,255,255,.14); }

/* ── Result panel ──────────────────────────────────────────── */
.result-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 24px;
    overflow: hidden;
    box-shadow: var(--shadow);
    margin-bottom: 20px;
    display: none; /* shown by JS */
}
.result-card.show { display: block; }

.result-head {
    padding: 16px 20px;
    display: flex; align-items: center; gap: 12px;
}
.rh-icon {
    width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 20px;
}
.rh-valid   { background: rgba(16,185,129,.18); color: var(--green); }
.rh-warn    { background: rgba(245,158,11,.18);  color: var(--amber); }
.rh-invalid { background: rgba(239,68,68,.18);   color: var(--red); }

.rh-title { font-size: 16px; font-weight: 800; }
.rh-sub   { font-size: 12px; color: var(--text-dim); margin-top: 2px; }

/* Divider */
.r-divider { border: none; border-top: 1px solid var(--border); margin: 0; }

/* Detail rows */
.r-body  { padding: 18px 20px; }
.r-grid  { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.r-row   { display: flex; flex-direction: column; gap: 3px; }
.r-row.full { grid-column: 1 / -1; }
.r-lbl   { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text-dim); }
.r-val   { font-size: 14px; font-weight: 600; color: var(--text); }
.r-val.mono { font-family: var(--mono); color: var(--gold); font-size: 13px; }
.r-val.green { color: var(--green); }
.r-val.red   { color: var(--red); }
.r-val.amber { color: var(--amber); }

/* Amount badge */
.amount-tag {
    display: inline-block; background: rgba(16,185,129,.12);
    border: 1px solid rgba(16,185,129,.3); color: var(--green);
    font-weight: 800; font-size: 16px; padding: 6px 14px; border-radius: 10px;
}
.amount-tag.free { background: rgba(100,116,139,.15); border-color: rgba(100,116,139,.3); color: var(--text-dim); }

/* Action area */
.r-actions { padding: 0 20px 20px; display: flex; gap: 10px; }
.btn-checkin {
    flex: 1; padding: 15px; background: var(--green); color: #fff;
    border: none; border-radius: 14px; font-family: var(--sans); font-size: 15px; font-weight: 800;
    cursor: pointer; transition: all .2s;
    display: flex; align-items: center; justify-content: center; gap: 9px;
}
.btn-checkin:hover:not(:disabled) { background: var(--green-d); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(16,185,129,.35); }
.btn-checkin:disabled { opacity: .4; pointer-events: none; }
.btn-checkin.already { background: rgba(16,185,129,.12); color: var(--green); border: 1.5px solid var(--green); cursor: default; }
.btn-rescan {
    flex: 0 0 auto; padding: 15px 18px;
    background: rgba(255,255,255,.06); color: var(--text);
    border: 1px solid var(--border); border-radius: 14px;
    font-family: var(--sans); font-size: 14px; font-weight: 700;
    cursor: pointer; transition: all .2s;
    display: flex; align-items: center; gap: 7px;
}
.btn-rescan:hover { background: rgba(255,255,255,.11); }

/* ── Stats bar ─────────────────────────────────────────────── */
.stats-bar {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 14px 20px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 20px;
    text-align: center;
}
.sb-item {}
.sb-val  { font-size: 22px; font-weight: 800; color: var(--text); }
.sb-lbl  { font-size: 10px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; color: var(--text-dim); margin-top: 2px; }
.sb-val.gold    { color: var(--gold); }
.sb-val.green   { color: var(--green); }
.sb-val.crimson { color: var(--crimson); }

/* ── History feed ──────────────────────────────────────────── */
.history-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 16px; overflow: hidden; box-shadow: var(--shadow);
}
.history-head { padding: 14px 20px; border-bottom: 1px solid var(--border); }
.history-head h3 { font-size: 13px; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; }
.history-list { max-height: 280px; overflow-y: auto; }
.history-list::-webkit-scrollbar { width: 4px; }
.history-list::-webkit-scrollbar-track { background: transparent; }
.history-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 4px; }
.history-item {
    display: flex; align-items: center; gap: 12px;
    padding: 13px 20px; border-bottom: 1px solid var(--border);
    animation: slideIn .3s ease;
}
@keyframes slideIn {
    from { opacity: 0; transform: translateX(-10px); }
    to   { opacity: 1; transform: translateX(0); }
}
.hi-dot {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 14px;
}
.hi-dot.ok   { background: rgba(16,185,129,.18); color: var(--green); }
.hi-dot.dup  { background: rgba(245,158,11,.18);  color: var(--amber); }
.hi-dot.err  { background: rgba(239,68,68,.18);   color: var(--red); }
.hi-name     { font-size: 14px; font-weight: 700; }
.hi-meta     { font-size: 11px; color: var(--text-dim); margin-top: 1px; font-family: var(--mono); }
.hi-time     { font-size: 11px; color: var(--text-dim); margin-left: auto; white-space: nowrap; }

.history-empty { padding: 32px; text-align: center; color: var(--text-dim); font-size: 13px; }

/* ── Permission denied / error ──────────────────────────────── */
.cam-error {
    aspect-ratio: 1/1; background: var(--bg2);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 12px; padding: 32px; text-align: center;
}
.cam-error i { font-size: 40px; color: var(--text-dim); }
.cam-error p { font-size: 14px; color: var(--text-dim); line-height: 1.6; }

/* ── Toast ──────────────────────────────────────────────────── */
.toast-wrap {
    position: fixed; top: 72px; left: 50%; transform: translateX(-50%);
    z-index: 100; pointer-events: none; display: flex; flex-direction: column; gap: 8px;
    align-items: center;
}
.toast {
    background: #1a2f52; border: 1px solid var(--border);
    color: var(--text); font-size: 13px; font-weight: 600;
    padding: 10px 18px; border-radius: 12px; white-space: nowrap;
    box-shadow: 0 4px 20px rgba(0,0,0,0.5);
    animation: toastIn .25s ease, toastOut .3s ease 2.4s forwards;
}
.toast.ok  { border-color: var(--green); color: var(--green); }
.toast.err { border-color: var(--red);   color: var(--red); }
@keyframes toastIn  { from { opacity:0; transform: translateY(-8px); } to { opacity:1; transform: translateY(0); } }
@keyframes toastOut { from { opacity:1; } to { opacity:0; } }

/* ── Pulse on successful scan ─────────────────────────────── */
@keyframes pulse-green {
    0%   { box-shadow: 0 0 0 0 rgba(16,185,129,.6); }
    70%  { box-shadow: 0 0 0 20px rgba(16,185,129,0); }
    100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
}
.pulse-ok { animation: pulse-green .6s ease; }

@media (max-width: 480px) {
    .page { padding: 16px 12px 70px; }
    .r-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- Toasts -->
<div class="toast-wrap" id="toastWrap"></div>

<!-- Top bar -->
<div class="topbar">
    <a href="view_ticket_details.php?event_id=<?= $event_id ?>" class="topbar-back">
        <i class="fas fa-arrow-left"></i>
        <span>Back</span>
    </a>
    <div class="topbar-logo">KEY<span>CHELE</span></div>
    <div class="topbar-event" title="<?= htmlspecialchars($event['title']) ?>">
        <?= htmlspecialchars(mb_strimwidth($event['title'], 0, 30, '…')) ?>
    </div>
</div>

<div class="page">

    <!-- Live stats bar (updated by JS) -->
    <div class="stats-bar">
        <div class="sb-item">
            <div class="sb-val gold" id="statScanned">0</div>
            <div class="sb-lbl">Scanned</div>
        </div>
        <div class="sb-item">
            <div class="sb-val green" id="statCheckedIn">–</div>
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

            <!-- Corners + scan line -->
            <div class="scan-frame" id="scanFrame" style="display:none;">
                <div class="sf-box">
                    <div class="sf-corner tl"></div>
                    <div class="sf-corner tr"></div>
                    <div class="sf-corner bl"></div>
                    <div class="sf-corner br"></div>
                    <div class="scan-line" id="scanLine"></div>
                </div>
            </div>

            <!-- Idle overlay -->
            <div class="cam-dim" id="camDim">
                <i class="fas fa-camera" style="font-size:40px;color:var(--text-dim);"></i>
                <p style="font-size:14px;color:var(--text-dim);text-align:center;line-height:1.6;">
                    Tap <strong style="color:var(--gold);">Start Scanner</strong><br>to begin scanning tickets
                </p>
            </div>
        </div>

        <div class="cam-controls">
            <button class="btn btn-start" id="btnStart" onclick="startCamera()">
                <i class="fas fa-play"></i> Start Scanner
            </button>
            <button class="btn btn-stop" id="btnStop" onclick="stopCamera()" style="display:none;">
                <i class="fas fa-stop"></i> Stop
            </button>
            <button class="btn btn-flip" id="btnFlip" onclick="flipCamera()" style="display:none;" title="Flip camera">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
    </div>

    <!-- Result card (hidden until QR scanned) -->
    <div class="result-card" id="resultCard">
        <div class="result-head" id="resultHead">
            <div class="rh-icon" id="resultIcon"><i class="fas fa-circle-notch fa-spin"></i></div>
            <div>
                <div class="rh-title" id="resultTitle">Verifying…</div>
                <div class="rh-sub"  id="resultSub">Please wait</div>
            </div>
        </div>
        <hr class="r-divider">
        <div class="r-body" id="resultBody"></div>
        <div class="r-actions" id="resultActions"></div>
    </div>

    <!-- Session scan history -->
    <div class="history-card">
        <div class="history-head">
            <h3><i class="fas fa-history" style="margin-right:7px;"></i>Session History</h3>
        </div>
        <div class="history-list" id="historyList">
            <div class="history-empty" id="historyEmpty">No scans yet this session</div>
        </div>
    </div>

</div><!-- .page -->

<script>
// ── State ─────────────────────────────────────────────────────
const EVENT_ID     = <?= $event_id ?>;
const VERIFY_URL   = 'scan_checkin.php?event_id=' + EVENT_ID;

let stream         = null;
let rafId          = null;
let scanning       = false;
let lastQR         = '';       // debounce repeated reads
let facingMode     = 'environment'; // default back camera
let sessionScanned = 0;
let sessionDups    = 0;

// Stats: fetch initial checked-in count
fetchStats();
async function fetchStats() {
    try {
        const r = await fetch('?event_id=' + EVENT_ID + '&action=stats_json');
        // If endpoint not available, silently skip
    } catch(e) {}
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

    // Stop any existing stream first
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

        status.textContent = 'Scanning…';
        status.className   = 'scanner-status ss-scan';

        scanning = true;
        requestAnimationFrame(tick);
    } catch(err) {
        console.error(err);
        const wrap = document.getElementById('camWrap');
        wrap.innerHTML = `<div class="cam-error">
            <i class="fas fa-exclamation-triangle" style="color:var(--amber);"></i>
            <p><strong>Camera access denied</strong><br>
            Please allow camera access in your browser settings and reload this page.</p>
        </div>`;
        status.textContent = 'No Camera';
        status.className   = 'scanner-status ss-error';
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

function flipCamera() {
    facingMode = facingMode === 'environment' ? 'user' : 'environment';
    startCamera();
}

// ── QR decode loop ────────────────────────────────────────────
function tick() {
    if (!scanning) return;
    rafId = requestAnimationFrame(tick);

    const video  = document.getElementById('camVideo');
    const canvas = document.getElementById('camCanvas');
    if (video.readyState !== video.HAVE_ENOUGH_DATA) return;

    const w = video.videoWidth;
    const h = video.videoHeight;
    canvas.width  = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, w, h);
    const imageData = ctx.getImageData(0, 0, w, h);

    const code = jsQR(imageData.data, w, h, { inversionAttempts: 'dontInvert' });
    if (code && code.data && code.data !== lastQR) {
        lastQR = code.data;
        // Pause scanning while we process
        scanning = false;
        onQRDetected(code.data);
        // Resume after 3.5 s (prevents duplicate scans of same code)
        setTimeout(() => { lastQR = ''; if (stream) scanning = true; }, 3500);
    }
}

// ── QR detected → verify with server ─────────────────────────
async function onQRDetected(rawData) {
    sessionScanned++;
    document.getElementById('statScanned').textContent = sessionScanned;

    // Brief beep-like visual
    document.getElementById('scannerCard').classList.add('pulse-ok');
    setTimeout(() => document.getElementById('scannerCard').classList.remove('pulse-ok'), 600);

    // Show result card in loading state
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
        <div>
            <div class="rh-title">Verifying ticket…</div>
            <div class="rh-sub">Checking against database</div>
        </div>`;
    document.getElementById('resultBody').innerHTML    = '';
    document.getElementById('resultActions').innerHTML = '';

    document.getElementById('scanStatus').textContent = 'Found QR';
    document.getElementById('scanStatus').className   = 'scanner-status ss-found';
}

function renderResult(data) {
    if (!data.success) {
        // Could still have ticket info for display
        if (data.ticket) {
            renderTicketCard(data.ticket, 'invalid', data.message);
        } else {
            renderError(data.message || 'Ticket not found');
        }
        return;
    }

    const t = data.ticket;
    if (data.status === 'already_checked_in') {
        sessionDups++;
        document.getElementById('statDups').textContent = sessionDups;
        renderTicketCard(t, 'already', 'Already checked in');
        addHistory(t, 'dup');
    } else {
        renderTicketCard(t, 'valid', 'Valid ticket — ready to check in');
        addHistory(t, 'ok');
    }
}

function renderTicketCard(t, type, subtitle) {
    const icons = { valid: 'fa-check-circle', already: 'fa-exclamation-circle', invalid: 'fa-times-circle' };
    const iconClasses = { valid: 'rh-valid', already: 'rh-warn', invalid: 'rh-invalid' };
    const titles = { valid: 'Valid Ticket ✓', already: 'Already Checked In', invalid: 'Invalid Ticket' };
    const statusColors = { valid: 'green', already: 'amber', invalid: 'red' };

    // Head
    document.getElementById('resultHead').innerHTML = `
        <div class="rh-icon ${iconClasses[type]}">
            <i class="fas ${icons[type]}"></i>
        </div>
        <div>
            <div class="rh-title">${titles[type]}</div>
            <div class="rh-sub">${subtitle}</div>
        </div>`;

    // Status badge color
    document.getElementById('scanStatus').textContent = titles[type];
    document.getElementById('scanStatus').className   = 'scanner-status ' +
        (type === 'valid' ? 'ss-found' : type === 'already' ? 'ss-warn' : 'ss-error');

    // Body
    const isPaid = parseFloat(t.amount_paid) > 0;
    const amountHtml = isPaid
        ? `<span class="amount-tag">KES ${Number(t.amount_paid).toLocaleString()}</span>`
        : `<span class="amount-tag free">FREE</span>`;

    document.getElementById('resultBody').innerHTML = `
        <div class="r-grid">
            <div class="r-row full">
                <span class="r-lbl">Ticket Number</span>
                <span class="r-val mono">${t.ticket_number}</span>
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
                <span class="r-val">${amountHtml}</span>
            </div>
            <div class="r-row">
                <span class="r-lbl">Email</span>
                <span class="r-val" style="font-size:12px;">${esc(t.buyer_email)}</span>
            </div>
            <div class="r-row">
                <span class="r-lbl">Phone</span>
                <span class="r-val">${esc(t.buyer_phone || '—')}</span>
            </div>
            <div class="r-row">
                <span class="r-lbl">Check-in Status</span>
                <span class="r-val ${statusColors[type]}">${
                    type === 'valid' ? 'Not yet' : type === 'already' ? 'Already In ✓' : 'Invalid'
                }</span>
            </div>
        </div>`;

    // Actions
    const actionsEl = document.getElementById('resultActions');
    if (type === 'valid') {
        actionsEl.innerHTML = `
            <button class="btn-checkin" id="btnConfirm" onclick="confirmCheckin(${t.id}, '${esc(t.buyer_name)}')">
                <i class="fas fa-check-circle"></i> Confirm Check-In
            </button>
            <button class="btn-rescan" onclick="rescan()">
                <i class="fas fa-qrcode"></i> Rescan
            </button>`;
    } else if (type === 'already') {
        actionsEl.innerHTML = `
            <button class="btn-checkin already" disabled>
                <i class="fas fa-check-double"></i> Already Checked In
            </button>
            <button class="btn-rescan" onclick="rescan()">
                <i class="fas fa-qrcode"></i> Rescan
            </button>`;
    } else {
        actionsEl.innerHTML = `
            <button class="btn-rescan" style="flex:1;" onclick="rescan()">
                <i class="fas fa-qrcode"></i> Scan Another Ticket
            </button>`;
    }
}

function renderError(msg) {
    document.getElementById('resultHead').innerHTML = `
        <div class="rh-icon rh-invalid"><i class="fas fa-times-circle"></i></div>
        <div>
            <div class="rh-title">Scan Error</div>
            <div class="rh-sub">${esc(msg)}</div>
        </div>`;
    document.getElementById('resultBody').innerHTML = `
        <p style="font-size:14px;color:var(--text-dim);padding-bottom:8px;">${esc(msg)}</p>`;
    document.getElementById('resultActions').innerHTML = `
        <button class="btn-rescan" style="flex:1;" onclick="rescan()">
            <i class="fas fa-qrcode"></i> Scan Another Ticket
        </button>`;

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
            document.getElementById('resultHead').querySelector('.rh-sub').textContent   = buyerName + ' has been successfully checked in';
            document.getElementById('scanStatus').textContent = 'Checked In ✓';
            document.getElementById('scanStatus').className   = 'scanner-status ss-found';

            // Update session counter
            const el = document.getElementById('statCheckedIn');
            const cur = parseInt(el.textContent) || 0;
            el.textContent = cur + 1;

            toast('✓ ' + buyerName + ' checked in!', 'ok');
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Check-In';
            toast(data.message || 'Check-in failed', 'err');
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Check-In';
        toast('Network error — please try again', 'err');
    }
}

// ── Rescan ────────────────────────────────────────────────────
function rescan() {
    document.getElementById('resultCard').classList.remove('show');
    document.getElementById('scanStatus').textContent = 'Scanning…';
    document.getElementById('scanStatus').className   = 'scanner-status ss-scan';
    lastQR = '';
    if (stream) scanning = true;
    else startCamera();
}

// ── Session history list ──────────────────────────────────────
function addHistory(t, type) {
    const list  = document.getElementById('historyList');
    const empty = document.getElementById('historyEmpty');
    if (empty) empty.remove();

    const now = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const icons = { ok: 'fa-check', dup: 'fa-exclamation', err: 'fa-times' };
    const item  = document.createElement('div');
    item.className = 'history-item';
    item.innerHTML = `
        <div class="hi-dot ${type}"><i class="fas ${icons[type]}"></i></div>
        <div>
            <div class="hi-name">${esc(t.buyer_name)}</div>
            <div class="hi-meta">${esc(t.ticket_number)}</div>
        </div>
        <div class="hi-time">${now}</div>`;
    list.insertBefore(item, list.firstChild);
}

// ── Toast helper ──────────────────────────────────────────────
function toast(msg, type) {
    const wrap = document.getElementById('toastWrap');
    const el   = document.createElement('div');
    el.className = 'toast ' + (type || '');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 2800);
}

// ── Escape helper ─────────────────────────────────────────────
function esc(s) {
    if (!s) return '—';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Cleanup on page unload
window.addEventListener('beforeunload', () => stopCamera(true));
</script>

<?php ob_end_flush(); ?>
</body>
</html>
