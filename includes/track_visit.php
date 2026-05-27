<?php
/**
 * ================================================================
 * KEYCHELE — Web Visit Tracker
 * Include at the TOP of index.php (after your DB connection):
 *   include_once 'includes/track_visit.php';
 * ================================================================
 * Features:
 *  - Deduplicates by session hash (IP + UA + today's date)
 *  - Increments visit_count for repeat same-day visits
 *  - Parses device type, OS, browser from User-Agent
 *  - Geo-locates via ip-api.com (free, no key needed, rate-limited)
 *  - Skips obvious bots / crawlers
 *  - Non-blocking: errors are silently logged, never shown to user
 * ================================================================
 */

if (!isset($pdo)) {
    // If called standalone, load connection
    @include_once __DIR__ . '/../msanii/includes/connect.php';
}

if (!isset($pdo)) return; // no DB — bail silently

/**
 * Get the real client IP (handles proxies & Cloudflare).
 */
function _kc_get_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Very fast UA parser — covers 95 %+ of real traffic.
 * Returns ['device'=>..., 'os_name'=>..., 'os_version'=>...,
 *          'browser_name'=>..., 'browser_version'=>..., 'is_bot'=>0|1]
 */
function _kc_parse_ua(string $ua): array {
    $ua_lower = strtolower($ua);

    // ── Bot detection ─────────────────────────────────────────
    $bot_patterns = ['bot','crawl','spider','slurp','facebookexternalhit','whatsapp',
                     'googlebot','bingbot','yandex','baidu','semrush','ahrefs','mj12bot',
                     'petalbot','bytespider','gptbot','claudebot','ccbot','curl','wget',
                     'python-requests','go-http-client','java/','apache-httpclient',
                     'libwww','httpclient','scrapy'];
    foreach ($bot_patterns as $b) {
        if (str_contains($ua_lower, $b)) {
            return ['device'=>'Bot','os_name'=>null,'os_version'=>null,
                    'browser_name'=>'Bot','browser_version'=>null,'is_bot'=>1];
        }
    }

    // ── OS detection ──────────────────────────────────────────
    $os_name = 'Unknown'; $os_version = null;
    $os_map = [
        'windows nt 10' => ['Windows','10'],
        'windows nt 11' => ['Windows','11'],
        'windows nt 6.3'=> ['Windows','8.1'],
        'windows nt 6.2'=> ['Windows','8'],
        'windows nt 6.1'=> ['Windows','7'],
        'windows'       => ['Windows',null],
        'android'       => ['Android',null],
        'iphone os'     => ['iOS',null],
        'ipad'          => ['iPadOS',null],
        'mac os x'      => ['macOS',null],
        'linux'         => ['Linux',null],
        'ubuntu'        => ['Ubuntu',null],
        'chrome os'     => ['ChromeOS',null],
    ];
    foreach ($os_map as $pattern => [$name, $ver]) {
        if (str_contains($ua_lower, $pattern)) {
            $os_name = $name; $os_version = $ver;
            // Extract Android version
            if ($name === 'Android' && preg_match('/android ([\d.]+)/i', $ua, $m)) $os_version = $m[1];
            // Extract iOS version
            if ($name === 'iOS' && preg_match('/os ([\d_]+) like/i', $ua, $m)) $os_version = str_replace('_','.',$m[1]);
            // Extract macOS version
            if ($name === 'macOS' && preg_match('/mac os x ([\d_.]+)/i', $ua, $m)) $os_version = str_replace('_','.',$m[1]);
            // Extract Windows version from NT
            if ($name === 'Windows' && !$ver && preg_match('/windows nt ([\d.]+)/i', $ua, $m)) $os_version = $m[1];
            break;
        }
    }

    // ── Device type ───────────────────────────────────────────
    $device = 'Desktop';
    if (str_contains($ua_lower, 'tablet') || str_contains($ua_lower, 'ipad'))        $device = 'Tablet';
    elseif (preg_match('/mobile|android|iphone|ipod|blackberry|windows phone/i', $ua)) $device = 'Mobile';

    // ── Browser detection ─────────────────────────────────────
    $browser = 'Unknown'; $b_ver = null;
    $browser_map = [
        'edg/'        => 'Microsoft Edge',
        'edge/'       => 'Microsoft Edge',
        'opr/'        => 'Opera',
        'opera/'      => 'Opera',
        'samsungbrowser' => 'Samsung Browser',
        'miui'        => 'MIUI Browser',
        'ucbrowser'   => 'UC Browser',
        'yabrowser'   => 'Yandex Browser',
        'brave'       => 'Brave',
        'firefox/'    => 'Firefox',
        'fxios/'      => 'Firefox',
        'chrome/'     => 'Chrome',
        'crios/'      => 'Chrome',
        'safari/'     => 'Safari',
    ];
    foreach ($browser_map as $pattern => $name) {
        if (str_contains($ua_lower, $pattern)) {
            $browser = $name;
            if (preg_match('/' . preg_quote(rtrim($pattern,'/'), '/') . '\/([\d.]+)/i', $ua, $m)) $b_ver = $m[1];
            break;
        }
    }

    return ['device'=>$device,'os_name'=>$os_name,'os_version'=>$os_version,
            'browser_name'=>$browser,'browser_version'=>$b_ver,'is_bot'=>0];
}

/**
 * Fetch geo info from ip-api.com (free, ~150 req/min without key).
 * Returns array or null on failure. Cached in session to avoid hammering.
 */
function _kc_get_geo(string $ip): ?array {
    if ($ip === '127.0.0.1' || str_starts_with($ip,'192.168.') || str_starts_with($ip,'10.')) {
        return ['country'=>'Local','country_code'=>'LO','region'=>'Local',
                'city'=>'Localhost','lat'=>null,'lon'=>null,'timezone'=>null,'isp'=>'Local'];
    }
    $sess_key = 'kc_geo_' . md5($ip);
    if (isset($_SESSION[$sess_key])) return $_SESSION[$sess_key];

    $url = "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,regionName,city,lat,lon,timezone,isp";
    $ctx = stream_context_create(['http'=>['timeout'=>2,'ignore_errors'=>true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    if (!$data || ($data['status'] ?? '') !== 'success') return null;
    $result = [
        'country'      => $data['country']     ?? null,
        'country_code' => $data['countryCode'] ?? null,
        'region'       => $data['regionName']  ?? null,
        'city'         => $data['city']        ?? null,
        'lat'          => $data['lat']         ?? null,
        'lon'          => $data['lon']         ?? null,
        'timezone'     => $data['timezone']    ?? null,
        'isp'          => $data['isp']         ?? null,
    ];
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION[$sess_key] = $result;
    return $result;
}

// ═══════════════════════════════════════════════════════════════
// MAIN TRACKING LOGIC
// ═══════════════════════════════════════════════════════════════
try {
    $ip = _kc_get_ip();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $today = date('Y-m-d');

    // Dedup key: SHA-256(ip + ua + date)
    $session_hash = hash('sha256', $ip . $ua . $today);

    // Parse UA first (fast, no network)
    $ua_data = _kc_parse_ua($ua);

    // Check if this session already exists today
    $check = $pdo->prepare("SELECT id, visit_count FROM web_visits WHERE session_hash = ? LIMIT 1");
    $check->execute([$session_hash]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // ── Repeat visit today: just increment the counter ───
        $pdo->prepare("UPDATE web_visits SET visit_count = visit_count + 1, last_seen = NOW() WHERE id = ?")
            ->execute([$existing['id']]);
    } else {
        // ── New session: full insert ─────────────────────────
        // Get geo (2s timeout, non-fatal)
        $geo = _kc_get_geo($ip);

        $page_url = substr(
            (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' .
            ($_SERVER['HTTP_HOST'] ?? '') .
            ($_SERVER['REQUEST_URI'] ?? ''), 0, 500
        );
        $referrer = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);

        $stmt = $pdo->prepare("
            INSERT INTO web_visits
                (session_hash, ip_address,
                 country, country_code, region, city, latitude, longitude, timezone, isp,
                 device_type, os_name, os_version, browser_name, browser_version, is_bot,
                 page_url, referrer, first_seen, last_seen, visit_count)
            VALUES
                (?, ?,
                 ?, ?, ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?,
                 ?, ?, NOW(), NOW(), 1)
        ");
        $stmt->execute([
            $session_hash, $ip,
            $geo['country']      ?? null,
            $geo['country_code'] ?? null,
            $geo['region']       ?? null,
            $geo['city']         ?? null,
            $geo['lat']          ?? null,
            $geo['lon']          ?? null,
            $geo['timezone']     ?? null,
            $geo['isp']          ?? null,
            $ua_data['device'],
            $ua_data['os_name'],
            $ua_data['os_version'],
            $ua_data['browser_name'],
            $ua_data['browser_version'],
            $ua_data['is_bot'],
            $page_url ?: null,
            $referrer ?: null,
        ]);
    }
} catch (Throwable $e) {
    // Never let tracking break the main page
    error_log('[KC visit tracker] ' . $e->getMessage());
}
