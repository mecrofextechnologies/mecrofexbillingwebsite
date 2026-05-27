<?php
include 'msanii/includes/connect.php';

// ================================================
// GET USERNAME FROM CLEAN URL
// ================================================
$request_uri = trim($_SERVER['REQUEST_URI'], '/');
$parts = explode('/', $request_uri);
if (($qpos = strpos($request_uri, '?')) !== false) {
    $request_uri = substr($request_uri, 0, $qpos);
    $parts = explode('/', trim($request_uri, '/'));
}

$username = '';
if (!empty($parts[0]) && empty($parts[1])) {
    $username = $parts[0];
} else {
    $username = $_GET['username'] ?? '';
}
$username = trim($username);

if (empty($username) || !preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
    http_response_code(404);
    die("<h1 style='text-align:center;margin-top:80px;font-family:sans-serif;'>404 - Invalid Creator Link</h1>");
}

// ================================================
// FETCH CREATOR DATA
// ================================================
$stmt = $pdo->prepare("
    SELECT mp.*, c.username, c.id AS creator_id,
           COALESCE(NULLIF(TRIM(c.full_name),''), mp.full_name, CONCAT('@', c.username)) AS full_name
    FROM my_page mp
    JOIN creators c ON mp.creator_id = c.id
    WHERE c.username = ?
    LIMIT 1
");
$stmt->execute([$username]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$creator) {
    http_response_code(404);
    echo "<h1 style='text-align:center;margin-top:80px;font-family:sans-serif;'>Creator Not Found</h1>";
    echo "<p style='text-align:center;'>Sorry, <strong>@$username</strong> doesn't exist.</p>";
    exit;
}

$creator_id = $creator['creator_id'];

// ================================================
// FETCH PORTFOLIO — matches new category schema
// visual | video | document | photography | web
// ================================================
$portfolio_stmt = $pdo->prepare("SELECT * FROM portfolio WHERE creator_id = ? ORDER BY created_at DESC");
$portfolio_stmt->execute([$creator_id]);
$all_items = $portfolio_stmt->fetchAll(PDO::FETCH_ASSOC);

// Upload dirs per category (matches dashboard)
$UPLOAD_DIRS = [
    'visual'      => 'msanii/uploads/visual/',
    'video'       => '',                          // YouTube only
    'document'    => 'msanii/uploads/documents/',
    'photography' => 'msanii/uploads/photography/',
    'web'         => 'msanii/uploads/web/',
];

$portfolio = ['visual'=>[],'video'=>[],'document'=>[],'photography'=>[],'web'=>[]];
foreach ($all_items as $item) {
    $cat = strtolower(trim($item['category'] ?? ''));
    if (isset($portfolio[$cat])) {
        $portfolio[$cat][] = $item;
    }
}

// ================================================
// FETCH TICKETS & EVENTS
// ================================================
$tickets_stmt = $pdo->prepare("
    SELECT t.id, t.title, t.venue, t.start_date, t.start_time, t.banner,
           MIN(ett.price) AS min_price, COUNT(ett.id) AS ticket_type_count
    FROM tickets t
    LEFT JOIN event_ticket_types ett ON t.id = ett.event_id
    WHERE t.creator_id = ?
    GROUP BY t.id
    ORDER BY t.created_at DESC
");
$tickets_stmt->execute([$creator_id]);
$tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

// ================================================
// SUGGESTED CREATORS
// ================================================
$suggested_stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(TRIM(c.full_name),''), mp.full_name, CONCAT('@',c.username)) AS full_name,
           mp.profile_pic, c.username
    FROM my_page mp
    JOIN creators c ON mp.creator_id = c.id
    WHERE c.id != ? AND mp.profile_pic != '' AND mp.profile_pic IS NOT NULL
    ORDER BY RAND() LIMIT 7
");
$suggested_stmt->execute([$creator_id]);
$suggested = $suggested_stmt->fetchAll(PDO::FETCH_ASSOC);

$displayName = $creator['full_name'];
$initial     = strtoupper(substr(strip_tags($displayName), 0, 1));

// Totals for stats badge
$totalPortfolio = array_sum(array_map('count', $portfolio));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($displayName) ?> | Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
/* ═══════════════════════════════════════════
   TOKENS
═══════════════════════════════════════════ */
:root {
  --navy:     #032d5d;
  --navy-d:   #021e3f;
  --navy-m:   #0a4a99;
  --navy-l:   #e8eef8;
  --accent:   #b81236;
  --accent-d: #8f0e2a;
  --accent-l: #fce4eb;
  --gold:     #f5c842;
  --gold-l:   #fffbea;
  --purple:   #6d28d9;
  --purple-l: #ede9fe;
  --white:    #ffffff;
  --off:      #f7f8fc;
  --g100:     #eef1f9;
  --g200:     #dde3f0;
  --g400:     #8a97b8;
  --g600:     #4a5578;
  --g900:     #0f172a;
  --green:    #16a34a;
  --ff:       'Playfair Display', Georgia, serif;
  --fb:       'DM Sans', system-ui, sans-serif;
  --radius:   18px;
  --radius-sm:10px;
  --shadow:   0 4px 24px rgba(3,45,93,.10);
  --shadow-lg:0 16px 56px rgba(3,45,93,.18);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:var(--fb);background:var(--off);color:var(--g900);-webkit-font-smoothing:antialiased;min-height:100vh;display:flex;flex-direction:column;}
img{display:block;max-width:100%;}
a{text-decoration:none;color:inherit;}

/* ═══════════════════════════════════════════
   HERO BANNER
═══════════════════════════════════════════ */
.hero-wrap{position:relative;width:100%;overflow:hidden;}
.hero-banner{position:relative;height:300px;background:var(--navy-d);overflow:hidden;}
@media(min-width:768px){.hero-banner{height:400px;}}
.hero-banner-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.45;}
.hero-banner-grad{position:absolute;inset:0;background:linear-gradient(to bottom,rgba(2,30,63,.3) 0%,rgba(2,30,63,.85) 70%,rgba(2,30,63,1) 100%);}
.hero-banner::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.06) 1px,transparent 1px);background-size:28px 28px;pointer-events:none;}
.hero-banner::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--gold),var(--accent),var(--gold));z-index:3;}

/* ═══════════════════════════════════════════
   PROFILE AREA
═══════════════════════════════════════════ */
.profile-area{position:relative;z-index:10;margin-top:-80px;padding:0 16px 32px;}
@media(min-width:768px){.profile-area{margin-top:-100px;padding:0 32px 40px;}}
.profile-inner{max-width:1200px;margin:0 auto;display:flex;flex-direction:column;align-items:center;gap:20px;}
@media(min-width:768px){.profile-inner{flex-direction:row;align-items:flex-end;gap:28px;}}

.avatar-ring{flex-shrink:0;width:130px;height:130px;border-radius:50%;padding:4px;background:linear-gradient(135deg,var(--gold),var(--accent));box-shadow:var(--shadow-lg);}
@media(min-width:768px){.avatar-ring{width:160px;height:160px;}}
.avatar-ring img,.avatar-ring .avatar-fb{width:100%;height:100%;border-radius:50%;object-fit:cover;border:4px solid var(--white);background:var(--navy-l);}
.avatar-fb{display:flex;align-items:center;justify-content:center;font-family:var(--ff);font-weight:900;font-size:52px;color:var(--navy);background:var(--navy-l);}

.profile-name-block{flex:1;text-align:center;padding-bottom:8px;}
@media(min-width:768px){.profile-name-block{text-align:left;}}
.profile-name{font-family:var(--ff);font-size:clamp(26px,5vw,50px);font-weight:900;color:var(--white);line-height:1.1;letter-spacing:-0.02em;text-shadow:0 2px 12px rgba(0,0,0,.4);}
.profile-handle{font-size:clamp(13px,2vw,16px);color:rgba(255,255,255,.65);font-weight:500;margin-top:6px;}
.profile-handle span{color:var(--gold);font-weight:700;}

.profile-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:18px;}
@media(min-width:768px){.profile-actions{justify-content:flex-start;}}

.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:50px;font-family:var(--fb);font-size:13px;font-weight:700;letter-spacing:.2px;border:none;cursor:pointer;transition:all .2s;white-space:nowrap;}
.btn-primary{background:var(--white);color:var(--navy);box-shadow:0 4px 16px rgba(0,0,0,.2);}
.btn-primary:hover{background:var(--navy-l);transform:translateY(-2px);}
.btn-accent{background:var(--accent);color:var(--white);box-shadow:0 4px 16px rgba(184,18,54,.35);}
.btn-accent:hover{background:var(--accent-d);transform:translateY(-2px);}
.btn-navy{background:var(--navy);color:var(--white);border:2px solid rgba(255,255,255,.25);box-shadow:0 4px 16px rgba(0,0,0,.2);}
.btn-navy:hover{background:var(--navy-m);transform:translateY(-2px);}
.btn-dark{background:rgba(0,0,0,.55);color:var(--white);border:1px solid rgba(255,255,255,.15);backdrop-filter:blur(8px);}
.btn-dark:hover{background:rgba(0,0,0,.75);transform:translateY(-2px);}

/* ═══════════════════════════════════════════
   MAIN LAYOUT
═══════════════════════════════════════════ */
.main-wrap{max-width:1200px;margin:0 auto;padding:0 16px 60px;width:100%;flex:1;}
@media(min-width:768px){.main-wrap{padding:0 32px 80px;}}
.grid-layout{display:grid;grid-template-columns:1fr;gap:24px;}
@media(min-width:1024px){.grid-layout{grid-template-columns:300px 1fr;}}

/* ═══════════════════════════════════════════
   CARDS
═══════════════════════════════════════════ */
.card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);border:1.5px solid var(--g100);overflow:hidden;}
.card-header{padding:20px 24px 0;}
.card-title{font-family:var(--ff);font-size:20px;font-weight:700;color:var(--navy);margin-bottom:16px;display:flex;align-items:center;gap:10px;}
.card-title-icon{width:34px;height:34px;border-radius:10px;background:var(--navy-l);color:var(--navy);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.card-body{padding:20px 24px 24px;}

/* ═══════════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════════ */
.sidebar{display:flex;flex-direction:column;gap:20px;}
.about-text{font-size:14px;color:var(--g600);line-height:1.8;}
.divider{height:1px;background:var(--g100);margin:16px 0;}
.social-row{display:flex;gap:10px;flex-wrap:wrap;}
.social-btn{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--g100);color:var(--navy);font-size:15px;border:1.5px solid var(--g200);transition:all .2s;}
.social-btn:hover{background:var(--navy);color:var(--gold);border-color:var(--navy);transform:translateY(-2px);}
.social-btn.ig:hover{background:linear-gradient(135deg,#f58529,#dd2a7b,#8134af);border-color:transparent;color:#fff;}
.social-btn.yt:hover{background:#ff0000;border-color:transparent;color:#fff;}
.social-btn.tt:hover{background:#000;border-color:transparent;color:#fff;}
.social-btn.tw:hover{background:#1da1f2;border-color:transparent;color:#fff;}

.sug-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;transition:background .2s;cursor:pointer;}
.sug-item:hover{background:var(--off);}
.sug-avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--g200);flex-shrink:0;}
.sug-name{font-size:13px;font-weight:700;color:var(--navy);line-height:1.2;}
.sug-handle{font-size:11px;color:var(--g400);margin-top:2px;}

/* ═══════════════════════════════════════════
   TABS
═══════════════════════════════════════════ */
.tabs-nav{display:flex;border-bottom:2px solid var(--g100);overflow-x:auto;scrollbar-width:none;gap:0;}
.tabs-nav::-webkit-scrollbar{display:none;}
.tab-btn{display:inline-flex;align-items:center;gap:8px;padding:16px 22px;font-family:var(--fb);font-size:14px;font-weight:700;color:var(--g400);border:none;background:transparent;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:all .2s;letter-spacing:.2px;}
.tab-btn:hover{color:var(--navy);}
.tab-btn.active{color:var(--navy);border-bottom-color:var(--accent);}
.tab-btn .tab-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;background:var(--g100);color:var(--g400);transition:all .2s;}
.tab-btn.active .tab-icon{background:var(--accent-l);color:var(--accent);}
.tab-count-badge{background:var(--accent);color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:20px;margin-left:2px;}

.tab-pane{display:none;animation:fadeIn .3s ease;}
.tab-pane.active{display:block;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}

/* ═══════════════════════════════════════════
   CATEGORY FILTER PILLS
═══════════════════════════════════════════ */
.cat-pills{display:flex;flex-wrap:wrap;gap:8px;padding:20px 24px 0;}
.cat-pill{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:50px;font-size:12px;font-weight:700;font-family:var(--fb);letter-spacing:.3px;border:1.5px solid var(--g200);background:var(--white);color:var(--g600);cursor:pointer;transition:all .2s;}
.cat-pill:hover{border-color:var(--navy);color:var(--navy);}
.cat-pill.active{background:var(--navy);color:var(--white);border-color:var(--navy);}

/* ═══════════════════════════════════════════
   PORTFOLIO GRID
═══════════════════════════════════════════ */
.portfolio-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;padding:20px 24px 24px;}
@media(min-width:480px){.portfolio-grid{grid-template-columns:repeat(2,1fr);gap:14px;}}
@media(min-width:640px){.portfolio-grid{grid-template-columns:repeat(3,1fr);gap:16px;}}
@media(min-width:900px){.portfolio-grid{grid-template-columns:repeat(auto-fill,minmax(200px,1fr));}}

.port-item{border-radius:var(--radius-sm);overflow:hidden;position:relative;background:var(--g100);box-shadow:0 2px 12px rgba(3,45,93,.08);transition:transform .3s,box-shadow .3s;cursor:pointer;}
.port-item:hover{transform:translateY(-5px);box-shadow:0 16px 40px rgba(3,45,93,.18);}
.port-item img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;transition:transform .4s;}
.port-item:hover img{transform:scale(1.06);}

.port-item-video{aspect-ratio:16/9;}
.port-item-video iframe{width:100%;height:100%;display:block;}

.port-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(3,45,93,.78) 0%,transparent 55%);opacity:0;transition:opacity .3s;display:flex;align-items:flex-end;padding:12px;}
.port-item:hover .port-overlay{opacity:1;}
.port-overlay-text{font-size:12px;font-weight:700;color:#fff;line-height:1.3;}
.port-overlay-sub{font-size:10px;opacity:.75;margin-top:3px;}

.port-type-badge{position:absolute;top:8px;right:8px;background:rgba(3,45,93,.82);color:var(--gold);font-size:9px;font-weight:800;letter-spacing:1px;text-transform:uppercase;padding:3px 9px;border-radius:20px;backdrop-filter:blur(6px);}

/* WEB PROJECT card — wider, shows tech */
.port-item-web{aspect-ratio:unset;}
.port-item-web .web-thumb{aspect-ratio:16/9;overflow:hidden;background:linear-gradient(135deg,#0d1b3e,#1a3a6e);display:flex;align-items:center;justify-content:center;}
.port-item-web .web-thumb img{width:100%;height:100%;object-fit:cover;}
.port-item-web .web-no-thumb{font-size:2rem;opacity:.25;color:white;}
.port-item-web .web-body{padding:12px;}
.web-item-title{font-family:var(--ff);font-size:13px;font-weight:700;color:var(--navy);margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.web-tech-pills{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px;}
.web-tech-pill{font-size:9px;font-weight:700;padding:2px 7px;border-radius:20px;background:var(--purple-l);color:var(--purple);font-family:var(--fb);text-transform:uppercase;letter-spacing:.4px;}
.web-visit{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:var(--purple);transition:color .2s;}
.web-visit:hover{color:var(--accent);}

/* DOCUMENT list inside grid */
.doc-grid-item{background:var(--white);border:1.5px solid var(--g200);border-radius:var(--radius-sm);padding:14px;display:flex;flex-direction:column;gap:8px;transition:box-shadow .25s,border-color .25s;}
.doc-grid-item:hover{box-shadow:var(--shadow);border-color:rgba(3,45,93,.2);}
.doc-grid-ico{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
.doc-grid-title{font-family:var(--ff);font-size:13px;font-weight:700;color:var(--navy);line-height:1.3;}
.doc-grid-desc{font-size:11px;color:var(--g400);line-height:1.5;}
.doc-dl-btn{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:var(--navy);background:var(--navy-l);border-radius:8px;padding:6px 12px;transition:all .2s;align-self:flex-start;}
.doc-dl-btn:hover{background:var(--navy);color:white;}

/* empty */
.empty-state{text-align:center;padding:60px 20px;}
.empty-icon{width:80px;height:80px;border-radius:50%;background:var(--navy-l);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:30px;color:var(--navy);}
.empty-title{font-family:var(--ff);font-size:20px;font-weight:700;color:var(--navy);margin-bottom:8px;}
.empty-sub{font-size:14px;color:var(--g400);}

/* ═══════════════════════════════════════════
   TICKETS GRID
   — 2 cols on mobile, 2 cols on desktop
═══════════════════════════════════════════ */
.tickets-grid{
  display:grid;
  grid-template-columns:repeat(2,1fr);   /* ← 2 cols on ALL sizes */
  gap:12px;
  padding:16px;
}
@media(min-width:640px){.tickets-grid{gap:20px;padding:24px;}}
@media(min-width:900px){.tickets-grid{grid-template-columns:repeat(2,1fr);gap:24px;}}

.ticket-card{border-radius:var(--radius);overflow:hidden;border:1.5px solid var(--g100);background:var(--white);box-shadow:var(--shadow);transition:transform .25s,box-shadow .25s,border-color .25s;display:flex;flex-direction:column;}
.ticket-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-lg);border-color:rgba(184,18,54,.25);}

.ticket-banner{position:relative;aspect-ratio:16/9;overflow:hidden;background:var(--g100);}
.ticket-banner img{width:100%;height:100%;object-fit:cover;transition:transform .5s;}
.ticket-card:hover .ticket-banner img{transform:scale(1.06);}
.ticket-banner-grad{position:absolute;inset:0;background:linear-gradient(to top,rgba(3,45,93,.8) 0%,transparent 55%);}

.ticket-date-badge{
  position:absolute;top:8px;left:8px;
  background:rgba(3,45,93,.85);color:var(--gold);
  font-family:var(--fb);font-size:9px;font-weight:800;
  letter-spacing:.8px;text-transform:uppercase;
  padding:4px 10px;border-radius:20px;
  backdrop-filter:blur(6px);border:1px solid rgba(245,200,66,.25);
}
@media(min-width:640px){.ticket-date-badge{font-size:10px;padding:5px 12px;top:12px;left:12px;}}

.ticket-body{padding:12px 14px 14px;flex:1;display:flex;flex-direction:column;}
@media(min-width:640px){.ticket-body{padding:18px 20px 20px;}}

.ticket-title{font-family:var(--ff);font-size:14px;font-weight:700;color:var(--navy);line-height:1.3;margin-bottom:8px;}
@media(min-width:640px){.ticket-title{font-size:18px;}}

.ticket-meta{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;}
.ticket-meta-row{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--g600);}
@media(min-width:640px){.ticket-meta-row{font-size:13px;}}
.ticket-meta-row i{color:var(--navy-m);width:12px;font-size:10px;flex-shrink:0;}
@media(min-width:640px){.ticket-meta-row i{width:14px;font-size:12px;}}

.ticket-footer{display:flex;align-items:center;justify-content:space-between;margin-top:auto;padding-top:10px;border-top:1px solid var(--g100);gap:8px;}
@media(min-width:640px){.ticket-footer{padding-top:14px;}}

.ticket-price{font-family:var(--ff);font-size:16px;font-weight:900;color:var(--accent);flex-shrink:0;}
@media(min-width:640px){.ticket-price{font-size:22px;}}
.ticket-price.free{color:var(--green);}
.ticket-price.tbd{font-size:11px;color:var(--g400);font-family:var(--fb);}

.ticket-cta{display:inline-flex;align-items:center;gap:5px;padding:8px 12px;border-radius:50px;background:var(--accent);color:var(--white);font-size:11px;font-weight:700;transition:all .2s;white-space:nowrap;}
@media(min-width:640px){.ticket-cta{padding:10px 20px;font-size:13px;gap:7px;}}
.ticket-cta:hover{background:var(--accent-d);transform:translateY(-1px);box-shadow:0 6px 18px rgba(184,18,54,.3);}

/* ═══════════════════════════════════════════
   FOOTER
═══════════════════════════════════════════ */
.site-footer{background:var(--navy-d);color:rgba(255,255,255,.5);padding:24px 20px;text-align:center;font-size:13px;border-top:3px solid var(--gold);}
.site-footer a{color:var(--gold);font-weight:700;}
.site-footer strong{color:rgba(255,255,255,.85);}
</style>
</head>
<body>

<!-- ═══════════ HERO BANNER ═══════════ -->
<div class="hero-wrap">
  <div class="hero-banner">
    <?php if (!empty($creator['banner'])): ?>
      <img src="msanii/uploads/<?= htmlspecialchars($creator['banner']) ?>" class="hero-banner-img" alt="Banner">
    <?php endif; ?>
    <div class="hero-banner-grad"></div>
  </div>

  <div class="profile-area">
    <div class="profile-inner">
      <!-- Avatar -->
      <div class="avatar-ring">
        <img src="msanii/uploads/<?= htmlspecialchars($creator['profile_pic'] ?? 'default.png') ?>"
             alt="<?= htmlspecialchars($displayName) ?>"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
        <div class="avatar-fb" style="display:none"><?= $initial ?></div>
      </div>

      <!-- Name + actions -->
      <div class="profile-name-block">
        <h1 class="profile-name"><?= htmlspecialchars($displayName) ?></h1>
        <p class="profile-handle"><span>@<?= htmlspecialchars($creator['username']) ?></span></p>
        <div class="profile-actions">
          <a href="book.php?creator=<?= $creator_id ?>" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Book Me</a>
          <a href="join-tribe.php?creator=<?= $creator_id ?>" class="btn btn-accent"><i class="fas fa-fire"></i> Join My Tribe</a>
          <a href="support.php?creator=<?= $creator_id ?>" class="btn btn-navy"><i class="fas fa-heart"></i> Support Me</a>
          <a href="index.php" class="btn btn-dark"><i class="fas fa-home"></i> Home</a>
        </div>
      </div>
    </div>
  </div>
</div>


<!-- ═══════════ MAIN CONTENT ═══════════ -->
<div class="main-wrap">
  <div class="grid-layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><div class="card-title-icon"><i class="fas fa-user"></i></div>About</div>
        </div>
        <div class="card-body" style="padding-top:0">
          <p class="about-text"><?= nl2br(htmlspecialchars($creator['bio'] ?? "This creator hasn't added a bio yet.")) ?></p>
          <?php
          $hasSocials = !empty($creator['instagram'])||!empty($creator['twitter'])||!empty($creator['tiktok'])||!empty($creator['youtube']);
          if ($hasSocials): ?>
          <div class="divider"></div>
          <div class="social-row">
            <?php if (!empty($creator['instagram'])): ?><a href="<?= htmlspecialchars($creator['instagram']) ?>" target="_blank" class="social-btn ig" title="Instagram"><i class="fab fa-instagram"></i></a><?php endif; ?>
            <?php if (!empty($creator['twitter'])): ?><a href="<?= htmlspecialchars($creator['twitter']) ?>" target="_blank" class="social-btn tw" title="Twitter/X"><i class="fab fa-x-twitter"></i></a><?php endif; ?>
            <?php if (!empty($creator['tiktok'])): ?><a href="<?= htmlspecialchars($creator['tiktok']) ?>" target="_blank" class="social-btn tt" title="TikTok"><i class="fab fa-tiktok"></i></a><?php endif; ?>
            <?php if (!empty($creator['youtube'])): ?><a href="<?= htmlspecialchars($creator['youtube']) ?>" target="_blank" class="social-btn yt" title="YouTube"><i class="fab fa-youtube"></i></a><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($suggested)): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title"><div class="card-title-icon"><i class="fas fa-users"></i></div>More Creators</div>
        </div>
        <div class="card-body" style="padding-top:8px">
          <?php foreach ($suggested as $sug): ?>
          <a href="/<?= htmlspecialchars($sug['username']) ?>" class="sug-item">
            <img src="msanii/uploads/<?= htmlspecialchars($sug['profile_pic']) ?>" class="sug-avatar" alt="<?= htmlspecialchars($sug['full_name']) ?>" onerror="this.src='msanii/uploads/default.png'">
            <div>
              <div class="sug-name"><?= htmlspecialchars($sug['full_name']) ?></div>
              <div class="sug-handle">@<?= htmlspecialchars($sug['username']) ?></div>
            </div>
            <i class="fas fa-chevron-right" style="margin-left:auto;font-size:11px;color:var(--g400)"></i>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </aside>

    <!-- MAIN TABS -->
    <div>
      <div class="card" style="overflow:visible">

        <!-- Tab nav -->
        <div class="tabs-nav">
          <button class="tab-btn active" data-tab="portfolio">
            <span class="tab-icon"><i class="fas fa-images"></i></span>
            Portfolio
            <?php if ($totalPortfolio > 0): ?>
              <span class="tab-count-badge"><?= $totalPortfolio ?></span>
            <?php endif; ?>
          </button>
          <button class="tab-btn" data-tab="tickets">
            <span class="tab-icon"><i class="fas fa-ticket-alt"></i></span>
            Events
            <?php if (!empty($tickets)): ?>
              <span class="tab-count-badge"><?= count($tickets) ?></span>
            <?php endif; ?>
          </button>
        </div>

        <!-- ═══════ PORTFOLIO TAB ═══════ -->
        <div id="pane-portfolio" class="tab-pane active">
          <?php
          $hasPortfolio = $totalPortfolio > 0;
          ?>
          <?php if (!$hasPortfolio): ?>
            <div class="empty-state">
              <div class="empty-icon"><i class="fas fa-images"></i></div>
              <div class="empty-title">No portfolio items yet</div>
              <p class="empty-sub">Check back later — something amazing is coming!</p>
            </div>
          <?php else: ?>

            <!-- Category pills -->
            <div class="cat-pills">
              <button class="cat-pill active" data-cat="all"><i class="fas fa-th"></i> All</button>
              <?php if (!empty($portfolio['visual'])): ?>
                <button class="cat-pill" data-cat="visual"><i class="fas fa-image"></i> Visual Works</button>
              <?php endif; ?>
              <?php if (!empty($portfolio['video'])): ?>
                <button class="cat-pill" data-cat="video"><i class="fas fa-film"></i> Videos</button>
              <?php endif; ?>
              <?php if (!empty($portfolio['web'])): ?>
                <button class="cat-pill" data-cat="web"><i class="fas fa-globe"></i> Web Projects</button>
              <?php endif; ?>
              <?php if (!empty($portfolio['photography'])): ?>
                <button class="cat-pill" data-cat="photography"><i class="fas fa-camera"></i> Photography</button>
              <?php endif; ?>
              <?php if (!empty($portfolio['document'])): ?>
                <button class="cat-pill" data-cat="document"><i class="fas fa-file-lines"></i> Documents</button>
              <?php endif; ?>
            </div>

            <div class="portfolio-grid">

              <!-- ── VISUAL WORKS ── -->
              <?php foreach ($portfolio['visual'] as $item): ?>
                <div class="port-item" data-cat="visual" onclick="openLb('msanii/uploads/visual/<?= htmlspecialchars($item['file_path']) ?>')">
                  <img src="msanii/uploads/visual/<?= htmlspecialchars($item['file_path']) ?>"
                       alt="<?= htmlspecialchars($item['title'] ?? '') ?>" loading="lazy">
                  <div class="port-overlay">
                    <div class="port-overlay-text"><?= htmlspecialchars($item['title'] ?: 'Visual Work') ?></div>
                  </div>
                  <div class="port-type-badge"><i class="fas fa-image"></i> Visual</div>
                </div>
              <?php endforeach; ?>

              <!-- ── VIDEOS ── -->
              <?php foreach ($portfolio['video'] as $item): ?>
                <div class="port-item port-item-video" data-cat="video">
                  <iframe src="https://www.youtube.com/embed/<?= htmlspecialchars($item['youtube_id']) ?>"
                          title="<?= htmlspecialchars($item['title'] ?? 'Video') ?>"
                          frameborder="0"
                          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                          allowfullscreen loading="lazy"></iframe>
                  <div class="port-type-badge"><i class="fas fa-film"></i> Video</div>
                </div>
              <?php endforeach; ?>

              <!-- ── WEB PROJECTS ── -->
              <?php foreach ($portfolio['web'] as $item): ?>
                <a href="<?= htmlspecialchars($item['project_url'] ?? '#') ?>"
                   target="_blank" rel="noopener"
                   class="port-item port-item-web" data-cat="web">
                  <div class="web-thumb">
                    <?php if (!empty($item['file_path'])): ?>
                      <img src="msanii/uploads/web/<?= htmlspecialchars($item['file_path']) ?>"
                           alt="<?= htmlspecialchars($item['title'] ?? 'Web Project') ?>" loading="lazy">
                    <?php else: ?>
                      <div class="web-no-thumb"><i class="fas fa-globe"></i></div>
                    <?php endif; ?>
                  </div>
                  <div class="web-body">
                    <div class="web-item-title"><?= htmlspecialchars($item['title'] ?? 'Web Project') ?></div>
                    <?php if (!empty($item['tech_stack'])): ?>
                      <div class="web-tech-pills">
                        <?php foreach (array_slice(array_map('trim', explode(',', $item['tech_stack'])), 0, 4) as $tech): ?>
                          <?php if ($tech): ?><span class="web-tech-pill"><?= htmlspecialchars($tech) ?></span><?php endif; ?>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    <span class="web-visit"><i class="fas fa-arrow-up-right-from-square"></i> Visit Site</span>
                  </div>
                  <div class="port-type-badge"><i class="fas fa-globe"></i> Web</div>
                </a>
              <?php endforeach; ?>

              <!-- ── PHOTOGRAPHY ── -->
              <?php foreach ($portfolio['photography'] as $item): ?>
                <div class="port-item" data-cat="photography" onclick="openLb('msanii/uploads/photography/<?= htmlspecialchars($item['file_path']) ?>')">
                  <img src="msanii/uploads/photography/<?= htmlspecialchars($item['file_path']) ?>"
                       alt="<?= htmlspecialchars($item['title'] ?? '') ?>" loading="lazy">
                  <div class="port-overlay">
                    <div class="port-overlay-text"><?= htmlspecialchars($item['title'] ?: 'Photo') ?></div>
                  </div>
                  <div class="port-type-badge"><i class="fas fa-camera"></i> Photo</div>
                </div>
              <?php endforeach; ?>

              <!-- ── DOCUMENTS ── -->
              <?php
              $docIcons = [
                'pdf'=>['fa-file-pdf','#ef4444'],'doc'=>['fa-file-word','#2563eb'],'docx'=>['fa-file-word','#2563eb'],
                'ppt'=>['fa-file-powerpoint','#ea580c'],'pptx'=>['fa-file-powerpoint','#ea580c'],
                'xls'=>['fa-file-excel','#16a34a'],'xlsx'=>['fa-file-excel','#16a34a'],
              ];
              foreach ($portfolio['document'] as $item):
                $ext = strtolower(pathinfo($item['file_path'] ?? '', PATHINFO_EXTENSION));
                [$dIco,$dColor] = $docIcons[$ext] ?? ['fa-file-lines','var(--navy)'];
              ?>
                <div class="doc-grid-item" data-cat="document">
                  <div class="doc-grid-ico" style="background:<?= $dColor ?>18;color:<?= $dColor ?>">
                    <i class="fa-solid <?= $dIco ?>"></i>
                  </div>
                  <div class="doc-grid-title"><?= htmlspecialchars($item['title'] ?? 'Document') ?></div>
                  <?php if (!empty($item['description'])): ?>
                    <div class="doc-grid-desc"><?= htmlspecialchars($item['description']) ?></div>
                  <?php endif; ?>
                  <a href="msanii/uploads/documents/<?= htmlspecialchars($item['file_path']) ?>"
                     class="doc-dl-btn" download>
                    <i class="fas fa-arrow-down"></i> Download <?= strtoupper($ext) ?>
                  </a>
                </div>
              <?php endforeach; ?>

            </div><!-- /portfolio-grid -->
          <?php endif; ?>
        </div><!-- /portfolio pane -->

        <!-- ═══════ TICKETS TAB ═══════ -->
        <div id="pane-tickets" class="tab-pane">
          <?php if (!empty($tickets)): ?>
            <div class="tickets-grid">
              <?php foreach ($tickets as $t):
                $min   = (float)($t['min_price'] ?? 0);
                $hasTk = (int)$t['ticket_type_count'] > 0;
                if (!$hasTk) {
                  $priceHtml='Tickets soon'; $priceClass='tbd'; $ctaTxt='Coming soon'; $ctaHref='#';
                } elseif ($min==0) {
                  $priceHtml='Free'; $priceClass='free'; $ctaTxt='Reserve Free'; $ctaHref="ticket.php?id={$t['id']}";
                } else {
                  $priceHtml='KES '.number_format($min); $priceClass=''; $ctaTxt='Get Ticket'; $ctaHref="ticket.php?id={$t['id']}";
                }
                $dateStr = !empty($t['start_date']) ? date('d M Y', strtotime($t['start_date'])) : 'TBD';
                $timeStr = !empty($t['start_time']) ? date('g:i A', strtotime($t['start_time'])) : '';
              ?>
              <a href="<?= $ctaHref ?>" class="ticket-card">
                <div class="ticket-banner">
                  <img src="msanii/uploads/banners/<?= htmlspecialchars($t['banner'] ?? 'default-event.jpg') ?>"
                       alt="<?= htmlspecialchars($t['title']) ?>"
                       onerror="this.src='https://via.placeholder.com/600x338/032d5d/ffffff?text=Event'">
                  <div class="ticket-banner-grad"></div>
                  <div class="ticket-date-badge"><i class="fas fa-calendar-alt"></i> <?= $dateStr ?></div>
                </div>
                <div class="ticket-body">
                  <h3 class="ticket-title"><?= htmlspecialchars($t['title']) ?></h3>
                  <div class="ticket-meta">
                    <div class="ticket-meta-row">
                      <i class="fas fa-map-marker-alt"></i>
                      <span><?= htmlspecialchars($t['venue'] ?? 'Location TBD') ?></span>
                    </div>
                    <?php if ($timeStr): ?>
                    <div class="ticket-meta-row">
                      <i class="fas fa-clock"></i>
                      <span><?= $timeStr ?></span>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div class="ticket-footer">
                    <div class="ticket-price <?= $priceClass ?>"><?= $priceHtml ?></div>
                    <?php if ($ctaHref !== '#'): ?>
                      <span class="ticket-cta"><i class="fas fa-ticket-alt"></i> <?= $ctaTxt ?></span>
                    <?php else: ?>
                      <span style="font-size:11px;color:var(--g400);font-weight:600"><i class="fas fa-clock"></i> Soon</span>
                    <?php endif; ?>
                  </div>
                </div>
              </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="empty-state">
              <div class="empty-icon"><i class="fas fa-ticket-alt"></i></div>
              <div class="empty-title">No events yet</div>
              <p class="empty-sub">This creator is preparing something — stay tuned!</p>
            </div>
          <?php endif; ?>
        </div><!-- /tickets pane -->

      </div><!-- /card -->
    </div><!-- /main col -->

  </div><!-- /grid-layout -->
</div><!-- /main-wrap -->

<!-- LIGHTBOX -->
<div id="lightbox" onclick="closeLb(event)" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(2,20,50,.92);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:1rem;">
  <button onclick="closeLb()" style="position:absolute;top:1.25rem;right:1.25rem;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.12);border:none;color:white;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">
    <i class="fas fa-xmark"></i>
  </button>
  <img id="lbImg" src="" alt="" style="max-width:92vw;max-height:88vh;border-radius:12px;box-shadow:0 24px 80px rgba(0,0,0,.5)">
</div>

<footer class="site-footer">
  <p>© <strong>Keychele</strong> <?= date('Y') ?> · Creator profile for <strong>@<?= htmlspecialchars($username) ?></strong> · <a href="index.php">Visit main site</a></p>
</footer>

<script>
// Tabs
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const target = btn.dataset.tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('pane-' + target).classList.add('active');
  });
});

// Portfolio filter
document.querySelectorAll('.cat-pill').forEach(pill => {
  pill.addEventListener('click', () => {
    const cat = pill.dataset.cat;
    document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    document.querySelectorAll('.portfolio-grid > *[data-cat]').forEach(item => {
      item.style.display = (cat === 'all' || item.dataset.cat === cat) ? '' : 'none';
    });
  });
});

// Lightbox
function openLb(src) {
  document.getElementById('lbImg').src = src;
  const lb = document.getElementById('lightbox');
  lb.style.display = 'flex';
}
function closeLb(e) {
  if (!e || e.target === document.getElementById('lightbox') || e.currentTarget.tagName === 'BUTTON') {
    document.getElementById('lightbox').style.display = 'none';
    document.getElementById('lbImg').src = '';
  }
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLb(); });
</script>
</body>
</html>