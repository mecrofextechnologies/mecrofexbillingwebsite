<?php
include 'msanii/includes/connect.php';


// ─── Pagination ───────────────────────────────────────────────
$perPage = 12;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Count totals
$totalCreators = (int)$pdo->query("
    SELECT COUNT(*) FROM my_page mp JOIN creators c ON mp.creator_id = c.id
")->fetchColumn();
$totalPages = max(1, ceil($totalCreators / $perPage));

// Count creators who have events
$withEvents = (int)$pdo->query("
    SELECT COUNT(DISTINCT mp.creator_id)
    FROM my_page mp
    JOIN creators c ON mp.creator_id = c.id
    JOIN tickets t ON t.creator_id = c.id
")->fetchColumn();

// Fetch creators — explicitly select c.full_name so any profile update
// in the creators table is always reflected here (mp.* would shadow it)
$stmt = $pdo->prepare("
    SELECT
        mp.*,
        c.username,
        c.full_name,
        COUNT(DISTINCT t.id)   AS event_count,
        COUNT(DISTINCT tp.id)  AS ticket_count
    FROM my_page mp
    JOIN creators c ON mp.creator_id = c.id
    LEFT JOIN tickets t  ON t.creator_id  = c.id
    LEFT JOIN ticket_purchases tp ON tp.ticket_id = t.id AND tp.status = 'completed'
    GROUP BY mp.id, c.username, c.full_name
    ORDER BY mp.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset,  PDO::PARAM_INT);
$stmt->execute();
$creators = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<meta name="description" content="Discover all creators on Keychele"/>
<title>Creators | Keychele</title>

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
   BRAND TOKENS
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

*, *::before, *::after { box-sizing: border-box; }
img { display: block; max-width: 100%; }

@font-face {
    font-family: 'Glacial Indifference';
    src: local('Glacial Indifference');
    font-weight: normal;
}

body { font-family: var(--fb); -webkit-font-smoothing: antialiased; background: var(--g50); }

/* ══════════════════════════════════════════════════
   HERO
══════════════════════════════════════════════════ */
.cr-hero {
    position: relative;
    background: var(--navy-d);
    padding: 90px 0 70px;
    overflow: hidden;
}
.cr-hero::before {
    content: '';
    position: absolute; inset: 0;
    background:
        radial-gradient(ellipse 70% 60% at 15% 50%, rgba(254,196,17,.10) 0%, transparent 65%),
        radial-gradient(ellipse 55% 70% at 85% 25%, rgba(191,19,57,.08) 0%, transparent 65%);
}
.cr-hero-dots {
    position: absolute; inset: 0; overflow: hidden; opacity: .05;
    background-image: radial-gradient(var(--gold) 1.5px, transparent 1.5px);
    background-size: 36px 36px;
}
.cr-hero-inner { position: relative; z-index: 2; text-align: center; }
.cr-hero-eyebrow {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(254,196,17,.12);
    border: 1px solid rgba(254,196,17,.3);
    color: var(--gold);
    font-family: var(--fh);
    font-size: 11px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase;
    padding: 7px 18px; border-radius: 30px;
    margin-bottom: 20px;
}
.cr-hero h1 {
    font-family: var(--fh);
    font-size: clamp(32px, 6vw, 62px);
    font-weight: 900; color: var(--white);
    line-height: 1.1; margin-bottom: 16px;
}
.cr-hero h1 em { font-style: normal; color: var(--gold); }
.cr-hero p {
    font-size: clamp(15px, 2vw, 18px);
    color: rgba(255,255,255,.52);
    max-width: 540px; margin: 0 auto 38px; line-height: 1.7;
}
.hero-gold-bar {
    width: 60px; height: 4px; border-radius: 2px;
    background: var(--gold); margin: 0 auto 24px;
}
.hero-stats {
    display: flex; justify-content: center;
    gap: 40px; flex-wrap: wrap;
}
.hero-stat-item { text-align: center; }
.hero-stat-num {
    font-family: var(--fh);
    font-size: 34px; font-weight: 900;
    color: var(--gold); line-height: 1;
}
.hero-stat-lbl {
    font-size: 10px; font-weight: 700; letter-spacing: 2px;
    text-transform: uppercase; color: rgba(255,255,255,.38);
    margin-top: 5px;
}
.hero-vdivider {
    width: 1px; height: 50px; background: rgba(255,255,255,.1);
    align-self: center;
}

/* ══════════════════════════════════════════════════
   STICKY CONTROLS
══════════════════════════════════════════════════ */
.ctrl-bar {
    background: var(--white);
    border-bottom: 2px solid var(--g100);
    padding: 18px 0;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 4px 24px rgba(5,46,100,.07);
}
.ctrl-inner {
    display: flex; align-items: center;
    gap: 14px; flex-wrap: wrap;
}
.ctrl-heading {
    font-family: var(--fh);
    font-size: 16px; font-weight: 800;
    color: var(--navy); flex: 1;
    display: flex; align-items: center; gap: 10px;
}
.ctrl-count-pill {
    background: var(--navy-l); color: var(--navy);
    font-family: var(--fh); font-size: 11px; font-weight: 800;
    padding: 4px 12px; border-radius: 20px;
}
.results-info {
    font-family: var(--fh);
    font-size: 12px; font-weight: 700;
    color: var(--g400); white-space: nowrap;
}
.results-info span { color: var(--navy); }

.search-box {
    position: relative; min-width: 220px; max-width: 360px;
}
.search-box input {
    width: 100%;
    padding: 11px 46px 11px 18px;
    border: 1.5px solid var(--g200); border-radius: 30px;
    font-family: var(--fb); font-size: 14px; color: var(--navy);
    background: var(--g50); outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.search-box input:focus {
    border-color: var(--navy); background: #fff;
    box-shadow: 0 0 0 3px rgba(5,46,100,.1);
}
.search-box input::placeholder { color: var(--g400); }
.search-ico {
    position: absolute; right: 14px; top: 50%;
    transform: translateY(-50%);
    color: var(--g400); font-size: 14px; pointer-events: none;
}

/* ══════════════════════════════════════════════════
   CREATORS GRID
══════════════════════════════════════════════════ */
.creators-section { padding: 40px 0 90px; }

.creators-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 22px;
}

.cr-card {
    background: var(--white);
    border-radius: 20px;
    border: 1.5px solid var(--g100);
    overflow: hidden;
    display: flex; flex-direction: column;
    box-shadow: 0 4px 18px rgba(5,46,100,.07);
    transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    position: relative;
    animation: fadeUp .45s ease both;
}
.cr-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 22px 52px rgba(5,46,100,.17);
    border-color: rgba(5,46,100,.14);
}

.cr-card:nth-child(1)  { animation-delay:.04s; }
.cr-card:nth-child(2)  { animation-delay:.08s; }
.cr-card:nth-child(3)  { animation-delay:.12s; }
.cr-card:nth-child(4)  { animation-delay:.16s; }
.cr-card:nth-child(5)  { animation-delay:.20s; }
.cr-card:nth-child(6)  { animation-delay:.24s; }
.cr-card:nth-child(7)  { animation-delay:.28s; }
.cr-card:nth-child(8)  { animation-delay:.32s; }
.cr-card:nth-child(9)  { animation-delay:.36s; }
.cr-card:nth-child(10) { animation-delay:.40s; }
.cr-card:nth-child(11) { animation-delay:.44s; }
.cr-card:nth-child(12) { animation-delay:.48s; }
@keyframes fadeUp {
    from { opacity:0; transform:translateY(22px); }
    to   { opacity:1; transform:translateY(0); }
}

.cr-banner {
    position: relative;
    height: 110px;
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-m) 100%);
    overflow: hidden;
    flex-shrink: 0;
}
.cr-banner-img {
    width: 100%; height: 100%;
    object-fit: cover; opacity: .55;
}
.cr-banner-grad {
    position: absolute; inset: 0;
    background: linear-gradient(to bottom, transparent 30%, rgba(5,46,100,.7) 100%);
}
.cr-banner::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: var(--gold); z-index: 2;
}

.cr-avatar-wrap {
    position: relative; z-index: 5;
    display: flex; justify-content: center;
    margin-top: -44px; margin-bottom: 10px;
    flex-shrink: 0;
}
.cr-avatar {
    width: 88px; height: 88px; border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--white);
    box-shadow: 0 4px 18px rgba(5,46,100,.18);
    background: var(--g100);
}
.cr-avatar-fallback {
    width: 88px; height: 88px; border-radius: 50%;
    background: linear-gradient(135deg, var(--navy), var(--navy-m));
    border: 4px solid var(--white);
    box-shadow: 0 4px 18px rgba(5,46,100,.18);
    display: flex; align-items: center; justify-content: center;
    font-family: var(--fh); font-weight: 900;
    font-size: 28px; color: var(--gold);
}

.cr-ev-badge {
    position: absolute; bottom: 2px; right: calc(50% - 50px);
    background: var(--navy);
    color: var(--gold);
    font-family: var(--fh); font-size: 9px; font-weight: 800;
    letter-spacing: .5px; text-transform: uppercase;
    padding: 2px 8px; border-radius: 10px;
    border: 2px solid var(--white);
    white-space: nowrap;
}

.cr-body {
    padding: 4px 20px 20px;
    display: flex; flex-direction: column;
    align-items: center; text-align: center;
    flex: 1;
}
.cr-name {
    font-family: var(--fh);
    font-weight: 800; font-size: 17px;
    color: var(--navy); margin-bottom: 3px;
    line-height: 1.2;
}
.cr-username {
    font-size: 13px; color: var(--g400); font-weight: 500;
    margin-bottom: 12px;
}
.cr-username span { color: var(--crimson); font-weight: 700; }

.cr-bio {
    font-size: 13px; color: var(--g600);
    line-height: 1.65; margin-bottom: 14px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.cr-stats {
    display: flex; gap: 0;
    width: 100%; margin-bottom: 16px;
    border: 1.5px solid var(--g100);
    border-radius: 10px; overflow: hidden;
}
.cr-stat {
    flex: 1; padding: 10px 8px; text-align: center;
    border-right: 1.5px solid var(--g100);
}
.cr-stat:last-child { border-right: none; }
.cr-stat-num {
    font-family: var(--fh); font-weight: 900;
    font-size: 18px; color: var(--navy);
    line-height: 1;
}
.cr-stat-lbl {
    font-size: 10px; color: var(--g400);
    font-weight: 600; letter-spacing: 1px;
    text-transform: uppercase; margin-top: 3px;
}

.cr-socials {
    display: flex; gap: 8px; margin-bottom: 16px;
    justify-content: center;
    flex-wrap: wrap;
}
.cr-social-link {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: var(--g100); color: var(--navy);
    font-size: 12px; text-decoration: none;
    transition: all .2s;
    border: 1.5px solid var(--g200);
}
.cr-social-link:hover {
    background: var(--navy); color: var(--gold);
    border-color: var(--navy);
    transform: translateY(-2px);
}

.cr-category {
    display: inline-block;
    font-family: var(--fh); font-size: 10px; font-weight: 700;
    letter-spacing: 1px; text-transform: uppercase;
    padding: 3px 10px; border-radius: 12px;
    background: var(--gold-l); color: var(--gold-d);
    border: 1px solid rgba(254,196,17,.35);
    margin-bottom: 14px;
}

.cr-sep { width: 100%; height: 1px; background: var(--g100); margin-bottom: 14px; }

.cr-cta {
    width: 100%; padding: 13px;
    background: var(--navy); color: var(--white);
    border: none; border-radius: 10px;
    font-family: var(--fh); font-size: 13px; font-weight: 800;
    letter-spacing: .3px; text-decoration: none;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all .2s; cursor: pointer;
    margin-top: auto;
}
.cr-cta:hover {
    background: var(--crimson); color: var(--white);
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(191,19,57,.3);
}

/* ══════════════════════════════════════════════════
   NO CREATORS STATE
══════════════════════════════════════════════════ */
.no-cr {
    grid-column: 1/-1;
    text-align: center; padding: 80px 20px;
}
.no-cr-icon {
    width: 90px; height: 90px; border-radius: 50%;
    background: var(--navy-l);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 22px; font-size: 36px; color: var(--navy);
}
.no-cr h3 {
    font-family: var(--fh); font-weight: 800;
    font-size: 22px; color: var(--navy); margin-bottom: 10px;
}
.no-cr p { font-size: 15px; color: var(--g400); }

/* ══════════════════════════════════════════════════
   PAGINATION
══════════════════════════════════════════════════ */
.cr-pagination {
    display: flex; align-items: center; justify-content: center;
    gap: 6px; flex-wrap: wrap; margin-top: 52px;
}
.cr-page-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 40px; height: 40px; padding: 0 10px;
    border-radius: 10px;
    font-family: var(--fh); font-size: 13px; font-weight: 700;
    color: var(--navy); background: var(--white);
    border: 1.5px solid var(--g200);
    text-decoration: none; transition: all .2s;
}
.cr-page-btn:hover {
    background: var(--navy-l); border-color: var(--navy); color: var(--navy);
}
.cr-page-btn.active {
    background: var(--navy); border-color: var(--navy);
    color: var(--white); box-shadow: 0 4px 14px rgba(5,46,100,.3);
}
.cr-page-btn.disabled {
    opacity: .32; pointer-events: none;
}
.cr-page-ellipsis { font-size: 14px; color: var(--g400); padding: 0 4px; }
.cr-page-info {
    font-family: var(--fh); font-size: 12px; color: var(--g400);
    font-weight: 600; text-align: center; margin-top: 14px;
}
.cr-page-info span { color: var(--navy); font-weight: 800; }

/* ══════════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════════ */
@media (max-width: 768px) {
    .ctrl-inner { flex-direction: column; align-items: stretch; }
    .search-box { max-width: 100%; }
    .results-info { display: none; }
    .creators-grid { grid-template-columns: repeat(2, 1fr); gap: 14px; }
    .hero-vdivider { display: none; }
}
@media (max-width: 480px) {
    .creators-grid { grid-template-columns: 1fr; }
    .cr-hero { padding: 70px 0 50px; }
}
</style>
</head>

<body id="section_1">
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/nav.php'); ?>

<main>

<!-- HERO -->
<section class="cr-hero">
    <div class="cr-hero-dots"></div>
    <div class="container">
        <div class="cr-hero-inner">
            <div class="cr-hero-eyebrow">
                <i class="fas fa-star"></i>
                Keychele Creators
            </div>
            <h1>Meet the <em>Talent</em><br>Behind the Events</h1>
            <div class="hero-gold-bar"></div>
            <p>Browse all creators on Keychele — artists, organizers, educators and visionaries shaping Kenya's event scene.</p>

            <div class="hero-stats">
                <div class="hero-stat-item">
                    <div class="hero-stat-num"><?= $totalCreators ?></div>
                    <div class="hero-stat-lbl">Total Creators</div>
                </div>
                <div class="hero-vdivider"></div>
                <div class="hero-stat-item">
                    <div class="hero-stat-num"><?= $withEvents ?></div>
                    <div class="hero-stat-lbl">Active Hosts</div>
                </div>
                <div class="hero-vdivider"></div>
                <div class="hero-stat-item">
                    <div class="hero-stat-num"><?= $totalCreators - $withEvents ?></div>
                    <div class="hero-stat-lbl">New Creators</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- STICKY CONTROLS -->
<div class="ctrl-bar">
    <div class="container">
        <div class="ctrl-inner">
            <div class="ctrl-heading">
                All Creators
                <span class="ctrl-count-pill"><?= $totalCreators ?></span>
            </div>
            <div class="results-info">
                Showing <span id="visibleCount"><?= count($creators) ?></span> of <span><?= $totalCreators ?></span>
            </div>
            <div class="search-box">
                <input type="search" id="creatorSearch"
                       placeholder="Search by name or username…"
                       autocomplete="off"/>
                <i class="fas fa-search search-ico"></i>
            </div>
        </div>
    </div>
</div>

<!-- CREATORS GRID -->
<section class="creators-section">
    <div class="container">

        <div class="creators-grid" id="creatorGrid">
            <?php if (!empty($creators)): ?>
                <?php foreach ($creators as $cr):
                    $avatar   = "msanii/uploads/" . htmlspecialchars($cr['profile_pic'] ?: 'default.png');

                    // Always use full_name from creators table (c.full_name).
                    // Fall back to page_name from my_page only if creators.full_name is empty.
                    $displayName = !empty($cr['full_name'])
                        ? $cr['full_name']
                        : (!empty($cr['page_name']) ? $cr['page_name'] : 'Creator');

                    $initial  = strtoupper(substr($displayName, 0, 1));
                    $evCount  = (int)($cr['event_count']  ?? 0);
                    $tkCount  = (int)($cr['ticket_count'] ?? 0);
                    $hasEvents = $evCount > 0;

                    $socials = [];
                    if (!empty($cr['twitter']))   $socials[] = ['icon'=>'fab fa-x-twitter',  'url'=>$cr['twitter']];
                    if (!empty($cr['instagram']))  $socials[] = ['icon'=>'fab fa-instagram',  'url'=>$cr['instagram']];
                    if (!empty($cr['facebook']))   $socials[] = ['icon'=>'fab fa-facebook-f', 'url'=>$cr['facebook']];
                    if (!empty($cr['youtube']))    $socials[] = ['icon'=>'fab fa-youtube',     'url'=>$cr['youtube']];
                    if (!empty($cr['tiktok']))     $socials[] = ['icon'=>'fab fa-tiktok',      'url'=>$cr['tiktok']];
                    if (!empty($cr['website']))    $socials[] = ['icon'=>'fas fa-globe',        'url'=>$cr['website']];
                ?>
                <div class="cr-card"
                     data-name="<?= strtolower(htmlspecialchars($displayName)) ?>"
                     data-username="<?= strtolower(htmlspecialchars($cr['username'] ?? '')) ?>">

                    <!-- Banner -->
                    <div class="cr-banner">
                        <?php if (!empty($cr['banner_pic'])): ?>
                            <img src="msanii/uploads/<?= htmlspecialchars($cr['banner_pic']) ?>"
                                 class="cr-banner-img" alt="" loading="lazy"/>
                        <?php endif; ?>
                        <div class="cr-banner-grad"></div>
                    </div>

                    <!-- Avatar -->
                    <div class="cr-avatar-wrap">
                        <img src="<?= $avatar ?>"
                             class="cr-avatar"
                             alt="<?= htmlspecialchars($displayName) ?>"
                             loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
                        <div class="cr-avatar-fallback" style="display:none;"><?= $initial ?></div>

                        <?php if ($hasEvents): ?>
                        <div class="cr-ev-badge">
                            <?= $evCount ?> Event<?= $evCount > 1 ? 's' : '' ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Body -->
                    <div class="cr-body">
                        <h3 class="cr-name"><?= htmlspecialchars($displayName) ?></h3>
                        <div class="cr-username">
                            <span>@<?= htmlspecialchars($cr['username'] ?? '') ?></span>
                        </div>

                        <?php if (!empty($cr['category'])): ?>
                        <div class="cr-category">
                            <?= htmlspecialchars($cr['category']) ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($cr['bio'])): ?>
                        <p class="cr-bio"><?= htmlspecialchars($cr['bio']) ?></p>
                        <?php endif; ?>

                        <!-- Stats -->
                        <div class="cr-stats">
                            <div class="cr-stat">
                                <div class="cr-stat-num"><?= $evCount ?></div>
                                <div class="cr-stat-lbl">Events</div>
                            </div>
                            <div class="cr-stat">
                                <div class="cr-stat-num"><?= $tkCount ?></div>
                                <div class="cr-stat-lbl">Tickets</div>
                            </div>
                            <div class="cr-stat">
                                <div class="cr-stat-num">
                                    <?= !empty($cr['created_at']) ? date('Y', strtotime($cr['created_at'])) : '—' ?>
                                </div>
                                <div class="cr-stat-lbl">Since</div>
                            </div>
                        </div>

                        <!-- Social links -->
                        <?php if (!empty($socials)): ?>
                        <div class="cr-socials">
                            <?php foreach ($socials as $s): ?>
                            <a href="<?= htmlspecialchars($s['url']) ?>"
                               class="cr-social-link"
                               target="_blank" rel="noopener noreferrer">
                                <i class="<?= $s['icon'] ?>"></i>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="cr-sep"></div>

                        <!-- CTA -->
                        <a href="/<?= htmlspecialchars($cr['username']) ?>"
                           class="cr-cta">
                            <i class="fas fa-user"></i>
                            View Profile
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="no-cr">
                    <div class="no-cr-icon"><i class="fas fa-users"></i></div>
                    <h3>No Creators Found</h3>
                    <p>Try a different search or check back later.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Creators pagination">
            <div class="cr-pagination">
                <a href="?page=<?= $page-1 ?>"
                   class="cr-page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                   aria-label="Previous">
                    <i class="fas fa-chevron-left" style="font-size:11px;"></i>
                </a>

                <?php
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                if ($start > 1): ?>
                    <a href="?page=1" class="cr-page-btn">1</a>
                    <?php if ($start > 2): ?><span class="cr-page-ellipsis">…</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?= $i ?>"
                       class="cr-page-btn <?= $i == $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span class="cr-page-ellipsis">…</span><?php endif; ?>
                    <a href="?page=<?= $totalPages ?>" class="cr-page-btn"><?= $totalPages ?></a>
                <?php endif; ?>

                <a href="?page=<?= $page+1 ?>"
                   class="cr-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                   aria-label="Next">
                    <i class="fas fa-chevron-right" style="font-size:11px;"></i>
                </a>
            </div>
            <p class="cr-page-info">
                Page <span><?= $page ?></span> of <span><?= $totalPages ?></span>
                &nbsp;·&nbsp; <?= $totalCreators ?> total creators
            </p>
        </nav>
        <?php endif; ?>

    </div>
</section>

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
(function(){
    const inp     = document.getElementById('creatorSearch');
    const cards   = document.querySelectorAll('.cr-card');
    const counter = document.getElementById('visibleCount');
    if (!inp || !cards.length) return;

    inp.addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        let visible = 0;
        cards.forEach(card => {
            const n = (card.dataset.name || '') + ' ' + (card.dataset.username || '');
            const show = !q || n.includes(q);
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (counter) counter.textContent = visible;

        const grid = document.getElementById('creatorGrid');
        let noRes = grid.querySelector('.no-res-msg');
        if (visible === 0 && q) {
            if (!noRes) {
                noRes = document.createElement('div');
                noRes.className = 'no-res-msg no-cr';
                noRes.innerHTML = `
                    <div class="no-cr-icon"><i class="fas fa-search"></i></div>
                    <h3>No results for "<em>${q}</em>"</h3>
                    <p>Try searching by full name or @username.</p>`;
                grid.appendChild(noRes);
            }
        } else if (noRes) { noRes.remove(); }
    });

    inp.addEventListener('keydown', e => {
        if (e.key === 'Escape') { inp.value = ''; inp.dispatchEvent(new Event('input')); }
    });
})();
</script>
</body>
</html>