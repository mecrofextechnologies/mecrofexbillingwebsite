<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ═══════════════════════════════════════════════════════════════
//  GEMINI API KEY
// ═══════════════════════════════════════════════════════════════
define('GEMINI_API_KEY', 'AIzaSyCpT5OPhH3CY90WKvXGN9ZojEY0E4EtIFU');

include 'includes/connect.php';
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header('Content-Type: application/json');
    http_response_code(401);
    die(json_encode(['error' => ['message' => 'Please login first.']]));
}

// ───────────────────────────────────────────────────────────────
//  AJAX: AI GENERATION PROXY  (action=ai_generate)
// ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_generate') {
    header('Content-Type: application/json');
    try {
        $prompt     = trim($_POST['prompt'] ?? '');
        if (empty($prompt)) throw new Exception('No prompt provided.');

        $payload = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature'     => 0.7,
                'maxOutputTokens' => 4000,
            ]
        ]);

      $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-8b:generateContent?key=' . GEMINI_API_KEY;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) throw new Exception('cURL error: ' . $curl_err);
        if ($http_code !== 200) {
            $body = json_decode($response, true);
            throw new Exception('Gemini API error (' . $http_code . '): ' . ($body['error']['message'] ?? $response));
        }

        $body = json_decode($response, true);
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (!$text) throw new Exception('Empty response from Gemini.');

        // Return in Anthropic-compatible format so the JS needs zero changes
        echo json_encode([
            'content' => [['type' => 'text', 'text' => $text]]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => ['message' => $e->getMessage()]]);
    }
    exit;
}

// ───────────────────────────────────────────────────────────────
//  CREATE TABLES
// ───────────────────────────────────────────────────────────────
$pdo->exec("
CREATE TABLE IF NOT EXISTS creator_games (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    creator_id      INT UNSIGNED NOT NULL,
    title           VARCHAR(150) NOT NULL,
    description     TEXT,
    category        VARCHAR(60)  DEFAULT 'General',
    visibility      ENUM('public','private') DEFAULT 'public',
    cover           VARCHAR(255),
    questions_json  LONGTEXT     NOT NULL,
    game_pin        CHAR(6)      UNIQUE,
    status          ENUM('draft','lobby','question','results','ended') DEFAULT 'draft',
    current_question INT         DEFAULT -1,
    play_count      INT UNSIGNED DEFAULT 0,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_creator (creator_id),
    INDEX idx_pin     (game_pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS game_sessions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id     INT UNSIGNED NOT NULL,
    player_name VARCHAR(80)  NOT NULL,
    score       INT          DEFAULT 0,
    answers_json TEXT,
    joined_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pageStmt = $pdo->prepare("SELECT id FROM my_page WHERE creator_id = ? LIMIT 1");
$pageStmt->execute([$creator_id]);
$has_page = (bool)$pageStmt->fetchColumn();

$success  = $error = "";
$game_pin = "";

function generateUniquePin($pdo) {
    $attempts = 0;
    do {
        $pin = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare("SELECT id FROM creator_games WHERE game_pin = ? LIMIT 1");
        $chk->execute([$pin]);
        $attempts++;
    } while ($chk->fetchColumn() && $attempts < 20);
    return $pin;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $has_page) {
    try {
        $title       = trim($_POST['game_title'] ?? '');
        $description = trim($_POST['game_description'] ?? '');
        $category    = $_POST['game_category'] ?? 'General';
        $visibility  = $_POST['visibility'] ?? 'public';
        $questions   = json_decode($_POST['questions_json'] ?? '[]', true);

        if (empty($title))     throw new Exception("Game title is required.");
        if (empty($questions)) throw new Exception("Add at least one question.");

        $cover_name = null;
        if (!empty($_FILES['cover']['name'])) {
            $target_dir = "uploads/game_covers/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $cover_name = time() . "_" . basename($_FILES['cover']['name']);
            move_uploaded_file($_FILES['cover']['tmp_name'], $target_dir . $cover_name);
        }

        $pdo->beginTransaction();
        $pin  = generateUniquePin($pdo);
        $stmt = $pdo->prepare("INSERT INTO creator_games
            (creator_id, title, description, category, visibility, cover, questions_json, game_pin, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'lobby', NOW())");
        $stmt->execute([$creator_id, $title, $description, $category, $visibility, $cover_name, json_encode($questions), $pin]);
        $game_id_new = $pdo->lastInsertId();
        $pdo->commit();
        $success  = "Game created!";
        $game_pin = $pin;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Game — Keychele</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800;900&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

  <style>
  :root {
    --ink:      #0a0c14;
    --ink2:     #141828;
    --ink3:     #1e2236;
    --surface:  #f4f3ef;
    --surface2: #eeede8;
    --card:     #ffffff;
    --border:   #e0ddd6;
    --border2:  #cac8c0;
    --muted:    #8f8d98;
    --accent:   #ff3f61;
    --accent-h: #e8294f;
    --accent2:  #6d28d9;
    --accent2-h:#5b21b6;
    --gold:     #f59e0b;
    --gold-h:   #d97706;
    --teal:     #0d9488;
    --teal-h:   #0f766e;
    --emerald:  #059669;
    --ans-0: #ff3f61;
    --ans-1: #6d28d9;
    --ans-2: #f59e0b;
    --ans-3: #0d9488;
    --r:     10px;
    --r-lg:  18px;
    --r-xl:  24px;
    --sh:    0 1px 12px rgba(10,12,20,.07);
    --sh-md: 0 4px 20px rgba(10,12,20,.10);
    --sh-lg: 0 12px 48px rgba(10,12,20,.16);
    --sh-xl: 0 24px 80px rgba(10,12,20,.24);
    --topbar-h: 58px;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { height: 100%; }
  body {
    font-family: 'DM Sans', system-ui, sans-serif;
    background: var(--surface);
    color: var(--ink);
    height: 100%;
    -webkit-font-smoothing: antialiased;
  }
  h1,h2,h3,h4 { font-family: 'Syne', sans-serif; }

  /* ── LAYOUT ── */
  .editor-layout {
    display: grid;
    grid-template-columns: 256px 1fr 280px;
    grid-template-rows: var(--topbar-h) 1fr;
    height: 100vh;
    overflow: hidden;
  }

  /* ── TOPBAR ── */
  .topbar {
    grid-column: 1 / -1;
    background: var(--ink);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 1.1rem; gap: .75rem;
    position: relative; z-index: 200;
  }
  .topbar::after {
    content: '';
    position: absolute; bottom: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, var(--accent) 0%, var(--accent2) 40%, var(--teal) 70%, var(--gold) 100%);
  }
  .topbar-left { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
  .brand {
    font-family: 'Syne', sans-serif; font-weight: 900; font-size: 1.05rem;
    color: white; white-space: nowrap; letter-spacing: -.03em;
    display: flex; align-items: center; gap: 6px; flex-shrink: 0;
  }
  .brand-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--gold));
    box-shadow: 0 0 8px rgba(255,63,97,.5);
    animation: dotPulse 3s ease-in-out infinite;
  }
  @keyframes dotPulse { 0%,100% { transform:scale(1); } 50% { transform:scale(1.3); } }
  .brand em { color: var(--gold); font-style: normal; }
  .tb-sep { width: 1px; height: 20px; background: rgba(255,255,255,.1); flex-shrink: 0; }
  .game-title-wrap { position: relative; flex: 1; min-width: 0; max-width: 300px; }
  .game-title-input {
    width: 100%; background: rgba(255,255,255,.07);
    border: 1.5px solid rgba(255,255,255,.1);
    border-radius: 8px; padding: 7px 14px;
    color: white; font-family: 'Syne', sans-serif;
    font-weight: 700; font-size: .83rem; outline: none;
    transition: border-color .2s, background .2s, box-shadow .2s;
  }
  .game-title-input::placeholder { color: rgba(255,255,255,.25); }
  .game-title-input:focus { border-color: var(--gold); background: rgba(255,255,255,.11); box-shadow: 0 0 0 3px rgba(245,158,11,.15); }
  .topbar-actions { display: flex; align-items: center; gap: 7px; flex-shrink: 0; }
  .tb-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 15px; border-radius: 8px;
    font-family: 'Syne', sans-serif; font-weight: 700; font-size: .75rem;
    cursor: pointer; border: none; transition: all .18s; white-space: nowrap;
  }
  .tb-btn-ghost { background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.13); color: rgba(255,255,255,.75); }
  .tb-btn-ghost:hover { background: rgba(255,255,255,.13); color: white; }
  .tb-btn-ai {
    background: linear-gradient(135deg, rgba(109,40,217,.8), rgba(109,40,217,.5));
    border: 1px solid rgba(109,40,217,.5); color: white;
    box-shadow: 0 0 20px rgba(109,40,217,.2);
    position: relative; overflow: hidden;
  }
  .tb-btn-ai::before { content:''; position:absolute; inset:0; background:linear-gradient(135deg,rgba(255,255,255,.12),transparent); }
  .tb-btn-ai:hover { background:linear-gradient(135deg,rgba(109,40,217,1),rgba(109,40,217,.7)); box-shadow:0 4px 20px rgba(109,40,217,.45); transform:translateY(-1px); }
  .tb-btn-ai .ai-spark { animation: sparkle 2.5s ease-in-out infinite; }
  @keyframes sparkle { 0%,100% { transform:scale(1) rotate(0deg); } 50% { transform:scale(1.25) rotate(12deg); } }
  .tb-btn-accent { background: var(--accent); color: white; box-shadow: 0 2px 12px rgba(255,63,97,.3); }
  .tb-btn-accent:hover { background:var(--accent-h); transform:translateY(-1px); box-shadow:0 6px 22px rgba(255,63,97,.45); }

  /* ── LEFT SIDEBAR ── */
  .sidebar-left { background:var(--ink2); border-right:1px solid rgba(255,255,255,.05); display:flex; flex-direction:column; overflow:hidden; }
  .sl-header { padding:.9rem 1rem .75rem; border-bottom:1px solid rgba(255,255,255,.06); display:flex; align-items:center; justify-content:space-between; gap:8px; }
  .sl-header-left { display:flex; align-items:center; gap:8px; }
  .sl-header-title { font-family:'Syne',sans-serif; font-weight:700; font-size:.65rem; text-transform:uppercase; letter-spacing:.14em; color:rgba(255,255,255,.3); }
  .sl-count { background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.22); border-radius:100px; padding:1px 8px; font-family:'Syne',sans-serif; font-weight:900; font-size:.67rem; color:var(--gold); }
  .sl-sort-btn { margin-left:auto; width:24px; height:24px; border-radius:6px; background:transparent; border:1px solid rgba(255,255,255,.08); color:rgba(255,255,255,.25); font-size:.65rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .18s; }
  .sl-sort-btn:hover { background:rgba(255,255,255,.08); color:rgba(255,255,255,.6); }
  .question-list { flex:1; overflow-y:auto; padding:.5rem; display:flex; flex-direction:column; gap:.35rem; }
  .question-list::-webkit-scrollbar { width:2px; }
  .question-list::-webkit-scrollbar-thumb { background:rgba(255,255,255,.08); border-radius:2px; }
  .q-thumb { background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.05); border-radius:8px; padding:8px 9px; cursor:pointer; transition:all .15s; display:flex; align-items:center; gap:8px; }
  .q-thumb:hover { background:rgba(255,255,255,.07); border-color:rgba(255,255,255,.12); transform:translateX(2px); }
  .q-thumb.active { background:rgba(255,63,97,.1); border-color:rgba(255,63,97,.3); }
  .q-thumb-num { width:22px; height:22px; border-radius:6px; background:rgba(255,255,255,.07); font-family:'Syne',sans-serif; font-weight:900; font-size:.65rem; color:rgba(255,255,255,.35); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .q-thumb.active .q-thumb-num { background:var(--accent); color:white; }
  .q-thumb-info { flex:1; min-width:0; }
  .q-thumb-text { font-size:.7rem; color:rgba(255,255,255,.45); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .q-thumb.active .q-thumb-text { color:rgba(255,255,255,.85); }
  .q-thumb-type { font-size:.58rem; color:rgba(255,255,255,.2); font-family:'Syne',sans-serif; font-weight:700; text-transform:uppercase; letter-spacing:.08em; margin-top:2px; }
  .q-thumb.active .q-thumb-type { color:rgba(255,63,97,.6); }
  .q-thumb-del { width:20px; height:20px; border-radius:5px; background:transparent; border:none; color:rgba(255,255,255,.12); font-size:.58rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; flex-shrink:0; opacity:0; }
  .q-thumb:hover .q-thumb-del { opacity:1; }
  .q-thumb-del:hover { color:#ff8fa3; background:rgba(255,63,97,.2); }
  .add-q-btn { margin:.5rem; padding:9px; background:rgba(245,158,11,.06); border:1px dashed rgba(245,158,11,.2); border-radius:8px; color:rgba(245,158,11,.7); font-family:'Syne',sans-serif; font-weight:700; font-size:.72rem; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:7px; transition:all .18s; flex-shrink:0; }
  .add-q-btn:hover { background:rgba(245,158,11,.12); border-color:rgba(245,158,11,.4); color:var(--gold); }

  /* ── CENTER EDITOR ── */
  .editor-center { background:var(--surface); overflow-y:auto; display:flex; flex-direction:column; padding:1.25rem 1.5rem; }
  .editor-center::-webkit-scrollbar { width:4px; }
  .editor-center::-webkit-scrollbar-thumb { background:rgba(10,12,20,.1); border-radius:4px; }
  .editor-header-row { display:flex; align-items:center; gap:8px; margin-bottom:1rem; flex-wrap:wrap; }
  .q-type-label { font-family:'Syne',sans-serif; font-weight:700; font-size:.65rem; color:var(--muted); text-transform:uppercase; letter-spacing:.1em; }
  .q-type-pills { display:flex; gap:4px; }
  .q-type-pill { padding:5px 12px; border-radius:100px; font-family:'Syne',sans-serif; font-weight:700; font-size:.65rem; cursor:pointer; border:1.5px solid var(--border); background:white; color:var(--muted); transition:all .18s; }
  .q-type-pill:hover { border-color:var(--ink); color:var(--ink); }
  .q-type-pill.active { background:var(--ink); color:white; border-color:var(--ink); }
  .q-index-badge { margin-left:auto; font-family:'Syne',sans-serif; font-weight:800; font-size:.68rem; color:var(--muted); background:white; padding:3px 11px; border-radius:100px; border:1.5px solid var(--border); box-shadow:var(--sh); }
  .q-canvas { background:var(--ink2); border-radius:var(--r-lg); min-height:200px; position:relative; overflow:hidden; margin-bottom:1rem; box-shadow:var(--sh-lg); padding:1.5rem; display:flex; flex-direction:column; align-items:center; justify-content:center; }
  .q-canvas::before { content:''; position:absolute; inset:0; pointer-events:none; background-image:radial-gradient(circle at 20% 50%,rgba(109,40,217,.06) 0%,transparent 50%),radial-gradient(circle at 80% 20%,rgba(255,63,97,.05) 0%,transparent 40%),radial-gradient(circle,rgba(255,255,255,.035) 1px,transparent 1px); background-size:100% 100%,100% 100%,24px 24px; }
  .canvas-strips { position:absolute; top:0; left:0; right:0; height:3px; display:flex; pointer-events:none; z-index:2; }
  .canvas-strips span { flex:1; height:100%; }
  .q-img-area { position:relative; z-index:1; width:96px; height:64px; background:rgba(255,255,255,.07); border:1.5px dashed rgba(255,255,255,.18); border-radius:10px; cursor:pointer; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; margin-bottom:10px; transition:all .2s; overflow:hidden; }
  .q-img-area:hover { background:rgba(255,255,255,.13); border-color:rgba(255,255,255,.38); }
  .q-img-area i { font-size:1.1rem; color:rgba(255,255,255,.3); }
  .q-img-area span { font-size:.55rem; color:rgba(255,255,255,.25); font-family:'Syne',sans-serif; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
  .q-img-area img { width:100%; height:100%; object-fit:cover; position:absolute; inset:0; border-radius:9px; }
  #qImgInput { display:none; }
  .q-text-area { position:relative; z-index:1; width:100%; max-width:600px; background:rgba(255,255,255,.96); border-radius:10px; padding:1rem 1.25rem; text-align:center; box-shadow:0 8px 32px rgba(0,0,0,.22),0 2px 8px rgba(0,0,0,.1); margin-top:10px; }
  #qTextInput { width:100%; background:transparent; border:none; font-family:'Syne',sans-serif; font-weight:700; font-size:clamp(.9rem,2.2vw,1.1rem); color:var(--ink); outline:none; resize:none; text-align:center; line-height:1.55; min-height:52px; }
  #qTextInput::placeholder { color:#bfbdb8; }
  .q-settings-bar { background:white; border-radius:var(--r); border:1.5px solid var(--border); padding:.75rem 1rem; display:flex; align-items:center; gap:.85rem; flex-wrap:wrap; margin-bottom:1rem; box-shadow:var(--sh); }
  .qs-group { display:flex; align-items:center; gap:6px; }
  .qs-label { font-family:'Syne',sans-serif; font-weight:700; font-size:.65rem; color:var(--muted); white-space:nowrap; }
  .qs-select { background:var(--surface); border:1.5px solid var(--border); border-radius:7px; padding:5px 26px 5px 8px; font-family:'DM Sans',sans-serif; font-weight:600; font-size:.75rem; color:var(--ink); outline:none; cursor:pointer; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238f8d98' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 6px center; background-size:12px; transition:border-color .2s,box-shadow .2s; }
  .qs-select:focus { border-color:var(--accent2); box-shadow:0 0 0 3px rgba(109,40,217,.08); }
  .qs-divider { width:1px; height:22px; background:var(--border); flex-shrink:0; }
  .answers-grid { display:grid; grid-template-columns:1fr 1fr; gap:.7rem; margin-bottom:1rem; }
  .ans-block { border-radius:var(--r); overflow:hidden; box-shadow:var(--sh-md); transition:transform .15s,box-shadow .15s; position:relative; }
  .ans-block:hover { transform:translateY(-2px); box-shadow:var(--sh-lg); }
  .ans-block[data-idx="0"] { background:var(--ans-0); }
  .ans-block[data-idx="1"] { background:var(--ans-1); }
  .ans-block[data-idx="2"] { background:var(--ans-2); }
  .ans-block[data-idx="3"] { background:var(--ans-3); }
  .ans-block.is-correct { box-shadow:0 0 0 3px white,0 6px 28px rgba(0,0,0,.2); transform:translateY(-1px); }
  .ans-block::before { content:''; position:absolute; inset:0; background:linear-gradient(135deg,rgba(255,255,255,.08),transparent); pointer-events:none; }
  .ans-inner { padding:12px 13px; display:flex; align-items:center; gap:9px; }
  .ans-badge { width:28px; height:28px; border-radius:7px; background:rgba(0,0,0,.2); font-family:'Syne',sans-serif; font-weight:900; font-size:.72rem; color:rgba(255,255,255,.8); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .ans-input { flex:1; background:rgba(0,0,0,.14); border:1px solid rgba(255,255,255,.18); border-radius:7px; padding:7px 10px; color:white; font-family:'DM Sans',sans-serif; font-weight:500; font-size:.83rem; outline:none; transition:all .18s; }
  .ans-input::placeholder { color:rgba(255,255,255,.35); }
  .ans-input:focus { background:rgba(0,0,0,.22); border-color:rgba(255,255,255,.5); }
  .ans-correct-footer { display:flex; align-items:center; justify-content:center; gap:7px; padding:8px 13px; cursor:pointer; background:rgba(0,0,0,.16); border-top:1px solid rgba(255,255,255,.1); transition:background .18s; user-select:none; }
  .ans-correct-footer:hover { background:rgba(0,0,0,.26); }
  .ans-correct-footer .cf-icon { width:19px; height:19px; border-radius:50%; background:rgba(255,255,255,.12); border:1.5px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; font-size:.6rem; color:rgba(255,255,255,.5); transition:all .18s; flex-shrink:0; }
  .ans-correct-footer .cf-label { font-family:'Syne',sans-serif; font-weight:700; font-size:.65rem; color:rgba(255,255,255,.55); letter-spacing:.02em; transition:color .18s; }
  .ans-block.is-correct .ans-correct-footer { background:rgba(255,255,255,.2); border-top-color:rgba(255,255,255,.28); }
  .ans-block.is-correct .ans-correct-footer .cf-icon { background:white; border-color:white; color:var(--emerald); box-shadow:0 0 0 3px rgba(255,255,255,.22); }
  .ans-block.is-correct .ans-correct-footer .cf-label { color:white; font-weight:900; }
  .empty-state { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:1rem; text-align:center; padding:3rem 2rem; animation:fadeUp .5s ease both; }
  .empty-visual { width:88px; height:88px; border-radius:22px; background:white; border:2px dashed var(--border2); box-shadow:var(--sh); display:flex; align-items:center; justify-content:center; font-size:2rem; position:relative; overflow:hidden; }
  .empty-visual::after { content:''; position:absolute; inset:0; background:linear-gradient(135deg,rgba(109,40,217,.04),rgba(255,63,97,.04)); }
  .empty-title { font-family:'Syne',sans-serif; font-weight:900; font-size:1.15rem; color:var(--ink); }
  .empty-sub { font-size:.8rem; color:var(--muted); line-height:1.7; max-width:280px; }
  .empty-actions { display:flex; gap:.65rem; align-items:center; flex-wrap:wrap; justify-content:center; }
  .empty-cta { display:inline-flex; align-items:center; gap:8px; padding:10px 22px; border-radius:var(--r); background:var(--ink); color:white; font-family:'Syne',sans-serif; font-weight:800; font-size:.8rem; border:none; cursor:pointer; box-shadow:var(--sh-lg); transition:transform .18s,box-shadow .18s; }
  .empty-cta:hover { transform:translateY(-2px); box-shadow:0 18px 52px rgba(10,12,20,.22); }
  .empty-cta-ai { display:inline-flex; align-items:center; gap:8px; padding:10px 22px; border-radius:var(--r); background:linear-gradient(135deg,var(--accent2),#4c1d95); color:white; font-family:'Syne',sans-serif; font-weight:800; font-size:.8rem; border:none; cursor:pointer; box-shadow:0 8px 28px rgba(109,40,217,.3); transition:transform .18s,box-shadow .18s; }
  .empty-cta-ai:hover { transform:translateY(-2px); box-shadow:0 12px 36px rgba(109,40,217,.45); }

  /* ── RIGHT SIDEBAR ── */
  .sidebar-right { background:var(--card); border-left:1.5px solid var(--border); display:flex; flex-direction:column; overflow-y:auto; }
  .sidebar-right::-webkit-scrollbar { width:3px; }
  .sidebar-right::-webkit-scrollbar-thumb { background:var(--border); border-radius:3px; }
  .sr-section { padding:.9rem 1rem; border-bottom:1px solid var(--border); }
  .sr-sec-title { font-family:'Syne',sans-serif; font-weight:700; font-size:.63rem; text-transform:uppercase; letter-spacing:.12em; color:var(--muted); margin-bottom:.8rem; display:flex; align-items:center; gap:7px; }
  .sr-sec-title i { font-size:.7rem; }
  .sr-field { margin-bottom:.75rem; }
  .sr-label { font-family:'Syne',sans-serif; font-weight:700; font-size:.65rem; color:#374151; margin-bottom:4px; display:block; }
  .sr-input,.sr-select,.sr-textarea { width:100%; background:var(--surface); border:1.5px solid var(--border); border-radius:8px; padding:7px 10px; font-family:'DM Sans',sans-serif; font-size:.8rem; color:var(--ink); outline:none; transition:border-color .2s,box-shadow .2s; appearance:none; }
  .sr-input:focus,.sr-select:focus,.sr-textarea:focus { border-color:var(--accent2); box-shadow:0 0 0 3px rgba(109,40,217,.07); }
  .sr-select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238f8d98' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; background-size:12px; padding-right:28px; cursor:pointer; }
  .sr-textarea { resize:vertical; min-height:62px; line-height:1.55; }
  .cover-drop { border:1.5px dashed var(--border2); border-radius:9px; padding:.8rem; text-align:center; cursor:pointer; transition:all .2s; background:var(--surface2); position:relative; overflow:hidden; }
  .cover-drop:hover { border-color:var(--accent2); background:#f0ecff; }
  .cover-drop input { position:absolute; inset:0; opacity:0; cursor:pointer; }
  .cover-drop-icon { font-size:1rem; color:var(--muted); }
  .cover-drop-text { font-family:'Syne',sans-serif; font-weight:700; font-size:.67rem; color:var(--muted); margin-top:4px; }
  .cover-preview { width:100%; height:62px; object-fit:cover; border-radius:7px; display:none; }
  .stats-grid { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
  .stat-box { background:var(--surface); border:1.5px solid var(--border); border-radius:9px; padding:8px; text-align:center; }
  .stat-val { font-family:'Syne',sans-serif; font-weight:900; font-size:1.2rem; color:var(--ink); line-height:1; }
  .stat-lbl { font-size:.6rem; color:var(--muted); margin-top:3px; font-weight:500; }
  .publish-btn { margin:.9rem 1rem; width:calc(100% - 2rem); height:42px; background:var(--accent); border:none; border-radius:var(--r); color:white; font-family:'Syne',sans-serif; font-weight:900; font-size:.82rem; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 18px rgba(255,63,97,.3); transition:transform .18s,box-shadow .18s,background .18s; position:relative; overflow:hidden; }
  .publish-btn::before { content:''; position:absolute; inset:0; background:linear-gradient(135deg,rgba(255,255,255,.14),transparent); }
  .publish-btn:hover { background:var(--accent-h); transform:translateY(-2px); box-shadow:0 8px 28px rgba(255,63,97,.48); }
  .publish-btn:disabled { opacity:.55; cursor:not-allowed; transform:none; box-shadow:none; }

  /* ── NO PAGE GATE ── */
  .no-page-gate { position:fixed; inset:0; z-index:999; background:var(--ink); display:flex; align-items:center; justify-content:center; padding:1.5rem; }
  .npg-card { background:var(--card); border-radius:var(--r-xl); max-width:440px; width:100%; overflow:hidden; box-shadow:var(--sh-xl); animation:popIn .5s cubic-bezier(.16,1,.3,1) both; }
  @keyframes popIn { from { opacity:0;transform:scale(.88); } to { opacity:1;transform:scale(1); } }
  .npg-head { background:var(--ink2); padding:2rem; text-align:center; position:relative; overflow:hidden; }
  .npg-head::after { content:''; position:absolute; bottom:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--accent),var(--accent2),var(--teal)); }
  .npg-ico { width:62px; height:62px; border-radius:17px; background:rgba(245,158,11,.12); border:2px solid rgba(245,158,11,.25); display:flex; align-items:center; justify-content:center; font-size:1.55rem; color:var(--gold); margin:0 auto 1rem; box-shadow:0 0 28px rgba(245,158,11,.15); }
  .npg-head-title { font-family:'Syne',sans-serif; font-weight:900; font-size:1.15rem; color:white; }
  .npg-head-sub { font-size:.78rem; color:rgba(255,255,255,.4); margin-top:.4rem; }
  .npg-body { padding:1.5rem; }
  .npg-cta { display:flex; align-items:center; gap:9px; width:100%; padding:11px 20px; border-radius:var(--r); background:var(--ink); color:white; text-decoration:none; font-family:'Syne',sans-serif; font-weight:800; font-size:.82rem; justify-content:center; margin-top:1rem; box-shadow:var(--sh-md); transition:transform .18s,box-shadow .18s; }
  .npg-cta:hover { transform:translateY(-2px); box-shadow:var(--sh-lg); }
  .npg-note { text-align:center; font-size:.68rem; color:var(--muted); margin-top:.7rem; }

  /* ── PIN MODAL ── */
  .pin-modal { position:fixed; inset:0; z-index:400; background:rgba(10,12,20,.88); backdrop-filter:blur(10px); display:flex; align-items:center; justify-content:center; padding:1.5rem; opacity:0; pointer-events:none; transition:opacity .3s; }
  .pin-modal.open { opacity:1; pointer-events:all; }
  .pin-modal-inner { background:var(--ink2); border:1px solid rgba(255,255,255,.08); border-radius:var(--r-xl); width:100%; max-width:420px; overflow:hidden; box-shadow:var(--sh-xl); transform:scale(.85) translateY(20px); transition:transform .4s cubic-bezier(.16,1,.3,1); position:relative; }
  .pin-modal.open .pin-modal-inner { transform:scale(1) translateY(0); }
  .pin-modal-inner::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg,var(--accent),var(--accent2),var(--teal),var(--gold)); }
  .pin-modal-header { padding:1.75rem 2rem 1.5rem; text-align:center; border-bottom:1px solid rgba(255,255,255,.07); }
  .pin-modal-icon { width:58px; height:58px; border-radius:15px; background:rgba(255,63,97,.13); border:1.5px solid rgba(255,63,97,.22); display:flex; align-items:center; justify-content:center; font-size:1.4rem; color:var(--accent); margin:0 auto 1rem; box-shadow:0 0 28px rgba(255,63,97,.15); }
  .pin-modal-title { font-family:'Syne',sans-serif; font-weight:900; font-size:1.25rem; color:white; margin-bottom:.3rem; }
  .pin-modal-sub { font-size:.78rem; color:rgba(255,255,255,.38); }
  .pin-display { padding:1.4rem 2rem; text-align:center; border-bottom:1px solid rgba(255,255,255,.07); }
  .pin-label { font-family:'Syne',sans-serif; font-weight:700; font-size:.6rem; text-transform:uppercase; letter-spacing:.16em; color:rgba(255,255,255,.28); margin-bottom:.7rem; display:flex; align-items:center; justify-content:center; gap:6px; }
  .pin-digits { font-family:'Syne',sans-serif; font-weight:900; font-size:clamp(2.5rem,8vw,3.6rem); letter-spacing:.22em; color:var(--gold); line-height:1; margin-bottom:.9rem; text-shadow:0 0 40px rgba(245,158,11,.25); }
  .pin-copy-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 18px; border-radius:100px; background:rgba(245,158,11,.08); border:1.5px solid rgba(245,158,11,.22); color:var(--gold); font-family:'Syne',sans-serif; font-weight:700; font-size:.75rem; cursor:pointer; transition:all .18s; }
  .pin-copy-btn:hover { background:rgba(245,158,11,.18); border-color:rgba(245,158,11,.45); }
  .pin-copy-btn.copied { background:rgba(13,148,136,.12); border-color:rgba(13,148,136,.35); color:var(--teal); }
  .pin-share-row { padding:1rem 2rem; display:flex; flex-direction:column; gap:.55rem; border-bottom:1px solid rgba(255,255,255,.07); }
  .pin-share-item { display:flex; align-items:center; gap:10px; font-size:.76rem; color:rgba(255,255,255,.45); }
  .pin-share-item i { color:var(--gold); width:13px; font-size:.78rem; flex-shrink:0; }
  .pin-share-item strong { color:white; font-family:'Syne',sans-serif; font-weight:700; }
  .pin-actions { padding:1rem 2rem; display:flex; gap:.65rem; }
  .pin-action-btn { flex:1; padding:10px; border-radius:9px; font-family:'Syne',sans-serif; font-weight:800; font-size:.77rem; cursor:pointer; border:none; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:7px; transition:transform .15s; }
  .pin-action-btn:hover { transform:translateY(-2px); }
  .pin-action-primary { background:var(--accent); color:white; box-shadow:0 4px 14px rgba(255,63,97,.3); }
  .pin-action-ghost { background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.11); color:rgba(255,255,255,.8); }

  /* ── PREVIEW MODAL ── */
  .preview-modal { position:fixed; inset:0; z-index:300; background:rgba(10,12,20,.85); backdrop-filter:blur(8px); display:flex; align-items:center; justify-content:center; padding:1.5rem; opacity:0; pointer-events:none; transition:opacity .25s; }
  .preview-modal.open { opacity:1; pointer-events:all; }
  .preview-inner { background:var(--ink2); border-radius:var(--r-lg); width:100%; max-width:580px; overflow:hidden; box-shadow:var(--sh-xl); transform:scale(.9); transition:transform .3s cubic-bezier(.16,1,.3,1); }
  .preview-modal.open .preview-inner { transform:scale(1); }
  .preview-top { padding:.9rem 1.2rem; border-bottom:1px solid rgba(255,255,255,.07); display:flex; align-items:center; justify-content:space-between; }
  .preview-q-num { font-family:'Syne',sans-serif; font-weight:700; font-size:.75rem; color:rgba(255,255,255,.4); }
  .preview-timer { width:34px; height:34px; border-radius:50%; background:rgba(245,158,11,.12); border:2px solid rgba(245,158,11,.3); display:flex; align-items:center; justify-content:center; font-family:'Syne',sans-serif; font-weight:900; font-size:.9rem; color:var(--gold); }
  .preview-close { width:28px; height:28px; border-radius:7px; background:rgba(255,255,255,.07); border:none; color:rgba(255,255,255,.5); font-size:.73rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .15s; }
  .preview-close:hover { background:rgba(255,255,255,.14); color:white; }
  .preview-body { padding:1.2rem; }
  .preview-q-text { background:rgba(255,255,255,.95); border-radius:10px; padding:.9rem; text-align:center; font-family:'Syne',sans-serif; font-weight:700; font-size:.92rem; color:var(--ink); margin-bottom:1rem; box-shadow:0 4px 18px rgba(0,0,0,.18); min-height:54px; display:flex; align-items:center; justify-content:center; }
  .preview-answers { display:grid; grid-template-columns:1fr 1fr; gap:.6rem; }
  .preview-ans { border-radius:9px; padding:12px; display:flex; align-items:center; gap:8px; cursor:pointer; transition:transform .15s; color:white; position:relative; overflow:hidden; }
  .preview-ans::before { content:''; position:absolute; inset:0; background:linear-gradient(135deg,rgba(255,255,255,.1),transparent); pointer-events:none; }
  .preview-ans:hover { transform:scale(1.02); }
  .preview-ans[data-idx="0"] { background:var(--ans-0); }
  .preview-ans[data-idx="1"] { background:var(--ans-1); }
  .preview-ans[data-idx="2"] { background:var(--ans-2); }
  .preview-ans[data-idx="3"] { background:var(--ans-3); }
  .preview-ans-badge { width:22px; height:22px; border-radius:6px; background:rgba(0,0,0,.2); font-family:'Syne',sans-serif; font-weight:900; font-size:.65rem; color:rgba(255,255,255,.8); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .preview-ans-text { font-family:'Syne',sans-serif; font-weight:600; font-size:.8rem; }
  .preview-nav { display:flex; align-items:center; justify-content:space-between; padding:.85rem 1.2rem; border-top:1px solid rgba(255,255,255,.07); }
  .prev-nav-btn { padding:6px 14px; border-radius:8px; border:none; font-family:'Syne',sans-serif; font-weight:700; font-size:.73rem; cursor:pointer; display:flex; align-items:center; gap:6px; transition:all .15s; }
  .prev-nav-btn:hover { transform:translateY(-1px); }
  .prev-nav-btn.ghost { background:rgba(255,255,255,.07); color:rgba(255,255,255,.65); }
  .prev-nav-btn.primary { background:var(--gold); color:var(--ink); font-weight:800; }

  /* ── AI GENERATOR MODAL ── */
  .grq-overlay { position:fixed; inset:0; z-index:9999; background:rgba(10,12,20,.92); backdrop-filter:blur(12px); display:flex; align-items:center; justify-content:center; padding:1rem; opacity:0; pointer-events:none; transition:opacity .3s ease; }
  .grq-overlay.open { opacity:1; pointer-events:all; }
  .grq-modal { background:var(--ink2); border:1px solid rgba(255,255,255,.08); border-radius:var(--r-xl); width:100%; max-width:640px; max-height:92vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 40px 100px rgba(0,0,0,.6); transform:scale(.9) translateY(24px); transition:transform .45s cubic-bezier(.16,1,.3,1); position:relative; }
  .grq-overlay.open .grq-modal { transform:scale(1) translateY(0); }
  .grq-modal::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg,var(--accent),var(--accent2),var(--teal),var(--gold)); z-index:10; }
  .grq-header { padding:1.5rem 1.75rem 1.2rem; border-bottom:1px solid rgba(255,255,255,.07); display:flex; align-items:flex-start; gap:1rem; flex-shrink:0; }
  .grq-header-icon { width:50px; height:50px; border-radius:13px; flex-shrink:0; background:linear-gradient(135deg,rgba(109,40,217,.25),rgba(255,63,97,.15)); border:1.5px solid rgba(109,40,217,.3); display:flex; align-items:center; justify-content:center; font-size:1.35rem; box-shadow:0 0 28px rgba(109,40,217,.2); }
  .grq-header-title { font-family:'Syne',sans-serif; font-weight:900; font-size:1.2rem; color:white; letter-spacing:-.02em; }
  .grq-header-sub { font-size:.76rem; color:rgba(255,255,255,.38); margin-top:.3rem; }
  .grq-close { width:30px; height:30px; border-radius:8px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1); color:rgba(255,255,255,.45); font-size:.77rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .15s; flex-shrink:0; margin-top:2px; }
  .grq-close:hover { background:rgba(255,63,97,.18); border-color:rgba(255,63,97,.35); color:#ff8fa3; }
  .grq-steps { display:flex; padding:0 1.75rem; border-bottom:1px solid rgba(255,255,255,.06); flex-shrink:0; }
  .grq-step { flex:1; padding:.8rem .4rem; text-align:center; display:flex; align-items:center; justify-content:center; gap:6px; font-family:'Syne',sans-serif; font-weight:700; font-size:.65rem; color:rgba(255,255,255,.22); letter-spacing:.06em; text-transform:uppercase; border-bottom:2px solid transparent; cursor:default; transition:all .25s; }
  .grq-step.active { color:white; border-bottom-color:var(--accent2); }
  .grq-step.done { color:var(--teal); border-bottom-color:var(--teal); }
  .grq-step-num { width:18px; height:18px; border-radius:50%; background:rgba(255,255,255,.07); display:flex; align-items:center; justify-content:center; font-size:.62rem; font-weight:900; transition:all .25s; }
  .grq-step.active .grq-step-num { background:var(--accent2); color:white; }
  .grq-step.done .grq-step-num { background:var(--teal); color:white; }
  .grq-body { flex:1; overflow-y:auto; padding:1.4rem 1.75rem; }
  .grq-body::-webkit-scrollbar { width:3px; }
  .grq-body::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:3px; }
  .grq-footer { padding:.9rem 1.75rem; border-top:1px solid rgba(255,255,255,.07); display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-shrink:0; }
  .grq-panel { display:none; }
  .grq-panel.active { display:block; animation:panelIn .28s ease both; }
  @keyframes panelIn { from { opacity:0;transform:translateX(12px); } to { opacity:1;transform:translateX(0); } }
  .grq-field { margin-bottom:1.05rem; }
  .grq-label { display:flex; align-items:center; gap:7px; font-family:'Syne',sans-serif; font-weight:700; font-size:.67rem; color:rgba(255,255,255,.45); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.5rem; }
  .grq-label i { color:var(--gold); font-size:.72rem; }
  .grq-input,.grq-select,.grq-textarea { width:100%; background:rgba(255,255,255,.05); border:1.5px solid rgba(255,255,255,.09); border-radius:9px; padding:9px 13px; color:white; font-family:'DM Sans',sans-serif; font-size:.83rem; outline:none; transition:border-color .2s,background .2s,box-shadow .2s; appearance:none; }
  .grq-input::placeholder,.grq-textarea::placeholder { color:rgba(255,255,255,.18); }
  .grq-input:focus,.grq-select:focus,.grq-textarea:focus { border-color:var(--accent2); background:rgba(109,40,217,.07); box-shadow:0 0 0 3px rgba(109,40,217,.12); }
  .grq-select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,0.3)' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; background-size:13px; padding-right:32px; cursor:pointer; }
  .grq-select option { background:var(--ink2); color:white; }
  .grq-textarea { resize:vertical; min-height:66px; line-height:1.55; }
  .grq-hint { font-size:.68rem; color:rgba(255,255,255,.22); margin-top:4px; line-height:1.55; }
  .quiz-type-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.6rem; }
  .quiz-type-card { background:rgba(255,255,255,.04); border:1.5px solid rgba(255,255,255,.08); border-radius:11px; padding:12px 13px; cursor:pointer; transition:all .2s; display:flex; align-items:flex-start; gap:10px; }
  .quiz-type-card:hover { background:rgba(255,255,255,.08); border-color:rgba(255,255,255,.16); }
  .quiz-type-card.selected { border-color:var(--accent2); background:rgba(109,40,217,.12); box-shadow:0 0 0 3px rgba(109,40,217,.14); }
  .qtc-emoji { font-size:1.4rem; width:36px; height:36px; flex-shrink:0; background:rgba(255,255,255,.06); border-radius:8px; display:flex; align-items:center; justify-content:center; }
  .qtc-name { font-family:'Syne',sans-serif; font-weight:800; font-size:.77rem; color:white; margin-bottom:2px; }
  .qtc-desc { font-size:.66rem; color:rgba(255,255,255,.32); line-height:1.45; }
  .qtc-check { width:17px; height:17px; border-radius:50%; flex-shrink:0; background:rgba(255,255,255,.09); border:1.5px solid rgba(255,255,255,.18); display:flex; align-items:center; justify-content:center; font-size:.58rem; color:transparent; transition:all .2s; margin-top:2px; }
  .quiz-type-card.selected .qtc-check { background:var(--accent2); border-color:var(--accent2); color:white; box-shadow:0 0 0 3px rgba(109,40,217,.25); }
  .grq-row { display:grid; grid-template-columns:1fr 1fr; gap:.7rem; }
  .diff-pills { display:flex; gap:5px; }
  .diff-pill { flex:1; padding:7px 5px; border-radius:8px; text-align:center; font-family:'Syne',sans-serif; font-weight:800; font-size:.67rem; cursor:pointer; border:1.5px solid rgba(255,255,255,.09); background:rgba(255,255,255,.04); color:rgba(255,255,255,.38); transition:all .18s; }
  .diff-pill:hover { background:rgba(255,255,255,.09); }
  .diff-pill[data-diff="easy"].selected { background:rgba(13,148,136,.14); border-color:var(--teal); color:var(--teal); }
  .diff-pill[data-diff="medium"].selected { background:rgba(245,158,11,.13); border-color:var(--gold); color:var(--gold); }
  .diff-pill[data-diff="hard"].selected { background:rgba(255,63,97,.13); border-color:var(--accent); color:var(--accent); }
  .count-stepper { display:flex; align-items:center; background:rgba(255,255,255,.05); border:1.5px solid rgba(255,255,255,.09); border-radius:9px; overflow:hidden; }
  .count-btn { width:36px; height:40px; background:rgba(255,255,255,.04); border:none; color:rgba(255,255,255,.45); font-size:1rem; cursor:pointer; transition:all .15s; flex-shrink:0; }
  .count-btn:hover { background:rgba(255,255,255,.1); color:white; }
  .count-val { flex:1; text-align:center; height:40px; font-family:'Syne',sans-serif; font-weight:900; font-size:.97rem; color:white; background:transparent; border:none; outline:none; line-height:40px; }
  .topic-tags { display:flex; flex-wrap:wrap; gap:5px; margin-top:.45rem; }
  .topic-tag { padding:4px 11px; border-radius:100px; cursor:pointer; font-family:'Syne',sans-serif; font-weight:700; font-size:.65rem; border:1.5px solid rgba(255,255,255,.09); background:rgba(255,255,255,.04); color:rgba(255,255,255,.38); transition:all .18s; user-select:none; }
  .topic-tag:hover { background:rgba(255,255,255,.1); color:rgba(255,255,255,.75); }
  .topic-tag.selected { background:rgba(245,158,11,.13); border-color:rgba(245,158,11,.35); color:var(--gold); }
  .grq-toggle-row { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:.75rem; }
  .grq-toggle-label { font-family:'Syne',sans-serif; font-weight:700; font-size:.76rem; color:rgba(255,255,255,.55); }
  .grq-toggle { width:40px; height:22px; border-radius:100px; background:rgba(255,255,255,.09); border:none; cursor:pointer; position:relative; transition:background .25s; flex-shrink:0; }
  .grq-toggle::after { content:''; position:absolute; top:3px; left:3px; width:16px; height:16px; border-radius:50%; background:white; transition:transform .25s cubic-bezier(.16,1,.3,1); box-shadow:0 1px 4px rgba(0,0,0,.3); }
  .grq-toggle.on { background:var(--accent2); }
  .grq-toggle.on::after { transform:translateX(18px); }
  .grq-divider { display:flex; align-items:center; gap:9px; margin:1rem 0 .9rem; }
  .grq-divider-line { flex:1; height:1px; background:rgba(255,255,255,.07); }
  .grq-divider-text { font-family:'Syne',sans-serif; font-weight:700; font-size:.6rem; text-transform:uppercase; letter-spacing:.12em; color:rgba(255,255,255,.22); }
  .grq-generating { display:none; flex-direction:column; align-items:center; justify-content:center; padding:2.5rem 1.5rem; gap:1.2rem; text-align:center; }
  .grq-generating.active { display:flex; }
  .gen-orb { width:76px; height:76px; position:relative; }
  .gen-orb-ring { position:absolute; inset:0; border-radius:50%; border:2px solid transparent; border-top-color:var(--accent2); border-right-color:var(--accent); animation:orbSpin 1.2s linear infinite; }
  .gen-orb-ring:nth-child(2) { inset:10px; border-top-color:var(--gold); border-right-color:var(--teal); animation-duration:.8s; animation-direction:reverse; }
  .gen-orb-core { position:absolute; inset:22px; border-radius:50%; background:linear-gradient(135deg,var(--accent2),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:.95rem; animation:orbPulse 1.5s ease-in-out infinite; }
  @keyframes orbSpin { to { transform:rotate(360deg); } }
  @keyframes orbPulse { 0%,100% { transform:scale(1);opacity:1; } 50% { transform:scale(.85);opacity:.7; } }
  .gen-step-text { font-family:'Syne',sans-serif; font-weight:800; font-size:.9rem; color:white; line-height:1.4; }
  .gen-step-sub { font-size:.74rem; color:rgba(255,255,255,.3); }
  .gen-dots span { display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--accent2); margin:0 3px; animation:dotBounce .8s ease-in-out infinite; }
  .gen-dots span:nth-child(2) { animation-delay:.15s; }
  .gen-dots span:nth-child(3) { animation-delay:.3s; }
  @keyframes dotBounce { 0%,80%,100% { transform:translateY(0); } 40% { transform:translateY(-7px); } }
  .gen-progress-wrap { width:100%; max-width:300px; background:rgba(255,255,255,.07); border-radius:100px; height:3px; }
  .gen-progress-bar { height:100%; border-radius:100px; width:0%; background:linear-gradient(90deg,var(--accent2),var(--accent)); transition:width .6s ease; box-shadow:0 0 10px rgba(109,40,217,.5); }
  .result-summary { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:13px; padding:1rem 1.2rem; display:flex; align-items:center; gap:1rem; margin-bottom:1rem; flex-wrap:wrap; }
  .result-badge { width:50px; height:50px; border-radius:13px; flex-shrink:0; background:linear-gradient(135deg,var(--accent2),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:1.25rem; box-shadow:0 6px 20px rgba(109,40,217,.35); }
  .result-title { font-family:'Syne',sans-serif; font-weight:900; font-size:.95rem; color:white; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .result-meta { display:flex; gap:.9rem; margin-top:4px; flex-wrap:wrap; }
  .result-meta-item { font-size:.68rem; color:rgba(255,255,255,.38); display:flex; align-items:center; gap:4px; }
  .result-meta-item i { color:var(--gold); }
  .q-preview-list { display:flex; flex-direction:column; gap:.5rem; }
  .q-preview-item { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07); border-radius:9px; padding:.75rem .95rem; cursor:pointer; transition:all .18s; display:flex; align-items:flex-start; gap:9px; }
  .q-preview-item:hover { background:rgba(255,255,255,.08); border-color:rgba(255,255,255,.13); }
  .q-prev-num { width:24px; height:24px; border-radius:6px; flex-shrink:0; background:rgba(255,255,255,.07); font-family:'Syne',sans-serif; font-weight:900; font-size:.68rem; color:rgba(255,255,255,.4); display:flex; align-items:center; justify-content:center; }
  .q-prev-text { font-family:'Syne',sans-serif; font-weight:700; font-size:.8rem; color:rgba(255,255,255,.82); line-height:1.4; }
  .q-prev-fact { font-size:.67rem; color:rgba(245,158,11,.65); margin-top:3px; }
  .q-prev-answers { display:none; margin-top:.6rem; grid-template-columns:1fr 1fr; gap:4px; }
  .q-prev-answers.show { display:grid; }
  .q-prev-ans { border-radius:6px; padding:5px 8px; font-size:.7rem; font-family:'DM Sans',sans-serif; font-weight:500; display:flex; align-items:center; gap:6px; color:white; }
  .q-prev-ans[data-i="0"] { background:rgba(255,63,97,.22); }
  .q-prev-ans[data-i="1"] { background:rgba(109,40,217,.22); }
  .q-prev-ans[data-i="2"] { background:rgba(245,158,11,.18); }
  .q-prev-ans[data-i="3"] { background:rgba(13,148,136,.18); }
  .q-prev-ans.correct { outline:1.5px solid rgba(255,255,255,.3); font-weight:700; }
  .q-regen-btn { width:24px; height:24px; border-radius:6px; flex-shrink:0; background:transparent; border:1px solid rgba(255,255,255,.09); color:rgba(255,255,255,.28); font-size:.65rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .18s; }
  .q-regen-btn:hover { background:rgba(109,40,217,.2); border-color:rgba(109,40,217,.4); color:var(--accent2); }
  .grq-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 20px; border-radius:9px; border:none; font-family:'Syne',sans-serif; font-weight:800; font-size:.79rem; cursor:pointer; transition:all .18s; white-space:nowrap; }
  .grq-btn:hover { transform:translateY(-1px); }
  .grq-btn-primary { background:linear-gradient(135deg,var(--accent2),#4c1d95); color:white; box-shadow:0 4px 16px rgba(109,40,217,.38); }
  .grq-btn-primary:hover { box-shadow:0 8px 26px rgba(109,40,217,.55); }
  .grq-btn-gold { background:linear-gradient(135deg,var(--gold),#d97706); color:var(--ink); box-shadow:0 4px 16px rgba(245,158,11,.32); }
  .grq-btn-gold:hover { box-shadow:0 8px 26px rgba(245,158,11,.48); }
  .grq-btn-ghost { background:rgba(255,255,255,.07); color:rgba(255,255,255,.6); border:1px solid rgba(255,255,255,.11); }
  .grq-btn-ghost:hover { background:rgba(255,255,255,.12); color:white; }
  .grq-btn:disabled { opacity:.5; cursor:not-allowed; transform:none !important; box-shadow:none !important; }

  /* ── TOAST ── */
  #toast-container>.toast { border-radius:10px !important; font-family:'DM Sans',sans-serif !important; font-size:.85rem !important; padding:12px 17px 12px 48px !important; box-shadow:0 8px 28px rgba(0,0,0,.14) !important; }
  #toast-container>.toast-success { background-color:#052e16 !important; border-left:3px solid #22c55e !important; }
  #toast-container>.toast-error { background-color:#450a0a !important; border-left:3px solid #ef4444 !important; }
  #toast-container>.toast-info { background-color:var(--ink2) !important; border-left:3px solid var(--gold) !important; }
  #toast-container>.toast-warning { background-color:#431407 !important; border-left:3px solid var(--gold) !important; }

  @keyframes spin { to { transform:rotate(360deg); } }
  @keyframes fadeUp { from { opacity:0;transform:translateY(16px); } to { opacity:1;transform:translateY(0); } }
  .spinner { animation:spin .7s linear infinite; }
  </style>
</head>
<body>

<?php if (!$has_page): ?>
<div class="no-page-gate">
  <div class="npg-card">
    <div class="npg-head">
      <div class="npg-ico"><i class="fa-solid fa-gamepad"></i></div>
      <div class="npg-head-title">Set Up Your Creator Page First</div>
      <div class="npg-head-sub">You need a creator page before building games.</div>
    </div>
    <div class="npg-body">
      <p style="font-size:.8rem;color:var(--muted);line-height:1.75;text-align:center">Your creator page is your public profile where fans discover your content, games, and events.</p>
      <a href="https://keychele.co.ke/msanii/index.php?page=create_page" class="npg-cta">
        <i class="fa-solid fa-store"></i> Set Up My Creator Page
        <i class="fa-solid fa-arrow-right" style="font-size:.72rem;opacity:.7"></i>
      </a>
      <div class="npg-note"><i class="fa-solid fa-circle-info" style="color:var(--gold)"></i> You'll be redirected back after setup</div>
    </div>
  </div>
</div>

<?php else: ?>

<div class="editor-layout">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="topbar-left">
      <div class="brand"><div class="brand-dot"></div>Key<em>chele</em></div>
      <div class="tb-sep"></div>
      <div class="game-title-wrap">
        <input type="text" class="game-title-input" id="gameTitleInput" placeholder="Untitled Game…" maxlength="100" autocomplete="off">
      </div>
    </div>
    <div class="topbar-actions">
      <button class="tb-btn tb-btn-ai" onclick="GRQ.open()">
        <span class="ai-spark">✨</span> AI Generate
      </button>
      <button class="tb-btn tb-btn-ghost" onclick="openPreview()">
        <i class="fa-solid fa-eye"></i> Preview
      </button>
      <button class="tb-btn tb-btn-accent" onclick="publishGame()">
        <i class="fa-solid fa-rocket"></i> Publish
      </button>
    </div>
  </div>

  <!-- LEFT SIDEBAR -->
  <div class="sidebar-left">
    <div class="sl-header">
      <div class="sl-header-left">
        <span class="sl-header-title">Questions</span>
        <span class="sl-count" id="qCount">0</span>
      </div>
      <button class="sl-sort-btn" title="Reorder"><i class="fa-solid fa-arrows-up-down"></i></button>
    </div>
    <div class="question-list" id="questionList"></div>
    <button class="add-q-btn" onclick="addQuestion()">
      <i class="fa-solid fa-plus"></i> Add Question
    </button>
  </div>

  <!-- CENTER EDITOR -->
  <div class="editor-center" id="editorCenter">
    <div class="empty-state" id="emptyState">
      <div class="empty-visual">🎯</div>
      <div>
        <div class="empty-title">No questions yet</div>
        <div class="empty-sub">Add questions manually or let AI build your entire quiz in seconds.</div>
      </div>
      <div class="empty-actions">
        <button class="empty-cta" onclick="addQuestion()"><i class="fa-solid fa-plus"></i> Add Manually</button>
        <button class="empty-cta-ai" onclick="GRQ.open()"><span>✨</span> AI Generate</button>
      </div>
    </div>

    <div id="questionEditor" style="display:none;">
      <div class="editor-header-row">
        <span class="q-type-label">Type</span>
        <div class="q-type-pills">
          <button class="q-type-pill active" data-type="quiz"       onclick="setQType(this,'quiz')">Quiz</button>
          <button class="q-type-pill"        data-type="true_false" onclick="setQType(this,'true_false')">True/False</button>
          <button class="q-type-pill"        data-type="poll"       onclick="setQType(this,'poll')">Poll</button>
        </div>
        <div class="q-index-badge" id="qIndexBadge">Q1</div>
      </div>

      <div class="q-canvas">
        <div class="canvas-strips">
          <span style="background:var(--ans-0)"></span>
          <span style="background:var(--ans-1)"></span>
          <span style="background:var(--ans-2)"></span>
          <span style="background:var(--ans-3)"></span>
        </div>
        <div class="q-img-area" id="qImgArea" onclick="document.getElementById('qImgInput').click()">
          <input type="file" id="qImgInput" accept="image/*" onchange="handleQImage(this)">
          <i class="fa-solid fa-image"></i>
          <span>Image</span>
          <img id="qImgPreview" alt="">
        </div>
        <div class="q-text-area">
          <textarea id="qTextInput" rows="2" placeholder="Type your question here…" oninput="syncQText(this)"></textarea>
        </div>
      </div>

      <div class="q-settings-bar">
        <div class="qs-group">
          <span class="qs-label"><i class="fa-regular fa-clock"></i> Time</span>
          <select class="qs-select" id="qTimeLimit" onchange="syncQSetting('timeLimit',this.value)">
            <option value="5">5s</option><option value="10">10s</option>
            <option value="20" selected>20s</option><option value="30">30s</option>
            <option value="60">60s</option><option value="90">90s</option>
            <option value="120">2m</option><option value="240">4m</option>
          </select>
        </div>
        <div class="qs-divider"></div>
        <div class="qs-group">
          <span class="qs-label"><i class="fa-solid fa-star"></i> Points</span>
          <select class="qs-select" id="qPoints" onchange="syncQSetting('points',this.value)">
            <option value="standard" selected>Standard</option>
            <option value="double">Double</option>
            <option value="none">None</option>
          </select>
        </div>
        <div class="qs-divider"></div>
        <div class="qs-group">
          <span class="qs-label"><i class="fa-solid fa-list"></i> Select</span>
          <select class="qs-select" id="qAnswerMode" onchange="syncQSetting('answerMode',this.value)">
            <option value="single">Single</option><option value="multi">Multi</option>
          </select>
        </div>
        <div style="flex:1"></div>
        <button style="border:none;background:rgba(255,63,97,.08);color:var(--accent);border-radius:7px;padding:5px 11px;cursor:pointer;font-family:'Syne',sans-serif;font-weight:700;font-size:.65rem;display:flex;align-items:center;gap:5px;" onclick="removeQuestion(activeIdx)">
          <i class="fa-solid fa-trash"></i> Delete
        </button>
      </div>

      <div class="answers-grid" id="answersGrid"></div>
    </div>
  </div>

  <!-- RIGHT SIDEBAR -->
  <div class="sidebar-right">
    <div class="sr-section">
      <div class="sr-sec-title"><i class="fa-solid fa-sliders"></i> Game Settings</div>
      <div class="sr-field">
        <label class="sr-label">Category</label>
        <select class="sr-select" id="gameCategory">
          <option>General Knowledge</option><option>Education</option><option>Entertainment</option>
          <option>Sports</option><option>Science</option><option>History</option>
          <option>Geography</option><option>Music</option><option>Technology</option>
          <option>Business</option><option>Other</option>
        </select>
      </div>
      <div class="sr-field">
        <label class="sr-label">Description</label>
        <textarea class="sr-textarea" id="gameDesc" placeholder="Describe your game…"></textarea>
      </div>
      <div class="sr-field">
        <label class="sr-label">Visibility</label>
        <select class="sr-select" id="gameVisibility">
          <option value="public">🌍 Public</option>
          <option value="private">🔒 Private</option>
        </select>
      </div>
      <div class="sr-field">
        <label class="sr-label">Cover Image</label>
        <div class="cover-drop">
          <input type="file" id="coverInput" accept="image/*" onchange="handleCoverChange(this)">
          <img class="cover-preview" id="coverPreview" alt="">
          <div id="coverDropInner">
            <div class="cover-drop-icon"><i class="fa-solid fa-image"></i></div>
            <div class="cover-drop-text">Click to upload cover</div>
          </div>
        </div>
      </div>
    </div>

    <div class="sr-section">
      <div class="sr-sec-title"><i class="fa-solid fa-chart-bar"></i> Overview</div>
      <div class="stats-grid">
        <div class="stat-box"><div class="stat-val" id="statQCount">0</div><div class="stat-lbl">Questions</div></div>
        <div class="stat-box"><div class="stat-val" id="statAvgTime">—</div><div class="stat-lbl">Avg Time</div></div>
        <div class="stat-box"><div class="stat-val" id="statEstTime">0m</div><div class="stat-lbl">Est. Duration</div></div>
        <div class="stat-box"><div class="stat-val" id="statAnswers">0</div><div class="stat-lbl">Total Answers</div></div>
      </div>
    </div>

    <button class="publish-btn" id="publishBtn" onclick="publishGame()">
      <i class="fa-solid fa-rocket"></i> Publish Game
    </button>
  </div>

</div>

<!-- PREVIEW MODAL -->
<div class="preview-modal" id="previewModal">
  <div class="preview-inner">
    <div class="preview-top">
      <span class="preview-q-num" id="prevQNum">Question 1 of 1</span>
      <div class="preview-timer" id="prevTimer">20</div>
      <button class="preview-close" onclick="closePreview()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="preview-body">
      <div class="preview-q-text" id="prevQText">Your question appears here</div>
      <div class="preview-answers" id="prevAnswers"></div>
    </div>
    <div class="preview-nav">
      <button class="prev-nav-btn ghost" onclick="prevPreviewQ()"><i class="fa-solid fa-chevron-left"></i> Prev</button>
      <span id="prevProgress" style="font-size:.68rem;color:rgba(255,255,255,.3);font-family:'Syne',sans-serif;font-weight:700;"></span>
      <button class="prev-nav-btn primary" onclick="nextPreviewQ()">Next <i class="fa-solid fa-chevron-right"></i></button>
    </div>
  </div>
</div>

<!-- PIN MODAL -->
<div class="pin-modal" id="pinModal">
  <div class="pin-modal-inner">
    <div class="pin-modal-header">
      <div class="pin-modal-icon"><i class="fa-solid fa-gamepad"></i></div>
      <div class="pin-modal-title">Your Game is Live! 🎉</div>
      <div class="pin-modal-sub">Share this PIN with your players to join</div>
    </div>
    <div class="pin-display">
      <div class="pin-label"><i class="fa-solid fa-hashtag"></i> GAME PIN</div>
      <div class="pin-digits" id="pinDigits">------</div>
      <button class="pin-copy-btn" onclick="copyPin()" id="pinCopyBtn">
        <i class="fa-regular fa-copy"></i> Copy PIN
      </button>
    </div>
    <div class="pin-share-row">
      <div class="pin-share-item"><i class="fa-solid fa-mobile-screen"></i><span>Players go to <strong>keychele.co.ke/play</strong></span></div>
      <div class="pin-share-item"><i class="fa-solid fa-keyboard"></i><span>Enter the 6-digit PIN above</span></div>
      <div class="pin-share-item"><i class="fa-solid fa-circle-play"></i><span>Start playing instantly!</span></div>
    </div>
    <div class="pin-actions">
      <button class="pin-action-btn pin-action-primary" onclick="closePinAndHost()"><i class="fa-solid fa-play"></i> Host Game</button>
      <a href="manage_games.php" class="pin-action-btn pin-action-ghost"><i class="fa-solid fa-list"></i> My Games</a>
    </div>
  </div>
</div>

<!-- AI GENERATOR MODAL -->
<div class="grq-overlay" id="grqOverlay">
  <div class="grq-modal" id="grqModal">
    <div class="grq-header">
      <div class="grq-header-icon">✨</div>
      <div style="flex:1">
        <div class="grq-header-title">AI Quiz Generator</div>
        <div class="grq-header-sub">Configure preferences — we'll build the whole quiz in seconds</div>
      </div>
      <button class="grq-close" onclick="GRQ.close()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="grq-steps" id="grqSteps">
      <div class="grq-step active" id="stepTab1"><div class="grq-step-num">1</div> Type</div>
      <div class="grq-step"        id="stepTab2"><div class="grq-step-num">2</div> Topic</div>
      <div class="grq-step"        id="stepTab3"><div class="grq-step-num">3</div> Options</div>
      <div class="grq-step"        id="stepTab4"><div class="grq-step-num">4</div> Review</div>
    </div>

    <div class="grq-body" id="grqBody">

      <!-- Panel 1: Type -->
      <div class="grq-panel active" id="panel1">
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-shapes"></i> Quiz Format</div>
          <div class="quiz-type-grid" id="quizTypeGrid">
            <div class="quiz-type-card selected" data-type="trivia"><div class="qtc-emoji">🧠</div><div style="flex:1"><div class="qtc-name">Trivia / Brainstorming</div><div class="qtc-desc">General knowledge across any topic. Perfect for group fun.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="color"><div class="qtc-emoji">🎨</div><div style="flex:1"><div class="qtc-name">Color Recognition</div><div class="qtc-desc">Colors, hex codes, color theory and design concepts.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="math"><div class="qtc-emoji">➗</div><div style="flex:1"><div class="qtc-name">Math Challenge</div><div class="qtc-desc">Arithmetic, algebra, logic puzzles, and number problems.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="vocabulary"><div class="qtc-emoji">📚</div><div style="flex:1"><div class="qtc-name">Vocabulary / Language</div><div class="qtc-desc">Word meanings, synonyms, antonyms, spelling bees.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="geography"><div class="qtc-emoji">🌍</div><div style="flex:1"><div class="qtc-name">Geography & Maps</div><div class="qtc-desc">Capitals, flags, countries, continents, landmarks.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="science"><div class="qtc-emoji">🔬</div><div style="flex:1"><div class="qtc-name">Science & Nature</div><div class="qtc-desc">Biology, physics, chemistry, space, and nature.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="history"><div class="qtc-emoji">🏛️</div><div style="flex:1"><div class="qtc-name">History & Culture</div><div class="qtc-desc">Historical events, civilizations, art, global cultures.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="tech"><div class="qtc-emoji">💻</div><div style="flex:1"><div class="qtc-name">Technology & AI</div><div class="qtc-desc">Programming, gadgets, internet, AI, digital trends.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="pop_culture"><div class="qtc-emoji">🎬</div><div style="flex:1"><div class="qtc-name">Pop Culture</div><div class="qtc-desc">Movies, music, TV shows, celebrities, viral trends.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
            <div class="quiz-type-card" data-type="sports"><div class="qtc-emoji">⚽</div><div style="flex:1"><div class="qtc-name">Sports & Athletics</div><div class="qtc-desc">Rules, records, players, teams, sporting events.</div></div><div class="qtc-check"><i class="fa-solid fa-check"></i></div></div>
          </div>
        </div>
      </div>

      <!-- Panel 2: Topic -->
      <div class="grq-panel" id="panel2">
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-pen"></i> Quiz Title <span style="font-size:.62rem;color:rgba(255,255,255,.2);font-weight:500;text-transform:none;letter-spacing:0;margin-left:4px;">optional</span></div>
          <input type="text" class="grq-input" id="grqTitle" placeholder="e.g. African Geography Blitz, Color Theory 101…" maxlength="100">
        </div>
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-crosshairs"></i> Specific Focus <span style="font-size:.62rem;color:rgba(255,255,255,.2);font-weight:500;text-transform:none;letter-spacing:0;margin-left:4px;">optional</span></div>
          <input type="text" class="grq-input" id="grqFocus" placeholder="e.g. East Africa, RGB color model, Premier League 2023…" maxlength="120">
          <div class="grq-hint">Leave blank to let AI pick interesting sub-topics.</div>
        </div>
        <div class="grq-field">
          <div class="grq-label"><i class="fa-solid fa-users"></i> Target Audience</div>
          <div class="topic-tags" id="audienceTags">
            <div class="topic-tag selected" data-val="general">👥 General</div>
            <div class="topic-tag" data-val="kids">🧒 Kids (6–12)</div>
            <div class="topic-tag" data-val="teens">🧑‍🎓 Teens</div>
            <div class="topic-tag" data-val="adults">🏢 Adults</div>
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
              <option value="10">⚡ 10 sec</option>
              <option value="20" selected>🎯 20 sec</option>
              <option value="30">🧠 30 sec</option>
              <option value="60">💡 60 sec</option>
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
        <div class="grq-toggle-row"><div><div class="grq-toggle-label">Include fun facts / explanations</div><div class="grq-hint" style="margin-top:2px">Short explanation shown after each answer</div></div><button class="grq-toggle on" id="toggleFacts" onclick="this.classList.toggle('on')"></button></div>
        <div class="grq-toggle-row" style="margin-top:.75rem"><div><div class="grq-toggle-label">Shuffle answer order</div></div><button class="grq-toggle on" id="toggleShuffle" onclick="this.classList.toggle('on')"></button></div>
        <div class="grq-toggle-row" style="margin-top:.75rem"><div><div class="grq-toggle-label">Double points on final question</div></div><button class="grq-toggle" id="toggleDouble" onclick="this.classList.toggle('on')"></button></div>
        <div class="grq-field" style="margin-top:.6rem">
          <div class="grq-label"><i class="fa-solid fa-globe"></i> Visibility</div>
          <select class="grq-select" id="grqVisibility">
            <option value="public">🌍 Public</option>
            <option value="private">🔒 Private</option>
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
        <div style="width:100%;max-width:280px">
          <div class="gen-progress-wrap"><div class="gen-progress-bar" id="genProgressBar"></div></div>
        </div>
      </div>

      <!-- Panel 4: Review -->
      <div class="grq-panel" id="panel4">
        <div class="result-summary" id="resultSummary"></div>
        <div class="grq-label" style="margin-bottom:.55rem"><i class="fa-solid fa-list-check"></i> Questions Preview <span class="grq-hint" style="text-transform:none;letter-spacing:0;font-size:.66rem;margin-left:4px;color:rgba(255,255,255,.2)">Click to expand · ↺ to regenerate</span></div>
        <div class="q-preview-list" id="qPreviewList"></div>
      </div>

    </div>

    <div class="grq-footer" id="grqFooter">
      <button class="grq-btn grq-btn-ghost" id="grqBtnBack" onclick="GRQ.back()" style="display:none"><i class="fa-solid fa-chevron-left"></i> Back</button>
      <div style="flex:1"></div>
      <button class="grq-btn grq-btn-ghost" onclick="GRQ.close()">Cancel</button>
      <button class="grq-btn grq-btn-primary" id="grqBtnNext" onclick="GRQ.next()">Next <i class="fa-solid fa-chevron-right"></i></button>
    </div>
  </div>
</div>

<!-- Hidden publish form -->
<form method="POST" enctype="multipart/form-data" id="publishForm" style="display:none">
  <input type="hidden" name="game_title"       id="fGameTitle">
  <input type="hidden" name="game_description" id="fGameDesc">
  <input type="hidden" name="game_category"    id="fGameCategory">
  <input type="hidden" name="visibility"       id="fVisibility">
  <input type="hidden" name="questions_json"   id="fQuestionsJson">
  <input type="file"   name="cover"            id="fCover" style="display:none">
</form>

<script>
// ═══════════════════════════════════════════════════════
// QUIZ EDITOR STATE
// ═══════════════════════════════════════════════════════
let questions  = [];
let activeIdx  = -1;
let previewIdx = 0;
let newGameId  = null;
const LABELS   = ['A','B','C','D'];

// ── PROXY CALL (Gemini via PHP proxy) ──
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

// ── Question management ──────────────────────────────
function defaultQuestion() {
    return {
        text:'', type:'quiz', timeLimit:20,
        points:'standard', answerMode:'single', image:null,
        answers:[
            {text:'',correct:false},{text:'',correct:false},
            {text:'',correct:false},{text:'',correct:false}
        ]
    };
}

function addQuestion(prefilled) {
    const q = prefilled || defaultQuestion();
    questions.push(q);
    renderQuestionList();
    selectQuestion(questions.length - 1);
    updateStats();
    if (!prefilled) toastr.info('New question added','',{timeOut:1500,positionClass:'toast-bottom-right'});
}

function addMultipleQuestions(qs) {
    qs.forEach(q => questions.push(q));
    renderQuestionList();
    selectQuestion(questions.length - 1);
    updateStats();
}

function removeQuestion(idx) {
    if (idx < 0 || idx >= questions.length) return;
    questions.splice(idx, 1);
    if (activeIdx >= questions.length) activeIdx = questions.length - 1;
    renderQuestionList();
    if (questions.length === 0) showEmpty();
    else selectQuestion(activeIdx < 0 ? 0 : activeIdx);
    updateStats();
}

function selectQuestion(idx) {
    activeIdx = idx;
    renderQuestionList();
    renderEditor();
}

function renderQuestionList() {
    const list = document.getElementById('questionList');
    document.getElementById('qCount').textContent = questions.length;
    if (!questions.length) { list.innerHTML = ''; return; }
    list.innerHTML = questions.map((q, i) => `
        <div class="q-thumb ${i === activeIdx ? 'active' : ''}" onclick="selectQuestion(${i})">
            <div class="q-thumb-num">${i + 1}</div>
            <div class="q-thumb-info">
                <div class="q-thumb-text">${escHtml(q.text) || 'Untitled question'}</div>
                <div class="q-thumb-type">${q.type === 'true_false' ? 'T/F' : q.type}</div>
            </div>
            <button class="q-thumb-del" onclick="event.stopPropagation();removeQuestion(${i})" title="Delete">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        </div>`).join('');
}

function showEmpty() {
    document.getElementById('emptyState').style.display    = 'flex';
    document.getElementById('questionEditor').style.display = 'none';
    activeIdx = -1;
}

function renderEditor() {
    if (activeIdx < 0 || activeIdx >= questions.length) { showEmpty(); return; }
    document.getElementById('emptyState').style.display     = 'none';
    document.getElementById('questionEditor').style.display  = 'block';
    const q = questions[activeIdx];
    document.querySelectorAll('.q-type-pill').forEach(p => p.classList.toggle('active', p.dataset.type === q.type));
    document.getElementById('qIndexBadge').textContent = `Q${activeIdx + 1}`;
    document.getElementById('qTextInput').value        = q.text;
    document.getElementById('qTimeLimit').value        = q.timeLimit;
    document.getElementById('qPoints').value           = q.points;
    document.getElementById('qAnswerMode').value       = q.answerMode;
    const img = document.getElementById('qImgPreview');
    if (q.image) { img.src = q.image; img.style.display = 'block'; }
    else { img.style.display = 'none'; img.src = ''; }
    renderAnswers(q);
}

function renderAnswers(q) {
    const grid = document.getElementById('answersGrid');
    if (q.type === 'true_false') {
        if (q.answers.length !== 2) {
            q.answers = [{text:'True',correct:q.answers[0]?.correct||false},{text:'False',correct:q.answers[1]?.correct||false}];
        } else { q.answers[0].text='True'; q.answers[1].text='False'; }
    } else {
        while (q.answers.length < 4) q.answers.push({text:'',correct:false});
        if (q.answers.length > 4) q.answers = q.answers.slice(0,4);
    }
    const count = q.type === 'true_false' ? 2 : 4;
    grid.innerHTML = q.answers.slice(0, count).map((a, i) => `
        <div class="ans-block ${a.correct?'is-correct':''}" data-idx="${i}" id="ansBlock_${i}">
            <div class="ans-inner">
                <div class="ans-badge">${LABELS[i]}</div>
                <input type="text" class="ans-input"
                    value="${escHtml(a.text)}"
                    placeholder="${q.type==='true_false'?a.text:'Answer '+LABELS[i]}"
                    ${q.type==='true_false'?'readonly':''}
                    data-ans="${i}"
                    oninput="syncAnswer(${i},this.value)">
            </div>
            <div class="ans-correct-footer" onclick="toggleCorrect(${i})">
                <span class="cf-icon"><i class="fa-solid fa-check"></i></span>
                <span class="cf-label" id="correctLabel_${i}">${a.correct?'✓ Correct Answer':'Mark as Correct'}</span>
            </div>
        </div>`).join('');
}

function syncQText(el) { if (activeIdx < 0) return; questions[activeIdx].text = el.value; renderQuestionList(); }
function syncQSetting(key, val) { if (activeIdx < 0) return; questions[activeIdx][key] = val; if (key==='timeLimit') updateStats(); }
function syncAnswer(idx, val) { if (activeIdx < 0) return; questions[activeIdx].answers[idx].text = val; updateStats(); }

function toggleCorrect(idx) {
    if (activeIdx < 0) return;
    const q = questions[activeIdx];
    if (q.answerMode === 'single' || q.type === 'true_false') {
        q.answers.forEach((a, i) => { a.correct = (i === idx); });
    } else { q.answers[idx].correct = !q.answers[idx].correct; }
    const count = q.type === 'true_false' ? 2 : 4;
    for (let i = 0; i < count; i++) {
        const block = document.getElementById('ansBlock_' + i);
        const label = document.getElementById('correctLabel_' + i);
        if (!block || !q.answers[i]) continue;
        const isC = q.answers[i].correct;
        block.classList.toggle('is-correct', isC);
        if (label) label.textContent = isC ? '✓ Correct Answer' : 'Mark as Correct';
    }
}

function setQType(el, type) {
    if (activeIdx < 0) return;
    const prev = questions[activeIdx].type;
    questions[activeIdx].type = type;
    document.querySelectorAll('.q-type-pill').forEach(p => p.classList.toggle('active', p.dataset.type === type));
    if (type === 'true_false' && prev !== 'true_false') {
        const prevCorrect = questions[activeIdx].answers.findIndex(a => a.correct);
        questions[activeIdx].answers = [{text:'True',correct:prevCorrect===0},{text:'False',correct:prevCorrect===1}];
    } else if (prev === 'true_false' && type !== 'true_false') {
        while (questions[activeIdx].answers.length < 4) questions[activeIdx].answers.push({text:'',correct:false});
    }
    renderAnswers(questions[activeIdx]);
}

function handleQImage(input) {
    if (!input.files[0] || activeIdx < 0) return;
    const reader = new FileReader();
    reader.onload = e => {
        questions[activeIdx].image = e.target.result;
        const img = document.getElementById('qImgPreview');
        img.src = e.target.result; img.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
}

function updateStats() {
    const n = questions.length;
    document.getElementById('statQCount').textContent = n;
    document.getElementById('qCount').textContent = n;
    if (!n) {
        ['statAvgTime','statEstTime','statAnswers'].forEach(id => {
            document.getElementById(id).textContent = id==='statEstTime'?'0m':'—';
        });
        return;
    }
    const totalSecs = questions.reduce((s,q) => s + parseInt(q.timeLimit), 0);
    const totalAns  = questions.reduce((s,q) => s + q.answers.filter(a => a.text.trim()).length, 0);
    document.getElementById('statAvgTime').textContent = Math.round(totalSecs/n)+'s';
    document.getElementById('statEstTime').textContent = Math.ceil(totalSecs/60)+'m';
    document.getElementById('statAnswers').textContent = totalAns;
}

function handleCoverChange(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('coverPreview').src = e.target.result;
        document.getElementById('coverPreview').style.display = 'block';
        document.getElementById('coverDropInner').style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
}

// ── Preview ──────────────────────────────────────────
function openPreview() {
    if (!questions.length) { toastr.error('Add at least one question first!'); return; }
    previewIdx = activeIdx >= 0 ? activeIdx : 0;
    renderPreview();
    document.getElementById('previewModal').classList.add('open');
}
function closePreview() { document.getElementById('previewModal').classList.remove('open'); }
function renderPreview() {
    const q   = questions[previewIdx];
    const tot = questions.length;
    document.getElementById('prevQNum').textContent  = `Question ${previewIdx+1} of ${tot}`;
    document.getElementById('prevTimer').textContent = q.timeLimit;
    document.getElementById('prevQText').textContent = q.text || 'Untitled question';
    document.getElementById('prevProgress').textContent = `${previewIdx+1} / ${tot}`;
    const answers = q.type==='true_false' ? [{text:'True'},{text:'False'}] : q.answers;
    document.getElementById('prevAnswers').innerHTML = answers.slice(0,4).map((a,i) => `
        <div class="preview-ans" data-idx="${i}">
            <div class="preview-ans-badge">${LABELS[i]}</div>
            <span class="preview-ans-text">${escHtml(a.text)||(i===0?'True':i===1?'False':'(empty)')}</span>
        </div>`).join('');
}
function nextPreviewQ() { if (previewIdx < questions.length-1) { previewIdx++; renderPreview(); } }
function prevPreviewQ() { if (previewIdx > 0) { previewIdx--; renderPreview(); } }

// ── Publish ──────────────────────────────────────────
function publishGame() {
    const title = document.getElementById('gameTitleInput').value.trim();
    if (!title) { toastr.error('Please enter a game title.'); document.getElementById('gameTitleInput').focus(); return; }
    if (!questions.length) { toastr.error('Add at least one question before publishing.'); return; }
    const missingCorrect = questions.filter(q => q.type!=='poll' && !q.answers.some(a => a.correct));
    if (missingCorrect.length) {
        const nums = questions.map((q,i) => missingCorrect.includes(q)?'Q'+(i+1):null).filter(Boolean).join(', ');
        toastr.warning(`${nums} ${missingCorrect.length===1?'has':'have'} no correct answer marked.`,'Heads up!',{timeOut:5000});
    }
    document.getElementById('fGameTitle').value     = title;
    document.getElementById('fGameDesc').value      = document.getElementById('gameDesc').value;
    document.getElementById('fGameCategory').value  = document.getElementById('gameCategory').value;
    document.getElementById('fVisibility').value    = document.getElementById('gameVisibility').value;
    document.getElementById('fQuestionsJson').value = JSON.stringify(questions);
    const btn = document.getElementById('publishBtn');
    btn.disabled = true;
    btn.innerHTML = `<svg class="spinner" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Publishing…`;
    document.getElementById('publishForm').submit();
}

// ── Pin modal ────────────────────────────────────────
function showPinModal(pin, gameId) {
    newGameId = gameId;
    document.getElementById('pinDigits').textContent = pin;
    document.getElementById('pinModal').classList.add('open');
}
function closePinModal() { document.getElementById('pinModal').classList.remove('open'); }
function closePinAndHost() {
    if (newGameId) window.location.href = 'host_game.php?id=' + newGameId;
    else closePinModal();
}
function copyPin() {
    const pin = document.getElementById('pinDigits').textContent;
    navigator.clipboard.writeText(pin).then(() => {
        const btn = document.getElementById('pinCopyBtn');
        btn.classList.add('copied'); btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = '<i class="fa-regular fa-copy"></i> Copy PIN'; }, 2000);
    }).catch(() => toastr.success('PIN: ' + pin, 'Copy manually', {timeOut:5000}));
}

function escHtml(str) {
    return (str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ═══════════════════════════════════════════════════════
// AI QUIZ GENERATOR MODULE
// ═══════════════════════════════════════════════════════
const GRQ = (() => {
    let step = 1;
    let generatedData = null;
    let genProgressInterval = null;
    const TOTAL = 4;

    const GEN_MSGS = [
        ['Consulting the knowledge oracle…',  'Summoning answers from the void'],
        ['Crafting tricky distractors…',       'Making wrong answers convincingly wrong'],
        ['Balancing difficulty curve…',         'Not too easy, not too brutal'],
        ['Adding some spice…',                  'Inserting a fun fact or two'],
        ['Final polish…',                       'Almost ready — looking good!'],
    ];

    function open() {
        step = 1; generatedData = null;
        resetForm();
        document.getElementById('grqOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        document.getElementById('grqOverlay').classList.remove('open');
        document.body.style.overflow = '';
    }

    function goTo(s) {
        step = s;
        document.querySelectorAll('.grq-panel').forEach(p => p.classList.remove('active'));
        document.getElementById('grqGenerating').classList.remove('active');
        if (s <= TOTAL) document.getElementById('panel'+s)?.classList.add('active');

        ['stepTab1','stepTab2','stepTab3','stepTab4'].forEach((id, idx) => {
            const el = document.getElementById(id);
            el.classList.remove('active','done');
            if (idx+1 === s) el.classList.add('active');
            if (idx+1 < s)  el.classList.add('done');
        });

        const btnBack = document.getElementById('grqBtnBack');
        const btnNext = document.getElementById('grqBtnNext');
        btnBack.style.display = (s > 1 && s <= TOTAL) ? 'inline-flex' : 'none';

        if (s === TOTAL) {
            btnNext.innerHTML = '<i class="fa-solid fa-rocket"></i> Import to Editor';
            btnNext.className = 'grq-btn grq-btn-gold';
        } else if (s === 3) {
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
        } else if (step === 2) {
            goTo(3);
        } else if (step === 3) {
            generate();
        } else if (step === TOTAL) {
            importToEditor();
        }
    }

    function back() { if (step > 1) goTo(step - 1); }

    function getType()      { return document.querySelector('.quiz-type-card.selected')?.dataset.type || null; }
    function getAudience()  { return document.querySelector('#audienceTags .topic-tag.selected')?.dataset.val || 'general'; }
    function getDiff()      { return document.querySelector('.diff-pill.selected')?.dataset.diff || 'medium'; }
    function getAnsFormat() { return document.querySelector('#answerFormatTags .topic-tag.selected')?.dataset.val || 'multiple_choice'; }
    function stepCount(dir) { const inp = document.getElementById('grqCount'); inp.value = Math.min(30, Math.max(3, parseInt(inp.value)+dir)); }

    function resetForm() {
        document.querySelectorAll('.quiz-type-card').forEach(c => c.classList.remove('selected'));
        document.querySelector('.quiz-type-card[data-type="trivia"]')?.classList.add('selected');
        document.getElementById('grqTitle').value     = '';
        document.getElementById('grqFocus').value     = '';
        document.getElementById('grqCount').value     = '10';
        document.getElementById('grqTimePerQ').value  = '20';
        document.getElementById('grqLanguage').value  = 'English';
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
        const audience= getAudience();
        const lang    = document.getElementById('grqLanguage').value;
        const focus   = document.getElementById('grqFocus').value.trim();
        const titleH  = document.getElementById('grqTitle').value.trim();
        const fmt     = getAnsFormat();
        const time    = document.getElementById('grqTimePerQ').value;
        const facts   = document.getElementById('toggleFacts').classList.contains('on');
        const dbl     = document.getElementById('toggleDouble').classList.contains('on');

        const fmtInstr = fmt==='multiple_choice' ? 'All questions must be multiple choice with exactly 4 options.'
            : fmt==='true_false' ? 'All questions must be True/False (only 2 options: "True" and "False").'
            : 'Mix multiple choice (4 options) and True/False in roughly equal proportion.';

        return `You are a professional educational quiz creator for Keychele, a live quiz platform used in Kenya and Africa.

Create a ${count}-question ${typeMap[type]||type} quiz in ${lang} for a ${audience} audience at ${diff} difficulty.${focus?` Focus on: "${focus}".`:''}${titleH?` Suggested title: "${titleH}".`:''}

${fmtInstr}
Each question must have ONE correct answer.
Time limit per question: ${time} seconds.
Points: ${dbl?'standard for all except last question = "double"':'standard for all'}.
${facts?'Include a short "fact" field (1–2 sentences) explaining the correct answer.':''}

IMPORTANT: Respond ONLY with valid JSON. No markdown, no code blocks, no backticks, no extra text before or after.
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

    async function generate() {
        document.querySelectorAll('.grq-panel').forEach(p => p.classList.remove('active'));
        document.getElementById('grqGenerating').classList.add('active');
        document.getElementById('grqBtnBack').style.display = 'none';
        document.getElementById('grqBtnNext').style.display = 'none';
        document.getElementById('grqSteps').style.opacity   = '.4';

        let progress = 0;
        const bar    = document.getElementById('genProgressBar');
        const txt    = document.getElementById('genStepText');
        const sub    = document.getElementById('genStepSub');

        genProgressInterval = setInterval(() => {
            progress = Math.min(progress + Math.random()*5 + 1.5, 88);
            bar.style.width = progress + '%';
            const mi = Math.min(Math.floor(progress/20), GEN_MSGS.length-1);
            if (txt.textContent !== GEN_MSGS[mi][0]) {
                txt.textContent = GEN_MSGS[mi][0];
                sub.textContent = GEN_MSGS[mi][1];
            }
        }, 700);

        try {
            const raw    = await callAI(buildPrompt(), 4000);
            // Strip any markdown code fences Gemini might add
            const clean  = raw.replace(/```json\s*/gi,'').replace(/```\s*/g,'').trim();
            const parsed = JSON.parse(clean);

            clearInterval(genProgressInterval);
            bar.style.width = '100%';

            if (!parsed.questions?.length) throw new Error('No questions returned.');

            generatedData = parsed;
            await new Promise(r => setTimeout(r, 400));
            document.getElementById('grqGenerating').classList.remove('active');
            document.getElementById('grqSteps').style.opacity = '1';
            renderReview(parsed);
            goTo(4);

        } catch(err) {
            clearInterval(genProgressInterval);
            bar.style.width = '0%';
            document.getElementById('grqGenerating').classList.remove('active');
            document.getElementById('grqSteps').style.opacity = '1';
            goTo(3);
            toastr.error('Generation failed: ' + err.message, 'Oops!', {timeOut:7000});
        }
    }

    function renderReview(data) {
        const total = data.questions.length;
        const secs  = data.questions.reduce((s,q) => s+parseInt(q.timeLimit||20), 0);
        document.getElementById('resultSummary').innerHTML = `
            <div class="result-badge">✨</div>
            <div style="flex:1;min-width:0">
                <div class="result-title">${escHtml(data.title||'AI Generated Quiz')}</div>
                <div class="result-meta">
                    <span class="result-meta-item"><i class="fa-solid fa-circle-question"></i> ${total} questions</span>
                    <span class="result-meta-item"><i class="fa-solid fa-clock"></i> ~${Math.ceil(secs/60)} min</span>
                    <span class="result-meta-item"><i class="fa-solid fa-tag"></i> ${data.category||'General'}</span>
                    <span class="result-meta-item"><i class="fa-solid fa-signal"></i> ${getDiff()}</span>
                </div>
            </div>`;

        document.getElementById('qPreviewList').innerHTML = data.questions.map((q,i) => `
            <div class="q-preview-item" onclick="GRQ.togglePreviewItem(this)">
                <div class="q-prev-num">${i+1}</div>
                <div style="flex:1;min-width:0">
                    <div class="q-prev-text">${escHtml(q.text)}</div>
                    ${q.fact?`<div class="q-prev-fact"><i class="fa-solid fa-lightbulb"></i> ${escHtml(q.fact)}</div>`:''}
                    <div class="q-prev-answers" id="prevAns_${i}">
                        ${(q.answers||[]).map((a,j)=>`
                            <div class="q-prev-ans ${a.correct?'correct':''}" data-i="${j}">
                                ${escHtml(a.text)}${a.correct?'<span style="margin-left:auto;opacity:.7;font-size:.6rem"><i class="fa-solid fa-check"></i></span>':''}
                            </div>`).join('')}
                    </div>
                </div>
                <button class="q-regen-btn" onclick="event.stopPropagation();GRQ.regenQ(${i})" title="Regenerate">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            </div>`).join('');
    }

    function togglePreviewItem(el) {
        const ans = el.querySelector('.q-prev-answers');
        if (ans) ans.classList.toggle('show');
    }

    async function regenQ(idx) {
        if (!generatedData?.questions?.[idx]) return;
        const btn = document.querySelector(`#qPreviewList .q-preview-item:nth-child(${idx+1}) .q-regen-btn`);
        if (btn) { btn.innerHTML='<i class="fa-solid fa-spinner spinner"></i>'; btn.disabled=true; }
        try {
            const q = generatedData.questions[idx];
            const prompt = `Replace this quiz question with a completely different one on the same topic and difficulty level. Current question: "${q.text}". Return ONLY a raw JSON object with no markdown, no code blocks, no extra text: {"text":"...","type":"quiz","timeLimit":20,"points":"standard","answerMode":"single","image":null,"fact":"...","answers":[{"text":"...","correct":false},{"text":"...","correct":true},{"text":"...","correct":false},{"text":"...","correct":false}]}`;
            const raw  = await callAI(prompt, 600);
            const clean = raw.replace(/```json\s*/gi,'').replace(/```\s*/g,'').trim();
            const newQ = JSON.parse(clean);
            generatedData.questions[idx] = newQ;

            const item = document.querySelector(`#qPreviewList .q-preview-item:nth-child(${idx+1})`);
            if (item) {
                item.querySelector('.q-prev-text').textContent = newQ.text;
                const ansEl = item.querySelector('.q-prev-answers');
                if (ansEl) ansEl.innerHTML = (newQ.answers||[]).map((a,j)=>`
                    <div class="q-prev-ans ${a.correct?'correct':''}" data-i="${j}">
                        ${escHtml(a.text)}${a.correct?'<span style="margin-left:auto;opacity:.7;font-size:.6rem"><i class="fa-solid fa-check"></i></span>':''}
                    </div>`).join('');
            }
            toastr.success('Question regenerated!','',{timeOut:2000});
        } catch(e) { toastr.error('Regen failed: '+e.message); }
        finally { if(btn){btn.innerHTML='<i class="fa-solid fa-arrows-rotate"></i>';btn.disabled=false;} }
    }

    function importToEditor() {
        if (!generatedData?.questions?.length) { toastr.error('No questions to import.'); return; }
        const titleEl = document.getElementById('gameTitleInput');
        if (!titleEl.value.trim()) titleEl.value = generatedData.title || 'AI Quiz';
        const descEl = document.getElementById('gameDesc');
        if (!descEl.value.trim()) descEl.value = generatedData.description || '';
        const catSel = document.getElementById('gameCategory');
        const catMatch = Array.from(catSel.options).find(o => o.value === generatedData.category);
        if (catMatch) catSel.value = generatedData.category;
        const firstNewIdx = questions.length;
        addMultipleQuestions(generatedData.questions);
        updateStats();
        close();
        toastr.success(`${generatedData.questions.length} questions imported!`,'🎉 Quiz ready!',{timeOut:4000});
        selectQuestion(firstNewIdx);
    }

    return { open, close, next, back, stepCount, togglePreviewItem, regenQ };
})();

// ── Event delegation ──────────────────────────────────
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
    closeButton:true, progressBar:true,
    positionClass:'toast-top-right', timeOut:5000,
    showMethod:'slideDown', hideMethod:'fadeOut'
};

<?php if ($success && $game_pin): ?>
showPinModal('<?= $game_pin ?>', <?= $game_id_new ?? 0 ?>);
<?php endif; ?>
<?php if ($error): ?>
toastr.error('<strong><?= addslashes($error) ?></strong>', 'Error', {timeOut:8000});
<?php endif; ?>
</script>

<?php endif; ?>
</body>
</html>