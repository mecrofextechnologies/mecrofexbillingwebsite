<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'includes/connect.php';
ob_end_clean();

// Re-check session after include in case connect.php interfered
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) { header('Location: login.php'); exit; }

$game_id = (int)($_GET['id'] ?? 0);
if (!$game_id) { header('Location: create_games.php'); exit; }

$gs = $pdo->prepare("SELECT * FROM creator_games WHERE id = ? AND creator_id = ? LIMIT 1");
$gs->execute([$game_id, $creator_id]);
$game = $gs->fetch(PDO::FETCH_ASSOC);
if (!$game) { header('Location: create_games.php'); exit; }

$questions = json_decode($game['questions_json'], true) ?: [];
$q_count   = count($questions);

// Set to lobby status if still draft
if ($game['status'] === 'draft') {
    $pdo->prepare("UPDATE creator_games SET status='lobby', current_question=-1 WHERE id=?")->execute([$game_id]);
    $game['status'] = 'lobby';
    $game['current_question'] = -1;}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Host Game — <?= htmlspecialchars($game['title']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Lilita+One&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <style>
  :root {
    --bg:       #0d0f1c;
    --surface:  #161828;
    --card:     #1e2035;
    --border:   rgba(255,255,255,0.09);
    --purple:   #7c3aed;
    --purple-l: #a78bfa;
    --gold:     #ffd166;
    --coral:    #ff5e62;
    --teal:     #00c4b0;
    --green:    #22c55e;
    --red:      #ef4444;
    --ink:      #f0f0f8;
    --muted:    rgba(240,240,248,0.45);
    --ans-a:    #e63950;
    --ans-b:    #1368ce;
    --ans-c:    #d89e00;
    --ans-d:    #26890c;
    --r:        14px;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Nunito', sans-serif;
    background: var(--bg); color: var(--ink);
    min-height: 100vh; overflow-x: hidden;
  }

  /* ── TOP BAR ── */
  .topbar {
    background: var(--surface); border-bottom: 1px solid var(--border);
    padding: 0 1.5rem; height: 58px;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 100;
  }
  .tb-brand { font-family: 'Lilita One', cursive; font-size: 1.2rem; color: white; }
  .tb-brand em { color: var(--gold); font-style: normal; }
  .tb-game-name {
    font-weight: 800; font-size: 0.85rem; color: var(--muted);
    max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .tb-right { display: flex; align-items: center; gap: 10px; }
  .tb-status-badge {
    padding: 5px 13px; border-radius: 100px;
    font-size: 0.7rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.08em;
    display: flex; align-items: center; gap: 6px;
  }
  .tb-status-badge .dot {
    width: 7px; height: 7px; border-radius: 50%;
    animation: pulse 1.4s ease-in-out infinite;
  }
  @keyframes pulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:0.5;transform:scale(0.8);} }
  .badge-lobby  { background: rgba(255,209,102,0.15); border: 1px solid rgba(255,209,102,0.3); color: var(--gold); }
  .badge-lobby .dot  { background: var(--gold); }
  .badge-live   { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: var(--green); }
  .badge-live .dot   { background: var(--green); }
  .badge-ended  { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: var(--red); }

  /* ── LAYOUT ── */
  .layout {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 0; min-height: calc(100vh - 58px);
  }
  @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }

  /* ── LEFT PANEL ── */
  .left-panel {
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    overflow-y: auto; max-height: calc(100vh - 58px);
    position: sticky; top: 58px;
  }

  .panel-section { padding: 1.25rem 1.25rem 0; }
  .panel-section:last-child { padding-bottom: 1.25rem; }
  .sec-title {
    font-size: 0.65rem; font-weight: 900; text-transform: uppercase;
    letter-spacing: 0.12em; color: var(--muted); margin-bottom: 0.85rem;
    display: flex; align-items: center; gap: 6px;
  }
  .sec-title i { color: var(--purple-l); }

  /* PIN display */
  .pin-display {
    background: linear-gradient(135deg, rgba(124,58,237,0.2), rgba(124,58,237,0.05));
    border: 1px solid rgba(124,58,237,0.3);
    border-radius: var(--r); padding: 1.1rem; text-align: center;
    margin-bottom: 0.85rem;
  }
  .pin-label { font-size: 0.62rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.12em; color: var(--muted); margin-bottom: 5px; }
  .pin-number {
    font-family: 'Lilita One', cursive; font-size: 2.8rem; color: var(--gold);
    letter-spacing: 0.2em; line-height: 1;
    text-shadow: 0 0 30px rgba(255,209,102,0.35);
  }
  .pin-copy-btn {
    margin-top: 0.6rem; background: rgba(255,255,255,0.07);
    border: 1px solid var(--border); border-radius: 8px;
    padding: 7px 14px; font-family: 'Nunito', sans-serif; font-weight: 800;
    font-size: 0.75rem; color: var(--muted); cursor: pointer; transition: all 0.18s;
    display: flex; align-items: center; gap: 6px; justify-content: center; width: 100%;
  }
  .pin-copy-btn:hover { background: rgba(255,255,255,0.12); color: white; }

  /* Stats row */
  .stats-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; margin-bottom: 0.85rem; }
  .stat-box {
    background: var(--card); border-radius: var(--r); padding: 12px 10px; text-align: center;
  }
  .stat-val { font-family: 'Lilita One', cursive; font-size: 1.6rem; color: white; line-height: 1; }
  .stat-lbl { font-size: 0.6rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-top: 4px; }

  /* Control buttons */
  .ctrl-btn {
    width: 100%; height: 50px; border: none; border-radius: var(--r);
    font-family: 'Nunito', sans-serif; font-weight: 900; font-size: 0.95rem;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 9px;
    transition: transform 0.15s, box-shadow 0.15s, opacity 0.2s;
    margin-bottom: 0.6rem;
  }
  .ctrl-btn:hover:not(:disabled) { transform: translateY(-2px); }
  .ctrl-btn:disabled { opacity: 0.45; cursor: not-allowed; transform: none; }
  .btn-next {
    background: linear-gradient(135deg, var(--green), #16a34a);
    color: white; box-shadow: 0 4px 18px rgba(34,197,94,0.35);
  }
  .btn-results {
    background: linear-gradient(135deg, var(--purple), var(--purple-l));
    color: white; box-shadow: 0 4px 18px rgba(124,58,237,0.35);
  }
  .btn-end {
    background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3);
    color: var(--red);
  }
  .btn-end:hover { background: rgba(239,68,68,0.25) !important; }

  /* Q progress mini */
  .q-progress-wrap { margin-bottom: 0.85rem; }
  .q-progress-label { font-size: 0.7rem; font-weight: 800; color: var(--muted); margin-bottom: 5px; }
  .q-progress-bar { height: 8px; background: rgba(255,255,255,0.07); border-radius: 100px; overflow: hidden; }
  .q-progress-fill { height: 100%; background: linear-gradient(90deg, var(--teal), var(--purple-l)); transition: width 0.5s ease; border-radius: 100px; }

  /* Player list */
  .player-list { flex: 1; overflow-y: auto; }
  .player-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 10px; border-radius: 10px; margin-bottom: 4px;
    background: var(--card); font-size: 0.82rem; font-weight: 700;
    animation: slideIn 0.3s ease both;
  }
  @keyframes slideIn { from{opacity:0;transform:translateX(-10px);} to{opacity:1;transform:translateX(0);} }
  .player-avatar {
    width: 28px; height: 28px; border-radius: 8px;
    background: var(--purple); display: flex; align-items: center; justify-content: center;
    font-size: 0.7rem; font-weight: 900; color: white; flex-shrink: 0;
  }
  .player-score { margin-left: auto; font-family: 'Lilita One', cursive; font-size: 0.9rem; color: var(--gold); }

  .divider { height: 1px; background: var(--border); margin: 0.85rem 0; }

  /* ── RIGHT PANEL — Main stage ── */
  .main-stage {
    display: flex; flex-direction: column;
    align-items: center; justify-content: flex-start;
    padding: 2rem 1.5rem; overflow-y: auto;
  }

  /* ── LOBBY VIEW ── */
  .lobby-view { width: 100%; max-width: 640px; text-align: center; }
  .lobby-hero { margin-bottom: 2rem; }
  .lobby-hero h1 {
    font-family: 'Lilita One', cursive;
    font-size: clamp(1.8rem, 3vw, 2.6rem); color: white; margin-bottom: 0.5rem;
  }
  .lobby-hero p { font-size: 0.9rem; color: var(--muted); font-weight: 700; }
  .lobby-pin-big {
    background: var(--card); border: 2px solid rgba(255,209,102,0.2);
    border-radius: 20px; padding: 2rem; margin-bottom: 1.5rem;
  }
  .lpb-l { font-size: 0.7rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.12em; color: var(--muted); margin-bottom: 8px; }
  .lpb-pin {
    font-family: 'Lilita One', cursive; font-size: clamp(3rem, 8vw, 5rem);
    color: var(--gold); letter-spacing: 0.25em; line-height: 1;
    text-shadow: 0 0 50px rgba(255,209,102,0.4);
  }
  .lpb-url { font-size: 0.8rem; color: var(--muted); margin-top: 8px; font-weight: 700; }
  .lpb-url span { color: white; }

  .players-waiting-grid {
    display: flex; flex-wrap: wrap; gap: 8px; justify-content: center;
    min-height: 80px; margin-bottom: 1.5rem;
  }
  .pw-chip {
    background: var(--card); border: 1px solid var(--border);
    border-radius: 100px; padding: 6px 14px;
    font-size: 0.82rem; font-weight: 800; color: white;
    display: flex; align-items: center; gap: 6px;
    animation: popIn 0.3s cubic-bezier(0.16,1,0.3,1) both;
  }
  @keyframes popIn { from{opacity:0;transform:scale(0.7);} to{opacity:1;transform:scale(1);} }
  .pw-chip .av {
    width: 22px; height: 22px; border-radius: 50%;
    background: var(--purple); font-size: 0.65rem; font-weight: 900;
    display: flex; align-items: center; justify-content: center; color: white;
  }
  .pw-count { font-size: 1rem; font-weight: 800; color: var(--muted); text-align: center; margin-bottom: 1rem; }
  .pw-count span { color: white; font-family: 'Lilita One', cursive; font-size: 1.4rem; }

  .start-game-btn {
    width: 100%; height: 64px;
    background: linear-gradient(135deg, var(--green), #16a34a);
    border: none; border-radius: 16px; color: white;
    font-family: 'Nunito', sans-serif; font-weight: 900; font-size: 1.2rem;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 12px;
    box-shadow: 0 6px 28px rgba(34,197,94,0.4);
    transition: transform 0.18s, box-shadow 0.18s;
  }
  .start-game-btn:hover { transform: translateY(-3px); box-shadow: 0 12px 36px rgba(34,197,94,0.55); }

  /* ── QUESTION VIEW ── */
  .question-view { width: 100%; max-width: 720px; }
  .qv-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; }
  .qv-badge {
    background: rgba(124,58,237,0.2); border: 1px solid rgba(124,58,237,0.35);
    border-radius: 100px; padding: 6px 16px;
    font-size: 0.72rem; font-weight: 900; color: var(--purple-l); text-transform: uppercase; letter-spacing: 0.08em;
  }
  .qv-timer-display {
    font-family: 'Lilita One', cursive; font-size: 1.6rem; color: white;
    background: var(--card); border-radius: 12px; padding: 6px 18px;
    border: 2px solid var(--border);
    transition: border-color 0.3s, color 0.3s;
  }
  .qv-timer-display.urgent { border-color: var(--coral); color: var(--coral); }

  .qv-question-text {
    background: white; color: #111;
    border-radius: 16px; padding: 1.5rem 2rem;
    font-family: 'Nunito', sans-serif; font-weight: 800;
    font-size: clamp(1rem, 2vw, 1.3rem); line-height: 1.55;
    text-align: center; margin-bottom: 1.25rem;
    box-shadow: 0 8px 28px rgba(0,0,0,0.4);
  }
  .qv-img { width: 100%; max-height: 160px; object-fit: cover; border-radius: 12px; margin-bottom: 1rem; display: none; }

  .answers-preview {
    display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem; margin-bottom: 1.5rem;
  }
  .ans-preview-btn {
    border: none; border-radius: 12px; padding: 14px 12px;
    display: flex; align-items: center; gap: 10px;
    font-family: 'Nunito', sans-serif; font-weight: 800; font-size: 0.9rem;
    color: white; text-align: left; opacity: 0.92;
  }
  .ans-preview-btn[data-idx="0"] { background: var(--ans-a); }
  .ans-preview-btn[data-idx="1"] { background: var(--ans-b); }
  .ans-preview-btn[data-idx="2"] { background: var(--ans-c); color: #111; }
  .ans-preview-btn[data-idx="3"] { background: var(--ans-d); }
  .ans-preview-btn.is-correct { outline: 3px solid white; outline-offset: 2px; }
  .ans-icon { width: 28px; height: 28px; border-radius: 7px; background: rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.85rem; }

  /* Live answer bar chart */
  .answer-bars { margin-bottom: 1.5rem; }
  .ab-title { font-size: 0.68rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); margin-bottom: 0.75rem; }
  .ab-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
  .ab-label { width: 18px; font-weight: 900; font-size: 0.8rem; text-align: center; }
  .ab-bar-wrap { flex: 1; height: 28px; background: rgba(255,255,255,0.06); border-radius: 6px; overflow: hidden; }
  .ab-bar { height: 100%; border-radius: 6px; transition: width 0.5s ease; min-width: 4px; display: flex; align-items: center; padding-left: 8px; }
  .ab-bar[data-idx="0"] { background: var(--ans-a); }
  .ab-bar[data-idx="1"] { background: var(--ans-b); }
  .ab-bar[data-idx="2"] { background: var(--ans-c); }
  .ab-bar[data-idx="3"] { background: var(--ans-d); }
  .ab-count { font-size: 0.72rem; font-weight: 900; color: white; min-width: 24px; text-align: right; }

  /* ── PODIUM ── */
  .podium-view { width: 100%; max-width: 640px; }
  .podium-title {
    font-family: 'Lilita One', cursive; font-size: 2rem; text-align: center;
    margin-bottom: 0.5rem; color: var(--gold);
    text-shadow: 0 0 30px rgba(255,209,102,0.5);
  }
  .podium-sub { text-align: center; color: var(--muted); font-size: 0.85rem; font-weight: 700; margin-bottom: 2rem; }

  .podium-stage {
    display: flex; align-items: flex-end; justify-content: center;
    gap: 1rem; margin-bottom: 2rem; height: 220px;
  }
  .podium-place {
    display: flex; flex-direction: column; align-items: center;
    flex: 1; max-width: 160px;
  }
  .podium-avatar {
    width: 60px; height: 60px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Lilita One', cursive; font-size: 1.4rem; color: white;
    margin-bottom: 8px; position: relative;
    box-shadow: 0 6px 20px rgba(0,0,0,0.4);
  }
  .podium-avatar .crown { position: absolute; top: -16px; font-size: 1.2rem; }
  .podium-place-1 .podium-avatar { background: linear-gradient(135deg, #f59e0b, #d97706); width: 72px; height: 72px; font-size: 1.7rem; }
  .podium-place-2 .podium-avatar { background: linear-gradient(135deg, #6b7280, #4b5563); }
  .podium-place-3 .podium-avatar { background: linear-gradient(135deg, #92400e, #78350f); }
  .podium-name { font-weight: 900; font-size: 0.82rem; color: white; text-align: center; margin-bottom: 6px; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .podium-score-val { font-family: 'Lilita One', cursive; font-size: 1rem; color: var(--gold); margin-bottom: 6px; }
  .podium-bar {
    width: 100%; border-radius: 10px 10px 0 0;
    display: flex; align-items: flex-end; justify-content: center;
    padding-bottom: 12px; font-family: 'Lilita One', cursive; font-size: 1.5rem; color: rgba(255,255,255,0.5);
  }
  .podium-place-1 .podium-bar { background: linear-gradient(180deg,rgba(245,158,11,0.4),rgba(245,158,11,0.15)); height: 140px; }
  .podium-place-2 .podium-bar { background: linear-gradient(180deg,rgba(107,114,128,0.4),rgba(107,114,128,0.15)); height: 100px; }
  .podium-place-3 .podium-bar { background: linear-gradient(180deg,rgba(146,64,14,0.4),rgba(146,64,14,0.15)); height: 70px; }

  .lb-section { background: var(--card); border-radius: 16px; overflow: hidden; margin-bottom: 1.5rem; }
  .lb-head { padding: 14px 18px; border-bottom: 1px solid var(--border); font-size: 0.68rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); display: flex; align-items: center; gap: 6px; }
  .lb-head i { color: var(--gold); }
  .lb-row {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 18px; border-bottom: 1px solid var(--border);
    transition: background 0.15s;
  }
  .lb-row:last-child { border-bottom: none; }
  .lb-row:hover { background: rgba(255,255,255,0.03); }
  .lb-rank { width: 26px; height: 26px; border-radius: 7px; background: rgba(255,255,255,0.07); font-family: 'Lilita One', cursive; font-size: 0.85rem; color: var(--muted); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .lb-row:nth-child(1) .lb-rank { background: var(--gold); color: #222; }
  .lb-row:nth-child(2) .lb-rank { background: #9ca3af; color: #111; }
  .lb-row:nth-child(3) .lb-rank { background: #92400e; color: white; }
  .lb-name { flex: 1; font-weight: 800; font-size: 0.88rem; }
  .lb-score-val { font-family: 'Lilita One', cursive; font-size: 1rem; color: var(--gold); }

  .confetti-wrap { position: fixed; inset: 0; pointer-events: none; z-index: 999; overflow: hidden; }
  .confetti-piece {
    position: absolute; width: 10px; height: 14px;
    animation: confettiFall linear forwards; opacity: 0.9;
  }
  @keyframes confettiFall {
    0%   { transform: translateY(-20px) rotate(0deg); opacity: 1; }
    100% { transform: translateY(100vh) rotate(720deg); opacity: 0; }
  }
  @keyframes spin { to{transform:rotate(360deg);} }
  .spin { animation: spin 0.7s linear infinite; display: inline-block; }

  /* ── DEBUG PANEL ── */
  #debugPanel {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 9999;
    background: #000; color: #0f0; font-family: monospace; font-size: 11px;
    max-height: 220px; overflow-y: auto; padding: 6px 10px;
    border-top: 2px solid #0f0;
    display: none;
  }
  #debugPanel .de { padding: 2px 0; border-bottom: 1px solid #111; }
  #debugPanel .de.err { color: #f55; }
  #debugPanel .de.ok  { color: #4f4; }
  #debugPanel .de.warn{ color: #ff0; }
  #debugToggle {
    position: fixed; bottom: 0; right: 80px; z-index: 10000;
    background: #222; color: #0f0; border: 1px solid #0f0;
    font-size: 11px; padding: 3px 8px; cursor: pointer; font-family: monospace;
  }
  </style>
</head>
<body>

<div class="topbar">
  <div class="tb-brand">Key<em>chele</em></div>
  <div class="tb-game-name"><i class="fa-solid fa-gamepad" style="margin-right:5px;color:var(--purple-l)"></i><?= htmlspecialchars($game['title']) ?></div>
  <div class="tb-right">
    <div class="tb-status-badge badge-lobby" id="statusBadge">
      <span class="dot"></span>
      <span id="statusLabel">Lobby</span>
    </div>
  </div>
</div>

<div class="layout">

  <!-- ═══════════ LEFT PANEL ═══════════ -->
  <div class="left-panel">

    <div class="panel-section">
      <div class="sec-title"><i class="fa-solid fa-key"></i> Game PIN</div>
      <div class="pin-display">
        <div class="pin-label">Share with players</div>
        <div class="pin-number"><?= $game['game_pin'] ?></div>
        <button class="pin-copy-btn" onclick="copyPin()">
          <i class="fa-regular fa-copy"></i> Copy PIN
        </button>
      </div>
    </div>

    <div class="panel-section">
      <div class="stats-row">
        <div class="stat-box">
          <div class="stat-val" id="playerCount">0</div>
          <div class="stat-lbl">Players</div>
        </div>
        <div class="stat-box">
          <div class="stat-val"><?= $q_count ?></div>
          <div class="stat-lbl">Questions</div>
        </div>
      </div>

      <div class="q-progress-wrap" id="qProgressWrap" style="display:none">
        <div class="q-progress-label" id="qProgressLabel">Question 1 of <?= $q_count ?></div>
        <div class="q-progress-bar"><div class="q-progress-fill" id="qProgressFill" style="width:0%"></div></div>
      </div>

      <div class="divider"></div>

      <div id="controlsArea">
        <button class="ctrl-btn btn-next" id="mainCtrlBtn" onclick="handleMainCtrl()">
          <i class="fa-solid fa-play"></i> Start Game
        </button>
        <button class="ctrl-btn btn-end" id="endBtn" onclick="endGame()" style="display:none">
          <i class="fa-solid fa-stop"></i> End Game
        </button>
      </div>
    </div>

    <div class="panel-section" style="flex:1; display:flex; flex-direction:column; overflow:hidden;">
      <div class="sec-title"><i class="fa-solid fa-users"></i> Players <span id="pCountBadge" style="color:white;margin-left:4px">0</span></div>
      <div class="player-list" id="playerList">
        <div style="text-align:center;padding:1.5rem;color:var(--muted);font-size:0.82rem;">
          Waiting for players to join...
        </div>
      </div>
    </div>

  </div>

  <!-- ═══════════ MAIN STAGE ═══════════ -->
  <div class="main-stage" id="mainStage">

    <!-- LOBBY VIEW -->
    <div class="lobby-view" id="lobbyView">
      <div class="lobby-hero">
        <h1>🎮 <?= htmlspecialchars($game['title']) ?></h1>
        <p>Share the PIN below — players can join at <strong style="color:white">keychele.co.ke/play</strong></p>
      </div>
      <div class="lobby-pin-big">
        <div class="lpb-l">Game PIN</div>
        <div class="lpb-pin"><?= $game['game_pin'] ?></div>
        <div class="lpb-url">Players join at <span>keychele.co.ke/play</span></div>
      </div>
      <div class="pw-count">Waiting: <span id="waitingCount">0</span> players</div>
      <div class="players-waiting-grid" id="playersWaitingGrid"></div>
      <button class="start-game-btn" id="startGameBtn" onclick="handleMainCtrl()">
        <i class="fa-solid fa-rocket"></i> Start Game Now!
      </button>
    </div>

    <!-- QUESTION VIEW -->
    <div class="question-view" id="questionView" style="display:none">
      <div class="qv-header">
        <div class="qv-badge" id="qvBadge">Question 1</div>
        <div class="qv-timer-display" id="qvTimer">—</div>
      </div>
      <img src="" alt="" class="qv-img" id="qvImg">
      <div class="qv-question-text" id="qvText">Loading...</div>
      <div class="answers-preview" id="qvAnswers"></div>
      <div class="answer-bars" id="answerBars" style="display:none">
        <div class="ab-title"><i class="fa-solid fa-chart-bar" style="margin-right:5px"></i>Live Responses</div>
        <div id="abRows"></div>
      </div>
    </div>

    <!-- PODIUM VIEW -->
    <div class="podium-view" id="podiumView" style="display:none">
      <div class="podium-title">🏆 Final Results!</div>
      <div class="podium-sub">Game over — here are the top players!</div>
      <div class="podium-stage" id="podiumStage"></div>
      <div class="lb-section">
        <div class="lb-head"><i class="fa-solid fa-trophy"></i> Full Leaderboard</div>
        <div id="finalLbRows"></div>
      </div>
      <button class="ctrl-btn btn-end" style="max-width:280px;margin:0 auto" onclick="window.location.href='index.php?page=create_game'">
        <i class="fa-solid fa-plus"></i> Create Another Game
      </button>
    </div>

  </div>
</div>

<div class="confetti-wrap" id="confettiWrap"></div>

<script>
const GAME_ID   = <?= $game_id ?>;
const GAME_PIN  = '<?= $game['game_pin'] ?>';
const Q_COUNT   = <?= $q_count ?>;
const QUESTIONS = <?= json_encode($questions) ?>;
const ANS_ICONS  = ['▲','●','◆','■'];
const ANS_LABELS = ['A','B','C','D'];

let gameStatus   = '<?= $game['status'] ?>';
let currentQ     = <?= (int)$game['current_question'] ?>;
let timerInt     = null;
let timeLeft     = 0;
let pollInt      = null;
let knownPlayers = new Set();

// ═══════════════════════════════════
// POLLING
// ═══════════════════════════════════
function startPolling() {
  pollInt = setInterval(pollResults, 1500);
}

async function pollResults() {
  // ✅ FIXED: was 'msanii/play.php' — now correctly points up one level to root
  const url = `../play.php?action=get_results&game_id=${GAME_ID}`;
  dbg('POLL → ' + url);
  try {
    const r = await fetch(url);
    dbg('POLL HTTP ' + r.status + ' ' + r.statusText, r.ok ? 'ok' : 'err');
    const raw = await r.text();
    dbg('POLL RAW: ' + raw.slice(0, 200));
    let d;
    try { d = JSON.parse(raw); } catch(pe) { dbg('JSON PARSE ERROR: ' + pe.message, 'err'); return; }
    if (!d.ok) { dbg('POLL !ok: ' + d.error, 'err'); return; }

    dbg('POLL ok status=' + d.status + ' players=' + d.player_count, 'ok');
    document.getElementById('playerCount').textContent  = d.player_count;
    document.getElementById('pCountBadge').textContent  = d.player_count;
    document.getElementById('waitingCount').textContent = d.player_count;

    updatePlayerList(d.leaderboard);

    if ((gameStatus === 'question' || gameStatus === 'results') && d.answer_stats?.dist) {
      updateAnswerBars(d.answer_stats.dist, d.answer_stats.total);
    }

    if (d.status !== gameStatus) {
      gameStatus = d.status;
    }

    if (d.status === 'ended') {
      clearInterval(pollInt);
      showPodium(d.leaderboard);
    }
  } catch(e) { dbg('POLL EXCEPTION: ' + e.message, 'err'); }
}

// ═══════════════════════════════════
// PLAYER LIST
// ═══════════════════════════════════
function updatePlayerList(leaderboard) {
  if (!leaderboard || !leaderboard.length) {
    document.getElementById('playerList').innerHTML =
      '<div style="text-align:center;padding:1.5rem;color:var(--muted);font-size:0.82rem;">Waiting for players...</div>';
    document.getElementById('playersWaitingGrid').innerHTML = '';
    return;
  }

  document.getElementById('playerList').innerHTML = leaderboard.map((p, i) => `
    <div class="player-item">
      <div class="player-avatar" style="background:${avatarColor(i)}">${p.player_name.charAt(0).toUpperCase()}</div>
      <span>${esc(p.player_name)}</span>
      <span class="player-score">${parseInt(p.score).toLocaleString()}</span>
    </div>
  `).join('');

  const grid = document.getElementById('playersWaitingGrid');
  leaderboard.forEach((p, i) => {
    if (!knownPlayers.has(p.player_name)) {
      knownPlayers.add(p.player_name);
      const chip = document.createElement('div');
      chip.className = 'pw-chip';
      chip.innerHTML = `<div class="av" style="background:${avatarColor(i)}">${p.player_name.charAt(0).toUpperCase()}</div>${esc(p.player_name)}`;
      grid.appendChild(chip);
    }
  });
}

function avatarColor(i) {
  const colors = ['#7c3aed','#2563eb','#059669','#d97706','#dc2626','#db2777','#0891b2'];
  return colors[i % colors.length];
}

// ═══════════════════════════════════
// CONTROLS
// ═══════════════════════════════════
async function handleMainCtrl() {
  const btn      = document.getElementById('mainCtrlBtn');
  const startBtn = document.getElementById('startGameBtn');
  btn.disabled = true;
  if (startBtn) startBtn.disabled = true;

  if (gameStatus === 'lobby') {
    await hostCommand('next_question');
  } else if (gameStatus === 'question') {
    await hostCommand('show_results');
  } else if (gameStatus === 'results') {
    await hostCommand('next_question');
  }

  btn.disabled = false;
  if (startBtn) startBtn.disabled = false;
}

async function endGame() {
  if (!confirm('End the game now? All players will see final results.')) return;
  await hostCommand('end_game');
}

async function hostCommand(command) {
  dbg('CMD → ' + command + ' game_id=' + GAME_ID, 'warn');
  const fd = new FormData();
  fd.append('action', 'host_control');
  fd.append('game_id', GAME_ID);
  fd.append('command', command);
  try {
    // ✅ FIXED: was 'msanii/play.php' — now correctly points up one level to root
    const r = await fetch('../play.php', { method: 'POST', body: fd });
    dbg('CMD HTTP ' + r.status, r.ok ? 'ok' : 'err');
    const raw = await r.text();
    dbg('CMD RAW: ' + raw.slice(0, 300));
    let d;
    try { d = JSON.parse(raw); } catch(pe) { dbg('CMD JSON ERROR: ' + pe.message + ' raw=' + raw.slice(0,100), 'err'); return; }
    if (!d.ok) { dbg('CMD FAILED: ' + d.error, 'err'); alert('Command failed: ' + d.error); return; }
    dbg('CMD ok new status=' + d.status, 'ok');
    gameStatus = d.status;
    if (d.status === 'question') {
      currentQ = d.current_q;
      showQuestion(currentQ);
    } else if (d.status === 'results') {
      showResults(currentQ);
    } else if (d.status === 'ended') {
      clearInterval(timerInt);
      pollResults();
    }
    updateStatusBadge(d.status);
    updateControls(d.status);
  } catch(e) { dbg('CMD EXCEPTION: ' + e.message, 'err'); alert('Network error: ' + e.message); }
}

// ═══════════════════════════════════
// SHOW QUESTION
// ═══════════════════════════════════
function showQuestion(idx) {
  clearInterval(timerInt);
  const q = QUESTIONS[idx];
  if (!q) return;

  document.getElementById('lobbyView').style.display     = 'none';
  document.getElementById('questionView').style.display  = 'block';
  document.getElementById('podiumView').style.display    = 'none';
  document.getElementById('answerBars').style.display    = 'none';
  document.getElementById('qProgressWrap').style.display = 'block';

  const pct = ((idx + 1) / Q_COUNT) * 100;
  document.getElementById('qProgressFill').style.width   = pct + '%';
  document.getElementById('qProgressLabel').textContent  = `Question ${idx + 1} of ${Q_COUNT}`;
  document.getElementById('qvBadge').textContent         = `Question ${idx + 1} of ${Q_COUNT}`;

  const img = document.getElementById('qvImg');
  if (q.image) { img.src = q.image; img.style.display = 'block'; }
  else { img.style.display = 'none'; }

  document.getElementById('qvText').textContent = q.text || 'Untitled question';

  const answers = q.type === 'true_false'
    ? [{ text: 'True', correct: q.answers[0]?.correct }, { text: 'False', correct: q.answers[1]?.correct }]
    : (q.answers || []);

  document.getElementById('qvAnswers').innerHTML = answers.slice(0, 4).map((a, i) => `
    <div class="ans-preview-btn ${a.correct ? 'is-correct' : ''}" data-idx="${i}">
      <div class="ans-icon">${ANS_ICONS[i]}</div>
      <span>${esc(a.text || '(empty)')}</span>
    </div>
  `).join('');

  const secs    = parseInt(q.timeLimit) || 20;
  timeLeft      = secs;
  const timerEl = document.getElementById('qvTimer');
  timerEl.classList.remove('urgent');
  timerEl.textContent = secs + 's';

  clearInterval(timerInt);
  timerInt = setInterval(() => {
    timeLeft--;
    timerEl.textContent = timeLeft + 's';
    if (timeLeft <= 5) timerEl.classList.add('urgent');
    if (timeLeft <= 0) clearInterval(timerInt);
  }, 1000);
}

// ═══════════════════════════════════
// SHOW RESULTS (per-question)
// ═══════════════════════════════════
function showResults(idx) {
  clearInterval(timerInt);
  document.getElementById('qvTimer').textContent = 'Done';
  document.getElementById('qvTimer').classList.remove('urgent');
  document.getElementById('answerBars').style.display = 'block';

  const q = QUESTIONS[idx];
  const answers = q.type === 'true_false'
    ? [{ text: 'True', correct: q.answers[0]?.correct }, { text: 'False', correct: q.answers[1]?.correct }]
    : (q.answers || []);
  document.querySelectorAll('.ans-preview-btn').forEach((el, i) => {
    el.classList.toggle('is-correct', !!(answers[i]?.correct));
  });
  pollResults();
}

// ═══════════════════════════════════
// ANSWER BARS
// ═══════════════════════════════════
function updateAnswerBars(dist, total) {
  const max = Math.max(...dist, 1);
  document.getElementById('abRows').innerHTML = dist.map((count, i) => `
    <div class="ab-row">
      <div class="ab-label" style="color:var(--ink)">${ANS_LABELS[i]}</div>
      <div class="ab-bar-wrap">
        <div class="ab-bar" data-idx="${i}" style="width:${(count/max)*100}%">
          <span style="font-size:0.7rem;font-weight:900;color:rgba(255,255,255,0.8)">${count > 0 ? count : ''}</span>
        </div>
      </div>
      <div class="ab-count">${count}</div>
    </div>
  `).join('');
}

// ═══════════════════════════════════
// PODIUM
// ═══════════════════════════════════
function showPodium(leaderboard) {
  clearInterval(timerInt);
  document.getElementById('lobbyView').style.display    = 'none';
  document.getElementById('questionView').style.display = 'none';
  document.getElementById('podiumView').style.display   = 'block';

  updateStatusBadge('ended');
  updateControls('ended');

  const top3    = leaderboard.slice(0, 3);
  const order   = [top3[1], top3[0], top3[2]].filter(Boolean);
  const classes = top3[1] ? ['podium-place-2', 'podium-place-1', 'podium-place-3'] : ['podium-place-1'];
  const crowns  = { 'podium-place-1': '👑', 'podium-place-2': '🥈', 'podium-place-3': '🥉' };

  document.getElementById('podiumStage').innerHTML = order.map((p, i) => {
    const cls = classes[i];
    return `
      <div class="podium-place ${cls}">
        <div class="podium-avatar">
          <span class="crown">${crowns[cls]}</span>
          ${p.player_name.charAt(0).toUpperCase()}
        </div>
        <div class="podium-name">${esc(p.player_name)}</div>
        <div class="podium-score-val">${parseInt(p.score).toLocaleString()} pts</div>
        <div class="podium-bar">${['🥈','👑','🥉'][i]}</div>
      </div>
    `;
  }).join('');

  document.getElementById('finalLbRows').innerHTML = leaderboard.map((p, i) => `
    <div class="lb-row">
      <div class="lb-rank">${i + 1}</div>
      <div class="lb-name">${esc(p.player_name)}</div>
      <div class="lb-score-val">${parseInt(p.score).toLocaleString()}</div>
    </div>
  `).join('');

  launchConfetti();
}

// ═══════════════════════════════════
// STATUS / CONTROLS UPDATE
// ═══════════════════════════════════
function updateStatusBadge(status) {
  const badge = document.getElementById('statusBadge');
  const label = document.getElementById('statusLabel');
  badge.className = 'tb-status-badge';
  if (status === 'lobby')         { badge.classList.add('badge-lobby'); label.textContent = 'Lobby'; }
  else if (status === 'question') { badge.classList.add('badge-live');  label.textContent = 'Live'; }
  else if (status === 'results')  { badge.classList.add('badge-live');  label.textContent = 'Results'; }
  else                            { badge.classList.add('badge-ended'); label.textContent = 'Ended'; }
}

function updateControls(status) {
  const btn      = document.getElementById('mainCtrlBtn');
  const endBtn   = document.getElementById('endBtn');
  const startBtn = document.getElementById('startGameBtn');

  if (status === 'lobby') {
    btn.innerHTML = '<i class="fa-solid fa-play"></i> Start Game';
    btn.className = 'ctrl-btn btn-next';
    btn.style.display = 'flex';
    endBtn.style.display = 'none';
  } else if (status === 'question') {
    btn.innerHTML = '<i class="fa-solid fa-eye"></i> Show Answers';
    btn.className = 'ctrl-btn btn-results';
    btn.style.display = 'flex';
    endBtn.style.display = 'flex';
    if (startBtn) startBtn.style.display = 'none';
  } else if (status === 'results') {
    const isLast = currentQ >= Q_COUNT - 1;
    btn.innerHTML = isLast
      ? '<i class="fa-solid fa-flag-checkered"></i> Show Final Results'
      : '<i class="fa-solid fa-chevron-right"></i> Next Question';
    btn.className = 'ctrl-btn btn-next';
    btn.style.display = 'flex';
    endBtn.style.display = 'none';
  } else {
    btn.style.display  = 'none';
    endBtn.style.display = 'none';
  }
}

// ═══════════════════════════════════
// CONFETTI
// ═══════════════════════════════════
function launchConfetti() {
  const wrap   = document.getElementById('confettiWrap');
  const colors = ['#ffd166','#ff5e62','#7c3aed','#00c4b0','#22c55e','#3b82f6'];
  for (let i = 0; i < 120; i++) {
    const el = document.createElement('div');
    el.className = 'confetti-piece';
    el.style.cssText = `
      left: ${Math.random()*100}%;
      background: ${colors[Math.floor(Math.random()*colors.length)]};
      width: ${6 + Math.random()*8}px;
      height: ${10 + Math.random()*10}px;
      border-radius: ${Math.random() > 0.5 ? '50%' : '2px'};
      animation-duration: ${2 + Math.random()*2}s;
      animation-delay: ${Math.random()*1.5}s;
    `;
    wrap.appendChild(el);
  }
  setTimeout(() => wrap.innerHTML = '', 5000);
}

// ═══════════════════════════════════
// UTILS
// ═══════════════════════════════════
function copyPin() {
  navigator.clipboard.writeText(GAME_PIN).then(() => {
    const btn = event.currentTarget;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    setTimeout(() => { btn.innerHTML = '<i class="fa-regular fa-copy"></i> Copy PIN'; }, 2000);
  });
}

function esc(s) {
  return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ═══════════════════════════════════
// DEBUG
// ═══════════════════════════════════
function dbg(msg, cls='') {
  const p = document.getElementById('debugPanel');
  if (!p) { console.log('[DBG]', msg); return; }
  const d = document.createElement('div');
  d.className = 'de ' + cls;
  d.textContent = new Date().toISOString().slice(11,23) + ' ' + msg;
  p.appendChild(d);
  p.scrollTop = p.scrollHeight;
  console.log('[DBG]', msg);
}

// ═══════════════════════════════════
// INIT
// ═══════════════════════════════════
window.addEventListener('DOMContentLoaded', () => {
  updateStatusBadge(gameStatus);
  updateControls(gameStatus);

  if (gameStatus === 'question' && currentQ >= 0) {
    showQuestion(currentQ);
    updateControls('question');
  } else if (gameStatus === 'results' && currentQ >= 0) {
    showQuestion(currentQ);
    showResults(currentQ);
    updateControls('results');
  } else if (gameStatus === 'ended') {
    pollResults().then(() => {});
  }

  startPolling();
});
</script>

<div id="debugPanel"></div>
<button id="debugToggle" onclick="document.getElementById('debugPanel').style.display=document.getElementById('debugPanel').style.display==='none'?'block':'none'">DEBUG</button>

</body>
</html>