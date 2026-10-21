<?php
// Public endpoint -- a fire-and-forget beacon fired once from index.php
// via fetch() after the page has already rendered, so a 1-2s geo-IP
// lookup (lookup_ip_geolocation() below) is never visible to the
// visitor. Low-stakes traffic data, not an audit trail -- no session,
// no CSRF token, same reasoning the main billing app's own equivalent
// (api/register_visit.php) uses for why this is acceptable here.
//
// Reuses the main app's bot-detection/geo-IP helpers via an absolute
// require rather than duplicating ~150 lines of signature lists here --
// both codebases live on the same server by design, and these are pure
// functions with no database coupling, so this creates no privilege
// leakage: this file still only ever connects to the database through
// its own narrowly-scoped credentials (includes/db_secrets.php, INSERT
// on website_visits only), never the main app's.
require_once '/var/www/mecrofexbilling/includes/visit_tracking.php';

header('Content-Type: application/json');

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false], 405);
}

$secretsFile = __DIR__ . '/../includes/db_secrets.php';
if (!is_file($secretsFile)) {
    respond(['ok' => false], 503);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$path = isset($input['path']) ? substr((string) $input['path'], 0, 255) : '/';

$userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

// Silent no-op for a detected bot -- never counted at all, not logged
// then filtered. Reported back as ok:true so a bot gains no signal
// about being detected either way.
if (is_bot_user_agent($userAgent)) {
    respond(['ok' => true]);
}

// Cloudflare terminates TLS and proxies every request here, so
// REMOTE_ADDR is Cloudflare's own edge IP, not the visitor's --
// CF-Connecting-IP is the real one. Falls back to REMOTE_ADDR for local
// dev / anything not actually behind Cloudflare.
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$referrer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

if (!isset($_COOKIE['mb_visitor_id']) || !preg_match('/^[0-9a-f-]{36}$/i', $_COOKIE['mb_visitor_id'])) {
    $visitorId = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    setcookie('mb_visitor_id', $visitorId, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} else {
    $visitorId = $_COOKIE['mb_visitor_id'];
}

$geo = lookup_ip_geolocation($ip);

try {
    require $secretsFile; // defines WEBSITE_DB_HOST/NAME/USER/PASS
    $pdo = new PDO(
        'mysql:host=' . WEBSITE_DB_HOST . ';dbname=' . WEBSITE_DB_NAME . ';charset=utf8mb4',
        WEBSITE_DB_USER,
        WEBSITE_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
    );

    $pdo->prepare("
        INSERT INTO website_visits (visitor_id, ip_address, country, region, city, path, referrer, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $visitorId,
        $ip !== 'unknown' ? substr($ip, 0, 45) : '0.0.0.0',
        $geo['country'],
        $geo['region'],
        $geo['city'],
        $path !== '' ? $path : '/',
        $referrer !== '' ? substr($referrer, 0, 255) : null,
        $userAgent !== '' ? substr($userAgent, 0, 255) : null,
    ]);

    respond(['ok' => true]);
} catch (Throwable $e) {
    error_log('track_visit: ' . $e->getMessage());
    respond(['ok' => false], 500);
}
