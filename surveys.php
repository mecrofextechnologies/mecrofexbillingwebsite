<?php
include 'msanii/includes/connect.php';

$survey_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($survey_id <= 0) {
    http_response_code(404);
    die("Invalid survey link.");
}

try {
    $stmt = $pdo->prepare("
        SELECT id, title, description, is_active, thank_you_message, allow_anonymous,
               collect_email, deadline
        FROM surveys WHERE id = ?
    ");
    $stmt->execute([$survey_id]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) { http_response_code(404); die("Survey not found."); }
    if (!$survey['is_active']) { die("This survey is currently not accepting responses."); }

    // Check deadline
    if (!empty($survey['deadline']) && date('Y-m-d') > $survey['deadline']) {
        $deadline_passed = true;
    } else {
        $deadline_passed = false;
    }

    $stmt = $pdo->prepare("
        SELECT id, question_text, question_type, is_required, options, position,
               placeholder, help_text
        FROM survey_questions
        WHERE survey_id = ?
        ORDER BY position ASC
    ");
    $stmt->execute([$survey_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get creator info
    $stmt = $pdo->prepare("
        SELECT c.username, mp.full_name, mp.profile_pic, mp.banner
        FROM surveys s
        JOIN creators c ON s.creator_id = c.id
        LEFT JOIN my_page mp ON c.id = mp.creator_id
        WHERE s.id = ?
    ");
    $stmt->execute([$survey_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);

    // Count responses
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM survey_responses WHERE survey_id = ?");
    $stmt->execute([$survey_id]);
    $response_count = $stmt->fetchColumn();

} catch (Exception $e) {
    http_response_code(500);
    die("Error loading survey.");
}

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$deadline_passed) {
    $answers    = [];
    $has_error  = false;
    $respondent_email = trim($_POST['respondent_email'] ?? '');

    foreach ($questions as $q) {
        $qid   = $q['id'];
        $value = $_POST["q_$qid"] ?? null;

        if ($q['is_required'] && (empty($value) && $value !== '0')) {
            $errors[] = $q['question_text'];
            $has_error = true;
        }

        $answers[$qid] = is_array($value) ? implode(', ', $value) : ($value ?? '');
    }

    if (!$has_error) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO survey_responses (survey_id, respondent_email, answers)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([
                $survey_id,
                $respondent_email ?: null,
                json_encode($answers)
            ]);
            $success = true;
        } catch (Exception $e) {
            $errors[] = "Failed to save your response. Please try again.";
        }
    }
}

$total_q    = count($questions);
$thank_you  = !empty($survey['thank_you_message'])
    ? $survey['thank_you_message']
    : "Thank you! Your response has been recorded successfully.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($survey['title']) ?> — Keychele</title>
  <meta name="description" content="<?= htmlspecialchars(mb_substr($survey['description'] ?? '', 0, 160)) ?>">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

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
    --r-lg:   22px;
    --sh:     0 4px 28px rgba(5,46,100,0.10);
    --sh-lg:  0 16px 56px rgba(5,46,100,0.15);
    --trn:    0.2s ease;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  html { scroll-behavior: smooth; }

  body {
    font-family: 'GlacialI', 'Trebuchet MS', sans-serif;
    background: var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
  }

  h1,h2,h3,h4 { font-family: 'Montserrat', sans-serif; }

  /* ─── PAGE SHELL ─── */
  .shell {
    min-height: 100vh;
    display: flex; flex-direction: column;
  }

  /* ─── HERO BANNER ─── */
  .survey-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(120deg, var(--navy) 0%, #0a3a7a 55%, #1056b8 100%);
    padding: 3rem 1.5rem 5rem;
    text-align: center;
  }

  .survey-hero::before {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
    background-size: 36px 36px;
  }

  .hero-orb {
    position: absolute; border-radius: 50%; pointer-events: none;
  }
  .hero-orb-1 { width:300px; height:300px; top:-100px; right:-80px; background: radial-gradient(circle, rgba(254,196,17,0.15) 0%, transparent 65%); }
  .hero-orb-2 { width:200px; height:200px; bottom:-60px; left:-40px; background: radial-gradient(circle, rgba(191,19,57,0.18) 0%, transparent 65%); }
  .hero-orb-3 { width:150px; height:150px; top:20%; left:5%; background: radial-gradient(circle, rgba(255,255,255,0.04) 0%, transparent 65%); }

  .hero-inner {
    position: relative; z-index: 1;
    max-width: 640px; margin: 0 auto;
  }

  .hero-creator {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    margin-bottom: 1.25rem;
  }

  .hero-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    background: linear-gradient(135deg, var(--red), var(--red-l));
    display: flex; align-items: center; justify-content: center;
    font-family: 'Montserrat', sans-serif; font-weight: 800;
    font-size: 0.95rem; color: white;
    border: 2px solid rgba(255,255,255,0.25);
    overflow: hidden; flex-shrink: 0;
  }
  .hero-avatar img { width: 100%; height: 100%; object-fit: cover; }

  .hero-creator-name {
    font-family: 'Montserrat', sans-serif; font-weight: 700;
    font-size: 0.82rem; color: rgba(255,255,255,0.75);
  }

  .hero-tag {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(254,196,17,0.14);
    border: 1px solid rgba(254,196,17,0.28);
    border-radius: 100px; padding: 4px 13px;
    font-family: 'Montserrat', sans-serif;
    font-size: 0.62rem; font-weight: 700;
    letter-spacing: 0.09em; text-transform: uppercase;
    color: var(--gold); margin-bottom: 1rem;
  }

  .hero-title {
    font-family: 'Montserrat', sans-serif; font-weight: 900;
    font-size: clamp(1.5rem, 4vw, 2.4rem);
    color: white; letter-spacing: -0.04em; line-height: 1.2;
    margin-bottom: 0.75rem;
  }

  .hero-desc {
    font-size: 0.9rem; color: rgba(255,255,255,0.6);
    line-height: 1.65; max-width: 520px; margin: 0 auto 1.25rem;
  }

  .hero-meta {
    display: flex; align-items: center; justify-content: center;
    flex-wrap: wrap; gap: 0.75rem;
  }

  .hero-chip {
    display: flex; align-items: center; gap: 5px;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 100px; padding: 5px 12px;
    font-family: 'Montserrat', sans-serif;
    font-size: 0.65rem; font-weight: 700;
    color: rgba(255,255,255,0.7);
  }
  .hero-chip i { font-size: 0.62rem; }

  /* ─── MAIN CONTENT ─── */
  .main {
    flex: 1;
    max-width: 700px; width: 100%;
    margin: -3rem auto 3rem;
    padding: 0 1rem;
    position: relative; z-index: 2;
  }

  /* ─── PROGRESS BAR ─── */
  .progress-wrap {
    background: var(--card);
    border-radius: var(--r-lg) var(--r-lg) 0 0;
    padding: 1rem 1.5rem 0;
    border: 1px solid var(--border);
    border-bottom: none;
    box-shadow: var(--sh);
  }

  .progress-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 8px;
  }

  .progress-label {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.7rem; font-weight: 700;
    color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em;
  }

  .progress-pct {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.72rem; font-weight: 800;
    color: var(--navy);
  }

  .progress-track {
    width: 100%; height: 4px;
    background: rgba(5,46,100,0.08);
    border-radius: 100px; overflow: hidden;
  }

  .progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--red), var(--gold));
    border-radius: 100px;
    transition: width 0.4s ease;
    width: 0%;
  }

  /* ─── FORM CARD ─── */
  .form-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-top: none;
    border-radius: 0 0 var(--r-lg) var(--r-lg);
    padding: 1.75rem 1.5rem;
    box-shadow: var(--sh);
  }

  @media (max-width: 480px) {
    .form-card { padding: 1.25rem 1rem; }
    .progress-wrap { padding: 0.85rem 1rem 0; }
  }

  /* ─── QUESTION BLOCK ─── */
  .q-wrap {
    padding: 1.25rem 0;
    border-bottom: 1px solid rgba(5,46,100,0.07);
    transition: opacity 0.2s;
  }
  .q-wrap:last-of-type { border-bottom: none; }

  .q-num-row {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 0.6rem;
  }

  .q-badge {
    width: 24px; height: 24px; border-radius: 6px;
    background: var(--navy); color: white;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Montserrat', sans-serif; font-weight: 800;
    font-size: 0.65rem; flex-shrink: 0;
  }

  .q-type-tag {
    display: flex; align-items: center; gap: 4px;
    background: rgba(5,46,100,0.07);
    border-radius: 100px; padding: 2px 8px;
    font-family: 'Montserrat', sans-serif;
    font-size: 0.57rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.05em;
    color: var(--navy);
  }

  .q-req-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--red); flex-shrink: 0;
    margin-left: 4px;
  }

  .q-text {
    font-family: 'Montserrat', sans-serif;
    font-weight: 700; font-size: 0.92rem;
    color: var(--text); line-height: 1.4;
    margin-bottom: 0.3rem;
  }

  .q-req-star { color: var(--red); margin-left: 3px; }

  .q-help {
    font-size: 0.73rem; color: var(--muted);
    margin-bottom: 0.85rem; line-height: 1.5;
  }

  /* ─── INPUT STYLES ─── */
  .kc-input {
    width: 100%;
    font-family: 'GlacialI', 'Trebuchet MS', sans-serif;
    font-size: 0.9rem; color: var(--text);
    background: #fafafa;
    border: 1.5px solid rgba(5,46,100,0.14);
    border-radius: 10px;
    padding: 12px 14px; outline: none;
    transition: border-color var(--trn), box-shadow var(--trn), background var(--trn);
    appearance: none;
  }

  .kc-input:focus {
    border-color: var(--navy); background: white;
    box-shadow: 0 0 0 3px rgba(5,46,100,0.08);
  }

  textarea.kc-input { resize: vertical; min-height: 110px; }

  select.kc-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b7a99' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center;
    background-size: 16px; padding-right: 36px; cursor: pointer;
  }

  /* ─── RADIO / CHECKBOX CARDS ─── */
  .choice-list { display: flex; flex-direction: column; gap: 8px; }

  .choice-item {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 14px;
    border: 1.5px solid rgba(5,46,100,0.14);
    border-radius: 10px; cursor: pointer;
    transition: all var(--trn);
    background: #fafafa;
    user-select: none;
  }

  .choice-item:hover {
    border-color: var(--navy);
    background: #f0f4ff;
  }

  .choice-item input[type=radio],
  .choice-item input[type=checkbox] {
    width: 18px; height: 18px;
    accent-color: var(--navy);
    cursor: pointer; flex-shrink: 0;
  }

  .choice-item span {
    font-size: 0.88rem; color: var(--text);
    line-height: 1.3;
  }

  /* Selected state via JS */
  .choice-item.chosen {
    border-color: var(--navy);
    background: rgba(5,46,100,0.06);
    box-shadow: 0 0 0 3px rgba(5,46,100,0.06);
  }

  /* ─── STAR RATING ─── */
  .star-wrap {
    display: flex; gap: 6px;
    flex-wrap: wrap;
  }

  .star-btn {
    background: none; border: none; cursor: pointer;
    font-size: 1.75rem; color: rgba(5,46,100,0.15);
    transition: color 0.15s, transform 0.15s;
    padding: 2px;
  }

  .star-btn:hover,
  .star-btn.lit { color: var(--gold); }
  .star-btn.lit { text-shadow: 0 0 8px rgba(254,196,17,0.5); }
  .star-btn:hover { transform: scale(1.15); }

  .star-label {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.72rem; font-weight: 700;
    color: var(--muted); margin-top: 6px;
  }

  /* ─── SCALE 1-10 ─── */
  .scale-wrap { display: flex; flex-wrap: wrap; gap: 6px; }

  .scale-btn {
    width: 44px; height: 44px; border-radius: 10px;
    border: 1.5px solid rgba(5,46,100,0.14);
    background: #fafafa; cursor: pointer;
    font-family: 'Montserrat', sans-serif;
    font-weight: 800; font-size: 0.9rem;
    color: var(--muted);
    display: flex; align-items: center; justify-content: center;
    transition: all var(--trn);
  }

  .scale-btn:hover {
    border-color: var(--navy); color: var(--navy); background: #f0f4ff;
  }

  .scale-btn.picked {
    background: var(--navy); color: white;
    border-color: var(--navy);
    box-shadow: 0 3px 10px rgba(5,46,100,0.25);
    transform: translateY(-2px);
  }

  .scale-ends {
    display: flex; justify-content: space-between;
    font-size: 0.68rem; color: var(--muted);
    font-family: 'Montserrat', sans-serif; font-weight: 600;
    margin-top: 6px;
  }

  /* ─── YES / NO ─── */
  .yesno-wrap { display: flex; gap: 10px; }

  .yesno-btn {
    flex: 1; height: 52px; border-radius: 11px;
    border: 1.5px solid rgba(5,46,100,0.14);
    background: #fafafa; cursor: pointer;
    font-family: 'Montserrat', sans-serif;
    font-weight: 800; font-size: 0.88rem;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all var(--trn); color: var(--muted);
  }

  .yesno-btn:hover { border-color: var(--navy); color: var(--navy); background: #f0f4ff; }

  .yesno-btn.yes-picked { background: rgba(0,179,126,0.1); border-color: #00b37e; color: #065f46; box-shadow: 0 3px 12px rgba(0,179,126,0.15); }
  .yesno-btn.no-picked  { background: rgba(191,19,57,0.08); border-color: var(--red); color: var(--red); box-shadow: 0 3px 12px rgba(191,19,57,0.12); }

  /* ─── SUCCESS SCREEN ─── */
  .success-screen {
    text-align: center;
    padding: 3rem 1.5rem;
  }

  .success-ico {
    width: 80px; height: 80px; border-radius: 50%;
    background: linear-gradient(135deg, #00b37e, #00d68f);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; color: white;
    margin: 0 auto 1.5rem;
    box-shadow: 0 8px 32px rgba(0,179,126,0.3);
    animation: popIn 0.5s cubic-bezier(0.16,1,0.3,1) both;
  }

  @keyframes popIn {
    from { opacity:0; transform: scale(0.6); }
    to   { opacity:1; transform: scale(1); }
  }

  .success-title {
    font-family: 'Montserrat', sans-serif;
    font-weight: 900; font-size: 1.6rem;
    color: var(--text); letter-spacing: -0.04em;
    margin-bottom: 0.75rem;
  }

  .success-msg {
    font-size: 0.9rem; color: var(--muted);
    line-height: 1.65; max-width: 380px; margin: 0 auto 1.75rem;
  }

  /* ─── DEADLINE BANNER ─── */
  .deadline-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    padding: 3rem 1.5rem;
    text-align: center;
    box-shadow: var(--sh);
  }

  .deadline-ico {
    width: 72px; height: 72px; border-radius: 50%;
    background: rgba(245,158,11,0.12);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem; color: #f59e0b;
    margin: 0 auto 1.25rem;
  }

  /* ─── ERROR BANNER ─── */
  .err-banner {
    background: rgba(191,19,57,0.08);
    border: 1px solid rgba(191,19,57,0.2);
    border-left: 4px solid var(--red);
    border-radius: 10px; padding: 1rem 1.15rem;
    margin-bottom: 1.25rem;
  }

  .err-banner-title {
    font-family: 'Montserrat', sans-serif;
    font-weight: 700; font-size: 0.8rem; color: var(--red);
    margin-bottom: 6px; display: flex; align-items: center; gap: 6px;
  }

  .err-banner ul {
    list-style: none; padding: 0;
  }

  .err-banner ul li {
    font-size: 0.8rem; color: #7f1d1d;
    padding: 3px 0;
    display: flex; align-items: flex-start; gap: 6px;
  }

  .err-banner ul li::before {
    content: '→'; flex-shrink: 0; font-weight: 700; color: var(--red);
  }

  /* ─── EMAIL FIELD ─── */
  .email-collect-wrap {
    background: rgba(5,46,100,0.04);
    border: 1px solid rgba(5,46,100,0.1);
    border-radius: 10px; padding: 1rem 1.15rem;
    margin-bottom: 1.25rem;
  }

  /* ─── SUBMIT BUTTON ─── */
  .submit-btn {
    width: 100%; height: 52px;
    background: linear-gradient(135deg, var(--red) 0%, var(--red-l) 100%);
    border: none; border-radius: var(--r);
    color: white;
    font-family: 'Montserrat', sans-serif;
    font-weight: 800; font-size: 0.95rem;
    letter-spacing: 0.02em; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 9px;
    box-shadow: 0 4px 20px rgba(191,19,57,0.35);
    transition: transform 0.2s, box-shadow 0.2s;
    margin-top: 1.5rem;
  }
  .submit-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(191,19,57,0.48); }
  .submit-btn:active { transform: translateY(0); }
  .submit-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }

  /* ─── FOOTER BRAND ─── */
  .brand-footer {
    text-align: center; padding: 1.5rem 1rem 2rem;
    display: flex; align-items: center; justify-content: center; gap: 8px;
  }

  .brand-footer-text {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.72rem; font-weight: 700;
    color: var(--muted); letter-spacing: 0.04em;
  }

  .brand-footer-logo {
    font-family: 'Montserrat', sans-serif;
    font-weight: 900; font-size: 0.85rem;
    color: var(--navy); letter-spacing: -0.04em;
  }

  .brand-footer-logo span { color: var(--gold); }

  @keyframes spin { to { transform: rotate(360deg); } }
  .spinner { animation: spin 0.8s linear infinite; display: inline-block; }

  /* ─── REQUIRED FIELD HIGHLIGHT ─── */
  .q-wrap.has-error .kc-input,
  .q-wrap.has-error .choice-item { border-color: var(--red) !important; }
  .q-wrap.has-error .q-text { color: var(--red); }

  @media (max-width: 480px) {
    .scale-btn { width: 38px; height: 38px; font-size: 0.82rem; }
    .yesno-btn { font-size: 0.82rem; height: 48px; }
    .hero-chip { font-size: 0.6rem; padding: 4px 9px; }
  }
  </style>
</head>
<body>
<div class="shell">

  <!-- ══ HERO ══ -->
  <div class="survey-hero">
    <div class="hero-orb hero-orb-1"></div>
    <div class="hero-orb hero-orb-2"></div>
    <div class="hero-orb hero-orb-3"></div>

    <div class="hero-inner">
      <!-- Creator info -->
      <?php if ($creator): ?>
      <div class="hero-creator">
        <div class="hero-avatar">
          <?php if (!empty($creator['profile_pic'])): ?>
            <img src="<?= htmlspecialchars($creator['profile_pic']) ?>" alt="">
          <?php else: ?>
            <?= strtoupper(mb_substr($creator['full_name'] ?? $creator['username'], 0, 2)) ?>
          <?php endif; ?>
        </div>
        <span class="hero-creator-name"><?= htmlspecialchars($creator['full_name'] ?? $creator['username']) ?></span>
      </div>
      <?php endif; ?>

      <div class="hero-tag"><i class="fa-solid fa-square-poll-horizontal"></i> Survey</div>

      <h1 class="hero-title"><?= htmlspecialchars($survey['title']) ?></h1>

      <?php if (!empty($survey['description'])): ?>
        <p class="hero-desc"><?= nl2br(htmlspecialchars($survey['description'])) ?></p>
      <?php endif; ?>

      <div class="hero-meta">
        <span class="hero-chip">
          <i class="fa-solid fa-list-check"></i>
          <?= $total_q ?> Question<?= $total_q !== 1 ? 's' : '' ?>
        </span>
        <span class="hero-chip">
          <i class="fa-solid fa-users"></i>
          <?= number_format($response_count) ?> Response<?= $response_count !== 1 ? 's' : '' ?>
        </span>
        <?php if (!empty($survey['deadline'])): ?>
        <span class="hero-chip">
          <i class="fa-solid fa-hourglass-half"></i>
          Closes <?= date('d M Y', strtotime($survey['deadline'])) ?>
        </span>
        <?php endif; ?>
        <?php if ($survey['allow_anonymous']): ?>
        <span class="hero-chip">
          <i class="fa-solid fa-user-secret"></i>
          Anonymous OK
        </span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══ MAIN ══ -->
  <div class="main">

    <?php if ($deadline_passed): ?>
      <!-- Deadline passed -->
      <div class="deadline-card">
        <div class="deadline-ico"><i class="fa-solid fa-hourglass-end"></i></div>
        <h2 style="font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.4rem;color:var(--text);margin-bottom:0.75rem">Survey Closed</h2>
        <p style="font-size:0.88rem;color:var(--muted);line-height:1.65">
          This survey closed on <strong><?= date('d F Y', strtotime($survey['deadline'])) ?></strong> and is no longer accepting responses.<br>
          Thank you for your interest!
        </p>
      </div>

    <?php elseif ($success): ?>
      <!-- Success -->
      <div class="form-card" style="border-radius:var(--r-lg)">
        <div class="success-screen">
          <div class="success-ico"><i class="fa-solid fa-check"></i></div>
          <h2 class="success-title">All Done!</h2>
          <p class="success-msg"><?= htmlspecialchars($thank_you) ?></p>
          <a href="javascript:history.back()"
             style="display:inline-flex;align-items:center;gap:7px;padding:11px 22px;border-radius:10px;background:rgba(5,46,100,0.08);border:1px solid rgba(5,46,100,0.15);font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;color:var(--navy);text-decoration:none;transition:background 0.2s"
             onmouseover="this.style.background='rgba(5,46,100,0.14)'"
             onmouseout="this.style.background='rgba(5,46,100,0.08)'">
            <i class="fa-solid fa-arrow-left"></i> Go Back
          </a>
        </div>
      </div>

    <?php else: ?>
      <!-- Progress bar -->
      <div class="progress-wrap">
        <div class="progress-header">
          <span class="progress-label">Progress</span>
          <span class="progress-pct" id="progressPct">0%</span>
        </div>
        <div class="progress-track">
          <div class="progress-fill" id="progressFill"></div>
        </div>
      </div>

      <!-- Form card -->
      <div class="form-card">

        <?php if (!empty($errors)): ?>
        <div class="err-banner">
          <div class="err-banner-title">
            <i class="fa-solid fa-circle-exclamation"></i>
            Please answer the required questions:
          </div>
          <ul>
            <?php foreach ($errors as $err): ?>
              <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <form method="POST" id="surveyForm" novalidate>

          <?php if ($survey['collect_email']): ?>
          <div class="email-collect-wrap">
            <label style="display:block;font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.75rem;color:#374151;margin-bottom:8px">
              <i class="fa-solid fa-envelope" style="margin-right:5px;color:var(--navy)"></i>
              Your Email Address
            </label>
            <input type="email" name="respondent_email" class="kc-input"
                   placeholder="your@email.com">
            <div style="font-size:0.68rem;color:var(--muted);margin-top:5px">Your email will only be used by the creator.</div>
          </div>
          <?php endif; ?>

          <?php foreach ($questions as $qi => $q):
            $qid      = $q['id'];
            $name     = "q_{$qid}";
            $req      = $q['is_required'];
            $type     = $q['question_type'];
            $opts     = $q['options'] ? array_map('trim', explode("\n", trim($q['options']))) : [];
            $ph       = $q['placeholder'] ?? '';
            $help     = $q['help_text'] ?? '';

            $type_labels = [
              'text'=>'Short Answer','textarea'=>'Paragraph','email'=>'Email',
              'number'=>'Number','date'=>'Date','radio'=>'Single Choice',
              'checkbox'=>'Multiple Choice','select'=>'Dropdown','yesno'=>'Yes / No',
              'rating'=>'Star Rating','scale'=>'Scale 1–10','url'=>'URL'
            ];
            $type_icons = [
              'text'=>'fa-font','textarea'=>'fa-align-left','email'=>'fa-envelope',
              'number'=>'fa-hashtag','date'=>'fa-calendar-days','radio'=>'fa-circle-dot',
              'checkbox'=>'fa-square-check','select'=>'fa-chevron-down','yesno'=>'fa-toggle-on',
              'rating'=>'fa-star','scale'=>'fa-sliders','url'=>'fa-link'
            ];
          ?>
          <div class="q-wrap" data-qi="<?= $qi ?>" id="qwrap_<?= $qid ?>">

            <div class="q-num-row">
              <div class="q-badge"><?= $qi + 1 ?></div>
              <span class="q-type-tag">
                <i class="fa-solid <?= $type_icons[$type] ?? 'fa-question' ?>" style="font-size:0.55rem"></i>
                <?= $type_labels[$type] ?? $type ?>
              </span>
              <?php if ($req): ?><div class="q-req-dot" title="Required"></div><?php endif; ?>
            </div>

            <div class="q-text">
              <?= htmlspecialchars($q['question_text']) ?>
              <?php if ($req): ?><span class="q-req-star">*</span><?php endif; ?>
            </div>

            <?php if (!empty($help)): ?>
              <div class="q-help"><?= htmlspecialchars($help) ?></div>
            <?php endif; ?>

            <?php
            $placeholder = !empty($ph) ? $ph : 'Your answer...';
            ?>

            <!-- ── RENDER BY TYPE ── -->
            <?php if ($type === 'text' || $type === 'email' || $type === 'number' || $type === 'url'): ?>
              <input type="<?= $type === 'url' ? 'url' : $type ?>"
                     name="<?= $name ?>"
                     class="kc-input"
                     placeholder="<?= htmlspecialchars($placeholder) ?>"
                     <?= $req ? 'required' : '' ?>>

            <?php elseif ($type === 'textarea'): ?>
              <textarea name="<?= $name ?>"
                        class="kc-input"
                        placeholder="<?= htmlspecialchars($placeholder) ?>"
                        <?= $req ? 'required' : '' ?>></textarea>

            <?php elseif ($type === 'date'): ?>
              <input type="date" name="<?= $name ?>" class="kc-input" <?= $req ? 'required' : '' ?>>

            <?php elseif ($type === 'radio' && !empty($opts)): ?>
              <div class="choice-list">
                <?php foreach ($opts as $oi => $opt): if(empty(trim($opt))) continue; ?>
                  <label class="choice-item" onclick="pickRadio(this)">
                    <input type="radio" name="<?= $name ?>" value="<?= htmlspecialchars(trim($opt)) ?>" <?= $req ? 'required' : '' ?> style="display:none">
                    <div style="width:18px;height:18px;border-radius:50%;border:2px solid rgba(5,46,100,0.3);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all 0.15s" class="radio-ring">
                      <div style="width:8px;height:8px;border-radius:50%;background:var(--navy);opacity:0;transition:opacity 0.15s" class="radio-dot"></div>
                    </div>
                    <span><?= htmlspecialchars(trim($opt)) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>

            <?php elseif ($type === 'checkbox' && !empty($opts)): ?>
              <div class="choice-list">
                <?php foreach ($opts as $opt): if(empty(trim($opt))) continue; ?>
                  <label class="choice-item" onclick="toggleCheckbox(this)">
                    <input type="checkbox" name="<?= $name ?>[]" value="<?= htmlspecialchars(trim($opt)) ?>" style="display:none">
                    <div style="width:18px;height:18px;border-radius:5px;border:2px solid rgba(5,46,100,0.3);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all 0.15s" class="cb-box">
                      <i class="fa-solid fa-check" style="font-size:0.6rem;color:white;opacity:0;transition:opacity 0.15s" class="cb-icon"></i>
                    </div>
                    <span><?= htmlspecialchars(trim($opt)) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>

            <?php elseif ($type === 'select' && !empty($opts)): ?>
              <select name="<?= $name ?>" class="kc-input" <?= $req ? 'required' : '' ?>>
                <option value="">— Select an option —</option>
                <?php foreach ($opts as $opt): if(empty(trim($opt))) continue; ?>
                  <option value="<?= htmlspecialchars(trim($opt)) ?>"><?= htmlspecialchars(trim($opt)) ?></option>
                <?php endforeach; ?>
              </select>

            <?php elseif ($type === 'yesno'): ?>
              <div class="yesno-wrap">
                <button type="button" class="yesno-btn" data-val="Yes" onclick="pickYesNo(this, '<?= $name ?>')">
                  <i class="fa-solid fa-thumbs-up"></i> Yes
                </button>
                <button type="button" class="yesno-btn" data-val="No" onclick="pickYesNo(this, '<?= $name ?>')">
                  <i class="fa-solid fa-thumbs-down"></i> No
                </button>
                <input type="hidden" name="<?= $name ?>" id="yesno_<?= $qid ?>" value="">
              </div>

            <?php elseif ($type === 'rating'): ?>
              <div>
                <div class="star-wrap" id="stars_<?= $qid ?>">
                  <?php for($s=1;$s<=5;$s++): ?>
                    <button type="button" class="star-btn" data-val="<?= $s ?>" onclick="pickStar(this, '<?= $qid ?>')">
                      <i class="fa-solid fa-star"></i>
                    </button>
                  <?php endfor; ?>
                </div>
                <div class="star-label" id="starLabel_<?= $qid ?>">Click a star to rate</div>
                <input type="hidden" name="<?= $name ?>" id="starVal_<?= $qid ?>" value="">
              </div>

            <?php elseif ($type === 'scale'): ?>
              <div>
                <div class="scale-wrap" id="scale_<?= $qid ?>">
                  <?php for($s=1;$s<=10;$s++): ?>
                    <button type="button" class="scale-btn" data-val="<?= $s ?>" onclick="pickScale(this, '<?= $qid ?>')">
                      <?= $s ?>
                    </button>
                  <?php endfor; ?>
                </div>
                <div class="scale-ends">
                  <span>Not at all</span>
                  <span>Absolutely yes</span>
                </div>
                <input type="hidden" name="<?= $name ?>" id="scaleVal_<?= $qid ?>" value="">
              </div>

            <?php else: ?>
              <input type="text" name="<?= $name ?>" class="kc-input" placeholder="<?= htmlspecialchars($placeholder) ?>">
            <?php endif; ?>

          </div>
          <?php endforeach; ?>

          <!-- Submit -->
          <button type="submit" class="submit-btn" id="submitBtn">
            <i class="fa-solid fa-paper-plane"></i>
            Submit Response
          </button>

        </form>
      </div>
    <?php endif; ?>

  </div><!-- /main -->

  <!-- Footer -->
  <div class="brand-footer">
    <div class="brand-footer-text">Powered by</div>
    <div class="brand-footer-logo">Key<span>chele</span></div>
  </div>

</div><!-- /shell -->

<script>
/* ════════════════════════════════
   RADIO CARDS
════════════════════════════════ */
function pickRadio(label) {
  const name = label.querySelector('input').name;
  document.querySelectorAll(`input[name="${name}"]`).forEach(inp => {
    const lbl = inp.closest('.choice-item');
    lbl.classList.remove('chosen');
    lbl.querySelector('.radio-ring').style.borderColor = 'rgba(5,46,100,0.3)';
    lbl.querySelector('.radio-dot').style.opacity = '0';
  });
  label.classList.add('chosen');
  label.querySelector('input').checked = true;
  label.querySelector('.radio-ring').style.borderColor = 'var(--navy)';
  label.querySelector('.radio-dot').style.opacity = '1';
  updateProgress();
}

/* ════════════════════════════════
   CHECKBOX CARDS
════════════════════════════════ */
function toggleCheckbox(label) {
  const inp   = label.querySelector('input');
  const box   = label.querySelector('.cb-box');
  const icon  = label.querySelector('.fa-check');
  inp.checked = !inp.checked;

  if (inp.checked) {
    label.classList.add('chosen');
    box.style.background    = 'var(--navy)';
    box.style.borderColor   = 'var(--navy)';
    icon.style.opacity      = '1';
  } else {
    label.classList.remove('chosen');
    box.style.background    = '';
    box.style.borderColor   = 'rgba(5,46,100,0.3)';
    icon.style.opacity      = '0';
  }
  updateProgress();
}

/* ════════════════════════════════
   YES / NO
════════════════════════════════ */
function pickYesNo(btn, name) {
  const wrap = btn.closest('.yesno-wrap');
  wrap.querySelectorAll('.yesno-btn').forEach(b => {
    b.classList.remove('yes-picked','no-picked');
  });
  const val = btn.dataset.val;
  btn.classList.add(val === 'Yes' ? 'yes-picked' : 'no-picked');
  document.getElementById('yesno_' + name.replace('q_','')).value = val;
  updateProgress();
}

/* ════════════════════════════════
   STAR RATING
════════════════════════════════ */
const STAR_LABELS = ['','Poor','Fair','Good','Very Good','Excellent'];

function pickStar(btn, qid) {
  const val   = parseInt(btn.dataset.val);
  const stars = document.querySelectorAll(`#stars_${qid} .star-btn`);
  stars.forEach((s, i) => s.classList.toggle('lit', i < val));
  document.getElementById('starVal_' + qid).value = val;
  document.getElementById('starLabel_' + qid).textContent = `${val}/5 — ${STAR_LABELS[val]}`;
  updateProgress();
}

/* ════════════════════════════════
   SCALE 1-10
════════════════════════════════ */
function pickScale(btn, qid) {
  document.querySelectorAll(`#scale_${qid} .scale-btn`).forEach(b => b.classList.remove('picked'));
  btn.classList.add('picked');
  document.getElementById('scaleVal_' + qid).value = btn.dataset.val;
  updateProgress();
}

/* ════════════════════════════════
   PROGRESS BAR
════════════════════════════════ */
const TOTAL_Q = <?= $total_q ?>;

function updateProgress() {
  let answered = 0;

  document.querySelectorAll('.q-wrap').forEach(wrap => {
    const inputs = wrap.querySelectorAll('input:not([type=hidden]):not([type=radio]):not([type=checkbox]), textarea, select');
    const radios = wrap.querySelectorAll('input[type=radio]:checked');
    const cbs    = wrap.querySelectorAll('input[type=checkbox]:checked');
    const hidden = wrap.querySelectorAll('input[type=hidden]');

    let filled = false;

    inputs.forEach(i => { if(i.value.trim()) filled = true; });
    if(radios.length > 0) filled = true;
    if(cbs.length > 0) filled = true;
    hidden.forEach(h => { if(h.value.trim()) filled = true; });

    if(filled) answered++;
  });

  const pct = TOTAL_Q > 0 ? Math.round((answered / TOTAL_Q) * 100) : 0;
  document.getElementById('progressFill').style.width = pct + '%';
  document.getElementById('progressPct').textContent  = pct + '%';
}

/* ════════════════════════════════
   FORM SUBMIT
════════════════════════════════ */
const form = document.getElementById('surveyForm');
if (form) {
  form.addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = `
        <svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
        </svg>
        Submitting...
      `;
    }
  });
}

/* ════════════════════════════════
   LIVE PROGRESS ON TYPING
════════════════════════════════ */
document.addEventListener('input', updateProgress);
document.addEventListener('change', updateProgress);
</script>

</body>
</html>
