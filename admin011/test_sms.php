<?php
// test_sms.php — open directly in browser to test SMS
// DELETE THIS FILE after debugging is done
require '../vendor/autoload.php';
include '../msanii/includes/connect.php';

$settings = $pdo->query("SELECT * FROM inactivity_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// ── Change these two values to a real creator in your DB ──
$test_phone = '0712345678';   // <-- put your own number here
$test_name  = 'Test User';
// ─────────────────────────────────────────────────────────

$api_key   = trim($settings['talksasa_api_key'] ?? '');
$sender_id = trim($settings['talksasa_sender_id'] ?? 'KEYCHELE');

// Normalize phone
$digits = preg_replace('/\D/', '', $test_phone);
if (strlen($digits) === 10 && str_starts_with($digits, '0')) $digits = '254' . substr($digits, 1);

$message = "KEYCHELE TEST: Hi {$test_name}, this is a test SMS from Keychele Platform.";

$payload = [
    'api_key'   => $api_key,
    'sender_id' => $sender_id,
    'message'   => $message,
    'numbers'   => $digits,
];

$ch = curl_init('https://bulksms.talksasa.com/api/v3');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response  = curl_exec($ch);
$curl_err  = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
?>
<!DOCTYPE html>
<html>
<head>
  <title>SMS Debug</title>
  <style>
    body { font-family: monospace; padding: 30px; background: #f4f4f4; }
    pre  { background: #1e1e2e; color: #cdd6f4; padding: 20px; border-radius: 10px; white-space: pre-wrap; word-break: break-all; }
    .ok  { color: #a6e3a1; font-weight: bold; }
    .err { color: #f38ba8; font-weight: bold; }
    h2   { margin-top: 24px; }
  </style>
</head>
<body>
  <h1>TalkSasa SMS Debug</h1>

  <h2>Settings from DB</h2>
  <pre>API Key    : <?= htmlspecialchars($api_key ?: '(EMPTY — this is your problem!)') ?>
Sender ID  : <?= htmlspecialchars($sender_id) ?>
</pre>

  <h2>Request Sent</h2>
  <pre><?= htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT)) ?></pre>

  <h2>Result</h2>
  <pre>
cURL error : <?= $curl_err ? '<span class="err">'.htmlspecialchars($curl_err).'</span>' : '<span class="ok">none</span>' ?>

HTTP code  : <?= $http_code ?>

Raw response:
<?= htmlspecialchars($response ?: '(empty)') ?>
  </pre>

  <h2>Parsed Response</h2>
  <pre><?= htmlspecialchars(json_encode(json_decode($response, true), JSON_PRETTY_PRINT) ?: '(could not parse JSON)') ?></pre>

  <p style="color:red;font-weight:bold">⚠️ Delete this file after debugging!</p>
</body>
</html>