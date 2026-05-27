<?php
include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit();
}

$error = null;

try {
    $stmt = $pdo->prepare("
        SELECT
            s.id, s.title, s.description, s.created_at, s.is_active,
            s.deadline, s.allow_anonymous, s.collect_email,
            COUNT(DISTINCT q.id)  AS question_count,
            COUNT(DISTINCT r.id)  AS response_count
        FROM surveys s
        LEFT JOIN survey_questions q  ON q.survey_id = s.id
        LEFT JOIN survey_responses r  ON r.survey_id = s.id
        WHERE s.creator_id = ?
        GROUP BY s.id
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$creator_id]);
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error   = "Error loading surveys: " . htmlspecialchars($e->getMessage());
    $surveys = [];
}

$total_surveys   = count($surveys);
$total_responses = array_sum(array_column($surveys, 'response_count'));
$active_surveys  = count(array_filter($surveys, fn($s) => $s['is_active']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Surveys — Keychele</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
  @font-face {
    font-family: 'GlacialI';
    src: url('https://db.onlinewebfonts.com/t/74f7b46c5a6a4374c10b2cb3ef8b4fc1.woff2') format('woff2');
    font-weight: 400;
  }

  :root {
    --red:    #bf1339;
    --red-l:  #e8143f;
    --gold:   #fec411;
    --gold-d: #d4a60a;
    --navy:   #052e64;
    --bg:     #eef1f8;
    --card:   #ffffff;
    --border: rgba(5,46,100,0.1);
    --text:   #0f1923;
    --muted:  #6b7a99;
    --green:  #00b37e;
    --r:      14px;
    --r-lg:   20px;
    --sh:     0 4px 24px rgba(5,46,100,0.09);
    --sh-lg:  0 14px 48px rgba(5,46,100,0.14);
    --trn:    0.22s ease;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { scroll-behavior: smooth; }

  body {
    font-family: 'GlacialI', 'Trebuchet MS', sans-serif;
    background: var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
    padding-bottom: 80px;
    min-height: 100vh;
  }

  h1,h2,h3,h4,h5 { font-family: 'Montserrat', sans-serif; }

  .page { padding: 1.5rem; max-width: 1060px; margin: 0 auto; }
  @media (max-width: 600px) { .page { padding: 1rem 0.9rem; } }

  /* ─── HERO ─── */
  .page-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(120deg, var(--navy) 0%, #0a3a7a 55%, #1056b8 100%);
    border-radius: var(--r-lg); padding: 2rem 2.25rem 2.25rem;
    margin-bottom: 1.75rem;
    box-shadow: var(--sh-lg), 0 0 0 1px rgba(255,255,255,0.05) inset;
    animation: heroIn 0.55s cubic-bezier(0.16,1,0.3,1) both;
  }
  @keyframes heroIn { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }

  .page-hero::before {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
    background-size: 34px 34px;
  }

  .hero-orb { position: absolute; border-radius: 50%; pointer-events: none; }
  .hero-orb1 { width:280px; height:280px; top:-100px; right:-60px; background: radial-gradient(circle, rgba(254,196,17,0.15) 0%, transparent 65%); }
  .hero-orb2 { width:180px; height:180px; bottom:-70px; left:20%; background: radial-gradient(circle, rgba(191,19,57,0.18) 0%, transparent 65%); }

  .hero-inner { position: relative; z-index: 1; display: flex; align-items: flex-start; justify-content: space-between; gap: 1.5rem; flex-wrap: wrap; }

  .hero-tag {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(254,196,17,0.14); border: 1px solid rgba(254,196,17,0.28);
    border-radius: 100px; padding: 4px 12px;
    font-family: 'Montserrat', sans-serif; font-size: 0.65rem; font-weight: 700;
    letter-spacing: 0.09em; text-transform: uppercase; color: var(--gold); margin-bottom: 0.7rem;
  }
  .hero-title { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: clamp(1.5rem, 3vw, 2.1rem); color: white; letter-spacing: -0.04em; line-height: 1.15; margin-bottom: 0.4rem; }
  .hero-sub { font-size: 0.83rem; color: rgba(255,255,255,0.5); margin-bottom: 1.25rem; }

  .hero-create-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 11px 22px; border-radius: 11px;
    background: linear-gradient(135deg, var(--red), var(--red-l));
    color: white; font-family: 'Montserrat', sans-serif;
    font-weight: 800; font-size: 0.82rem; letter-spacing: 0.02em;
    text-decoration: none; border: none; cursor: pointer;
    box-shadow: 0 4px 18px rgba(191,19,57,0.4);
    transition: transform 0.2s, box-shadow 0.2s;
  }
  .hero-create-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 26px rgba(191,19,57,0.52); }

  /* Hero stat chips */
  .hero-stats { display: flex; flex-wrap: wrap; gap: 0.65rem; margin-top: 1rem; }
  .hero-stat {
    display: flex; align-items: center; gap: 9px;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 11px; padding: 10px 14px;
  }
  .hero-stat-ico {
    width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem; flex-shrink: 0;
  }
  .hero-stat-val { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: 1.2rem; color: white; line-height: 1; letter-spacing: -0.03em; }
  .hero-stat-lbl { font-size: 0.65rem; color: rgba(255,255,255,0.45); margin-top: 2px; font-family: 'Montserrat', sans-serif; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; }

  /* ─── SECTION LABEL ─── */
  .sec-label {
    font-family: 'Montserrat', sans-serif; font-size: 0.68rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted);
    display: flex; align-items: center; gap: 8px; margin-bottom: 1rem;
  }
  .sec-label::before { content: ''; width: 3px; height: 14px; border-radius: 3px; background: linear-gradient(180deg, var(--red), var(--gold)); flex-shrink: 0; }

  /* ─── SEARCH / FILTER BAR ─── */
  .filter-bar {
    display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
    margin-bottom: 1.1rem;
  }

  .search-wrap { position: relative; flex: 1; min-width: 200px; }
  .search-ico { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); font-size: 0.82rem; color: var(--muted); pointer-events: none; }
  .search-input {
    width: 100%;
    font-family: 'GlacialI', sans-serif; font-size: 0.88rem; color: var(--text);
    background: var(--card); border: 1.5px solid var(--border);
    border-radius: 10px; padding: 10px 14px 10px 38px; outline: none;
    transition: border-color var(--trn), box-shadow var(--trn);
  }
  .search-input:focus { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(5,46,100,0.08); }

  .filter-btn {
    display: flex; align-items: center; gap: 6px;
    padding: 10px 16px; border-radius: 10px;
    background: var(--card); border: 1.5px solid var(--border);
    font-family: 'Montserrat', sans-serif; font-size: 0.74rem; font-weight: 700;
    color: var(--muted); cursor: pointer; transition: all 0.18s; white-space: nowrap;
  }
  .filter-btn:hover, .filter-btn.active { border-color: var(--navy); color: var(--navy); background: #f0f4ff; }
  .filter-btn.active { background: rgba(5,46,100,0.08); }

  /* ─── SURVEY CARD ─── */
  .survey-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
  }

  .s-card {
    background: var(--card);
    border-radius: var(--r-lg);
    border: 1px solid var(--border);
    box-shadow: var(--sh);
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
    display: flex; flex-direction: column;
    animation: cardIn 0.45s cubic-bezier(0.16,1,0.3,1) both;
  }
  @keyframes cardIn { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
  .s-card:nth-child(2) { animation-delay: 0.05s; }
  .s-card:nth-child(3) { animation-delay: 0.10s; }
  .s-card:nth-child(4) { animation-delay: 0.15s; }
  .s-card:nth-child(5) { animation-delay: 0.20s; }
  .s-card:nth-child(6) { animation-delay: 0.25s; }

  .s-card:hover { transform: translateY(-3px); box-shadow: var(--sh-lg); border-color: rgba(5,46,100,0.18); }

  /* Colour stripe top */
  .s-card-stripe { height: 4px; background: linear-gradient(90deg, var(--navy), #1056b8); }
  .s-card-stripe.active-stripe { background: linear-gradient(90deg, var(--green), #00d68f); }
  .s-card-stripe.inactive-stripe { background: linear-gradient(90deg, var(--muted), #9ca3af); }

  .s-card-body { padding: 1.1rem 1.25rem; flex: 1; }

  /* Status badge */
  .s-status {
    display: inline-flex; align-items: center; gap: 5px;
    border-radius: 100px; padding: 3px 9px;
    font-family: 'Montserrat', sans-serif; font-size: 0.6rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-bottom: 0.65rem;
  }
  .s-status-dot { width: 6px; height: 6px; border-radius: 50%; }
  .s-status.active { background: rgba(0,179,126,0.1); color: #065f46; }
  .s-status.active .s-status-dot { background: var(--green); animation: pulse 1.4s infinite; }
  .s-status.inactive { background: rgba(107,122,153,0.1); color: var(--muted); }
  .s-status.inactive .s-status-dot { background: var(--muted); }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.4; }
  }

  .s-card-title {
    font-family: 'Montserrat', sans-serif; font-weight: 800;
    font-size: 0.92rem; color: var(--text); line-height: 1.35;
    margin-bottom: 0.4rem;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
  }

  .s-card-desc {
    font-size: 0.78rem; color: var(--muted); line-height: 1.5;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    margin-bottom: 0.9rem;
  }

  /* Stat row */
  .s-meta { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.85rem; }
  .s-meta-chip {
    display: flex; align-items: center; gap: 5px;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 7px; padding: 4px 9px;
    font-family: 'Montserrat', sans-serif; font-size: 0.62rem; font-weight: 700;
    color: var(--muted);
  }
  .s-meta-chip i { font-size: 0.58rem; }
  .s-meta-chip.green { background: rgba(0,179,126,0.08); border-color: rgba(0,179,126,0.18); color: #065f46; }

  .s-card-date {
    font-size: 0.68rem; color: var(--muted); margin-bottom: 0;
    display: flex; align-items: center; gap: 5px;
  }

  /* Action footer */
  .s-card-foot {
    padding: 0.85rem 1.25rem;
    border-top: 1px solid var(--border);
    background: #fafbfd;
    display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
  }

  .s-actions { display: flex; align-items: center; gap: 4px; }

  .s-action-btn {
    width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem; cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.18s; text-decoration: none;
    background: transparent; color: var(--muted);
    position: relative;
  }
  .s-action-btn:hover { background: var(--bg); border-color: var(--border); }

  /* Tooltips */
  .s-action-btn[title]:hover::after {
    content: attr(title);
    position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
    background: rgba(15,25,35,0.88); color: white;
    font-family: 'Montserrat', sans-serif; font-size: 0.6rem; font-weight: 700;
    padding: 4px 8px; border-radius: 6px; white-space: nowrap; pointer-events: none; z-index: 10;
  }

  .s-action-btn.share   { color: #7c3aed; }
  .s-action-btn.details { color: #0369a1; }
  .s-action-btn.view    { color: var(--navy); }
  .s-action-btn.edit    { color: #0369a1; }
  .s-action-btn.del     { color: var(--red); }

  .s-action-btn.share:hover   { background: rgba(124,58,237,0.08); border-color: rgba(124,58,237,0.2); }
  .s-action-btn.details:hover { background: rgba(3,105,161,0.08);  border-color: rgba(3,105,161,0.2); }
  .s-action-btn.view:hover    { background: rgba(5,46,100,0.08);   border-color: var(--border); }
  .s-action-btn.edit:hover    { background: rgba(3,105,161,0.08);  border-color: rgba(3,105,161,0.2); }
  .s-action-btn.del:hover     { background: rgba(191,19,57,0.08);  border-color: rgba(191,19,57,0.2); }

  .s-action-btn.disabled { opacity: 0.35; cursor: not-allowed; pointer-events: none; }

  .s-view-link {
    display: flex; align-items: center; gap: 5px;
    font-family: 'Montserrat', sans-serif; font-size: 0.7rem; font-weight: 700;
    color: var(--navy); text-decoration: none;
    padding: 6px 10px; border-radius: 8px;
    background: rgba(5,46,100,0.06); border: 1px solid rgba(5,46,100,0.12);
    transition: all 0.18s;
  }
  .s-view-link:hover { background: rgba(5,46,100,0.12); }

  /* ─── EMPTY STATE ─── */
  .empty-card {
    background: var(--card);
    border-radius: var(--r-lg);
    border: 1px solid var(--border);
    box-shadow: var(--sh);
    padding: 4rem 2rem;
    text-align: center;
    animation: cardIn 0.5s cubic-bezier(0.16,1,0.3,1) both;
  }

  .empty-ico {
    width: 80px; height: 80px; border-radius: 50%;
    background: rgba(5,46,100,0.07);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; color: var(--navy); opacity: 0.5;
    margin: 0 auto 1.5rem;
  }

  .empty-title { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: 1.4rem; color: var(--text); margin-bottom: 0.65rem; }
  .empty-sub { font-size: 0.88rem; color: var(--muted); line-height: 1.65; max-width: 380px; margin: 0 auto 2rem; }

  .empty-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 26px; border-radius: 12px;
    background: linear-gradient(135deg, var(--navy), var(--navy-l, #0a4494));
    color: white; font-family: 'Montserrat', sans-serif;
    font-weight: 800; font-size: 0.84rem; text-decoration: none;
    box-shadow: 0 4px 18px rgba(5,46,100,0.3);
    transition: transform 0.2s, box-shadow 0.2s;
  }
  .empty-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 26px rgba(5,46,100,0.4); }

  /* ─── TOASTR ─── */
  #toast-container > .toast { border-radius: 12px !important; box-shadow: 0 8px 32px rgba(0,0,0,0.14) !important; font-family: 'GlacialI', sans-serif !important; font-size: 0.875rem !important; padding: 14px 18px 14px 56px !important; }
  #toast-container > .toast-success { background-color: #064E3B !important; border-left: 4px solid #10B981 !important; }
  #toast-container > .toast-error   { background-color: #7F1D1D !important; border-left: 4px solid #EF4444 !important; }
  #toast-container > .toast-info    { background-color: var(--navy) !important; border-left: 4px solid var(--gold) !important; }
  #toast-container > .toast-warning { background-color: #78350F !important; border-left: 4px solid #f59e0b !important; }
  </style>
</head>
<body>

<div class="page">

  <!-- ══ HERO ══ -->
  <div class="page-hero">
    <div class="hero-orb hero-orb1"></div>
    <div class="hero-orb hero-orb2"></div>
    <div class="hero-inner">
      <div class="hero-left">
        <div class="hero-tag"><i class="fa-solid fa-square-poll-horizontal"></i> Surveys</div>
        <h1 class="hero-title">My Surveys</h1>
        <p class="hero-sub">Create, manage and analyze your survey forms</p>
        <a href="index.php?page=survey_form" class="hero-create-btn">
          <i class="fa-solid fa-plus"></i> Create New Survey
        </a>

        <!-- Stats -->
        <div class="hero-stats">
          <div class="hero-stat">
            <div class="hero-stat-ico" style="background:rgba(254,196,17,0.14)">
              <i class="fa-solid fa-clipboard-list" style="color:var(--gold)"></i>
            </div>
            <div>
              <div class="hero-stat-val"><?= $total_surveys ?></div>
              <div class="hero-stat-lbl">Total Surveys</div>
            </div>
          </div>
          <div class="hero-stat">
            <div class="hero-stat-ico" style="background:rgba(0,179,126,0.14)">
              <i class="fa-solid fa-circle-check" style="color:#00b37e"></i>
            </div>
            <div>
              <div class="hero-stat-val"><?= $active_surveys ?></div>
              <div class="hero-stat-lbl">Active</div>
            </div>
          </div>
          <div class="hero-stat">
            <div class="hero-stat-ico" style="background:rgba(255,255,255,0.1)">
              <i class="fa-solid fa-users" style="color:rgba(255,255,255,0.7)"></i>
            </div>
            <div>
              <div class="hero-stat-val"><?= number_format($total_responses) ?></div>
              <div class="hero-stat-lbl">Total Responses</div>
            </div>
          </div>
        </div>
      </div>

      <div style="font-size:5rem;color:rgba(255,255,255,0.07);flex-shrink:0;align-self:center">
        <i class="fa-solid fa-square-poll-horizontal"></i>
      </div>
    </div>
  </div>

  <?php if ($error): ?>
  <div style="background:rgba(191,19,57,0.08);border:1px solid rgba(191,19,57,0.2);border-left:4px solid var(--red);border-radius:10px;padding:1rem 1.15rem;margin-bottom:1.25rem;font-size:0.85rem;color:var(--red);display:flex;align-items:center;gap:8px">
    <i class="fa-solid fa-circle-exclamation"></i> <?= $error ?>
  </div>
  <?php endif; ?>

  <?php if (empty($surveys)): ?>

    <!-- ── EMPTY STATE ── -->
    <div class="empty-card">
      <div class="empty-ico"><i class="fa-solid fa-clipboard-list"></i></div>
      <h2 class="empty-title">No Surveys Yet</h2>
      <p class="empty-sub">Start collecting valuable feedback, RSVPs, or data from your audience. Create your first survey in minutes.</p>
      <a href="index.php?page=survey_form" class="empty-btn">
        <i class="fa-solid fa-plus"></i> Create Your First Survey
      </a>
    </div>

  <?php else: ?>

    <!-- ── FILTER BAR ── -->
    <div class="filter-bar">
      <div class="search-wrap">
        <i class="search-ico fa-solid fa-magnifying-glass"></i>
        <input type="text" class="search-input" id="searchInput" placeholder="Search surveys..." oninput="filterSurveys()">
      </div>
      <button class="filter-btn active" id="filterAll" onclick="setFilter('all', this)">
        All <span style="background:var(--navy);color:white;border-radius:5px;padding:1px 7px;font-size:0.6rem;margin-left:3px"><?= $total_surveys ?></span>
      </button>
      <button class="filter-btn" id="filterActive" onclick="setFilter('active', this)">
        <span class="s-status-dot" style="width:7px;height:7px;border-radius:50%;background:var(--green);display:inline-block"></span>
        Active
      </button>
      <button class="filter-btn" id="filterInactive" onclick="setFilter('inactive', this)">
        <span class="s-status-dot" style="width:7px;height:7px;border-radius:50%;background:var(--muted);display:inline-block"></span>
        Inactive
      </button>
    </div>

    <!-- ── SURVEY GRID ── -->
    <div class="survey-grid" id="surveyGrid">

      <?php foreach ($surveys as $s):
        $is_active  = (bool)$s['is_active'];
        $has_resp   = $s['response_count'] > 0;
        $closed     = !empty($s['deadline']) && date('Y-m-d') > $s['deadline'];
      ?>
      <div class="s-card"
           data-title="<?= strtolower(htmlspecialchars($s['title'])) ?>"
           data-status="<?= $is_active ? 'active' : 'inactive' ?>">

        <!-- Colour stripe -->
        <div class="s-card-stripe <?= $is_active ? 'active-stripe' : 'inactive-stripe' ?>"></div>

        <div class="s-card-body">

          <!-- Status -->
          <div class="s-status <?= $is_active ? 'active' : 'inactive' ?>">
            <div class="s-status-dot"></div>
            <?= $is_active ? 'Active' : 'Inactive' ?>
            <?php if ($closed): ?>
              &nbsp;·&nbsp;<span style="color:#f59e0b">Closed</span>
            <?php endif; ?>
          </div>

          <!-- Title -->
          <div class="s-card-title"><?= htmlspecialchars($s['title']) ?></div>

          <!-- Description -->
          <?php if (!empty($s['description'])): ?>
            <div class="s-card-desc"><?= htmlspecialchars($s['description']) ?></div>
          <?php else: ?>
            <div class="s-card-desc" style="font-style:italic;opacity:0.5">No description added.</div>
          <?php endif; ?>

          <!-- Meta chips -->
          <div class="s-meta">
            <div class="s-meta-chip">
              <i class="fa-solid fa-list-check"></i>
              <?= $s['question_count'] ?> Q
            </div>
            <div class="s-meta-chip <?= $has_resp ? 'green' : '' ?>">
              <i class="fa-solid fa-users"></i>
              <?= number_format($s['response_count']) ?> Response<?= $s['response_count'] != 1 ? 's' : '' ?>
            </div>
            <?php if (!empty($s['deadline'])): ?>
            <div class="s-meta-chip" style="<?= $closed ? 'background:rgba(245,158,11,0.1);border-color:rgba(245,158,11,0.2);color:#92400e' : '' ?>">
              <i class="fa-solid fa-hourglass-half"></i>
              <?= $closed ? 'Closed ' : 'Closes ' ?><?= date('d M', strtotime($s['deadline'])) ?>
            </div>
            <?php endif; ?>
            <?php if ($s['allow_anonymous'] ?? false): ?>
            <div class="s-meta-chip">
              <i class="fa-solid fa-user-secret"></i> Anon OK
            </div>
            <?php endif; ?>
          </div>

          <div class="s-card-date">
            <i class="fa-regular fa-clock" style="font-size:0.6rem"></i>
            Created <?= date('d M Y', strtotime($s['created_at'])) ?>
          </div>

        </div>

        <!-- Action footer -->
        <div class="s-card-foot">

          <div class="s-actions">
            <!-- Share -->
            <button class="s-action-btn share" title="Copy link"
                    onclick="copySurveyLink(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['title'])) ?>')">
              <i class="fa-solid fa-share-nodes"></i>
            </button>
            <!-- View details & analytics -->
            <a href="index.php?page=view_survey&id=<?= $s['id'] ?>"
               class="s-action-btn details" title="View details & responses">
              <i class="fa-solid fa-chart-bar"></i>
            </a>
            <!-- Edit -->
            <a href="index.php?page=survey_edit&id=<?= $s['id'] ?>" class="s-action-btn edit" title="Edit survey">
              <i class="fa-solid fa-pen-to-square"></i>
            </a>
            <!-- Delete -->
            <button class="s-action-btn del" title="Delete survey"
                    onclick="confirmDelete(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['title'])) ?>')">
              <i class="fa-solid fa-trash-can"></i>
            </button>
          </div>

          <!-- Open live survey -->
          <a href="https://keychele.co.ke/surveys.php?id=<?= $s['id'] ?>" target="_blank" class="s-view-link">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Survey
          </a>

        </div>
      </div>
      <?php endforeach; ?>

    </div><!-- /survey-grid -->

    <!-- No results msg (hidden) -->
    <div id="noResults" style="display:none; text-align:center; padding:2.5rem 1rem;">
      <i class="fa-solid fa-magnifying-glass" style="font-size:2rem; color:var(--muted); opacity:0.4; margin-bottom:0.75rem; display:block"></i>
      <p style="font-family:'Montserrat',sans-serif; font-weight:700; font-size:0.88rem; color:var(--muted)">No surveys match your search.</p>
    </div>

  <?php endif; ?>

</div><!-- /page -->


<script>
/* ── TOASTR CONFIG ── */
toastr.options = {
  closeButton:     true,
  progressBar:     true,
  positionClass:   'toast-top-right',
  timeOut:         5000,
  extendedTimeOut: 1500,
  showMethod:      'slideDown',
  hideMethod:      'fadeOut',
  preventDuplicates: true
};

/* ── COPY SURVEY LINK ── */
function copySurveyLink(id, title) {
  const url = `https://keychele.co.ke/surveys.php?id=${id}`;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => {
      toastr.success(
        `<strong>Link copied!</strong><br><small style="word-break:break-all">${url}</small>`,
        `<i class="fa-solid fa-share-nodes" style="margin-right:6px"></i> ${title}`,
        { timeOut: 5000 }
      );
    }).catch(() => prompt("Copy link manually:", url));
  } else {
    prompt("Copy link manually:", url);
  }
}

/* ── DELETE CONFIRM ── */
function confirmDelete(id, title) {
  Swal.fire({
    title: 'Delete Survey?',
    html: `<span style="font-family:'GlacialI',sans-serif;font-size:0.9rem">You are about to permanently delete<br><strong>"${title}"</strong>.<br>All questions &amp; responses will be lost.</span>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#bf1339',
    cancelButtonColor: '#6b7280',
    confirmButtonText: '<i class="fa-solid fa-trash-can"></i> Delete',
    cancelButtonText: 'Cancel',
    reverseButtons: true,
    customClass: {
      popup:   'swal-kc-popup',
      title:   'swal-kc-title',
      confirm: 'swal-kc-confirm'
    }
  }).then(result => {
    if (result.isConfirmed) {
      toastr.warning('Deleting survey...', '', { timeOut: 2000 });
      // Use root-relative path so it works regardless of routing
      window.location.href = `<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') ?>/survey_delete.php?id=${id}`;
    }
  });
}

/* ── FILTER & SEARCH ── */
let currentFilter = 'all';

function setFilter(filter, btn) {
  currentFilter = filter;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  filterSurveys();
}

function filterSurveys() {
  const q    = document.getElementById('searchInput').value.toLowerCase().trim();
  const grid = document.getElementById('surveyGrid');
  if (!grid) return;

  let visible = 0;
  grid.querySelectorAll('.s-card').forEach(card => {
    const title  = card.dataset.title || '';
    const status = card.dataset.status || '';
    const matchQ = !q || title.includes(q);
    const matchF = currentFilter === 'all' || status === currentFilter;

    if (matchQ && matchF) {
      card.style.display = '';
      visible++;
    } else {
      card.style.display = 'none';
    }
  });

  const noRes = document.getElementById('noResults');
  if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
}

/* ── REDIRECT TOASTRS ── */
<?php if (isset($_GET['deleted'])): ?>
toastr.success(
  '<strong>Survey deleted.</strong><br><small>All related data has been permanently removed.</small>',
  '<i class="fa-solid fa-circle-check" style="margin-right:6px"></i> Deleted',
  { timeOut: 5000 }
);
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
  <?php if ($_GET['error'] === 'session'): ?>
toastr.error(
  '<strong>Session expired.</strong><br><small>Please log in again and retry.</small>',
  '<i class="fa-solid fa-circle-exclamation" style="margin-right:6px"></i> Auth Error',
  { timeOut: 7000 }
);
  <?php else: ?>
toastr.error(
  '<strong>Could not delete survey.</strong><br><small>Please try again or contact support.</small>',
  '<i class="fa-solid fa-circle-exclamation" style="margin-right:6px"></i> Error',
  { timeOut: 6000 }
);
  <?php endif; ?>
<?php endif; ?>
</script>

<style>
/* SweetAlert2 Keychele overrides */
.swal2-popup { border-radius: 18px !important; font-family: 'Montserrat', sans-serif !important; }
.swal2-title { font-family: 'Montserrat', sans-serif !important; font-weight: 900 !important; font-size: 1.2rem !important; color: #0f1923 !important; }
.swal2-confirm, .swal2-cancel { border-radius: 10px !important; font-family: 'Montserrat', sans-serif !important; font-weight: 700 !important; font-size: 0.85rem !important; padding: 10px 22px !important; }
</style>

</body>
</html>
