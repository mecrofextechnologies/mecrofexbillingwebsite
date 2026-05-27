<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
/**
 * generate_random_quiz.php
 * AI-powered random quiz generator for Keychele
 * All AI calls proxied through create_game.php (server-side API key)
 */
include 'includes/connect.php';
$creator_id = $_SESSION['creator_id'] ?? null;
// AFTER
if (!$creator_id) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Please login first.']));
}

// ─── AJAX: save generated quiz to DB ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_generated') {
    header('Content-Type: application/json');
    try {
        $title       = trim($_POST['title']       ?? 'Generated Quiz');
        $description = trim($_POST['description'] ?? '');
        $category    = trim($_POST['category']    ?? 'General');
        $visibility  = $_POST['visibility']       ?? 'public';
        $questions   = json_decode($_POST['questions_json'] ?? '[]', true);
        if (empty($questions)) throw new Exception('No questions provided.');

        $pdo->exec("CREATE TABLE IF NOT EXISTS creator_games (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            creator_id INT UNSIGNED NOT NULL,
            title VARCHAR(150) NOT NULL,
            description TEXT,
            category VARCHAR(60) DEFAULT 'General',
            visibility ENUM('public','private') DEFAULT 'public',
            cover VARCHAR(255),
            questions_json LONGTEXT NOT NULL,
            game_pin CHAR(6) UNIQUE,
            status ENUM('draft','lobby','question','results','ended') DEFAULT 'draft',
            current_question INT DEFAULT -1,
            play_count INT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_creator (creator_id),
            INDEX idx_pin (game_pin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $attempts = 0;
        do {
            $pin = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $chk = $pdo->prepare("SELECT id FROM creator_games WHERE game_pin = ? LIMIT 1");
            $chk->execute([$pin]);
            $attempts++;
        } while ($chk->fetchColumn() && $attempts < 20);

        $stmt = $pdo->prepare("INSERT INTO creator_games
            (creator_id, title, description, category, visibility, questions_json, game_pin, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'lobby', NOW())");
        $stmt->execute([$creator_id, $title, $description, $category, $visibility, json_encode($questions), $pin]);
        $game_id = $pdo->lastInsertId();

        echo json_encode(['success' => true, 'game_id' => $game_id, 'pin' => $pin]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Generate Random Quiz — Keychele</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800;900&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

  <style>
  :root {
    --ink:     #0a0c14;
    --ink2:    #141828;
    --ink3:    #1e2236;
    --surface: #f4f3ef;
    --card:    #ffffff;
    --border:  #e0ddd6;
    --muted:   #8f8d98;
    --accent:  #ff3f61;
    --accent2: #6d28d9;
    --gold:    #f59e0b;
    --teal:    #0d9488;
    --emerald: #059669;
    --ans-0: #ff3f61;
    --ans-1: #6d28d9;
    --ans-2: #f59e0b;
    --ans-3: #0d9488;
    --r:    10px;
    --r-lg: 18px;
    --r-xl: 24px;
    --sh:   0 1px 12px rgba(10,12,20,.07);
    --sh-md:0 4px 20px rgba(10,12,20,.10);
    --sh-lg:0 12px 48px rgba(10,12,20,.16);
    --sh-xl:0 24px 80px rgba(10,12,20,.24);
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { height: 100%; }

  body {
    font-family: 'DM Sans', system-ui, sans-serif;
    background: var(--surface);
    color: var(--ink);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
  }

  h1,h2,h3,h4 { font-family: 'Syne', sans-serif; }

  /* ── TRIGGER BUTTON ── */
  .gen-quiz-trigger {
    display: inline-flex; align-items: center; gap: 9px;
    padding: 11px 22px; border-radius: var(--r);
    background: linear-gradient(135deg, var(--accent2), #4c1d95);
    color: white; font-family: 'Syne', sans-serif;
    font-weight: 800; font-size: 0.85rem; border: none;
    cursor: pointer; box-shadow: 0 6px 24px rgba(109,40,217,.35);
    transition: transform .2s, box-shadow .2s; position: relative; overflow: hidden;
  }
  .gen-quiz-trigger::before { content:''; position:absolute; inset:0; background:linear-gradient(135deg,rgba(255,255,255,.12),transparent); }
  .gen-quiz-trigger:hover { transform:translateY(-2px); box-shadow:0 12px 32px rgba(109,40,217,.48); }
  .gen-quiz-trigger .spark { font-size:1rem; animation:sparkle 2s ease-in-out infinite; }
  @keyframes sparkle { 0%,100%{transform:scale(1) rotate(0deg);} 50%{transform:scale(1.3) rotate(15deg);} }

  /* ── OVERLAY & MODAL ── */
  .grq-overlay {
    position:fixed; inset:0; z-index:9999;
    background:rgba(10,12,20,.92); backdrop-filter:blur(12px);
    display:flex; align-items:center; justify-content:center; padding:1rem;
    opacity:0; pointer-events:none; transition:opacity .3s ease;
  }
  .grq-overlay.open { opacity:1; pointer-events:all; }

  .grq-modal {
    background:var(--ink2); border:1px solid rgba(255,255,255,.08);
    border-radius:var(--r-xl); width:100%; max-width:640px;
    max-height:92vh; overflow:hidden; display:flex; flex-direction:column;
    box-shadow:0 40px 100px rgba(0,0,0,.6);
    transform:scale(.9) translateY(24px);
    transition:transform .45s cubic-bezier(.16,1,.3,1); position:relative;
  }
  .grq-overlay.open .grq-modal { transform:scale(1) translateY(0); }
  .grq-modal::before {
    content:''; position:absolute; top:0; left:0; right:0; height:3px;
    background:linear-gradient(90deg,var(--accent),var(--accent2),var(--teal),var(--gold)); z-index:10;
  }

  /* ── HEADER ── */
  .grq-header {
    padding:1.6rem 1.75rem 1.3rem;
    border-bottom:1px solid rgba(255,255,255,.07);
    display:flex; align-items:flex-start; gap:1rem; flex-shrink:0;
  }
  .grq-header-icon {
    width:52px; height:52px; border-radius:14px; flex-shrink:0;
    background:linear-gradient(135deg,rgba(109,40,217,.25),rgba(255,63,97,.15));
    border:1.5px solid rgba(109,40,217,.3);
    display:flex; align-items:center; justify-content:center;
    font-size:1.4rem; box-shadow:0 0 28px rgba(109,40,217,.2);
  }
  .grq-header-text { flex:1; }
  .grq-header-title { font-family:'Syne',sans-serif; font-weight:900; font-size:1.25rem; color:white; line-height:1.2; letter-spacing:-.02em; }
  .grq-header-sub { font-size:.78rem; color:rgba(255,255,255,.4); margin-top:.35rem; }
  .grq-close {
    width:32px; height:32px; border-radius:8px;
    background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1);
    color:rgba(255,255,255,.5); font-size:.8rem; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all .15s; flex-shrink:0; margin-top:2px;
  }
  .grq-close:hover { background:rgba(255,63,97,.2); border-color:rgba(255,63,97,.35); color:#ff8fa3; }

  /* ── STEPS ── */
  .grq-steps { display:flex; padding:0 1.75rem; border-bottom:1px solid rgba(255,255,255,.06); flex-shrink:0; }
  .grq-step {
    flex:1; padding:.85rem .4rem; text-align:center;
    display:flex; align-items:center; justify-content:center; gap:7px;
    font-family:'Syne',sans-serif; font-weight:700; font-size:.68rem;
    color:rgba(255,255,255,.25); letter-spacing:.06em; text-transform:uppercase;
    border-bottom:2px solid transparent; cursor:default; transition:all .25s;
  }
  .grq-step.active { color:white; border-bottom-color:var(--accent2); }
  .grq-step.done   { color:var(--teal); border-bottom-color:var(--teal); }
  .grq-step-num {
    width:20px; height:20px; border-radius:50%; background:rgba(255,255,255,.08);
    display:flex; align-items:center; justify-content:center;
    font-size:.65rem; font-weight:800; transition:all .25s;
  }
  .grq-step.active .grq-step-num { background:var(--accent2); color:white; }
  .grq-step.done   .grq-step-num { background:var(--teal); color:white; }

  /* ── BODY ── */
  .grq-body { flex:1; overflow-y:auto; padding:1.5rem 1.75rem; }
  .grq-body::-webkit-scrollbar { width:3px; }
  .grq-body::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:3px; }

  /* ── FOOTER ── */
  .grq-footer {
    padding:1rem 1.75rem; border-top:1px solid rgba(255,255,255,.07);
    display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-shrink:0;
  }

  /* ── PANELS ── */
  .grq-panel { display:none; }
  .grq-panel.active { display:block; animation:panelIn .3s ease both; }
  @keyframes panelIn { from{opacity:0;transform:translateX(14px);} to{opacity:1;transform:translateX(0);} }

  /* ── FORM COMPONENTS ── */
  .grq-field { margin-bottom:1.1rem; }
  .grq-label {
    display:flex; align-items:center; gap:7px;
    font-family:'Syne',sans-serif; font-weight:700; font-size:.7rem;
    color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.55rem;
  }
  .grq-label i { color:var(--gold); font-size:.75rem; }

  .grq-input, .grq-select, .grq-textarea {
    width:100%; background:rgba(255,255,255,.05);
    border:1.5px solid rgba(255,255,255,.1); border-radius:10px; padding:10px 14px;
    color:white; font-family:'DM Sans',sans-serif; font-size:.85rem; outline:none;
    transition:border-color .2s,background .2s,box-shadow .2s; appearance:none;
  }
  .grq-input::placeholder, .grq-textarea::placeholder { color:rgba(255,255,255,.2); }
  .grq-input:focus, .grq-select:focus, .grq-textarea:focus {
    border-color:var(--accent2); background:rgba(109,40,217,.07); box-shadow:0 0 0 3px rgba(109,40,217,.12);
  }
  .grq-select {
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.3)' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 11px center; background-size:14px;
    padding-right:34px; cursor:pointer;
  }
  .grq-select option { background:var(--ink2); color:white; }
  .grq-textarea { resize:vertical; min-height:70px; line-height:1.55; }
  .grq-hint { font-size:.7rem; color:rgba(255,255,255,.25); margin-top:5px; line-height:1.55; }

  /* ── QUIZ TYPE CARDS ── */
  .quiz-type-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.65rem; }
  @media (max-width:500px) { .quiz-type-grid { grid-template-columns:1fr; } }

  .quiz-type-card {
    background:rgba(255,255,255,.04); border:1.5px solid rgba(255,255,255,.09);
    border-radius:12px; padding:13px 14px; cursor:pointer; transition:all .2s;
    display:flex; align-items:flex-start; gap:11px;
  }
  .quiz-type-card:hover { background:rgba(255,255,255,.08); border-color:rgba(255,255,255,.18); }
  .quiz-type-card.selected {
    border-color:var(--accent2); background:rgba(109,40,217,.12);
    box-shadow:0 0 0 3px rgba(109,40,217,.15);
  }
  .qtc-emoji {
    font-size:1.5rem; width:38px; height:38px; flex-shrink:0;
    background:rgba(255,255,255,.06); border-radius:9px;
    display:flex; align-items:center; justify-content:center;
  }
  .qtc-info { flex:1; min-width:0; }
  .qtc-name { font-family:'Syne',sans-serif; font-weight:800; font-size:.8rem; color:white; margin-bottom:3px; }
  .qtc-desc { font-size:.68rem; color:rgba(255,255,255,.35); line-height:1.45; }
  .qtc-check {
    width:18px; height:18px; border-radius:50%;
    background:rgba(255,255,255,.1); border:1.5px solid rgba(255,255,255,.2);
    display:flex; align-items:center; justify-content:center;
    font-size:.6rem; color:transparent; flex-shrink:0; transition:all .2s; margin-top:2px;
  }
  .quiz-type-card.selected .qtc-check { background:var(--accent2); border-color:var(--accent2); color:white; box-shadow:0 0 0 3px rgba(109,40,217,.25); }

  /* ── DIFFICULTY / COUNT ── */
  .grq-row { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
  .diff-pills { display:flex; gap:6px; }
  .diff-pill {
    flex:1; padding:8px 5px; border-radius:9px; text-align:center;
    font-family:'Syne',sans-serif; font-weight:800; font-size:.7rem; cursor:pointer;
    border:1.5px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04);
    color:rgba(255,255,255,.4); transition:all .18s;
  }
  .diff-pill:hover { background:rgba(255,255,255,.1); }
  .diff-pill[data-diff="easy"].selected   { background:rgba(13,148,136,.15); border-color:var(--teal); color:var(--teal); }
  .diff-pill[data-diff="medium"].selected { background:rgba(245,158,11,.15); border-color:var(--gold); color:var(--gold); }
  .diff-pill[data-diff="hard"].selected   { background:rgba(255,63,97,.15); border-color:var(--accent); color:var(--accent); }

  .count-stepper {
    display:flex; align-items:center; background:rgba(255,255,255,.05);
    border:1.5px solid rgba(255,255,255,.1); border-radius:10px; overflow:hidden;
  }
  .count-btn { width:38px; height:42px; background:rgba(255,255,255,.04); border:none; color:rgba(255,255,255,.5); font-size:1rem; cursor:pointer; transition:all .15s; flex-shrink:0; }
  .count-btn:hover { background:rgba(255,255,255,.12); color:white; }
  .count-val { flex:1; text-align:center; font-family:'Syne',sans-serif; font-weight:900; font-size:1rem; color:white; background:transparent; border:none; outline:none; padding:0; height:42px; line-height:42px; }

  /* ── TAGS ── */
  .topic-tags { display:flex; flex-wrap:wrap; gap:6px; margin-top:.5rem; }
  .topic-tag {
    padding:5px 12px; border-radius:100px; cursor:pointer;
    font-family:'Syne',sans-serif; font-weight:700; font-size:.68rem;
    border:1.5px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04);
    color:rgba(255,255,255,.4); transition:all .18s; user-select:none;
  }
  .topic-tag:hover { background:rgba(255,255,255,.1); color:rgba(255,255,255,.8); }
  .topic-tag.selected { background:rgba(245,158,11,.15); border-color:rgba(245,158,11,.4); color:var(--gold); }

  /* ── TOGGLES ── */
  .grq-toggle-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:.75rem; }
  .grq-toggle-label { font-family:'Syne',sans-serif; font-weight:700; font-size:.78rem; color:rgba(255,255,255,.6); }
  .grq-toggle {
    width:42px; height:24px; border-radius:100px; background:rgba(255,255,255,.1);
    border:none; cursor:pointer; position:relative; transition:background .25s; flex-shrink:0;
  }
  .grq-toggle::after {
    content:''; position:absolute; top:3px; left:3px; width:18px; height:18px; border-radius:50%;
    background:white; transition:transform .25s cubic-bezier(.16,1,.3,1); box-shadow:0 1px 4px rgba(0,0,0,.3);
  }
  .grq-toggle.on { background:var(--accent2); }
  .grq-toggle.on::after { transform:translateX(18px); }

  /* ── DIVIDER ── */
  .grq-divider { display:flex; align-items:center; gap:10px; margin:1.1rem 0 1rem; }
  .grq-divider-line { flex:1; height:1px; background:rgba(255,255,255,.07); }
  .grq-divider-text { font-family:'Syne',sans-serif; font-weight:700; font-size:.63rem; text-transform:uppercase; letter-spacing:.12em; color:rgba(255,255,255,.25); }

  /* ── GENERATING STATE ── */
  .grq-generating { display:none; flex-direction:column; align-items:center; justify-content:center; padding:2.5rem 1.5rem; gap:1.25rem; text-align:center; }
  .grq-generating.active { display:flex; }
  .gen-orb { width:80px; height:80px; position:relative; }
  .gen-orb-ring { position:absolute; inset:0; border-radius:50%; border:2px solid transparent; border-top-color:var(--accent2); border-right-color:var(--accent); animation:orbSpin 1.2s linear infinite; }
  .gen-orb-ring:nth-child(2) { inset:10px; border-top-color:var(--gold); border-right-color:var(--teal); animation-duration:.8s; animation-direction:reverse; }
  .gen-orb-core { position:absolute; inset:22px; border-radius:50%; background:linear-gradient(135deg,var(--accent2),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:1rem; animation:orbPulse 1.5s ease-in-out infinite; }
  @keyframes orbSpin { to { transform:rotate(360deg); } }
  @keyframes orbPulse { 0%,100%{transform:scale(1);opacity:1;} 50%{transform:scale(.85);opacity:.7;} }
  .gen-step-text { font-family:'Syne',sans-serif; font-weight:700; font-size:.95rem; color:white; line-height:1.4; }
  .gen-step-sub { font-size:.78rem; color:rgba(255,255,255,.35); }
  .gen-dots span { display:inline-block; width:7px; height:7px; border-radius:50%; background:var(--accent2); margin:0 3px; animation:dotBounce .8s ease-in-out infinite; }
  .gen-dots span:nth-child(2) { animation-delay:.15s; }
  .gen-dots span:nth-child(3) { animation-delay:.3s; }
  @keyframes dotBounce { 0%,80%,100%{transform:translateY(0);} 40%{transform:translateY(-8px);} }
  .gen-progress-wrap { width:100%; background:rgba(255,255,255,.08); border-radius:100px; height:4px; }
  .gen-progress-bar { height:100%; border-radius:100px; background:linear-gradient(90deg,var(--accent2),var(--accent)); width:0%; transition:width .6s ease; box-shadow:0 0 10px rgba(109,40,217,.5); }

  /* ── REVIEW PANEL ── */
  .result-summary {
    background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08);
    border-radius:14px; padding:1.1rem 1.25rem;
    display:flex; align-items:center; gap:1rem; margin-bottom:1.1rem; flex-wrap:wrap;
  }
  .result-badge {
    width:52px; height:52px; border-radius:14px;
    background:linear-gradient(135deg,var(--accent2),var(--accent));
    display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0;
    box-shadow:0 6px 20px rgba(109,40,217,.35);
  }
  .result-info { flex:1; min-width:0; }
  .result-title { font-family:'Syne',sans-serif; font-weight:900; font-size:1rem; color:white; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .result-meta { display:flex; gap:1rem; margin-top:5px; flex-wrap:wrap; }
  .result-meta-item { font-size:.7rem; color:rgba(255,255,255,.4); display:flex; align-items:center; gap:4px; }
  .result-meta-item i { color:var(--gold); }

  .q-preview-list { display:flex; flex-direction:column; gap:.55rem; }
  .q-preview-item {
    background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07);
    border-radius:10px; padding:.8rem 1rem; cursor:pointer;
    transition:background .18s,border-color .18s; display:flex; align-items:flex-start; gap:10px;
  }
  .q-preview-item:hover { background:rgba(255,255,255,.08); border-color:rgba(255,255,255,.15); }
  .q-preview-item.expanded { border-color:rgba(109,40,217,.3); }
  .q-prev-num {
    width:26px; height:26px; border-radius:7px; flex-shrink:0;
    background:rgba(255,255,255,.08); font-family:'Syne',sans-serif;
    font-weight:800; font-size:.72rem; color:rgba(255,255,255,.45);
    display:flex; align-items:center; justify-content:center;
  }
  .q-prev-body { flex:1; min-width:0; }
  .q-prev-text { font-family:'Syne',sans-serif; font-weight:700; font-size:.82rem; color:rgba(255,255,255,.85); line-height:1.4; }
  .q-prev-fact { font-size:.68rem; color:rgba(245,158,11,.7); margin-top:4px; }
  .q-prev-answers { display:none; margin-top:.65rem; grid-template-columns:1fr 1fr; gap:5px; }
  .q-prev-answers.show { display:grid; }
  .q-prev-ans { border-radius:7px; padding:6px 10px; font-size:.72rem; font-family:'DM Sans',sans-serif; font-weight:500; display:flex; align-items:center; gap:7px; color:white; }
  .q-prev-ans[data-i="0"] { background:rgba(255,63,97,.25); }
  .q-prev-ans[data-i="1"] { background:rgba(109,40,217,.25); }
  .q-prev-ans[data-i="2"] { background:rgba(245,158,11,.2); }
  .q-prev-ans[data-i="3"] { background:rgba(13,148,136,.2); }
  .q-prev-ans.correct { outline:2px solid rgba(255,255,255,.35); font-weight:700; }
  .q-prev-ans .tick { font-size:.6rem; margin-left:auto; opacity:.6; }
  .q-regen-btn {
    width:26px; height:26px; border-radius:7px; flex-shrink:0;
    background:transparent; border:1px solid rgba(255,255,255,.1);
    color:rgba(255,255,255,.3); font-size:.68rem; cursor:pointer;
    display:flex; align-items:center; justify-content:center; transition:all .18s;
  }
  .q-regen-btn:hover { background:rgba(109,40,217,.2); border-color:rgba(109,40,217,.4); color:var(--accent2); }

  /* ── BUTTONS ── */
  .grq-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 22px; border-radius:10px; border:none; font-family:'Syne',sans-serif; font-weight:800; font-size:.82rem; cursor:pointer; transition:all .18s; white-space:nowrap; }
  .grq-btn:hover { transform:translateY(-1px); }
  .grq-btn-primary { background:linear-gradient(135deg,var(--accent2),#4c1d95); color:white; box-shadow:0 4px 18px rgba(109,40,217,.4); }
  .grq-btn-primary:hover { box-shadow:0 8px 28px rgba(109,40,217,.55); }
  .grq-btn-gold { background:linear-gradient(135deg,var(--gold),#d97706); color:var(--ink); box-shadow:0 4px 18px rgba(245,158,11,.35); }
  .grq-btn-gold:hover { box-shadow:0 8px 28px rgba(245,158,11,.5); }
  .grq-btn-ghost { background:rgba(255,255,255,.07); color:rgba(255,255,255,.65); border:1px solid rgba(255,255,255,.12); }
  .grq-btn-ghost:hover { background:rgba(255,255,255,.12); color:white; }
  .grq-btn:disabled { opacity:.5; cursor:not-allowed; transform:none !important; box-shadow:none !important; }

  /* ── TOAST ── */
  #toast-container>.toast { border-radius:10px !important; font-family:'DM Sans',sans-serif !important; font-size:.875rem !important; padding:13px 17px 13px 50px !important; box-shadow:0 8px 28px rgba(0,0,0,.14) !important; }
  #toast-container>.toast-success { background-color:#052e16 !important; border-left:3px solid #22c55e !important; }
  #toast-container>.toast-error   { background-color:#450a0a !important; border-left:3px solid #ef4444 !important; }
  #toast-container>.toast-info    { background-color:var(--ink2) !important; border-left:3px solid var(--gold) !important; }

  @keyframes spin { to{transform:rotate(360deg);} }
  .spinner { animation:spin .7s linear infinite; }

  /* ── DEMO PAGE ── */
  .demo-page { min-height:100vh; display:flex; align-items:center; justify-content:center; flex-direction:column; gap:1.5rem; }
  .demo-brand { font-family:'Syne',sans-serif; font-weight:900; font-size:1.5rem; color:var(--ink); letter-spacing:-.03em; }
  .demo-brand em { color:var(--accent2); font-style:normal; }
  </style>
</head>
<body>

<div class="demo-page">
  <div class="demo-brand">Key<em>chele</em> AI Quiz</div>
  <button class="gen-quiz-trigger" onclick="GRQ.open()">
    <span class="spark">✨</span> Generate Random Quiz
  </button>
  <p style="font-size:.78rem;color:var(--muted);text-align:center;max-width:320px;line-height:1.65;">
    Click to open the AI quiz generator. Embed anywhere by calling
    <code style="background:rgba(0,0,0,.07);padding:2px 6px;border-radius:4px;">GRQ.open()</code>.
  </p>
</div>

<!-- MODAL OVERLAY -->
<div class="grq-overlay" id="grqOverlay">
  <div class="grq-modal">
    <div class="grq-header">
      <div class="grq-header-icon">✨</div>
      <div class="grq-header-text">
        <div class="grq-header-title">AI Quiz Generator</div>
        <div class="grq-header-sub">Configure your preferences — we'll do the rest in seconds</div>
      </div>
      <button class="grq-close" onclick="GRQ.close()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="grq-steps" id="grqSteps">
      <div class="grq-step active" data-step="1" id="stepTab1"><div class="grq-step-num">1</div> Type</div>
      <div class="grq-step" data-step="2" id="stepTab2"><div class="grq-step-num">2</div> Topic</div>
      <div class="grq-step" data-step="3" id="stepTab3"><div class="grq-step-num">3</div> Options</div>
      <div class="grq-step" data-step="4" id="stepTab4"><div class="grq-step-num">4</div> Review</div>
    </div>

    <div class="grq-body" id="grqBody">

      <!-- Panel 1: Type -->
      <div class="grq-panel active" id="panel1">
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-shapes"></i> Quiz Format</div>
          <div class="quiz-type-grid" id="quizTypeGrid">
            <div class="quiz-type-card selected" data-type="trivia"><div class="qtc-emoji">🧠</div><div class="qtc-info"><div class="qtc-name">Trivia / Brainstorming</div><div class="qtc-desc">General knowledge across any topic. Perfect for group fun.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="color"><div class="qtc-emoji">🎨</div><div class="qtc-info"><div class="qtc-name">Color Recognition</div><div class="qtc-desc">Colors, hex codes, color theory and design concepts.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="math"><div class="qtc-emoji">➗</div><div class="qtc-info"><div class="qtc-name">Math Challenge</div><div class="qtc-desc">Arithmetic, algebra, logic puzzles, and number problems.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="vocabulary"><div class="qtc-emoji">📚</div><div class="qtc-info"><div class="qtc-name">Vocabulary / Language</div><div class="qtc-desc">Word meanings, synonyms, antonyms, spelling bees.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="geography"><div class="qtc-emoji">🌍</div><div class="qtc-info"><div class="qtc-name">Geography & Maps</div><div class="qtc-desc">Capitals, flags, countries, continents, landmarks.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="science"><div class="qtc-emoji">🔬</div><div class="qtc-info"><div class="qtc-name">Science & Nature</div><div class="qtc-desc">Biology, physics, chemistry, space, and nature.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="history"><div class="qtc-emoji">🏛️</div><div class="qtc-info"><div class="qtc-name">History & Culture</div><div class="qtc-desc">Historical events, civilizations, art, global cultures.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="tech"><div class="qtc-emoji">💻</div><div class="qtc-info"><div class="qtc-name">Technology & AI</div><div class="qtc-desc">Programming, gadgets, internet, AI, digital trends.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="pop_culture"><div class="qtc-emoji">🎬</div><div class="qtc-info"><div class="qtc-name">Pop Culture</div><div class="qtc-desc">Movies, music, TV shows, celebrities, viral trends.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="sports"><div class="qtc-emoji">⚽</div><div class="qtc-info"><div class="qtc-name">Sports & Athletics</div><div class="qtc-desc">Rules, records, players, teams, sporting events.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
          </div>
        </div>
      </div>

      <!-- Panel 2: Topic -->
      <div class="grq-panel" id="panel2">
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-pen"></i> Quiz Title <span style="margin-left:auto;font-size:.64rem;color:rgba(255,255,255,.3);font-weight:500;text-transform:none;letter-spacing:0">optional — we'll auto-generate</span></div>
          <input type="text" class="grq-input" id="grqTitle" placeholder="e.g. African Geography Blitz, Color Theory 101…" maxlength="100">
        </div>
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-crosshairs"></i> Specific Focus <span style="margin-left:auto;font-size:.64rem;color:rgba(255,255,255,.3);font-weight:500;text-transform:none;letter-spacing:0">optional</span></div>
          <input type="text" class="grq-input" id="grqFocus" placeholder="e.g. East Africa, RGB color model, Premier League 2023…" maxlength="120">
          <div class="grq-hint">Leave blank to let AI pick interesting sub-topics.</div>
        </div>
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-users"></i> Target Audience</div>
          <div class="topic-tags" id="audienceTags">
            <div class="topic-tag selected" data-val="general">👥 General</div>
            <div class="topic-tag" data-val="kids">🧒 Kids (6–12)</div>
            <div class="topic-tag" data-val="teens">🧑‍🎓 Teens (13–18)</div>
            <div class="topic-tag" data-val="adults">🏢 Adults</div>
            <div class="topic-tag" data-val="seniors">👴 Seniors</div>
            <div class="topic-tag" data-val="students">📖 Students</div>
            <div class="topic-tag" data-val="professionals">💼 Professionals</div>
          </div>
        </div>
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-language"></i> Language</div>
          <select class="grq-select" id="grqLanguage">
            <option value="English">🇬🇧 English</option>
            <option value="Swahili">🇰🇪 Swahili</option>
            <option value="French">🇫🇷 French</option>
            <option value="Spanish">🇪🇸 Spanish</option>
            <option value="Arabic">🇸🇦 Arabic</option>
            <option value="Portuguese">🇧🇷 Portuguese</option>
            <option value="German">🇩🇪 German</option>
            <option value="Chinese">🇨🇳 Chinese (Simplified)</option>
          </select>
        </div>
      </div>

      <!-- Panel 3: Options -->
      <div class="grq-panel" id="panel3">
        <div class="grq-row">
          <div class="grq-field">
            <div class="grq-label"><i class="fa-solid fa-list-ol"></i> Number of Questions</div>
            <div class="count-stepper">
              <button class="count-btn" onclick="GRQ.stepCount(-1)">−</button>
              <input type="text" class="count-val" id="grqCount" value="10" readonly>
              <button class="count-btn" onclick="GRQ.stepCount(1)">+</button>
            </div>
            <div class="grq-hint">Min 3 · Max 30</div>
          </div>
          <div class="grq-field">
            <div class="grq-label"><i class="fa-regular fa-clock"></i> Time Per Question</div>
            <select class="grq-select" id="grqTimePerQ">
              <option value="10">⚡ 10 sec (Fast)</option>
              <option value="20" selected>🎯 20 sec (Normal)</option>
              <option value="30">🧠 30 sec (Thinking)</option>
              <option value="60">💡 60 sec (Deep)</option>
            </select>
          </div>
        </div>
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-signal"></i> Difficulty</div>
          <div class="diff-pills">
            <div class="diff-pill selected" data-diff="easy">😊 Easy</div>
            <div class="diff-pill" data-diff="medium">🤔 Medium</div>
            <div class="diff-pill" data-diff="hard">🔥 Hard</div>
          </div>
        </div>
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-layer-group"></i> Answer Format</div>
          <div class="topic-tags" id="answerFormatTags">
            <div class="topic-tag selected" data-val="multiple_choice">📋 Multiple Choice (4)</div>
            <div class="topic-tag" data-val="true_false">✅ True / False</div>
            <div class="topic-tag" data-val="mixed">🔀 Mixed</div>
          </div>
        </div>
        <div class="grq-divider"><div class="grq-divider-line"></div><div class="grq-divider-text">Advanced</div><div class="grq-divider-line"></div></div>
        <div class="grq-toggle-row"><div><div class="grq-toggle-label">Include fun facts / explanations</div><div class="grq-hint" style="margin-top:2px">Adds a short explanation after each correct answer</div></div><button class="grq-toggle on" id="toggleFacts" onclick="this.classList.toggle('on')"></button></div>
        <div class="grq-toggle-row" style="margin-top:.6rem"><div><div class="grq-toggle-label">Shuffle answer order</div><div class="grq-hint" style="margin-top:2px">Randomize options so correct answer position varies</div></div><button class="grq-toggle on" id="toggleShuffle" onclick="this.classList.toggle('on')"></button></div>
        <div class="grq-toggle-row" style="margin-top:.6rem"><div><div class="grq-toggle-label">Double points for last question</div><div class="grq-hint" style="margin-top:2px">Makes the final question worth 2× points</div></div><button class="grq-toggle" id="toggleDouble" onclick="this.classList.toggle('on')"></button></div>
        <div class="grq-field" style="margin-top:.75rem">
          <div class="grq-label"><i class="fa-solid fa-globe"></i> Visibility</div>
          <select class="grq-select" id="grqVisibility">
            <option value="public">🌍 Public — anyone can find it</option>
            <option value="private">🔒 Private — only via PIN</option>
          </select>
        </div>
      </div>

      <!-- Generating -->
      <div class="grq-generating" id="grqGenerating">
        <div class="gen-orb">
          <div class="gen-orb-ring"></div>
          <div class="gen-orb-ring"></div>
          <div class="gen-orb-core">✨</div>
        </div>
        <div>
          <div class="gen-step-text" id="genStepText">Crafting your questions…</div>
          <div class="gen-step-sub" id="genStepSub">Hang tight, this takes just a moment</div>
        </div>
        <div class="gen-dots"><span></span><span></span><span></span></div>
        <div style="width:100%;max-width:320px">
          <div class="gen-progress-wrap"><div class="gen-progress-bar" id="genProgressBar"></div></div>
        </div>
      </div>

      <!-- Panel 4: Review -->
      <div class="grq-panel" id="panel4">
        <div class="result-summary" id="resultSummary"></div>
        <div class="grq-label" style="margin-bottom:.6rem"><i class="fa-solid fa-list-check"></i> Questions Preview <span style="margin-left:6px;font-size:.62rem;color:rgba(255,255,255,.2);font-weight:500;text-transform:none;letter-spacing:0">Click to expand · ↺ to regenerate</span></div>
        <div class="q-preview-list" id="qPreviewList"></div>
      </div>

    </div><!-- /body -->

    <div class="grq-footer" id="grqFooter">
      <button class="grq-btn grq-btn-ghost" id="btnBack" onclick="GRQ.back()" style="display:none"><i class="fa-solid fa-chevron-left"></i> Back</button>
      <div style="flex:1"></div>
      <button class="grq-btn grq-btn-ghost" onclick="GRQ.close()">Cancel</button>
      <button class="grq-btn grq-btn-primary" id="btnNext" onclick="GRQ.next()">Next <i class="fa-solid fa-chevron-right"></i></button>
    </div>
  </div>
</div>

<script>
// ═══════════════════════════════════════════════════════
// PROXY HELPER — all AI calls go through create_game.php
// ═══════════════════════════════════════════════════════
async function callAI(prompt, max_tokens = 4000) {
  const fd = new FormData();
  fd.append('action', 'ai_generate');
  fd.append('prompt', prompt);
  fd.append('max_tokens', max_tokens);

  const res = await fetch('create_game.php', { method: 'POST', body: fd });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err?.error?.message || 'Server error ' + res.status);
  }
  const data = await res.json();
  if (data.error) throw new Error(data.error.message || JSON.stringify(data.error));
  const text = data.content?.find(b => b.type === 'text')?.text || '';
  if (!text) throw new Error('Empty response from AI.');
  return text;
}

// ═══════════════════════════════════════════════════════
// GRQ MODULE
// ═══════════════════════════════════════════════════════
const GRQ = (() => {
  let step = 1;
  let generatedQuestions = [];
  let generatedData = null;
  let genProgressInterval = null;
  const TOTAL = 4;

  const GEN_MESSAGES = [
    ['Consulting the knowledge oracle…',  'Summoning answers from the void'],
    ['Crafting tricky distractors…',      'Making wrong answers convincingly wrong'],
    ['Balancing difficulty curve…',       'Not too easy, not too brutal'],
    ['Adding some spice…',               'Inserting a fun fact or two'],
    ['Final polish…',                    'Almost ready — looking good!'],
  ];

  // ── open / close ──────────────────────────────────────
  function open() {
    step = 1; generatedQuestions = []; generatedData = null;
    resetUI();
    document.getElementById('grqOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function close() {
    document.getElementById('grqOverlay').classList.remove('open');
    document.body.style.overflow = '';
  }

  // ── navigation ────────────────────────────────────────
  function goTo(s) {
    step = s;
    document.querySelectorAll('.grq-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('grqGenerating').classList.remove('active');
    if (s <= TOTAL) document.getElementById('panel' + s)?.classList.add('active');

    document.querySelectorAll('.grq-step').forEach(t => {
      const ts = parseInt(t.dataset.step);
      t.classList.remove('active', 'done');
      if (ts === s) t.classList.add('active');
      if (ts < s)  t.classList.add('done');
    });

    const btnBack = document.getElementById('btnBack');
    const btnNext = document.getElementById('btnNext');
    btnBack.style.display = (s > 1 && s <= TOTAL) ? 'inline-flex' : 'none';

    if (s === TOTAL) {
      btnNext.innerHTML = '<i class="fa-solid fa-rocket"></i> Save & Use Quiz';
      btnNext.className = 'grq-btn grq-btn-gold';
    } else if (s === TOTAL - 1) {
      btnNext.innerHTML = '✨ Generate Quiz <i class="fa-solid fa-wand-magic-sparkles"></i>';
      btnNext.className = 'grq-btn grq-btn-primary';
    } else {
      btnNext.innerHTML = 'Next <i class="fa-solid fa-chevron-right"></i>';
      btnNext.className = 'grq-btn grq-btn-primary';
    }
    document.getElementById('grqBody').scrollTop = 0;
  }

  function next() {
    if (step === 1) {
      if (!getType()) { toastr.warning('Please select a quiz type.'); return; }
      goTo(2);
    } else if (step === 2) { goTo(3); }
    else if (step === 3) { generate(); }
    else if (step === TOTAL) { saveQuiz(); }
  }

  function back() { if (step > 1) goTo(step - 1); }

  // ── getters ───────────────────────────────────────────
  function getType()     { return document.querySelector('.quiz-type-card.selected')?.dataset.type || null; }
  function getAudience() { return document.querySelector('#audienceTags .topic-tag.selected')?.dataset.val || 'general'; }
  function getDiff()     { return document.querySelector('.diff-pill.selected')?.dataset.diff || 'medium'; }
  function getFmt()      { return document.querySelector('#answerFormatTags .topic-tag.selected')?.dataset.val || 'multiple_choice'; }
  function stepCount(dir) { const inp=document.getElementById('grqCount'); inp.value=Math.min(30,Math.max(3,parseInt(inp.value)+dir)); }

  function resetUI() {
    document.querySelectorAll('.quiz-type-card').forEach(c => c.classList.remove('selected'));
    document.querySelector('.quiz-type-card[data-type="trivia"]')?.classList.add('selected');
    document.getElementById('grqTitle').value = '';
    document.getElementById('grqFocus').value = '';
    document.getElementById('grqCount').value = '10';
    document.getElementById('grqTimePerQ').value = '20';
    document.getElementById('grqLanguage').value = 'English';
    document.getElementById('grqVisibility').value = 'public';
    document.querySelectorAll('.diff-pill').forEach(p => p.classList.remove('selected'));
    document.querySelector('.diff-pill[data-diff="easy"]')?.classList.add('selected');
    document.querySelectorAll('#audienceTags .topic-tag').forEach(t => t.classList.remove('selected'));
    document.querySelector('#audienceTags .topic-tag[data-val="general"]')?.classList.add('selected');
    document.querySelectorAll('#answerFormatTags .topic-tag').forEach(t => t.classList.remove('selected'));
    document.querySelector('#answerFormatTags .topic-tag[data-val="multiple_choice"]')?.classList.add('selected');
    document.getElementById('toggleFacts').classList.add('on');
    document.getElementById('toggleShuffle').classList.add('on');
    document.getElementById('toggleDouble').classList.remove('on');
    goTo(1);
  }

  // ── prompt builder ────────────────────────────────────
  function buildPrompt() {
    const typeMap = {
      trivia:'general knowledge trivia / brainstorming', color:'color recognition and color theory',
      math:'math challenges and number problems', vocabulary:'vocabulary and language',
      geography:'geography and world maps', science:'science and nature',
      history:'history and culture', tech:'technology and AI',
      pop_culture:'pop culture (movies, music, TV)', sports:'sports and athletics'
    };
    const type    = getType();
    const count   = parseInt(document.getElementById('grqCount').value);
    const diff    = getDiff();
    const aud     = getAudience();
    const lang    = document.getElementById('grqLanguage').value;
    const focus   = document.getElementById('grqFocus').value.trim();
    const title   = document.getElementById('grqTitle').value.trim();
    const fmt     = getFmt();
    const time    = document.getElementById('grqTimePerQ').value;
    const facts   = document.getElementById('toggleFacts').classList.contains('on');
    const shuffle = document.getElementById('toggleShuffle').classList.contains('on');
    const dbl     = document.getElementById('toggleDouble').classList.contains('on');

    const fmtInstr = fmt === 'multiple_choice'
      ? 'All questions must be multiple choice with exactly 4 options.'
      : fmt === 'true_false'
      ? 'All questions must be True/False format (only 2 options: "True" and "False").'
      : 'Mix multiple choice (4 options) and True/False questions in roughly equal proportion.';

    return `You are a professional educational quiz creator for Keychele, a live quiz platform used in Kenya and Africa.

Create a ${count}-question ${typeMap[type]||type} quiz in ${lang} for a ${aud} audience at ${diff} difficulty.${focus ? ` Focus on: "${focus}".` : ''}${title ? ` Suggested title: "${title}".` : ''}

${fmtInstr}
Each question must have ONE correct answer.
Time limit per question: ${time} seconds.
Points: ${dbl ? 'standard for all except last question = "double"' : 'standard for all'}.
${facts ? 'Include a short "fact" field (1–2 sentences) explaining the correct answer.' : ''}
${shuffle ? 'Randomize the position of the correct answer across questions.' : ''}

Respond ONLY with valid JSON, no markdown, no preamble:
{
  "title": "...",
  "description": "...",
  "category": "one of: General Knowledge, Education, Entertainment, Sports, Science, History, Geography, Music, Technology, Business, Other",
  "questions": [
    {
      "text": "Question?",
      "type": "quiz",
      "timeLimit": ${time},
      "points": "standard",
      "answerMode": "single",
      "image": null,
      "fact": "",
      "answers": [
        {"text": "...", "correct": false},
        {"text": "...", "correct": true},
        {"text": "...", "correct": false},
        {"text": "...", "correct": false}
      ]
    }
  ]
}`;
  }

  // ── generation ────────────────────────────────────────
  async function generate() {
    document.querySelectorAll('.grq-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('grqGenerating').classList.add('active');
    document.getElementById('btnBack').style.display = 'none';
    document.getElementById('btnNext').style.display = 'none';
    document.getElementById('grqSteps').style.opacity = '.4';

    let progress = 0;
    const bar     = document.getElementById('genProgressBar');
    const stepTxt = document.getElementById('genStepText');
    const stepSub = document.getElementById('genStepSub');

    genProgressInterval = setInterval(() => {
      progress = Math.min(progress + Math.random() * 5 + 1.5, 88);
      bar.style.width = progress + '%';
      const m = GEN_MESSAGES[Math.min(Math.floor(progress / 20), GEN_MESSAGES.length - 1)];
      if (stepTxt.textContent !== m[0]) { stepTxt.textContent = m[0]; stepSub.textContent = m[1]; }
    }, 700);

    try {
      // ✅ Proxy call — no API key in browser
      const raw    = await callAI(buildPrompt(), 4000);
      const parsed = JSON.parse(raw.replace(/```json|```/g, '').trim());
      clearInterval(genProgressInterval);
      bar.style.width = '100%';
      if (!parsed.questions?.length) throw new Error('No questions returned.');

      generatedData      = parsed;
      generatedQuestions = parsed.questions;

      await new Promise(r => setTimeout(r, 400));
      document.getElementById('grqGenerating').classList.remove('active');
      document.getElementById('grqSteps').style.opacity = '1';
      renderReview(parsed);
      goTo(4);

    } catch (err) {
      clearInterval(genProgressInterval);
      bar.style.width = '0%';
      document.getElementById('grqGenerating').classList.remove('active');
      document.getElementById('grqSteps').style.opacity = '1';
      goTo(3);
      toastr.error('Generation failed: ' + err.message, 'Oops!', { timeOut: 7000 });
    }
  }

  // ── render review ─────────────────────────────────────
  function renderReview(data) {
    const total = data.questions.length;
    const secs  = data.questions.reduce((s, q) => s + parseInt(q.timeLimit || 20), 0);

    document.getElementById('resultSummary').innerHTML = `
      <div class="result-badge">✨</div>
      <div class="result-info">
        <div class="result-title">${escHtml(data.title || 'AI Generated Quiz')}</div>
        <div class="result-meta">
          <span class="result-meta-item"><i class="fa-solid fa-circle-question"></i> ${total} questions</span>
          <span class="result-meta-item"><i class="fa-solid fa-clock"></i> ~${Math.ceil(secs/60)} min</span>
          <span class="result-meta-item"><i class="fa-solid fa-tag"></i> ${data.category || 'General'}</span>
          <span class="result-meta-item"><i class="fa-solid fa-signal"></i> ${getDiff()}</span>
        </div>
      </div>`;

    document.getElementById('qPreviewList').innerHTML = data.questions.map((q, i) => `
      <div class="q-preview-item" onclick="GRQ.toggleItem(this)">
        <div class="q-prev-num">${i + 1}</div>
        <div class="q-prev-body">
          <div class="q-prev-text">${escHtml(q.text)}</div>
          ${q.fact ? `<div class="q-prev-fact"><i class="fa-solid fa-lightbulb"></i> ${escHtml(q.fact)}</div>` : ''}
          <div class="q-prev-answers" id="prevAns_${i}">
            ${(q.answers || []).map((a, j) => `
              <div class="q-prev-ans ${a.correct ? 'correct' : ''}" data-i="${j}">
                ${escHtml(a.text)}
                ${a.correct ? '<span class="tick"><i class="fa-solid fa-check"></i></span>' : ''}
              </div>`).join('')}
          </div>
        </div>
        <button class="q-regen-btn" onclick="event.stopPropagation(); GRQ.regenQ(${i})" title="Regenerate">
          <i class="fa-solid fa-arrows-rotate"></i>
        </button>
      </div>`).join('');
  }

  function toggleItem(el) {
    el.classList.toggle('expanded');
    const ans = el.querySelector('.q-prev-answers');
    if (ans) ans.classList.toggle('show');
  }

  // ── regen single question ─────────────────────────────
  async function regenQ(idx) {
    if (!generatedQuestions[idx]) return;
    const btn = document.querySelector(`#qPreviewList .q-preview-item:nth-child(${idx + 1}) .q-regen-btn`);
    if (btn) { btn.innerHTML = '<i class="fa-solid fa-spinner spinner"></i>'; btn.disabled = true; }

    try {
      const q      = generatedQuestions[idx];
      const prompt = `Replace this quiz question with a fresh one on the same topic and difficulty. Current: "${q.text}". Return ONLY a JSON object (no markdown): {text,type:"quiz",timeLimit:20,points:"standard",answerMode:"single",image:null,fact:"",answers:[{text,correct},...]}`;
      // ✅ Proxy call
      const raw  = await callAI(prompt, 600);
      const newQ = JSON.parse(raw.replace(/```json|```/g, '').trim());
      generatedQuestions[idx] = newQ;

      const item = document.querySelector(`#qPreviewList .q-preview-item:nth-child(${idx + 1})`);
      if (item) {
        item.querySelector('.q-prev-text').textContent = newQ.text;
        const factEl = item.querySelector('.q-prev-fact');
        if (factEl) factEl.textContent = newQ.fact ? '💡 ' + newQ.fact : '';
        const ansEl = document.getElementById('prevAns_' + idx);
        if (ansEl) ansEl.innerHTML = (newQ.answers || []).map((a, j) => `
          <div class="q-prev-ans ${a.correct ? 'correct' : ''}" data-i="${j}">
            ${escHtml(a.text)}
            ${a.correct ? '<span class="tick"><i class="fa-solid fa-check"></i></span>' : ''}
          </div>`).join('');
      }
      toastr.success('Question regenerated!', '', { timeOut: 2000 });
    } catch (e) {
      toastr.error('Regen failed: ' + e.message);
    } finally {
      if (btn) { btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i>'; btn.disabled = false; }
    }
  }

  // ── save ──────────────────────────────────────────────
  async function saveQuiz() {
    const btnNext = document.getElementById('btnNext');
    btnNext.disabled = true;
    btnNext.innerHTML = '<i class="fa-solid fa-spinner spinner"></i> Saving…';

    const title       = document.getElementById('grqTitle').value.trim() || generatedData?.title || 'AI Generated Quiz';
    const visibility  = document.getElementById('grqVisibility').value;
    const category    = generatedData?.category || 'General Knowledge';
    const description = `AI-generated ${getType()} quiz | ${getDiff()} difficulty`;

    const fd = new FormData();
    fd.append('action', 'save_generated');
    fd.append('title', title);
    fd.append('description', description);
    fd.append('category', category);
    fd.append('visibility', visibility);
    fd.append('questions_json', JSON.stringify(generatedQuestions));

    try {
      const res  = await fetch('generate_random_quiz.php', { method: 'POST', body: fd });
      const json = await res.json();
      if (json.error) throw new Error(json.error);
      toastr.success(`Quiz saved! PIN: <strong>${json.pin}</strong>`, '🎉 Done!', { timeOut: 0, extendedTimeOut: 0 });
      close();
      setTimeout(() => { window.location.href = 'host_game.php?id=' + json.game_id; }, 1400);
    } catch (e) {
      toastr.error('Save failed: ' + e.message);
      btnNext.disabled = false;
      btnNext.innerHTML = '<i class="fa-solid fa-rocket"></i> Save & Use Quiz';
    }
  }

  function escHtml(s) {
    return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  return { open, close, next, back, stepCount, toggleItem, regenQ };
})();

// ── Event delegation ──────────────────────────────────────
document.getElementById('quizTypeGrid').addEventListener('click', e => {
  const card = e.target.closest('.quiz-type-card');
  if (!card) return;
  document.querySelectorAll('.quiz-type-card').forEach(c => c.classList.remove('selected'));
  card.classList.add('selected');
});
document.getElementById('audienceTags').addEventListener('click', e => {
  const t = e.target.closest('.topic-tag'); if (!t) return;
  document.querySelectorAll('#audienceTags .topic-tag').forEach(x => x.classList.remove('selected'));
  t.classList.add('selected');
});
document.getElementById('answerFormatTags').addEventListener('click', e => {
  const t = e.target.closest('.topic-tag'); if (!t) return;
  document.querySelectorAll('#answerFormatTags .topic-tag').forEach(x => x.classList.remove('selected'));
  t.classList.add('selected');
});
document.querySelectorAll('.diff-pill').forEach(pill => {
  pill.addEventListener('click', () => {
    document.querySelectorAll('.diff-pill').forEach(p => p.classList.remove('selected'));
    pill.classList.add('selected');
  });
});
document.getElementById('grqOverlay').addEventListener('click', e => {
  if (e.target === document.getElementById('grqOverlay')) GRQ.close();
});

toastr.options = {
  closeButton: true, progressBar: true,
  positionClass: 'toast-top-right', timeOut: 5000,
  showMethod: 'slideDown', hideMethod: 'fadeOut'
};
</script>

</body>
</html>