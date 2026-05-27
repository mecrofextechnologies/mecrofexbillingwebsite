<?php
/* ════════════════════════════════════════════════════════════
   KEYCHELE HOME PAGE — index.php
   Requires: css/keychele-home.css
════════════════════════════════════════════════════════════════ */
include 'msanii/includes/connect.php';
include_once 'includes/track_visit.php';
include_once 'includes/mecroguard.php';

date_default_timezone_set('Africa/Nairobi');

// ── Hero Slides ──────────────────────────────────────────────
$slides = [];
try {
    $slides = $pdo->query("SELECT * FROM sliders ORDER BY id DESC")
                  ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $slides = []; }

// ── ALL Events — RANDOM ORDER (reshuffles on every reload) ────
$allEvents = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.title, t.venue, t.start_date, t.start_time,
               t.banner, t.creator_id, t.show_views,
               COALESCE(tpv.total_views, 0) AS views,
               c.username, mp.profile_pic,
               MIN(ett.price)  AS min_price,
               COUNT(ett.id)   AS ticket_count,
               CASE
                 WHEN (t.start_date > CURDATE()
                   OR (t.start_date = CURDATE() AND t.start_time > CURTIME()))
                 THEN 'upcoming' ELSE 'past'
               END AS ev_type
        FROM tickets t
        LEFT JOIN creators c             ON t.creator_id = c.id
        LEFT JOIN my_page mp             ON c.id = mp.creator_id
        LEFT JOIN event_ticket_types ett ON t.id = ett.event_id
        LEFT JOIN total_ticketpageviews tpv ON tpv.ticket_id = t.id
        WHERE t.creator_id IS NOT NULL
          AND (t.visibility = 'public' OR t.visibility IS NULL)
        GROUP BY t.id
        ORDER BY RAND()
        LIMIT 100
    ");
    $stmt->execute();
    $allEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $allEvents = []; }

// ── Event months — dedicated DB query, only months that have events ──
$eventMonths = [];
try {
    $rows = $pdo->query("
        SELECT DISTINCT DATE_FORMAT(start_date, '%Y-%m') AS mk,
                        DATE_FORMAT(start_date, '%b %Y')  AS ml
        FROM tickets
        WHERE creator_id IS NOT NULL
          AND start_date IS NOT NULL
          AND (visibility = 'public' OR visibility IS NULL)
        ORDER BY mk DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $eventMonths[$r['mk']] = $r['ml'];
} catch (Exception $e) { $eventMonths = []; }

// ── Past Events only ──────────────────────────────────────────
$pastEvents = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.title, t.venue, t.start_date, t.start_time,
               t.banner, t.creator_id, t.show_views,
               COALESCE(tpv.total_views, 0) AS views,
               c.username, mp.profile_pic,
               MIN(ett.price) AS min_price, COUNT(ett.id) AS ticket_count
        FROM tickets t
        LEFT JOIN creators c             ON t.creator_id = c.id
        LEFT JOIN my_page mp             ON c.id = mp.creator_id
        LEFT JOIN event_ticket_types ett ON t.id = ett.event_id
        LEFT JOIN total_ticketpageviews tpv ON tpv.ticket_id = t.id
        WHERE (t.start_date < CURDATE()
           OR (t.start_date = CURDATE() AND t.start_time <= CURTIME()))
          AND t.creator_id IS NOT NULL
          AND (t.visibility = 'public' OR t.visibility IS NULL)
        GROUP BY t.id
        ORDER BY t.start_date DESC, t.start_time DESC
        LIMIT 100
    ");
    $stmt->execute();
    $pastEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $pastEvents = []; }

// ── Past-event months — only months that have past events ────
$pastEventMonths = [];
try {
    $rows = $pdo->query("
        SELECT DISTINCT DATE_FORMAT(start_date, '%Y-%m') AS mk,
                        DATE_FORMAT(start_date, '%b %Y')  AS ml
        FROM tickets
        WHERE creator_id IS NOT NULL
          AND start_date IS NOT NULL
          AND (start_date < CURDATE()
               OR (start_date = CURDATE() AND start_time <= CURTIME()))
          AND (visibility = 'public' OR visibility IS NULL)
        ORDER BY mk DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $pastEventMonths[$r['mk']] = $r['ml'];
} catch (Exception $e) { $pastEventMonths = []; }

// ── Creators ─────────────────────────────────────────────────
$creators = [];
try {
    $creators = $pdo->query("
        SELECT mp.*,
               c.username,
               COALESCE(NULLIF(TRIM(c.full_name),''),'Creator') AS full_name,
               COUNT(DISTINCT t.id)  AS event_count,
               COUNT(DISTINCT tp.id) AS ticket_count
        FROM my_page mp
        JOIN creators c ON mp.creator_id = c.id
        LEFT JOIN tickets t  ON t.creator_id = c.id
        LEFT JOIN ticket_purchases tp
               ON tp.ticket_id = t.id AND tp.status = 'completed'
        GROUP BY mp.id, c.username, c.full_name
        ORDER BY mp.created_at DESC
        LIMIT 80
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $creators = []; }

// ── Music Tracks ──────────────────────────────────────────────
$musicTracks = [];
try {
    $stmt = $pdo->prepare("
        SELECT m.id, m.title, m.artist, m.album, m.genre, m.description,
               m.audio_file, m.cover_art, m.is_free, m.price,
               m.release_date, m.play_count, m.download_count,
               COALESCE(mp.full_name, c.username) AS creator_name,
               mp.profile_pic AS creator_pic
        FROM music m
        LEFT JOIN creators c ON c.id = m.creator_id
        LEFT JOIN my_page mp ON mp.creator_id = m.creator_id
        WHERE m.status = 'published' AND m.visibility = 'public'
        ORDER BY m.play_count DESC, m.created_at DESC
        LIMIT 80
    ");
    $stmt->execute();
    $musicTracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $musicTracks = []; }

// ── Platform Stats ────────────────────────────────────────────
$totalCreators = (int)$pdo->query("SELECT COUNT(*) FROM my_page mp JOIN creators c ON mp.creator_id=c.id")->fetchColumn();
$totalEvents   = (int)$pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
$totalTickets  = (int)$pdo->query("SELECT COUNT(*) FROM ticket_purchases WHERE status='completed'")->fetchColumn();

// Total views: sum the `views` column from the tickets table
try {
    $totalViews = (int)$pdo->query("SELECT COALESCE(SUM(views), 0) FROM tickets")->fetchColumn();
} catch (Exception $e) {
    try {
        $totalViews = (int)$pdo->query("SELECT COUNT(*) FROM ticket_page_visits")->fetchColumn();
    } catch (Exception $e2) { $totalViews = 0; }
}

// ── Helpers ───────────────────────────────────────────────────
include_once __DIR__ . '/includes/creator_ticket_settings.php';

function daysUntil($d) {
    $t    = new DateTime('today');
    $e    = new DateTime($d);
    $diff = $t->diff($e);
    return $diff->invert ? -$diff->days : $diff->days;
}

function fmtViews(int $n): string {
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 1000)    return round($n / 1000, 1) . 'K';
    return (string)$n;
}

function getGenreIcon(string $genre): string {
    $map = [
        'afrobeats'   => 'fa-drum',
        'bongo flava' => 'fa-drum',
        'gengetone'   => 'fa-drum',
        'amapiano'    => 'fa-drum',
        'gospel'      => 'fa-church',
        'hip-hop'     => 'fa-microphone-lines',
        'drill'       => 'fa-microphone-lines',
        'r&b'         => 'fa-heart',
        'reggae'      => 'fa-peace',
        'jazz'        => 'fa-violin',
        'classical'   => 'fa-violin',
        'electronic'  => 'fa-wave-square',
    ];
    return $map[strtolower(trim($genre))] ?? 'fa-music';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Keychele — Ticketing, Fundraising &amp; Creator Platform in Kenya</title>
    <meta name="description" content="Sell event tickets, receive fan donations, fundraise, host trivia &amp; sell digital products — all in one M-Pesa ready platform.">
    <meta name="keywords" content="event ticketing Kenya, sell tickets online, online fundraising Kenya, creator donations, M-Pesa ticketing, creator platform Africa">
    <meta name="author" content="Keychele Team">
    <meta name="robots" content="index, follow">

    <meta property="og:title" content="Keychele – Ticketing, Fundraising &amp; Fan Support for Creators in Kenya">
    <meta property="og:description" content="Sell tickets, run fundraisers, host competitions — M-Pesa integrated. The creator's platform.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://keychele.co.ke">
    <meta property="og:image" content="https://keychele.co.ke/og-image.jpg">
    <meta property="og:site_name" content="Keychele">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Keychele – Kenya's Creator Platform">
    <meta name="twitter:image" content="https://keychele.co.ke/twitter-image.jpg">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    <!-- Core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/magnific-popup.css" rel="stylesheet">
    <link href="css/tooplate-waso-strategy.css" rel="stylesheet">

    <!-- Home Page Styles -->
    <link href="css/keychele-home.css" rel="stylesheet">

    <link rel="icon" href="/favicon.ico" type="image/x-icon">

    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "Keychele",
      "url": "https://keychele.co.ke",
      "logo": "https://keychele.co.ke/logo.png",
      "description": "Kenya's all-in-one platform for event ticketing, fundraising, creator support and competitions.",
      "address": { "@type": "PostalAddress", "addressLocality": "Nairobi", "addressCountry": "KE" },
      "email": "info@keychele.co.ke",
      "telephone": "+254112680521"
    }
    </script>
</head>

<style>
    /* ═══════════════════════════════════════════════════════════════
   KEYCHELE HOME PAGE STYLES — keychele-home.css
   Brand: Red #bf1339 | Gold #fec411 | Navy #052e64
═══════════════════════════════════════════════════════════════ */

/* ── BRAND TOKENS ── */
:root {
    --navy:    #052e64;
    --navy-d:  #031e44;
    --navy-m:  #0a4a99;
    --navy-l:  #e8eef8;
    --navy-xl: #f2f5fc;
    --gold:    #fec411;
    --gold-d:  #d9a70a;
    --gold-l:  #fffbea;
    --cr:      #bf1339;
    --cr-d:    #96102d;
    --cr-l:    #fce8ec;
    --white:   #ffffff;
    --g50:     #f8f9fd;
    --g100:    #eef1f9;
    --g200:    #dce2f0;
    --g400:    #8a97b8;
    --g600:    #4a5578;
    --green:   #16a34a;
    --fh:      'Montserrat', system-ui, sans-serif;
}

/* ═══════════════════════════════════════════════════
   1. HERO SECTION
═══════════════════════════════════════════════════ */
.kc-hero-section {
    position: relative;
    overflow: hidden;
    height: 72vh;
}
.kc-hero-img {
    width: 100%;
    height: 72vh;
    object-fit: cover;
    display: block;
}
.kc-hero-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(5,46,100,.65) 0%, rgba(191,19,57,.35) 100%);
    z-index: 1;
    pointer-events: none;
}
.kc-hero-caption {
    position: absolute;
    inset: 0;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 0 20px;
}
.kc-hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: rgba(254,196,17,.18);
    border: 1px solid rgba(254,196,17,.4);
    color: var(--gold);
    font-family: var(--fh);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 3px;
    text-transform: uppercase;
    padding: 6px 18px;
    border-radius: 30px;
    margin-bottom: 16px;
}
.kc-hero-title {
    font-family: var(--fh);
    font-size: clamp(1.8rem, 4.5vw, 3.4rem);
    font-weight: 900;
    color: #fff;
    line-height: 1.2;
    margin-bottom: 16px;
    text-shadow: 0 2px 20px rgba(0,0,0,.3);
}
.kc-hero-title span { color: var(--gold); }
.kc-hero-sub {
    font-size: clamp(13px, 1.6vw, 16px);
    color: rgba(255,255,255,.82);
    max-width: 560px;
    line-height: 1.7;
    margin-bottom: 28px;
}
.kc-hero-btns {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    justify-content: center;
}
.kc-hero-btn-main {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 13px 32px;
    background: var(--cr);
    color: #fff;
    border: none;
    border-radius: 50px;
    font-family: var(--fh);
    font-size: 14px;
    font-weight: 800;
    text-decoration: none;
    transition: all .2s;
    box-shadow: 0 6px 20px rgba(191,19,57,.4);
}
.kc-hero-btn-main:hover {
    background: var(--cr-d);
    color: #fff;
    transform: translateY(-2px);
}
.kc-hero-btn-out {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 13px 32px;
    border: 2px solid rgba(255,255,255,.7);
    color: #fff;
    border-radius: 50px;
    font-family: var(--fh);
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    transition: all .2s;
    background: rgba(255,255,255,.08);
    backdrop-filter: blur(6px);
}
.kc-hero-btn-out:hover {
    background: rgba(255,255,255,.2);
    color: #fff;
    border-color: #fff;
}

/* Carousel controls */
.kc-hero-prev,
.kc-hero-next {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: rgba(0,0,0,.45);
    border: none;
    z-index: 5;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background .25s, transform .25s;
}
.kc-hero-prev:hover,
.kc-hero-next:hover {
    background: var(--cr);
    transform: translateY(-50%) scale(1.1);
}
.kc-hero-prev { left: 18px; }
.kc-hero-next { right: 18px; }
.kc-hero-prev i,
.kc-hero-next i { color: #fff; font-size: 1.1rem; }

/* Carousel dots */
.kc-hero-dots {
    position: absolute;
    bottom: 18px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 8px;
    z-index: 5;
}
.kc-hero-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255,255,255,.4);
    transition: all .25s;
    cursor: pointer;
}
.kc-hero-dot.active {
    background: var(--gold);
    width: 24px;
    border-radius: 4px;
}

@media (max-width: 991px) {
    .kc-hero-section,
    .kc-hero-img { height: 55vh; }
}
@media (max-width: 576px) {
    .kc-hero-section,
    .kc-hero-img { height: 46vh; }
    .kc-hero-prev,
    .kc-hero-next { width: 36px; height: 36px; font-size: .9rem; }
}

/* ═══════════════════════════════════════════════════
   2. ADVERTS STRIP
═══════════════════════════════════════════════════ */
.kc-advert-section {
    background: #fff;
    padding: 20px 0;
    border-bottom: 1px solid var(--g100);
}
.kc-advert-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}
.kc-advert-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    gap: 8px;
    padding: 16px 10px 14px;
    border-radius: 14px;
    text-decoration: none;
    position: relative;
    overflow: hidden;
    transition: transform .25s, box-shadow .25s;
    border: 1.5px solid transparent;
    text-align: center;
    min-width: 0;
}
.kc-advert-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(0,0,0,.14);
}
.kc-advert-card::before {
    content: '';
    position: absolute;
    inset: 0;
    opacity: .07;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23fff' fill-opacity='1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.kc-advert-card-1 { background: linear-gradient(135deg, var(--navy), var(--navy-m)); border-color: rgba(5,46,100,.2); }
.kc-advert-card-2 { background: linear-gradient(135deg, var(--cr), #e8143f);        border-color: rgba(191,19,57,.2); }
.kc-advert-card-3 { background: linear-gradient(135deg, #d9860a, var(--gold));      border-color: rgba(254,196,17,.3); }

.kc-advert-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(255,255,255,.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #fff;
    flex-shrink: 0;
    backdrop-filter: blur(4px);
}
.kc-advert-text { flex: 1; min-width: 0; }
.kc-advert-text h6 {
    font-family: var(--fh);
    font-size: 12px;
    font-weight: 800;
    color: #fff;
    margin-bottom: 2px;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.kc-advert-text p {
    font-size: 10px;
    color: rgba(255,255,255,.78);
    margin: 0;
    line-height: 1.4;
}
.kc-advert-arrow { display: none; }

@media (min-width: 640px) {
    .kc-advert-section { padding: 28px 0; }
    .kc-advert-grid { gap: 16px; }
    .kc-advert-card {
        flex-direction: row;
        align-items: center;
        justify-content: flex-start;
        padding: 18px;
        gap: 14px;
        text-align: left;
    }
    .kc-advert-icon { width: 48px; height: 48px; font-size: 22px; }
    .kc-advert-text h6 { font-size: 14px; }
    .kc-advert-text p  { font-size: 11px; }
    .kc-advert-arrow {
        display: flex;
        margin-left: auto;
        flex-shrink: 0;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: rgba(255,255,255,.2);
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 12px;
        transition: background .2s;
    }
    .kc-advert-card:hover .kc-advert-arrow { background: rgba(255,255,255,.35); }
}
@media (max-width: 399px) {
    .kc-advert-text p { display: none; }
    .kc-advert-icon { width: 38px; height: 38px; font-size: 16px; }
    .kc-advert-text h6 { font-size: 11px; }
    .kc-advert-card { padding: 14px 8px 12px; }
}

/* ════════════════════════════════════════════════════════
   3. IMAGE AD BANNER
════════════════════════════════════════════════════════ */
.kc-ad-banner {
    width: 100%;
    padding: 0.6rem 0 0.4rem;
    box-sizing: border-box;
}
.kc-ad-label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
    padding: 0 2px;
}
.kc-ad-label::before,
.kc-ad-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e5e7eb;
}
.kc-ad-sponsored {
    font-size: 10px;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    white-space: nowrap;
    font-weight: 500;
}
.kc-ad-slider-wrap {
    position: relative;
    width: 100%;
    overflow: hidden;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    background: #000;
    line-height: 0;
}
.kc-ad-track {
    display: flex;
    width: 100%;
    transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}
.kc-ad-slide {
    min-width: 100%;
    width: 100%;
    flex-shrink: 0;
    box-sizing: border-box;
}
.kc-ad-slide a { display: block; width: 100%; line-height: 0; }
.kc-ad-img {
    display: block;
    width: 100%;
    height: auto;
    aspect-ratio: 970 / 350;
    object-fit: contain;
    object-position: center;
    background: #000;
}
.kc-ad-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(0,0,0,.4);
    color: #fff;
    border: none;
    cursor: pointer;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    font-size: 24px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .2s, opacity .2s;
    z-index: 3;
    padding: 0;
    opacity: .7;
}
.kc-ad-arrow:hover { background: rgba(0,0,0,.65); opacity: 1; }
.kc-ad-prev { left: 10px; }
.kc-ad-next { right: 10px; }
.kc-ad-badge {
    position: absolute;
    bottom: 8px;
    left: 10px;
    font-size: 10px;
    font-weight: 500;
    color: #fff;
    background: rgba(0,0,0,.5);
    border-radius: 3px;
    padding: 2px 6px;
    letter-spacing: 0.05em;
    pointer-events: none;
    z-index: 3;
    line-height: 1.4;
}
.kc-ad-dots {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    min-height: 14px;
}
.kc-ad-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #d1d5db;
    border: none;
    cursor: pointer;
    padding: 0;
    transition: background .25s, transform .25s;
    flex-shrink: 0;
}
.kc-ad-dot.active { background: #374151; transform: scale(1.4); }

@media (min-width: 1200px) { .kc-ad-img { aspect-ratio: 970 / 350; } }
@media (max-width: 1024px) { .kc-ad-img { aspect-ratio: 728 / 300; } }
@media (max-width: 768px) {
    .kc-ad-img { aspect-ratio: 600 / 280; }
    .kc-ad-arrow { width: 28px; height: 28px; font-size: 20px; }
    .kc-ad-prev { left: 6px; }
    .kc-ad-next { right: 6px; }
}
@media (max-width: 480px) {
    .kc-ad-img { aspect-ratio: 3 / 2; }
    .kc-ad-arrow { width: 24px; height: 24px; font-size: 17px; opacity: .85; }
    .kc-ad-prev { left: 4px; }
    .kc-ad-next { right: 4px; }
    .kc-ad-badge { font-size: 9px; padding: 1px 5px; bottom: 5px; left: 6px; }
    .kc-ad-dots { gap: 5px; margin-top: 6px; }
    .kc-ad-dot { width: 5px; height: 5px; }
}
@media (max-width: 360px) {
    .kc-ad-img { aspect-ratio: 5 / 3; }
    .kc-ad-arrow { width: 22px; height: 22px; font-size: 15px; }
}

/* ═══════════════════════════════════════════════════
   4. HUB SECTION — Tabbed Explorer
═══════════════════════════════════════════════════ */
.kc-hub-section {
    background: var(--g50);
    padding: 60px 0 80px;
    overflow-x: hidden;
}
.kc-hub-header {
    text-align: center;
    margin-bottom: 36px;
}
.kc-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: rgba(254,196,17,.12);
    border: 1px solid rgba(254,196,17,.35);
    color: var(--gold-d);
    font-family: var(--fh);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 3px;
    text-transform: uppercase;
    padding: 6px 16px;
    border-radius: 30px;
    margin-bottom: 14px;
}
.kc-section-title {
    font-family: var(--fh);
    font-weight: 900;
    font-size: clamp(22px, 4vw, 34px);
    color: var(--navy);
    line-height: 1.15;
    margin-bottom: 12px;
}
.kc-section-title em { font-style: normal; color: var(--cr); }

/* ── Tab Navigation ── */
.kc-tabs-nav {
    display: flex;
    align-items: center;
    gap: 4px;
    background: #fff;
    border: 1.5px solid var(--g100);
    border-radius: 50px;
    padding: 4px;
    width: fit-content;
    max-width: 100%;
    margin: 0 auto 32px;
    box-shadow: 0 2px 12px rgba(5,46,100,.07);
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
}
.kc-tabs-nav::-webkit-scrollbar { display: none; }
.kc-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 14px;
    border: none;
    border-radius: 50px;
    background: transparent;
    font-family: var(--fh);
    font-size: 12px;
    font-weight: 700;
    color: var(--g600);
    cursor: pointer;
    transition: all .22s;
    white-space: nowrap;
    flex-shrink: 0;
}
.kc-tab-btn i { font-size: 11px; }
.kc-tab-btn:hover { color: var(--navy); background: var(--g50); }
.kc-tab-btn.active {
    background: var(--navy);
    color: #fff;
    box-shadow: 0 4px 14px rgba(5,46,100,.25);
}
.kc-tab-btn.active i { color: var(--gold); }
.kc-tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 4px;
    border-radius: 9px;
    background: rgba(255,255,255,.2);
    font-size: 9px;
    font-weight: 800;
}
.kc-tab-btn:not(.active) .kc-tab-count { background: var(--g100); color: var(--navy); }
.kc-tab-panel { display: none; }
.kc-tab-panel.active { display: block; }

/* ── Grid ── */
.kc-hub-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    width: 100%;
    box-sizing: border-box;
}
@media (min-width: 640px)  { .kc-hub-grid { grid-template-columns: repeat(3, 1fr); gap: 16px; } }
@media (min-width: 1024px) { .kc-hub-grid { grid-template-columns: repeat(4, 1fr); gap: 18px; } }

/* ── Lazy load animation ── */
.kc-grid-item {
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .38s ease, transform .38s ease;
    min-width: 0;
    width: 100%;
    box-sizing: border-box;
}
.kc-grid-item.kc-in-view { opacity: 1; transform: translateY(0); }
.kc-item-hidden { display: none !important; }
.kc-grid-item:nth-child(2) { transition-delay: .04s; }
.kc-grid-item:nth-child(3) { transition-delay: .08s; }
.kc-grid-item:nth-child(4) { transition-delay: .12s; }
.kc-grid-item:nth-child(5) { transition-delay: .16s; }
.kc-grid-item:nth-child(6) { transition-delay: .20s; }
.kc-grid-item:nth-child(7) { transition-delay: .24s; }
.kc-grid-item:nth-child(8) { transition-delay: .28s; }

/* ══════════════════════════════════════════════════════════════
   UNIFIED CARD SYSTEM
   Events (.kc-ev-card) · Creators (.kc-cr-card) · Music (.kc-mu-card)
   All share the same poster silhouette, overlay, badge & price
   positioning — synced with ticketstyle.css .ecard
══════════════════════════════════════════════════════════════ */

/* ── Shared card shell ── */
.kc-ev-card,
.kc-cr-card,
.kc-mu-card {
    background: var(--white);
    border-radius: 14px;
    overflow: hidden;
    border: 1.5px solid var(--g100);
    box-shadow: 0 4px 18px rgba(5,46,100,.08);
    display: flex;
    flex-direction: column;
    transition: transform .28s ease, box-shadow .28s ease, border-color .2s;
    min-width: 0;
    width: 100%;
    box-sizing: border-box;
    text-decoration: none;
}
.kc-ev-card:hover,
.kc-cr-card:hover,
.kc-mu-card:hover {
    transform: translateY(-7px);
    box-shadow: 0 20px 50px rgba(5,46,100,.18);
    border-color: rgba(5,46,100,.14);
}

/* ── Shared image area — portrait poster ratio (3:4) ── */
.kc-ev-img,
.kc-mu-cover {
    position: relative;
    width: 100%;
    aspect-ratio: 3 / 4;
    overflow: hidden;
    background: var(--g100);
    flex-shrink: 0;
    /* override old padding-top trick */
    padding-top: 0 !important;
}
.kc-ev-img img,
.kc-mu-cover img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .55s ease;
}
.kc-ev-card:hover .kc-ev-img img,
.kc-mu-card:hover .kc-mu-cover img { transform: scale(1.06); }

/* Music cover stays square */
.kc-mu-cover { aspect-ratio: 1 / 1; }

/* ── Gradient fade — bottom-to-top ── */
.kc-ev-img-grad {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to top,
        rgba(5,46,100,.88) 0%,
        rgba(5,46,100,.30) 45%,
        transparent 100%
    );
}

/* ── Status / genre badge — top left ── */
.kc-ev-badge,
.kc-mu-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(5,46,100,.82);
    color: #fff;
    font-family: var(--fh);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
    padding: 3px 9px;
    border-radius: 20px;
    border: 1px solid rgba(254,196,17,.38);
    backdrop-filter: blur(5px);
    white-space: nowrap;
    line-height: 1;
}
.kc-ev-badge i,
.kc-mu-badge i { color: var(--gold); font-size: 9px; }

.kc-ev-badge.upcoming {
    background: rgba(5,46,100,.85);
    color: var(--gold);
    border-color: rgba(254,196,17,.3);
}
.kc-ev-badge.live {
    background: rgba(22,163,74,.9);
    color: #fff;
    border-color: transparent;
    animation: kcLivePulse 2s infinite;
}
.kc-ev-badge.past {
    background: rgba(50,55,70,.8);
    color: rgba(255,255,255,.65);
    border-color: transparent;
}
@keyframes kcLivePulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(22,163,74,.5); }
    50%      { box-shadow: 0 0 0 6px rgba(22,163,74,0); }
}

/* ── Countdown chip — top right (events only) ── */
.kc-ev-cd {
    position: absolute;
    top: 8px;
    right: 8px;
    font-family: var(--fh);
    font-size: 9px;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 20px;
    backdrop-filter: blur(5px);
    line-height: 1;
    white-space: nowrap;
}
.kc-ev-cd.soon  { background: rgba(191,19,57,.9);  color: #fff; }
.kc-ev-cd.days  { background: rgba(254,196,17,.9); color: var(--navy); }
.kc-ev-cd.ended { background: rgba(40,45,60,.8);   color: rgba(255,255,255,.5); }

/* ── Hot / New badges — top right (music only) ── */
.kc-mu-hot-badge,
.kc-mu-new-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    font-family: var(--fh);
    font-size: 9px;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 999px;
    line-height: 1;
    color: #fff;
}
.kc-mu-hot-badge {
    background: rgba(191,19,57,.9);
    animation: muHotPulse 2s infinite;
}
.kc-mu-new-badge { background: rgba(5,100,52,.9); }
@keyframes muHotPulse { 0%,100%{opacity:1} 50%{opacity:.7} }

/* ── Price tag — bottom left (crimson pill) ── */
.kc-ev-price {
    position: absolute;
    bottom: 10px;
    left: 10px;
    background: var(--cr);
    color: #fff;
    font-family: var(--fh);
    font-size: 11px;
    font-weight: 800;
    padding: 4px 11px;
    border-radius: 6px;
    line-height: 1.4;
    white-space: nowrap;
    letter-spacing: .3px;
    text-shadow: none;
}
.kc-ev-price.free { background: var(--green); }

/* ── Play button — bottom right (music only) ── */
.kc-mu-play-btn {
    position: absolute;
    bottom: 10px;
    right: 10px;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: rgba(255,255,255,.92);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: var(--navy);
    box-shadow: 0 3px 14px rgba(0,0,0,.28);
    transition: transform .22s;
    z-index: 10;
    /* remove old full-cover overlay behaviour */
    inset: auto;
    width: 38px;
    height: 38px;
}
.kc-mu-card:hover .kc-mu-play-btn { transform: scale(1.12); }
/* Hide the old scale-in icon wrapper */
.kc-mu-play-btn .kc-play-icon {
    width: auto;
    height: auto;
    background: transparent;
    box-shadow: none;
    transform: scale(1) !important;
    color: inherit;
}

/* Music cover placeholder */
.kc-mu-cover-ph {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    color: rgba(254,196,17,.3);
}

/* ── Views badge (optional overlay) ── */
.kc-ev-views {
    position: absolute;
    bottom: 10px;
    right: 10px;
    background: rgba(5,46,100,.65);
    color: rgba(255,255,255,.82);
    font-family: var(--fh);
    font-size: 9px;
    font-weight: 700;
    padding: 3px 7px;
    border-radius: 20px;
    backdrop-filter: blur(4px);
    display: inline-flex;
    align-items: center;
    gap: 3px;
    line-height: 1;
}
.kc-ev-views i { font-size: 8px; }

/* ── Shared card body ── */
.kc-ev-body,
.kc-mu-body {
    padding: 12px 13px 15px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 0;
}

/* ── Shared title ── */
.kc-ev-title,
.kc-mu-title {
    font-family: var(--fh);
    font-weight: 800;
    font-size: 13px;
    color: var(--navy);
    line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: 0;
}

/* ── Host row (events) ── */
.kc-ev-host {
    display: flex;
    align-items: center;
    gap: 5px;
    min-width: 0;
    overflow: hidden;
}
.kc-ev-host img {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    object-fit: cover;
    border: 1.5px solid var(--navy-l);
    flex-shrink: 0;
}
.kc-ev-host span {
    font-size: 10px;
    color: var(--g400);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.kc-ev-host strong { color: var(--navy); }

/* ── Meta rows (events) ── */
.kc-ev-meta { display: flex; flex-direction: column; gap: 3px; }
.kc-ev-meta-row {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    color: var(--g600);
    min-width: 0;
    overflow: hidden;
}
.kc-ev-meta-row i {
    color: var(--navy);
    font-size: 9px;
    width: 11px;
    flex-shrink: 0;
}

/* ── Divider ── */
.kc-ev-sep {
    height: 1px;
    background: var(--g100);
    margin: 4px 0 6px;
}

/* ── CTA button — shared across all three card types ── */
.kc-ev-cta,
.kc-cr-cta {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 9px 10px;
    border-radius: 8px;
    font-family: var(--fh);
    font-size: 11px;
    font-weight: 800;
    text-decoration: none;
    transition: background .2s, box-shadow .2s;
    margin-top: auto;
    border: none;
    cursor: pointer;
    text-align: center;
}
.kc-ev-cta.buy      { background: var(--cr);    color: #fff; }
.kc-ev-cta.buy:hover { background: var(--cr-d); box-shadow: 0 4px 14px rgba(191,19,57,.35); }
.kc-ev-cta.free-t   { background: var(--green); color: #fff; }
.kc-ev-cta.free-t:hover { background: #138a3e; box-shadow: 0 4px 14px rgba(22,163,74,.3); }
.kc-ev-cta.ended,
.kc-ev-cta.soon-t   { background: var(--g100);  color: var(--g400); cursor: default; }
.kc-cr-cta          { background: var(--navy);   color: #fff; }
.kc-cr-cta:hover    { background: var(--cr);     color: #fff; box-shadow: 0 4px 12px rgba(191,19,57,.3); }

/* ── Past event desaturation ── */
.kc-ev-past { filter: saturate(.55); }
.kc-ev-past:hover { filter: saturate(1); transition: filter .3s ease; }

/* ══════════════════════════════════════════════════
   CREATOR CARD — banner + floating avatar
══════════════════════════════════════════════════ */
.kc-cr-banner {
    height: 56px;
    background: linear-gradient(135deg, var(--navy), var(--navy-m));
    position: relative;
    flex-shrink: 0;
}
.kc-cr-banner::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--gold);
    z-index: 2;
}
.kc-cr-av-wrap {
    display: flex;
    justify-content: center;
    margin-top: -34px;
    margin-bottom: 7px;
    position: relative;
    z-index: 5;
}
.kc-cr-av {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid var(--white);
    box-shadow: 0 4px 14px rgba(5,46,100,.20);
    background: var(--g100);
}
.kc-cr-av-fb {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--navy), var(--navy-m));
    border: 3px solid var(--white);
    box-shadow: 0 4px 14px rgba(5,46,100,.20);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--fh);
    font-weight: 900;
    font-size: 24px;
    color: var(--gold);
}
.kc-cr-body {
    padding: 0 13px 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    flex: 1;
    min-width: 0;
}
.kc-cr-name {
    font-family: var(--fh);
    font-weight: 800;
    font-size: 13px;
    color: var(--navy);
    margin-bottom: 2px;
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    width: 100%;
}
.kc-cr-uname {
    font-size: 11px;
    color: var(--cr);
    font-weight: 700;
    margin-bottom: 9px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    width: 100%;
}
.kc-cr-stats {
    display: flex;
    width: 100%;
    margin-bottom: 10px;
    border: 1.5px solid var(--g100);
    border-radius: 9px;
    overflow: hidden;
}
.kc-cr-stat {
    flex: 1;
    padding: 7px 3px;
    text-align: center;
    border-right: 1.5px solid var(--g100);
}
.kc-cr-stat:last-child { border-right: none; }
.kc-cr-stat-n {
    font-family: var(--fh);
    font-weight: 900;
    font-size: 14px;
    color: var(--navy);
    line-height: 1;
}
.kc-cr-stat-l {
    font-size: 8px;
    color: var(--g400);
    font-weight: 600;
    letter-spacing: .8px;
    text-transform: uppercase;
    margin-top: 2px;
}

/* ══════════════════════════════════════════════════
   MUSIC CARD — artist + foot row
══════════════════════════════════════════════════ */
.kc-mu-artist {
    font-size: 10px;
    font-weight: 700;
    color: var(--g400);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.kc-mu-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
    margin-top: auto;
    padding-top: 8px;
    border-top: 1px solid var(--g100);
}
.kc-mu-free-tag {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
    font-size: 9px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 999px;
}
.kc-mu-price {
    font-size: 13px;
    font-weight: 900;
    color: var(--navy);
}
.kc-mu-cta {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--cr);
    color: #fff;
    font-family: var(--fh);
    font-size: 9px;
    font-weight: 800;
    padding: 5px 10px;
    border-radius: 7px;
    text-transform: uppercase;
    letter-spacing: .03em;
    text-decoration: none;
    transition: background .2s, box-shadow .2s;
}
.kc-mu-cta:hover { background: var(--cr-d); box-shadow: 0 3px 10px rgba(191,19,57,.3); }
.kc-mu-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    font-size: 9px;
    color: var(--g400);
    margin-top: 5px;
}
.kc-mu-meta span { display: flex; align-items: center; gap: 3px; }
.kc-mu-meta i { font-size: 8px; color: var(--g200); }

/* ══════════════════════════════════════════════════
   MONTH PILLS ROW
══════════════════════════════════════════════════ */
.kc-month-pills-row {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 18px;
    overflow-x: auto;
    overflow-y: visible;
    flex-wrap: nowrap;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 4px;
}
.kc-month-pills-row::-webkit-scrollbar { display: none; }
button.kc-month-pill,
.kc-month-pill {
    -webkit-appearance: none;
    appearance: none;
    display: inline-flex;
    align-items: center;
    padding: 5px 13px;
    border: 1.5px solid var(--g200);
    border-radius: 20px;
    font-family: var(--fh);
    font-size: 11px;
    font-weight: 700;
    line-height: 1.4;
    color: var(--g600) !important;
    background: var(--white) !important;
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    transition: all .18s;
    box-shadow: none !important;
    outline: none;
    margin: 0;
}
button.kc-month-pill:hover,
.kc-month-pill:hover {
    border-color: var(--navy);
    color: var(--navy) !important;
    background: var(--navy-l) !important;
}
button.kc-month-pill.active,
.kc-month-pill.active {
    background: var(--navy) !important;
    color: #fff !important;
    border-color: var(--navy);
    box-shadow: 0 3px 10px rgba(5,46,100,.22) !important;
}

/* ══════════════════════════════════════════════════
   TAB FILTER ROW
══════════════════════════════════════════════════ */
.kc-tab-filter-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 18px;
}
.kc-tab-filter-left { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.kc-filter-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 12px;
    border: 1.5px solid var(--g200);
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    color: var(--g600);
    background: var(--white);
    cursor: pointer;
    transition: all .18s;
    white-space: nowrap;
}
.kc-filter-pill:hover,
.kc-filter-pill.active {
    border-color: var(--navy);
    color: var(--navy);
    background: var(--navy-l);
}
.kc-tab-search-wrap {
    display: flex;
    align-items: center;
    gap: 7px;
    background: var(--white);
    border: 1.5px solid var(--g200);
    border-radius: 30px;
    padding: 0 12px;
    height: 36px;
    transition: border-color .2s;
    flex: 1;
    min-width: 0;
}
.kc-tab-search-wrap:focus-within { border-color: var(--navy); }
.kc-tab-search-wrap i { color: var(--g400); font-size: 11px; flex-shrink: 0; }
.kc-tab-search-wrap input {
    border: none;
    outline: none;
    font-size: 12px;
    color: var(--navy);
    background: transparent;
    flex: 1;
    min-width: 0;
    width: 100%;
}
.kc-tab-search-wrap input::placeholder { color: var(--g400); }

@media (max-width: 639px) {
    .kc-tab-filter-row { flex-direction: column; align-items: stretch; }
    .kc-tab-filter-left {
        flex-wrap: nowrap;
        overflow-x: auto;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 2px;
    }
    .kc-tab-filter-left::-webkit-scrollbar { display: none; }
    .kc-tab-search-wrap { width: 100%; }
}

/* ══════════════════════════════════════════════════
   RESPONSIVE — card image ratios
══════════════════════════════════════════════════ */
@media (max-width: 600px) {
    .kc-ev-img  { aspect-ratio: 2 / 3; }
    .kc-mu-cover { aspect-ratio: 1 / 1; }
    .kc-ev-badge, .kc-mu-badge { font-size: 9px; padding: 2px 7px; top: 6px; left: 6px; }
    .kc-ev-cd   { font-size: 8px; padding: 2px 6px; top: 6px; right: 6px; }
    .kc-ev-price { font-size: 10px; padding: 3px 9px; bottom: 8px; left: 8px; }
    .kc-mu-play-btn { width: 32px; height: 32px; font-size: 12px; bottom: 8px; right: 8px; }
    .kc-ev-title, .kc-mu-title { font-size: 12px; }
    .kc-cr-av, .kc-cr-av-fb   { width: 60px; height: 60px; }
}
@media (max-width: 380px) {
    .kc-ev-badge, .kc-mu-badge,
    .kc-ev-cd, .kc-ev-price { display: none; }
}

/* ═══════════════════════════════════════════════════
   5. MINI AUDIO PLAYER
═══════════════════════════════════════════════════ */
.kc-mini-player {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    gap: 14px;
    background: rgba(5,30,68,.97);
    backdrop-filter: blur(16px);
    border-top: 1px solid rgba(255,255,255,.08);
    padding: 10px 20px;
    box-shadow: 0 -8px 32px rgba(0,0,0,.4);
    animation: playerSlideUp .3s cubic-bezier(.16,1,.3,1) both;
}
.kc-mini-player.show { display: flex; }
@keyframes playerSlideUp {
    from { transform: translateY(100%); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}
.kc-mp-cover {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    flex-shrink: 0;
    background: #1a2744;
    border: 1px solid rgba(255,255,255,.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    color: rgba(255,255,255,.3);
    overflow: hidden;
}
.kc-mp-cover img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
.kc-mp-info { flex-shrink: 0; min-width: 0; max-width: 160px; }
.kc-mp-title  { font-size: .78rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kc-mp-artist { font-size: .65rem; color: rgba(255,255,255,.45); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kc-mp-controls { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.kc-mp-ctrl {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    background: rgba(255,255,255,.08);
    color: rgba(255,255,255,.6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .78rem;
    transition: background .15s, color .15s;
}
.kc-mp-ctrl:hover { background: rgba(255,255,255,.15); color: #fff; }
.kc-mp-ctrl.play-btn {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--cr), #e8143f);
    color: #fff;
    font-size: .88rem;
    box-shadow: 0 3px 14px rgba(191,19,57,.4);
}
.kc-mp-ctrl.play-btn:hover { background: linear-gradient(135deg, #d4163f, #ff2050); }
.kc-mp-progress { flex: 1; min-width: 0; }
.kc-mp-bar-wrap { cursor: pointer; padding: 6px 0; }
.kc-mp-bar {
    width: 100%;
    height: 4px;
    border-radius: 999px;
    background: rgba(255,255,255,.12);
    overflow: hidden;
}
.kc-mp-fill {
    height: 100%;
    border-radius: 999px;
    width: 0%;
    background: linear-gradient(90deg, var(--cr), var(--gold));
    transition: width .3s linear;
}
.kc-mp-times {
    display: flex;
    justify-content: space-between;
    font-size: .6rem;
    color: rgba(255,255,255,.35);
    margin-top: 3px;
}
.kc-mp-close {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    background: rgba(255,255,255,.07);
    color: rgba(255,255,255,.4);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .72rem;
    transition: background .15s, color .15s;
}
.kc-mp-close:hover { background: rgba(191,19,57,.3); color: #fff; }

@media (max-width: 540px) {
    .kc-mini-player { gap: 10px; padding: 10px 14px; }
    .kc-mp-info { display: none; }
    .kc-mp-progress { flex: 1; }
}

/* ── Event card as full link ── */
a.kc-ev-card {
    text-decoration: none;
    color: inherit;
}
a.kc-ev-card:hover {
    border-color: rgba(254,196,17,.35);
}

/* ── Views badge ── */
.kc-ev-views {
    position: absolute;
    bottom: 10px;
    right: 10px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(5,46,100,.72);
    color: rgba(255,255,255,.9);
    font-family: var(--fh);
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 20px;
    backdrop-filter: blur(5px);
    border: 1px solid rgba(255,255,255,.12);
    line-height: 1;
    white-space: nowrap;
}
.kc-ev-views i {
    font-size: 9px;
    color: var(--gold);
}

/* ── Load More button ── */
.kc-load-more-wrap {
    text-align: center;
    margin-top: 36px;
}
.kc-load-more-btn {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 13px 40px;
    background: var(--white);
    color: var(--navy);
    border: 2px solid var(--navy);
    border-radius: 50px;
    font-family: var(--fh);
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    transition: all .22s;
    letter-spacing: .5px;
    box-shadow: 0 4px 18px rgba(5,46,100,.10);
}
.kc-load-more-btn i {
    font-size: 11px;
    transition: transform .22s;
}
.kc-load-more-btn:hover:not(:disabled) {
    background: var(--navy);
    color: var(--gold);
    box-shadow: 0 8px 28px rgba(5,46,100,.22);
    transform: translateY(-2px);
}
.kc-load-more-btn:hover:not(:disabled) i {
    transform: translateY(3px);
}
.kc-load-more-btn:disabled,
.kc-load-more-btn.hidden { display: none; }

/* ── Mobile ── */
@media (max-width: 600px) {
    .kc-ev-views { font-size: 9px; padding: 2px 6px; bottom: 8px; right: 8px; }
    .kc-load-more-btn { padding: 11px 28px; font-size: 12px; }
    .kc-ev-body { padding: 8px 10px 11px; }
}
</style>

<body id="section_1">

    <?php include_once('includes/header.php'); ?>
    <?php include_once('includes/nav.php'); ?>

    <main>

    <!-- ════════════════════════════════════════════════════════
         HERO CAROUSEL
    ════════════════════════════════════════════════════════ -->
    <section class="kc-hero-section">
        <div id="kcHeroCarousel" class="carousel slide carousel-fade h-100"
             data-bs-ride="carousel" data-bs-touch="true" data-bs-interval="5000">
            <div class="carousel-inner h-100">

                <?php if (empty($slides)): ?>
                <div class="carousel-item active h-100">
                    <img src="images/slide/christina-wocintechchat-com-NDoVgcS_lZM-unsplash.jpg"
                         class="kc-hero-img" alt="Keychele — Kenya's Creator Platform">
                    <div class="kc-hero-overlay"></div>
                    <div class="kc-hero-caption">
                        <div class="kc-hero-eyebrow"><i class="fas fa-bolt"></i> Welcome to Keychele</div>
                        <h1 class="kc-hero-title">Kenya's <span>Creator</span><br>Monetization Platform</h1>
                        <p class="kc-hero-sub">Sell tickets, run fundraisers, host competitions &amp; receive fan support — all in one place.</p>
                        <div class="kc-hero-btns">
                            <a href="/register" class="kc-hero-btn-main"><i class="fas fa-user-plus"></i> Get Started Free</a>
                            <a href="/events"   class="kc-hero-btn-out"><i class="fas fa-calendar-alt"></i> Browse Events</a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <?php foreach ($slides as $i => $slide): ?>
                    <div class="carousel-item <?= $i === 0 ? 'active' : '' ?> h-100">
                        <img src="uploads/slides/<?= htmlspecialchars($slide['image']) ?>"
                             class="kc-hero-img"
                             alt="<?= htmlspecialchars($slide['title']) ?>">
                        <div class="kc-hero-overlay"></div>
                        <div class="kc-hero-caption">
                            <div class="kc-hero-eyebrow">
                                <i class="fas fa-bolt"></i>
                                <?= htmlspecialchars($slide['description']) ?>
                            </div>
                            <h1 class="kc-hero-title"><?= nl2br(htmlspecialchars($slide['title'])) ?></h1>
                            <div class="kc-hero-btns">
                                <a href="<?= htmlspecialchars($slide['btn1_link']) ?>"
                                   class="kc-hero-btn-main">
                                    <?= htmlspecialchars($slide['btn1_text']) ?>
                                </a>
                                <?php if (!empty($slide['btn2_text']) && !empty($slide['btn2_link'])): ?>
                                <a href="<?= htmlspecialchars($slide['btn2_link']) ?>"
                                   class="kc-hero-btn-out">
                                    <?= htmlspecialchars($slide['btn2_text']) ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            </div><!-- /carousel-inner -->

            <?php if (count($slides) > 1): ?>
            <button class="kc-hero-prev" data-bs-target="#kcHeroCarousel" data-bs-slide="prev" aria-label="Previous">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button class="kc-hero-next" data-bs-target="#kcHeroCarousel" data-bs-slide="next" aria-label="Next">
                <i class="fas fa-chevron-right"></i>
            </button>
            <?php endif; ?>

        </div><!-- /carousel -->

        <?php if (count($slides) > 1): ?>
        <div class="kc-hero-dots" id="kcHeroDots">
            <?php foreach ($slides as $i => $slide): ?>
            <div class="kc-hero-dot <?= $i === 0 ? 'active' : '' ?>"
                 onclick="kcGotoSlide(<?= $i ?>)"></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </section>
    <!-- /HERO -->


    <!-- ════════════════════════════════════════════════════════
         ADVERTS STRIP
    ════════════════════════════════════════════════════════ -->
    <section class="kc-advert-section">
        <div class="container">
            <div class="kc-advert-grid">

                <a href="/register" class="kc-advert-card kc-advert-card-1">
                    <div class="kc-advert-icon"><i class="fas fa-ticket-alt"></i></div>
                    <div class="kc-advert-text">
                        <h6>Sell Tickets</h6>
                        <p>Go live in minutes.</p>
                    </div>
                    <div class="kc-advert-arrow"><i class="fas fa-arrow-right"></i></div>
                </a>

                <a href="/register" class="kc-advert-card kc-advert-card-2">
                    <div class="kc-advert-icon"><i class="fas fa-music"></i></div>
                    <div class="kc-advert-text">
                        <h6>Sell Music</h6>
                        <p>Tracks &amp; albums to fans.</p>
                    </div>
                    <div class="kc-advert-arrow"><i class="fas fa-arrow-right"></i></div>
                </a>

                <a href="/register" class="kc-advert-card kc-advert-card-3">
                    <div class="kc-advert-icon"><i class="fas fa-trophy"></i></div>
                    <div class="kc-advert-text">
                        <h6>Run Contest</h6>
                        <p>Trivia, polls &amp; prizes.</p>
                    </div>
                    <div class="kc-advert-arrow"><i class="fas fa-arrow-right"></i></div>
                </a>

            </div>
        </div>
    </section>
    <!-- /ADVERTS -->

<!-- ════════════════════════════════════════════════════════
     IMAGE AD BANNER
════════════════════════════════════════════════════════ -->
<section class="kc-ad-banner">
  <div class="container">

    <div class="kc-ad-label">
      <span class="kc-ad-sponsored">Sponsored</span>
    </div>

    <div class="kc-ad-slider-wrap">
      <div class="kc-ad-track" id="kcAdTrack">

        <div class="kc-ad-slide">
          <a href="#" target="_blank" rel="noopener sponsored">
            <img src="https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?w=1200&h=120&fit=crop&q=80" alt="Advertisement" class="kc-ad-img" loading="lazy">
          </a>
        </div>

        <div class="kc-ad-slide">
          <a href="#" target="_blank" rel="noopener sponsored">
            <img src="https://images.unsplash.com/photo-1492684223066-81342ee5ff30?w=1200&h=120&fit=crop&q=80" alt="Advertisement" class="kc-ad-img" loading="lazy">
          </a>
        </div>

        <div class="kc-ad-slide">
          <a href="#" target="_blank" rel="noopener sponsored">
            <img src="https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=1200&h=120&fit=crop&q=80" alt="Advertisement" class="kc-ad-img" loading="lazy">
          </a>
        </div>

        <div class="kc-ad-slide">
          <a href="#" target="_blank" rel="noopener sponsored">
            <img src="https://images.unsplash.com/photo-1511578314322-379afb476865?w=1200&h=120&fit=crop&q=80" alt="Advertisement" class="kc-ad-img" loading="lazy">
          </a>
        </div>

      </div>

      <button class="kc-ad-arrow kc-ad-prev" id="kcAdPrev" aria-label="Previous ad">&#8249;</button>
      <button class="kc-ad-arrow kc-ad-next" id="kcAdNext" aria-label="Next ad">&#8250;</button>
      <span class="kc-ad-badge">Ad</span>
    </div>

    <div class="kc-ad-dots" id="kcAdDots"></div>

  </div>
</section>
<!-- /IMAGE AD BANNER -->





    <!-- ════════════════════════════════════════════════════════
         HUB — ONE SECTION, FOUR TABS
    ════════════════════════════════════════════════════════ -->
    <section class="kc-hub-section" id="section_hub">
    <div class="container">

        <div class="kc-hub-header">
            <div class="kc-eyebrow" style="margin-bottom:10px;">
                <i class="fas fa-layer-group"></i> Explore Everything
            </div>
            <h2 class="kc-section-title" style="text-align:center;">
                Discover <em>Events, Creators &amp; Music</em>
            </h2>
        </div>

        <!-- ── Tab Navigation ── -->
        <div class="kc-tabs-nav" role="tablist">
            <button class="kc-tab-btn active" data-tab="all-events" role="tab" aria-selected="true">
                <i class="fas fa-calendar-alt"></i>
                <span>All Events</span>
                <span class="kc-tab-count"><?= count($allEvents) ?></span>
            </button>
            <button class="kc-tab-btn" data-tab="past-events" role="tab" aria-selected="false">
                <i class="fas fa-history"></i>
                <span>Past Events</span>
                <span class="kc-tab-count"><?= count($pastEvents) ?></span>
            </button>
            <button class="kc-tab-btn" data-tab="creators" role="tab" aria-selected="false">
                <i class="fas fa-users"></i>
                <span>Creators</span>
                <span class="kc-tab-count"><?= count($creators) ?></span>
            </button>
            <button class="kc-tab-btn" data-tab="music" role="tab" aria-selected="false">
                <i class="fas fa-music"></i>
                <span>Music</span>
                <span class="kc-tab-count"><?= count($musicTracks) ?></span>
            </button>
        </div>


        <!-- ══════════════════════════════════════════
             TAB 1 — ALL EVENTS
        ══════════════════════════════════════════ -->
        <div class="kc-tab-panel active" id="tab-all-events" role="tabpanel">

            <div class="kc-tab-filter-row">
                <div class="kc-tab-filter-left">
                    <button class="kc-filter-pill active" data-filter="all">All</button>
                    <button class="kc-filter-pill" data-filter="upcoming">Upcoming</button>
                    <button class="kc-filter-pill" data-filter="past">Past</button>
                    <button class="kc-filter-pill" data-filter="free">Free</button>
                </div>
                <div class="kc-tab-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" id="evSearchAll" placeholder="Search events…" autocomplete="off">
                </div>
            </div>

            <?php if (!empty($eventMonths)): ?>
            <div class="kc-month-pills-row" id="kcMonthPillsAll">
                <button class="kc-month-pill active" data-month="">All Months</button>
                <?php foreach ($eventMonths as $mk => $ml): ?>
                <button class="kc-month-pill" data-month="<?= $mk ?>"><?= htmlspecialchars($ml) ?></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="kc-hub-grid" id="grid-all-events">
            <?php if (empty($allEvents)): ?>
                <div class="kc-no-results" style="grid-column:1/-1;">
                    <div class="kc-no-icon"><i class="fas fa-calendar"></i></div>
                    <h4>No Events Yet</h4>
                    <p>Creators are planning something — check back soon!</p>
                </div>
            <?php else: ?>
                <?php
                $idx = 0;
                foreach ($allEvents as $ev):
                    $idx++;

                    $evType  = $ev['ev_type'] ?? 'past';
                    $isPast  = ($evType === 'past');
                    $evDt    = strtotime($ev['start_date'] . ' ' . ($ev['start_time'] ?? '00:00'));
                    $days    = daysUntil($ev['start_date']);
                    $isToday = (date('Y-m-d', $evDt) === date('Y-m-d'));
                    $isLive  = $isToday && !$isPast && abs(time() - $evDt) < 7200;
                    $isFree  = ($ev['ticket_count'] > 0 && (int)$ev['min_price'] === 0);
                    $hasTk   = $ev['ticket_count'] > 0;
                    $evViews = (int)($ev['views'] ?? 0);

                    $hiddenCls = $idx > 8 ? ' kc-item-hidden' : '';
                    $evMonth   = date('Y-m', strtotime($ev['start_date']));

                    if ($isPast)      { $badge = 'past';     $badgeTxt = '✓ Past'; }
                    elseif ($isLive)  { $badge = 'live';     $badgeTxt = '● Live'; }
                    else              { $badge = 'upcoming'; $badgeTxt = 'Upcoming'; }

                    if ($isPast)        { $cdClass = 'ended'; $cdTxt = date('d M Y', strtotime($ev['start_date'])); }
                    elseif ($days === 0){ $cdClass = 'soon';  $cdTxt = 'Today!'; }
                    elseif ($days <= 7) { $cdClass = 'soon';  $cdTxt = "In {$days}d"; }
                    else               { $cdClass = 'days';  $cdTxt = "In {$days}d"; }

                    if ($isPast)    { $ctaC = 'ended';  $ctaTxt = '🏁 Ended';        $ctaHref = ''; }
                    elseif (!$hasTk){ $ctaC = 'soon-t'; $ctaTxt = '⏳ Soon';          $ctaHref = ''; }
                    elseif ($isFree){ $ctaC = 'free-t'; $ctaTxt = 'Reserve Free';     $ctaHref = "/ticket/{$ev['id']}/"; }
                    else            { $ctaC = 'buy';    $ctaTxt = 'Buy · KES ' . number_format($ev['min_price']) . '+'; $ctaHref = "/ticket/{$ev['id']}/"; }

                    if ($isFree)    { $priceLabel = 'FREE'; }
                    elseif ($hasTk) { $priceLabel = 'KES ' . number_format($ev['min_price']); }
                    else            { $priceLabel = ''; }

                    $pastCardCls = $isPast ? ' kc-ev-past' : '';
                    $freeClass   = $isFree ? ' free' : '';
                    $banner      = htmlspecialchars('msanii/uploads/banners/' . ($ev['banner'] ?: 'default-event.jpg'));
                    $profPic     = htmlspecialchars('msanii/uploads/' . ($ev['profile_pic'] ?: 'default.png'));
                    $evTitle     = htmlspecialchars($ev['title']);
                    $evVenue     = htmlspecialchars($ev['venue'] ?? 'TBD');
                    $evUsername  = htmlspecialchars($ev['username'] ?? 'Unknown');
                    $evDate      = date('D, d M Y', strtotime($ev['start_date']));
                    $evTime      = date('g:i A',    strtotime($ev['start_time'] ?? '00:00'));
                ?>
                <div class="kc-grid-item<?= $hiddenCls ?>"
                     data-title="<?= strtolower($evTitle) ?>"
                     data-venue="<?= strtolower($evVenue) ?>"
                     data-host="<?= strtolower($evUsername) ?>"
                     data-type="<?= $evType ?>"
                     data-month="<?= $evMonth ?>">
                    <a href="/ticket/<?= $ev['id'] ?>/" class="kc-ev-card<?= $pastCardCls ?>">
                    <div a href="/ticket/<?= $ev['id'] ?>/" class="kc-ev-card<?= $pastCardCls ?>">
                        <div class="kc-ev-img">
                            <img src="<?= $banner ?>" alt="<?= $evTitle ?>" loading="lazy"
                                 onerror="this.src='https://placehold.co/480x270/052e64/fec411?text=Event'"/>
                            <div class="kc-ev-img-grad"></div>
                            <div class="kc-ev-badge <?= $badge ?>"><?= $badgeTxt ?></div>
                            <div class="kc-ev-cd <?= $cdClass ?>"><?= $cdTxt ?></div>
                            <?php if ($priceLabel): ?>
                            <div class="kc-ev-price<?= $freeClass ?>"><?= $priceLabel ?></div>
                            <?php endif; ?>
                           <?php if ($evViews > 0 && cts_show_views($ev)): ?>
                             <div class="kc-ev-views"><i class="fas fa-eye"></i> <?= fmtViews($evViews) ?></div>
                           <?php endif; ?>
                        </div>
                        
                        
                        <div class="kc-ev-body">
                            <div class="kc-ev-host">
                                <img src="<?= $profPic ?>" alt=""
                                     onerror="this.src='https://placehold.co/22x22/052e64/fec411?text=K'"/>
                                <span><strong><?= $evUsername ?></strong></span>
                            </div>
                            <h3 class="kc-ev-title"><?= $evTitle ?></h3>
                        </div>
                    </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div><!-- /grid-all-events -->

            <?php if (count($allEvents) > 8): ?>
            <div class="kc-load-more-wrap">
                <button class="kc-load-more-btn" id="lm-all-events"
                        onclick="kcLoadMore('grid-all-events','lm-all-events')">
                    <i class="fas fa-chevron-down"></i> Load More Events
                </button>
            </div>
            <?php endif; ?>

        </div><!-- /tab-all-events -->


        <!-- ══════════════════════════════════════════
             TAB 2 — PAST EVENTS
        ══════════════════════════════════════════ -->
        <div class="kc-tab-panel" id="tab-past-events" role="tabpanel">

            <div class="kc-tab-filter-row">
                <p style="font-size:14px;color:var(--g400);margin:0;">
                    <i class="fas fa-history" style="color:var(--navy);margin-right:6px;"></i>
                    Relive the highlights — browse all past Keychele events.
                </p>
                <div class="kc-tab-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" id="evSearchPast" placeholder="Search past events…" autocomplete="off">
                </div>
            </div>

            <?php if (!empty($pastEventMonths)): ?>
            <div class="kc-month-pills-row" id="kcMonthPillsPast">
                <button class="kc-month-pill active" data-month="">All Months</button>
                <?php foreach ($pastEventMonths as $mk => $ml): ?>
                <button class="kc-month-pill" data-month="<?= $mk ?>"><?= htmlspecialchars($ml) ?></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="kc-hub-grid" id="grid-past-events">
            <?php if (empty($pastEvents)): ?>
                <div class="kc-no-results" style="grid-column:1/-1;">
                    <div class="kc-no-icon"><i class="fas fa-history"></i></div>
                    <h4>No Past Events Yet</h4>
                    <p>Completed events will appear here.</p>
                </div>
            <?php else: ?>
                <?php
                $idx = 0;
                foreach ($pastEvents as $ev):
                    $idx++;
                    $hiddenCls  = $idx > 8 ? ' kc-item-hidden' : '';
                    $isFree     = ($ev['ticket_count'] > 0 && (int)$ev['min_price'] === 0);
                    $hasTk      = $ev['ticket_count'] > 0;
                    $freeClass  = $isFree ? ' free' : '';
                    $evMonth    = date('Y-m', strtotime($ev['start_date']));
                    $evViews    = (int)($ev['views'] ?? 0);

                    if ($isFree)    { $priceLabel = 'FREE'; }
                    elseif ($hasTk) { $priceLabel = 'KES ' . number_format($ev['min_price']); }
                    else            { $priceLabel = ''; }

                    $banner     = htmlspecialchars('msanii/uploads/banners/' . ($ev['banner'] ?: 'default-event.jpg'));
                    $profPic    = htmlspecialchars('msanii/uploads/' . ($ev['profile_pic'] ?: 'default.png'));
                    $evTitle    = htmlspecialchars($ev['title']);
                    $evVenue    = htmlspecialchars($ev['venue'] ?? 'TBD');
                    $evUsername = htmlspecialchars($ev['username'] ?? 'Unknown');
                    $evDate     = date('D, d M Y', strtotime($ev['start_date']));
                    $evTime     = date('g:i A',    strtotime($ev['start_time'] ?? '00:00'));
                    $cdTxt      = date('d M Y',    strtotime($ev['start_date']));
                ?>
                <div class="kc-grid-item<?= $hiddenCls ?>"
                     data-title="<?= strtolower($evTitle) ?>"
                     data-venue="<?= strtolower($evVenue) ?>"
                     data-host="<?= strtolower($evUsername) ?>"
                     data-month="<?= $evMonth ?>">
                    <div class="kc-ev-card kc-ev-past">
                        <div class="kc-ev-img">
                            <img src="<?= $banner ?>" alt="<?= $evTitle ?>" loading="lazy"
                                 onerror="this.src='https://placehold.co/480x270/052e64/fec411?text=Event'"/>
                            <div class="kc-ev-img-grad"></div>
                            <div class="kc-ev-badge past">✓ Past Event</div>
                            <div class="kc-ev-cd ended"><?= $cdTxt ?></div>
                            <?php if ($priceLabel): ?>
                            <div class="kc-ev-price<?= $freeClass ?>" style="opacity:.75;"><?= $priceLabel ?></div>
                            <?php endif; ?>
                            <?php if ($evViews > 0 && cts_show_views($ev)): ?>
                               <div class="kc-ev-views"><i class="fas fa-eye"></i> <?= fmtViews($evViews) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="kc-ev-body">
                            <h3 class="kc-ev-title"><?= $evTitle ?></h3>
                            <div class="kc-ev-host">
                                <img src="<?= $profPic ?>" alt=""
                                     onerror="this.src='https://placehold.co/22x22/052e64/fec411?text=K'"/>
                                <span>by <strong>@<?= $evUsername ?></strong></span>
                            </div>
                            <div class="kc-ev-meta">
                                <div class="kc-ev-meta-row">
                                    <i class="fas fa-calendar-check"></i>
                                    <?= $evDate ?>
                                </div>
                                <div class="kc-ev-meta-row">
                                    <i class="fas fa-clock"></i>
                                    <?= $evTime ?>
                                </div>
                                <div class="kc-ev-meta-row">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?= $evVenue ?></span>
                                </div>
                            </div>
                            <div class="kc-ev-sep"></div>
                            <div class="kc-ev-cta ended">🏁 Event Ended</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div><!-- /grid-past-events -->

            <?php if (count($pastEvents) > 8): ?>
            <div class="kc-load-more-wrap">
                <button class="kc-load-more-btn" id="lm-past-events"
                        onclick="kcLoadMore('grid-past-events','lm-past-events')">
                    <i class="fas fa-chevron-down"></i> Load More Past Events
                </button>
            </div>
            <?php endif; ?>

        </div><!-- /tab-past-events -->


        <!-- ══════════════════════════════════════════
             TAB 3 — CREATORS
        ══════════════════════════════════════════ -->
        <div class="kc-tab-panel" id="tab-creators" role="tabpanel">

            <div class="kc-tab-filter-row">
                <p style="font-size:14px;color:var(--g400);margin:0;">
                    <i class="fas fa-users" style="color:var(--navy);margin-right:6px;"></i>
                    Discover talented creators on the Keychele platform.
                </p>
                <div class="kc-tab-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" id="crSearch" placeholder="Search creators…" autocomplete="off">
                </div>
            </div>

            <div class="kc-hub-grid" id="grid-creators">
            <?php if (empty($creators)): ?>
                <div class="kc-no-results" style="grid-column:1/-1;">
                    <div class="kc-no-icon"><i class="fas fa-user-plus"></i></div>
                    <h4>No Creators Yet</h4>
                    <p>Be the first to join Keychele!</p>
                </div>
            <?php else: ?>
                <?php
                $idx = 0;
                foreach ($creators as $cr):
                    $idx++;
                    $hiddenCls = $idx > 8 ? ' kc-item-hidden' : '';
                    $av        = htmlspecialchars('msanii/uploads/' . ($cr['profile_pic'] ?: 'default.png'));
                    $name      = htmlspecialchars($cr['full_name']);
                    $init      = strtoupper(substr($cr['full_name'], 0, 1));
                    $uname     = htmlspecialchars($cr['username'] ?? '');
                    $evC       = (int)($cr['event_count']  ?? 0);
                    $tkC       = (int)($cr['ticket_count'] ?? 0);
                    $sinceYr   = !empty($cr['created_at']) ? date('Y', strtotime($cr['created_at'])) : '—';
                ?>
                <div class="kc-grid-item<?= $hiddenCls ?>"
                     data-name="<?= strtolower($name) ?>"
                     data-username="<?= strtolower($uname) ?>">
                    <div class="kc-cr-card">
                        <div class="kc-cr-banner"></div>
                        <div class="kc-cr-av-wrap">
                            <img src="<?= $av ?>"
                                 class="kc-cr-av"
                                 alt="<?= $name ?>"
                                 loading="lazy"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
                            <div class="kc-cr-av-fb" style="display:none;"><?= $init ?></div>
                        </div>
                        <div class="kc-cr-body">
                            <div class="kc-cr-name"><?= $name ?></div>
                            <div class="kc-cr-uname">@<?= $uname ?></div>
                            <div class="kc-cr-stats">
                                <div class="kc-cr-stat">
                                    <div class="kc-cr-stat-n"><?= $evC ?></div>
                                    <div class="kc-cr-stat-l">Events</div>
                                </div>
                                <div class="kc-cr-stat">
                                    <div class="kc-cr-stat-n"><?= $tkC ?></div>
                                    <div class="kc-cr-stat-l">Tickets</div>
                                </div>
                                <div class="kc-cr-stat">
                                    <div class="kc-cr-stat-n"><?= $sinceYr ?></div>
                                    <div class="kc-cr-stat-l">Since</div>
                                </div>
                            </div>
                            <a href="/<?= $uname ?>" class="kc-cr-cta">
                                <i class="fas fa-user"></i> View Profile
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div><!-- /grid-creators -->

            <?php if (count($creators) > 8): ?>
            <div class="kc-load-more-wrap">
                <button class="kc-load-more-btn" id="lm-creators"
                        onclick="kcLoadMore('grid-creators','lm-creators')">
                    <i class="fas fa-chevron-down"></i> Load More Creators
                </button>
            </div>
            <?php endif; ?>

        </div><!-- /tab-creators -->


        <!-- ══════════════════════════════════════════
             TAB 4 — MUSIC
        ══════════════════════════════════════════ -->
        <div class="kc-tab-panel" id="tab-music" role="tabpanel">

            <div class="kc-tab-filter-row">
                <p style="font-size:14px;color:var(--g400);margin:0;">
                    <i class="fas fa-headphones" style="color:var(--navy);margin-right:6px;"></i>
                    Stream and purchase exclusive tracks from Kenyan artists.
                </p>
                <div class="kc-tab-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" id="muSearch" placeholder="Search tracks…" autocomplete="off">
                </div>
            </div>

            <div class="kc-hub-grid" id="grid-music">
            <?php if (empty($musicTracks)): ?>
                <div class="kc-no-results" style="grid-column:1/-1;">
                    <div class="kc-no-icon"><i class="fas fa-music"></i></div>
                    <h4>No Tracks Yet</h4>
                    <p>Music will appear here once creators start uploading.</p>
                </div>
            <?php else: ?>
                <?php
                $idx = 0;
                foreach ($musicTracks as $tr):
                    $idx++;
                    $hiddenCls = $idx > 8 ? ' kc-item-hidden' : '';

                    $coverSrc = !empty($tr['cover_art'])
                        ? 'https://keychele.co.ke/msanii/uploads/music/covers/' . htmlspecialchars(basename($tr['cover_art']))
                        : '';
                    $audioSrc  = 'https://keychele.co.ke/msanii/uploads/music/audio/' . htmlspecialchars(basename($tr['audio_file']));
                    $isNew     = !empty($tr['release_date']) && strtotime($tr['release_date']) >= strtotime('-30 days');
                    $isHot     = (int)$tr['play_count'] >= 100;
                    $genreIcon = getGenreIcon($tr['genre'] ?? '');

                    $trTitle     = htmlspecialchars($tr['title']);
                    $trArtist    = htmlspecialchars($tr['artist'] ?? '');
                    $trGenre     = htmlspecialchars($tr['genre'] ?? 'Music');
                    $trCreator   = htmlspecialchars($tr['creator_name'] ?? 'Artist');
                    $trAlbum     = htmlspecialchars($tr['album'] ?? '');
                    $trPrice     = number_format((float)$tr['price']);
                    $trPlayCount = number_format((int)$tr['play_count']);
                    $trDlCount   = number_format((int)$tr['download_count']);
                    $trRelease   = !empty($tr['release_date']) ? date('M Y', strtotime($tr['release_date'])) : '';
                    $trId        = (int)$tr['id'];
                    $trIsFree    = (bool)$tr['is_free'];

                    $albumHtml   = $trAlbum ? " &middot; <span style=\"font-style:italic;color:#94a3b8;\">{$trAlbum}</span>" : '';
                    $releaseHtml = $trRelease ? "<span><i class=\"fas fa-calendar\"></i> {$trRelease}</span>" : '';

                    $hotBadgeHtml = '';
                    if ($isHot)     { $hotBadgeHtml = '<div class="kc-mu-hot-badge"><i class="fas fa-fire"></i> Hot</div>'; }
                    elseif ($isNew) { $hotBadgeHtml = '<div class="kc-mu-new-badge"><i class="fas fa-star"></i> New</div>'; }

                    if ($trIsFree) {
                        $priceHtml = '<span class="kc-mu-free-tag"><i class="fas fa-unlock"></i> Free</span>';
                        $ctaLabel  = 'Stream';
                        $ctaIcon   = 'fa-play';
                    } else {
                        $priceHtml = "<span class=\"kc-mu-price\">KSh {$trPrice}</span>";
                        $ctaLabel  = 'Buy';
                        $ctaIcon   = 'fa-cart-shopping';
                    }

                    $coverHtml = $coverSrc
                        ? "<img src=\"{$coverSrc}\" alt=\"{$trTitle}\" loading=\"lazy\"
                                onerror=\"this.style.display='none';this.nextElementSibling.style.display='flex';\">
                           <div class=\"kc-mu-cover-ph\" style=\"display:none;\"><i class=\"fas {$genreIcon}\"></i></div>"
                        : "<div class=\"kc-mu-cover-ph\"><i class=\"fas {$genreIcon}\"></i></div>";
                ?>
                <div class="kc-grid-item<?= $hiddenCls ?>"
                     data-title="<?= strtolower($trTitle) ?>"
                     data-artist="<?= strtolower($trArtist) ?>">
                    <div class="kc-mu-card">
                        <div class="kc-mu-cover">
                            <?= $coverHtml ?>
                            <div class="kc-mu-badge">
                                <i class="fas <?= $genreIcon ?>"></i>
                                <?= $trGenre ?>
                            </div>
                            <?= $hotBadgeHtml ?>
                            <button class="kc-mu-play-btn"
                                    id="kc-playbtn-<?= $trId ?>"
                                    data-id="<?= $trId ?>"
                                    data-title="<?= $trTitle ?>"
                                    data-artist="<?= $trArtist ?>"
                                    data-src="<?= $audioSrc ?>"
                                    data-cover="<?= $coverSrc ?>"
                                    onclick="kcMuPlay(this); event.preventDefault();"
                                    aria-label="Play <?= $trTitle ?>">
                                <div class="kc-play-icon">
                                    <i class="fas fa-play" id="kc-muicon-<?= $trId ?>"></i>
                                </div>
                            </button>
                        </div>
                        <a href="music/<?= $trId ?>" class="kc-mu-body-link" style="text-decoration:none;color:inherit;display:block;">
                            <div class="kc-mu-body">
                                <div class="kc-mu-artist">
                                    <?= ($trArtist ?: $trCreator) ?><?= $albumHtml ?>
                                </div>
                                <div class="kc-mu-title"><?= $trTitle ?></div>
                                <div class="kc-mu-foot">
                                    <div><?= $priceHtml ?></div>
                                    <div class="kc-mu-cta">
                                        <?= $ctaLabel ?>
                                        <i class="fas <?= $ctaIcon ?>" style="font-size:9px;"></i>
                                    </div>
                                </div>
                                <div class="kc-mu-meta">
                                    <span><i class="fas fa-play"></i> <?= $trPlayCount ?></span>
                                    <span><i class="fas fa-download"></i> <?= $trDlCount ?></span>
                                    <?= $releaseHtml ?>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div><!-- /grid-music -->

            <?php if (count($musicTracks) > 8): ?>
            <div class="kc-load-more-wrap">
                <button class="kc-load-more-btn" id="lm-music"
                        onclick="kcLoadMore('grid-music','lm-music')">
                    <i class="fas fa-chevron-down"></i> Load More Tracks
                </button>
            </div>
            <?php endif; ?>

        </div><!-- /tab-music -->

    </div><!-- /container -->
    </section>
    <!-- /HUB -->


    <!-- ════════════════════════════════════════════════════════
         MINI AUDIO PLAYER (fixed bottom)
    ════════════════════════════════════════════════════════ -->
    <div class="kc-mini-player" id="kcMiniPlayer">
        <div class="kc-mp-cover" id="kcMpCover"><i class="fas fa-music"></i></div>
        <div class="kc-mp-info">
            <div class="kc-mp-title"  id="kcMpTitle">—</div>
            <div class="kc-mp-artist" id="kcMpArtist">—</div>
        </div>
        <div class="kc-mp-controls">
            <button class="kc-mp-ctrl" onclick="kcMuSeek(-10)" aria-label="Rewind 10s"><i class="fas fa-backward"></i></button>
            <button class="kc-mp-ctrl play-btn" id="kcMpPlayBtn" onclick="kcMuToggle()" aria-label="Play/Pause">
                <i class="fas fa-play" id="kcMpIcon"></i>
            </button>
            <button class="kc-mp-ctrl" onclick="kcMuSeek(10)" aria-label="Forward 10s"><i class="fas fa-forward"></i></button>
        </div>
        <div class="kc-mp-progress">
            <div class="kc-mp-bar-wrap" onclick="kcMuScrub(event)">
                <div class="kc-mp-bar"><div class="kc-mp-fill" id="kcMpFill"></div></div>
            </div>
            <div class="kc-mp-times">
                <span id="kcTimeCur">0:00</span>
                <span id="kcTimeTot">0:00</span>
            </div>
        </div>
        <button class="kc-mp-close" onclick="kcMuClose()" aria-label="Close player"><i class="fas fa-xmark"></i></button>
    </div>
    <audio id="kcAudio"></audio>


    </main>

    <?php include_once('includes/footer.php'); ?>

    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/jquery.sticky.js"></script>
    <script src="js/jquery.magnific-popup.min.js"></script>
    <script src="js/magnific-popup-options.js"></script>
    <script src="js/click-scroll.js"></script>
    <script src="js/custom.js"></script>

    <script>
    /* ════════════════════════════════════════════════
       1. HERO CAROUSEL — sync dots
    ════════════════════════════════════════════════ */
    (function () {
        var carousel = document.getElementById('kcHeroCarousel');
        if (!carousel) return;
        carousel.addEventListener('slid.bs.carousel', function (e) {
            document.querySelectorAll('.kc-hero-dot').forEach(function (d, i) {
                d.classList.toggle('active', i === e.to);
            });
        });
    })();

    function kcGotoSlide(idx) {
        var el = document.getElementById('kcHeroCarousel');
        if (!el) return;
        bootstrap.Carousel.getOrCreateInstance(el).to(idx);
    }


    /* ════════════════════════════════════════════════
       2. TAB SWITCHING
    ════════════════════════════════════════════════ */
    document.querySelectorAll('.kc-tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.kc-tab-btn').forEach(function (b) {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            document.querySelectorAll('.kc-tab-panel').forEach(function (p) {
                p.classList.remove('active');
            });
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            var panel = document.getElementById('tab-' + this.dataset.tab);
            if (panel) {
                panel.classList.add('active');
                panel.querySelectorAll('.kc-grid-item:not(.kc-item-hidden):not(.kc-in-view)').forEach(function (el) {
                    kcObserver.observe(el);
                });
            }
        });
    });


    /* ════════════════════════════════════════════════
       3. LAZY LOADING — IntersectionObserver
    ════════════════════════════════════════════════ */
    var kcObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('kc-in-view');
                kcObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.06, rootMargin: '0px 0px -10px 0px' });

    document.querySelectorAll('.kc-grid-item:not(.kc-item-hidden)').forEach(function (el) {
        kcObserver.observe(el);
    });


    /* ════════════════════════════════════════════════
       4. LOAD MORE
    ════════════════════════════════════════════════ */
    function kcLoadMore(gridId, btnId) {
        var grid = document.getElementById(gridId);
        var btn  = document.getElementById(btnId);
        if (!grid) return;
        Array.from(grid.querySelectorAll('.kc-item-hidden')).slice(0, 8).forEach(function (el) {
            el.classList.remove('kc-item-hidden');
            kcObserver.observe(el);
        });
        if (!grid.querySelectorAll('.kc-item-hidden').length && btn) {
            btn.classList.add('hidden');
        }
    }


    /* ════════════════════════════════════════════════
       5. ALL EVENTS — search + type + month filter
    ════════════════════════════════════════════════ */
    var evFilterAll = 'all';
    var evMonthAll  = '';

    function applyAllEventsFilter() {
        var q = (document.getElementById('evSearchAll').value || '').toLowerCase().trim();
        document.querySelectorAll('#grid-all-events .kc-grid-item').forEach(function (item) {
            var matchType = true, matchMonth = true, matchSearch = true;

            if (evFilterAll !== 'all') {
                if (evFilterAll === 'free') {
                    var priceEl = item.querySelector('.kc-ev-price');
                    matchType = priceEl && priceEl.textContent.trim() === 'FREE';
                } else {
                    matchType = (item.dataset.type === evFilterAll);
                }
            }
            if (evMonthAll) {
                matchMonth = (item.dataset.month || '').startsWith(evMonthAll);
            }
            if (q) {
                matchSearch = ['title','venue','host'].some(function (f) {
                    return (item.dataset[f] || '').includes(q);
                });
            }

            var show = matchType && matchMonth && matchSearch;
            item.style.display = show ? '' : 'none';
            if (show && !item.classList.contains('kc-in-view') && !item.classList.contains('kc-item-hidden')) {
                kcObserver.observe(item);
            }
        });
    }

    document.querySelectorAll('.kc-filter-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('.kc-filter-pill').forEach(function (p) { p.classList.remove('active'); });
            this.classList.add('active');
            evFilterAll = this.dataset.filter;
            applyAllEventsFilter();
        });
    });

    document.querySelectorAll('#kcMonthPillsAll .kc-month-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('#kcMonthPillsAll .kc-month-pill').forEach(function (p) { p.classList.remove('active'); });
            this.classList.add('active');
            evMonthAll = this.dataset.month;
            applyAllEventsFilter();
        });
    });

    document.getElementById('evSearchAll') &&
        document.getElementById('evSearchAll').addEventListener('input', applyAllEventsFilter);


    /* ════════════════════════════════════════════════
       6. PAST EVENTS — search + month filter
    ════════════════════════════════════════════════ */
    var evMonthPast = '';

    function applyPastEventsFilter() {
        var q = (document.getElementById('evSearchPast').value || '').toLowerCase().trim();
        document.querySelectorAll('#grid-past-events .kc-grid-item').forEach(function (item) {
            var matchMonth = true, matchSearch = true;
            if (evMonthPast) {
                matchMonth = (item.dataset.month || '').startsWith(evMonthPast);
            }
            if (q) {
                matchSearch = ['title','venue','host'].some(function (f) {
                    return (item.dataset[f] || '').includes(q);
                });
            }
            var show = matchMonth && matchSearch;
            item.style.display = show ? '' : 'none';
            if (show && !item.classList.contains('kc-in-view') && !item.classList.contains('kc-item-hidden')) {
                kcObserver.observe(item);
            }
        });
    }

    document.querySelectorAll('#kcMonthPillsPast .kc-month-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('#kcMonthPillsPast .kc-month-pill').forEach(function (p) { p.classList.remove('active'); });
            this.classList.add('active');
            evMonthPast = this.dataset.month;
            applyPastEventsFilter();
        });
    });

    document.getElementById('evSearchPast') &&
        document.getElementById('evSearchPast').addEventListener('input', applyPastEventsFilter);


    /* ════════════════════════════════════════════════
       7. CREATORS SEARCH
    ════════════════════════════════════════════════ */
    document.getElementById('crSearch') &&
        document.getElementById('crSearch').addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('#grid-creators .kc-grid-item').forEach(function (item) {
                var show = !q || ['name','username'].some(function (f) { return (item.dataset[f] || '').includes(q); });
                item.style.display = show ? '' : 'none';
                if (show && !item.classList.contains('kc-in-view')) kcObserver.observe(item);
            });
        });


    /* ════════════════════════════════════════════════
       8. MUSIC SEARCH
    ════════════════════════════════════════════════ */
    document.getElementById('muSearch') &&
        document.getElementById('muSearch').addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('#grid-music .kc-grid-item').forEach(function (item) {
                var show = !q || ['title','artist'].some(function (f) { return (item.dataset[f] || '').includes(q); });
                item.style.display = show ? '' : 'none';
                if (show && !item.classList.contains('kc-in-view')) kcObserver.observe(item);
            });
        });


    /* ════════════════════════════════════════════════
       9. MINI AUDIO PLAYER
    ════════════════════════════════════════════════ */
    (function () {
        var audio    = document.getElementById('kcAudio');
        var player   = document.getElementById('kcMiniPlayer');
        var fill     = document.getElementById('kcMpFill');
        var mpIcon   = document.getElementById('kcMpIcon');
        var timeCur  = document.getElementById('kcTimeCur');
        var timeTot  = document.getElementById('kcTimeTot');
        var activeId = null;

        function fmt(s) {
            s = Math.floor(s) || 0;
            return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        }

        window.kcMuPlay = function (btn) {
            var id     = btn.dataset.id;
            var src    = btn.dataset.src;
            var title  = btn.dataset.title;
            var artist = btn.dataset.artist;
            var cover  = btn.dataset.cover;

            if (activeId === id) { kcMuToggle(); return; }

            if (activeId) {
                var prevIcon = document.getElementById('kc-muicon-' + activeId);
                if (prevIcon) prevIcon.className = 'fas fa-play';
                var prevBtn = document.getElementById('kc-playbtn-' + activeId);
                if (prevBtn) prevBtn.classList.remove('playing');
            }

            activeId  = id;
            audio.src = src;
            audio.play().catch(function () {});

            var thisIcon = document.getElementById('kc-muicon-' + id);
            if (thisIcon) thisIcon.className = 'fas fa-pause';
            btn.classList.add('playing');
            mpIcon.className = 'fas fa-pause';

            document.getElementById('kcMpTitle').textContent  = title;
            document.getElementById('kcMpArtist').textContent = artist;

            var coverEl = document.getElementById('kcMpCover');
            coverEl.innerHTML = cover
                ? '<img src="' + cover + '" alt="cover">'
                : '<i class="fas fa-music"></i>';

            player.classList.add('show');
        };

        window.kcMuToggle = function () {
            if (!audio.src) return;
            if (audio.paused) {
                audio.play();
                mpIcon.className = 'fas fa-pause';
                if (activeId) {
                    var ic = document.getElementById('kc-muicon-' + activeId);
                    if (ic) ic.className = 'fas fa-pause';
                }
            } else {
                audio.pause();
                mpIcon.className = 'fas fa-play';
                if (activeId) {
                    var ic = document.getElementById('kc-muicon-' + activeId);
                    if (ic) ic.className = 'fas fa-play';
                }
            }
        };

        window.kcMuSeek = function (s) {
            audio.currentTime = Math.max(0, Math.min(audio.duration || 0, audio.currentTime + s));
        };

        window.kcMuScrub = function (e) {
            var rect = e.currentTarget.getBoundingClientRect();
            audio.currentTime = ((e.clientX - rect.left) / rect.width) * (audio.duration || 0);
        };

        window.kcMuClose = function () {
            audio.pause();
            player.classList.remove('show');
            if (activeId) {
                var ic = document.getElementById('kc-muicon-' + activeId);
                if (ic) ic.className = 'fas fa-play';
                var btn = document.getElementById('kc-playbtn-' + activeId);
                if (btn) btn.classList.remove('playing');
            }
            activeId = null;
            audio.src = '';
            fill.style.width = '0%';
            timeCur.textContent = '0:00';
            timeTot.textContent = '0:00';
        };

        audio.addEventListener('timeupdate', function () {
            if (!audio.duration) return;
            fill.style.width = (audio.currentTime / audio.duration * 100) + '%';
            timeCur.textContent = fmt(audio.currentTime);
        });

        audio.addEventListener('loadedmetadata', function () {
            timeTot.textContent = fmt(audio.duration);
        });

        audio.addEventListener('ended', function () {
            mpIcon.className = 'fas fa-play';
            if (activeId) {
                var ic = document.getElementById('kc-muicon-' + activeId);
                if (ic) ic.className = 'fas fa-play';
                var btn = document.getElementById('kc-playbtn-' + activeId);
                if (btn) btn.classList.remove('playing');
            }
            fill.style.width = '0%';
        });
    })();
    </script>

</body>
</html>