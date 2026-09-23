<?php
/* Talksasa SMS sender used by the automatic withdrawal flow. Same gateway,
   same credential table (creatorwithdrawal_sms_settings) and same request
   shape as the existing MecroGuard OTP sender in request_withdrawals.php —
   just shared so the B2C result callback can reuse it.

   Both Talksasa tables (creatorwithdrawal_sms_settings for withdrawal SMS,
   admin_sms_settings for platform SMS) are edited from
   Admin01 → Settings → SMS Settings (admin011/sms_settings.php). */

const KC_TALKSASA_TABLES = ['creatorwithdrawal_sms_settings', 'admin_sms_settings'];
const KC_TALKSASA_DEFAULT_ENDPOINT = 'https://bulksms.talksasa.com/api/v3/sms/send';

function kc_talksasa_ensure_table(PDO $pdo, string $table): void {
    if (!in_array($table, KC_TALKSASA_TABLES, true)) throw new InvalidArgumentException('Unknown SMS table');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `$table` (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            provider     VARCHAR(50)  NOT NULL DEFAULT 'talksasa',
            api_id       VARCHAR(100) NOT NULL DEFAULT '',
            api_token    VARCHAR(255) NOT NULL DEFAULT '',
            sender_name  VARCHAR(50)  NOT NULL DEFAULT 'TALKSASA',
            api_endpoint VARCHAR(255) NOT NULL DEFAULT '" . KC_TALKSASA_DEFAULT_ENDPOINT . "',
            created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
}

function kc_talksasa_settings(PDO $pdo, string $table = 'creatorwithdrawal_sms_settings'): ?array {
    if (!in_array($table, KC_TALKSASA_TABLES, true)) return null;
    try {
        $row = $pdo->query("SELECT * FROM `$table` WHERE provider = 'talksasa' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/* Returns ['ok' => bool, 'reason' => string]. Reason never contains the token. */
function kc_talksasa_send(PDO $pdo, string $phone, string $message, string $table = 'creatorwithdrawal_sms_settings'): array {
    $sms = kc_talksasa_settings($pdo, $table);
    if (!$sms)                               return ['ok' => false, 'reason' => 'SMS gateway not configured'];
    $token = trim((string)($sms['api_token'] ?? ''));
    if ($token === '')                       return ['ok' => false, 'reason' => 'SMS API token missing'];
    $to = kc_normalize_phone($phone);
    if (!$to)                                return ['ok' => false, 'reason' => 'Invalid recipient phone'];
    if (!function_exists('curl_init'))       return ['ok' => false, 'reason' => 'cURL unavailable'];

    $url = rtrim((string)($sms['api_endpoint'] ?? '') ?: KC_TALKSASA_DEFAULT_ENDPOINT, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'recipient' => $to,
            'sender_id' => trim((string)($sms['sender_name'] ?? '')),
            'type'      => 'plain',
            'message'   => $message,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', "Authorization: Bearer {$token}"],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr)                                  return ['ok' => false, 'reason' => 'Network error: ' . $curlErr];
    if (in_array($httpCode, [401, 403], true))     return ['ok' => false, 'reason' => "API token rejected (HTTP $httpCode)"];
    $result = is_string($response) ? json_decode($response, true) : null;
    if (!in_array($httpCode, [200, 201, 202], true)) {
        return ['ok' => false, 'reason' => 'Gateway error: ' . substr((string)($result['message'] ?? "HTTP $httpCode"), 0, 150)];
    }
    if (isset($result['status']) && in_array(strtolower((string)$result['status']), ['error', 'failed', 'fail'], true)) {
        return ['ok' => false, 'reason' => 'Gateway failure: ' . substr((string)($result['message'] ?? $result['status']), 0, 150)];
    }
    return ['ok' => true, 'reason' => 'sent'];
}
