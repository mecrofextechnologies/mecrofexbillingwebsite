<?php
// ==================== MY_MUSIC.PHP ====================
ob_start();
include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit();
}

// ── Handle AJAX Actions ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $action = $_POST['action'];

    if ($action === 'delete') {
        $music_id = $_POST['music_id'] ?? null;
        try {
            $stmt = $pdo->prepare("SELECT audio_file, cover_art FROM music WHERE id = :id AND creator_id = :creator_id");
            $stmt->execute([':id' => $music_id, ':creator_id' => $creator_id]);
            $track = $stmt->fetch();

            if ($track) {
                if ($track['audio_file']) @unlink("uploads/music/audio/" . $track['audio_file']);
                if ($track['cover_art'])  @unlink("uploads/music/covers/" . $track['cover_art']);

                $del = $pdo->prepare("DELETE FROM music WHERE id = :id AND creator_id = :creator_id");
                $del->execute([':id' => $music_id, ':creator_id' => $creator_id]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Track not found.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'update') {
        $music_id = $_POST['music_id'] ?? null;
        $title    = trim($_POST['title'] ?? '');
        $artist   = trim($_POST['artist'] ?? '');
        $genre    = $_POST['genre'] ?? 'Other';
        $is_free  = isset($_POST['is_free']) ? 1 : 0;
        $price    = $is_free ? 0 : floatval($_POST['price'] ?? 0);

        if (empty($title) || empty($artist)) {
            echo json_encode(['success' => false, 'error' => 'Title and Artist are required.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE music
                SET title = :title, artist = :artist, genre = :genre, is_free = :is_free, price = :price
                WHERE id = :id AND creator_id = :creator_id
            ");
            $stmt->execute([
                ':title'      => $title,
                ':artist'     => $artist,
                ':genre'      => $genre,
                ':is_free'    => $is_free,
                ':price'      => $price,
                ':id'         => $music_id,
                ':creator_id' => $creator_id,
            ]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// ── Fetch tracks ─────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM music WHERE creator_id = :creator_id ORDER BY created_at DESC");
$stmt->execute([':creator_id' => $creator_id]);
$tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total     = count($tracks);
$free_cnt  = 0;
$paid_cnt  = 0;
foreach ($tracks as $t) {
    if ($t['is_free']) $free_cnt++; else $paid_cnt++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Music — Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* ── Design Tokens ── */
    :root {
      --navy:        #032d5d;
      --navy-light:  #0a3d7a;
      --crimson:     #b81236;
      --surface:     #ffffff;
      --bg:          #f0f4fa;
      --border:      #e2e8f0;
      --text-pri:    #0f172a;
      --text-sec:    #475569;
      --text-muted:  #94a3b8;
      --green:       #059669;
      --green-bg:    #d1fae5;
      --blue:        #2563eb;
      --blue-bg:     #dbeafe;
      --indigo:      #4f46e5;
      --indigo-bg:   #e0e7ff;
      --amber:       #d97706;
      --amber-bg:    #fef3c7;
      --red:         #dc2626;
      --red-bg:      #fee2e2;
      --purple:      #7c3aed;
      --purple-bg:   #ede9fe;
      --teal:        #0d9488;
      --teal-bg:     #ccfbf1;
      --gold:        #f59e0b;
      --radius-sm:   8px;
      --radius-md:   14px;
      --radius-lg:   20px;
      --shadow-sm:   0 1px 4px rgba(3,45,93,.07);
      --shadow-md:   0 4px 20px rgba(3,45,93,.10);
      --shadow-lg:   0 12px 40px rgba(3,45,93,.14);
      --transition:  all .22s cubic-bezier(.4,0,.2,1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Sora', sans-serif;
      background: var(--bg);
      color: var(--text-pri);
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
    }

    /* ── Preloader ── */
    #preloader {
      position: fixed; inset: 0; z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      background: rgba(3,45,93,.55); backdrop-filter: blur(6px);
      transition: opacity .4s ease;
    }
    .preloader-box {
      background: #fff; border-radius: var(--radius-lg);
      padding: 28px 40px; display: flex; align-items: center; gap: 14px;
      box-shadow: var(--shadow-lg);
    }
    .spin {
      width: 22px; height: 22px; border: 3px solid var(--border);
      border-top-color: var(--crimson); border-radius: 50%;
      animation: spin .75s linear infinite; flex-shrink: 0;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .preloader-box span { font-size: .9rem; font-weight: 600; color: var(--text-sec); }

    /* ── Topbar ── */
    .topbar {
      background: rgba(255,255,255,.97);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border);
      padding: 12px 24px;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 40;
      box-shadow: var(--shadow-sm);
      gap: 12px; flex-wrap: wrap;
    }
    .topbar-brand {
      display: inline-flex; align-items: center; gap: 10px;
      text-decoration: none; color: var(--navy); font-weight: 800;
      font-size: 1rem; letter-spacing: -.02em;
    }
    .topbar-brand-icon {
      width: 34px; height: 34px; border-radius: 10px;
      background: var(--navy); display: flex; align-items: center; justify-content: center;
      font-size: .75rem; color: #fec411;
    }
    .topbar-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

    /* ── Action Buttons ── */
    .action-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 16px; border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .78rem; font-weight: 600;
      cursor: pointer; border: none; text-decoration: none;
      transition: var(--transition); white-space: nowrap; letter-spacing: .01em;
    }
    .action-btn i { font-size: .7rem; }
    .btn-navy    { background: var(--navy);    color: #fff; }
    .btn-navy:hover    { background: var(--navy-light); box-shadow: 0 4px 14px rgba(3,45,93,.3); transform: translateY(-1px); }
    .btn-crimson { background: var(--crimson); color: #fff; }
    .btn-crimson:hover { background: #9f0f2e; box-shadow: 0 4px 14px rgba(184,18,54,.3); transform: translateY(-1px); }

    /* ── Page Wrap ── */
    .page-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px 96px; }
    @media (min-width: 768px) { .page-wrap { padding: 28px 28px 48px; } }

    /* ── Hero ── */
    .hero {
      position: relative; overflow: hidden;
      background: linear-gradient(130deg, var(--navy) 0%, #0a3d7a 45%, #7c3aed 100%);
      border-radius: var(--radius-lg); padding: 32px 36px; margin-bottom: 24px;
      box-shadow: var(--shadow-lg);
    }
    .hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
      pointer-events: none;
    }
    .hero-inner {
      position: relative; z-index: 1;
      display: flex; justify-content: space-between; align-items: flex-start;
      flex-wrap: wrap; gap: 16px;
    }
    .hero-eyebrow {
      display: inline-flex; align-items: center; gap: 7px;
      background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.2);
      backdrop-filter: blur(8px); border-radius: 999px;
      padding: 4px 14px; font-size: .72rem; font-weight: 600;
      color: #fff; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 10px;
    }
    .hero-title { font-size: clamp(1.25rem, 3vw, 2rem); font-weight: 800; color: #fff; letter-spacing: -.02em; line-height: 1.2; }
    .hero-sub   { font-size: .83rem; color: rgba(255,255,255,.7); margin-top: 6px; }
    .hero-right { text-align: right; }
    .hero-count-val   { font-family: 'JetBrains Mono', monospace; font-size: 2rem; font-weight: 700; color: #fff; line-height: 1; }
    .hero-count-label { font-size: .75rem; color: rgba(255,255,255,.6); margin-top: 4px; }

    /* ── Stats Grid ── */
    .stats-grid {
      display: grid; grid-template-columns: repeat(2, 1fr);
      gap: 14px; margin-bottom: 24px;
    }
    @media (min-width: 640px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }

    .stat-card {
      background: var(--surface); border-radius: var(--radius-md);
      padding: 18px 16px; border: 1px solid var(--border);
      box-shadow: var(--shadow-sm); position: relative; overflow: hidden;
      transition: var(--transition);
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .stat-card::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
      border-radius: var(--radius-md) var(--radius-md) 0 0;
    }
    .stat-card.sc-blue::before   { background: var(--blue); }
    .stat-card.sc-green::before  { background: var(--green); }
    .stat-card.sc-amber::before  { background: var(--amber); }
    .stat-card.sc-purple::before { background: var(--purple); }
    .stat-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 8px; }
    .stat-value { font-size: 1.6rem; font-weight: 800; letter-spacing: -.03em; color: var(--text-pri); line-height: 1; }
    .stat-icon-chip {
      margin-top: 12px; width: 34px; height: 34px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center; font-size: .85rem;
    }
    .stat-card.sc-blue   .stat-icon-chip { background: var(--blue-bg);   color: var(--blue); }
    .stat-card.sc-green  .stat-icon-chip { background: var(--green-bg);  color: var(--green); }
    .stat-card.sc-amber  .stat-icon-chip { background: var(--amber-bg);  color: var(--amber); }
    .stat-card.sc-purple .stat-icon-chip { background: var(--purple-bg); color: var(--purple); }

    /* ── Panel ── */
    .panel { background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-md); overflow: hidden; }
    .panel-header {
      padding: 20px 28px; border-bottom: 1px solid var(--border);
      background: #fafbfe; display: flex; align-items: center;
      justify-content: space-between; flex-wrap: wrap; gap: 14px;
    }
    .panel-title { font-size: 1rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 10px; }
    .panel-title i { color: var(--navy); }
    .record-badge { background: var(--blue-bg); color: var(--blue); font-size: .72rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; }

    /* ── Toolbar / Search ── */
    .toolbar { padding: 14px 28px; border-bottom: 1px solid var(--border); background: #fafbfe; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .search-box { position: relative; flex: 1; min-width: 220px; }
    .search-box i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: .82rem; pointer-events: none; }
    .search-input {
      width: 100%; padding: 10px 14px 10px 36px;
      border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .83rem; color: var(--text-pri);
      background: var(--surface); outline: none; transition: border-color .2s, box-shadow .2s;
    }
    .search-input:focus { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(3,45,93,.08); }
    .search-input::placeholder { color: var(--text-muted); }
    #clearSearchBtn { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: .78rem; padding: 0 4px; display: none; transition: color .2s; }
    #clearSearchBtn:hover { color: var(--crimson); }
    .filter-select {
      padding: 9px 12px; border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .78rem; font-weight: 600;
      color: var(--text-sec); background: var(--surface); outline: none; cursor: pointer;
    }
    .filter-select:focus { border-color: var(--navy); }

    /* ── Table ── */
    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table-scroll::-webkit-scrollbar { height: 5px; }
    .table-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 99px; }
    .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }

    table { width: 100%; border-collapse: collapse; min-width: 820px; }
    thead th {
      padding: 12px 16px; font-size: .68rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted);
      background: #f8fafc; border-bottom: 1px solid var(--border); white-space: nowrap;
    }
    thead th:first-child { padding-left: 24px; }
    thead th:last-child  { padding-right: 24px; }
    tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .15s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #f8faff; }
    tbody td { padding: 14px 16px; font-size: .83rem; color: var(--text-sec); vertical-align: middle; }
    tbody td:first-child { padding-left: 24px; }
    tbody td:last-child  { padding-right: 24px; }

    /* ── Cover thumb ── */
    .cover-thumb {
      width: 46px; height: 46px; border-radius: 10px;
      object-fit: cover; border: 1px solid var(--border); flex-shrink: 0;
    }
    .thumb-placeholder {
      width: 46px; height: 46px; border-radius: 10px;
      background: var(--purple-bg); display: flex; align-items: center;
      justify-content: center; color: var(--purple); flex-shrink: 0; font-size: .9rem;
    }
    .track-title-cell { font-weight: 700; color: var(--text-pri); font-size: .87rem; }
    .track-sub-cell   { font-size: .73rem; color: var(--text-muted); margin-top: 2px; }

    /* ── Badges ── */
    .badge { display: inline-block; padding: 3px 11px; border-radius: 999px; font-size: .7rem; font-weight: 700; letter-spacing: .03em; white-space: nowrap; }
    .badge-free { background: var(--teal-bg); color: var(--teal); }
    .badge-paid { background: var(--amber-bg); color: var(--amber); }
    .genre-chip {
      display: inline-block; padding: 3px 10px; border-radius: 7px;
      font-size: .7rem; font-weight: 700; background: var(--indigo-bg); color: var(--indigo);
    }
    .price-chip {
      font-family: 'JetBrains Mono', monospace; font-weight: 700;
      font-size: .75rem; color: var(--amber);
      background: var(--amber-bg); border-radius: 7px;
      padding: 4px 9px; display: inline-block;
    }

    /* ── Row icon actions ── */
    .row-actions { display: flex; align-items: center; justify-content: center; gap: 6px; }
    .icon-btn {
      width: 32px; height: 32px; border-radius: 8px; border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center; font-size: .72rem;
      transition: var(--transition); text-decoration: none;
    }
    .icon-btn-blue   { background: var(--blue-bg);   color: var(--blue); }
    .icon-btn-blue:hover   { background: #bfdbfe; transform: scale(1.1); }
    .icon-btn-gold   { background: var(--amber-bg);  color: var(--amber); }
    .icon-btn-gold:hover   { background: #fde68a; transform: scale(1.1); }
    .icon-btn-red    { background: var(--red-bg);    color: var(--red); }
    .icon-btn-red:hover    { background: #fecaca; transform: scale(1.1); }

    /* ── No results ── */
    #noResults { display: none; text-align: center; padding: 48px 24px; }
    #noResults i { font-size: 2rem; color: var(--text-muted); display: block; margin-bottom: 10px; }
    #noResults p { color: var(--text-muted); font-size: .875rem; }

    /* ── Empty state ── */
    .empty-state { text-align: center; padding: 64px 24px; }
    .empty-icon { width: 64px; height: 64px; border-radius: 18px; background: var(--purple-bg); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--purple); margin: 0 auto 16px; }

    /* ── Panel footer ── */
    .panel-footer {
      padding: 13px 28px; border-top: 1px solid var(--border);
      background: #fafbfe; font-size: .74rem; color: var(--text-muted);
      display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;
    }
    .panel-footer strong { color: var(--text-pri); }

    /* ── Mobile Cards ── */
    .mobile-cards { padding: 14px; display: flex; flex-direction: column; gap: 12px; }
    .m-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--radius-md); padding: 16px;
      position: relative; overflow: hidden; transition: var(--transition);
    }
    .m-card::before {
      content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 4px;
      border-radius: 4px 0 0 4px;
    }
    .m-card.free-card::before  { background: var(--teal); }
    .m-card.paid-card::before  { background: var(--amber); }
    .m-card:hover { box-shadow: var(--shadow-md); }
    .m-card-top { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 10px; }
    .m-card-info { flex: 1; min-width: 0; }
    .m-title { font-weight: 700; color: var(--text-pri); font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .m-sub   { font-size: .73rem; color: var(--text-muted); margin-top: 2px; }
    .m-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
    .m-field { background: #f8fafc; border-radius: 8px; padding: 8px 10px; }
    .m-field-label { font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 3px; }
    .m-field-val   { font-size: .8rem; font-weight: 600; color: var(--text-pri); }
    .m-actions { display: flex; gap: 8px; }
    .m-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 9px; border-radius: 9px; font-family: 'Sora', sans-serif; font-size: .75rem; font-weight: 600; border: none; cursor: pointer; transition: var(--transition); text-decoration: none; }
    .m-btn-navy   { background: var(--navy);    color: #fff; }
    .m-btn-navy:hover   { background: var(--navy-light); }
    .m-btn-gold   { background: var(--amber-bg); color: var(--amber); }
    .m-btn-gold:hover   { background: #fde68a; }
    .m-btn-red    { background: var(--red-bg);  color: var(--red); }
    .m-btn-red:hover    { background: #fecaca; }

    /* ── Modal ── */
    .modal-overlay {
      position: fixed; inset: 0; background: rgba(3,45,93,.55);
      backdrop-filter: blur(4px); z-index: 1000;
      display: none; align-items: center; justify-content: center; padding: 1.5rem;
    }
    .modal-overlay.show { display: flex; }
    .modal-box {
      background: var(--surface); border-radius: var(--radius-lg);
      width: 100%; max-width: 480px; overflow: hidden;
      box-shadow: var(--shadow-lg); position: relative; z-index: 1001;
      animation: modalIn .3s cubic-bezier(.34,1.56,.64,1);
    }
    @keyframes modalIn { from { opacity:0; transform:translateY(20px) scale(.95); } to { opacity:1; transform:none; } }
    .modal-head {
      padding: 18px 24px; border-bottom: 1px solid var(--border);
      background: #fafbfe; display: flex; align-items: center; justify-content: space-between;
    }
    .modal-head-title { font-size: .95rem; font-weight: 700; color: var(--text-pri); display: flex; align-items: center; gap: 9px; }
    .modal-close { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: .85rem; width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; transition: var(--transition); }
    .modal-close:hover { background: var(--red-bg); color: var(--red); }
    .modal-body { padding: 20px 24px; }
    .modal-foot { padding: 14px 24px; border-top: 1px solid var(--border); background: #fafbfe; display: flex; justify-content: flex-end; gap: 10px; }

    .field { margin-bottom: 14px; }
    .field label { display: block; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); margin-bottom: 6px; }
    .field input, .field select {
      width: 100%; padding: 10px 13px; border: 1.5px solid var(--border);
      border-radius: var(--radius-sm); font-family: 'Sora', sans-serif;
      font-size: .83rem; color: var(--text-pri); background: var(--surface);
      outline: none; transition: border-color .2s, box-shadow .2s;
    }
    .field input:focus, .field select:focus { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(3,45,93,.08); }
    .free-toggle { display: flex; align-items: center; gap: 10px; padding: 10px 13px; background: var(--teal-bg); border-radius: var(--radius-sm); border: 1.5px solid #a7f3d0; margin-bottom: 14px; }
    .free-toggle input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--teal); cursor: pointer; }
    .free-toggle label { font-size: .82rem; font-weight: 600; color: var(--teal); cursor: pointer; }

    .btn-modal {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 18px; border-radius: var(--radius-sm);
      font-family: 'Sora', sans-serif; font-size: .8rem; font-weight: 600;
      cursor: pointer; border: none; transition: var(--transition);
    }
    .btn-modal-cancel  { background: #f1f5f9; color: var(--text-sec); }
    .btn-modal-cancel:hover  { background: #e2e8f0; }
    .btn-modal-save    { background: var(--navy); color: #fff; }
    .btn-modal-save:hover    { background: var(--navy-light); }
    .btn-modal-save:disabled { opacity: .6; cursor: not-allowed; }

    /* ── Bottom Nav ── */
    .bottom-nav {
      position: fixed; bottom: 0; left: 0; right: 0;
      background: rgba(255,255,255,.96); backdrop-filter: blur(12px);
      border-top: 1px solid var(--border);
      display: flex; justify-content: space-around; align-items: center;
      padding: 8px 0 12px; z-index: 50;
    }
    @media (min-width: 768px) { .bottom-nav { display: none; } }
    .nav-item { display: flex; flex-direction: column; align-items: center; gap: 3px; text-decoration: none; color: var(--text-muted); font-size: .62rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; transition: color .2s; padding: 4px 12px; }
    .nav-item i { font-size: 1.15rem; }
    .nav-item.active { color: var(--navy); }
    .nav-fab { display: flex; flex-direction: column; align-items: center; gap: 3px; text-decoration: none; font-size: .62rem; font-weight: 600; letter-spacing: .05em; }
    .nav-fab-icon { width: 46px; height: 46px; border-radius: 14px; margin-top: -18px; background: var(--navy); color: #fec411; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; box-shadow: 0 4px 16px rgba(3,45,93,.35); }
    .nav-fab span { color: var(--navy); }

    /* ── Show/hide helpers ── */
    .desk-only { display: none; } @media (min-width: 768px) { .desk-only { display: block; } }
    .mob-only  { display: block; } @media (min-width: 768px) { .mob-only  { display: none;  } }

    /* ── Tooltip ── */
    [data-tip] { position: relative; }
    [data-tip]:hover::after {
      content: attr(data-tip); position: absolute; bottom: 125%; left: 50%;
      transform: translateX(-50%); background: var(--text-pri); color: #fff;
      font-size: .68rem; padding: 4px 8px; border-radius: 6px; white-space: nowrap;
      z-index: 20; pointer-events: none;
    }

    /* ── Animations ── */
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
    .stat-card { animation: fadeUp .4s ease both; }
    .stat-card:nth-child(1) { animation-delay: .05s; }
    .stat-card:nth-child(2) { animation-delay: .10s; }
    .stat-card:nth-child(3) { animation-delay: .15s; }
    .stat-card:nth-child(4) { animation-delay: .20s; }
    .hero { animation: fadeUp .35s ease both; }
  </style>
</head>
<body>

<!-- Preloader -->
<div id="preloader">
  <div class="preloader-box">
    <div class="spin"></div>
    <span>Loading your music…</span>
  </div>
</div>

<!-- ── Topbar ── -->
<div class="topbar">
  <a href="index.php?page=dashboard" class="topbar-brand">
    <span class="topbar-brand-icon"><i class="fas fa-ticket-alt"></i></span>
    Keychele
  </a>
  <div class="topbar-actions">
    <a href="add_music.php" class="action-btn btn-crimson">
      <i class="fas fa-plus"></i>
      <span>Upload Track</span>
    </a>
    <a href="index.php?page=my_wallet" class="action-btn btn-navy" data-tip="View your wallet">
      <i class="fas fa-wallet"></i>
      <span class="desk-only" style="display:inline">Wallet</span>
    </a>
    <a href="index.php?page=dashboard" class="action-btn btn-navy" data-tip="Dashboard">
      <i class="fas fa-home"></i>
      <span class="desk-only" style="display:inline">Dashboard</span>
    </a>
  </div>
</div>

<div class="page-wrap">

  <!-- ── Hero ── -->
  <div class="hero">
    <div class="hero-inner">
      <div>
        <div class="hero-eyebrow">
          <i class="fas fa-music" style="color:#fbbf24"></i>
          Music Manager
        </div>
        <div class="hero-title">My Music</div>
        <div class="hero-sub">Upload, manage and distribute your tracks all in one place</div>
      </div>
      <div class="hero-right desk-only">
        <div class="hero-count-val"><?= $total ?></div>
        <div class="hero-count-label">Total Tracks</div>
      </div>
    </div>
  </div>

  <!-- ── Stats ── -->
  <div class="stats-grid">
    <div class="stat-card sc-blue">
      <div class="stat-label">Total Tracks</div>
      <div class="stat-value"><?= $total ?></div>
      <div class="stat-icon-chip"><i class="fas fa-music"></i></div>
    </div>
    <div class="stat-card sc-green">
      <div class="stat-label">Free Tracks</div>
      <div class="stat-value"><?= $free_cnt ?></div>
      <div class="stat-icon-chip"><i class="fas fa-gift"></i></div>
    </div>
    <div class="stat-card sc-amber">
      <div class="stat-label">Paid Tracks</div>
      <div class="stat-value"><?= $paid_cnt ?></div>
      <div class="stat-icon-chip"><i class="fas fa-tag"></i></div>
    </div>
    <div class="stat-card sc-purple">
      <div class="stat-label">Genres</div>
      <div class="stat-value"><?= count(array_unique(array_column($tracks, 'genre'))) ?></div>
      <div class="stat-icon-chip"><i class="fas fa-layer-group"></i></div>
    </div>
  </div>

  <!-- ── Main Panel ── -->
  <div class="panel">

    <div class="panel-header">
      <div class="panel-title">
        <i class="fas fa-headphones"></i>
        All Tracks
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="record-badge" id="recordBadge"><?= $total ?> track<?= $total !== 1 ? 's' : '' ?></span>
        <a href="add_music.php" class="action-btn btn-crimson" style="padding:7px 14px;font-size:.75rem">
          <i class="fas fa-plus"></i> Upload Track
        </a>
      </div>
    </div>

    <?php if (empty($tracks)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-music"></i></div>
        <h4 style="font-size:1rem;font-weight:700;margin-bottom:6px">No Tracks Yet</h4>
        <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:20px">Upload your first track and start sharing your music!</p>
        <a href="add_music.php" class="action-btn btn-crimson">
          <i class="fas fa-plus"></i> Upload First Track
        </a>
      </div>

    <?php else: ?>

      <!-- Toolbar -->
      <div class="toolbar">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" class="search-input"
                 placeholder="Search tracks by title or artist…" autocomplete="off">
        </div>
        <button id="clearSearchBtn" title="Clear"><i class="fas fa-times"></i> Clear</button>
        <select class="filter-select" id="filterType">
          <option value="all">All Tracks</option>
          <option value="free">Free Only</option>
          <option value="paid">Paid Only</option>
        </select>
        <select class="filter-select" id="filterGenre">
          <option value="all">All Genres</option>
          <?php
            $genres = array_unique(array_column($tracks, 'genre'));
            sort($genres);
            foreach ($genres as $g) echo '<option value="' . htmlspecialchars($g) . '">' . htmlspecialchars($g) . '</option>';
          ?>
        </select>
      </div>

      <!-- Desktop Table -->
      <div class="desk-only table-scroll" style="display:block">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Track</th>
              <th>Genre</th>
              <th style="text-align:center">Pricing</th>
              <th style="text-align:center">Status</th>
              <th>Uploaded</th>
              <th style="text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody id="tracksTableBody">
            <?php foreach ($tracks as $i => $track):
              $is_free     = (bool)$track['is_free'];
              $type_class  = $is_free ? 'free' : 'paid';
            ?>
            <tr class="track-row"
                data-title="<?= htmlspecialchars(strtolower($track['title'] ?? ''), ENT_QUOTES) ?>"
                data-artist="<?= htmlspecialchars(strtolower($track['artist'] ?? ''), ENT_QUOTES) ?>"
                data-type="<?= $type_class ?>"
                data-genre="<?= htmlspecialchars($track['genre'] ?? '', ENT_QUOTES) ?>">
              <td style="font-family:'JetBrains Mono',monospace;font-size:.72rem;font-weight:600;color:var(--text-muted)"><?= $i + 1 ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:12px">
                  <?php if (!empty($track['cover_art'])): ?>
                    <img src="uploads/music/covers/<?= htmlspecialchars($track['cover_art']) ?>"
                         class="cover-thumb" alt="">
                  <?php else: ?>
                    <div class="thumb-placeholder"><i class="fas fa-music"></i></div>
                  <?php endif; ?>
                  <div>
                    <div class="track-title-cell"><?= htmlspecialchars($track['title'] ?? 'Untitled') ?></div>
                    <div class="track-sub-cell">
                      <i class="fas fa-user-music" style="font-size:.6rem;margin-right:3px"></i>
                      <?= htmlspecialchars($track['artist'] ?? '—') ?>
                    </div>
                  </div>
                </div>
              </td>
              <td><span class="genre-chip"><?= htmlspecialchars($track['genre'] ?? '—') ?></span></td>
              <td style="text-align:center">
                <?php if ($is_free): ?>
                  <span class="badge badge-free">Free</span>
                <?php else: ?>
                  <span class="price-chip">KSh <?= number_format($track['price'], 2) ?></span>
                <?php endif; ?>
              </td>
              <td style="text-align:center">
                <span class="badge badge-<?= $type_class ?>">
                  <?= $is_free ? 'Free' : 'Paid' ?>
                </span>
              </td>
              <td>
                <div style="font-size:.8rem;font-weight:600;color:var(--text-pri)"><?= !empty($track['created_at']) ? date('d M Y', strtotime($track['created_at'])) : '—' ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= !empty($track['created_at']) ? date('g:i A', strtotime($track['created_at'])) : '' ?></div>
              </td>
              <td>
                <div class="row-actions">
                  <!-- View -->
                  <a href="https://keychele.co.ke/music/<?= $track['id'] ?>"
                     target="_blank"
                     class="icon-btn icon-btn-blue" data-tip="Preview track">
                    <i class="fas fa-eye"></i>
                  </a>
                  <!-- Edit -->
                  <button class="icon-btn icon-btn-gold edit-btn"
                          data-track="<?= htmlspecialchars(json_encode($track), ENT_QUOTES) ?>"
                          data-tip="Edit track">
                    <i class="fas fa-pen-to-square"></i>
                  </button>
                  <!-- Delete -->
                  <button class="icon-btn icon-btn-red delete-btn"
                          data-id="<?= $track['id'] ?>"
                          data-title="<?= htmlspecialchars($track['title'] ?? 'this track', ENT_QUOTES) ?>"
                          data-tip="Delete track">
                    <i class="fas fa-trash-can"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="noResults">
          <i class="fas fa-search-minus"></i>
          <p>No tracks match your search or filter.</p>
        </div>
      </div>

      <!-- Mobile Cards -->
      <div class="mob-only mobile-cards" id="mobileCards">
        <?php foreach ($tracks as $track):
          $is_free    = (bool)$track['is_free'];
          $type_class = $is_free ? 'free' : 'paid';
        ?>
        <div class="m-card <?= $type_class ?>-card"
             data-title="<?= htmlspecialchars(strtolower($track['title'] ?? ''), ENT_QUOTES) ?>"
             data-artist="<?= htmlspecialchars(strtolower($track['artist'] ?? ''), ENT_QUOTES) ?>"
             data-type="<?= $type_class ?>"
             data-genre="<?= htmlspecialchars($track['genre'] ?? '', ENT_QUOTES) ?>">
          <div class="m-card-top">
            <?php if (!empty($track['cover_art'])): ?>
              <img src="uploads/music/covers/<?= htmlspecialchars($track['cover_art']) ?>"
                   class="cover-thumb" alt="">
            <?php else: ?>
              <div class="thumb-placeholder"><i class="fas fa-music"></i></div>
            <?php endif; ?>
            <div class="m-card-info">
              <div class="m-title"><?= htmlspecialchars($track['title'] ?? 'Untitled') ?></div>
              <div class="m-sub">
                <i class="fas fa-user-music" style="font-size:.6rem;margin-right:2px"></i>
                <?= htmlspecialchars($track['artist'] ?? '—') ?>
              </div>
            </div>
            <span class="badge badge-<?= $type_class ?>"><?= $is_free ? 'Free' : 'Paid' ?></span>
          </div>
          <div class="m-grid">
            <div class="m-field">
              <div class="m-field-label">Genre</div>
              <div class="m-field-val"><?= htmlspecialchars($track['genre'] ?? '—') ?></div>
            </div>
            <div class="m-field">
              <div class="m-field-label">Price</div>
              <div class="m-field-val" style="color:var(--amber)">
                <?= $is_free ? 'Free' : 'KSh ' . number_format($track['price'], 2) ?>
              </div>
            </div>
            <div class="m-field" style="grid-column:span 2">
              <div class="m-field-label">Uploaded</div>
              <div class="m-field-val"><?= !empty($track['created_at']) ? date('d M Y, g:i A', strtotime($track['created_at'])) : '—' ?></div>
            </div>
          </div>
          <div class="m-actions">
            <a href="https://keychele.co.ke/music/<?= $track['id'] ?>"
               target="_blank" class="m-btn m-btn-navy">
              <i class="fas fa-eye"></i> View
            </a>
            <button class="m-btn m-btn-gold edit-btn"
                    data-track="<?= htmlspecialchars(json_encode($track), ENT_QUOTES) ?>">
              <i class="fas fa-pen-to-square"></i> Edit
            </button>
            <button class="m-btn m-btn-red delete-btn"
                    data-id="<?= $track['id'] ?>"
                    data-title="<?= htmlspecialchars($track['title'] ?? 'this track', ENT_QUOTES) ?>">
              <i class="fas fa-trash-can"></i>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
        <div id="noResultsMob" style="display:none;text-align:center;padding:40px 16px;color:var(--text-muted);font-size:.875rem">
          <i class="fas fa-search-minus" style="font-size:1.8rem;display:block;margin-bottom:8px"></i>
          No tracks match your search or filter.
        </div>
      </div>

      <!-- Panel Footer -->
      <div class="panel-footer">
        <span id="footerCount">
          <strong><?= $total ?></strong> track<?= $total !== 1 ? 's' : '' ?> &nbsp;·&nbsp;
          <strong><?= $free_cnt ?></strong> free &nbsp;·&nbsp;
          <strong><?= $paid_cnt ?></strong> paid
        </span>
      </div>

    <?php endif; ?>
  </div><!-- /panel -->
</div><!-- /page-wrap -->

<!-- ── Edit Modal ── -->
<div class="modal-overlay" id="editOverlay">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-head-title">
        <i class="fas fa-pen-to-square" style="color:var(--amber)"></i>
        Edit Track
      </div>
      <button class="modal-close" onclick="closeModal('editOverlay')"><i class="fas fa-xmark"></i></button>
    </div>
    <form id="editForm">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="music_id" id="edit_id">
      <div class="modal-body">
        <div class="field">
          <label>Track Title</label>
          <input type="text" name="title" id="edit_title" required placeholder="Enter track title">
        </div>
        <div class="field">
          <label>Artist</label>
          <input type="text" name="artist" id="edit_artist" required placeholder="Artist name">
        </div>
        <div class="field">
          <label>Genre</label>
          <select name="genre" id="edit_genre">
            <option>Afrobeats</option>
            <option>Hip Hop</option>
            <option>R&amp;B</option>
            <option>Gospel</option>
            <option>Gengetone</option>
            <option>Other</option>
          </select>
        </div>
        <div class="free-toggle">
          <input type="checkbox" name="is_free" id="edit_free"
                 onchange="document.getElementById('edit_price_wrap').style.display = this.checked ? 'none' : 'block'">
          <label for="edit_free"><i class="fas fa-gift" style="margin-right:5px"></i>Make this track free</label>
        </div>
        <div class="field" id="edit_price_wrap">
          <label>Price (KSh)</label>
          <input type="number" name="price" id="edit_price" step="0.01" min="0" placeholder="0.00">
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn-modal btn-modal-cancel" onclick="closeModal('editOverlay')">Cancel</button>
        <button type="submit" class="btn-modal btn-modal-save" id="saveBtn">
          <i class="fas fa-floppy-disk"></i> Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Mobile Bottom Nav -->
<nav class="bottom-nav">
  <a href="index.php?page=dashboard" class="nav-item">
    <i class="fas fa-home"></i>Home
  </a>
  <a href="index.php?page=ticket_purchases" class="nav-item">
    <i class="fas fa-ticket-alt"></i>Tickets
  </a>
  <a href="add_music.php" class="nav-fab">
    <div class="nav-fab-icon"><i class="fas fa-plus"></i></div>
    <span>Upload</span>
  </a>
  <a href="index.php?page=my_wallet" class="nav-item">
    <i class="fas fa-wallet"></i>Wallet
  </a>
  <a href="index.php?page=profile" class="nav-item active">
    <i class="fas fa-music"></i>Music
  </a>
</nav>

<script>
// ── Preloader ──────────────────────────────────────────
window.addEventListener('load', function () {
  var p = document.getElementById('preloader');
  p.style.opacity = '0';
  setTimeout(function () { p.style.display = 'none'; }, 400);
});

// ── Search + Filter ────────────────────────────────────
(function () {
  var searchInput = document.getElementById('searchInput');
  var clearBtn    = document.getElementById('clearSearchBtn');
  var filterType  = document.getElementById('filterType');
  var filterGenre = document.getElementById('filterGenre');
  var noResultsD  = document.getElementById('noResults');
  var noResultsM  = document.getElementById('noResultsMob');
  var recordBadge = document.getElementById('recordBadge');

  var allRows  = Array.from(document.querySelectorAll('tr.track-row'));
  var allCards = Array.from(document.querySelectorAll('.m-card'));

  function applyFilters() {
    var q  = searchInput ? searchInput.value.trim().toLowerCase() : '';
    var tp = filterType  ? filterType.value  : 'all';
    var gn = filterGenre ? filterGenre.value : 'all';

    var visibleD = 0, visibleM = 0;

    allRows.forEach(function(el) {
      var matchQ  = !q  || el.dataset.title.includes(q) || el.dataset.artist.includes(q);
      var matchTp = tp === 'all' || el.dataset.type  === tp;
      var matchGn = gn === 'all' || el.dataset.genre === gn;
      var show = matchQ && matchTp && matchGn;
      el.style.display = show ? '' : 'none';
      if (show) visibleD++;
    });

    allCards.forEach(function(el) {
      var matchQ  = !q  || el.dataset.title.includes(q) || el.dataset.artist.includes(q);
      var matchTp = tp === 'all' || el.dataset.type  === tp;
      var matchGn = gn === 'all' || el.dataset.genre === gn;
      var show = matchQ && matchTp && matchGn;
      el.style.display = show ? '' : 'none';
      if (show) visibleM++;
    });

    if (clearBtn)    clearBtn.style.display = q ? 'inline-flex' : 'none';
    if (noResultsD)  noResultsD.style.display  = visibleD === 0 ? 'block' : 'none';
    if (noResultsM)  noResultsM.style.display  = visibleM === 0 ? 'block' : 'none';
    if (recordBadge) recordBadge.textContent = visibleD + ' track' + (visibleD !== 1 ? 's' : '');
  }

  if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
    searchInput.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') { searchInput.value = ''; applyFilters(); }
    });
  }
  if (clearBtn)    clearBtn.addEventListener('click', function() { searchInput.value = ''; applyFilters(); searchInput && searchInput.focus(); });
  if (filterType)  filterType.addEventListener('change', applyFilters);
  if (filterGenre) filterGenre.addEventListener('change', applyFilters);
})();

// ── Modal helpers ──────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('show'); }

// ── Edit ───────────────────────────────────────────────
document.querySelectorAll('.edit-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var track = JSON.parse(this.dataset.track);
    document.getElementById('edit_id').value     = track.id;
    document.getElementById('edit_title').value  = track.title;
    document.getElementById('edit_artist').value = track.artist;
    document.getElementById('edit_genre').value  = track.genre;
    document.getElementById('edit_free').checked = track.is_free == 1;
    document.getElementById('edit_price').value  = track.price;
    document.getElementById('edit_price_wrap').style.display = track.is_free == 1 ? 'none' : 'block';
    document.getElementById('editOverlay').classList.add('show');
  });
});

document.getElementById('editForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  var btn = document.getElementById('saveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

  var formData = new FormData(this);
  try {
    var res  = await fetch('my_music.php', { method: 'POST', body: formData });
    var data = await res.json();

    if (data.success) {
      closeModal('editOverlay');
      Swal.fire({
        icon: 'success', title: 'Track Updated!',
        text: 'Your changes have been saved.',
        toast: true, position: 'top-end', timer: 2800, showConfirmButton: false,
        background: '#f0fdf4'
      });
      setTimeout(function() { location.reload(); }, 900);
    } else {
      Swal.fire({ icon: 'error', title: 'Update Failed', text: data.error || 'Something went wrong.', confirmButtonColor: '#b81236' });
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save Changes';
    }
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not reach the server.', confirmButtonColor: '#b81236' });
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save Changes';
  }
});

// Close modal when clicking outside
document.getElementById('editOverlay').addEventListener('click', function(e) {
  if (e.target === this) closeModal('editOverlay');
});

// ── Delete ─────────────────────────────────────────────
document.querySelectorAll('.delete-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var id    = this.dataset.id;
    var title = this.dataset.title || 'this track';

    Swal.fire({
      icon: 'warning',
      title: 'Delete Track?',
      html: 'You are about to permanently delete <strong>' + title + '</strong>.<br>The audio file and cover art will be removed. This cannot be undone.',
      showCancelButton: true,
      confirmButtonText: '<i class="fas fa-trash-can"></i> Yes, Delete',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#475569',
      reverseButtons: true,
      focusCancel: true,
    }).then(async function(result) {
      if (!result.isConfirmed) return;

      var formData = new FormData();
      formData.append('action',   'delete');
      formData.append('music_id', id);

      try {
        var res  = await fetch('my_music.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
          // Fade out + remove both desktop row and mobile card
          var row  = document.querySelector('tr.track-row[data-title]');
          var card = document.getElementById('track-mob-' + id);

          // Remove row from table
          document.querySelectorAll('tr.track-row').forEach(function(r) {
            if (r.querySelector('.delete-btn') && r.querySelector('.delete-btn').dataset.id == id) {
              r.style.opacity = '0';
              r.style.transition = 'opacity .3s';
              setTimeout(function() { r.remove(); }, 300);
            }
          });
          // Remove mobile card
          document.querySelectorAll('.m-card .delete-btn').forEach(function(b) {
            if (b.dataset.id == id) {
              var c = b.closest('.m-card');
              c.style.opacity = '0';
              c.style.transition = 'opacity .3s';
              setTimeout(function() { c.remove(); }, 300);
            }
          });

          Swal.fire({
            icon: 'success', title: 'Track Deleted',
            toast: true, position: 'top-end', timer: 2500, showConfirmButton: false,
            background: '#f0fdf4'
          });
        } else {
          Swal.fire({ icon: 'error', title: 'Delete Failed', text: data.error || 'Something went wrong.', confirmButtonColor: '#b81236' });
        }
      } catch (err) {
        Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not reach the server.', confirmButtonColor: '#b81236' });
      }
    });
  });
});
</script>
</body>
</html>