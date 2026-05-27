<?php
include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit();
}

$survey_id = (int)($_GET['id'] ?? 0);
if (!$survey_id) {
    header("Location: index.php?page=surveys");
    exit();
}

// Load survey (must belong to this creator)
try {
    $stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ? AND creator_id = ?");
    $stmt->execute([$survey_id, $creator_id]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $survey = null;
}

if (!$survey) {
    header("Location: index.php?page=surveys");
    exit();
}

// Load questions
try {
    $stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY position ASC");
    $stmt->execute([$survey_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $questions = [];
}

// Load responses
try {
    $stmt = $pdo->prepare("SELECT * FROM survey_responses WHERE survey_id = ? ORDER BY submitted_at DESC");
    $stmt->execute([$survey_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $responses = [];
}

$total_responses = count($responses);
$total_questions = count($questions);
$is_active       = (bool)$survey['is_active'];
$closed          = !empty($survey['deadline']) && date('Y-m-d') > $survey['deadline'];

// Build per-question answer stats
$question_stats = [];
foreach ($questions as $q) {
    $qid = $q['id'];
    $question_stats[$qid] = ['answers' => []];
    foreach ($responses as $r) {
        $answers = json_decode($r['answers'], true) ?? [];
        if (isset($answers[$qid])) {
            $question_stats[$qid]['answers'][] = $answers[$qid];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>View Survey — <?= htmlspecialchars($survey['title']) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <!-- Chart.js for response visualisations -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <!-- html2pdf for export -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

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

  .page { padding: 1.5rem; max-width: 1080px; margin: 0 auto; }
  @media (max-width: 600px) { .page { padding: 1rem 0.9rem; } }

  /* ── HERO ── */
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
  .hero-title { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: clamp(1.4rem, 3vw, 2rem); color: white; letter-spacing: -0.04em; line-height: 1.15; margin-bottom: 0.4rem; }
  .hero-desc  { font-size: 0.83rem; color: rgba(255,255,255,0.55); margin-bottom: 1.25rem; max-width: 600px; line-height: 1.6; }

  .hero-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; }
  .hero-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 18px; border-radius: 11px;
    font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 0.78rem;
    text-decoration: none; border: none; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;
    letter-spacing: 0.02em;
  }
  .hero-btn.primary { background: linear-gradient(135deg,var(--red),var(--red-l)); color: white; box-shadow: 0 4px 18px rgba(191,19,57,0.4); }
  .hero-btn.primary:hover { transform: translateY(-2px); box-shadow: 0 8px 26px rgba(191,19,57,0.52); }
  .hero-btn.ghost { background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(255,255,255,0.2); }
  .hero-btn.ghost:hover { background: rgba(255,255,255,0.18); transform: translateY(-1px); }
  .hero-btn.gold { background: linear-gradient(135deg,var(--gold),#f5a623); color: #3a2000; box-shadow: 0 4px 18px rgba(254,196,17,0.4); }
  .hero-btn.gold:hover { transform: translateY(-2px); }

  /* Status badge */
  .s-status {
    display: inline-flex; align-items: center; gap: 5px;
    border-radius: 100px; padding: 4px 12px;
    font-family: 'Montserrat', sans-serif; font-size: 0.62rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.6rem;
  }
  .s-status-dot { width: 7px; height: 7px; border-radius: 50%; }
  .s-status.active { background: rgba(0,179,126,0.15); color: #00b37e; }
  .s-status.active .s-status-dot { background: #00b37e; animation: pulse 1.4s infinite; }
  .s-status.inactive { background: rgba(107,122,153,0.15); color: #6b7a99; }
  .s-status.inactive .s-status-dot { background: #6b7a99; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }

  /* Hero stats */
  .hero-stats { display: flex; flex-wrap: wrap; gap: 0.65rem; margin-top: 1.1rem; }
  .hero-stat {
    display: flex; align-items: center; gap: 9px;
    background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.1);
    border-radius: 11px; padding: 10px 14px;
  }
  .hero-stat-ico { width:32px; height:32px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:0.82rem; flex-shrink:0; }
  .hero-stat-val { font-family:'Montserrat',sans-serif; font-weight:900; font-size:1.15rem; color:white; line-height:1; }
  .hero-stat-lbl { font-size:0.62rem; color:rgba(255,255,255,0.45); margin-top:2px; font-family:'Montserrat',sans-serif; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; }

  /* Breadcrumb */
  .breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-family: 'Montserrat', sans-serif; font-size: 0.72rem; font-weight: 600;
    color: rgba(255,255,255,0.5); margin-bottom: 0.75rem;
  }
  .breadcrumb a { color: rgba(255,255,255,0.65); text-decoration: none; }
  .breadcrumb a:hover { color: white; }
  .breadcrumb i { font-size: 0.6rem; }

  /* Section label */
  .sec-label {
    font-family:'Montserrat',sans-serif; font-size:0.68rem; font-weight:700;
    text-transform:uppercase; letter-spacing:0.1em; color:var(--muted);
    display:flex; align-items:center; gap:8px; margin-bottom:1rem;
  }
  .sec-label::before { content:''; width:3px; height:14px; border-radius:3px; background:linear-gradient(180deg,var(--red),var(--gold)); flex-shrink:0; }

  /* ── GRID LAYOUT ── */
  .layout { display: grid; grid-template-columns: 340px 1fr; gap: 1.25rem; }
  @media (max-width: 860px) { .layout { grid-template-columns: 1fr; } }

  /* ── CARDS ── */
  .card {
    background: var(--card); border-radius: var(--r-lg);
    border: 1px solid var(--border); box-shadow: var(--sh);
    overflow: hidden; margin-bottom: 1.1rem;
    animation: cardIn 0.45s cubic-bezier(0.16,1,0.3,1) both;
  }
  @keyframes cardIn { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }

  .card-header {
    padding: 1rem 1.25rem; border-bottom: 1px solid var(--border);
    background: #fafbfd;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
  }
  .card-header-title {
    font-family:'Montserrat',sans-serif; font-weight:800; font-size:0.88rem; color:var(--text);
    display:flex; align-items:center; gap:8px;
  }
  .card-header-title i { color:var(--navy); font-size:0.82rem; }

  .card-body { padding: 1.25rem; }

  /* Detail list */
  .detail-list { list-style: none; }
  .detail-list li {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 0.7rem 0; border-bottom: 1px solid var(--border); font-size: 0.84rem;
  }
  .detail-list li:last-child { border-bottom: none; }
  .detail-list .dl-icon {
    width: 30px; height: 30px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.72rem; flex-shrink: 0; margin-top: 1px;
    background: rgba(5,46,100,0.07); color: var(--navy);
  }
  .detail-list .dl-label { font-family:'Montserrat',sans-serif; font-size:0.65rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted); margin-bottom:2px; }
  .detail-list .dl-val   { color: var(--text); line-height: 1.4; }

  /* Tag badges */
  .tag {
    display: inline-flex; align-items: center; gap: 4px;
    border-radius: 6px; padding: 3px 9px;
    font-family:'Montserrat',sans-serif; font-size:0.6rem; font-weight:700;
    text-transform:uppercase; letter-spacing:0.05em;
  }
  .tag.green { background:rgba(0,179,126,0.1); color:#065f46; }
  .tag.red   { background:rgba(191,19,57,0.1); color:var(--red); }
  .tag.blue  { background:rgba(5,46,100,0.08); color:var(--navy); }
  .tag.gold  { background:rgba(254,196,17,0.15); color:#a16207; }

  /* ── QUESTION CARDS ── */
  .q-card {
    background: var(--bg); border: 1px solid var(--border); border-radius: 12px;
    padding: 1.1rem 1.2rem; margin-bottom: 0.85rem;
  }
  .q-card:last-child { margin-bottom: 0; }
  .q-num { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:7px; background:var(--navy); color:white; font-family:'Montserrat',sans-serif; font-weight:800; font-size:0.68rem; flex-shrink:0; }
  .q-text { font-family:'Montserrat',sans-serif; font-weight:700; font-size:0.84rem; color:var(--text); flex:1; }
  .q-meta { display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.6rem; }
  .q-help { font-size:0.75rem; color:var(--muted); margin-top:0.4rem; font-style:italic; }

  /* Answers section inside q-card */
  .q-answers { margin-top: 0.85rem; border-top: 1px solid var(--border); padding-top: 0.85rem; }
  .q-answers-title { font-family:'Montserrat',sans-serif; font-size:0.62rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); margin-bottom:0.65rem; display:flex; align-items:center; gap:6px; }

  /* Choice bar chart */
  .choice-bar { margin-bottom: 0.55rem; }
  .choice-bar-label { font-size: 0.78rem; color: var(--text); display:flex; justify-content:space-between; margin-bottom:3px; }
  .choice-bar-track { height: 10px; border-radius: 100px; background: rgba(5,46,100,0.07); overflow:hidden; }
  .choice-bar-fill  { height: 100%; border-radius: 100px; background: linear-gradient(90deg,var(--navy),#1056b8); transition: width 0.8s ease; }

  /* Text answers list */
  .text-answer {
    background: var(--card); border: 1px solid var(--border); border-radius: 8px;
    padding: 0.65rem 0.9rem; font-size: 0.8rem; color: var(--text);
    line-height: 1.5; margin-bottom: 0.45rem;
    display: flex; gap: 8px; align-items: flex-start;
  }
  .text-answer-ico { color: var(--muted); font-size: 0.7rem; margin-top: 2px; flex-shrink: 0; }
  .text-answer.empty { color:var(--muted); font-style:italic; }

  /* Canvas chart */
  .chart-wrap { position: relative; height: 200px; margin-top: 0.75rem; }

  /* ── RESPONSES TABLE ── */
  .resp-table-wrap { overflow-x: auto; }
  .resp-table {
    width: 100%; border-collapse: collapse; font-size: 0.78rem;
  }
  .resp-table th {
    font-family:'Montserrat',sans-serif; font-weight:700; font-size:0.62rem; text-transform:uppercase; letter-spacing:0.07em;
    color:var(--muted); background:#fafbfd; padding:10px 12px; text-align:left;
    border-bottom: 2px solid var(--border); white-space: nowrap;
  }
  .resp-table td { padding:10px 12px; border-bottom:1px solid var(--border); color:var(--text); vertical-align:top; }
  .resp-table tr:last-child td { border-bottom: none; }
  .resp-table tr:hover td { background: rgba(5,46,100,0.03); }
  .resp-table .resp-email { font-family:'Montserrat',sans-serif; font-size:0.7rem; font-weight:600; color:var(--navy); }
  .resp-table .resp-date  { white-space:nowrap; color:var(--muted); font-size:0.72rem; }
  .resp-table .resp-ip    { font-size:0.7rem; color:var(--muted); }

  /* Expand / preview answer button */
  .btn-sm {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 10px; border-radius: 7px; cursor: pointer;
    font-family:'Montserrat',sans-serif; font-size:0.65rem; font-weight:700;
    border: 1px solid var(--border); background: var(--bg); color: var(--navy);
    transition: all 0.18s;
  }
  .btn-sm:hover { background: rgba(5,46,100,0.1); border-color: rgba(5,46,100,0.2); }

  /* Answer modal (inline expand) */
  .ans-preview { font-size:0.76rem; color:var(--text); line-height:1.55; max-width:320px; }

  /* ── EXPORT AREA (hidden until triggered) ── */
  #exportArea { display:none; }

  /* ── TOASTR ── */
  #toast-container > .toast { border-radius:12px !important; box-shadow:0 8px 32px rgba(0,0,0,0.14) !important; font-family:'GlacialI',sans-serif !important; font-size:0.875rem !important; padding:14px 18px 14px 56px !important; }
  #toast-container > .toast-success { background-color:#064E3B !important; border-left:4px solid #10B981 !important; }
  #toast-container > .toast-error   { background-color:#7F1D1D !important; border-left:4px solid #EF4444 !important; }
  #toast-container > .toast-info    { background-color:var(--navy) !important; border-left:4px solid var(--gold) !important; }

  /* SweetAlert2 */
  .swal2-popup { border-radius:18px !important; font-family:'Montserrat',sans-serif !important; }
  .swal2-title { font-family:'Montserrat',sans-serif !important; font-weight:900 !important; font-size:1.15rem !important; }
  .swal2-confirm, .swal2-cancel { border-radius:10px !important; font-family:'Montserrat',sans-serif !important; font-weight:700 !important; font-size:0.85rem !important; }

  /* No responses placeholder */
  .no-resp { text-align:center; padding:3rem 1rem; color:var(--muted); }
  .no-resp i { font-size:2.5rem; opacity:0.25; margin-bottom:1rem; display:block; }
  .no-resp p { font-family:'Montserrat',sans-serif; font-weight:700; font-size:0.88rem; }

  @media print {
    .no-print { display:none !important; }
    body { background:white; }
    .page { max-width:100%; padding:0; }
    .card { box-shadow:none; border:1px solid #ddd; break-inside:avoid; }
  }
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
        <!-- Breadcrumb -->
        <div class="breadcrumb">
          <a href="index.php?page=surveys"><i class="fa-solid fa-house-chimney"></i> Surveys</a>
          <i class="fa-solid fa-chevron-right"></i>
          <span>View Survey</span>
        </div>

        <!-- Status -->
        <div class="s-status <?= $is_active ? 'active' : 'inactive' ?>">
          <div class="s-status-dot"></div>
          <?= $is_active ? 'Active' : 'Inactive' ?>
          <?php if ($closed): ?>&nbsp;·&nbsp;<span style="color:#f59e0b">Deadline Passed</span><?php endif; ?>
        </div>

        <h1 class="hero-title"><?= htmlspecialchars($survey['title']) ?></h1>
        <?php if (!empty($survey['description'])): ?>
          <p class="hero-desc"><?= htmlspecialchars($survey['description']) ?></p>
        <?php endif; ?>

        <div class="hero-actions no-print">
          <a href="index.php?page=survey_edit&id=<?= $survey_id ?>" class="hero-btn ghost">
            <i class="fa-solid fa-pen-to-square"></i> Edit Survey
          </a>
          <button class="hero-btn gold" onclick="exportToPDF()">
            <i class="fa-solid fa-file-pdf"></i> Export PDF
          </button>
          <button class="hero-btn ghost" onclick="exportCSV()">
            <i class="fa-solid fa-file-csv"></i> Export CSV
          </button>
          <button class="hero-btn ghost" onclick="copySurveyLink(<?= $survey_id ?>)">
            <i class="fa-solid fa-share-nodes"></i> Share Link
          </button>
        </div>

        <!-- Hero stats -->
        <div class="hero-stats">
          <div class="hero-stat">
            <div class="hero-stat-ico" style="background:rgba(254,196,17,0.14)">
              <i class="fa-solid fa-list-check" style="color:var(--gold)"></i>
            </div>
            <div>
              <div class="hero-stat-val"><?= $total_questions ?></div>
              <div class="hero-stat-lbl">Questions</div>
            </div>
          </div>
          <div class="hero-stat">
            <div class="hero-stat-ico" style="background:rgba(0,179,126,0.14)">
              <i class="fa-solid fa-users" style="color:#00b37e"></i>
            </div>
            <div>
              <div class="hero-stat-val"><?= number_format($total_responses) ?></div>
              <div class="hero-stat-lbl">Responses</div>
            </div>
          </div>
          <?php if (!empty($survey['deadline'])): ?>
          <div class="hero-stat">
            <div class="hero-stat-ico" style="background:rgba(255,255,255,0.1)">
              <i class="fa-solid fa-hourglass-half" style="color:rgba(255,255,255,0.7)"></i>
            </div>
            <div>
              <div class="hero-stat-val"><?= date('d M', strtotime($survey['deadline'])) ?></div>
              <div class="hero-stat-lbl">Deadline</div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div style="font-size:5rem;color:rgba(255,255,255,0.06);flex-shrink:0;align-self:center">
        <i class="fa-solid fa-chart-bar"></i>
      </div>
    </div>
  </div><!-- /hero -->


  <!-- ══ MAIN CONTENT ══ -->
  <div id="printArea">

    <div class="layout">

      <!-- ── LEFT COLUMN: SURVEY DETAILS ── -->
      <div>

        <!-- Survey Details Card -->
        <div class="card">
          <div class="card-header">
            <div class="card-header-title"><i class="fa-solid fa-circle-info"></i> Survey Details</div>
          </div>
          <div class="card-body" style="padding: 0.5rem 1.25rem 1rem;">
            <ul class="detail-list">

              <li>
                <div class="dl-icon"><i class="fa-solid fa-heading"></i></div>
                <div>
                  <div class="dl-label">Title</div>
                  <div class="dl-val"><?= htmlspecialchars($survey['title']) ?></div>
                </div>
              </li>

              <?php if (!empty($survey['description'])): ?>
              <li>
                <div class="dl-icon"><i class="fa-solid fa-align-left"></i></div>
                <div>
                  <div class="dl-label">Description</div>
                  <div class="dl-val"><?= nl2br(htmlspecialchars($survey['description'])) ?></div>
                </div>
              </li>
              <?php endif; ?>

              <?php if (!empty($survey['thank_you_message'])): ?>
              <li>
                <div class="dl-icon"><i class="fa-solid fa-heart"></i></div>
                <div>
                  <div class="dl-label">Thank You Message</div>
                  <div class="dl-val"><?= nl2br(htmlspecialchars($survey['thank_you_message'])) ?></div>
                </div>
              </li>
              <?php endif; ?>

              <li>
                <div class="dl-icon"><i class="fa-solid fa-toggle-on"></i></div>
                <div>
                  <div class="dl-label">Status</div>
                  <div class="dl-val">
                    <?php if ($is_active): ?>
                      <span class="tag green"><i class="fa-solid fa-circle-check"></i> Active</span>
                    <?php else: ?>
                      <span class="tag red"><i class="fa-solid fa-circle-xmark"></i> Inactive</span>
                    <?php endif; ?>
                  </div>
                </div>
              </li>

              <li>
                <div class="dl-icon"><i class="fa-solid fa-user-secret"></i></div>
                <div>
                  <div class="dl-label">Anonymous Responses</div>
                  <div class="dl-val">
                    <?= $survey['allow_anonymous'] ? '<span class="tag green">Allowed</span>' : '<span class="tag red">Not Allowed</span>' ?>
                  </div>
                </div>
              </li>

              <li>
                <div class="dl-icon"><i class="fa-solid fa-envelope"></i></div>
                <div>
                  <div class="dl-label">Collect Email</div>
                  <div class="dl-val">
                    <?= $survey['collect_email'] ? '<span class="tag blue">Yes</span>' : '<span class="tag red">No</span>' ?>
                  </div>
                </div>
              </li>

              <?php if (!empty($survey['deadline'])): ?>
              <li>
                <div class="dl-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
                <div>
                  <div class="dl-label">Deadline</div>
                  <div class="dl-val">
                    <?= date('d F Y', strtotime($survey['deadline'])) ?>
                    <?php if ($closed): ?>
                      &nbsp;<span class="tag gold">Closed</span>
                    <?php endif; ?>
                  </div>
                </div>
              </li>
              <?php endif; ?>

              <li>
                <div class="dl-icon"><i class="fa-regular fa-clock"></i></div>
                <div>
                  <div class="dl-label">Created</div>
                  <div class="dl-val"><?= date('d F Y, h:i A', strtotime($survey['created_at'])) ?></div>
                </div>
              </li>

              <li>
                <div class="dl-icon"><i class="fa-solid fa-rotate"></i></div>
                <div>
                  <div class="dl-label">Last Updated</div>
                  <div class="dl-val"><?= date('d F Y, h:i A', strtotime($survey['updated_at'])) ?></div>
                </div>
              </li>

              <li>
                <div class="dl-icon"><i class="fa-solid fa-link"></i></div>
                <div>
                  <div class="dl-label">Survey Link</div>
                  <div class="dl-val" style="word-break:break-all; font-size:0.76rem;">
                    <a href="https://keychele.co.ke/surveys.php?id=<?= $survey_id ?>" target="_blank" style="color:var(--navy)">
                      keychele.co.ke/surveys.php?id=<?= $survey_id ?>
                    </a>
                  </div>
                </div>
              </li>

            </ul>
          </div>
        </div>

        <!-- Questions List Card -->
        <div class="card">
          <div class="card-header">
            <div class="card-header-title"><i class="fa-solid fa-list-check"></i> Questions (<?= $total_questions ?>)</div>
          </div>
          <div class="card-body">
            <?php if (empty($questions)): ?>
              <p style="color:var(--muted);font-size:0.84rem;text-align:center;padding:1rem 0;font-style:italic">No questions added yet.</p>
            <?php else: ?>
              <?php foreach ($questions as $i => $q): ?>
              <div class="q-card">
                <div style="display:flex;align-items:flex-start;gap:9px">
                  <div class="q-num"><?= $i+1 ?></div>
                  <div style="flex:1">
                    <div class="q-text"><?= htmlspecialchars($q['question_text']) ?></div>
                    <?php if (!empty($q['help_text'])): ?>
                      <div class="q-help"><?= htmlspecialchars($q['help_text']) ?></div>
                    <?php endif; ?>
                    <div class="q-meta">
                      <span class="tag blue"><?= ucfirst(str_replace('_',' ',$q['question_type'])) ?></span>
                      <?php if ($q['is_required']): ?>
                        <span class="tag red"><i class="fa-solid fa-asterisk"></i> Required</span>
                      <?php else: ?>
                        <span class="tag" style="background:rgba(107,122,153,0.08);color:var(--muted)">Optional</span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($q['placeholder'])): ?>
                      <div style="font-size:0.72rem;color:var(--muted);margin-top:4px"><i class="fa-regular fa-comment"></i> Placeholder: <em><?= htmlspecialchars($q['placeholder']) ?></em></div>
                    <?php endif; ?>
                    <?php
                      // Show options for choice-type questions
                      $opts = [];
                      if (!empty($q['options'])) {
                        $opts = json_decode($q['options'], true) ?? [];
                        if (!is_array($opts)) $opts = array_map('trim', explode(',', $q['options']));
                      }
                      if (!empty($opts)):
                    ?>
                    <div style="margin-top:6px;font-size:0.74rem;color:var(--muted)">
                      <strong style="font-family:'Montserrat',sans-serif;font-size:0.62rem;text-transform:uppercase;letter-spacing:0.06em">Options:</strong>
                      <?php foreach($opts as $opt): ?>
                        <span class="tag" style="background:rgba(5,46,100,0.05);color:var(--navy);margin-right:3px;margin-top:3px"><?= htmlspecialchars($opt) ?></span>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /left column -->


      <!-- ── RIGHT COLUMN: RESPONSES & ANALYTICS ── -->
      <div>

        <!-- Per-question analytics -->
        <div class="sec-label">Response Analytics</div>

        <?php foreach ($questions as $i => $q):
          $qid      = $q['id'];
          $ans_arr  = $question_stats[$qid]['answers'];
          $ans_count = count($ans_arr);

          // Determine if choice-based
          $opts = [];
          if (!empty($q['options'])) {
            $opts = json_decode($q['options'], true) ?? [];
            if (!is_array($opts)) $opts = array_map('trim', explode(',', $q['options']));
          }
          $is_choice = in_array($q['question_type'], ['radio','checkbox','select','multiple_choice','dropdown']);
        ?>
        <div class="card" style="margin-bottom:1rem">
          <div class="card-header">
            <div class="card-header-title">
              <div class="q-num"><?= $i+1 ?></div>
              <?= htmlspecialchars($q['question_text']) ?>
            </div>
            <span class="tag blue" style="white-space:nowrap"><?= $ans_count ?> answer<?= $ans_count!=1?'s':'' ?></span>
          </div>
          <div class="card-body">
            <?php if ($ans_count === 0): ?>
              <p style="color:var(--muted);font-size:0.82rem;font-style:italic;text-align:center;padding:0.75rem 0">No answers yet.</p>
            <?php elseif ($is_choice && !empty($opts)): ?>
              <!-- Bar chart for choice questions -->
              <div class="q-answers">
                <div class="q-answers-title"><i class="fa-solid fa-chart-bar"></i> Distribution</div>
                <?php
                  $counts = array_fill_keys($opts, 0);
                  foreach ($ans_arr as $a) {
                    $vals = is_array($a) ? $a : [$a];
                    foreach ($vals as $v) {
                      if (isset($counts[$v])) $counts[$v]++;
                      else $counts[$v] = 1;
                    }
                  }
                  $max = max($counts) ?: 1;
                ?>
                <?php foreach ($counts as $opt => $cnt): ?>
                  <div class="choice-bar">
                    <div class="choice-bar-label">
                      <span><?= htmlspecialchars($opt) ?></span>
                      <span style="font-family:'Montserrat',sans-serif;font-weight:700"><?= $cnt ?> (<?= $ans_count > 0 ? round($cnt/$ans_count*100) : 0 ?>%)</span>
                    </div>
                    <div class="choice-bar-track">
                      <div class="choice-bar-fill" style="width:<?= $ans_count > 0 ? round($cnt/$ans_count*100) : 0 ?>%"></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php elseif ($q['question_type'] === 'rating' || $q['question_type'] === 'number'): ?>
              <!-- Numeric stats -->
              <?php
                $nums = array_filter(array_map('floatval', $ans_arr));
                $avg  = count($nums) ? array_sum($nums)/count($nums) : 0;
                $min  = count($nums) ? min($nums) : 0;
                $max  = count($nums) ? max($nums) : 0;
              ?>
              <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:0.75rem">
                <?php foreach([['Average', round($avg,1)],['Min',$min],['Max',$max]] as [$lbl,$val]): ?>
                <div style="flex:1;min-width:80px;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:0.75rem;text-align:center">
                  <div style="font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.4rem;color:var(--navy)"><?= $val ?></div>
                  <div style="font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--muted)"><?= $lbl ?></div>
                </div>
                <?php endforeach; ?>
              </div>
              <!-- All answers -->
              <div class="q-answers">
                <div class="q-answers-title"><i class="fa-solid fa-list"></i> All Values</div>
                <?php foreach (array_slice($ans_arr,0,10) as $a): ?>
                  <div class="text-answer">
                    <i class="text-answer-ico fa-solid fa-hashtag"></i>
                    <span><?= htmlspecialchars((string)$a) ?></span>
                  </div>
                <?php endforeach; ?>
                <?php if (count($ans_arr) > 10): ?>
                  <p style="font-size:0.72rem;color:var(--muted);font-style:italic;margin-top:4px">+ <?= count($ans_arr)-10 ?> more in the full export.</p>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <!-- Text answers -->
              <div class="q-answers">
                <div class="q-answers-title"><i class="fa-solid fa-comment-dots"></i> Responses</div>
                <?php foreach (array_slice($ans_arr,0,8) as $a): ?>
                  <?php $txt = is_array($a) ? implode(', ',$a) : (string)$a; ?>
                  <div class="text-answer<?= trim($txt)==='' ? ' empty' : '' ?>">
                    <i class="text-answer-ico fa-regular fa-message"></i>
                    <span><?= $txt !== '' ? htmlspecialchars($txt) : 'No answer' ?></span>
                  </div>
                <?php endforeach; ?>
                <?php if (count($ans_arr) > 8): ?>
                  <p style="font-size:0.72rem;color:var(--muted);font-style:italic;margin-top:4px">Showing 8 of <?= count($ans_arr) ?>. Export CSV for all responses.</p>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- ── ALL RESPONSES TABLE ── -->
        <div class="sec-label" style="margin-top:0.25rem">All Responses</div>
        <div class="card">
          <div class="card-header">
            <div class="card-header-title"><i class="fa-solid fa-table"></i> Response Log</div>
            <span class="tag blue"><?= $total_responses ?> total</span>
          </div>
          <div class="card-body" style="padding:0">

            <?php if (empty($responses)): ?>
              <div class="no-resp">
                <i class="fa-solid fa-inbox"></i>
                <p>No responses received yet.</p>
              </div>
            <?php else: ?>
              <div class="resp-table-wrap">
                <table class="resp-table">
                  <thead>
                    <tr>
                      <th>#</th>
                      <?php if ($survey['collect_email']): ?><th>Email</th><?php endif; ?>
                      <th>IP Address</th>
                      <th>Submitted At</th>
                      <th>Answers</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($responses as $ri => $r):
                      $answers = json_decode($r['answers'], true) ?? [];
                    ?>
                    <tr>
                      <td style="font-family:'Montserrat',sans-serif;font-weight:700;color:var(--muted);font-size:0.7rem"><?= $ri+1 ?></td>
                      <?php if ($survey['collect_email']): ?>
                      <td class="resp-email"><?= htmlspecialchars($r['respondent_email'] ?? '—') ?></td>
                      <?php endif; ?>
                      <td class="resp-ip"><?= htmlspecialchars($r['respondent_ip'] ?? '—') ?></td>
                      <td class="resp-date"><?= date('d M Y, h:i A', strtotime($r['submitted_at'])) ?></td>
                      <td>
                        <button class="btn-sm no-print" onclick="showAnswers(<?= htmlspecialchars(json_encode($answers), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($questions), ENT_QUOTES) ?>)">
                          <i class="fa-solid fa-eye"></i> View
                        </button>
                        <div style="display:none" class="print-answers">
                          <?php foreach ($questions as $q):
                            $a = $answers[$q['id']] ?? '—';
                            $a = is_array($a) ? implode(', ', $a) : $a;
                          ?>
                            <div style="font-size:0.7rem;margin-bottom:2px"><strong><?= htmlspecialchars($q['question_text']) ?>:</strong> <?= htmlspecialchars($a) ?></div>
                          <?php endforeach; ?>
                        </div>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

          </div>
        </div><!-- /responses card -->

      </div><!-- /right column -->

    </div><!-- /layout -->

  </div><!-- /printArea -->

</div><!-- /page -->


<!-- ══ ANSWER MODAL ══ -->
<script>
toastr.options = { closeButton:true, progressBar:true, positionClass:'toast-top-right', timeOut:4000 };

function showAnswers(answers, questions) {
  let html = '<div style="text-align:left;font-family:\'GlacialI\',sans-serif">';
  questions.forEach(q => {
    let a = answers[q.id] ?? null;
    if (a === null) a = '<em style="color:#6b7a99">No answer</em>';
    else if (Array.isArray(a)) a = a.join(', ');
    html += `
      <div style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid rgba(5,46,100,0.08)">
        <div style="font-family:\'Montserrat\',sans-serif;font-weight:700;font-size:0.78rem;color:#052e64;margin-bottom:4px">${q.question_text}</div>
        <div style="font-size:0.83rem;color:#0f1923">${a}</div>
      </div>`;
  });
  html += '</div>';

  Swal.fire({
    title: '<span style="font-family:\'Montserrat\',sans-serif;font-weight:900;font-size:1rem">Response Details</span>',
    html:  html,
    width: 540,
    showConfirmButton: false,
    showCloseButton: true,
    customClass: { popup:'swal-kc-popup' }
  });
}

function copySurveyLink(id) {
  const url = `https://keychele.co.ke/surveys.php?id=${id}`;
  navigator.clipboard.writeText(url).then(() => {
    toastr.success(`<strong>Link copied!</strong><br><small>${url}</small>`, 'Share Link');
  });
}

function exportToPDF() {
  toastr.info('Preparing PDF export...', '', { timeOut: 2000 });

  // Show print-only answer cells
  document.querySelectorAll('.print-answers').forEach(el => el.style.display = 'block');
  document.querySelectorAll('.btn-sm').forEach(el => el.style.display = 'none');

  const element = document.getElementById('printArea');
  const opt = {
    margin:      [10, 10, 10, 10],
    filename:    `survey-<?= $survey_id ?>-<?= date('Ymd') ?>.pdf`,
    image:       { type: 'jpeg', quality: 0.95 },
    html2canvas: { scale: 2, useCORS: true, scrollY: 0 },
    jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' },
    pagebreak:   { mode: ['avoid-all', 'css', 'legacy'] }
  };

  html2pdf().set(opt).from(element).save().then(() => {
    // Restore
    document.querySelectorAll('.print-answers').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.btn-sm').forEach(el => el.style.display = '');
    toastr.success('PDF exported successfully!', '');
  });
}

function exportCSV() {
  // Build CSV from PHP-rendered data
  const questions = <?= json_encode(array_map(fn($q) => ['id' => $q['id'], 'text' => $q['question_text']], $questions)) ?>;
  const responses = <?= json_encode(array_map(fn($r) => [
    'email'        => $r['respondent_email'] ?? '',
    'ip'           => $r['respondent_ip'] ?? '',
    'submitted_at' => $r['submitted_at'],
    'answers'      => json_decode($r['answers'], true) ?? []
  ], $responses)) ?>;

  if (responses.length === 0) {
    toastr.warning('No responses to export.', '');
    return;
  }

  const escape = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';

  // Header row
  let headers = ['#', 'Email', 'IP', 'Submitted At'];
  questions.forEach(q => headers.push(q.text));
  let csv = headers.map(escape).join(',') + '\n';

  // Data rows
  responses.forEach((r, i) => {
    let row = [i+1, r.email, r.ip, r.submitted_at];
    questions.forEach(q => {
      let a = r.answers[q.id] ?? '';
      if (Array.isArray(a)) a = a.join('; ');
      row.push(a);
    });
    csv += row.map(escape).join(',') + '\n';
  });

  const blob = new Blob([csv], { type: 'text/csv' });
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = `survey-<?= $survey_id ?>-<?= date('Ymd') ?>.csv`;
  a.click();
  toastr.success('CSV exported!', '');
}
</script>

</body>
</html>
