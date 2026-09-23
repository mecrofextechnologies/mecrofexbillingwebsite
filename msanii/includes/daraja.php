<?php
/* Shared Safaricom Daraja client — the one place the Paybill credentials
   are read and the one place requests to Safaricom are built.

   Credential sources, lowest to highest precedence:
     1. Built-in defaults (Paybill 4032885, production).
     2. <kc_secrets_dir()>/daraja.ini — the same out-of-webroot directory
        that already holds the PII encryption keys (see crypto.php). This is
        where the installation's credentials are provisioned.
     3. Environment variables KC_DARAJA_* (e.g. set via SetEnv / php-fpm).
     4. Values saved by a System Administrator in
        Admin01 → Settings → Daraja API Credentials, stored in
        payment_gateway_settings with every secret encrypted by kc_encrypt().

   Secrets never leave this file except as outbound request fields to
   Safaricom: no secret is logged, echoed, or returned to a browser.

   Requires msanii/includes/connect.php (for $pdo, kc_encrypt/kc_decrypt,
   kc_webhook_token, kc_secrets_dir) to be loaded first. */

const KC_DARAJA_DEFAULT_SHORTCODE = '4032885';

/* Every setting the admin page manages. 'secret' ones are encrypted at
   rest and never rendered back into HTML. */
const KC_DARAJA_FIELDS = [
    'consumer_key'        => ['label' => 'Consumer Key',          'secret' => true,  'env' => 'KC_DARAJA_CONSUMER_KEY'],
    'consumer_secret'     => ['label' => 'Consumer Secret',       'secret' => true,  'env' => 'KC_DARAJA_CONSUMER_SECRET'],
    'passkey'             => ['label' => 'Passkey',               'secret' => true,  'env' => 'KC_DARAJA_PASSKEY'],
    'shortcode'           => ['label' => 'Shortcode / Paybill',   'secret' => false, 'env' => 'KC_DARAJA_SHORTCODE'],
    'b2c_shortcode'       => ['label' => 'B2C Shortcode',         'secret' => false, 'env' => 'KC_DARAJA_B2C_SHORTCODE'],
    'initiator_name'      => ['label' => 'Initiator Name',        'secret' => false, 'env' => 'KC_DARAJA_INITIATOR_NAME'],
    'security_credential' => ['label' => 'Security Credential',   'secret' => true,  'env' => 'KC_DARAJA_SECURITY_CREDENTIAL'],
    'environment'         => ['label' => 'Environment',           'secret' => false, 'env' => 'KC_DARAJA_ENVIRONMENT'],
    'callback_base_url'   => ['label' => 'Callback / Result Base URL', 'secret' => false, 'env' => 'KC_DARAJA_CALLBACK_BASE_URL'],
];

function kc_payment_settings_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_gateway_settings (
            setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
            setting_value TEXT         NULL,
            is_secret     TINYINT(1)   NOT NULL DEFAULT 0,
            updated_by    INT          NULL,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/* Append-only log in the out-of-webroot secrets directory (the app's
   existing *.log files sit in the docroot and are downloadable). Callers
   must never pass secrets; phone numbers should go through kc_mask_phone(). */
function kc_payment_log(string $channel, string $message): void {
    $dir = kc_secrets_dir() . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $channel = preg_replace('/[^a-z0-9_]/i', '', $channel) ?: 'payments';
    @file_put_contents(
        $dir . DIRECTORY_SEPARATOR . $channel . '.log',
        date('Y-m-d H:i:s') . ' - ' . $message . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function kc_mask_phone(?string $phone): string {
    $p = preg_replace('/\D/', '', (string)$phone);
    return strlen($p) >= 7 ? substr($p, 0, 5) . str_repeat('*', strlen($p) - 7) . substr($p, -2) : '***';
}

function kc_daraja_config(PDO $pdo, bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;

    $cfg = [
        'consumer_key'        => '',
        'consumer_secret'     => '',
        'passkey'             => '',
        'shortcode'           => KC_DARAJA_DEFAULT_SHORTCODE,
        'b2c_shortcode'       => '',
        'initiator_name'      => '',
        'security_credential' => '',
        'environment'         => 'production',
        'callback_base_url'   => 'https://keychele.co.ke',
    ];

    $ini = kc_secrets_dir() . DIRECTORY_SEPARATOR . 'daraja.ini';
    if (is_readable($ini)) {
        $fromFile = @parse_ini_file($ini, false, INI_SCANNER_RAW) ?: [];
        foreach ($cfg as $k => $_) {
            if (isset($fromFile[$k]) && trim((string)$fromFile[$k]) !== '') $cfg[$k] = trim((string)$fromFile[$k]);
        }
    }

    foreach (KC_DARAJA_FIELDS as $k => $meta) {
        $env = getenv($meta['env']);
        if ($env !== false && trim($env) !== '') $cfg[$k] = trim($env);
    }

    try {
        kc_payment_settings_ensure_table($pdo);
        $rows = $pdo->query("SELECT setting_key, setting_value, is_secret FROM payment_gateway_settings WHERE setting_key LIKE 'daraja.%'")
                    ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $k = substr($r['setting_key'], strlen('daraja.'));
            if (!array_key_exists($k, $cfg) || $r['setting_value'] === null || $r['setting_value'] === '') continue;
            $v = $r['is_secret'] ? kc_decrypt($r['setting_value']) : $r['setting_value'];
            if ($v !== null && $v !== '') $cfg[$k] = $v;
        }
    } catch (Throwable $e) {
        kc_payment_log('daraja', 'Could not read payment_gateway_settings: ' . get_class($e));
    }

    $cfg['environment']       = $cfg['environment'] === 'sandbox' ? 'sandbox' : 'production';
    $cfg['callback_base_url'] = rtrim($cfg['callback_base_url'], '/');
    if ($cfg['b2c_shortcode'] === '') $cfg['b2c_shortcode'] = $cfg['shortcode'];

    return $cache = $cfg;
}

/* Where each value came from, for the admin page — never the value itself. */
function kc_daraja_config_sources(PDO $pdo): array {
    $sources = [];
    $ini = kc_secrets_dir() . DIRECTORY_SEPARATOR . 'daraja.ini';
    $fromFile = is_readable($ini) ? (@parse_ini_file($ini, false, INI_SCANNER_RAW) ?: []) : [];
    kc_payment_settings_ensure_table($pdo);
    $db = $pdo->query("SELECT setting_key, updated_at FROM payment_gateway_settings WHERE setting_key LIKE 'daraja.%' AND setting_value IS NOT NULL AND setting_value <> ''")
              ->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (KC_DARAJA_FIELDS as $k => $meta) {
        if (isset($db['daraja.' . $k]))                                   $sources[$k] = 'Admin settings (updated ' . $db['daraja.' . $k] . ')';
        elseif (($e = getenv($meta['env'])) !== false && trim($e) !== '') $sources[$k] = 'Environment variable';
        elseif (isset($fromFile[$k]) && trim((string)$fromFile[$k]) !== '') $sources[$k] = 'Secure config file';
        else                                                              $sources[$k] = 'Not set';
    }
    return $sources;
}

function kc_daraja_save_settings(PDO $pdo, array $values, int $adminId): void {
    kc_payment_settings_ensure_table($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO payment_gateway_settings (setting_key, setting_value, is_secret, updated_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret),
                                updated_by = VALUES(updated_by), updated_at = NOW()
    ");
    foreach ($values as $k => $v) {
        if (!isset(KC_DARAJA_FIELDS[$k])) continue;
        $secret = KC_DARAJA_FIELDS[$k]['secret'];
        $stmt->execute(['daraja.' . $k, $secret ? kc_encrypt($v) : $v, $secret ? 1 : 0, $adminId]);
    }
}

function kc_daraja_missing(array $cfg, string $purpose): array {
    $need = $purpose === 'b2c'
        ? ['consumer_key', 'consumer_secret', 'b2c_shortcode', 'initiator_name', 'security_credential', 'callback_base_url']
        : ['consumer_key', 'consumer_secret', 'passkey', 'shortcode', 'callback_base_url'];
    return array_values(array_filter($need, fn($k) => trim((string)($cfg[$k] ?? '')) === ''));
}

function kc_daraja_api_base(array $cfg): string {
    return $cfg['environment'] === 'sandbox' ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';
}

/* Public URL Safaricom posts back to, carrying the shared webhook secret
   (see kc_verify_webhook_token) so forged callbacks are rejected. */
function kc_daraja_callback_url(array $cfg, string $path): string {
    return $cfg['callback_base_url'] . '/' . ltrim($path, '/') . '?key=' . kc_webhook_token();
}

/* STK AccountReference = the creator's username, reduced to the
   alphanumerics Daraja accepts (usernames here may contain . _ -).
   Falls back to the caller's transaction reference only when no usable
   username exists. */
function kc_daraja_account_reference(?string $username, string $fallback): string {
    $clean = preg_replace('/[^A-Za-z0-9]/', '', (string)$username);
    if ($clean === '') $clean = preg_replace('/[^A-Za-z0-9]/', '', $fallback);
    return substr($clean !== '' ? $clean : 'Keychele', 0, 20);
}

/* Low-level JSON call. Returns [httpCode, decodedBody|null, curlErrno, curlError]. */
function kc_daraja_http(string $method, string $url, array $headers, ?array $body = null, int $timeout = 30): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $res   = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($res) ? json_decode($res, true) : null, $errno, $err];
}

function kc_daraja_access_token(array $cfg, ?string &$error = null): ?string {
    static $tokens = [];
    $cacheKey = hash('sha256', $cfg['environment'] . '|' . $cfg['consumer_key'] . '|' . $cfg['consumer_secret']);
    if (isset($tokens[$cacheKey]) && $tokens[$cacheKey]['exp'] > time()) return $tokens[$cacheKey]['token'];

    if ($cfg['consumer_key'] === '' || $cfg['consumer_secret'] === '') {
        $error = 'Daraja consumer key/secret not configured';
        return null;
    }
    [$code, $data, $errno, $err] = kc_daraja_http(
        'GET',
        kc_daraja_api_base($cfg) . '/oauth/v1/generate?grant_type=client_credentials',
        ['Authorization: Basic ' . base64_encode($cfg['consumer_key'] . ':' . $cfg['consumer_secret'])]
    );
    if ($errno) { $error = "OAuth network error ($errno)"; return null; }
    if (empty($data['access_token'])) {
        $error = "OAuth rejected (HTTP $code) — check consumer key/secret and environment";
        return null;
    }
    $ttl = max(60, (int)($data['expires_in'] ?? 3599) - 60);
    $tokens[$cacheKey] = ['token' => $data['access_token'], 'exp' => time() + $ttl];
    return $data['access_token'];
}

/* Paybill STK push (Lipa na M-Pesa Online, CustomerPayBillOnline — the
   Daraja flow for collecting from a customer into a Paybill).
   Returns ['ok'=>true,'checkout_request_id'=>…] or ['ok'=>false,'error'=>…]. */
function kc_daraja_stk_push(PDO $pdo, string $phone, int $amount, string $accountRef, string $desc, string $callbackPath): array {
    $cfg = kc_daraja_config($pdo);
    if ($missing = kc_daraja_missing($cfg, 'stk')) {
        kc_payment_log('daraja', 'STK push blocked — missing config: ' . implode(',', $missing));
        return ['ok' => false, 'error' => 'Payment gateway is not configured'];
    }
    $tokenErr = null;
    $token = kc_daraja_access_token($cfg, $tokenErr);
    if (!$token) {
        kc_payment_log('daraja', 'STK push blocked — ' . $tokenErr);
        return ['ok' => false, 'error' => 'Payment gateway authentication failed'];
    }

    $send = function (string $ref) use ($cfg, $token, $phone, $amount, $desc, $callbackPath) {
        $timestamp = date('YmdHis');
        return kc_daraja_http('POST', kc_daraja_api_base($cfg) . '/mpesa/stkpush/v1/processrequest', [
            'Authorization: Bearer ' . $token, 'Content-Type: application/json',
        ], [
            'BusinessShortCode' => $cfg['shortcode'],
            'Password'          => base64_encode($cfg['shortcode'] . $cfg['passkey'] . $timestamp),
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $cfg['shortcode'],
            'PhoneNumber'       => $phone,
            'CallBackURL'       => kc_daraja_callback_url($cfg, $callbackPath),
            'AccountReference'  => $ref,
            'TransactionDesc'   => $desc,
        ], 60);
    };

    [$code, $data, $errno] = $send($accountRef);

    // Daraja documents AccountReference as max 12 chars but in practice
    // accepts longer ones. If this account enforces the limit, retry once
    // with the truncated username rather than failing the sale.
    if (!$errno && ($data['ResponseCode'] ?? null) !== '0' && strlen($accountRef) > 12
        && stripos((string)($data['errorMessage'] ?? ''), 'AccountReference') !== false) {
        kc_payment_log('daraja', "AccountReference rejected at length " . strlen($accountRef) . ", retrying truncated");
        [$code, $data, $errno] = $send(substr($accountRef, 0, 12));
    }

    if (!$errno && ($data['ResponseCode'] ?? null) === '0' && !empty($data['CheckoutRequestID'])) {
        return ['ok' => true, 'checkout_request_id' => $data['CheckoutRequestID']];
    }
    kc_payment_log('daraja', "STK push failed: HTTP $code errno $errno "
        . substr(json_encode(['code' => $data['errorCode'] ?? $data['ResponseCode'] ?? null, 'msg' => $data['errorMessage'] ?? $data['ResponseDescription'] ?? null]), 0, 300)
        . ' phone ' . kc_mask_phone($phone));
    return ['ok' => false, 'error' => 'M-Pesa request was not accepted'];
}

/* B2C payout (BusinessPayment) — the Daraja API for sending money from a
   business shortcode to a customer's M-Pesa. STK push can only collect.

   Result is asynchronous: an 'accepted' response only means Safaricom
   queued it; the outcome arrives at b2c_result.php. Returns:
     state 'accepted' — queued; wait for the result callback
     state 'rejected' — definitively not sent (safe to release funds)
     state 'unknown'  — may or may not have reached Safaricom (timeout etc.);
                        funds must stay reserved until reconciled. */
function kc_daraja_b2c_pay(PDO $pdo, string $phone, float $amount, string $originatorId, string $remarks, string $occasion): array {
    $cfg = kc_daraja_config($pdo);
    if ($missing = kc_daraja_missing($cfg, 'b2c')) {
        kc_payment_log('b2c', 'B2C blocked — missing config: ' . implode(',', $missing));
        return ['state' => 'rejected', 'error' => 'Payout gateway is not configured'];
    }
    $tokenErr = null;
    $token = kc_daraja_access_token($cfg, $tokenErr);
    if (!$token) {
        kc_payment_log('b2c', "B2C blocked [$originatorId] — " . $tokenErr);
        return ['state' => 'rejected', 'error' => 'Payout gateway authentication failed'];
    }

    [$code, $data, $errno, $err] = kc_daraja_http('POST', kc_daraja_api_base($cfg) . '/mpesa/b2c/v3/paymentrequest', [
        'Authorization: Bearer ' . $token, 'Content-Type: application/json',
    ], [
        'OriginatorConversationID' => $originatorId,
        'InitiatorName'            => $cfg['initiator_name'],
        'SecurityCredential'       => $cfg['security_credential'],
        'CommandID'                => 'BusinessPayment',
        'Amount'                   => (int)round($amount),
        'PartyA'                   => $cfg['b2c_shortcode'],
        'PartyB'                   => $phone,
        'Remarks'                  => substr($remarks, 0, 100),
        'QueueTimeOutURL'          => kc_daraja_callback_url($cfg, 'b2c_timeout.php'),
        'ResultURL'                => kc_daraja_callback_url($cfg, 'b2c_result.php'),
        'Occasion'                 => substr($occasion, 0, 100),
    ], 45);

    $summary = substr(json_encode([
        'http' => $code,
        'code' => $data['ResponseCode'] ?? $data['errorCode'] ?? null,
        'desc' => $data['ResponseDescription'] ?? $data['errorMessage'] ?? null,
        'conv' => $data['ConversationID'] ?? null,
    ]), 0, 500);

    if ($errno) {
        // 6 = DNS failure, 7 = could not connect: the request never left.
        // Anything else (notably 28, timeout) may have been received.
        $state = in_array($errno, [6, 7], true) ? 'rejected' : 'unknown';
        kc_payment_log('b2c', "B2C [$originatorId] network errno $errno ($state)");
        return ['state' => $state, 'error' => 'Could not reach M-Pesa', 'raw' => $summary];
    }
    if (($data['ResponseCode'] ?? null) === '0') {
        kc_payment_log('b2c', "B2C [$originatorId] accepted " . $summary);
        return [
            'state'                      => 'accepted',
            'conversation_id'            => $data['ConversationID'] ?? null,
            'originator_conversation_id' => $data['OriginatorConversationID'] ?? $originatorId,
            'raw'                        => $summary,
        ];
    }
    // A 4xx with a body, or an explicit non-zero ResponseCode, is Safaricom
    // saying no. 5xx / unparseable bodies are ambiguous.
    $state = ($code >= 400 && $code < 500 && is_array($data)) || isset($data['ResponseCode']) ? 'rejected' : 'unknown';
    kc_payment_log('b2c', "B2C [$originatorId] $state " . $summary);
    return [
        'state' => $state,
        'error' => $state === 'rejected'
            ? (string)($data['errorMessage'] ?? $data['ResponseDescription'] ?? 'Payout request rejected by M-Pesa')
            : 'No definitive response from M-Pesa',
        'raw'   => $summary,
    ];
}
