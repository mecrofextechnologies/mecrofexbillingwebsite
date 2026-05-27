<?php
include 'includes/connect.php';
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) die("Please login first.");

// ── Auto-create tables if needed ──────────────────────────
$pdo->exec("
CREATE TABLE IF NOT EXISTS creator_games (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    creator_id      INT UNSIGNED NOT NULL,
    title           VARCHAR(150) NOT NULL,
    description     TEXT,
    category        VARCHAR(60)  DEFAULT 'General',
    visibility      ENUM('public','private') DEFAULT 'public',
    cover           VARCHAR(255),
    questions_json  LONGTEXT     NOT NULL DEFAULT '[]',
    game_pin        CHAR(6)      UNIQUE,
    status          ENUM('draft','live','ended') DEFAULT 'draft',
    play_count      INT UNSIGNED DEFAULT 0,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_creator (creator_id),
    INDEX idx_pin     (game_pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS game_sessions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id      INT UNSIGNED NOT NULL,
    player_name  VARCHAR(80)  NOT NULL,
    score        INT          DEFAULT 0,
    answers_json TEXT,
    joined_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Handle Actions ────────────────────────────────────────
$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$game_id = (int)($_POST['game_id'] ?? $_GET['game_id'] ?? 0);
$msg     = $err = '';

if ($action === 'delete' && $game_id) {
    $chk = $pdo->prepare("SELECT id FROM creator_games WHERE id = ? AND creator_id = ?");
    $chk->execute([$game_id, $creator_id]);
    if ($chk->fetchColumn()) {
        $pdo->prepare("DELETE FROM game_sessions WHERE game_id = ?")->execute([$game_id]);
        $pdo->prepare("DELETE FROM creator_games WHERE id = ? AND creator_id = ?")->execute([$game_id, $creator_id]);
        $msg = "Game deleted successfully.";
    } else {
        $err = "Game not found or permission denied.";
    }
}

if ($action === 'toggle_status' && $game_id) {
    $chk = $pdo->prepare("SELECT id, status FROM creator_games WHERE id = ? AND creator_id = ?");
    $chk->execute([$game_id, $creator_id]);
    $g = $chk->fetch(PDO::FETCH_ASSOC);
    if ($g) {
        $newStatus = $g['status'] === 'live' ? 'ended' : 'live';
        $pdo->prepare("UPDATE creator_games SET status = ? WHERE id = ?")->execute([$newStatus, $game_id]);
        $msg = "Game status updated to " . ucfirst($newStatus) . ".";
    }
}

if ($action === 'regen_pin' && $game_id) {
    $chk = $pdo->prepare("SELECT id FROM creator_games WHERE id = ? AND creator_id = ?");
    $chk->execute([$game_id, $creator_id]);
    if ($chk->fetchColumn()) {
        $attempts = 0;
        do {
            $pin = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $p   = $pdo->prepare("SELECT id FROM creator_games WHERE game_pin = ? LIMIT 1");
            $p->execute([$pin]);
            $attempts++;
        } while ($p->fetchColumn() && $attempts < 20);
        $pdo->prepare("UPDATE creator_games SET game_pin = ? WHERE id = ?")->execute([$pin, $game_id]);
        $msg = "New PIN generated: <strong>$pin</strong>";
    }
}

// ── Fetch games ───────────────────────────────────────────
$perPage = 9;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$filter  = $_GET['filter'] ?? 'all';
$search  = trim($_GET['q'] ?? '');

$where   = "g.creator_id = ?";
$params  = [$creator_id];

if ($filter === 'live')   { $where .= " AND g.status = 'live'";   }
if ($filter === 'ended')  { $where .= " AND g.status = 'ended'";  }
if ($filter === 'draft')  { $where .= " AND g.status = 'draft'";  }
if ($search) {
    $where   .= " AND g.title LIKE ?";
    $params[] = "%$search%";
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM creator_games g WHERE $where");
$totalStmt->execute($params);
$total      = (int)$totalStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$gamesStmt = $pdo->prepare("
    SELECT g.*,
           COUNT(DISTINCT gs.id) AS player_count,
           MAX(gs.score)         AS top_score,
           AVG(gs.score)         AS avg_score
    FROM creator_games g
    LEFT JOIN game_sessions gs ON gs.game_id = g.id
    WHERE $where
    GROUP BY g.id
    ORDER BY g.created_at DESC
    LIMIT ? OFFSET ?
");
foreach ($params as $i => $val) {
    $gamesStmt->bindValue($i + 1, $val);
}
$gamesStmt->bindValue(count($params) + 1, (int)$perPage, PDO::PARAM_INT);
$gamesStmt->bindValue(count($params) + 2, (int)$offset,  PDO::PARAM_INT);
$gamesStmt->execute();
$games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Summary stats ─────────────────────────────────────────
$stats = $pdo->prepare("
    SELECT
        COUNT(*)                                              AS total_games,
        SUM(CASE WHEN status = 'live'  THEN 1 ELSE 0 END)   AS live_games,
        SUM(play_count)                                      AS total_plays,
        (SELECT COUNT(*) FROM game_sessions gs2 JOIN creator_games cg ON gs2.game_id = cg.id WHERE cg.creator_id = ?) AS total_players
    FROM creator_games WHERE creator_id = ?
");
$stats->execute([$creator_id, $creator_id]);
$s = $stats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Games — Keychele</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

  <style>
  @font-face {
    font-family: 'GlacialI';
    src: url('https://db.onlinewebfonts.com/t/74f7b46c5a6a4374c10b2cb3ef8b4fc1.woff2') format('woff2');
  }

  :root {
    --red:    #bf1339;
    --red-l:  #e8143f;
    --gold:   #fec411;
    --gold-d: #d4a60a;
    --navy:   #052e64;
    --navy-l: #0a4494;
    --bg:     #eef1f8;
    --card:   #ffffff;
    --border: rgba(5,46,100,0.1);
    --text:   #0f1923;
    --muted:  #6b7a99;
    --green:  #16a34a;
    --green-l:#dcfce7;
    --r:      14px;
    --r-lg:   20px;
    --sh:     0 4px 24px rgba(5,46,100,0.09);
    --sh-lg:  0 12px 48px rgba(5,46,100,0.13);
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'GlacialI','Trebuchet MS',sans-serif; background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; padding-bottom: 80px; }
  h1,h2,h3,h4,h5,.mf { font-family: 'Montserrat', sans-serif; }

  .page { padding: 1.5rem; max-width: 1100px; margin: 0 auto; }
  @media(max-width:600px){ .page{ padding:1rem .9rem; } }

  /* ── HERO ── */
  .page-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(120deg, var(--navy) 0%, #0a3a7a 55%, #0f4faa 100%);
    border-radius: var(--r-lg); padding: 2rem 2.25rem;
    margin-bottom: 1.75rem;
    box-shadow: var(--sh-lg), 0 0 0 1px rgba(255,255,255,0.05) inset;
    animation: heroIn 0.55s cubic-bezier(0.16,1,0.3,1) both;
  }
  @keyframes heroIn { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
  .page-hero::before {
    content:''; position:absolute; inset:0; pointer-events:none;
    background-image: linear-gradient(rgba(255,255,255,.03)1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.03)1px,transparent 1px);
    background-size:34px 34px;
  }
  .hero-orb1 { position:absolute;border-radius:50%;pointer-events:none;width:280px;height:280px;top:-100px;right:-60px;background:radial-gradient(circle,rgba(254,196,17,.16)0%,transparent 65%); }
  .hero-orb2 { position:absolute;border-radius:50%;pointer-events:none;width:180px;height:180px;bottom:-70px;left:25%;background:radial-gradient(circle,rgba(191,19,57,.18)0%,transparent 65%); }
  .hero-inner { position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:1rem; }
  .hero-tag {
    display:inline-flex;align-items:center;gap:6px;
    background:rgba(254,196,17,.14);border:1px solid rgba(254,196,17,.28);border-radius:100px;
    padding:4px 12px;font-family:'Montserrat',sans-serif;font-size:.65rem;font-weight:700;
    letter-spacing:.09em;text-transform:uppercase;color:var(--gold);margin-bottom:.7rem;
  }
  .hero-title { font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(1.4rem,3vw,2rem);color:white;letter-spacing:-.04em;line-height:1.15;margin-bottom:.4rem; }
  .hero-sub { font-size:.83rem;color:rgba(255,255,255,.55); }
  .hero-icon { font-size:3.5rem;color:rgba(255,255,255,.12);flex-shrink:0; }
  @media(max-width:480px){ .hero-icon{display:none} }

  /* ── STATS ROW ── */
  .stats-row { display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.75rem; }
  @media(max-width:700px){ .stats-row{grid-template-columns:repeat(2,1fr)} }
  @media(max-width:400px){ .stats-row{grid-template-columns:1fr 1fr} }

  .stat-card {
    background:var(--card);border-radius:var(--r);border:1px solid var(--border);
    box-shadow:var(--sh);padding:1.1rem 1.25rem;
    display:flex;align-items:center;gap:.9rem;
    animation:cardIn .5s cubic-bezier(0.16,1,0.3,1) both;
  }
  .stat-card:nth-child(1){animation-delay:.04s} .stat-card:nth-child(2){animation-delay:.08s}
  .stat-card:nth-child(3){animation-delay:.12s} .stat-card:nth-child(4){animation-delay:.16s}
  @keyframes cardIn { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }

  .stat-ico {
    width:42px;height:42px;border-radius:11px;
    display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0;
  }
  .stat-val { font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.5rem;line-height:1;color:var(--navy); }
  .stat-lbl { font-size:.7rem;color:var(--muted);margin-top:3px;font-weight:600; }

  /* ── TOOLBAR ── */
  .toolbar {
    background:var(--card);border-radius:var(--r-lg);border:1px solid var(--border);
    box-shadow:var(--sh);padding:1rem 1.25rem;
    display:flex;align-items:center;gap:.85rem;flex-wrap:wrap;
    margin-bottom:1.5rem;
  }
  .toolbar-title { font-family:'Montserrat',sans-serif;font-weight:800;font-size:.95rem;color:var(--navy);flex:1;min-width:0; }

  .filter-pills { display:flex;gap:.4rem;flex-wrap:wrap; }
  .filter-pill {
    padding:6px 14px;border-radius:100px;
    font-family:'Montserrat',sans-serif;font-weight:700;font-size:.72rem;
    border:1.5px solid var(--border);background:#fafafa;color:var(--muted);
    cursor:pointer;transition:all .18s;text-decoration:none;
  }
  .filter-pill:hover { border-color:var(--navy);color:var(--navy);background:rgba(5,46,100,.05); }
  .filter-pill.active { background:var(--navy);border-color:var(--navy);color:white; }
  .filter-pill .pill-count { background:rgba(255,255,255,.25);border-radius:100px;padding:1px 6px;font-size:.65rem;margin-left:4px; }
  .filter-pill:not(.active) .pill-count { background:var(--bg);color:var(--muted); }

  .search-wrap { position:relative;min-width:200px; }
  .search-wrap input {
    width:100%;padding:9px 38px 9px 14px;
    border:1.5px solid var(--border);border-radius:100px;
    font-family:'GlacialI',sans-serif;font-size:.83rem;color:var(--text);
    background:#fafafa;outline:none;transition:border-color .2s,box-shadow .2s;
  }
  .search-wrap input:focus { border-color:var(--navy);box-shadow:0 0 0 3px rgba(5,46,100,.08);background:white; }
  .search-wrap input::placeholder { color:var(--muted); }
  .search-ico { position:absolute;right:13px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.75rem;pointer-events:none; }

  .new-game-btn {
    display:flex;align-items:center;gap:7px;
    padding:9px 18px;border-radius:var(--r);
    background:linear-gradient(135deg,var(--red),var(--red-l));
    color:white;font-family:'Montserrat',sans-serif;font-weight:800;font-size:.78rem;
    border:none;cursor:pointer;text-decoration:none;
    box-shadow:0 4px 16px rgba(191,19,57,.3);
    transition:transform .18s,box-shadow .18s;white-space:nowrap;
  }
  .new-game-btn:hover { transform:translateY(-2px);box-shadow:0 7px 22px rgba(191,19,57,.45);color:white; }

  /* ── GAMES GRID ── */
  .games-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.1rem; }

  .game-card {
    background:var(--card);border-radius:var(--r-lg);border:1px solid var(--border);
    box-shadow:var(--sh);overflow:hidden;
    display:flex;flex-direction:column;
    transition:transform .22s,box-shadow .22s,border-color .22s;
    animation:cardIn .5s cubic-bezier(0.16,1,0.3,1) both;
  }
  .game-card:hover { transform:translateY(-5px);box-shadow:var(--sh-lg);border-color:rgba(5,46,100,.18); }

  /* Cover */
  .gc-cover {
    position:relative;height:130px;
    background:linear-gradient(135deg,#1a0a2e 0%,#0d1a3a 50%,#0a1520 100%);
    overflow:hidden;flex-shrink:0;
  }
  .gc-cover-img { width:100%;height:100%;object-fit:cover;opacity:.6; }
  .gc-cover-grid {
    position:absolute;inset:0;pointer-events:none;opacity:.04;
    background-image:linear-gradient(rgba(255,255,255,.8)1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.8)1px,transparent 1px);
    background-size:28px 28px;
  }
  .gc-cover-orb { position:absolute;border-radius:50%;pointer-events:none;width:160px;height:160px;top:-60px;right:-40px;background:radial-gradient(circle,rgba(254,196,17,.2)0%,transparent 65%); }

  .gc-status-badge {
    position:absolute;top:10px;left:10px;
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 10px;border-radius:100px;
    font-family:'Montserrat',sans-serif;font-weight:800;font-size:.62rem;
    letter-spacing:.06em;text-transform:uppercase;
  }
  .gc-status-badge.live  { background:rgba(22,163,74,.9);color:white; }
  .gc-status-badge.ended { background:rgba(107,122,153,.85);color:white; }
  .gc-status-badge.draft { background:rgba(254,196,17,.9);color:#1a1a1a; }
  .gc-status-dot { width:6px;height:6px;border-radius:50%; }
  .gc-status-badge.live .gc-status-dot { background:#7affb8;animation:blink 1.2s ease-in-out infinite; }
  @keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
  .gc-status-badge.ended .gc-status-dot { background:rgba(255,255,255,.6); }
  .gc-status-badge.draft .gc-status-dot { background:#b8860b; }

  .gc-pin-badge {
    position:absolute;top:10px;right:10px;
    background:rgba(5,46,100,.85);border:1px solid rgba(254,196,17,.3);
    border-radius:8px;padding:4px 10px;
    font-family:'Montserrat',sans-serif;font-weight:900;font-size:.78rem;
    color:var(--gold);letter-spacing:.15em;
    cursor:pointer;transition:background .18s;
    backdrop-filter:blur(6px);
  }
  .gc-pin-badge:hover { background:rgba(5,46,100,.95); }
  .gc-pin-badge.no-pin { color:rgba(255,255,255,.35);letter-spacing:.04em;font-size:.65rem; }

  .gc-cat-badge {
    position:absolute;bottom:10px;right:10px;
    background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);
    border-radius:100px;padding:3px 10px;
    font-family:'Montserrat',sans-serif;font-weight:700;font-size:.6rem;
    color:rgba(255,255,255,.7);letter-spacing:.05em;
    backdrop-filter:blur(4px);
  }

  /* Body */
  .gc-body { padding:1.1rem 1.25rem;flex:1;display:flex;flex-direction:column;gap:.7rem; }

  .gc-title { font-family:'Montserrat',sans-serif;font-weight:800;font-size:1rem;color:var(--text);line-height:1.3; }

  .gc-desc { font-size:.78rem;color:var(--muted);line-height:1.6;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }

  .gc-meta-row { display:flex;gap:.5rem;flex-wrap:wrap; }
  .gc-meta-tag {
    display:inline-flex;align-items:center;gap:4px;
    padding:3px 9px;border-radius:100px;
    background:#f0f4ff;border:1px solid rgba(5,46,100,.1);
    font-family:'Montserrat',sans-serif;font-size:.65rem;font-weight:700;color:var(--navy);
  }
  .gc-meta-tag i { font-size:.6rem; }

  .gc-stats { display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem; }
  .gc-stat {
    background:#fafbfd;border:1px solid var(--border);
    border-radius:9px;padding:8px 6px;text-align:center;
  }
  .gc-stat-val { font-family:'Montserrat',sans-serif;font-weight:900;font-size:1rem;color:var(--navy);line-height:1; }
  .gc-stat-lbl { font-size:.6rem;color:var(--muted);margin-top:2px;font-weight:600;text-transform:uppercase;letter-spacing:.06em; }

  /* Footer */
  .gc-footer { padding:.85rem 1.25rem;border-top:1px solid var(--border);display:flex;gap:.5rem;align-items:center; }

  .gc-action-btn {
    display:flex;align-items:center;gap:6px;
    padding:8px 14px;border-radius:9px;
    font-family:'Montserrat',sans-serif;font-weight:700;font-size:.75rem;
    border:none;cursor:pointer;transition:all .18s;text-decoration:none;
    white-space:nowrap;
  }
  .gc-action-btn:hover { transform:translateY(-1px); }
  .gc-btn-edit   { background:rgba(5,46,100,.08);border:1.5px solid rgba(5,46,100,.14);color:var(--navy); }
  .gc-btn-edit:hover { background:rgba(5,46,100,.14); }
  .gc-btn-toggle-live  { background:rgba(22,163,74,.1);border:1.5px solid rgba(22,163,74,.2);color:var(--green); }
  .gc-btn-toggle-live:hover { background:rgba(22,163,74,.18); }
  .gc-btn-toggle-end   { background:rgba(107,122,153,.08);border:1.5px solid var(--border);color:var(--muted); }
  .gc-btn-toggle-end:hover { background:rgba(107,122,153,.15); }
  .gc-btn-delete { background:rgba(191,19,57,.07);border:1.5px solid rgba(191,19,57,.15);color:var(--red); margin-left:auto; }
  .gc-btn-delete:hover { background:rgba(191,19,57,.15); }
  .gc-btn-pin { background:rgba(254,196,17,.1);border:1.5px solid rgba(254,196,17,.25);color:var(--gold-d); }
  .gc-btn-pin:hover { background:rgba(254,196,17,.2); }

  /* ── EMPTY STATE ── */
  .empty-state {
    grid-column:1/-1;padding:4rem 2rem;text-align:center;
  }
  .empty-icon {
    width:80px;height:80px;border-radius:22px;
    background:linear-gradient(135deg,rgba(5,46,100,.08),rgba(5,46,100,.04));
    border:2px dashed rgba(5,46,100,.15);
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 1.25rem;font-size:2rem;color:rgba(5,46,100,.3);
  }
  .empty-title { font-family:'Montserrat',sans-serif;font-weight:800;font-size:1.15rem;color:var(--text);margin-bottom:.5rem; }
  .empty-sub { font-size:.85rem;color:var(--muted);margin-bottom:1.5rem;line-height:1.6; }
  .empty-cta {
    display:inline-flex;align-items:center;gap:8px;
    padding:12px 24px;border-radius:var(--r);
    background:linear-gradient(135deg,var(--red),var(--red-l));
    color:white;font-family:'Montserrat',sans-serif;font-weight:800;font-size:.85rem;
    text-decoration:none;box-shadow:0 4px 16px rgba(191,19,57,.3);
    transition:transform .18s,box-shadow .18s;
  }
  .empty-cta:hover { transform:translateY(-2px);box-shadow:0 8px 24px rgba(191,19,57,.45);color:white; }

  /* ── PAGINATION ── */
  .cr-pagination { display:flex;align-items:center;justify-content:center;gap:5px;flex-wrap:wrap;margin-top:2rem; }
  .cr-page-btn {
    display:inline-flex;align-items:center;justify-content:center;
    min-width:38px;height:38px;padding:0 9px;border-radius:9px;
    font-family:'Montserrat',sans-serif;font-size:.8rem;font-weight:700;
    color:var(--navy);background:var(--card);border:1.5px solid var(--border);
    text-decoration:none;transition:all .18s;
  }
  .cr-page-btn:hover { background:rgba(5,46,100,.07);border-color:var(--navy); }
  .cr-page-btn.active { background:var(--navy);border-color:var(--navy);color:white;box-shadow:0 3px 12px rgba(5,46,100,.28); }
  .cr-page-btn.disabled { opacity:.32;pointer-events:none; }
  .cr-page-ellipsis { font-size:.85rem;color:var(--muted);padding:0 3px; }

  /* ── MODAL ── */
  .modal-overlay {
    position:fixed;inset:0;z-index:200;
    background:rgba(5,10,30,.7);backdrop-filter:blur(6px);
    display:flex;align-items:center;justify-content:center;padding:1.5rem;
    opacity:0;pointer-events:none;transition:opacity .25s;
  }
  .modal-overlay.open { opacity:1;pointer-events:all; }
  .modal-box {
    background:white;border-radius:var(--r-lg);width:100%;max-width:420px;overflow:hidden;
    box-shadow:var(--sh-lg);
    transform:scale(.9);transition:transform .3s cubic-bezier(0.16,1,0.3,1);
  }
  .modal-overlay.open .modal-box { transform:scale(1); }
  .modal-head {
    padding:1.5rem;border-bottom:1px solid var(--border);
    display:flex;align-items:center;gap:.85rem;
  }
  .modal-head-ico {
    width:40px;height:40px;border-radius:11px;
    background:rgba(191,19,57,.1);border:1px solid rgba(191,19,57,.2);
    display:flex;align-items:center;justify-content:center;
    color:var(--red);font-size:.9rem;flex-shrink:0;
  }
  .modal-head-title { font-family:'Montserrat',sans-serif;font-weight:800;font-size:.95rem;color:var(--text); }
  .modal-head-sub { font-size:.75rem;color:var(--muted);margin-top:2px; }
  .modal-body { padding:1.25rem 1.5rem; }
  .modal-body p { font-size:.85rem;color:var(--muted);line-height:1.65; }
  .modal-body strong { color:var(--text); }
  .modal-footer { padding:.85rem 1.5rem;border-top:1px solid var(--border);display:flex;gap:.6rem;justify-content:flex-end; }
  .modal-btn {
    padding:9px 20px;border-radius:9px;
    font-family:'Montserrat',sans-serif;font-weight:800;font-size:.8rem;
    border:none;cursor:pointer;transition:all .18s;
  }
  .modal-btn:hover { transform:translateY(-1px); }
  .modal-btn-cancel { background:#f0f4ff;color:var(--navy); }
  .modal-btn-danger { background:linear-gradient(135deg,var(--red),var(--red-l));color:white;box-shadow:0 3px 12px rgba(191,19,57,.3); }

  /* ── PIN modal ── */
  .pin-modal-overlay {
    position:fixed;inset:0;z-index:200;
    background:rgba(5,10,30,.8);backdrop-filter:blur(8px);
    display:flex;align-items:center;justify-content:center;padding:1.5rem;
    opacity:0;pointer-events:none;transition:opacity .25s;
  }
  .pin-modal-overlay.open { opacity:1;pointer-events:all; }
  .pin-modal-box {
    background:linear-gradient(145deg,#0d1f3c,#0a2a5c);
    border:1px solid rgba(254,196,17,.2);
    border-radius:var(--r-lg);width:100%;max-width:360px;
    padding:2rem;text-align:center;
    box-shadow:0 32px 80px rgba(0,0,0,.55);
    transform:scale(.88);transition:transform .35s cubic-bezier(0.16,1,0.3,1);
  }
  .pin-modal-overlay.open .pin-modal-box { transform:scale(1); }
  .pm-icon { font-size:2.5rem;color:var(--gold);margin-bottom:.75rem; }
  .pm-title { font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.1rem;color:white;margin-bottom:.3rem; }
  .pm-sub { font-size:.75rem;color:rgba(255,255,255,.45);margin-bottom:1.25rem; }
  .pm-pin {
    font-family:'Montserrat',sans-serif;font-weight:900;
    font-size:3rem;letter-spacing:.25em;color:var(--gold);line-height:1;
    text-shadow:0 0 40px rgba(254,196,17,.4);margin-bottom:1.1rem;
    animation:pinPulse 2s ease-in-out infinite;
  }
  @keyframes pinPulse{0%,100%{text-shadow:0 0 30px rgba(254,196,17,.3)}50%{text-shadow:0 0 60px rgba(254,196,17,.6)}}
  .pm-copy {
    display:inline-flex;align-items:center;gap:6px;
    padding:8px 20px;border-radius:100px;
    background:rgba(254,196,17,.12);border:1.5px solid rgba(254,196,17,.3);
    color:var(--gold);font-family:'Montserrat',sans-serif;font-weight:700;font-size:.8rem;
    cursor:pointer;transition:all .18s;margin-bottom:.75rem;
  }
  .pm-copy:hover { background:rgba(254,196,17,.22); }
  .pm-copy.copied { background:rgba(0,179,126,.15);border-color:rgba(0,179,126,.4);color:#2ecc9a; }
  .pm-close {
    display:block;width:100%;padding:10px;border-radius:10px;
    background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);
    color:rgba(255,255,255,.6);font-family:'Montserrat',sans-serif;font-weight:700;font-size:.8rem;
    cursor:pointer;transition:background .18s;
  }
  .pm-close:hover { background:rgba(255,255,255,.14); }

  /* ── TOASTR ── */
  #toast-container>.toast { border-radius:11px!important;box-shadow:0 8px 32px rgba(0,0,0,.14)!important;font-family:'GlacialI',sans-serif!important;font-size:.875rem!important;padding:14px 18px 14px 54px!important; }
  #toast-container>.toast-success { background-color:#064E3B!important;border-left:4px solid #10B981!important; }
  #toast-container>.toast-error   { background-color:#7F1D1D!important;border-left:4px solid #EF4444!important; }
  #toast-container>.toast-info    { background-color:var(--navy)!important;border-left:4px solid var(--gold)!important; }
  #toast-container>.toast-warning { background-color:#78350F!important;border-left:4px solid #F59E0B!important; }

  @keyframes spin{to{transform:rotate(360deg)}}
  .spinner{animation:spin .8s linear infinite}

  /* ── Leaderboard drawer ── */
  .lb-drawer-overlay {
    position:fixed;inset:0;z-index:200;
    background:rgba(5,10,30,.7);backdrop-filter:blur(6px);
    opacity:0;pointer-events:none;transition:opacity .25s;
  }
  .lb-drawer-overlay.open { opacity:1;pointer-events:all; }
  .lb-drawer {
    position:fixed;right:0;top:0;bottom:0;z-index:201;
    width:100%;max-width:420px;
    background:white;box-shadow:-8px 0 48px rgba(5,46,100,.18);
    display:flex;flex-direction:column;overflow:hidden;
    transform:translateX(100%);transition:transform .3s cubic-bezier(0.16,1,0.3,1);
  }
  .lb-drawer-overlay.open .lb-drawer { transform:translateX(0); }
  .lb-drawer-head {
    padding:1.25rem 1.5rem;background:linear-gradient(135deg,var(--navy),var(--navy-l));
    display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
  }
  .lb-drawer-title { font-family:'Montserrat',sans-serif;font-weight:900;font-size:1rem;color:white; }
  .lb-drawer-sub { font-size:.72rem;color:rgba(255,255,255,.45);margin-top:2px; }
  .lb-drawer-close {
    width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,.12);
    border:none;color:white;font-size:.8rem;cursor:pointer;display:flex;align-items:center;justify-content:center;
  }
  .lb-drawer-body { flex:1;overflow-y:auto;padding:1.25rem; }
  .lb-row {
    display:flex;align-items:center;gap:10px;
    padding:10px 12px;border-radius:11px;margin-bottom:.5rem;
    background:#fafbfd;border:1px solid var(--border);
  }
  .lb-row.top1 { background:rgba(254,196,17,.1);border-color:rgba(254,196,17,.3); }
  .lb-row.top2 { background:rgba(192,192,192,.1);border-color:rgba(192,192,192,.25); }
  .lb-row.top3 { background:rgba(205,127,50,.1);border-color:rgba(205,127,50,.25); }
  .lb-rank {
    width:28px;height:28px;border-radius:8px;background:rgba(5,46,100,.07);
    font-family:'Montserrat',sans-serif;font-weight:900;font-size:.78rem;color:var(--muted);
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
  }
  .lb-row.top1 .lb-rank{background:var(--gold);color:var(--navy)}
  .lb-row.top2 .lb-rank{background:rgba(192,192,192,.8);color:#111}
  .lb-row.top3 .lb-rank{background:rgba(205,127,50,.8);color:white}
  .lb-name { flex:1;font-weight:700;font-size:.88rem;color:var(--text); }
  .lb-score { font-family:'Montserrat',sans-serif;font-weight:900;font-size:.9rem;color:var(--navy); }
  .lb-row.top1 .lb-score { color:var(--gold-d); }
  .lb-empty { text-align:center;padding:2rem;font-size:.83rem;color:var(--muted); }
  </style>
</head>
<body>

<div class="page">

  <!-- HERO -->
  <div class="page-hero">
    <div class="hero-orb1"></div><div class="hero-orb2"></div>
    <div class="hero-inner">
      <div>
        <div class="hero-tag"><i class="fa-solid fa-gamepad"></i> Game Manager</div>
        <h1 class="hero-title">My Games</h1>
        <p class="hero-sub">Create, manage and share your Kahoot-style quiz games</p>
      </div>
      <div class="hero-icon"><i class="fa-solid fa-dice"></i></div>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-ico" style="background:rgba(5,46,100,.08);color:var(--navy)"><i class="fa-solid fa-gamepad"></i></div>
      <div>
        <div class="stat-val"><?= (int)$s['total_games'] ?></div>
        <div class="stat-lbl">Total Games</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-ico" style="background:rgba(22,163,74,.1);color:var(--green)"><i class="fa-solid fa-circle-play"></i></div>
      <div>
        <div class="stat-val"><?= (int)$s['live_games'] ?></div>
        <div class="stat-lbl">Live Now</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-ico" style="background:rgba(254,196,17,.12);color:var(--gold-d)"><i class="fa-solid fa-rotate"></i></div>
      <div>
        <div class="stat-val"><?= (int)$s['total_plays'] ?></div>
        <div class="stat-lbl">Total Plays</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-ico" style="background:rgba(191,19,57,.08);color:var(--red)"><i class="fa-solid fa-users"></i></div>
      <div>
        <div class="stat-val"><?= (int)$s['total_players'] ?></div>
        <div class="stat-lbl">Total Players</div>
      </div>
    </div>
  </div>

  <!-- TOOLBAR -->
  <div class="toolbar">
    <div class="toolbar-title">
      <?php
        $filterLabel = ['all'=>'All Games','live'=>'Live','ended'=>'Ended','draft'=>'Draft'];
        echo $filterLabel[$filter] ?? 'All Games';
      ?>
    </div>

    <!-- Filter pills -->
    <div class="filter-pills">
      <?php
        $countStmt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM creator_games WHERE creator_id = ? GROUP BY status");
        $countStmt->execute([$creator_id]);
        $counts = ['all'=>$s['total_games'],'live'=>0,'ended'=>0,'draft'=>0];
        foreach($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['status']] = $row['c'];
        $filters = ['all'=>'All','live'=>'Live','ended'=>'Ended','draft'=>'Draft'];
        foreach($filters as $k=>$v):
          $active = ($filter === $k) ? 'active' : '';
      ?>
      <a href="?page=my_games&filter=<?= $k ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="filter-pill <?= $active ?>">
        <?= $v ?><span class="pill-count"><?= $counts[$k] ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Search -->
    <form method="GET" action="" style="display:contents">
      <input type="hidden" name="page" value="my_games">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <div class="search-wrap">
        <input type="search" name="q" placeholder="Search games…" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        <i class="fa-solid fa-search search-ico"></i>
      </div>
    </form>

    <a href="?page=create_game" class="new-game-btn">
      <i class="fa-solid fa-plus"></i> New Game
    </a>
  </div>

  <!-- GAMES GRID -->
  <div class="games-grid">
    <?php if (empty($games)): ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="fa-solid fa-circle-question"></i></div>
      <div class="empty-title"><?= $search ? 'No games match your search' : 'No games yet' ?></div>
      <div class="empty-sub"><?= $search ? 'Try a different keyword.' : 'Create your first quiz game and share it with your audience — no coding needed!' ?></div>
      <?php if (!$search): ?>
      <a href="?page=create_game" class="empty-cta"><i class="fa-solid fa-plus"></i> Create First Game</a>
      <?php endif; ?>
    </div>

    <?php else: foreach($games as $g):
      $qs       = json_decode($g['questions_json'] ?? '[]', true);
      $qCount   = is_array($qs) ? count($qs) : 0;
      $status   = $g['status'];
      $hasPin   = !empty($g['game_pin']);
      $players  = (int)$g['player_count'];
      $topScore = $g['top_score'] !== null ? (int)$g['top_score'] : '—';
      $plays    = (int)$g['play_count'];
      $cover    = !empty($g['cover']) ? 'uploads/game_covers/'.htmlspecialchars($g['cover']) : null;
    ?>
    <div class="game-card">
      <!-- Cover -->
      <div class="gc-cover">
        <?php if($cover): ?>
        <img src="<?= $cover ?>" class="gc-cover-img" alt="" loading="lazy" onerror="this.style.display='none'">
        <?php endif; ?>
        <div class="gc-cover-grid"></div>
        <div class="gc-cover-orb"></div>

        <!-- Status badge -->
        <div class="gc-status-badge <?= $status ?>">
          <span class="gc-status-dot"></span>
          <?= ucfirst($status) ?>
        </div>

        <!-- PIN badge -->
        <?php if($hasPin): ?>
        <div class="gc-pin-badge" onclick="showPinModal('<?= $g['game_pin'] ?>', '<?= htmlspecialchars(addslashes($g['title'])) ?>')" title="Click to view/copy PIN">
          <?= $g['game_pin'] ?>
        </div>
        <?php else: ?>
        <div class="gc-pin-badge no-pin">No PIN</div>
        <?php endif; ?>

        <!-- Category -->
        <?php if(!empty($g['category'])): ?>
        <div class="gc-cat-badge"><?= htmlspecialchars($g['category']) ?></div>
        <?php endif; ?>
      </div>

      <!-- Body -->
      <div class="gc-body">
        <div class="gc-title"><?= htmlspecialchars($g['title']) ?></div>

        <?php if(!empty($g['description'])): ?>
        <div class="gc-desc"><?= htmlspecialchars($g['description']) ?></div>
        <?php endif; ?>

        <!-- Meta tags -->
        <div class="gc-meta-row">
          <span class="gc-meta-tag"><i class="fa-solid fa-circle-question"></i> <?= $qCount ?> question<?= $qCount !== 1 ? 's' : '' ?></span>
          <span class="gc-meta-tag"><i class="fa-regular fa-calendar"></i> <?= date('M j, Y', strtotime($g['created_at'])) ?></span>
          <?php if($g['visibility'] === 'private'): ?>
          <span class="gc-meta-tag" style="background:rgba(191,19,57,.08);border-color:rgba(191,19,57,.15);color:var(--red)"><i class="fa-solid fa-lock"></i> Private</span>
          <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="gc-stats">
          <div class="gc-stat">
            <div class="gc-stat-val"><?= $plays ?></div>
            <div class="gc-stat-lbl">Plays</div>
          </div>
          <div class="gc-stat">
            <div class="gc-stat-val"><?= $players ?></div>
            <div class="gc-stat-lbl">Players</div>
          </div>
          <div class="gc-stat">
            <div class="gc-stat-val"><?= is_int($topScore) ? number_format($topScore) : $topScore ?></div>
            <div class="gc-stat-lbl">Top Score</div>
          </div>
        </div>
      </div>

      <!-- Footer actions -->
      <div class="gc-footer">
        <a href="?page=create_game&edit=<?= $g['id'] ?>" class="gc-action-btn gc-btn-edit">
          <i class="fa-solid fa-pen-to-square"></i> Edit
        </a>

        <?php if($status === 'live'): ?>
        <button class="gc-action-btn gc-btn-toggle-end" onclick="toggleStatus(<?= $g['id'] ?>, 'end')">
          <i class="fa-solid fa-stop"></i> End
        </button>
        <?php else: ?>
        <button class="gc-action-btn gc-btn-toggle-live" onclick="toggleStatus(<?= $g['id'] ?>, 'live')">
          <i class="fa-solid fa-play"></i> Go Live
        </button>
        <?php endif; ?>

        <?php if($hasPin): ?>
        <button class="gc-action-btn gc-btn-pin" onclick="showLB(<?= $g['id'] ?>, '<?= htmlspecialchars(addslashes($g['title'])) ?>')" title="View leaderboard">
          <i class="fa-solid fa-trophy"></i>
        </button>
        <?php endif; ?>

        <button class="gc-action-btn gc-btn-delete" onclick="confirmDelete(<?= $g['id'] ?>, '<?= htmlspecialchars(addslashes($g['title'])) ?>')">
          <i class="fa-solid fa-trash-can"></i>
        </button>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- PAGINATION -->
  <?php if($totalPages > 1): ?>
  <nav>
    <div class="cr-pagination">
      <a href="?page=my_games&filter=<?= $filter ?>&q=<?= urlencode($search) ?>&page=<?= $page-1 ?>"
         class="cr-page-btn <?= $page<=1?'disabled':'' ?>">
        <i class="fas fa-chevron-left" style="font-size:10px"></i>
      </a>
      <?php
        $ps = max(1,$page-2); $pe = min($totalPages,$page+2);
        if($ps>1){ echo '<a href="?page=my_games&filter='.$filter.'&q='.urlencode($search).'&page=1" class="cr-page-btn">1</a>'; if($ps>2) echo '<span class="cr-page-ellipsis">…</span>'; }
        for($i=$ps;$i<=$pe;$i++) echo '<a href="?page=my_games&filter='.$filter.'&q='.urlencode($search).'&page='.$i.'" class="cr-page-btn '.($i==$page?'active':'').'">'.$i.'</a>';
        if($pe<$totalPages){ if($pe<$totalPages-1) echo '<span class="cr-page-ellipsis">…</span>'; echo '<a href="?page=my_games&filter='.$filter.'&q='.urlencode($search).'&page='.$totalPages.'" class="cr-page-btn">'.$totalPages.'</a>'; }
      ?>
      <a href="?page=my_games&filter=<?= $filter ?>&q=<?= urlencode($search) ?>&page=<?= $page+1 ?>"
         class="cr-page-btn <?= $page>=$totalPages?'disabled':'' ?>">
        <i class="fas fa-chevron-right" style="font-size:10px"></i>
      </a>
    </div>
  </nav>
  <?php endif; ?>

</div><!-- /page -->

<!-- ── DELETE CONFIRM MODAL ── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-head-ico"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div>
        <div class="modal-head-title">Delete Game</div>
        <div class="modal-head-sub">This action cannot be undone</div>
      </div>
    </div>
    <div class="modal-body">
      <p>Are you sure you want to permanently delete <strong id="deleteGameName">"Game Title"</strong>?<br><br>
      All player sessions and leaderboard data for this game will also be removed.</p>
    </div>
    <div class="modal-footer">
      <button class="modal-btn modal-btn-cancel" onclick="closeDeleteModal()">Cancel</button>
      <form method="POST" style="display:contents" id="deleteForm">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="game_id" id="deleteGameId">
        <button type="submit" class="modal-btn modal-btn-danger">
          <i class="fa-solid fa-trash-can"></i> Delete Game
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ── PIN MODAL ── -->
<div class="pin-modal-overlay" id="pinModal">
  <div class="pin-modal-box">
    <div class="pm-icon"><i class="fa-solid fa-gamepad"></i></div>
    <div class="pm-title" id="pmTitle">Game PIN</div>
    <div class="pm-sub">Share this PIN so players can join at <strong style="color:rgba(255,255,255,.7)">keychele.co.ke/play</strong></div>
    <div class="pm-pin" id="pmPin">------</div>
    <button class="pm-copy" id="pmCopyBtn" onclick="copyPinModal()">
      <i class="fa-regular fa-copy"></i> Copy PIN
    </button>
    <button class="pm-close" onclick="closePinModal()">Close</button>
  </div>
</div>

<!-- ── LEADERBOARD DRAWER ── -->
<div class="lb-drawer-overlay" id="lbDrawerOverlay" onclick="closeLB(event)">
  <div class="lb-drawer">
    <div class="lb-drawer-head">
      <div>
        <div class="lb-drawer-title" id="lbDrawerTitle">Leaderboard</div>
        <div class="lb-drawer-sub" id="lbDrawerSub">Top players</div>
      </div>
      <button class="lb-drawer-close" onclick="closeLBBtn()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="lb-drawer-body" id="lbDrawerBody">
      <div class="lb-empty"><i class="fa-solid fa-spinner spinner"></i> Loading...</div>
    </div>
  </div>
</div>

<script>
toastr.options = {
  closeButton:true, progressBar:true,
  positionClass:'toast-top-right', timeOut:5000,
  showMethod:'slideDown', hideMethod:'fadeOut'
};

<?php if($msg): ?>
toastr.success('<?= addslashes(strip_tags($msg)) ?>', '', {timeOut:5000});
<?php endif; ?>
<?php if($err): ?>
toastr.error('<?= addslashes($err) ?>', '', {timeOut:6000});
<?php endif; ?>

// ── Delete modal ────────────────────────────────────
function confirmDelete(id, name) {
  document.getElementById('deleteGameId').value = id;
  document.getElementById('deleteGameName').textContent = '"' + name + '"';
  document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
  document.getElementById('deleteModal').classList.remove('open');
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
  if (e.target === this) closeDeleteModal();
});

// ── Toggle status ───────────────────────────────────
function toggleStatus(id, action) {
  const fd = new FormData();
  fd.append('action', 'toggle_status');
  fd.append('game_id', id);
  fetch('', { method:'POST', body:fd })
    .then(() => { toastr.success('Game status updated!', '', {timeOut:3000}); setTimeout(()=>location.reload(),800); })
    .catch(() => toastr.error('Failed to update status.'));
}

// ── PIN modal ───────────────────────────────────────
function showPinModal(pin, title) {
  document.getElementById('pmPin').textContent  = pin;
  document.getElementById('pmTitle').textContent = title || 'Game PIN';
  document.getElementById('pinModal').classList.add('open');
}
function closePinModal() {
  document.getElementById('pinModal').classList.remove('open');
}
document.getElementById('pinModal').addEventListener('click', function(e){
  if(e.target === this) closePinModal();
});
function copyPinModal() {
  const pin = document.getElementById('pmPin').textContent;
  const btn = document.getElementById('pmCopyBtn');
  navigator.clipboard.writeText(pin).then(() => {
    btn.classList.add('copied');
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = '<i class="fa-regular fa-copy"></i> Copy PIN'; }, 2200);
  }).catch(() => {
    const ta = document.createElement('textarea');
    ta.value = pin; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
    toastr.success('PIN copied!', '', {timeOut:1800});
  });
}

// ── Leaderboard drawer ──────────────────────────────
function showLB(gameId, title) {
  document.getElementById('lbDrawerTitle').textContent = title;
  document.getElementById('lbDrawerSub').textContent   = 'Loading leaderboard...';
  document.getElementById('lbDrawerBody').innerHTML    = '<div class="lb-empty"><i class="fa-solid fa-spinner spinner"></i> Loading...</div>';
  document.getElementById('lbDrawerOverlay').classList.add('open');

  fetch(`?page=my_games&ajax_lb=1&game_id=${gameId}`)
    .then(r => r.json())
    .then(data => {
      document.getElementById('lbDrawerSub').textContent = data.total + ' player' + (data.total !== 1 ? 's' : '') + ' played';
      if (!data.rows || !data.rows.length) {
        document.getElementById('lbDrawerBody').innerHTML = '<div class="lb-empty"><i class="fa-solid fa-gamepad" style="color:var(--muted);font-size:1.8rem;display:block;margin-bottom:.6rem"></i>No players yet.<br>Share the PIN to get people playing!</div>';
        return;
      }
      const html = data.rows.map((r,i) => {
        const cls = i===0?'top1':i===1?'top2':i===2?'top3':'';
        return `<div class="lb-row ${cls}">
          <div class="lb-rank">${i+1}</div>
          <div class="lb-name">${escHtml(r.player_name)}</div>
          <div class="lb-score">${parseInt(r.score).toLocaleString()}</div>
        </div>`;
      }).join('');
      document.getElementById('lbDrawerBody').innerHTML = html;
    })
    .catch(() => {
      document.getElementById('lbDrawerBody').innerHTML = '<div class="lb-empty">Failed to load leaderboard.</div>';
    });
}
function closeLB(e) {
  if (e && !e.target.classList.contains('lb-drawer-overlay')) return;
  document.getElementById('lbDrawerOverlay').classList.remove('open');
}
function closeLBBtn() {
  document.getElementById('lbDrawerOverlay').classList.remove('open');
}
function escHtml(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>

<?php
// ── AJAX: leaderboard data ────────────────────────────────
if (isset($_GET['ajax_lb']) && isset($_GET['game_id'])) {
    $gid = (int)$_GET['game_id'];
    // Verify ownership
    $own = $pdo->prepare("SELECT id FROM creator_games WHERE id = ? AND creator_id = ?");
    $own->execute([$gid, $creator_id]);
    if ($own->fetchColumn()) {
        $lb = $pdo->prepare("SELECT player_name, score FROM game_sessions WHERE game_id = ? ORDER BY score DESC LIMIT 20");
        $lb->execute([$gid]);
        $rows  = $lb->fetchAll(PDO::FETCH_ASSOC);
        $cnt   = $pdo->prepare("SELECT COUNT(DISTINCT player_name) FROM game_sessions WHERE game_id = ?");
        $cnt->execute([$gid]);
        $total = (int)$cnt->fetchColumn();
        header('Content-Type: application/json');
        echo json_encode(['rows' => $rows, 'total' => $total]);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['rows' => [], 'total' => 0]);
    exit;
}
?>
</body>
</html>