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
} catch (Exception $e) { $survey = null; }

if (!$survey) {
    header("Location: index.php?page=surveys");
    exit();
}

// Load questions
try {
    $stmt = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY position ASC");
    $stmt->execute([$survey_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $questions = []; }

$success = null;
$error   = null;

// ── HANDLE FORM SUBMISSION ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Save survey meta ──
    if ($action === 'save_survey') {
        $title        = trim($_POST['title'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $thank_you    = trim($_POST['thank_you_message'] ?? '');
        $deadline     = trim($_POST['deadline'] ?? '') ?: null;
        $allow_anon   = isset($_POST['allow_anonymous']) ? 1 : 0;
        $collect_email= isset($_POST['collect_email'])   ? 1 : 0;
        $is_active    = isset($_POST['is_active'])       ? 1 : 0;

        if (!$title) {
            $error = "Survey title is required.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE surveys SET title=?, description=?, thank_you_message=?,
                    deadline=?, allow_anonymous=?, collect_email=?, is_active=?, updated_at=NOW()
                    WHERE id=? AND creator_id=?
                ");
                $stmt->execute([$title,$description,$thank_you,$deadline,$allow_anon,$collect_email,$is_active,$survey_id,$creator_id]);
                $success = "Survey details saved successfully.";
                // Reload
                $survey = array_merge($survey, compact('title','description','thank_you_message','deadline','allow_anonymous','collect_email','is_active'));
                $survey['thank_you_message'] = $thank_you;
                $survey['allow_anonymous']   = $allow_anon;
                $survey['collect_email']     = $collect_email;
                $survey['is_active']         = $is_active;
            } catch (Exception $e) {
                $error = "Failed to save: " . htmlspecialchars($e->getMessage());
            }
        }
    }

    // ── Save single question ──
    if ($action === 'save_question') {
        $qid          = (int)($_POST['question_id'] ?? 0);
        $question_text= trim($_POST['question_text'] ?? '');
        $question_type= trim($_POST['question_type'] ?? 'text');
        $is_required  = isset($_POST['is_required']) ? 1 : 0;
        $placeholder  = trim($_POST['placeholder'] ?? '');
        $help_text    = trim($_POST['help_text'] ?? '');
        $position     = (int)($_POST['position'] ?? 0);
        $options_raw  = trim($_POST['options'] ?? '');
        $options      = $options_raw ? json_encode(array_map('trim', explode("\n", $options_raw))) : null;

        if (!$question_text) {
            $error = "Question text is required.";
        } else {
            try {
                if ($qid) {
                    // Update
                    $stmt = $pdo->prepare("
                        UPDATE survey_questions SET question_text=?,question_type=?,is_required=?,
                        options=?,placeholder=?,help_text=?,position=?,created_at=created_at
                        WHERE id=? AND survey_id=?
                    ");
                    $stmt->execute([$question_text,$question_type,$is_required,$options,$placeholder,$help_text,$position,$qid,$survey_id]);
                    $success = "Question updated.";
                } else {
                    // Insert
                    $stmt = $pdo->prepare("
                        INSERT INTO survey_questions (survey_id,question_text,question_type,is_required,options,placeholder,help_text,position)
                        VALUES (?,?,?,?,?,?,?,?)
                    ");
                    $stmt->execute([$survey_id,$question_text,$question_type,$is_required,$options,$placeholder,$help_text,$position]);
                    $success = "Question added.";
                }
                // Reload questions
                $stmt2 = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id=? ORDER BY position ASC");
                $stmt2->execute([$survey_id]);
                $questions = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $error = "Failed: " . htmlspecialchars($e->getMessage());
            }
        }
    }

    // ── Delete question ──
    if ($action === 'delete_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM survey_questions WHERE id=? AND survey_id=?");
            $stmt->execute([$qid, $survey_id]);
            $success = "Question deleted.";
            $stmt2 = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id=? ORDER BY position ASC");
            $stmt2->execute([$survey_id]);
            $questions = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $error = "Failed to delete question.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Edit Survey — <?= htmlspecialchars($survey['title']) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

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
    background: var(--bg); color: var(--text);
    -webkit-font-smoothing: antialiased;
    padding-bottom: 100px; min-height: 100vh;
  }

  h1,h2,h3,h4,h5 { font-family: 'Montserrat', sans-serif; }

  .page { padding: 1.5rem; max-width: 1080px; margin: 0 auto; }
  @media (max-width: 600px) { .page { padding: 1rem 0.9rem; } }

  /* ── HERO ── */
  .page-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(120deg, #1d1160 0%, #052e64 55%, #0a3a7a 100%);
    border-radius: var(--r-lg); padding: 2rem 2.25rem 2.25rem;
    margin-bottom: 1.75rem;
    box-shadow: var(--sh-lg), 0 0 0 1px rgba(255,255,255,0.05) inset;
    animation: heroIn 0.55s cubic-bezier(0.16,1,0.3,1) both;
  }
  @keyframes heroIn { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
  .page-hero::before {
    content:''; position:absolute; inset:0; pointer-events:none;
    background-image: linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),
                      linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);
    background-size: 34px 34px;
  }
  .hero-orb { position:absolute; border-radius:50%; pointer-events:none; }
  .hero-orb1 { width:280px;height:280px;top:-100px;right:-60px; background:radial-gradient(circle,rgba(254,196,17,0.15) 0%,transparent 65%); }
  .hero-orb2 { width:180px;height:180px;bottom:-70px;left:20%; background:radial-gradient(circle,rgba(191,19,57,0.18) 0%,transparent 65%); }
  .hero-inner { position:relative;z-index:1;display:flex;align-items:flex-start;justify-content:space-between;gap:1.5rem;flex-wrap:wrap; }

  .hero-tag { display:inline-flex;align-items:center;gap:6px; background:rgba(254,196,17,0.14);border:1px solid rgba(254,196,17,0.28); border-radius:100px;padding:4px 12px; font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700; letter-spacing:0.09em;text-transform:uppercase;color:var(--gold);margin-bottom:0.7rem; }
  .hero-title { font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(1.4rem,3vw,2rem);color:white;letter-spacing:-0.04em;line-height:1.15;margin-bottom:0.4rem; }
  .hero-sub   { font-size:0.83rem;color:rgba(255,255,255,0.5);margin-bottom:1.25rem; }
  .breadcrumb { display:flex;align-items:center;gap:6px;font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:600;color:rgba(255,255,255,0.5);margin-bottom:0.75rem; }
  .breadcrumb a { color:rgba(255,255,255,0.65);text-decoration:none; }
  .breadcrumb a:hover { color:white; }
  .breadcrumb i { font-size:0.6rem; }
  .hero-actions { display:flex;gap:0.6rem;flex-wrap:wrap; }
  .hero-btn { display:inline-flex;align-items:center;gap:7px; padding:10px 18px;border-radius:11px; font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.78rem; text-decoration:none;border:none;cursor:pointer;transition:transform 0.2s,box-shadow 0.2s; letter-spacing:0.02em; }
  .hero-btn.primary { background:linear-gradient(135deg,var(--red),var(--red-l));color:white;box-shadow:0 4px 18px rgba(191,19,57,0.4); }
  .hero-btn.primary:hover { transform:translateY(-2px);box-shadow:0 8px 26px rgba(191,19,57,0.52); }
  .hero-btn.ghost   { background:rgba(255,255,255,0.1);color:white;border:1px solid rgba(255,255,255,0.2); }
  .hero-btn.ghost:hover { background:rgba(255,255,255,0.18);transform:translateY(-1px); }

  /* ── SEC LABEL ── */
  .sec-label { font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700; text-transform:uppercase;letter-spacing:0.1em;color:var(--muted); display:flex;align-items:center;gap:8px;margin-bottom:1rem; }
  .sec-label::before { content:'';width:3px;height:14px;border-radius:3px;background:linear-gradient(180deg,var(--red),var(--gold));flex-shrink:0; }

  /* ── LAYOUT ── */
  .layout { display:grid;grid-template-columns:380px 1fr;gap:1.25rem; }
  @media (max-width:880px) { .layout { grid-template-columns:1fr; } }

  /* ── CARD ── */
  .card { background:var(--card);border-radius:var(--r-lg);border:1px solid var(--border);box-shadow:var(--sh);overflow:hidden;margin-bottom:1.1rem;animation:cardIn 0.45s cubic-bezier(0.16,1,0.3,1) both; }
  @keyframes cardIn { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
  .card-header { padding:1rem 1.25rem;border-bottom:1px solid var(--border);background:#fafbfd;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap; }
  .card-header-title { font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.88rem;color:var(--text);display:flex;align-items:center;gap:8px; }
  .card-header-title i { color:var(--navy); }
  .card-body { padding:1.25rem; }

  /* ── FORM ELEMENTS ── */
  .form-group { margin-bottom: 1.1rem; }
  .form-group:last-child { margin-bottom: 0; }

  .form-label {
    display: flex; align-items: center; gap: 6px;
    font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.72rem;
    text-transform: uppercase; letter-spacing: 0.07em; color: var(--muted);
    margin-bottom: 0.4rem;
  }
  .form-label i { font-size: 0.65rem; }
  .form-label .req { color: var(--red); }

  .form-control {
    width: 100%; padding: 10px 13px; border-radius: 10px;
    border: 1.5px solid var(--border); background: var(--bg);
    font-family: 'GlacialI', sans-serif; font-size: 0.88rem; color: var(--text);
    outline: none; transition: border-color var(--trn), box-shadow var(--trn);
    resize: vertical;
  }
  .form-control:focus { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(5,46,100,0.08); background: white; }
  .form-control::placeholder { color: var(--muted); opacity: 0.65; }
  select.form-control { cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7a99' stroke-width='2' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px; }

  /* Toggle switch */
  .toggle-row { display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:0.7rem 0;border-bottom:1px solid var(--border); }
  .toggle-row:last-child { border-bottom:none; }
  .toggle-info-label { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;color:var(--text); }
  .toggle-info-sub   { font-size:0.72rem;color:var(--muted);margin-top:2px; }

  .switch { position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0; }
  .switch input { opacity:0;width:0;height:0; }
  .slider { position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:rgba(5,46,100,0.12);border-radius:100px;transition:0.3s; }
  .slider::before { position:absolute;content:'';height:18px;width:18px;left:3px;bottom:3px;background:white;border-radius:50%;transition:0.3s;box-shadow:0 1px 4px rgba(0,0,0,0.15); }
  input:checked + .slider { background:var(--green); }
  input:checked + .slider::before { transform:translateX(20px); }

  /* Submit button */
  .btn-primary {
    display:inline-flex;align-items:center;gap:8px;
    padding:12px 24px;border-radius:12px;border:none;cursor:pointer;
    background:linear-gradient(135deg,var(--navy),#1056b8);color:white;
    font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.82rem;
    box-shadow:0 4px 18px rgba(5,46,100,0.3);transition:transform 0.2s,box-shadow 0.2s;
    width:100%; justify-content:center;
  }
  .btn-primary:hover { transform:translateY(-2px);box-shadow:0 8px 28px rgba(5,46,100,0.42); }

  .btn-secondary {
    display:inline-flex;align-items:center;gap:7px;
    padding:10px 18px;border-radius:10px;border:1.5px solid var(--border);cursor:pointer;
    background:var(--bg);color:var(--navy);
    font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.78rem;
    transition:all 0.18s;
  }
  .btn-secondary:hover { background:rgba(5,46,100,0.08);border-color:rgba(5,46,100,0.2); }

  .btn-danger {
    display:inline-flex;align-items:center;gap:7px;
    padding:8px 14px;border-radius:9px;border:1.5px solid rgba(191,19,57,0.2);cursor:pointer;
    background:rgba(191,19,57,0.06);color:var(--red);
    font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.74rem;
    transition:all 0.18s;
  }
  .btn-danger:hover { background:rgba(191,19,57,0.12); }

  .btn-success {
    display:inline-flex;align-items:center;gap:7px;
    padding:8px 14px;border-radius:9px;border:1.5px solid rgba(0,179,126,0.2);cursor:pointer;
    background:rgba(0,179,126,0.08);color:#065f46;
    font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.74rem;
    transition:all 0.18s;
  }
  .btn-success:hover { background:rgba(0,179,126,0.15); }

  /* Alert */
  .alert { border-radius:10px;padding:0.9rem 1.1rem;margin-bottom:1.1rem;font-size:0.84rem;display:flex;align-items:center;gap:8px; }
  .alert.success { background:rgba(0,179,126,0.08);border:1px solid rgba(0,179,126,0.2);border-left:4px solid var(--green);color:#065f46; }
  .alert.error   { background:rgba(191,19,57,0.08);border:1px solid rgba(191,19,57,0.2);border-left:4px solid var(--red);color:var(--red); }

  /* ── QUESTION EDITOR LIST ── */
  .q-list { list-style:none; }

  .q-item {
    background:var(--bg);border:1px solid var(--border);border-radius:12px;
    padding:0;margin-bottom:0.75rem;overflow:hidden;
    transition:box-shadow 0.2s;
  }
  .q-item.sortable-ghost { opacity:0.4; }
  .q-item:hover { box-shadow:var(--sh); }

  .q-item-header {
    display:flex;align-items:center;gap:10px;
    padding:0.85rem 1rem;cursor:pointer;
    background:transparent;width:100%;border:none;text-align:left;
  }
  .q-item-header:hover { background:rgba(5,46,100,0.03); }
  .q-drag { color:var(--muted);font-size:0.8rem;cursor:grab;padding:4px;flex-shrink:0; }
  .q-drag:active { cursor:grabbing; }
  .q-num { display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;background:var(--navy);color:white;font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.68rem;flex-shrink:0; }
  .q-title { flex:1;font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.84rem;color:var(--text); }
  .q-type-badge { font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;background:rgba(5,46,100,0.08);color:var(--navy);border-radius:6px;padding:3px 8px;white-space:nowrap; }
  .q-req-badge  { font-size:0.6rem;color:var(--red); }
  .q-chevron    { color:var(--muted);font-size:0.72rem;transition:transform 0.2s;flex-shrink:0; }
  .q-item.open .q-chevron { transform:rotate(180deg); }

  .q-item-body { display:none;padding:0 1rem 1rem;border-top:1px solid var(--border); }
  .q-item.open .q-item-body { display:block; }

  /* Question form inside the collapsible */
  .q-form-grid { display:grid;grid-template-columns:1fr 1fr;gap:0.85rem;margin-top:0.9rem; }
  @media (max-width:500px) { .q-form-grid { grid-template-columns:1fr; } }
  .q-form-grid .span2 { grid-column:1/-1; }
  .q-form-actions { display:flex;align-items:center;gap:0.6rem;margin-top:1rem;flex-wrap:wrap; }

  /* Add question panel */
  .add-q-panel { background:white;border:2px dashed rgba(5,46,100,0.15);border-radius:14px;padding:1.5rem;text-align:center;cursor:pointer;transition:all 0.2s;margin-bottom:0.5rem; }
  .add-q-panel:hover { border-color:var(--navy);background:rgba(5,46,100,0.03); }
  .add-q-icon { font-size:1.6rem;color:rgba(5,46,100,0.2);margin-bottom:0.5rem; }
  .add-q-label { font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.82rem;color:var(--navy);margin-bottom:0.25rem; }
  .add-q-sub   { font-size:0.74rem;color:var(--muted); }

  /* New Q form (hidden by default) */
  #newQForm { display:none; background:var(--card);border:1.5px solid var(--border);border-radius:14px;padding:1.25rem;margin-bottom:0.75rem; }
  #newQForm.open { display:block; }

  /* Toastr */
  #toast-container > .toast { border-radius:12px !important; font-family:'GlacialI',sans-serif !important; font-size:0.875rem !important; padding:14px 18px 14px 56px !important; }
  #toast-container > .toast-success { background-color:#064E3B !important; border-left:4px solid #10B981 !important; }
  #toast-container > .toast-error   { background-color:#7F1D1D !important; border-left:4px solid #EF4444 !important; }
  #toast-container > .toast-info    { background-color:var(--navy) !important; border-left:4px solid var(--gold) !important; }

  .swal2-popup { border-radius:18px !important; font-family:'Montserrat',sans-serif !important; }
  .swal2-title { font-family:'Montserrat',sans-serif !important; font-weight:900 !important; font-size:1.1rem !important; }
  .swal2-confirm,.swal2-cancel { border-radius:10px !important; font-family:'Montserrat',sans-serif !important; font-weight:700 !important; }

  .char-hint { font-size:0.7rem;color:var(--muted);margin-top:3px;text-align:right; }
  </style>
</head>
<body>

<div class="page">

  <!-- ══ HERO ══ -->
  <div class="page-hero">
    <div class="hero-orb hero-orb1"></div>
    <div class="hero-orb hero-orb2"></div>
    <div class="hero-inner">
      <div>
        <div class="breadcrumb">
          <a href="index.php?page=surveys"><i class="fa-solid fa-house-chimney"></i> Surveys</a>
          <i class="fa-solid fa-chevron-right"></i>
          <a href="index.php?page=view_survey&id=<?= $survey_id ?>">View</a>
          <i class="fa-solid fa-chevron-right"></i>
          <span>Edit</span>
        </div>
        <div class="hero-tag"><i class="fa-solid fa-pen-to-square"></i> Editing</div>
        <h1 class="hero-title">Edit Survey</h1>
        <p class="hero-sub"><?= htmlspecialchars($survey['title']) ?></p>
        <div class="hero-actions">
          <a href="index.php?page=view_survey&id=<?= $survey_id ?>" class="hero-btn ghost">
            <i class="fa-solid fa-eye"></i> View Survey
          </a>
          <a href="https://keychele.co.ke/surveys.php?id=<?= $survey_id ?>" target="_blank" class="hero-btn ghost">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Live
          </a>
        </div>
      </div>
      <div style="font-size:5rem;color:rgba(255,255,255,0.06);flex-shrink:0;align-self:center">
        <i class="fa-solid fa-pen-to-square"></i>
      </div>
    </div>
  </div><!-- /hero -->


  <?php if ($success): ?>
  <div class="alert success"><i class="fa-solid fa-circle-check"></i> <?= $success ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> <?= $error ?></div>
  <?php endif; ?>


  <div class="layout">

    <!-- ── LEFT: SURVEY META FORM ── -->
    <div>
      <div class="sec-label">Survey Settings</div>

      <div class="card">
        <div class="card-header">
          <div class="card-header-title"><i class="fa-solid fa-sliders"></i> Survey Details</div>
        </div>
        <div class="card-body">
          <form method="POST" id="surveyForm">
            <input type="hidden" name="action" value="save_survey">

            <div class="form-group">
              <label class="form-label"><i class="fa-solid fa-heading"></i> Title <span class="req">*</span></label>
              <input type="text" name="title" class="form-control" required
                     value="<?= htmlspecialchars($survey['title']) ?>"
                     placeholder="Survey title…" maxlength="200" oninput="updateCharHint(this,'titleHint')">
              <div class="char-hint" id="titleHint"><?= strlen($survey['title']) ?>/200</div>
            </div>

            <div class="form-group">
              <label class="form-label"><i class="fa-solid fa-align-left"></i> Description</label>
              <textarea name="description" class="form-control" rows="3"
                        placeholder="Brief description shown to respondents…"
                        maxlength="1000" oninput="updateCharHint(this,'descHint')"><?= htmlspecialchars($survey['description'] ?? '') ?></textarea>
              <div class="char-hint" id="descHint"><?= strlen($survey['description'] ?? '') ?>/1000</div>
            </div>

            <div class="form-group">
              <label class="form-label"><i class="fa-solid fa-heart"></i> Thank You Message</label>
              <textarea name="thank_you_message" class="form-control" rows="3"
                        placeholder="Message shown after submission…"
                        maxlength="500" oninput="updateCharHint(this,'tyHint')"><?= htmlspecialchars($survey['thank_you_message'] ?? '') ?></textarea>
              <div class="char-hint" id="tyHint"><?= strlen($survey['thank_you_message'] ?? '') ?>/500</div>
            </div>

            <div class="form-group">
              <label class="form-label"><i class="fa-solid fa-calendar-xmark"></i> Deadline (optional)</label>
              <input type="date" name="deadline" class="form-control"
                     value="<?= htmlspecialchars($survey['deadline'] ?? '') ?>">
            </div>

            <!-- Toggles -->
            <div style="margin-bottom:1.1rem">
              <div class="toggle-row">
                <div>
                  <div class="toggle-info-label">Active</div>
                  <div class="toggle-info-sub">Allow respondents to submit</div>
                </div>
                <label class="switch">
                  <input type="checkbox" name="is_active" <?= $survey['is_active'] ? 'checked' : '' ?>>
                  <span class="slider"></span>
                </label>
              </div>
              <div class="toggle-row">
                <div>
                  <div class="toggle-info-label">Allow Anonymous</div>
                  <div class="toggle-info-sub">Respondents can submit without identifying</div>
                </div>
                <label class="switch">
                  <input type="checkbox" name="allow_anonymous" <?= $survey['allow_anonymous'] ? 'checked' : '' ?>>
                  <span class="slider"></span>
                </label>
              </div>
              <div class="toggle-row">
                <div>
                  <div class="toggle-info-label">Collect Email</div>
                  <div class="toggle-info-sub">Ask respondents for their email</div>
                </div>
                <label class="switch">
                  <input type="checkbox" name="collect_email" <?= $survey['collect_email'] ? 'checked' : '' ?>>
                  <span class="slider"></span>
                </label>
              </div>
            </div>

            <button type="submit" class="btn-primary">
              <i class="fa-solid fa-floppy-disk"></i> Save Survey Details
            </button>

          </form>
        </div>
      </div>

    </div><!-- /left -->


    <!-- ── RIGHT: QUESTIONS EDITOR ── -->
    <div>
      <div class="sec-label">Questions (<?= count($questions) ?>)</div>

      <!-- Existing Questions (sortable) -->
      <ul class="q-list" id="questionList">
        <?php foreach ($questions as $i => $q):
          $opts = [];
          if (!empty($q['options'])) {
            $opts = json_decode($q['options'], true) ?? [];
            if (!is_array($opts)) $opts = array_map('trim', explode(',', $q['options']));
          }
          $is_choice = in_array($q['question_type'], ['radio','checkbox','select','multiple_choice','dropdown']);
        ?>
        <li class="q-item" data-qid="<?= $q['id'] ?>">

          <!-- Header / toggle -->
          <div class="q-item-header" onclick="toggleQ(this.closest('.q-item'))">
            <i class="fa-solid fa-grip-vertical q-drag"></i>
            <div class="q-num"><?= $i+1 ?></div>
            <div class="q-title"><?= htmlspecialchars(mb_strimwidth($q['question_text'],0,60,'…')) ?></div>
            <?php if ($q['is_required']): ?><span class="q-req-badge"><i class="fa-solid fa-asterisk"></i></span><?php endif; ?>
            <span class="q-type-badge"><?= ucfirst(str_replace('_',' ',$q['question_type'])) ?></span>
            <i class="fa-solid fa-chevron-down q-chevron"></i>
          </div>

          <!-- Editable body -->
          <div class="q-item-body">
            <form method="POST">
              <input type="hidden" name="action" value="save_question">
              <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
              <input type="hidden" name="position" value="<?= $q['position'] ?? $i ?>">

              <div class="q-form-grid">

                <div class="span2">
                  <label class="form-label"><i class="fa-solid fa-question"></i> Question <span class="req">*</span></label>
                  <textarea name="question_text" class="form-control" rows="2" required><?= htmlspecialchars($q['question_text']) ?></textarea>
                </div>

                <div>
                  <label class="form-label"><i class="fa-solid fa-shapes"></i> Type</label>
                  <select name="question_type" class="form-control" onchange="toggleOptionsField(this)">
                    <?php
                    $types = ['text','textarea','number','email','date','time','radio','checkbox','select','rating','file','url'];
                    foreach ($types as $t):
                    ?>
                    <option value="<?= $t ?>" <?= $q['question_type']===$t?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$t)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div>
                  <label class="form-label"><i class="fa-regular fa-comment"></i> Placeholder</label>
                  <input type="text" name="placeholder" class="form-control" value="<?= htmlspecialchars($q['placeholder'] ?? '') ?>" placeholder="Hint text…">
                </div>

                <div class="span2">
                  <label class="form-label"><i class="fa-solid fa-circle-info"></i> Help Text</label>
                  <input type="text" name="help_text" class="form-control" value="<?= htmlspecialchars($q['help_text'] ?? '') ?>" placeholder="Small description shown below question…">
                </div>

                <div class="span2 options-field" <?= !$is_choice ? 'style="display:none"' : '' ?>>
                  <label class="form-label"><i class="fa-solid fa-list-ul"></i> Options <span style="font-weight:400;text-transform:none;letter-spacing:0;font-family:'GlacialI';font-size:0.72rem;color:var(--muted)">(one per line)</span></label>
                  <textarea name="options" class="form-control" rows="4" placeholder="Option 1&#10;Option 2&#10;Option 3"><?= htmlspecialchars(implode("\n", $opts)) ?></textarea>
                </div>

                <div>
                  <label class="form-label" style="margin-bottom:8px"><i class="fa-solid fa-asterisk"></i> Required</label>
                  <label class="switch">
                    <input type="checkbox" name="is_required" <?= $q['is_required'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                  </label>
                </div>

              </div>

              <div class="q-form-actions">
                <button type="submit" class="btn-success"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                <button type="button" class="btn-danger" onclick="deleteQuestion(<?= $q['id'] ?>, '<?= htmlspecialchars(addslashes($q['question_text'])) ?>')">
                  <i class="fa-solid fa-trash-can"></i> Delete
                </button>
              </div>

            </form>
          </div>

        </li>
        <?php endforeach; ?>
      </ul>

      <!-- Add new question panel -->
      <div class="add-q-panel" id="addQPanel" onclick="openNewQ()">
        <div class="add-q-icon"><i class="fa-solid fa-circle-plus"></i></div>
        <div class="add-q-label">Add New Question</div>
        <div class="add-q-sub">Click to expand the question builder</div>
      </div>

      <!-- New question form -->
      <div id="newQForm">
        <div style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.88rem;color:var(--text);margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between">
          <span><i class="fa-solid fa-plus" style="color:var(--navy);margin-right:6px"></i> New Question</span>
          <button type="button" onclick="closeNewQ()" style="background:none;border:none;cursor:pointer;color:var(--muted);font-size:0.75rem;font-family:'Montserrat',sans-serif;font-weight:700">
            <i class="fa-solid fa-xmark"></i> Cancel
          </button>
        </div>

        <form method="POST">
          <input type="hidden" name="action" value="save_question">
          <input type="hidden" name="question_id" value="0">
          <input type="hidden" name="position" value="<?= count($questions) + 1 ?>">

          <div class="q-form-grid">

            <div class="span2">
              <label class="form-label"><i class="fa-solid fa-question"></i> Question <span class="req">*</span></label>
              <textarea name="question_text" class="form-control" rows="2" required placeholder="Enter your question…"></textarea>
            </div>

            <div>
              <label class="form-label"><i class="fa-solid fa-shapes"></i> Type</label>
              <select name="question_type" class="form-control" onchange="toggleOptionsField(this)">
                <?php
                $types = ['text','textarea','number','email','date','time','radio','checkbox','select','rating','file','url'];
                foreach ($types as $t):
                ?>
                <option value="<?= $t ?>"><?= ucfirst(str_replace('_',' ',$t)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label"><i class="fa-regular fa-comment"></i> Placeholder</label>
              <input type="text" name="placeholder" class="form-control" placeholder="Hint text…">
            </div>

            <div class="span2">
              <label class="form-label"><i class="fa-solid fa-circle-info"></i> Help Text</label>
              <input type="text" name="help_text" class="form-control" placeholder="Small description…">
            </div>

            <div class="span2 options-field" style="display:none">
              <label class="form-label"><i class="fa-solid fa-list-ul"></i> Options <span style="font-weight:400;text-transform:none;letter-spacing:0;font-family:'GlacialI';font-size:0.72rem;color:var(--muted)">(one per line)</span></label>
              <textarea name="options" class="form-control" rows="4" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
            </div>

            <div>
              <label class="form-label" style="margin-bottom:8px"><i class="fa-solid fa-asterisk"></i> Required</label>
              <label class="switch">
                <input type="checkbox" name="is_required">
                <span class="slider"></span>
              </label>
            </div>

          </div>

          <div class="q-form-actions">
            <button type="submit" class="btn-primary" style="width:auto;padding:11px 22px">
              <i class="fa-solid fa-plus"></i> Add Question
            </button>
          </div>

        </form>
      </div>

      <!-- Delete question (hidden form) -->
      <form id="deleteQForm" method="POST" style="display:none">
        <input type="hidden" name="action" value="delete_question">
        <input type="hidden" name="question_id" id="deleteQId">
      </form>

    </div><!-- /right -->

  </div><!-- /layout -->

</div><!-- /page -->

<script>
toastr.options = { closeButton:true, progressBar:true, positionClass:'toast-top-right', timeOut:4000 };

<?php if ($success): ?>
toastr.success('<?= addslashes($success) ?>', 'Saved');
<?php endif; ?>
<?php if ($error): ?>
toastr.error('<?= addslashes($error) ?>', 'Error');
<?php endif; ?>

/* ── Toggle question expand ── */
function toggleQ(item) {
  const isOpen = item.classList.contains('open');
  document.querySelectorAll('.q-item.open').forEach(i => i.classList.remove('open'));
  if (!isOpen) item.classList.add('open');
}

/* ── Show/hide options field based on type ── */
const CHOICE_TYPES = ['radio','checkbox','select','multiple_choice','dropdown'];
function toggleOptionsField(sel) {
  const wrap = sel.closest('form').querySelector('.options-field');
  if (wrap) wrap.style.display = CHOICE_TYPES.includes(sel.value) ? '' : 'none';
}

/* ── Add new question panel ── */
function openNewQ() {
  document.getElementById('newQForm').classList.add('open');
  document.getElementById('addQPanel').style.display = 'none';
  document.getElementById('newQForm').scrollIntoView({ behavior:'smooth', block:'start' });
}
function closeNewQ() {
  document.getElementById('newQForm').classList.remove('open');
  document.getElementById('addQPanel').style.display = '';
}

/* ── Delete question ── */
function deleteQuestion(id, text) {
  Swal.fire({
    title: 'Delete Question?',
    html: `<span style="font-family:'GlacialI',sans-serif;font-size:0.88rem">Delete: <strong>"${text.substring(0,80)}"</strong>?<br>This cannot be undone.</span>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#bf1339',
    cancelButtonColor: '#6b7280',
    confirmButtonText: '<i class="fa-solid fa-trash-can"></i> Delete',
    cancelButtonText: 'Cancel',
    reverseButtons: true,
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('deleteQId').value = id;
      document.getElementById('deleteQForm').submit();
    }
  });
}

/* ── Char counter ── */
function updateCharHint(el, hintId) {
  const hint = document.getElementById(hintId);
  if (hint) hint.textContent = el.value.length + '/' + el.maxLength;
}

/* ── Sortable question reorder ── */
Sortable.create(document.getElementById('questionList'), {
  animation: 200,
  handle: '.q-drag',
  ghostClass: 'sortable-ghost',
  onEnd: function(evt) {
    // Update position values & re-number badges
    const items = document.querySelectorAll('#questionList .q-item');
    items.forEach((item, idx) => {
      const posInput = item.querySelector('input[name="position"]');
      if (posInput) posInput.value = idx + 1;
      const numBadge = item.querySelector('.q-num');
      if (numBadge) numBadge.textContent = idx + 1;
    });
    toastr.info('Drag to reorder — save each question to persist the new position.', '', { timeOut: 3000 });
  }
});
</script>

</body>
</html>
