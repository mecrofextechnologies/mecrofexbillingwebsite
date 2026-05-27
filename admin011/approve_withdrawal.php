<?php
include '../msanii/includes/connect.php';
require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

// ── Params ────────────────────────────────────────────────────
$allowed_per_page = [10, 20, 50, 100];
$per_page         = (int)($_GET['per_page'] ?? 20);
if (!in_array($per_page, $allowed_per_page)) $per_page = 20;
$current_page     = max(1, (int)($_GET['p'] ?? 1));
$status_filter    = trim($_GET['status'] ?? '');
$search_q         = trim($_GET['q'] ?? '');

$dash_page = isset($_GET['page']) ? $_GET['page'] : null;

function wdrUrl(array $params, ?string $dp): string {
    if ($dp !== null) $params['page'] = $dp;
    return '?' . http_build_query($params);
}

$successMsg = $errorMsg = '';

// ── Phone Normalizer ──────────────────────────────────────────
// Supports: 07XXXXXXXX, 01XXXXXXXX, +2547XXXXXXXX, +2541XXXXXXXX
function normalizePhone(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    if (preg_match('/^254[17]\d{8}$/', $digits)) return $digits;
    if (preg_match('/^0[17]\d{8}$/',   $digits)) return '254' . substr($digits, 1);
    if (preg_match('/^[17]\d{8}$/',    $digits)) return '254' . $digits;
    return $digits;
}

// ── Extract M-Pesa Name from notes field ──────────────────────
// notes format: "Net: 1000 | Fee: 29 | M-Pesa Name: John Doe | 2FA: PIN+OTP"
function extractMpesaName(string $notes): string {
    if (preg_match('/M-Pesa Name:\s*([^|]+)/i', $notes, $m)) {
        return trim($m[1]);
    }
    return '—';
}

// ── SMS Helper (same credentials as creator side) ─────────────
function sendAdminWithdrawalSMS(PDO $pdo, string $phone, string $message): array {
    try {
        $sms = $pdo->query(
            "SELECT * FROM creatorwithdrawal_sms_settings WHERE provider='talksasa' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log("AdminSMS: DB error loading settings: " . $e->getMessage());
        return ['ok' => false, 'reason' => 'SMS configuration could not be loaded.'];
    }

    if (!$sms) {
        error_log("AdminSMS: no row in creatorwithdrawal_sms_settings");
        return ['ok' => false, 'reason' => 'SMS gateway not configured.'];
    }

    $rawPhone = $phone;
    $phone    = normalizePhone($phone);

    if (!preg_match('/^254[17]\d{8}$/', $phone)) {
        error_log("AdminSMS: invalid phone '{$rawPhone}' normalized to '{$phone}'");
        return ['ok' => false, 'reason' => "Invalid phone number: {$rawPhone}"];
    }

    $url    = rtrim($sms['api_endpoint'], '/');
    $token  = trim($sms['api_token']);
    $sender = trim($sms['sender_name']);

    if (empty($token)) {
        return ['ok' => false, 'reason' => 'SMS API token is missing.'];
    }

    $payload = json_encode([
        'recipient' => $phone,
        'sender_id' => $sender,
        'type'      => 'plain',
        'message'   => $message,
    ]);

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'reason' => 'cURL extension not available.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer {$token}",
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("AdminSMS: cURL error: $curlErr");
        return ['ok' => false, 'reason' => "Network error: $curlErr"];
    }
    if ($response === false || $response === '') {
        return ['ok' => false, 'reason' => "Empty response (HTTP $httpCode)"];
    }

    error_log("AdminSMS → $url | phone: $phone | HTTP $httpCode | resp: $response");

    $result = json_decode($response, true);

    if (in_array($httpCode, [401, 403])) {
        return ['ok' => false, 'reason' => "API token rejected (HTTP $httpCode)."];
    }
    if (!in_array($httpCode, [200, 201, 202])) {
        $apiMsg = $result['message'] ?? ($result['error'] ?? "HTTP $httpCode");
        return ['ok' => false, 'reason' => "Gateway error: $apiMsg"];
    }
    if (isset($result['status']) && in_array(strtolower($result['status']), ['error','failed','fail'])) {
        $apiMsg = $result['message'] ?? $result['error'] ?? $result['status'];
        return ['ok' => false, 'reason' => "Gateway failure: $apiMsg"];
    }

    return ['ok' => true, 'reason' => 'SMS sent successfully.'];
}

// ── Handle status update ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $request_id       = (int)$_POST['request_id'];
    $new_status       = $_POST['status'] ?? '';
    $transaction_code = trim($_POST['transaction_code'] ?? '');

    if (!in_array($new_status, ['processing', 'completed', 'rejected'])) {
        $errorMsg = "Invalid status selected.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT creator_id, amount, charge, total_amount, mpesa_number, status, notes
                FROM withdrawal_requests WHERE id = ?
            ");
            $stmt->execute([$request_id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) throw new Exception("Withdrawal request not found.");
            if ($req['status'] === 'completed')
                throw new Exception("This withdrawal has already been completed and is locked.");

            $stmt = $pdo->prepare("
                UPDATE withdrawal_requests
                SET status = ?, transaction_code = ?, processed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_status, $transaction_code ?: null, $request_id]);

            if ($new_status === 'completed') {
                if (empty($transaction_code))
                    throw new Exception("Transaction code is required when marking as Completed.");
                $stmt = $pdo->prepare("
                    UPDATE my_balance SET amount = amount - ? WHERE creator_id = ?
                ");
                $stmt->execute([$req['total_amount'], $req['creator_id']]);
            }

            // Fetch creator details (including phone)
            $stmt = $pdo->prepare("SELECT full_name, email, phone FROM creators WHERE id = ?");
            $stmt->execute([$req['creator_id']]);
            $creator = $stmt->fetch(PDO::FETCH_ASSOC);

            // ── SMS Notification ─────────────────────────────────────
            $smsSent = false; $smsReason = '';
            if ($creator && !empty($creator['phone'])) {
                $cName   = $creator['full_name'] ?? 'Creator';
                $netAmt  = 'KES ' . number_format($req['amount'], 2);
                $mpesaNo = $req['mpesa_number'];

                $smsText = match($new_status) {
                    'processing' =>
                        "Hello {$cName}, your Keychele withdrawal request of {$netAmt} is now being processed. "
                      . "Funds will be sent to {$mpesaNo} shortly. Thank you!",

                    'completed' =>
                        "Hello {$cName}, your Keychele withdrawal of {$netAmt} has been completed! "
                      . "Sent to {$mpesaNo}. M-Pesa Tx Code: {$transaction_code}. "
                      . "Thank you for using Keychele!",

                    'rejected' =>
                        "Hello {$cName}, unfortunately your Keychele withdrawal request of {$netAmt} "
                      . "could not be processed at this time. "
                      . "Please contact support if you believe this was an error.",

                    default => ''
                };

                if ($smsText) {
                    $smsResult = sendAdminWithdrawalSMS($pdo, $creator['phone'], $smsText);
                    $smsSent   = $smsResult['ok'];
                    $smsReason = $smsResult['reason'];
                    if (!$smsSent) {
                        error_log("AdminSMS: failed for request #$request_id — " . $smsReason);
                    }
                }
            }

            // ── Email Notification ───────────────────────────────────
            if ($creator && !empty($creator['email'])) {
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
                    $mail->setFrom('notifications@keychele.co.ke', 'Keychele');
                    $mail->addAddress($creator['email'], $creator['full_name'] ?? 'Creator');
                    $mail->isHTML(true);
                    $mail->CharSet = 'UTF-8';

                    if ($new_status === 'processing') {
                        $mail->Subject = "Your Withdrawal Request is Being Processed";
                        $body = "<h2>Withdrawal Request Update</h2><p>Hello {$creator['full_name']},</p><p>Your withdrawal request is now being processed.</p><ul><li><strong>Amount to receive:</strong> KES " . number_format($req['amount'], 2) . "</li><li><strong>To number:</strong> {$req['mpesa_number']}</li></ul><p>You will receive funds in your M-Pesa soon.</p><p>Thank you for using Keychele!</p>";
                    } elseif ($new_status === 'completed') {
                        $mail->Subject = "Your Withdrawal Has Been Completed";
                        $body = "<h2>Withdrawal Successful</h2><p>Hello {$creator['full_name']},</p><p>Your withdrawal has been approved and funds sent successfully:</p><ul><li><strong>Amount received:</strong> KES " . number_format($req['amount'], 2) . "</li><li><strong>M-Pesa fee:</strong> KES " . number_format($req['charge'], 2) . "</li><li><strong>Total deducted:</strong> KES " . number_format($req['total_amount'], 2) . "</li><li><strong>To number:</strong> {$req['mpesa_number']}</li><li><strong>Transaction code:</strong> " . htmlspecialchars($transaction_code) . "</li></ul><p>Thank you for using Keychele!</p>";
                    } else {
                        $mail->Subject = "Withdrawal Request Update - Not Processed";
                        $body = "<h2>Withdrawal Request Update</h2><p>Hello {$creator['full_name']},</p><p>Unfortunately, your withdrawal request could not be processed at this time.</p><ul><li><strong>Requested amount:</strong> KES " . number_format($req['amount'], 2) . "</li><li><strong>To number:</strong> {$req['mpesa_number']}</li></ul><p>Please contact support if you believe this was an error.</p>";
                    }
                    $mail->Body    = $body;
                    $mail->AltBody = strip_tags($body);
                    $mail->send();
                } catch (Exception $e) {
                    error_log("Email failed for request #$request_id: " . $mail->ErrorInfo);
                }
            }

            $pdo->commit();

            $smsNote = $smsSent
                ? '<span style="color:#059669"><i class="fas fa-comment-sms"></i> SMS sent</span>'
                : '<span style="color:#d97706"><i class="fas fa-comment-slash"></i> SMS failed</span>';

            $successMsg = "Request #{$request_id} updated to <strong>" . ucfirst($new_status) . "</strong> — email notified · {$smsNote}.";

        } catch (Exception $e) {
            $pdo->rollBack();
            $errorMsg = "Error: " . htmlspecialchars($e->getMessage());
        }
    }
}

// ── KPI aggregates ────────────────────────────────────────────
try {
    $kpi = $pdo->query("
        SELECT
            COUNT(*)                                                               AS total,
            SUM(status = 'pending')                                                AS pending,
            SUM(status = 'processing')                                             AS processing,
            SUM(status = 'completed')                                              AS completed,
            SUM(status = 'rejected')                                               AS rejected,
            SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END)       AS paid_out,
            SUM(CASE WHEN status = 'pending'   THEN total_amount ELSE 0 END)       AS pending_val
        FROM withdrawal_requests
    ")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $kpi = ['total'=>0,'pending'=>0,'processing'=>0,'completed'=>0,'rejected'=>0,'paid_out'=>0,'pending_val'=>0];
}

// ── Filtered count ────────────────────────────────────────────
try {
    $where_parts = []; $bind = [];
    if ($status_filter !== '') {
        $where_parts[] = "wr.status = :status";
        $bind[':status'] = $status_filter;
    }
    if ($search_q !== '') {
        $where_parts[] = "(c.full_name LIKE :q OR c.email LIKE :q OR wr.mpesa_number LIKE :q OR wr.transaction_code LIKE :q OR wr.notes LIKE :q)";
        $bind[':q'] = '%' . $search_q . '%';
    }
    $where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM withdrawal_requests wr LEFT JOIN creators c ON wr.creator_id = c.id $where_sql");
    $cnt->execute($bind);
    $filtered_total = (int)$cnt->fetchColumn();
    $total_pages    = max(1, (int)ceil($filtered_total / $per_page));
    $current_page   = min($current_page, $total_pages);
    $offset         = ($current_page - 1) * $per_page;

    $stmt = $pdo->prepare("
        SELECT wr.id, wr.creator_id, c.full_name AS creator_name, c.email AS creator_email,
               c.phone AS creator_phone,
               wr.mpesa_number, wr.amount, wr.charge, wr.total_amount,
               wr.transaction_code, wr.status, wr.requested_at, wr.processed_at, wr.notes
        FROM withdrawal_requests wr
        LEFT JOIN creators c ON wr.creator_id = c.id
        $where_sql
        ORDER BY wr.requested_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $per_page,  PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,    PDO::PARAM_INT);
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $requests = []; $filtered_total = 0; $total_pages = 1;
    $errorMsg = "Query error: " . htmlspecialchars($e->getMessage());
}

function timeAgoWdr(?string $d): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '—';
    $diff = time() - strtotime($d);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60) . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('d M Y', strtotime($d));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Withdrawal Requests — Keychele Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
/* ══════════════════════════════════════════
   DESIGN TOKENS
══════════════════════════════════════════ */
:root {
  --bg:          #f0f2f8;
  --surface:     #ffffff;
  --surface-2:   #f7f8fc;
  --border:      #e4e8f0;
  --border-dark: #d0d6e8;

  --navy:        #0d1b3e;
  --blue:        #2563eb;
  --blue-h:      #1d4ed8;
  --blue-l:      #dbeafe;
  --blue-ll:     #eff6ff;

  --emerald:     #059669;
  --emerald-l:   #d1fae5;
  --amber:       #d97706;
  --amber-l:     #fef3c7;
  --red:         #dc2626;
  --red-l:       #fee2e2;
  --sky:         #0284c7;
  --sky-l:       #e0f2fe;
  --violet:      #7c3aed;
  --violet-l:    #ede9fe;
  --slate:       #64748b;
  --slate-l:     #f1f5f9;
  --teal:        #0d9488;
  --teal-l:      #ccfbf1;

  --text:        #0d1b3e;
  --text-2:      #4b5580;
  --text-3:      #8b95b8;

  --radius:      14px;
  --radius-sm:   9px;
  --shadow-sm:   0 1px 3px rgba(13,27,62,.06), 0 2px 8px rgba(13,27,62,.04);
  --shadow:      0 2px 8px rgba(13,27,62,.08), 0 8px 24px rgba(13,27,62,.06);
  --shadow-lg:   0 8px 32px rgba(13,27,62,.14);

  --font:        'Outfit', sans-serif;
  --mono:        'JetBrains Mono', monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  font-family: var(--font);
  color: var(--text);
  font-size: 14px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}

.shell { padding: 28px; max-width: 1800px; margin: 0 auto; }

/* ── PAGE HEADER ── */
.pg-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:26px; gap:16px; flex-wrap:wrap; }
.pg-header-left h1 {
  font-size:1.55rem; font-weight:800; letter-spacing:-.03em; color:var(--navy);
  display:flex; align-items:center; gap:10px;
}
.pg-header-left h1 .icon-wrap {
  width:38px; height:38px; background:var(--blue); border-radius:10px;
  display:inline-flex; align-items:center; justify-content:center;
  font-size:.85rem; color:#fff; flex-shrink:0;
}
.pg-header-left p { color:var(--text-2); font-size:.82rem; margin-top:3px; padding-left:48px; }

/* ── KPI GRID ── */
.kpi-grid {
  display:grid; grid-template-columns:repeat(6,1fr);
  gap:14px; margin-bottom:26px;
}
@media (max-width:1100px) { .kpi-grid { grid-template-columns:repeat(3,1fr); } }
@media (max-width:600px)  { .kpi-grid { grid-template-columns:repeat(2,1fr); } }

.kpi {
  background:var(--surface); border-radius:var(--radius);
  box-shadow:var(--shadow-sm); padding:18px 20px;
  position:relative; overflow:hidden;
  transition:transform .2s, box-shadow .2s;
  border:1px solid var(--border);
}
.kpi:hover { transform:translateY(-3px); box-shadow:var(--shadow); }
.kpi-bar {
  position:absolute; left:0; top:0; bottom:0; width:4px;
  border-radius:var(--radius) 0 0 var(--radius);
}
.kpi.c-blue   .kpi-bar { background:var(--blue); }
.kpi.c-green  .kpi-bar { background:var(--emerald); }
.kpi.c-amber  .kpi-bar { background:var(--amber); }
.kpi.c-red    .kpi-bar { background:var(--red); }
.kpi.c-sky    .kpi-bar { background:var(--sky); }
.kpi.c-violet .kpi-bar { background:var(--violet); }

.kpi-icon {
  width:36px; height:36px; border-radius:9px;
  display:flex; align-items:center; justify-content:center;
  font-size:.78rem; margin-bottom:12px;
}
.kpi.c-blue   .kpi-icon { background:var(--blue-l);   color:var(--blue); }
.kpi.c-green  .kpi-icon { background:var(--emerald-l);color:var(--emerald); }
.kpi.c-amber  .kpi-icon { background:var(--amber-l);  color:var(--amber); }
.kpi.c-red    .kpi-icon { background:var(--red-l);    color:var(--red); }
.kpi.c-sky    .kpi-icon { background:var(--sky-l);    color:var(--sky); }
.kpi.c-violet .kpi-icon { background:var(--violet-l); color:var(--violet); }

.kpi-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--text-3); margin-bottom:4px; }
.kpi-value { font-size:1.8rem; font-weight:800; line-height:1; letter-spacing:-.04em; }
.kpi.c-blue   .kpi-value { color:var(--blue); }
.kpi.c-green  .kpi-value { color:var(--emerald); }
.kpi.c-amber  .kpi-value { color:var(--amber); }
.kpi.c-red    .kpi-value { color:var(--red); }
.kpi.c-sky    .kpi-value { color:var(--sky); }
.kpi.c-violet .kpi-value { color:var(--violet); }
.kpi-sub { font-size:.72rem; color:var(--text-3); margin-top:3px; }

/* ── CARD ── */
.card {
  background:var(--surface); border-radius:var(--radius);
  box-shadow:var(--shadow-sm); border:1px solid var(--border); overflow:hidden;
}
.card-head {
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; padding:16px 20px; border-bottom:1px solid var(--border);
  flex-wrap:wrap; background:var(--surface-2);
}
.card-title { font-size:.92rem; font-weight:700; color:var(--navy); display:flex; align-items:center; gap:8px; }
.card-title i { color:var(--blue); font-size:.8rem; }

/* ── TOOLBAR ── */
.toolbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.search-wrap { position:relative; }
.search-wrap i { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:.75rem; pointer-events:none; }
.search-input {
  height:36px; width:220px; padding:0 12px 0 34px;
  border:1.5px solid var(--border-dark); border-radius:var(--radius-sm);
  font-family:var(--font); font-size:.82rem; color:var(--text);
  background:var(--surface); outline:none;
  transition:border .16s, box-shadow .16s;
}
.search-input:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.select-sm {
  height:36px; padding:0 10px;
  border:1.5px solid var(--border-dark); border-radius:var(--radius-sm);
  font-family:var(--font); font-size:.82rem; color:var(--text);
  background:var(--surface); outline:none; cursor:pointer;
  transition:border .16s;
}
.select-sm:focus { border-color:var(--blue); }
.btn-sm-ui {
  height:36px; padding:0 14px; border:none; border-radius:var(--radius-sm);
  font-family:var(--font); font-size:.8rem; font-weight:600;
  cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:all .15s;
}
.btn-sm-blue  { background:var(--blue); color:#fff; }
.btn-sm-blue:hover  { background:var(--blue-h); }
.btn-sm-ghost { background:var(--bg); color:var(--text-2); border:1px solid var(--border-dark); }
.btn-sm-ghost:hover { color:var(--red); border-color:var(--red); background:var(--red-l); }

/* ── TABLE ── */
.tbl-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; }
thead th {
  padding:10px 14px; background:var(--surface-2);
  border-bottom:1px solid var(--border);
  font-size:.68rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.08em; color:var(--text-3); white-space:nowrap; user-select:none;
}
tbody tr { border-bottom:1px solid var(--border); transition:background .1s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:var(--blue-ll); }
tbody tr.row-pending    { background:#fffdf0; }
tbody tr.row-pending:hover { background:#fff8d6; }
tbody tr.row-rejected   { background:#fff5f5; }
tbody tr.row-rejected:hover { background:#ffecec; }
tbody tr.row-processing { background:#f0f9ff; }
tbody tr.row-processing:hover { background:#e0f2fe; }

td { padding:11px 14px; font-size:.82rem; color:var(--text); vertical-align:middle; }

/* ── CREATOR CELL ── */
.creator-cell { display:flex; align-items:center; gap:10px; }
.avatar {
  width:34px; height:34px; border-radius:9px;
  background:var(--blue-l); color:var(--blue);
  font-weight:800; font-size:.7rem;
  display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.creator-name  { font-weight:600; line-height:1.2; font-size:.82rem; }
.creator-email { font-size:.68rem; color:var(--text-3); font-family:var(--mono); }
.creator-phone { font-size:.68rem; color:var(--teal); font-family:var(--mono); }

/* ── M-PESA DESTINATION CELL ── */
.mpesa-cell { display:flex; flex-direction:column; gap:2px; }
.mpesa-number { font-family:var(--mono); font-size:.8rem; font-weight:600; color:var(--text); }
.mpesa-name {
  display:inline-flex; align-items:center; gap:4px;
  font-size:.7rem; font-weight:600;
  background:var(--teal-l); color:var(--teal);
  padding:2px 8px; border-radius:100px; width:fit-content;
}
.mpesa-name i { font-size:.58rem; }

/* ── BADGES ── */
.badge {
  display:inline-flex; align-items:center; gap:4px;
  padding:3px 10px; border-radius:100px;
  font-size:.68rem; font-weight:700; letter-spacing:.02em; white-space:nowrap;
}
.badge-pending    { background:var(--amber-l);   color:#92400e; }
.badge-processing { background:var(--sky-l);     color:#075985; }
.badge-completed  { background:var(--emerald-l); color:#065f46; }
.badge-rejected   { background:var(--red-l);     color:#991b1b; }
.badge-locked     { background:var(--slate-l);   color:var(--slate); }

/* ── INLINE ACTION FORM ── */
.action-form { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.form-select-ui {
  height:32px; padding:0 8px;
  border:1.5px solid var(--border-dark); border-radius:var(--radius-sm);
  font-family:var(--font); font-size:.78rem; color:var(--text);
  background:var(--surface); outline:none; cursor:pointer; min-width:130px;
  transition:border .15s;
}
.form-select-ui:focus { border-color:var(--blue); }
.tx-input {
  height:32px; padding:0 10px;
  border:1.5px solid var(--border-dark); border-radius:var(--radius-sm);
  font-family:var(--mono); font-size:.75rem; color:var(--text);
  background:var(--surface); outline:none; width:155px;
  transition:border .15s, opacity .15s;
}
.tx-input:focus  { border-color:var(--blue); }
.tx-input.hidden { display:none; }
.tx-input[readonly] { background:var(--surface-2); color:var(--text-3); cursor:not-allowed; }
.btn-update {
  height:32px; padding:0 14px; border:none; border-radius:var(--radius-sm);
  font-family:var(--font); font-size:.78rem; font-weight:700;
  background:var(--blue); color:#fff; cursor:pointer; transition:background .15s;
  display:inline-flex; align-items:center; gap:5px;
}
.btn-update:hover { background:var(--blue-h); }
.locked-label {
  font-size:.76rem; font-style:italic; color:var(--text-3);
  display:flex; align-items:center; gap:5px;
}

/* ── SMS INDICATOR ── */
.sms-pill {
  display:inline-flex; align-items:center; gap:4px;
  font-size:.68rem; font-weight:700; padding:2px 8px;
  border-radius:100px;
}
.sms-pill.sent   { background:var(--teal-l);  color:var(--teal); }
.sms-pill.unsent { background:var(--amber-l); color:var(--amber); }

/* ── AMOUNT CELLS ── */
.amt-net   { font-weight:700; color:var(--emerald); }
.amt-fee   { color:var(--text-2); }
.amt-total { font-weight:700; color:var(--red); }
.tx-code   { font-family:var(--mono); font-size:.74rem; color:var(--text-2); background:var(--bg); padding:2px 7px; border-radius:5px; }

/* ── PAGINATION ── */
.pagi-wrap {
  display:flex; align-items:center; justify-content:space-between;
  padding:14px 20px; border-top:1px solid var(--border);
  background:var(--surface-2); flex-wrap:wrap; gap:10px;
}
.pagi-info  { font-size:.75rem; color:var(--text-3); }
.pagi-links { display:flex; gap:4px; }
.pagi-btn {
  height:32px; min-width:32px; padding:0 9px;
  border:1.5px solid var(--border-dark); border-radius:7px;
  background:var(--surface); font-family:var(--font);
  font-size:.78rem; font-weight:500; color:var(--text-2);
  cursor:pointer; text-decoration:none;
  display:inline-flex; align-items:center; justify-content:center;
  transition:all .14s;
}
.pagi-btn:hover:not(.pagi-active):not(.pagi-off) {
  border-color:var(--blue); color:var(--blue); background:var(--blue-ll);
}
.pagi-active { background:var(--blue); border-color:var(--blue); color:#fff; font-weight:700; }
.pagi-off    { opacity:.35; pointer-events:none; }
.pagi-dots   { border:none; background:none; color:var(--text-3); pointer-events:none; }

/* ── EMPTY ── */
.empty { text-align:center; padding:60px 20px; color:var(--text-3); }
.empty i { font-size:2rem; opacity:.3; display:block; margin-bottom:12px; }
.empty p  { font-size:.85rem; }

/* ── ALERTS ── */
.alert-success-ui {
  background:var(--emerald-l); border:1px solid #6ee7b7;
  border-radius:var(--radius-sm); padding:12px 16px;
  color:#065f46; font-size:.84rem; margin-bottom:0;
  display:flex; align-items:center; gap:8px;
}
.alert-err-ui {
  background:var(--red-l); border:1px solid #fca5a5;
  border-radius:var(--radius-sm); padding:12px 16px;
  color:#991b1b; font-size:.84rem; margin-bottom:0;
  display:flex; align-items:center; gap:8px;
}

/* ── SWEETALERT ── */
.swal2-popup  { font-family:var(--font) !important; border-radius:16px !important; }
.swal2-title  { font-family:var(--font) !important; font-weight:800 !important; }
.swal2-confirm { background:var(--blue) !important; border-radius:8px !important; font-family:var(--font) !important; font-weight:600 !important; }

/* ── SMS PREVIEW BOX (inside SweetAlert) ── */
.sms-preview-box {
  background:#f0fdf4; border:1px solid #6ee7b7; border-radius:10px;
  padding:12px 14px; margin-top:12px; text-align:left;
  font-size:.82rem; color:#065f46; line-height:1.6;
}
.sms-preview-box strong { display:block; font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; color:#059669; margin-bottom:4px; }

@media (max-width:680px) {
  .shell { padding:14px; }
  .search-input { width:100%; }
}
</style>
</head>
<body>
<div class="shell">

  <!-- ── PAGE HEADER ── -->
  <div class="pg-header">
    <div class="pg-header-left">
      <h1>
        <span class="icon-wrap"><i class="fas fa-hand-holding-usd"></i></span>
        Withdrawal Requests
      </h1>
      <p>Review, process &amp; track all creator withdrawal requests · Email + SMS notifications on every update</p>
    </div>
  </div>

  <!-- ── KPI STRIP ── -->
  <div class="kpi-grid">
    <div class="kpi c-blue">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-list-alt"></i></div>
      <div class="kpi-label">Total Requests</div>
      <div class="kpi-value"><?= number_format($kpi['total']) ?></div>
      <div class="kpi-sub">all time</div>
    </div>
    <div class="kpi c-amber">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-hourglass-half"></i></div>
      <div class="kpi-label">Pending</div>
      <div class="kpi-value"><?= number_format($kpi['pending']) ?></div>
      <div class="kpi-sub">KSh <?= number_format($kpi['pending_val'], 0) ?> queued</div>
    </div>
    <div class="kpi c-sky">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-spinner"></i></div>
      <div class="kpi-label">Processing</div>
      <div class="kpi-value"><?= number_format($kpi['processing']) ?></div>
      <div class="kpi-sub">in progress</div>
    </div>
    <div class="kpi c-green">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
      <div class="kpi-label">Completed</div>
      <div class="kpi-value"><?= number_format($kpi['completed']) ?></div>
      <div class="kpi-sub">paid out</div>
    </div>
    <div class="kpi c-red">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-times-circle"></i></div>
      <div class="kpi-label">Rejected</div>
      <div class="kpi-value"><?= number_format($kpi['rejected']) ?></div>
      <div class="kpi-sub">declined</div>
    </div>
    <div class="kpi c-violet">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-wallet"></i></div>
      <div class="kpi-label">Total Paid Out</div>
      <div class="kpi-value" style="font-size:1.3rem">KSh <?= number_format($kpi['paid_out'], 0) ?></div>
      <div class="kpi-sub">completed only</div>
    </div>
  </div>

  <!-- ── MAIN TABLE CARD ── -->
  <div class="card">
    <div class="card-head">
      <div class="card-title">
        <i class="fas fa-hand-holding-usd"></i>
        Requests
        <span style="font-weight:400;font-size:.76rem;color:var(--text-3)">
          (<?= number_format($filtered_total) ?><?= ($search_q || $status_filter) ? ' matching' : ' total' ?>)
        </span>
      </div>
      <!-- Filter form -->
      <form method="GET" id="filterForm" style="display:contents">
        <?php if ($dash_page): ?>
          <input type="hidden" name="page" value="<?= htmlspecialchars($dash_page) ?>">
        <?php endif; ?>
        <input type="hidden" name="p" value="1" id="pageHidden">
        <div class="toolbar">
          <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" name="q" class="search-input" id="searchInput"
                   value="<?= htmlspecialchars($search_q) ?>"
                   placeholder="Name, email, M-Pesa, Tx…">
          </div>
          <select name="status" class="select-sm" id="statusSel">
            <option value=""           <?= $status_filter===''           ?'selected':'' ?>>All Statuses</option>
            <option value="pending"    <?= $status_filter==='pending'    ?'selected':'' ?>>Pending</option>
            <option value="processing" <?= $status_filter==='processing' ?'selected':'' ?>>Processing</option>
            <option value="completed"  <?= $status_filter==='completed'  ?'selected':'' ?>>Completed</option>
            <option value="rejected"   <?= $status_filter==='rejected'   ?'selected':'' ?>>Rejected</option>
          </select>
          <select name="per_page" class="select-sm" id="perPageSel">
            <?php foreach ([10,20,50,100] as $n): ?>
              <option value="<?= $n ?>" <?= $per_page==$n?'selected':'' ?>><?= $n ?> / page</option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn-sm-ui btn-sm-blue"><i class="fas fa-filter"></i> Filter</button>
          <?php if ($search_q || $status_filter || $per_page != 20): ?>
            <a href="<?= htmlspecialchars(wdrUrl(['p'=>1,'per_page'=>20], $dash_page)) ?>"
               class="btn-sm-ui btn-sm-ghost">
              <i class="fas fa-times"></i> Clear
            </a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Inline alerts -->
    <?php if ($successMsg): ?>
      <div style="padding:14px 20px;border-bottom:1px solid var(--border)">
        <div class="alert-success-ui"><i class="fas fa-check-circle"></i><span><?= $successMsg ?></span></div>
      </div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
      <div style="padding:14px 20px;border-bottom:1px solid var(--border)">
        <div class="alert-err-ui"><i class="fas fa-exclamation-circle"></i><?= $errorMsg ?></div>
      </div>
    <?php endif; ?>

    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:36px">#</th>
            <th>Creator</th>
            <th>M-Pesa Destination</th><!-- number + name combined -->
            <th>Net Amount</th>
            <th>Fee</th>
            <th>Total</th>
            <th>Status</th>
            <th>Requested</th>
            <th>Processed</th>
            <th>Tx Code</th>
            <th style="text-align:right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($requests)): ?>
            <?php foreach ($requests as $i => $row):
              $status    = strtolower($row['status'] ?? 'pending');
              $isLocked  = $status === 'completed';
              $mpesaName = extractMpesaName($row['notes'] ?? '');
              $rc = match($status) {
                  'pending'    => 'row-pending',
                  'rejected'   => 'row-rejected',
                  'processing' => 'row-processing',
                  default      => ''
              };
              $badge_class = match($status) {
                  'pending'    => 'badge-pending',
                  'processing' => 'badge-processing',
                  'completed'  => 'badge-completed',
                  'rejected'   => 'badge-rejected',
                  default      => 'badge-locked'
              };
              $badge_icon = match($status) {
                  'pending'    => 'fa-hourglass-half',
                  'processing' => 'fa-spinner',
                  'completed'  => 'fa-check',
                  'rejected'   => 'fa-times',
                  default      => 'fa-circle'
              };
              $parts = explode(' ', trim($row['creator_name'] ?? ''));
              $init  = strtoupper(substr($parts[0] ?? '?', 0, 1) . substr(end($parts), 0, 1));
            ?>
            <tr class="<?= $rc ?>">
              <td style="color:var(--text-3);font-size:.75rem;font-family:var(--mono)"><?= $offset + $i + 1 ?></td>

              <!-- Creator -->
              <td>
                <div class="creator-cell">
                  <div class="avatar"><?= $init ?></div>
                  <div>
                    <div class="creator-name"><?= htmlspecialchars($row['creator_name'] ?? 'ID: '.$row['creator_id']) ?></div>
                    <div class="creator-email"><?= htmlspecialchars($row['creator_email'] ?? '—') ?></div>
                    <?php if (!empty($row['creator_phone'])): ?>
                      <div class="creator-phone">
                        <i class="fas fa-phone" style="font-size:.58rem;margin-right:3px"></i><?= htmlspecialchars($row['creator_phone']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <!-- M-Pesa Destination: number + extracted name -->
              <td>
                <div class="mpesa-cell">
                  <span class="mpesa-number"><?= htmlspecialchars($row['mpesa_number'] ?? '—') ?></span>
                  <?php if ($mpesaName !== '—'): ?>
                    <span class="mpesa-name">
                      <i class="fas fa-user-check"></i>
                      <?= htmlspecialchars($mpesaName) ?>
                    </span>
                  <?php else: ?>
                    <span style="font-size:.68rem;color:var(--text-3);font-style:italic">name not recorded</span>
                  <?php endif; ?>
                </div>
              </td>

              <td><span class="amt-net">KSh <?= number_format($row['amount'] ?? 0, 2) ?></span></td>
              <td><span class="amt-fee">KSh <?= number_format($row['charge'] ?? 0, 2) ?></span></td>
              <td><span class="amt-total">KSh <?= number_format($row['total_amount'] ?? 0, 2) ?></span></td>

              <td>
                <span class="badge <?= $badge_class ?>">
                  <i class="fas <?= $badge_icon ?>" style="font-size:.55rem"></i>
                  <?= ucfirst($status) ?>
                </span>
              </td>

              <td style="white-space:nowrap">
                <div style="font-size:.79rem"><?= date('d M Y', strtotime($row['requested_at'])) ?></div>
                <div style="font-size:.68rem;color:var(--text-3);font-family:var(--mono)"><?= date('H:i', strtotime($row['requested_at'])) ?></div>
              </td>

              <td style="font-size:.78rem;color:var(--text-3);white-space:nowrap">
                <?= $row['processed_at'] ? timeAgoWdr($row['processed_at']) : '<span style="font-style:italic">—</span>' ?>
              </td>

              <td>
                <?php if ($row['transaction_code']): ?>
                  <span class="tx-code"><?= htmlspecialchars($row['transaction_code']) ?></span>
                <?php else: ?>
                  <span style="color:var(--text-3);font-size:.76rem">—</span>
                <?php endif; ?>
              </td>

              <!-- Action -->
              <td style="text-align:right">
                <?php if ($isLocked): ?>
                  <span class="locked-label">
                    <i class="fas fa-lock" style="font-size:.65rem;color:var(--text-3)"></i> Locked
                  </span>
                <?php else: ?>
                  <form method="POST" class="action-form update-form"
                        data-id="<?= $row['id'] ?>"
                        data-name="<?= htmlspecialchars($row['creator_name'] ?? '') ?>"
                        data-mpesa-name="<?= htmlspecialchars($mpesaName) ?>"
                        data-mpesa-number="<?= htmlspecialchars($row['mpesa_number'] ?? '') ?>"
                        data-phone="<?= htmlspecialchars($row['creator_phone'] ?? '') ?>"
                        data-amount="<?= number_format($row['total_amount'] ?? 0, 2) ?>"
                        data-net="<?= number_format($row['amount'] ?? 0, 2) ?>">
                    <?php if ($dash_page): ?>
                      <input type="hidden" name="page" value="<?= htmlspecialchars($dash_page) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                    <select name="status" class="form-select-ui status-sel">
                      <option value="" disabled <?= $status === '' ? 'selected' : '' ?>>Action…</option>
                      <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>Processing</option>
                      <option value="completed"  <?= $status === 'completed'  ? 'selected' : '' ?>>Completed</option>
                      <option value="rejected"   <?= $status === 'rejected'   ? 'selected' : '' ?>>Rejected</option>
                    </select>
                    <input type="text" name="transaction_code"
                           class="tx-input <?= $status !== 'completed' ? 'hidden' : '' ?>"
                           placeholder="Tx Code (required)"
                           value="<?= htmlspecialchars($row['transaction_code'] ?? '') ?>"
                           <?= $status === 'completed' ? 'readonly' : '' ?>>
                    <button type="button" class="btn-update submit-update-btn">
                      <i class="fas fa-check"></i> Update
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="11">
              <div class="empty">
                <i class="fas fa-folder-open"></i>
                <p>No withdrawal requests found<?= ($search_q || $status_filter) ? ' matching your filters' : '' ?>.</p>
              </div>
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ── PAGINATION ── -->
    <?php if ($total_pages > 1 || $filtered_total > 0):
      $showFrom = $offset + 1;
      $showTo   = min($offset + $per_page, $filtered_total);
    ?>
    <div class="pagi-wrap">
      <div class="pagi-info">
        Showing <?= number_format($showFrom) ?>–<?= number_format($showTo) ?> of <?= number_format($filtered_total) ?> requests
      </div>
      <div class="pagi-links">
        <?php
          function pgWdrLink(int $p, int $pp, string $q, string $st, ?string $dp): string {
              $params = ['p' => $p, 'per_page' => $pp];
              if ($q  !== '') $params['q']      = $q;
              if ($st !== '') $params['status'] = $st;
              if ($dp !== null) $params['page'] = $dp;
              return '?' . http_build_query($params);
          }
          $win_start = max(1, $current_page - 2);
          $win_end   = min($total_pages, $current_page + 2);
        ?>
        <!-- Prev -->
        <?php if ($current_page > 1): ?>
          <a href="<?= pgWdrLink($current_page-1,$per_page,$search_q,$status_filter,$dash_page) ?>" class="pagi-btn">
            <i class="fas fa-chevron-left"></i>
          </a>
        <?php else: ?>
          <span class="pagi-btn pagi-off"><i class="fas fa-chevron-left"></i></span>
        <?php endif; ?>

        <?php if ($win_start > 1): ?>
          <a href="<?= pgWdrLink(1,$per_page,$search_q,$status_filter,$dash_page) ?>" class="pagi-btn">1</a>
          <?php if ($win_start > 2): ?><span class="pagi-btn pagi-dots">…</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $win_start; $i <= $win_end; $i++): ?>
          <a href="<?= pgWdrLink($i,$per_page,$search_q,$status_filter,$dash_page) ?>"
             class="pagi-btn <?= $i === $current_page ? 'pagi-active' : '' ?>">
            <?= $i ?>
          </a>
        <?php endfor; ?>
        <?php if ($win_end < $total_pages): ?>
          <?php if ($win_end < $total_pages-1): ?><span class="pagi-btn pagi-dots">…</span><?php endif; ?>
          <a href="<?= pgWdrLink($total_pages,$per_page,$search_q,$status_filter,$dash_page) ?>" class="pagi-btn"><?= $total_pages ?></a>
        <?php endif; ?>

        <!-- Next -->
        <?php if ($current_page < $total_pages): ?>
          <a href="<?= pgWdrLink($current_page+1,$per_page,$search_q,$status_filter,$dash_page) ?>" class="pagi-btn">
            <i class="fas fa-chevron-right"></i>
          </a>
        <?php else: ?>
          <span class="pagi-btn pagi-off"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div><!-- /card -->

</div><!-- /shell -->

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
/* ── AUTO-SUBMIT ON SELECT CHANGE ── */
document.getElementById('perPageSel')?.addEventListener('change', () => {
  document.getElementById('pageHidden').value = '1';
  document.getElementById('filterForm').submit();
});
document.getElementById('statusSel')?.addEventListener('change', () => {
  document.getElementById('pageHidden').value = '1';
  document.getElementById('filterForm').submit();
});
document.getElementById('searchInput')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') document.getElementById('pageHidden').value = '1';
});

/* ── TOGGLE TX CODE INPUT ── */
document.querySelectorAll('.status-sel').forEach(sel => {
  toggleTx(sel);
  sel.addEventListener('change', () => toggleTx(sel));
});
function toggleTx(sel) {
  const form    = sel.closest('.action-form');
  const txInput = form.querySelector('.tx-input');
  if (sel.value === 'completed') {
    txInput.classList.remove('hidden');
    txInput.required = true;
  } else {
    txInput.classList.add('hidden');
    txInput.required = false;
  }
}

/* ── BUILD SMS PREVIEW TEXT ── */
function buildSmsPreview(newStatus, name, netAmt, totalAmt, mpesaNumber, txCode) {
  if (newStatus === 'processing') {
    return `Hello ${name}, your Keychele withdrawal request of KES ${netAmt} is now being processed. Funds will be sent to ${mpesaNumber} shortly. Thank you!`;
  } else if (newStatus === 'completed') {
    return `Hello ${name}, your Keychele withdrawal of KES ${netAmt} has been completed! Sent to ${mpesaNumber}. M-Pesa Tx Code: ${txCode}. Thank you for using Keychele!`;
  } else if (newStatus === 'rejected') {
    return `Hello ${name}, unfortunately your Keychele withdrawal request of KES ${netAmt} could not be processed at this time. Please contact support if you believe this was an error.`;
  }
  return '';
}

/* ── CONFIRM BEFORE SUBMIT ── */
document.querySelectorAll('.submit-update-btn').forEach(btn => {
  btn.addEventListener('click', async function () {
    const form        = this.closest('.action-form');
    const sel         = form.querySelector('.status-sel');
    const newStatus   = sel.value;
    const txInput     = form.querySelector('.tx-input');
    const name        = form.dataset.name;
    const mpesaName   = form.dataset.mpesaName;
    const mpesaNumber = form.dataset.mpesaNumber;
    const phone       = form.dataset.phone;
    const totalAmt    = form.dataset.amount;
    const netAmt      = form.dataset.net;
    const txCode      = txInput?.value?.trim() || '';

    if (!newStatus) {
      await Swal.fire({ icon: 'warning', title: 'Select an Action', text: 'Please choose a status first.' });
      return;
    }
    if (newStatus === 'completed' && !txCode) {
      await Swal.fire({ icon: 'warning', title: 'Tx Code Required', text: 'Enter the M-Pesa transaction code to mark as Completed.' });
      txInput.focus();
      return;
    }

    const iconMap  = { processing: 'info', completed: 'success', rejected: 'warning' };
    const colorMap = { processing: '#0284c7', completed: '#059669', rejected: '#d97706' };

    // Build confirmation details including SMS preview
    const smsText    = buildSmsPreview(newStatus, name, netAmt, totalAmt, mpesaNumber, txCode);
    const phoneLabel = phone
      ? `<div style="font-size:.78rem;margin-top:6px;color:#4b5580">📱 SMS will be sent to <strong>${phone}</strong></div>`
      : `<div style="font-size:.78rem;margin-top:6px;color:#d97706">⚠️ No phone number on file — SMS will be skipped</div>`;

    const smsBlock = smsText
      ? `<div class="sms-preview-box">
           <strong><i class="fas fa-comment-sms" style="margin-right:4px"></i>SMS Preview</strong>
           ${smsText}
           ${phoneLabel}
         </div>`
      : '';

    const mpesaBlock = `<div style="font-size:.8rem;color:#4b5580;margin-top:8px;background:#f0f9ff;border-radius:8px;padding:8px 12px;text-align:left">
      <i class="fas fa-mobile-screen-button" style="color:#0284c7;margin-right:4px"></i>
      Sending to: <strong>${mpesaNumber}</strong>
      ${mpesaName && mpesaName !== '—' ? `&nbsp;·&nbsp;<span style="color:#0d9488;font-weight:600">${mpesaName}</span>` : ''}
    </div>`;

    const msgMap = {
      processing: `Mark <strong>${name}</strong>'s request as <strong>Processing</strong>?${mpesaBlock}${smsBlock}`,
      completed:  `Confirm payment of <strong>KSh ${totalAmt}</strong> to <strong>${name}</strong>?${mpesaBlock}${smsBlock}`,
      rejected:   `Reject the withdrawal request from <strong>${name}</strong>?${smsBlock}`
    };

    const result = await Swal.fire({
      icon            : iconMap[newStatus] || 'question',
      title           : 'Confirm Update',
      html            : msgMap[newStatus] || `Update request to <strong>${newStatus}</strong>?`,
      showCancelButton: true,
      confirmButtonText: '<i class="fas fa-check" style="margin-right:5px"></i>Yes, Update',
      cancelButtonText : 'Cancel',
      confirmButtonColor: colorMap[newStatus] || '#2563eb',
      width           : '520px',
    });

    if (result.isConfirmed) {
      this.disabled = true;
      this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      form.submit();
    }
  });
});
</script>
</body>
</html>