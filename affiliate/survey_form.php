<?php
include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit();
}

$success = "";
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_survey') {
    $title        = trim($_POST['title'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $thank_you    = trim($_POST['thank_you_message'] ?? '');
    $allow_anon   = isset($_POST['allow_anonymous']) ? 1 : 0;
    $collect_email= isset($_POST['collect_email']) ? 1 : 0;
    $deadline     = $_POST['deadline'] ?? null;

    if (empty($title)) $errors[] = "Survey title is required.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO surveys (creator_id, title, description, thank_you_message, allow_anonymous, collect_email, deadline)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $creator_id,
                $title,
                $description ?: null,
                $thank_you ?: null,
                $allow_anon,
                $collect_email,
                $deadline ?: null
            ]);
            $survey_id = $pdo->lastInsertId();

            $questions = $_POST['question_text'] ?? [];
            $types     = $_POST['question_type'] ?? [];
            $required  = $_POST['is_required'] ?? [];
            $options   = $_POST['options'] ?? [];
            $placeholders = $_POST['placeholder'] ?? [];
            $help_texts   = $_POST['help_text'] ?? [];

            foreach ($questions as $i => $q_text) {
                $q_text = trim($q_text);
                if (empty($q_text)) continue;

                $type     = $types[$i] ?? 'text';
                $is_req   = isset($required[$i]) ? 1 : 0;
                $opts     = trim($options[$i] ?? '');
                $ph       = trim($placeholders[$i] ?? '');
                $help     = trim($help_texts[$i] ?? '');
                $position = $i + 1;

                $stmt = $pdo->prepare("
                    INSERT INTO survey_questions
                    (survey_id, question_text, question_type, is_required, options, placeholder, help_text, position)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $survey_id, $q_text, $type, $is_req,
                    $opts ?: null, $ph ?: null, $help ?: null, $position
                ]);
            }

            $pdo->commit();
            $success = "Survey created successfully!";

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Failed to create survey: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Survey — Keychele</title>

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
    font-weight: 400;
  }

  :root {
    --red:     #bf1339;
    --red-l:   #e8143f;
    --gold:    #fec411;
    --gold-d:  #d4a60a;
    --navy:    #052e64;
    --navy-l:  #0a4494;
    --bg:      #eef1f8;
    --card:    #ffffff;
    --border:  rgba(5,46,100,0.1);
    --text:    #0f1923;
    --muted:   #6b7a99;
    --success: #00b37e;
    --warning: #f59e0b;
    --r:       14px;
    --r-lg:    20px;
    --sh:      0 4px 24px rgba(5,46,100,0.10);
    --sh-lg:   0 14px 48px rgba(5,46,100,0.15);
    --trn:     0.22s ease;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'GlacialI', 'Trebuchet MS', sans-serif;
    background: var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
    padding-bottom: 100px;
    min-height: 100vh;
  }

  h1,h2,h3,h4,h5 { font-family: 'Montserrat', sans-serif; }

  .page { padding: 1.5rem; max-width: 860px; margin: 0 auto; }
  @media (max-width: 600px) { .page { padding: 1rem 0.9rem; } }

  /* ─── HERO ─── */
  .page-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(120deg, var(--navy) 0%, #0a3a7a 55%, #0f4faa 100%);
    border-radius: var(--r-lg); padding: 2rem 2.25rem;
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
  .hero-orb1 { width:260px; height:260px; top:-90px; right:-50px; background: radial-gradient(circle, rgba(254,196,17,0.16) 0%, transparent 65%); }
  .hero-orb2 { width:160px; height:160px; bottom:-60px; left:20%; background: radial-gradient(circle, rgba(191,19,57,0.18) 0%, transparent 65%); }

  .hero-inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
  .hero-tag {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(254,196,17,0.14); border: 1px solid rgba(254,196,17,0.28);
    border-radius: 100px; padding: 4px 12px;
    font-family: 'Montserrat', sans-serif; font-size: 0.65rem; font-weight: 700;
    letter-spacing: 0.09em; text-transform: uppercase; color: var(--gold); margin-bottom: 0.65rem;
  }
  .hero-title { font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: clamp(1.4rem, 3vw, 2rem); color: white; letter-spacing: -0.04em; line-height: 1.15; margin-bottom: 0.35rem; }
  .hero-sub { font-size: 0.83rem; color: rgba(255,255,255,0.5); }
  .hero-icon { font-size: 3.5rem; color: rgba(255,255,255,0.1); flex-shrink: 0; }
  @media (max-width: 480px) { .hero-icon { display: none; } }

  /* ─── SECTION LABEL ─── */
  .sec-label {
    font-family: 'Montserrat', sans-serif; font-size: 0.68rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted);
    display: flex; align-items: center; gap: 8px; margin-bottom: 1rem;
  }
  .sec-label::before { content: ''; width: 3px; height: 14px; border-radius: 3px; background: linear-gradient(180deg, var(--red), var(--gold)); flex-shrink: 0; }

  /* ─── CARD ─── */
  .card {
    background: var(--card); border-radius: var(--r-lg);
    border: 1px solid var(--border); box-shadow: var(--sh);
    overflow: hidden; margin-bottom: 1.25rem;
    animation: cardIn 0.5s cubic-bezier(0.16,1,0.3,1) both;
  }
  @keyframes cardIn { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
  .card:nth-child(1) { animation-delay: 0.04s; }
  .card:nth-child(2) { animation-delay: 0.08s; }
  .card:nth-child(3) { animation-delay: 0.12s; }

  .card-head {
    padding: 1.15rem 1.5rem 1rem; border-bottom: 1px solid var(--border);
    background: #fafbfd; display: flex; align-items: center; gap: 10px;
  }
  .card-head-ico { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 0.88rem; flex-shrink: 0; }
  .card-head-title { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.9rem; color: var(--text); }
  .card-head-sub { font-size: 0.72rem; color: var(--muted); margin-top: 2px; }
  .card-body { padding: 1.5rem; }

  /* ─── FORM ELEMENTS ─── */
  .field { margin-bottom: 1.15rem; }
  .field-label {
    display: block; font-family: 'Montserrat', sans-serif;
    font-size: 0.73rem; font-weight: 700; color: #374151;
    margin-bottom: 0.45rem; letter-spacing: 0.01em;
  }
  .field-hint { font-size: 0.68rem; color: var(--muted); margin-top: 5px; }

  .fi { position: relative; }
  .fi-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); font-size: 0.82rem; color: #9ca3af; pointer-events: none; z-index: 1; }

  input[type=text], input[type=date], input[type=url],
  input[type=number], input[type=email], select, textarea {
    width: 100%;
    font-family: 'GlacialI', 'Trebuchet MS', sans-serif; font-size: 0.88rem;
    color: var(--text); background: #fafafa;
    border: 1.5px solid rgba(5,46,100,0.14); border-radius: 10px;
    padding: 11px 14px 11px 40px; outline: none;
    transition: border-color var(--trn), box-shadow var(--trn), background var(--trn);
    appearance: none; -webkit-appearance: none;
  }
  input:focus, select:focus, textarea:focus {
    border-color: var(--navy); background: #fff;
    box-shadow: 0 0 0 3px rgba(5,46,100,0.08);
  }
  .no-ico input, .no-ico select, .no-ico textarea { padding-left: 14px; }
  textarea { resize: vertical; min-height: 90px; padding-left: 14px !important; }
  select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b7a99' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center; background-size: 16px; padding-right: 36px;
  }

  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  @media (max-width: 560px) { .grid2 { grid-template-columns: 1fr; } }
  .grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
  @media (max-width: 700px) { .grid3 { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 420px) { .grid3 { grid-template-columns: 1fr; } }

  /* ─── TOGGLE SWITCH ─── */
  .toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem; padding: 12px 0;
    border-bottom: 1px solid var(--border);
  }
  .toggle-row:last-child { border-bottom: none; padding-bottom: 0; }

  .toggle-info { flex: 1; }
  .toggle-label { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.8rem; color: var(--text); }
  .toggle-sub { font-size: 0.7rem; color: var(--muted); margin-top: 2px; }

  .kc-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
  .kc-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
  .kc-slider {
    position: absolute; inset: 0; border-radius: 100px;
    background: rgba(5,46,100,0.12);
    cursor: pointer; transition: background 0.25s;
  }
  .kc-slider::before {
    content: ''; position: absolute;
    width: 18px; height: 18px; border-radius: 50%;
    background: white; left: 3px; top: 3px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.2);
    transition: transform 0.25s;
  }
  .kc-switch input:checked + .kc-slider { background: var(--navy); }
  .kc-switch input:checked + .kc-slider::before { transform: translateX(20px); }

  /* ─── QUESTION BLOCK ─── */
  .q-block {
    background: #fafbfd;
    border: 1.5px solid var(--border);
    border-radius: var(--r);
    padding: 0;
    margin-bottom: 0.85rem;
    transition: border-color 0.2s, box-shadow 0.2s;
    overflow: hidden;
    animation: cardIn 0.3s cubic-bezier(0.16,1,0.3,1) both;
  }
  .q-block:hover { border-color: rgba(5,46,100,0.22); box-shadow: 0 2px 14px rgba(5,46,100,0.08); }
  .q-block.dragging { opacity: 0.5; border: 2px dashed var(--navy); }

  .q-block-head {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    background: rgba(5,46,100,0.04);
    border-bottom: 1px solid var(--border);
    cursor: grab;
  }
  .q-block-head:active { cursor: grabbing; }

  .q-num {
    width: 26px; height: 26px; border-radius: 7px;
    background: var(--navy); color: white;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Montserrat', sans-serif; font-weight: 800;
    font-size: 0.72rem; flex-shrink: 0;
  }

  .q-drag-handle { color: var(--muted); font-size: 0.8rem; cursor: grab; }
  .q-head-right { display: flex; align-items: center; gap: 6px; margin-left: auto; }

  .q-type-badge {
    display: flex; align-items: center; gap: 5px;
    background: rgba(5,46,100,0.08); border: 1px solid rgba(5,46,100,0.12);
    border-radius: 7px; padding: 4px 9px;
    font-family: 'Montserrat', sans-serif; font-size: 0.62rem; font-weight: 700;
    color: var(--navy); text-transform: uppercase; letter-spacing: 0.05em;
    white-space: nowrap;
  }

  .q-req-badge {
    background: rgba(191,19,57,0.1); border: 1px solid rgba(191,19,57,0.2);
    border-radius: 7px; padding: 4px 9px;
    font-family: 'Montserrat', sans-serif; font-size: 0.6rem; font-weight: 700;
    color: var(--red); text-transform: uppercase; letter-spacing: 0.05em;
    display: none;
  }
  .q-req-badge.show { display: block; }

  .q-del-btn {
    width: 28px; height: 28px; border-radius: 7px;
    background: rgba(191,19,57,0.08); border: 1px solid rgba(191,19,57,0.15);
    display: flex; align-items: center; justify-content: center;
    color: var(--red); font-size: 0.72rem; cursor: pointer;
    transition: background 0.18s;
  }
  .q-del-btn:hover { background: rgba(191,19,57,0.2); }
  .q-del-btn.hidden { display: none; }

  .q-body { padding: 1.1rem 1.25rem 1.25rem; }

  .q-required-toggle {
    display: flex; align-items: center; gap: 8px;
    margin-top: 10px; cursor: pointer; user-select: none;
  }
  .q-required-toggle input { accent-color: var(--red); width: 16px; height: 16px; cursor: pointer; }
  .q-req-text { font-family: 'Montserrat', sans-serif; font-size: 0.72rem; font-weight: 700; color: var(--red); }

  .options-area { display: none; margin-top: 10px; }
  .options-area.show { display: block; }

  /* ─── TYPE PICKER GRID ─── */
  .type-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin-bottom: 12px;
  }
  @media (max-width: 500px) { .type-grid { grid-template-columns: repeat(2, 1fr); } }

  .type-btn {
    display: flex; flex-direction: column; align-items: center;
    gap: 4px; padding: 9px 6px;
    border-radius: 9px;
    border: 1.5px solid var(--border);
    background: #fafafa;
    cursor: pointer;
    transition: all 0.16s;
    font-family: 'Montserrat', sans-serif;
    font-size: 0.6rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
    color: var(--muted);
    text-align: center;
  }
  .type-btn i { font-size: 1rem; color: var(--muted); transition: color 0.16s; }
  .type-btn:hover { border-color: var(--navy); color: var(--navy); background: #f0f4ff; }
  .type-btn:hover i { color: var(--navy); }
  .type-btn.selected { border-color: var(--navy); background: rgba(5,46,100,0.08); color: var(--navy); }
  .type-btn.selected i { color: var(--navy); }

  /* ─── ADD QUESTION BUTTON ─── */
  .add-q-btn {
    display: flex; align-items: center; gap: 8px;
    padding: 11px 18px; border-radius: 10px;
    background: rgba(5,46,100,0.06); border: 1.5px dashed rgba(5,46,100,0.22);
    color: var(--navy);
    font-family: 'Montserrat', sans-serif; font-size: 0.8rem; font-weight: 700;
    cursor: pointer; width: 100%; justify-content: center;
    transition: background 0.18s, border-color 0.18s; margin-bottom: 1.25rem;
  }
  .add-q-btn:hover { background: rgba(5,46,100,0.1); border-color: var(--navy); }

  /* ─── COUNTER ─── */
  .q-counter {
    font-family: 'Montserrat', sans-serif; font-weight: 700;
    font-size: 0.72rem; color: var(--muted);
    display: flex; align-items: center; gap: 6px;
    margin-bottom: 1rem;
  }
  .q-counter-num {
    background: var(--navy); color: white;
    width: 22px; height: 22px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.7rem; font-weight: 800;
  }

  /* ─── SUBMIT ─── */
  .submit-btn {
    width: 100%; height: 52px;
    background: linear-gradient(135deg, var(--red) 0%, var(--red-l) 100%);
    border: none; border-radius: var(--r); color: white;
    font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 0.95rem;
    letter-spacing: 0.02em; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 9px;
    box-shadow: 0 4px 20px rgba(191,19,57,0.35);
    transition: transform 0.2s, box-shadow 0.2s;
  }
  .submit-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(191,19,57,0.48); }
  .submit-btn:active { transform: translateY(0); }
  .submit-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }

  /* ─── TOASTR ─── */
  #toast-container > .toast { border-radius: 12px !important; box-shadow: 0 8px 32px rgba(0,0,0,0.14) !important; font-family: 'GlacialI', sans-serif !important; font-size: 0.875rem !important; padding: 14px 18px 14px 56px !important; }
  #toast-container > .toast-success { background-color: #064E3B !important; border-left: 4px solid #10B981 !important; }
  #toast-container > .toast-error   { background-color: #7F1D1D !important; border-left: 4px solid #EF4444 !important; }
  #toast-container > .toast-info    { background-color: var(--navy) !important; border-left: 4px solid var(--gold) !important; }

  @keyframes spin { to { transform: rotate(360deg); } }
  .spinner { animation: spin 0.8s linear infinite; display: inline-block; }

  /* ─── COLOUR STRIPE ─── */
  .q-block::before {
    content: ''; display: block; height: 3px;
    background: linear-gradient(90deg, var(--navy), var(--navy-l));
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
      <div>
        <div class="hero-tag"><i class="fa-solid fa-square-poll-horizontal"></i> Surveys</div>
        <h1 class="hero-title">Create a Survey</h1>
        <p class="hero-sub">Build custom forms — collect feedback, RSVPs, data &amp; more</p>
      </div>
      <div class="hero-icon"><i class="fa-solid fa-clipboard-list"></i></div>
    </div>
  </div>

  <form method="POST" id="surveyForm">
    <input type="hidden" name="action" value="create_survey">

    <!-- ══ SURVEY INFO ══ -->
    <div class="sec-label">Survey Information</div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(5,46,100,0.08); color:var(--navy)"><i class="fa-solid fa-file-lines"></i></div>
        <div>
          <div class="card-head-title">Basic Information</div>
          <div class="card-head-sub">Give your survey a title and context</div>
        </div>
      </div>
      <div class="card-body">

        <div class="field">
          <label class="field-label">Survey Title <span style="color:var(--red)">*</span></label>
          <div class="fi">
            <i class="fi-icon fa-solid fa-pen-nib"></i>
            <input type="text" name="title" required placeholder="e.g. Customer Feedback – Nairobi Music Festival 2025">
          </div>
        </div>

        <div class="field no-ico">
          <label class="field-label">Description / Instructions</label>
          <textarea name="description" rows="3" placeholder="e.g. Welcome! Please take a moment to share your thoughts. This should take less than 3 minutes."></textarea>
          <div class="field-hint">Shown to respondents at the top of the survey.</div>
        </div>

        <div class="grid2">
          <div class="field">
            <label class="field-label">Survey Deadline</label>
            <div class="fi">
              <i class="fi-icon fa-solid fa-hourglass-half"></i>
              <input type="date" name="deadline">
            </div>
            <div class="field-hint">Optional — when responses close.</div>
          </div>
          <div class="field no-ico">
            <label class="field-label">Thank-You Message</label>
            <input type="text" name="thank_you_message" placeholder="e.g. Thank you! Your response has been recorded." style="padding-left:14px">
            <div class="field-hint">Shown after submission.</div>
          </div>
        </div>

      </div>
    </div>

    <!-- ══ SETTINGS ══ -->
    <div class="sec-label" style="margin-top:1.5rem">Survey Settings</div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(254,196,17,0.12); color:var(--gold-d)"><i class="fa-solid fa-sliders"></i></div>
        <div>
          <div class="card-head-title">Response Options</div>
          <div class="card-head-sub">Control how people respond to your survey</div>
        </div>
      </div>
      <div class="card-body">

        <div class="toggle-row">
          <div class="toggle-info">
            <div class="toggle-label"><i class="fa-solid fa-user-secret" style="margin-right:6px;color:var(--navy)"></i> Allow Anonymous Responses</div>
            <div class="toggle-sub">Respondents are not required to identify themselves</div>
          </div>
          <label class="kc-switch">
            <input type="checkbox" name="allow_anonymous" value="1" checked>
            <span class="kc-slider"></span>
          </label>
        </div>

        <div class="toggle-row">
          <div class="toggle-info">
            <div class="toggle-label"><i class="fa-solid fa-envelope" style="margin-right:6px;color:var(--navy)"></i> Collect Email Addresses</div>
            <div class="toggle-sub">Ask respondents to provide their email at the start</div>
          </div>
          <label class="kc-switch">
            <input type="checkbox" name="collect_email" value="1">
            <span class="kc-slider"></span>
          </label>
        </div>

      </div>
    </div>

    <!-- ══ QUESTIONS ══ -->
    <div class="sec-label" style="margin-top:1.5rem">
      Survey Questions
      <span style="margin-left:auto; font-size:0.7rem; color:var(--muted); font-weight:500; letter-spacing:0">
        Drag to reorder
      </span>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(191,19,57,0.08); color:var(--red)"><i class="fa-solid fa-list-ul"></i></div>
        <div>
          <div class="card-head-title">Questions Builder</div>
          <div class="card-head-sub">Add any question type — text, choice, rating, date and more</div>
        </div>
      </div>
      <div class="card-body">

        <div class="q-counter">
          <div class="q-counter-num" id="qCountNum">1</div>
          <span id="qCountLabel">Question added</span>
        </div>

        <!-- Questions container -->
        <div id="qContainer">

          <!-- ─── DEFAULT QUESTION ─── -->
          <div class="q-block" data-index="0">
            <div class="q-block-head">
              <i class="fa-solid fa-grip-vertical q-drag-handle"></i>
              <div class="q-num">1</div>
              <div class="q-head-right">
                <span class="q-type-badge q-type-label"><i class="fa-solid fa-font"></i> Short Text</span>
                <span class="q-req-badge">Required</span>
                <button type="button" class="q-del-btn hidden" onclick="removeQ(this)"><i class="fa-solid fa-trash-can"></i></button>
              </div>
            </div>
            <div class="q-body">

              <!-- Question text -->
              <div class="field">
                <label class="field-label">Question <span style="color:var(--red)">*</span></label>
                <div class="fi">
                  <i class="fi-icon fa-solid fa-question"></i>
                  <input type="text" name="question_text[]" required placeholder="e.g. How satisfied were you with the event?">
                </div>
              </div>

              <!-- Answer type picker -->
              <div class="field">
                <label class="field-label">Answer Type</label>
                <div class="type-grid">
                  <button type="button" class="type-btn selected" data-type="text" onclick="setType(this)"><i class="fa-solid fa-font"></i> Short Text</button>
                  <button type="button" class="type-btn" data-type="textarea" onclick="setType(this)"><i class="fa-solid fa-align-left"></i> Long Text</button>
                  <button type="button" class="type-btn" data-type="email" onclick="setType(this)"><i class="fa-solid fa-envelope"></i> Email</button>
                  <button type="button" class="type-btn" data-type="number" onclick="setType(this)"><i class="fa-solid fa-hashtag"></i> Number</button>
                  <button type="button" class="type-btn" data-type="date" onclick="setType(this)"><i class="fa-solid fa-calendar-days"></i> Date</button>
                  <button type="button" class="type-btn" data-type="radio" onclick="setType(this)"><i class="fa-solid fa-circle-dot"></i> Single Choice</button>
                  <button type="button" class="type-btn" data-type="checkbox" onclick="setType(this)"><i class="fa-solid fa-square-check"></i> Multi Choice</button>
                  <button type="button" class="type-btn" data-type="select" onclick="setType(this)"><i class="fa-solid fa-chevron-down"></i> Dropdown</button>
                  <button type="button" class="type-btn" data-type="yesno" onclick="setType(this)"><i class="fa-solid fa-toggle-on"></i> Yes / No</button>
                  <button type="button" class="type-btn" data-type="rating" onclick="setType(this)"><i class="fa-solid fa-star"></i> Star Rating</button>
                  <button type="button" class="type-btn" data-type="scale" onclick="setType(this)"><i class="fa-solid fa-sliders"></i> Scale 1–10</button>
                  <button type="button" class="type-btn" data-type="url" onclick="setType(this)"><i class="fa-solid fa-link"></i> URL / Link</button>
                </div>
                <!-- Hidden input stores actual type -->
                <input type="hidden" name="question_type[]" value="text" class="q-type-val">
              </div>

              <!-- Options (shown for choice types) -->
              <div class="options-area field no-ico">
                <label class="field-label">Answer Choices <span style="font-weight:400;color:var(--muted);font-size:0.68rem">(one per line)</span></label>
                <textarea name="options[]" rows="5" placeholder="Excellent&#10;Very Good&#10;Good&#10;Average&#10;Poor"></textarea>
                <div class="field-hint">Enter each option on a new line. Used for Single Choice, Multi Choice, and Dropdown.</div>
              </div>

              <!-- Placeholder -->
              <div class="grid2">
                <div class="field no-ico">
                  <label class="field-label">Placeholder Text</label>
                  <input type="text" name="placeholder[]" placeholder="e.g. Type your answer here..." style="padding-left:14px">
                  <div class="field-hint">Hint shown inside the answer field.</div>
                </div>
                <div class="field no-ico">
                  <label class="field-label">Help / Subtext</label>
                  <input type="text" name="help_text[]" placeholder="e.g. Please be as specific as possible." style="padding-left:14px">
                  <div class="field-hint">Small text shown below the question.</div>
                </div>
              </div>

              <!-- Required toggle -->
              <label class="q-required-toggle">
                <input type="checkbox" name="is_required[]" value="1" class="q-req-cb" onchange="updateReqBadge(this)">
                <span class="q-req-text"><i class="fa-solid fa-asterisk" style="font-size:0.6rem"></i> Mark as Required</span>
              </label>

            </div>
          </div>
          <!-- /default question -->

        </div><!-- /qContainer -->

        <!-- Add button -->
        <button type="button" class="add-q-btn" onclick="addQuestion()">
          <i class="fa-solid fa-plus"></i> Add Question
        </button>

        <!-- Submit -->
        <button type="submit" class="submit-btn" id="submitBtn">
          <i class="fa-solid fa-floppy-disk"></i> Create Survey
        </button>

      </div>
    </div>

  </form>
</div><!-- /page -->

<script>
/* ════════════════════════════════════
   TYPE LABELS & ICONS
════════════════════════════════════ */
const TYPE_META = {
  text:     { icon: 'fa-font',          label: 'Short Text'     },
  textarea: { icon: 'fa-align-left',    label: 'Long Text'      },
  email:    { icon: 'fa-envelope',      label: 'Email'          },
  number:   { icon: 'fa-hashtag',       label: 'Number'         },
  date:     { icon: 'fa-calendar-days', label: 'Date'           },
  radio:    { icon: 'fa-circle-dot',    label: 'Single Choice'  },
  checkbox: { icon: 'fa-square-check',  label: 'Multi Choice'   },
  select:   { icon: 'fa-chevron-down',  label: 'Dropdown'       },
  yesno:    { icon: 'fa-toggle-on',     label: 'Yes / No'       },
  rating:   { icon: 'fa-star',          label: 'Star Rating'    },
  scale:    { icon: 'fa-sliders',       label: 'Scale 1–10'     },
  url:      { icon: 'fa-link',          label: 'URL / Link'     }
};
const OPTION_TYPES = ['radio','checkbox','select'];

/* ════════════════════════════════════
   SET QUESTION TYPE
════════════════════════════════════ */
function setType(btn) {
  const block  = btn.closest('.q-block');
  const type   = btn.dataset.type;
  const meta   = TYPE_META[type];

  // Update hidden input
  block.querySelector('.q-type-val').value = type;

  // Update button highlight
  block.querySelectorAll('.type-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');

  // Update badge in header
  const badge = block.querySelector('.q-type-label');
  badge.innerHTML = `<i class="fa-solid ${meta.icon}"></i> ${meta.label}`;

  // Show/hide options textarea
  const optArea = block.querySelector('.options-area');
  if (OPTION_TYPES.includes(type)) {
    optArea.classList.add('show');
  } else {
    optArea.classList.remove('show');
  }
}

/* ════════════════════════════════════
   REQUIRED BADGE
════════════════════════════════════ */
function updateReqBadge(cb) {
  const block = cb.closest('.q-block');
  const badge = block.querySelector('.q-req-badge');
  badge.classList.toggle('show', cb.checked);
}

/* ════════════════════════════════════
   ADD QUESTION
════════════════════════════════════ */
let qCount = 1;

function addQuestion() {
  qCount++;
  const idx = qCount - 1;
  const container = document.getElementById('qContainer');

  const div = document.createElement('div');
  div.className = 'q-block';
  div.dataset.index = idx;

  // Build type buttons
  let typeBtns = Object.entries(TYPE_META).map(([type, m]) =>
    `<button type="button" class="type-btn${type==='text' ? ' selected' : ''}" data-type="${type}" onclick="setType(this)">
      <i class="fa-solid ${m.icon}"></i> ${m.label}
    </button>`
  ).join('');

  div.innerHTML = `
    <div class="q-block-head">
      <i class="fa-solid fa-grip-vertical q-drag-handle"></i>
      <div class="q-num">${qCount}</div>
      <div class="q-head-right">
        <span class="q-type-badge q-type-label"><i class="fa-solid fa-font"></i> Short Text</span>
        <span class="q-req-badge">Required</span>
        <button type="button" class="q-del-btn" onclick="removeQ(this)"><i class="fa-solid fa-trash-can"></i></button>
      </div>
    </div>
    <div class="q-body">
      <div class="field">
        <label class="field-label">Question <span style="color:var(--red)">*</span></label>
        <div class="fi">
          <i class="fi-icon fa-solid fa-question"></i>
          <input type="text" name="question_text[]" required placeholder="Type your question here...">
        </div>
      </div>

      <div class="field">
        <label class="field-label">Answer Type</label>
        <div class="type-grid">${typeBtns}</div>
        <input type="hidden" name="question_type[]" value="text" class="q-type-val">
      </div>

      <div class="options-area field no-ico">
        <label class="field-label">Answer Choices <span style="font-weight:400;color:var(--muted);font-size:0.68rem">(one per line)</span></label>
        <textarea name="options[]" rows="5" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
        <div class="field-hint">Enter each option on a new line.</div>
      </div>

      <div class="grid2">
        <div class="field no-ico">
          <label class="field-label">Placeholder Text</label>
          <input type="text" name="placeholder[]" placeholder="e.g. Type your answer here..." style="padding-left:14px">
        </div>
        <div class="field no-ico">
          <label class="field-label">Help / Subtext</label>
          <input type="text" name="help_text[]" placeholder="e.g. Please be specific." style="padding-left:14px">
        </div>
      </div>

      <label class="q-required-toggle">
        <input type="checkbox" name="is_required[]" value="1" class="q-req-cb" onchange="updateReqBadge(this)">
        <span class="q-req-text"><i class="fa-solid fa-asterisk" style="font-size:0.6rem"></i> Mark as Required</span>
      </label>
    </div>
  `;

  container.appendChild(div);
  updateDelBtns();
  updateCounter();

  // Scroll to new block
  setTimeout(() => div.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);

  toastr.info('New question added', '', { timeOut: 1600, positionClass: 'toast-bottom-right' });
}

/* ════════════════════════════════════
   REMOVE QUESTION
════════════════════════════════════ */
function removeQ(btn) {
  const block = btn.closest('.q-block');
  block.style.opacity = '0';
  block.style.transform = 'translateY(-8px)';
  block.style.transition = 'all 0.22s ease';
  setTimeout(() => {
    block.remove();
    qCount--;
    renumberQuestions();
    updateDelBtns();
    updateCounter();
  }, 220);
}

/* ════════════════════════════════════
   HELPERS
════════════════════════════════════ */
function renumberQuestions() {
  document.querySelectorAll('.q-block').forEach((b, i) => {
    b.querySelector('.q-num').textContent = i + 1;
  });
}

function updateDelBtns() {
  const blocks = document.querySelectorAll('.q-block');
  blocks.forEach(b => {
    const btn = b.querySelector('.q-del-btn');
    if (blocks.length <= 1) btn.classList.add('hidden');
    else btn.classList.remove('hidden');
  });
}

function updateCounter() {
  const n = document.querySelectorAll('.q-block').length;
  document.getElementById('qCountNum').textContent = n;
  document.getElementById('qCountLabel').textContent = n === 1 ? 'Question added' : 'Questions added';
}

/* ════════════════════════════════════
   FORM SUBMIT
════════════════════════════════════ */
document.getElementById('surveyForm').addEventListener('submit', function() {
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = `
    <svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
    </svg> Creating Survey...
  `;
});

/* ════════════════════════════════════
   TOASTR CONFIG
════════════════════════════════════ */
toastr.options = {
  closeButton:     true,
  progressBar:     true,
  positionClass:   'toast-top-right',
  timeOut:         5000,
  extendedTimeOut: 1500,
  showMethod:      'slideDown',
  hideMethod:      'fadeOut'
};

<?php if ($success): ?>
toastr.success(
  '<strong><?= addslashes($success) ?></strong><br><small>Redirecting to your surveys list...</small>',
  '<i class="fa-solid fa-circle-check" style="margin-right:6px"></i> Survey Created!',
  { timeOut: 3000 }
);
setTimeout(() => { window.location.href = 'index.php?page=my_surveys'; }, 3200);
<?php endif; ?>

<?php if (!empty($errors)): ?>
<?php foreach ($errors as $err): ?>
toastr.error(
  '<strong><?= addslashes($err) ?></strong><br><small>Please fix and try again.</small>',
  '<i class="fa-solid fa-circle-exclamation" style="margin-right:6px"></i> Error',
  { timeOut: 7000 }
);
<?php endforeach; ?>
<?php endif; ?>
</script>

</body>
</html>