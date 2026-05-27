<?php
/**
 * MecroGuard — Human Verification + Visit Tracking
 * ──────────────────────────────────────────────────
 * Requires the existing $pdo from your db connection file.
 *
 * Order in index.php:
 *   require 'db_connection.php';   ← sets $pdo
 *   require 'mecroguard.php';      ← this file
 */

if (session_status() === PHP_SESSION_NONE) session_start();

define('MG_VISIT_EXPIRY_DAYS', 90);

/* ════════════════════════════════════════
   HELPERS
   ════════════════════════════════════════ */

function mg_real_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function mg_get_geo(string $ip): array {
    $blank = ['location'=>'Unknown','city'=>'Unknown','region'=>'Unknown','country'=>'Unknown','isp'=>'Unknown'];

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $blank;
    }

    $url = "http://ip-api.com/json/{$ip}?fields=status,country,city,regionName,isp,org";
    $raw = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'MecroGuard/2.0',
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }

    if (!$raw && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 4]]);
        $raw = @file_get_contents($url, false, $ctx);
    }

    if (!$raw) return $blank;

    $d = json_decode($raw, true);
    if (($d['status'] ?? '') !== 'success') return $blank;

    $city    = $d['city']       ?? '';
    $region  = $d['regionName'] ?? '';
    $country = $d['country']    ?? '';
    $parts   = array_filter(array_unique([$city, $region, $country]));

    return [
        'location' => implode(', ', $parts) ?: 'Unknown',
        'city'     => $city    ?: 'Unknown',
        'region'   => $region  ?: 'Unknown',
        'country'  => $country ?: 'Unknown',
        'isp'      => $d['isp'] ?? $d['org'] ?? 'Unknown',
    ];
}

function mg_parse_ua(string $ua): array {
    $browser = 'Unknown';
    if      (preg_match('/Edg\/(\d+)/i',            $ua, $m)) $browser = 'Edge '    . $m[1];
    elseif  (preg_match('/OPR\/(\d+)/i',            $ua, $m)) $browser = 'Opera '   . $m[1];
    elseif  (preg_match('/SamsungBrowser\/(\d+)/i', $ua, $m)) $browser = 'Samsung ' . $m[1];
    elseif  (preg_match('/Chrome\/(\d+)/i',         $ua, $m)) $browser = 'Chrome '  . $m[1];
    elseif  (preg_match('/Firefox\/(\d+)/i',        $ua, $m)) $browser = 'Firefox ' . $m[1];
    elseif  (preg_match('/Version\/[\d.]+.*Safari/i',$ua))    $browser = 'Safari';
    elseif  (preg_match('/MSIE (\d+)|Trident.*rv:(\d+)/i', $ua, $m)) $browser = 'IE ' . ($m[1] ?: $m[2]);

    $os = 'Unknown';
    if      (preg_match('/Windows NT 10\.0/i',  $ua))       $os = 'Windows 10/11';
    elseif  (preg_match('/Windows NT 6\.1/i',   $ua))       $os = 'Windows 7';
    elseif  (preg_match('/Mac OS X ([\d_]+)/i', $ua, $m))   $os = 'macOS '   . str_replace('_','.',$m[1]);
    elseif  (preg_match('/Android ([\d.]+)/i',  $ua, $m))   $os = 'Android ' . $m[1];
    elseif  (preg_match('/iPhone OS ([\d_]+)/i',$ua, $m))   $os = 'iOS '     . str_replace('_','.',$m[1]);
    elseif  (preg_match('/iPad.*OS ([\d_]+)/i', $ua, $m))   $os = 'iPadOS '  . str_replace('_','.',$m[1]);
    elseif  (preg_match('/Linux/i',             $ua))        $os = 'Linux';

    $device = 'Desktop';
    if      (preg_match('/iPad/i',            $ua)) $device = 'iPad';
    elseif  (preg_match('/iPhone/i',          $ua)) $device = 'iPhone';
    elseif  (preg_match('/Android.*Mobile/i', $ua)) $device = 'Android Phone';
    elseif  (preg_match('/Android/i',         $ua)) $device = 'Android Tablet';
    elseif  (preg_match('/Mobile/i',          $ua)) $device = 'Mobile';

    return ['browser' => $browser, 'os' => $os, 'device' => $device];
}

/**
 * Insert or update a visit row, bump global total, prune old rows.
 *
 * FIX: Every named parameter (:xxx) appears EXACTLY ONCE in the SQL.
 *      PDO silently drops duplicated named params, which was causing
 *      the INSERT to fail without any error.
 *
 *      Solution: use a NOW() call directly in SQL for last_visit inside
 *      ON DUPLICATE KEY UPDATE, and pass two differently-named params
 *      (:first_visit, :last_visit) for the INSERT VALUES clause.
 */
function mg_record_visit(PDO $pdo, string $ip, string $page, int $elapsed_ms = 0): void {
    try {
        $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $geo     = mg_get_geo($ip);
        $ua_info = mg_parse_ua($ua);
        $secs    = max(0, (int) round($elapsed_ms / 1000));
        $now     = date('Y-m-d H:i:s');   // used as BOTH first_visit AND last_visit on insert
        $today   = date('Y-m-d');

        // ── Each named param appears EXACTLY once ──
        $stmt = $pdo->prepare("
            INSERT INTO web_visits
                (ip_address, device, os, browser, isp,
                 location, city, region, country,
                 page_visited, time_spent, visit_count,
                 first_visit, last_visit, date_recorded)
            VALUES
                (:ip, :device, :os, :browser, :isp,
                 :location, :city, :region, :country,
                 :page, :secs, 1,
                 :first_visit, :last_visit, :today)
            ON DUPLICATE KEY UPDATE
                visit_count = visit_count + 1,
                last_visit  = NOW(),
                time_spent  = time_spent + :secs_update
        ");

        $stmt->execute([
            ':ip'          => $ip,
            ':device'      => $ua_info['device'],
            ':os'          => $ua_info['os'],
            ':browser'     => $ua_info['browser'],
            ':isp'         => $geo['isp'],
            ':location'    => $geo['location'],
            ':city'        => $geo['city'],
            ':region'      => $geo['region'],
            ':country'     => $geo['country'],
            ':page'        => $page,
            ':secs'        => $secs,           // time_spent in VALUES
            ':first_visit' => $now,
            ':last_visit'  => $now,
            ':today'       => $today,
            ':secs_update' => $secs,           // time_spent in UPDATE (different param name)
        ]);

        // ── Bump singleton total counter ──
        $pdo->exec("
            INSERT INTO web_visit_total (id, total_views)
            VALUES (1, 1)
            ON DUPLICATE KEY UPDATE total_views = total_views + 1
        ");

        // ── Prune old rows ──
        $days = (int) MG_VISIT_EXPIRY_DAYS;
        $pdo->exec("DELETE FROM web_visits WHERE last_visit < DATE_SUB(NOW(), INTERVAL {$days} DAY)");

    } catch (Throwable $e) {
        error_log('[MecroGuard] DB error: ' . $e->getMessage());
    }
}

/* ════════════════════════════════════════
   RATE LIMITING
   ════════════════════════════════════════ */
$_mg_ip    = mg_real_ip();
$_mg_key   = 'mg_fails_' . md5($_mg_ip);
$_mg_now   = time();
$_mg_fails = $_SESSION[$_mg_key]['count'] ?? 0;
$_mg_since = $_SESSION[$_mg_key]['since'] ?? $_mg_now;

if ($_mg_now - $_mg_since > 600) {
    $_SESSION[$_mg_key] = ['count' => 0, 'since' => $_mg_now];
    $_mg_fails = 0;
}
$_mg_locked = $_mg_fails >= 5;

/* ════════════════════════════════════════
   AJAX VERIFY HANDLER
   ════════════════════════════════════════ */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_SERVER['HTTP_X_MECROGUARD'] ?? '') === 'verify'
) {
    header('Content-Type: application/json');

    if ($_mg_locked) {
        echo json_encode(['ok' => false, 'msg' => 'Too many attempts. Please wait and refresh.']);
        exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $token    = trim($body['token'] ?? '');
    $ms       = (int)($body['ms']   ?? 0);
    $hp       = $body['hp']         ?? null;
    $page     = substr(trim($body['page'] ?? $_SERVER['HTTP_REFERER'] ?? '/'), 0, 500);
    $expected = $_SESSION['mg_token'] ?? '';

    $pass = ($token === $expected && strlen($expected) === 64 && $hp === '' && $ms >= 1500);

    if ($pass) {
        $_SESSION['mecroguard_verified']    = true;
        $_SESSION['mecroguard_verified_at'] = $_mg_now;
        $_SESSION['mecroguard_ua_hash']     = md5($_SERVER['HTTP_USER_AGENT'] ?? '');
        unset($_SESSION['mg_token']);

        // Record the visit from the verification click
        mg_record_visit($pdo, $_mg_ip, $page, $ms);

        echo json_encode(['ok' => true]);
    } else {
        $_SESSION[$_mg_key]['count'] = ($_SESSION[$_mg_key]['count'] ?? 0) + 1;
        echo json_encode(['ok' => false, 'msg' => 'Verification failed. Try again.']);
    }
    exit;
}

/* ════════════════════════════════════════════════════════
   ALREADY VERIFIED?
   Record the visit silently on every page load, then skip
   the overlay — user doesn't see the checkbox again.
   ════════════════════════════════════════════════════════ */
if (
    !empty($_SESSION['mecroguard_verified']) &&
    md5($_SERVER['HTTP_USER_AGENT'] ?? '') === ($_SESSION['mecroguard_ua_hash'] ?? '') &&
    ($_mg_now - ($_SESSION['mecroguard_verified_at'] ?? 0)) < 86400
) {
    // Still track this page visit — elapsed ms is 0 since no overlay was shown
    $_mg_page = substr(
        (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' .
        ($_SERVER['HTTP_HOST'] ?? '') .
        ($_SERVER['REQUEST_URI'] ?? '/'),
        0, 500
    );
    mg_record_visit($pdo, $_mg_ip, $_mg_page, 0);

    return; // Skip overlay — user is already verified
}

/* ════════════════════════════════════════
   FRESH TOKEN  (new / unverified visitor)
   ════════════════════════════════════════ */
$_SESSION['mg_token'] = bin2hex(random_bytes(32));
$mg_token = $_SESSION['mg_token'];
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
#mg-overlay,#mg-overlay *,#mg-overlay *::before,#mg-overlay *::after{box-sizing:border-box;margin:0;padding:0;}
#mg-overlay{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;font-family:'Inter',system-ui,sans-serif;background:rgba(4,14,31,.75);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);}
#mg-overlay .mg-card{width:min(400px,92vw);background:#fff;border-radius:20px;padding:36px 32px 28px;box-shadow:0 24px 64px rgba(0,0,0,.22),0 2px 8px rgba(0,0,0,.08);animation:mgIn .5s cubic-bezier(.16,1,.3,1) both;position:relative;}
@keyframes mgIn{from{opacity:0;transform:translateY(24px) scale(.96);}to{opacity:1;transform:none;}}
#mg-overlay .mg-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:20px 20px 0 0;background:linear-gradient(90deg,#052e64,#bf1339,#fec411);}
#mg-overlay .mg-icon-wrap{width:64px;height:64px;background:linear-gradient(135deg,#eef3fb,#dce6f7);border-radius:18px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;border:1.5px solid rgba(5,46,100,.1);}
#mg-overlay .mg-icon-wrap svg{width:32px;height:32px;}
#mg-overlay .mg-title{font-size:1.25rem;font-weight:700;color:#0d1b2a;text-align:center;margin-bottom:6px;}
#mg-overlay .mg-sub{font-size:.84rem;color:#64748b;text-align:center;line-height:1.55;margin-bottom:28px;}
#mg-overlay .mg-loading{display:flex;align-items:center;justify-content:center;gap:10px;padding:18px;background:#f8fafc;border-radius:12px;border:1.5px solid #e2e8f0;margin-bottom:24px;font-size:.82rem;color:#64748b;font-weight:500;}
#mg-overlay .mg-spinner{width:18px;height:18px;border-radius:50%;flex-shrink:0;border:2px solid #e2e8f0;border-top-color:#052e64;animation:mgSpin .7s linear infinite;}
@keyframes mgSpin{to{transform:rotate(360deg);}}
#mg-overlay .mg-cta-wrap{position:relative;margin-bottom:24px;}
#mg-overlay .mg-arrow-hint{position:absolute;left:50%;bottom:calc(100% + 8px);transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;gap:2px;animation:mgBounce 1.1s ease-in-out infinite;pointer-events:none;opacity:0;transition:opacity .3s;}
#mg-overlay .mg-arrow-hint.show{opacity:1;}
#mg-overlay .mg-arrow-hint span{font-size:.7rem;font-weight:600;color:#052e64;background:#fffbea;border:1px solid #fec411;padding:3px 10px;border-radius:20px;white-space:nowrap;}
#mg-overlay .mg-arrow-hint svg{width:20px;height:20px;color:#052e64;}
@keyframes mgBounce{0%,100%{transform:translateX(-50%) translateY(0);}50%{transform:translateX(-50%) translateY(5px);}}
#mg-overlay .mg-check-row{display:flex;align-items:center;gap:16px;background:#f8fafc;border:2px solid #e2e8f0;border-radius:14px;padding:18px 20px;cursor:not-allowed;opacity:.5;transition:all .3s;user-select:none;}
#mg-overlay .mg-check-row.ready{cursor:pointer;opacity:1;border-color:#052e64;background:#fff;box-shadow:0 0 0 4px rgba(5,46,100,.06);}
#mg-overlay .mg-check-row.ready:hover{border-color:#bf1339;box-shadow:0 0 0 4px rgba(191,19,57,.08);transform:translateY(-1px);}
#mg-overlay .mg-check-row.verified{border-color:#16a34a;background:#f0fdf4;cursor:default;box-shadow:0 0 0 4px rgba(22,163,74,.08);}
#mg-overlay .mg-check-row.error{border-color:#bf1339;background:#fff5f5;animation:mgShake .35s ease;}
@keyframes mgShake{0%,100%{transform:translateX(0);}25%{transform:translateX(-6px);}75%{transform:translateX(6px);}}
#mg-overlay .mg-box{width:24px;height:24px;flex-shrink:0;border-radius:6px;border:2px solid #cbd5e1;background:#fff;display:flex;align-items:center;justify-content:center;transition:all .3s cubic-bezier(.34,1.56,.64,1);}
#mg-overlay .mg-check-row.ready .mg-box{border-color:#052e64;}
#mg-overlay .mg-box.spinning{border-color:#052e64;border-top-color:transparent;animation:mgSpin .6s linear infinite;}
#mg-overlay .mg-box.done{background:#16a34a;border-color:#16a34a;transform:scale(1.1);}
#mg-overlay .mg-box.done::after{content:'';width:5px;height:9px;border:2px solid #fff;border-top:0;border-left:0;transform:rotate(45deg) translate(-1px,-1px);display:block;}
#mg-overlay .mg-check-label strong{display:block;font-size:.95rem;font-weight:600;color:#0d1b2a;}
#mg-overlay .mg-check-label span{font-size:.75rem;color:#94a3b8;}
#mg-overlay .mg-lock-badge{margin-left:auto;flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:2px;}
#mg-overlay .mg-lock-badge svg{width:28px;height:28px;opacity:.35;}
#mg-overlay .mg-lock-badge em{font-size:.52rem;font-style:normal;color:#94a3b8;letter-spacing:.07em;font-weight:600;text-transform:uppercase;}
#mg-overlay .mg-err{font-size:.75rem;color:#bf1339;text-align:center;min-height:16px;margin-bottom:10px;}
#mg-overlay .mg-footer{display:flex;align-items:center;justify-content:space-between;border-top:1px solid #f1f5f9;padding-top:16px;}
#mg-overlay .mg-footer-left{display:flex;align-items:center;gap:6px;}
#mg-overlay .mg-kc-dot{width:18px;height:18px;border-radius:5px;flex-shrink:0;background:linear-gradient(135deg,#052e64,#bf1339);display:flex;align-items:center;justify-content:center;}
#mg-overlay .mg-kc-dot svg{width:11px;height:11px;}
#mg-overlay .mg-footer-left span{font-size:.7rem;font-weight:600;color:#94a3b8;letter-spacing:.04em;}
#mg-overlay .mg-footer-right{font-size:.68rem;color:#cbd5e1;}
#mg-overlay .mg-hp{position:absolute;left:-9999px;width:0;height:0;overflow:hidden;opacity:0;}
#mg-overlay.mg-out{animation:mgOut .6s ease forwards;}
@keyframes mgOut{to{opacity:0;backdrop-filter:blur(0px);}}
#mg-overlay.mg-out .mg-card{animation:mgCardOut .6s cubic-bezier(.4,0,1,1) forwards;}
@keyframes mgCardOut{to{opacity:0;transform:scale(.94) translateY(-10px);}}
</style>

<div id="mg-overlay">
<div class="mg-card">
    <div class="mg-icon-wrap">
        <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M16 3L4 8v8c0 7 5 13.4 12 15 7-1.6 12-8 12-15V8L16 3Z" fill="rgba(5,46,100,.1)" stroke="#052e64" stroke-width="1.8" stroke-linejoin="round"/>
            <path d="M11 16l3 3 7-6" stroke="#bf1339" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>
    <h1 class="mg-title">Quick Security Check</h1>
    <p class="mg-sub">Just a moment — we're making sure<br>you're a real person before you continue.</p>
    <div class="mg-loading" id="mgLoading">
        <div class="mg-spinner"></div>
        <span>Checking your browser…</span>
    </div>
    <div class="mg-err" id="mgErr"></div>
    <div class="mg-cta-wrap">
        <div class="mg-arrow-hint" id="mgArrow">
            <span>Click here to confirm</span>
            <svg viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v9.586l2.293-2.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 13.586V4a1 1 0 011-1z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="mg-check-row" id="mgCheckRow" onclick="mgVerify()">
            <div class="mg-box" id="mgBox"></div>
            <div class="mg-check-label">
                <strong>I am not a robot</strong>
                <span id="mgCheckSub">Ready in a moment…</span>
            </div>
            <div class="mg-lock-badge">
                <svg viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="11" width="18" height="11" rx="2" stroke="#94a3b8" stroke-width="1.8"/>
                    <path d="M7 11V7a5 5 0 0110 0v4" stroke="#94a3b8" stroke-width="1.8" fill="none"/>
                    <circle cx="12" cy="16" r="1.5" fill="#94a3b8"/>
                </svg>
                <em>Protected</em>
            </div>
        </div>
    </div>
    <div class="mg-hp" aria-hidden="true">
        <input type="text" id="mgHp" name="website" tabindex="-1" autocomplete="off" value="">
    </div>
    <div class="mg-footer">
        <div class="mg-footer-left">
            <div class="mg-kc-dot">
                <svg viewBox="0 0 12 12" fill="none">
                    <path d="M6 1l1.5 3h3l-2.5 2 1 3L6 7.5 3 9l1-3L1.5 4h3z" fill="#fec411"/>
                </svg>
            </div>
            <span>Keychele · MecroGuard</span>
        </div>
        <div class="mg-footer-right">Secured connection</div>
    </div>
</div>
</div>

<script>
(function () {
    const TOKEN    = <?= json_encode($mg_token) ?>;
    const MIN_MS   = 1500;
    const start    = Date.now();
    const PAGE_URL = window.location.href;

    let ready = false, busy = false;

    const overlay  = document.getElementById('mg-overlay');
    const loading  = document.getElementById('mgLoading');
    const checkRow = document.getElementById('mgCheckRow');
    const box      = document.getElementById('mgBox');
    const sub      = document.getElementById('mgCheckSub');
    const arrow    = document.getElementById('mgArrow');
    const errEl    = document.getElementById('mgErr');

    function enableCheck() {
        if (ready) return;
        ready = true;
        loading.style.display = 'none';
        checkRow.classList.add('ready');
        sub.textContent = 'Click to confirm';
        arrow.classList.add('show');
        checkRow.style.borderColor = '#fec411';
        setTimeout(() => { checkRow.style.borderColor = ''; }, 400);
    }

    setTimeout(enableCheck, MIN_MS);

    let moved = false;
    function onMove() {
        if (moved) return;
        moved = true;
        if (Date.now() - start >= MIN_MS) enableCheck();
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('touchstart', onMove);
    }
    document.addEventListener('mousemove', onMove, { passive: true });
    document.addEventListener('touchstart', onMove, { passive: true });

    window.mgVerify = function () {
        if (busy || !ready || checkRow.classList.contains('verified')) return;
        if ((document.getElementById('mgHp')?.value ?? '') !== '') return;

        busy = true;
        arrow.classList.remove('show');
        box.classList.add('spinning');
        sub.textContent = 'Verifying…';
        errEl.textContent = '';

        fetch(window.location.pathname, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-MecroGuard': 'verify' },
            body: JSON.stringify({
                token : TOKEN,
                ms    : Date.now() - start,
                hp    : document.getElementById('mgHp')?.value ?? '',
                page  : PAGE_URL,
            })
        })
        .then(r => r.json())
        .then(d => d.ok ? onPass() : onFail(d.msg))
        .catch(() => onFail('Connection error — please refresh.'));
    };

    function onPass() {
        box.classList.remove('spinning');
        box.classList.add('done');
        checkRow.classList.add('verified');
        sub.textContent = 'Verified ✓';
        setTimeout(() => {
            overlay.classList.add('mg-out');
            setTimeout(() => overlay.remove(), 620);
        }, 700);
    }

    function onFail(msg) {
        busy = false;
        box.classList.remove('spinning');
        checkRow.classList.add('error');
        errEl.textContent = msg || 'Verification failed — please try again.';
        sub.textContent = 'Try again';
        setTimeout(() => checkRow.classList.remove('error'), 2000);
    }
})();
</script>