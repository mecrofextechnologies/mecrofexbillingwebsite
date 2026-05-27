<?php
include 'msanii/includes/connect.php';


// ─── Pagination ───────────────────────────────────────────────
$perPage = 12;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$filter  = $_GET['filter'] ?? 'all';  // all | upcoming | past

// ─── Build WHERE clause based on filter ──────────────────────
switch ($filter) {
    case 'upcoming':
        $where = "(t.start_date > CURDATE() OR (t.start_date = CURDATE() AND t.start_time > CURTIME()))";
        break;
    case 'past':
        $where = "(t.start_date < CURDATE() OR (t.start_date = CURDATE() AND t.start_time <= CURTIME()))";
        break;
    default:
        $where = "1=1";
}

// Total count
$countStmt = $pdo->query("
    SELECT COUNT(DISTINCT t.id)
    FROM tickets t
    JOIN creators c ON t.creator_id = c.id
    WHERE $where
");
$totalEvents = (int)$countStmt->fetchColumn();
$totalPages  = max(1, ceil($totalEvents / $perPage));

// Counts for filter tabs
$upcomingCount = (int)$pdo->query("SELECT COUNT(DISTINCT t.id) FROM tickets t JOIN creators c ON t.creator_id=c.id WHERE (t.start_date > CURDATE() OR (t.start_date = CURDATE() AND t.start_time > CURTIME()))")->fetchColumn();
$pastCount     = (int)$pdo->query("SELECT COUNT(DISTINCT t.id) FROM tickets t JOIN creators c ON t.creator_id=c.id WHERE (t.start_date < CURDATE() OR (t.start_date = CURDATE() AND t.start_time <= CURTIME()))")->fetchColumn();
$allCount      = $upcomingCount + $pastCount;

// Fetch events
$stmt = $pdo->prepare("
    SELECT
        t.id, t.title, t.venue, t.start_date, t.start_time, t.banner, t.creator_id,
        c.username, mp.profile_pic,
        MIN(ett.price) AS min_price,
        COUNT(ett.id)  AS ticket_types_count
    FROM tickets t
    JOIN creators c ON t.creator_id = c.id
    LEFT JOIN my_page mp ON c.id = mp.creator_id
    LEFT JOIN event_ticket_types ett ON t.id = ett.event_id AND ett.is_active = 1
    WHERE $where
    GROUP BY t.id
    ORDER BY t.start_date " . ($filter === 'past' ? 'DESC' : 'ASC') . ", t.start_time ASC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset,  PDO::PARAM_INT);
$stmt->execute();
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper: days until event
function daysUntil($dateStr) {
    $today = new DateTime('today');
    $event = new DateTime($dateStr);
    $diff  = $today->diff($event);
    return $diff->invert ? -$diff->days : $diff->days;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<meta name="description" content="Discover and buy tickets for upcoming events on Keychele"/>
<title>Events | Keychele</title>

<!-- Existing site CSS -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400&display=swap" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="css/bootstrap-icons.css" rel="stylesheet">
<link href="css/magnific-popup.css" rel="stylesheet">
<link href="css/tooplate-waso-strategy.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
/* ══════════════════════════════════════════════════
   BRAND TOKENS  –  Navy · Gold · Crimson
══════════════════════════════════════════════════ */
:root {
    --navy:      #052e64;
    --navy-d:    #031e44;
    --navy-m:    #0a4a99;
    --navy-l:    #e8eef8;
    --navy-xl:   #f2f5fc;
    --gold:      #fec411;
    --gold-d:    #d9a70a;
    --gold-l:    #fffbea;
    --crimson:   #bf1339;
    --crimson-d: #96102d;
    --crimson-l: #fce8ec;
    --white:     #ffffff;
    --g50:       #f8f9fd;
    --g100:      #eef1f9;
    --g200:      #dce2f0;
    --g400:      #8a97b8;
    --g600:      #4a5578;
    --green:     #16a34a;
    --green-l:   #dcfce7;

    --fh: 'Montserrat', system-ui, sans-serif;
    --fb: 'Glacial Indifference', 'Montserrat', system-ui, sans-serif;
}

/* ── RESET ── */
*, *::before, *::after { box-sizing: border-box; }
img { display: block; max-width: 100%; }

/* Glacial Indifference fallback declaration */
@font-face {
    font-family: 'Glacial Indifference';
    src: local('Glacial Indifference');
    font-weight: normal;
}

body { font-family: var(--fb); -webkit-font-smoothing: antialiased; background: var(--g50); }

/* ══════════════════════════════════════════════════
   HERO
══════════════════════════════════════════════════ */
.ev-hero {
    position: relative;
    background: var(--navy-d);
    padding: 90px 0 70px;
    overflow: hidden;
}
.ev-hero::before {
    content: '';
    position: absolute; inset: 0;
    background:
        radial-gradient(ellipse 80% 60% at 20% 50%, rgba(254,196,17,.12) 0%, transparent 70%),
        radial-gradient(ellipse 60% 80% at 80% 30%, rgba(191,19,57,.10) 0%, transparent 70%);
}
.ev-hero-grid {
    position: absolute; inset: 0; overflow: hidden; opacity: .04;
    background-image:
        linear-gradient(var(--gold) 1px, transparent 1px),
        linear-gradient(90deg, var(--gold) 1px, transparent 1px);
    background-size: 48px 48px;
}
.ev-hero-inner { position: relative; z-index: 2; text-align: center; }
.ev-hero-eyebrow {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(254,196,17,.12);
    border: 1px solid rgba(254,196,17,.3);
    color: var(--gold);
    font-family: var(--fh);
    font-size: 11px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase;
    padding: 7px 18px; border-radius: 30px;
    margin-bottom: 20px;
}
.ev-hero h1 {
    font-family: var(--fh);
    font-size: clamp(32px, 6vw, 64px);
    font-weight: 900; color: var(--white);
    line-height: 1.1; margin-bottom: 16px;
}
.ev-hero h1 em { font-style: normal; color: var(--gold); }
.ev-hero p {
    font-size: clamp(15px, 2vw, 18px);
    color: rgba(255,255,255,.55);
    max-width: 560px; margin: 0 auto 36px; line-height: 1.7;
}
.hero-stat-row {
    display: flex; justify-content: center; gap: 40px; flex-wrap: wrap;
    margin-top: 10px;
}
.hero-stat {
    text-align: center;
}
.hero-stat-num {
    font-family: var(--fh);
    font-size: 32px; font-weight: 900; color: var(--gold);
    line-height: 1;
}
.hero-stat-lbl {
    font-size: 11px; font-weight: 600; letter-spacing: 2px;
    text-transform: uppercase; color: rgba(255,255,255,.4);
    margin-top: 4px;
}
.hero-divider {
    width: 1px; height: 48px;
    background: rgba(255,255,255,.1);
    align-self: center;
}

/* Gold underline bar */
.hero-gold-bar {
    width: 60px; height: 4px; border-radius: 2px;
    background: var(--gold); margin: 0 auto 24px;
}

/* ══════════════════════════════════════════════════
   SEARCH + FILTERS BAR
══════════════════════════════════════════════════ */
.controls-bar {
    background: var(--white);
    border-bottom: 2px solid var(--g100);
    padding: 20px 0;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 4px 24px rgba(5,46,100,.08);
}
.controls-inner {
    display: flex; align-items: center;
    gap: 16px; flex-wrap: wrap;
}

/* Filter tabs */
.filter-tabs {
    display: flex; gap: 6px; flex-wrap: wrap;
}
.filter-tab {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px;
    border-radius: 30px;
    border: 1.5px solid var(--g200);
    background: transparent;
    font-family: var(--fh);
    font-size: 12px; font-weight: 700;
    letter-spacing: .5px; text-transform: uppercase;
    color: var(--g600);
    cursor: pointer; text-decoration: none;
    transition: all .2s;
}
.filter-tab:hover {
    border-color: var(--navy);
    color: var(--navy);
    background: var(--navy-l);
}
.filter-tab.active {
    background: var(--navy);
    border-color: var(--navy);
    color: var(--white);
}
.filter-tab .tab-count {
    background: rgba(255,255,255,.2);
    color: inherit;
    font-size: 10px; font-weight: 800;
    padding: 1px 7px; border-radius: 10px;
    min-width: 22px; text-align: center;
}
.filter-tab:not(.active) .tab-count {
    background: var(--g100);
    color: var(--g400);
}
.filter-tab.tab-upcoming.active { background: var(--navy); border-color: var(--navy); }
.filter-tab.tab-past.active     { background: var(--g600); border-color: var(--g600); }
.filter-tab.tab-free.active     { background: var(--green); border-color: var(--green); }

/* Search box */
.search-box {
    position: relative; flex: 1; min-width: 220px; max-width: 380px; margin-left: auto;
}
.search-box input {
    width: 100%;
    padding: 11px 48px 11px 18px;
    border: 1.5px solid var(--g200);
    border-radius: 30px;
    font-family: var(--fb);
    font-size: 14px; color: var(--navy);
    background: var(--g50);
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.search-box input:focus {
    border-color: var(--navy);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(5,46,100,.1);
}
.search-box input::placeholder { color: var(--g400); }
.search-box .search-ico {
    position: absolute; right: 14px; top: 50%;
    transform: translateY(-50%);
    color: var(--g400); font-size: 15px; pointer-events: none;
}

/* Results count */
.results-info {
    font-family: var(--fh);
    font-size: 12px; font-weight: 700;
    color: var(--g400); white-space: nowrap;
}
.results-info span { color: var(--navy); }

/* ══════════════════════════════════════════════════
   EVENTS GRID
══════════════════════════════════════════════════ */
.events-section { padding: 40px 0 80px; }

.events-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 24px;
}

/* ── EVENT CARD ── */
.ev-card {
    background: var(--white);
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(5,46,100,.08);
    border: 1.5px solid var(--g100);
    display: flex; flex-direction: column;
    transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    position: relative;
}
.ev-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 50px rgba(5,46,100,.18);
    border-color: rgba(5,46,100,.15);
}
.ev-card.is-past {
    opacity: .82;
}
.ev-card.is-past:hover { opacity: 1; }

/* Image area */
.ev-card-img-wrap {
    position: relative;
    aspect-ratio: 16/9;
    overflow: hidden;
    background: var(--g100);
    flex-shrink: 0;
}
.ev-card-img-wrap img {
    width: 100%; height: 100%;
    object-fit: cover;
    transition: transform .5s ease;
}
.ev-card:hover .ev-card-img-wrap img { transform: scale(1.07); }

/* Image gradient */
.ev-card-img-grad {
    position: absolute; inset: 0;
    background: linear-gradient(to top,
        rgba(5,46,100,.65) 0%,
        transparent 55%);
}

/* Status badge (top-left) */
.ev-status {
    position: absolute; top: 12px; left: 12px;
    font-family: var(--fh);
    font-size: 10px; font-weight: 800;
    letter-spacing: 1.5px; text-transform: uppercase;
    padding: 5px 12px; border-radius: 20px;
    display: flex; align-items: center; gap: 5px;
    backdrop-filter: blur(6px);
}
.ev-status.upcoming {
    background: rgba(5,46,100,.85);
    color: var(--gold);
    border: 1px solid rgba(254,196,17,.4);
}
.ev-status.happening {
    background: rgba(22,163,74,.9);
    color: #fff;
    animation: pulse-green 2s infinite;
}
.ev-status.past {
    background: rgba(70,75,95,.8);
    color: rgba(255,255,255,.7);
}
@keyframes pulse-green {
    0%,100% { box-shadow: 0 0 0 0 rgba(22,163,74,.5); }
    50%      { box-shadow: 0 0 0 8px rgba(22,163,74,0); }
}
.ev-status .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: currentColor;
}

/* Countdown badge (top-right) */
.ev-countdown {
    position: absolute; top: 12px; right: 12px;
    font-family: var(--fh);
    font-size: 10px; font-weight: 800; letter-spacing: .5px;
    padding: 5px 11px; border-radius: 20px;
    backdrop-filter: blur(6px);
}
.ev-countdown.soon {
    background: rgba(191,19,57,.85); color: #fff;
}
.ev-countdown.days {
    background: rgba(254,196,17,.9); color: var(--navy);
}
.ev-countdown.past-lbl {
    background: rgba(50,50,65,.75); color: rgba(255,255,255,.6);
}

/* Price tag (bottom-left of image) */
.ev-price-tag {
    position: absolute; bottom: 10px; left: 12px;
    font-family: var(--fh);
    font-size: 14px; font-weight: 900;
    color: #fff;
    text-shadow: 0 1px 4px rgba(0,0,0,.5);
}
.ev-price-tag.free { color: #6ee7a4; }

/* Card body */
.ev-card-body {
    padding: 20px 20px 18px;
    flex: 1; display: flex; flex-direction: column; gap: 0;
}
.ev-card-title {
    font-family: var(--fh);
    font-size: 16px; font-weight: 800;
    color: var(--navy); line-height: 1.3;
    margin-bottom: 10px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Organizer row */
.ev-organizer {
    display: flex; align-items: center; gap: 9px;
    margin-bottom: 13px;
}
.ev-org-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--navy-l);
    flex-shrink: 0;
}
.ev-org-name {
    font-size: 12px; color: var(--g400); font-weight: 500;
}
.ev-org-name strong { color: var(--navy); font-weight: 700; }

/* Meta rows */
.ev-meta {
    display: flex; flex-direction: column; gap: 6px;
    margin-bottom: 16px;
}
.ev-meta-row {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; color: var(--g600);
}
.ev-meta-row i { color: var(--navy); font-size: 12px; width: 14px; flex-shrink: 0; }
.ev-meta-row .trunc {
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* Separator */
.ev-sep {
    height: 1px; background: var(--g100); margin: 4px 0 16px;
}

/* CTA button */
.ev-cta {
    display: flex; align-items: center; justify-content: center;
    gap: 8px; padding: 13px 20px;
    border-radius: 10px;
    font-family: var(--fh);
    font-size: 13px; font-weight: 800;
    text-decoration: none; letter-spacing: .3px;
    transition: all .2s; margin-top: auto;
    border: none; cursor: pointer;
}
.ev-cta.cta-buy {
    background: var(--crimson); color: #fff;
}
.ev-cta.cta-buy:hover {
    background: var(--crimson-d); color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(191,19,57,.35);
}
.ev-cta.cta-free {
    background: var(--green); color: #fff;
}
.ev-cta.cta-free:hover {
    background: #138a3e; color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(22,163,74,.35);
}
.ev-cta.cta-past {
    background: var(--g100); color: var(--g400);
    cursor: default;
}
.ev-cta.cta-none {
    background: var(--g100); color: var(--g400);
    cursor: default;
}

/* Ticket types badges */
.ev-types {
    display: flex; flex-wrap: wrap; gap: 5px;
    margin-bottom: 13px;
}
.ev-type-badge {
    font-family: var(--fh);
    font-size: 10px; font-weight: 700;
    padding: 3px 9px; border-radius: 12px;
    background: var(--navy-l); color: var(--navy);
}

/* ══════════════════════════════════════════════════
   NO EVENTS STATE
══════════════════════════════════════════════════ */
.no-ev {
    grid-column: 1/-1;
    text-align: center; padding: 80px 20px;
}
.no-ev-icon {
    width: 90px; height: 90px; border-radius: 50%;
    background: var(--navy-l);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 22px;
    font-size: 36px; color: var(--navy);
}
.no-ev h3 {
    font-family: var(--fh); font-weight: 800;
    font-size: 22px; color: var(--navy);
    margin-bottom: 10px;
}
.no-ev p { font-size: 15px; color: var(--g400); }

/* ══════════════════════════════════════════════════
   PAGINATION
══════════════════════════════════════════════════ */
.ev-pagination {
    display: flex; align-items: center; justify-content: center;
    gap: 6px; flex-wrap: wrap;
    margin-top: 52px;
}
.ev-page-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 40px; height: 40px; padding: 0 10px;
    border-radius: 10px;
    font-family: var(--fh); font-size: 13px; font-weight: 700;
    color: var(--navy); background: var(--white);
    border: 1.5px solid var(--g200);
    text-decoration: none; transition: all .2s;
}
.ev-page-btn:hover {
    background: var(--navy-l);
    border-color: var(--navy);
    color: var(--navy);
}
.ev-page-btn.active {
    background: var(--navy); border-color: var(--navy);
    color: var(--white);
    box-shadow: 0 4px 14px rgba(5,46,100,.3);
}
.ev-page-btn.disabled {
    opacity: .35; pointer-events: none;
}
.ev-page-ellipsis {
    font-size: 14px; color: var(--g400); padding: 0 4px;
}
.ev-page-info {
    font-family: var(--fh);
    font-size: 12px; color: var(--g400); font-weight: 600;
    text-align: center; margin-top: 14px;
}
.ev-page-info span { color: var(--navy); font-weight: 800; }

/* ══════════════════════════════════════════════════
   SECTION HEADING
══════════════════════════════════════════════════ */
.sec-heading {
    display: flex; align-items: baseline;
    gap: 12px; margin-bottom: 28px;
    flex-wrap: wrap;
}
.sec-heading h2 {
    font-family: var(--fh); font-weight: 900;
    font-size: clamp(20px, 3vw, 26px);
    color: var(--navy); margin: 0;
}
.sec-heading .count-pill {
    background: var(--navy-l);
    color: var(--navy);
    font-family: var(--fh);
    font-size: 11px; font-weight: 800;
    padding: 4px 12px; border-radius: 20px;
}

/* ══════════════════════════════════════════════════
   ANIMATION: stagger cards on load
══════════════════════════════════════════════════ */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(22px); }
    to   { opacity: 1; transform: translateY(0); }
}
.ev-card {
    animation: fadeUp .45s ease both;
}
.ev-card:nth-child(1)  { animation-delay: .04s; }
.ev-card:nth-child(2)  { animation-delay: .08s; }
.ev-card:nth-child(3)  { animation-delay: .12s; }
.ev-card:nth-child(4)  { animation-delay: .16s; }
.ev-card:nth-child(5)  { animation-delay: .20s; }
.ev-card:nth-child(6)  { animation-delay: .24s; }
.ev-card:nth-child(7)  { animation-delay: .28s; }
.ev-card:nth-child(8)  { animation-delay: .32s; }
.ev-card:nth-child(9)  { animation-delay: .36s; }
.ev-card:nth-child(10) { animation-delay: .40s; }
.ev-card:nth-child(11) { animation-delay: .44s; }
.ev-card:nth-child(12) { animation-delay: .48s; }

/* ══════════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════════ */
@media (max-width: 768px) {
    .controls-inner { flex-direction: column; align-items: stretch; }
    .search-box { margin-left: 0; max-width: 100%; }
    .results-info { display: none; }
    .events-grid { grid-template-columns: 1fr; }
    .hero-divider { display: none; }
}
@media (max-width: 480px) {
    .ev-hero { padding: 70px 0 50px; }
    .filter-tabs { gap: 4px; }
    .filter-tab { padding: 8px 12px; font-size: 11px; }
}
</style>
</head>

<body id="section_1">
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/nav.php'); ?>

<main>

<!-- ══════════════════════════════════════════════════
     HERO
══════════════════════════════════════════════════ -->
<section class="ev-hero">
    <div class="ev-hero-grid"></div>
    <div class="container">
        <div class="ev-hero-inner">
            <div class="ev-hero-eyebrow">
                <i class="fas fa-ticket-alt"></i>
                Keychele Events
            </div>
            <h1>Discover <em>Experiences</em><br>Worth Attending</h1>
            <div class="hero-gold-bar"></div>
            <p>Browse all creators on Keychele who are shaping the events culture in Kenya and beyond. .</p>

            <div class="hero-stat-row">
                <div class="hero-stat">
                    <div class="hero-stat-num"><?= $upcomingCount ?></div>
                    <div class="hero-stat-lbl">Upcoming</div>
                </div>
                <div class="hero-divider"></div>
                <div class="hero-stat">
                    <div class="hero-stat-num"><?= $allCount ?></div>
                    <div class="hero-stat-lbl">Total Events</div>
                </div>
                <div class="hero-divider"></div>
                <div class="hero-stat">
                    <div class="hero-stat-num"><?= $pastCount ?></div>
                    <div class="hero-stat-lbl">Past Events</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════
     STICKY CONTROLS BAR
══════════════════════════════════════════════════ -->
<div class="controls-bar">
    <div class="container">
        <div class="controls-inner">
            <!-- Filter tabs -->
            <div class="filter-tabs">
                <a href="?filter=all&page=1"
                   class="filter-tab <?= $filter==='all'?'active':'' ?>">
                    All Events
                    <span class="tab-count"><?= $allCount ?></span>
                </a>
                <a href="?filter=upcoming&page=1"
                   class="filter-tab tab-upcoming <?= $filter==='upcoming'?'active':'' ?>">
                    <i class="fas fa-calendar-alt" style="font-size:10px;"></i>
                    Upcoming
                    <span class="tab-count"><?= $upcomingCount ?></span>
                </a>
                <a href="?filter=past&page=1"
                   class="filter-tab tab-past <?= $filter==='past'?'active':'' ?>">
                    <i class="fas fa-history" style="font-size:10px;"></i>
                    Past
                    <span class="tab-count"><?= $pastCount ?></span>
                </a>
            </div>

            <!-- Results info -->
            <div class="results-info">
                Showing <span><?= count($events) ?></span> of <span><?= $totalEvents ?></span> events
            </div>

            <!-- Search -->
            <div class="search-box">
                <input type="search" id="evSearch"
                       placeholder="Search events, venues, hosts…"
                       autocomplete="off"/>
                <i class="fas fa-search search-ico"></i>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════
     EVENTS GRID
══════════════════════════════════════════════════ -->
<section class="events-section">
    <div class="container">
        <div class="sec-heading">
            <h2>
                <?php
                if ($filter === 'upcoming') echo 'Upcoming Events';
                elseif ($filter === 'past') echo 'Past Events';
                else echo 'All Events';
                ?>
            </h2>
            <span class="count-pill"><?= $totalEvents ?> total</span>
        </div>

        <div class="events-grid" id="eventsGrid">
            <?php if (!empty($events)): ?>
                <?php foreach ($events as $event):
                    // ── Status logic ──────────────────────────────
                    $eventDt  = strtotime($event['start_date'] . ' ' . ($event['start_time'] ?? '00:00:00'));
                    $now      = time();
                    $days     = daysUntil($event['start_date']);

                    // Is past?
                    $isPast = ($eventDt < $now);
                    // Is happening today?
                    $isToday = (date('Y-m-d', $eventDt) === date('Y-m-d'));
                    // Is happening right now (within +/-2hrs window)?
                    $isLive  = !$isPast && $isToday && abs($now - $eventDt) < 7200;

                    // Status badge
                    if ($isLive) {
                        $statusClass = 'happening';
                        $statusText  = '● LIVE NOW';
                    } elseif ($isPast) {
                        $statusClass = 'past';
                        $statusText  = 'Past Event';
                    } else {
                        $statusClass = 'upcoming';
                        $statusText  = 'Upcoming';
                    }

                    // Countdown badge
                    if ($isPast) {
                        $cdClass = 'past-lbl';
                        $cdText  = date('d M Y', $eventDt);
                    } elseif ($days === 0) {
                        $cdClass = 'soon';
                        $cdText  = 'Today!';
                    } elseif ($days <= 7) {
                        $cdClass = 'soon';
                        $cdText  = "In {$days}d";
                    } else {
                        $cdClass = 'days';
                        $cdText  = "In {$days}d";
                    }

                    // Price logic
                    $minPrice = $event['min_price'];
                    $hasTypes = $event['ticket_types_count'] > 0;
                    $isFree   = $hasTypes && ((int)$minPrice === 0);

                    if ($isPast) {
                        $priceText = 'Event Ended';
                        $ctaClass  = 'cta-past';
                        $ctaIcon   = 'fa-check-circle';
                        $priceTag  = '';
                    } elseif (!$hasTypes) {
                        $priceText = 'Coming Soon';
                        $ctaClass  = 'cta-none';
                        $ctaIcon   = 'fa-clock';
                        $priceTag  = '';
                    } elseif ($isFree) {
                        $priceText = 'Reserve Free Ticket';
                        $ctaClass  = 'cta-free';
                        $ctaIcon   = 'fa-ticket-alt';
                        $priceTag  = 'FREE';
                    } else {
                        $priceText = 'Buy Ticket · KES ' . number_format($minPrice) . '+';
                        $ctaClass  = 'cta-buy';
                        $ctaIcon   = 'fa-ticket-alt';
                        $priceTag  = 'From KES ' . number_format($minPrice);
                    }

                    $banner  = "msanii/uploads/banners/" . htmlspecialchars($event['banner'] ?: 'default-event.jpg');
                    $profPic = "msanii/uploads/" . htmlspecialchars($event['profile_pic'] ?: 'default.png');
                ?>
                <div class="ev-card <?= $isPast ? 'is-past' : '' ?>"
                     data-title="<?= strtolower(htmlspecialchars($event['title'])) ?>"
                     data-venue="<?= strtolower(htmlspecialchars($event['venue'] ?? '')) ?>"
                     data-host="<?= strtolower(htmlspecialchars($event['username'] ?? '')) ?>">

                    <!-- Image -->
                    <div class="ev-card-img-wrap">
                        <img src="<?= $banner ?>"
                             alt="<?= htmlspecialchars($event['title']) ?>"
                             loading="lazy"
                             onerror="this.src='https://via.placeholder.com/480x270?text=Event'"/>
                        <div class="ev-card-img-grad"></div>

                        <!-- Status badge -->
                        <div class="ev-status <?= $statusClass ?>">
                            <?php if ($isLive): ?>
                                <span class="dot"></span>
                            <?php endif; ?>
                            <?= $statusText ?>
                        </div>

                        <!-- Countdown -->
                        <div class="ev-countdown <?= $cdClass ?>"><?= $cdText ?></div>

                        <!-- Price tag -->
                        <?php if ($priceTag): ?>
                        <div class="ev-price-tag <?= $isFree ? 'free' : '' ?>">
                            <?= htmlspecialchars($priceTag) ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Body -->
                    <div class="ev-card-body">
                        <h3 class="ev-card-title"><?= htmlspecialchars($event['title']) ?></h3>

                        <div class="ev-organizer">
                            <img src="<?= $profPic ?>" class="ev-org-avatar"
                                 alt="@<?= htmlspecialchars($event['username'] ?? '') ?>"
                                 onerror="this.src='https://via.placeholder.com/32?text=K'"/>
                            <div class="ev-org-name">
                                by <strong>@<?= htmlspecialchars($event['username'] ?? 'Unknown') ?></strong>
                            </div>
                        </div>

                        <div class="ev-meta">
                            <div class="ev-meta-row">
                                <i class="fas fa-calendar-alt"></i>
                                <span><?= date('D, d M Y', strtotime($event['start_date'])) ?></span>
                            </div>
                            <div class="ev-meta-row">
                                <i class="fas fa-clock"></i>
                                <span><?= date('g:i A', strtotime($event['start_time'] ?? '00:00')) ?></span>
                            </div>
                            <div class="ev-meta-row">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="trunc"><?= htmlspecialchars($event['venue'] ?? 'Location TBD') ?></span>
                            </div>
                        </div>

                        <div class="ev-sep"></div>

                        <?php if (!$isPast && $ctaClass !== 'cta-none' && $ctaClass !== 'cta-past'): ?>
                        <a href="ticket.php?id=<?= $event['id'] ?>"
                           class="ev-cta <?= $ctaClass ?>">
                            <i class="fas <?= $ctaIcon ?>"></i>
                            <?= $priceText ?>
                        </a>
                        <?php else: ?>
                        <div class="ev-cta <?= $ctaClass ?>">
                            <i class="fas <?= $ctaIcon ?>"></i>
                            <?= $priceText ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="no-ev">
                    <div class="no-ev-icon">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <h3>No Events Found</h3>
                    <p>
                        <?php if ($filter === 'upcoming'): ?>
                            No upcoming events right now — check back soon!
                        <?php elseif ($filter === 'past'): ?>
                            No past events recorded yet.
                        <?php else: ?>
                            Creators are preparing amazing experiences — stay tuned!
                        <?php endif; ?>
                    </p>
                    <?php if ($filter !== 'all'): ?>
                    <a href="?filter=all" style="margin-top:16px;display:inline-block;padding:12px 28px;background:var(--navy);color:#fff;border-radius:10px;font-family:var(--fh);font-weight:700;font-size:14px;text-decoration:none;">
                        View All Events
                    </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ══ PAGINATION ══ -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Events pagination">
            <div class="ev-pagination">
                <!-- Prev -->
                <a href="?filter=<?= $filter ?>&page=<?= $page-1 ?>"
                   class="ev-page-btn <?= $page<=1?'disabled':'' ?>"
                   aria-label="Previous">
                    <i class="fas fa-chevron-left" style="font-size:11px;"></i>
                </a>

                <?php
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                if ($start > 1): ?>
                    <a href="?filter=<?= $filter ?>&page=1" class="ev-page-btn">1</a>
                    <?php if ($start > 2): ?><span class="ev-page-ellipsis">…</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?filter=<?= $filter ?>&page=<?= $i ?>"
                       class="ev-page-btn <?= $i==$page?'active':'' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages-1): ?><span class="ev-page-ellipsis">…</span><?php endif; ?>
                    <a href="?filter=<?= $filter ?>&page=<?= $totalPages ?>"
                       class="ev-page-btn"><?= $totalPages ?></a>
                <?php endif; ?>

                <!-- Next -->
                <a href="?filter=<?= $filter ?>&page=<?= $page+1 ?>"
                   class="ev-page-btn <?= $page>=$totalPages?'disabled':'' ?>"
                   aria-label="Next">
                    <i class="fas fa-chevron-right" style="font-size:11px;"></i>
                </a>
            </div>
            <p class="ev-page-info">
                Page <span><?= $page ?></span> of <span><?= $totalPages ?></span>
                &nbsp;·&nbsp; <?= $totalEvents ?> total events
            </p>
        </nav>
        <?php endif; ?>

    </div>
</section>

</main>

<?php include_once('includes/footer.php'); ?>

<!-- JS -->
<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.sticky.js"></script>
<script src="js/jquery.magnific-popup.min.js"></script>
<script src="js/magnific-popup-options.js"></script>
<script src="js/click-scroll.js"></script>
<script src="js/custom.js"></script>

<!-- Live search -->
<script>
(function(){
    const inp   = document.getElementById('evSearch');
    const cards = document.querySelectorAll('.ev-card');
    const info  = document.querySelector('.results-info span:first-child');
    if (!inp || !cards.length) return;

    inp.addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        let visible = 0;
        cards.forEach(card => {
            const t = (card.dataset.title || '') + ' ' + (card.dataset.venue || '') + ' ' + (card.dataset.host || '');
            const show = !q || t.includes(q);
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (info) info.textContent = visible;

        // No results message
        const grid = document.getElementById('eventsGrid');
        let noRes = grid.querySelector('.no-res-msg');
        if (visible === 0 && q) {
            if (!noRes) {
                noRes = document.createElement('div');
                noRes.className = 'no-res-msg no-ev';
                noRes.innerHTML = `
                    <div class="no-ev-icon"><i class="fas fa-search"></i></div>
                    <h3>No results for "${q}"</h3>
                    <p>Try a different search term — event name, venue or host.</p>`;
                grid.appendChild(noRes);
            }
        } else if (noRes) {
            noRes.remove();
        }
    });

    // Clear on escape
    inp.addEventListener('keydown', e => {
        if (e.key === 'Escape') { inp.value = ''; inp.dispatchEvent(new Event('input')); }
    });
})();
</script>
</body>
</html>