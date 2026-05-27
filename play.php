<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
include 'msanii/includes/connect.php';

$action = $_REQUEST['action'] ?? '';

if ($action !== '') {
    ob_end_clean();
    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Content-Type: application/json');

    function jsonOut($data) { echo json_encode($data); exit; }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS creator_games (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            creator_id       INT UNSIGNED NOT NULL,
            title            VARCHAR(150) NOT NULL,
            description      TEXT,
            category         VARCHAR(60)  DEFAULT 'General',
            visibility       ENUM('public','private') DEFAULT 'public',
            cover            VARCHAR(255),
            questions_json   LONGTEXT     NOT NULL,
            game_pin         CHAR(6)      UNIQUE,
            status           ENUM('draft','lobby','question','results','ended') DEFAULT 'draft',
            current_question INT          DEFAULT -1,
            play_count       INT UNSIGNED DEFAULT 0,
            created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_creator (creator_id),
            INDEX idx_pin     (game_pin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS game_sessions (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            game_id      INT UNSIGNED NOT NULL,
            player_name  VARCHAR(80)  NOT NULL,
            score        INT          DEFAULT 0,
            answers_json LONGTEXT     DEFAULT '[]',
            joined_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try { $pdo->exec("ALTER TABLE creator_games ADD COLUMN current_question INT DEFAULT -1"); } catch (PDOException $e) {}
        try { $pdo->exec("ALTER TABLE creator_games MODIFY COLUMN status ENUM('draft','lobby','question','results','ended') DEFAULT 'draft'"); } catch (PDOException $e) {}

    } catch (PDOException $e) {
        jsonOut(['ok' => false, 'error' => 'DB init error: ' . $e->getMessage()]);
    }

    $game_id = (int)($_REQUEST['game_id'] ?? 0);

    try {
        if ($action === 'join') {
            $pin  = trim($_POST['pin'] ?? '');
            $name = trim($_POST['player_name'] ?? '');
            if (!preg_match('/^\d{6}$/', $pin) || $name === '') {
                jsonOut(['ok' => false, 'error' => 'Invalid PIN or name.']);
            }
            $st = $pdo->prepare("SELECT id, title, category, questions_json, status, current_question
                FROM creator_games WHERE game_pin = ? AND status NOT IN ('ended','draft') LIMIT 1");
            $st->execute([$pin]);
            $game = $st->fetch(PDO::FETCH_ASSOC);
            if (!$game) jsonOut(['ok' => false, 'error' => 'Game not found or not open. Check the PIN.']);

            $chk = $pdo->prepare("SELECT id FROM game_sessions WHERE game_id = ? AND player_name = ? LIMIT 1");
            $chk->execute([$game['id'], $name]);
            $sid = $chk->fetchColumn();
            if (!$sid) {
                $ins = $pdo->prepare("INSERT INTO game_sessions (game_id, player_name, score, answers_json) VALUES (?,?,0,'[]')");
                $ins->execute([$game['id'], $name]);
                $sid = $pdo->lastInsertId();
            }
            $qs = json_decode($game['questions_json'], true);
            jsonOut([
                'ok'         => true,
                'session_id' => (int)$sid,
                'game_id'    => (int)$game['id'],
                'title'      => $game['title'],
                'category'   => $game['category'],
                'q_count'    => count($qs),
                'status'     => $game['status'],
                'current_q'  => (int)$game['current_question'],
            ]);
        }

        if ($action === 'get_state') {
            $sid = (int)($_GET['session_id'] ?? 0);
            if (!$game_id || !$sid) jsonOut(['ok' => false, 'error' => 'Missing params']);
            $st = $pdo->prepare("SELECT g.status, g.current_question, g.questions_json, s.score, s.answers_json
                FROM creator_games g JOIN game_sessions s ON s.game_id = g.id
                WHERE g.id = ? AND s.id = ?");
            $st->execute([$game_id, $sid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsonOut(['ok' => false, 'error' => 'Session not found']);

            $qs   = json_decode($row['questions_json'], true);
            $log  = json_decode($row['answers_json'], true) ?: [];
            $cur  = (int)$row['current_question'];
            $cnt  = count($qs);

            $out = [
                'ok'               => true,
                'status'           => $row['status'],
                'current_q'        => $cur,
                'q_count'          => $cnt,
                'score'            => (int)$row['score'],
                'already_answered' => isset($log[$cur]),
            ];

            if ($row['status'] === 'question' && $cur >= 0 && $cur < $cnt) {
                $q = $qs[$cur];
                $safe = array_map(function($a){ return ['text' => $a['text']]; }, $q['answers'] ?? []);
                if (($q['type'] ?? '') === 'true_false') $safe = [['text'=>'True'],['text'=>'False']];
                $out['question'] = [
                    'text'      => $q['text'] ?? '',
                    'type'      => $q['type'] ?? 'quiz',
                    'timeLimit' => (int)($q['timeLimit'] ?? 20),
                    'image'     => $q['image'] ?? null,
                    'answers'   => array_slice($safe, 0, 4),
                ];
            }

            if ($row['status'] === 'results' && $cur >= 0 && $cur < $cnt) {
                $q = $qs[$cur];
                $ans = $q['answers'] ?? [];
                if (($q['type'] ?? '') === 'true_false') {
                    $ans = [
                        ['text'=>'True',  'correct' => $ans[0]['correct'] ?? false],
                        ['text'=>'False', 'correct' => $ans[1]['correct'] ?? false],
                    ];
                }
                $out['correct_answers'] = array_map(function($a){ return (bool)($a['correct'] ?? false); }, array_slice($ans, 0, 4));
                $out['player_answer']   = $log[$cur] ?? null;
            }

            jsonOut($out);
        }

        if ($action === 'submit_answer') {
            $sid  = (int)($_POST['session_id'] ?? 0);
            $qi   = (int)($_POST['q_index']    ?? 0);
            $ai   = (int)($_POST['ans_index']   ?? 0);
            $time = (float)($_POST['time_taken'] ?? 0);
            if (!$game_id || !$sid) jsonOut(['ok' => false, 'error' => 'Missing params']);

            $st = $pdo->prepare("SELECT g.questions_json, g.current_question, g.status, s.score, s.answers_json
                FROM creator_games g JOIN game_sessions s ON s.game_id = g.id
                WHERE g.id = ? AND s.id = ? LIMIT 1");
            $st->execute([$game_id, $sid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status'] !== 'question' || (int)$row['current_question'] !== $qi) {
                jsonOut(['ok' => false, 'error' => 'Not accepting answers right now']);
            }
            $qs  = json_decode($row['questions_json'], true);
            $log = json_decode($row['answers_json'], true) ?: [];
            if (isset($log[$qi])) jsonOut(['ok' => false, 'error' => 'Already answered']);

            $q    = $qs[$qi];
            $ans  = $q['answers'] ?? [];
            if (($q['type'] ?? '') === 'true_false') {
                $ans = [
                    ['text'=>'True',  'correct' => $ans[0]['correct'] ?? false],
                    ['text'=>'False', 'correct' => $ans[1]['correct'] ?? false],
                ];
            }
            $correct  = !empty($ans[$ai]['correct']);
            $timelim  = (int)($q['timeLimit'] ?? 20);
            $pts = 0;
            if ($correct) {
                $pts_mode = $q['points'] ?? 'standard';
                $base = ($pts_mode === 'double') ? 2000 : (($pts_mode === 'none') ? 0 : 1000);
                $pts  = (int)max((int)($base * 0.5), round($base - ($time / max($timelim, 1)) * ($base * 0.5)));
            }
            $log[$qi]  = ['ans' => $ai, 'correct' => $correct, 'pts' => $pts];
            $new_score = (int)$row['score'] + $pts;
            $pdo->prepare("UPDATE game_sessions SET score = ?, answers_json = ? WHERE id = ?")
                ->execute([$new_score, json_encode($log), $sid]);
            jsonOut(['ok' => true, 'correct' => $correct, 'pts' => $pts, 'total_score' => $new_score]);
        }

        if ($action === 'get_results') {
            if (!$game_id) jsonOut(['ok' => false, 'error' => 'Missing game_id']);
            $st = $pdo->prepare("SELECT status, current_question, questions_json FROM creator_games WHERE id = ?");
            $st->execute([$game_id]);
            $game = $st->fetch(PDO::FETCH_ASSOC);
            if (!$game) jsonOut(['ok' => false, 'error' => 'Game not found']);
            $qs  = json_decode($game['questions_json'], true);
            $cur = (int)$game['current_question'];

            $lb = $pdo->prepare("SELECT player_name, score FROM game_sessions WHERE game_id = ? ORDER BY score DESC, joined_at ASC LIMIT 50");
            $lb->execute([$game_id]);
            $board = $lb->fetchAll(PDO::FETCH_ASSOC);

            $stats = null;
            if ($cur >= 0 && $cur < count($qs)) {
                $rows = $pdo->prepare("SELECT answers_json FROM game_sessions WHERE game_id = ?");
                $rows->execute([$game_id]);
                $all  = $rows->fetchAll(PDO::FETCH_COLUMN);
                $q    = $qs[$cur];
                $acnt = ($q['type'] ?? '') === 'true_false' ? 2 : min(4, count($q['answers'] ?? []));
                $dist = array_fill(0, $acnt, 0); $tot = 0;
                foreach ($all as $aj) {
                    $entry = json_decode($aj, true) ?: [];
                    if (isset($entry[$cur])) { $a = (int)($entry[$cur]['ans'] ?? -1); if ($a >= 0 && $a < $acnt) { $dist[$a]++; $tot++; } }
                }
                $stats = ['dist' => $dist, 'total' => $tot];
            }
            jsonOut(['ok' => true, 'status' => $game['status'], 'current_q' => $cur, 'q_count' => count($qs),
                'player_count' => count($board), 'leaderboard' => $board, 'answer_stats' => $stats]);
        }

        if ($action === 'host_control') {
            $cid = $_SESSION['creator_id'] ?? null;
            if (!$cid) jsonOut(['ok' => false, 'error' => 'Not authenticated']);
            $cmd = $_POST['command'] ?? '';
            $st  = $pdo->prepare("SELECT id, questions_json, current_question, status FROM creator_games WHERE id = ? AND creator_id = ? LIMIT 1");
            $st->execute([$game_id, $cid]);
            $game = $st->fetch(PDO::FETCH_ASSOC);
            if (!$game) jsonOut(['ok' => false, 'error' => 'Game not found']);
            $qs  = json_decode($game['questions_json'], true);
            $cur = (int)$game['current_question'];

            if ($cmd === 'next_question') {
                $next = $cur + 1;
                if ($next >= count($qs)) {
                    $pdo->prepare("UPDATE creator_games SET status='ended', current_question=?, updated_at=NOW() WHERE id=?")->execute([count($qs), $game_id]);
                    $pdo->prepare("UPDATE creator_games SET play_count=play_count+1 WHERE id=?")->execute([$game_id]);
                    jsonOut(['ok' => true, 'status' => 'ended']);
                }
                $pdo->prepare("UPDATE creator_games SET status='question', current_question=?, updated_at=NOW() WHERE id=?")->execute([$next, $game_id]);
                jsonOut(['ok' => true, 'status' => 'question', 'current_q' => $next]);
            }
            if ($cmd === 'show_results') {
                $pdo->prepare("UPDATE creator_games SET status='results', updated_at=NOW() WHERE id=?")->execute([$game_id]);
                jsonOut(['ok' => true, 'status' => 'results', 'current_q' => $cur]);
            }
            if ($cmd === 'end_game') {
                $pdo->prepare("UPDATE creator_games SET status='ended', play_count=play_count+1, updated_at=NOW() WHERE id=?")->execute([$game_id]);
                jsonOut(['ok' => true, 'status' => 'ended']);
            }
            jsonOut(['ok' => false, 'error' => 'Unknown command']);
        }

        if ($action === 'get_players') {
            if (!$game_id) jsonOut(['ok' => false, 'error' => 'Missing game_id']);
            $st = $pdo->prepare("SELECT status, current_question FROM creator_games WHERE id = ?");
            $st->execute([$game_id]);
            $game = $st->fetch(PDO::FETCH_ASSOC);
            $pl = $pdo->prepare("SELECT player_name FROM game_sessions WHERE game_id = ? ORDER BY joined_at DESC LIMIT 50");
            $pl->execute([$game_id]);
            jsonOut(['ok' => true, 'status' => $game['status'] ?? 'lobby',
                'current_q' => (int)($game['current_question'] ?? -1),
                'players'   => $pl->fetchAll(PDO::FETCH_COLUMN)]);
        }

    } catch (PDOException $e) {
        jsonOut(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'error' => $e->getMessage()]);
    }

    jsonOut(['ok' => false, 'error' => 'Unknown action']);
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Play — Keychele</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800;900&family=Space+Mono:wght@400;700&family=Outfit:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
/* ══════════════════════════════════════════════════════════════
   TOKENS
══════════════════════════════════════════════════════════════ */
:root {
  --bg:        #07050f;
  --bg2:       #0e0a1f;
  --surface:   #13102a;
  --surface2:  #1c1840;
  --border:    rgba(255,255,255,.07);
  --border2:   rgba(255,255,255,.13);
  --purple:    #7c3aed;
  --purple-l:  #a78bfa;
  --purple-ll: #ddd6fe;
  --gold:      #f59e0b;
  --gold-l:    #fcd34d;
  --teal:      #06b6d4;
  --coral:     #f43f5e;
  --green:     #22c55e;
  --ans-a:     #ef4444;
  --ans-b:     #3b82f6;
  --ans-c:     #eab308;
  --ans-d:     #22c55e;
  --r:         14px;
  --r-lg:      20px;
  --sidebar-w: 280px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{
  font-family:'Outfit',sans-serif;background:var(--bg);color:#fff;
  min-height:100vh;overflow-x:hidden;-webkit-font-smoothing:antialiased;
}
body::before{
  content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
  opacity:.35;
}

/* ── AMBIENT ──────────────────────────────────────────────── */
.glow-orb{position:fixed;border-radius:50%;pointer-events:none;z-index:0;filter:blur(80px);opacity:.15}
.orb1{width:60vw;height:60vw;max-width:700px;background:radial-gradient(circle,#7c3aed,transparent);top:-20%;right:-10%}
.orb2{width:50vw;height:50vw;max-width:600px;background:radial-gradient(circle,#06b6d4,transparent);bottom:-25%;left:-10%}
.orb3{width:35vw;height:35vw;max-width:400px;background:radial-gradient(circle,#f59e0b,transparent);top:40%;left:30%;opacity:.07}

/* ── CONFETTI ─────────────────────────────────────────────── */
.confetti-wrap{position:fixed;inset:0;pointer-events:none;z-index:999;overflow:hidden}
.confetti-piece{position:absolute;animation:cfFall linear forwards}
@keyframes cfFall{0%{opacity:1;transform:translateY(-20px) rotate(0)}100%{opacity:0;transform:translateY(100vh) rotate(720deg)}}

/* ══════════════════════════════════════════════════════════════
   APP SHELL
══════════════════════════════════════════════════════════════ */
.app-shell{position:relative;z-index:1;min-height:100vh;display:flex}

/* ══════════════════════════════════════════════════════════════
   LIVE SIDEBAR
══════════════════════════════════════════════════════════════ */
.live-sidebar{
  width:var(--sidebar-w);flex-shrink:0;
  background:linear-gradient(180deg,var(--surface) 0%,var(--bg2) 100%);
  border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  position:fixed;left:0;top:0;bottom:0;
  transform:translateX(-100%);
  transition:transform .35s cubic-bezier(.16,1,.3,1);
  z-index:20;overflow:hidden;
}
.live-sidebar.visible{transform:translateX(0)}
@media(min-width:900px){
  .live-sidebar{position:relative;transform:none!important;display:flex}
  .sidebar-toggle-btn{display:none!important}
  .sidebar-overlay{display:none!important}
}
.sidebar-header{
  padding:1.2rem 1rem .95rem;border-bottom:1px solid var(--border);
  background:rgba(124,58,237,.08);flex-shrink:0;
}
.sidebar-brand{
  font-family:'Syne',sans-serif;font-weight:900;font-size:.87rem;
  letter-spacing:.06em;text-transform:uppercase;
  background:linear-gradient(135deg,var(--purple-l),var(--teal));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
  margin-bottom:.45rem;
}
.sidebar-live-row{display:flex;align-items:center;justify-content:space-between}
.sidebar-live-dot{
  display:inline-flex;align-items:center;gap:6px;
  font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  color:var(--green);
}
.sidebar-live-dot::before{
  content:'';width:7px;height:7px;border-radius:50%;
  background:var(--green);animation:pulse 1.4s ease-in-out infinite;
}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.35;transform:scale(.75)}}
.sidebar-count-chip{
  font-size:.63rem;font-weight:700;
  background:rgba(255,255,255,.07);border:1px solid var(--border2);
  border-radius:100px;padding:2px 9px;color:rgba(255,255,255,.45);
}
.lb-list{flex:1;overflow-y:auto;padding:.65rem .65rem .9rem}
.lb-list::-webkit-scrollbar{width:3px}
.lb-list::-webkit-scrollbar-track{background:transparent}
.lb-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:2px}
.lb-empty{
  text-align:center;padding:2.5rem 1rem;
  font-size:.73rem;color:rgba(255,255,255,.2);font-weight:600;
  display:flex;flex-direction:column;align-items:center;gap:.6rem;
}
.lb-empty-icon{font-size:1.8rem;opacity:.3}
.lb-item{
  display:flex;align-items:center;gap:9px;
  padding:9px 10px;border-radius:12px;margin-bottom:5px;
  background:rgba(255,255,255,.03);border:1px solid transparent;
  transition:background .4s,border-color .4s,transform .4s cubic-bezier(.16,1,.3,1);
  animation:slideInLb .4s cubic-bezier(.16,1,.3,1) both;
}
@keyframes slideInLb{from{opacity:0;transform:translateX(-14px)}to{opacity:1;transform:none}}
.lb-item.rank-1{background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.2)}
.lb-item.rank-2{background:rgba(156,163,175,.05);border-color:rgba(156,163,175,.15)}
.lb-item.rank-3{background:rgba(180,83,9,.05);border-color:rgba(180,83,9,.2)}
.lb-item.is-me{background:rgba(124,58,237,.1);border-color:rgba(124,58,237,.3)}
.lb-item.rank-1.is-me{background:rgba(245,158,11,.14);border-color:rgba(245,158,11,.4)}
.lb-rank-num{
  width:26px;height:26px;border-radius:8px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-family:'Space Mono',monospace;font-size:.7rem;font-weight:700;
  background:rgba(255,255,255,.06);color:rgba(255,255,255,.3);
}
.lb-item.rank-1 .lb-rank-num{background:var(--gold);color:#111}
.lb-item.rank-2 .lb-rank-num{background:#9ca3af;color:#111}
.lb-item.rank-3 .lb-rank-num{background:#b45309;color:#fff}
.lb-avatar{
  width:30px;height:30px;border-radius:9px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-weight:900;font-size:.85rem;
  text-transform:uppercase;
  background:linear-gradient(135deg,var(--purple),var(--teal));color:#fff;
}
.lb-item.rank-1 .lb-avatar{background:linear-gradient(135deg,#f59e0b,#d97706)}
.lb-item.is-me  .lb-avatar{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
.lb-info{flex:1;min-width:0}
.lb-name{
  font-size:.8rem;font-weight:700;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  display:flex;align-items:center;gap:5px;
}
.lb-you-chip{
  font-size:.56rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;
  background:rgba(124,58,237,.3);color:var(--purple-l);
  border-radius:4px;padding:1px 5px;flex-shrink:0;
}
.lb-pts{font-family:'Space Mono',monospace;font-size:.7rem;color:rgba(255,255,255,.35);margin-top:1px}
.lb-item.rank-1 .lb-pts{color:var(--gold-l)}
.lb-item.is-me  .lb-pts{color:var(--purple-l)}
.lb-delta{font-family:'Space Mono',monospace;font-size:.62rem;font-weight:700;opacity:0;transition:opacity .3s}
.lb-delta.show{opacity:1}.lb-delta.up{color:var(--green)}.lb-delta.dn{color:var(--coral)}

/* ── SIDEBAR TOGGLE (mobile) ─────────────────────────────── */
.sidebar-toggle-btn{
  position:fixed;left:1rem;top:1rem;z-index:25;
  width:40px;height:40px;border-radius:12px;
  background:var(--surface);border:1px solid var(--border2);
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;color:#fff;font-size:.85rem;
  box-shadow:0 4px 20px rgba(0,0,0,.4);transition:background .2s;
}
.sidebar-toggle-btn:hover{background:var(--surface2)}
.sidebar-overlay{
  position:fixed;inset:0;z-index:19;background:rgba(0,0,0,.55);
  opacity:0;pointer-events:none;transition:opacity .3s;
}
.sidebar-overlay.show{opacity:1;pointer-events:all}

/* ══════════════════════════════════════════════════════════════
   MOBILE RANK BANNER
   Shown between topbar and progress bar on the game screen.
   Hidden at ≥900 px (sidebar handles it).
══════════════════════════════════════════════════════════════ */
.mobile-rank-bar{
  display:none; /* toggled via JS */
  width:100%;
  background:linear-gradient(90deg,rgba(124,58,237,.16) 0%,rgba(6,182,212,.1) 100%);
  border-bottom:1px solid rgba(255,255,255,.09);
  align-items:center;justify-content:space-between;
  padding:.55rem 1rem;
  flex-shrink:0;
  position:relative;overflow:hidden;
  gap:.75rem;
}
/* shimmer sweep */
.mobile-rank-bar::after{
  content:'';position:absolute;top:0;left:-100%;width:60%;height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.04),transparent);
  animation:mrbShimmer 4s linear infinite;pointer-events:none;
}
@keyframes mrbShimmer{0%{left:-100%}100%{left:200%}}

/* Only render on small screens */
@media(max-width:899px){
  .mobile-rank-bar{display:flex}
}

/* left side: badge + text */
.mrb-left{display:flex;align-items:center;gap:10px;flex:1;min-width:0}

.mrb-rank-badge{
  width:36px;height:36px;border-radius:10px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-family:'Space Mono',monospace;font-weight:700;font-size:.85rem;
  background:rgba(255,255,255,.08);border:1px solid var(--border2);
  color:rgba(255,255,255,.6);
  transition:background .45s,color .45s,border-color .45s,transform .2s;
}
.mrb-rank-badge.g1{background:var(--gold);color:#111;border-color:var(--gold);font-size:1rem}
.mrb-rank-badge.g2{background:#9ca3af;color:#111;border-color:#9ca3af;font-size:1rem}
.mrb-rank-badge.g3{background:#b45309;color:#fff;border-color:#b45309;font-size:1rem}

.mrb-texts{display:flex;flex-direction:column;min-width:0}
.mrb-label{
  font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;
  color:rgba(255,255,255,.3);line-height:1;margin-bottom:2px;
}
.mrb-rank-text{
  font-family:'Syne',sans-serif;font-weight:800;
  font-size:.88rem;color:#fff;line-height:1.3;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.mrb-rank-text .hi{color:var(--gold-l)}

/* right side: score + live dot */
.mrb-right{display:flex;align-items:center;gap:8px;flex-shrink:0}
.mrb-score-pill{
  background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);
  border-radius:100px;padding:4px 11px;
  font-family:'Space Mono',monospace;font-weight:700;font-size:.74rem;
  color:var(--gold-l);white-space:nowrap;
  transition:color .3s;
}
.mrb-live-dot{
  width:7px;height:7px;border-radius:50%;
  background:var(--green);animation:pulse 1.4s ease-in-out infinite;flex-shrink:0;
}

/* rank-change pop */
@keyframes rankPop{0%{transform:scale(1)}35%{transform:scale(1.38)}65%{transform:scale(.88)}100%{transform:scale(1)}}
.rank-pop{animation:rankPop .5s cubic-bezier(.16,1,.3,1)}

/* ══════════════════════════════════════════════════════════════
   MAIN CONTENT
══════════════════════════════════════════════════════════════ */
.main-content{flex:1;display:flex;flex-direction:column;min-height:100vh;min-width:0}

/* ══════════════════════════════════════════════════════════════
   SCREEN SYSTEM
══════════════════════════════════════════════════════════════ */
.screen{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem 1.25rem;min-height:100vh}
.screen.hidden{display:none!important}

/* ══════════════════════════════════════════════════════════════
   JOIN
══════════════════════════════════════════════════════════════ */
.join-wrap{width:100%;max-width:440px;display:flex;flex-direction:column;align-items:center}
.kc-logo{
  font-family:'Syne',sans-serif;font-weight:900;
  font-size:clamp(2.2rem,6vw,3rem);letter-spacing:-.03em;margin-bottom:.25rem;
  background:linear-gradient(135deg,#fff 0%,var(--purple-ll) 100%);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.kc-logo em{
  background:linear-gradient(135deg,var(--gold),var(--gold-l));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
  font-style:normal;
}
.kc-tagline{font-size:.8rem;color:rgba(255,255,255,.3);font-weight:600;margin-bottom:1.75rem;letter-spacing:.04em}
.join-card{
  width:100%;background:var(--surface);border:1px solid var(--border2);
  border-radius:var(--r-lg);overflow:hidden;
  box-shadow:0 24px 80px rgba(0,0,0,.5),0 0 0 1px rgba(255,255,255,.04);
}
.join-card-top{
  background:linear-gradient(135deg,rgba(124,58,237,.2),rgba(6,182,212,.08));
  border-bottom:1px solid var(--border);padding:1.4rem 1.75rem 1.2rem;
}
.join-step-lbl{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--purple-l);margin-bottom:.35rem}
.join-step-title{font-family:'Syne',sans-serif;font-weight:800;font-size:1.05rem;color:#fff}
.join-card-body{padding:1.4rem 1.75rem 1.75rem}
.step{display:none}.step.active{display:block}
.field-lbl{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.3);margin-bottom:.45rem}
.pin-input{
  width:100%;height:64px;background:rgba(255,255,255,.05);
  border:1.5px solid var(--border2);border-radius:var(--r);
  text-align:center;font-family:'Space Mono',monospace;font-weight:700;
  font-size:1.9rem;letter-spacing:.3em;color:#fff;outline:none;
  transition:border-color .2s,box-shadow .2s,background .2s;margin-bottom:1.2rem;
}
.pin-input:focus{border-color:var(--purple);box-shadow:0 0 0 4px rgba(124,58,237,.15);background:rgba(124,58,237,.06)}
.pin-input::placeholder{font-size:.95rem;letter-spacing:.08em;font-weight:600;color:rgba(255,255,255,.15)}
.name-input{
  width:100%;height:52px;background:rgba(255,255,255,.05);
  border:1.5px solid var(--border2);border-radius:var(--r);
  text-align:center;font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;
  color:#fff;outline:none;
  transition:border-color .2s,box-shadow .2s,background .2s;margin-bottom:1.2rem;
}
.name-input:focus{border-color:var(--purple);box-shadow:0 0 0 4px rgba(124,58,237,.15);background:rgba(124,58,237,.06)}
.name-input::placeholder{color:rgba(255,255,255,.18);font-weight:600}
.join-btn{
  width:100%;height:52px;border:none;border-radius:var(--r);
  font-family:'Syne',sans-serif;font-weight:800;font-size:.95rem;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:9px;
  transition:transform .15s,box-shadow .15s;letter-spacing:.02em;
}
.join-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,0,0,.35)}
.join-btn:disabled{opacity:.5;cursor:not-allowed}
.btn-ghost{background:rgba(255,255,255,.06);border:1.5px solid var(--border2);color:#fff;margin-bottom:.7rem}
.btn-primary{background:linear-gradient(135deg,var(--purple),#5b21b6);color:#fff;box-shadow:0 4px 24px rgba(124,58,237,.4)}
.error-msg{
  background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.25);
  border-radius:10px;padding:10px 14px;
  font-size:.78rem;font-weight:700;color:#fda4af;
  margin-bottom:1rem;display:flex;align-items:center;gap:8px;
}
.back-link{
  display:flex;align-items:center;gap:6px;
  font-size:.73rem;font-weight:700;color:rgba(255,255,255,.3);
  cursor:pointer;border:none;background:none;
  margin-bottom:1rem;padding:0;transition:color .15s;
}
.back-link:hover{color:var(--purple-l)}
.join-footer{
  margin-top:1.4rem;font-size:.7rem;color:rgba(255,255,255,.18);
  text-align:center;font-weight:600;
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.join-footer::before,.join-footer::after{content:'';flex:1;height:1px;background:rgba(255,255,255,.06)}

/* ══════════════════════════════════════════════════════════════
   WAITING
══════════════════════════════════════════════════════════════ */
.waiting-card{
  background:var(--surface);border:1px solid var(--border2);
  border-radius:var(--r-lg);padding:2.5rem 2rem;
  width:100%;max-width:460px;text-align:center;
  box-shadow:0 32px 80px rgba(0,0,0,.5);
}
.in-badge{
  display:inline-flex;align-items:center;gap:7px;
  background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);
  border-radius:100px;padding:5px 14px;
  font-size:.63rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;
  color:#4ade80;margin-bottom:1.25rem;
}
.in-badge .dot{width:6px;height:6px;border-radius:50%;background:#4ade80;animation:pulse 1.2s ease-in-out infinite}
.wait-title{font-family:'Syne',sans-serif;font-weight:900;font-size:clamp(1.15rem,4vw,1.6rem);margin-bottom:.3rem}
.wait-subtitle{font-size:.8rem;color:rgba(255,255,255,.3);font-weight:600;margin-bottom:1.5rem}
.player-tag{
  display:inline-flex;align-items:center;gap:8px;
  background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.25);
  border-radius:10px;padding:10px 18px;margin-bottom:1.75rem;
  font-size:.83rem;font-weight:700;color:rgba(255,255,255,.55);
}
.player-tag strong{color:var(--purple-l)}
.wait-cartoon{margin:0 auto 1.5rem;width:108px;height:108px;animation:bob 2s ease-in-out infinite}
@keyframes bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
.wait-status{
  font-size:.8rem;font-weight:700;color:rgba(255,255,255,.3);
  margin-bottom:1rem;display:flex;align-items:center;justify-content:center;gap:8px;
}
.dots span{display:inline-block;width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.22);margin:0 2px;animation:dotb 1.2s ease-in-out infinite}
.dots span:nth-child(2){animation-delay:.2s}.dots span:nth-child(3){animation-delay:.4s}
@keyframes dotb{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-7px)}}
.wait-meta{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.wm-box{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:12px;padding:12px 8px}
.wm-val{font-family:'Space Mono',monospace;font-weight:700;font-size:1.3rem;color:#fff;line-height:1}
.wm-lbl{font-size:.57rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.22);margin-top:4px}

/* ══════════════════════════════════════════════════════════════
   GAME SCREEN
══════════════════════════════════════════════════════════════ */
.game-screen{justify-content:flex-start;padding:0;background:linear-gradient(160deg,var(--bg2) 0%,var(--bg) 100%)}
.game-topbar{
  width:100%;padding:.8rem 1.1rem;
  background:rgba(0,0,0,.45);backdrop-filter:blur(16px);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  flex-shrink:0;gap:.65rem;
}
.score-pill{
  display:flex;align-items:center;gap:8px;
  background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);
  border-radius:100px;padding:5px 13px 5px 7px;
}
.score-icon{width:26px;height:26px;border-radius:7px;background:rgba(245,158,11,.15);display:flex;align-items:center;justify-content:center;font-size:.78rem;color:var(--gold)}
.score-val{font-family:'Space Mono',monospace;font-weight:700;font-size:.9rem;color:var(--gold-l)}
.score-lbl{font-size:.52rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.22)}
.q-pill{
  background:rgba(255,255,255,.06);border:1px solid var(--border2);
  border-radius:100px;padding:5px 15px;
  font-family:'Space Mono',monospace;font-size:.75rem;font-weight:700;color:rgba(255,255,255,.45);
}
.q-pill strong{color:#fff}
.timer-wrap{position:relative;display:flex;align-items:center;justify-content:center;width:44px;height:44px}
.timer-svg{transform:rotate(-90deg);position:absolute}
.timer-bg{fill:none;stroke:rgba(255,255,255,.08);stroke-width:3.5}
.timer-fg{fill:none;stroke:var(--teal);stroke-width:3.5;stroke-linecap:round;transition:stroke-dashoffset 1s linear,stroke .3s}
.timer-fg.urgent{stroke:var(--coral)}
.timer-num{font-family:'Space Mono',monospace;font-weight:700;font-size:.88rem;position:relative;z-index:1;color:#fff}
.progress-bar{width:100%;height:4px;background:rgba(255,255,255,.05);flex-shrink:0}
.progress-fill{height:100%;background:linear-gradient(90deg,var(--purple),var(--teal));transition:width .5s}
.q-area{width:100%;max-width:700px;margin:0 auto;padding:1.1rem 1.1rem .45rem;flex-shrink:0}
.q-img{width:100%;max-height:130px;object-fit:cover;border-radius:12px;margin-bottom:.7rem;display:none}
.q-text{
  background:#fff;color:#111;border-radius:16px;padding:1.15rem 1.4rem;
  font-family:'Syne',sans-serif;font-weight:700;
  font-size:clamp(.92rem,2.4vw,1.12rem);line-height:1.55;
  text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.35);
  min-height:62px;display:flex;align-items:center;justify-content:center;
}
.ans-grid{
  display:grid;grid-template-columns:1fr 1fr;gap:.7rem;
  padding:.7rem 1.1rem 1rem;width:100%;max-width:700px;margin:0 auto;
  flex:1;align-content:start;
}
@media(max-width:420px){.ans-grid{grid-template-columns:1fr}}
.ans-btn{
  border:none;border-radius:14px;padding:15px 13px;
  display:flex;align-items:center;gap:11px;
  cursor:pointer;color:#fff;
  font-family:'Outfit',sans-serif;font-weight:700;font-size:.9rem;
  transition:transform .15s,box-shadow .15s,opacity .25s;
  text-align:left;line-height:1.4;
  box-shadow:0 4px 0 rgba(0,0,0,.3),0 8px 18px rgba(0,0,0,.2);
  position:relative;overflow:hidden;
}
.ans-btn::before{content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgba(255,255,255,.1),transparent);pointer-events:none}
.ans-btn[data-idx="0"]{background:var(--ans-a)}.ans-btn[data-idx="1"]{background:var(--ans-b)}
.ans-btn[data-idx="2"]{background:var(--ans-c);color:#111}.ans-btn[data-idx="3"]{background:var(--ans-d)}
.ans-btn:not(:disabled):hover{transform:scale(1.02) translateY(-2px);box-shadow:0 6px 0 rgba(0,0,0,.3),0 12px 26px rgba(0,0,0,.25)}
.ans-btn:disabled{cursor:default}
.ans-btn.answered-correct{transform:scale(1.03)!important;box-shadow:0 0 0 3px #fff,0 8px 0 rgba(0,0,0,.25)!important}
.ans-btn.answered-wrong{opacity:.3;transform:scale(.96)!important}
.ans-btn.reveal-correct{box-shadow:0 0 0 2px rgba(255,255,255,.7),0 8px 0 rgba(0,0,0,.25)!important}
.ans-btn.reveal-wrong{opacity:.2}
.ans-icon{width:32px;height:32px;min-width:32px;border-radius:9px;background:rgba(0,0,0,.18);display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:900}
.ans-btn[data-idx="2"] .ans-icon{background:rgba(0,0,0,.1)}
.wait-host-msg{
  display:none;text-align:center;padding:1.4rem 1rem;
  background:rgba(0,0,0,.35);border:1px solid var(--border);border-radius:14px;
  margin:.7rem 1.1rem 1rem;max-width:700px;width:calc(100% - 2.2rem);align-self:center;
}
.wait-host-msg.show{display:block}
.whm-icon{font-size:1.65rem;margin-bottom:.35rem;display:block;animation:bob 2s ease-in-out infinite}
.whm-text{font-family:'Syne',sans-serif;font-weight:800;font-size:.87rem;margin-bottom:.2rem}
.whm-sub{font-size:.7rem;color:rgba(255,255,255,.28);font-weight:600}

/* ══════════════════════════════════════════════════════════════
   RESULT OVERLAY
══════════════════════════════════════════════════════════════ */
.result-overlay{
  position:fixed;inset:0;z-index:50;
  background:rgba(7,5,15,.88);backdrop-filter:blur(12px);
  display:flex;align-items:center;justify-content:center;padding:1.5rem;
  opacity:0;pointer-events:none;transition:opacity .28s;
}
.result-overlay.show{opacity:1;pointer-events:all}
.result-card{
  background:linear-gradient(145deg,var(--surface),var(--bg2));
  border:1px solid var(--border2);border-radius:24px;padding:2.2rem 2rem;
  width:100%;max-width:360px;text-align:center;
  box-shadow:0 40px 100px rgba(0,0,0,.7);
  transform:scale(.8) translateY(20px);
  transition:transform .38s cubic-bezier(.16,1,.3,1);
}
.result-overlay.show .result-card{transform:scale(1) translateY(0)}
.result-emoji{font-size:3.4rem;margin-bottom:.7rem;display:block;animation:emojiPop .5s cubic-bezier(.16,1,.3,1) .1s both}
@keyframes emojiPop{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}
.result-label{font-family:'Syne',sans-serif;font-weight:900;font-size:1.6rem;margin-bottom:.3rem}
.result-pts-text{font-size:.83rem;color:rgba(255,255,255,.38);margin-bottom:1.4rem;font-weight:700}
.result-pts-text strong{color:var(--gold-l);font-size:1.1rem}
.result-score-box{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:14px;padding:14px;margin-bottom:1rem}
.rsb-lbl{font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.23);margin-bottom:4px}
.rsb-val{font-family:'Space Mono',monospace;font-weight:700;font-size:2.1rem;color:var(--gold-l);line-height:1}
.result-wait{font-size:.72rem;color:rgba(255,255,255,.28);font-weight:700;display:flex;align-items:center;justify-content:center;gap:6px}

/* ══════════════════════════════════════════════════════════════
   FINAL
══════════════════════════════════════════════════════════════ */
.final-screen{background:linear-gradient(145deg,var(--bg2),var(--bg));justify-content:center}
.final-card{background:var(--surface);border:1px solid var(--border2);border-radius:var(--r-lg);width:100%;max-width:520px;overflow:hidden;box-shadow:0 40px 100px rgba(0,0,0,.6)}
.final-head{padding:1.9rem 2rem 1.4rem;text-align:center;background:linear-gradient(135deg,rgba(245,158,11,.1),rgba(124,58,237,.07));border-bottom:1px solid var(--border)}
.final-trophy{font-size:2.8rem;margin-bottom:.4rem;display:block}
.final-title{font-family:'Syne',sans-serif;font-weight:900;font-size:1.65rem;margin-bottom:.25rem}
.final-sub{font-size:.78rem;color:rgba(255,255,255,.38);font-weight:600}
.podium-stage{display:flex;align-items:flex-end;justify-content:center;gap:.8rem;padding:1.4rem 1rem 0;height:196px}
.podium-col{display:flex;flex-direction:column;align-items:center;flex:1;max-width:140px}
.podium-av{width:50px;height:50px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:900;font-size:1.15rem;color:#fff;margin-bottom:5px;position:relative;background:linear-gradient(135deg,var(--purple),var(--teal))}
.podium-av .crown{position:absolute;top:-13px;font-size:.95rem}
.p1 .podium-av{background:linear-gradient(135deg,#f59e0b,#d97706);width:60px;height:60px;font-size:1.35rem}
.p2 .podium-av{background:linear-gradient(135deg,#6b7280,#4b5563)}
.p3 .podium-av{background:linear-gradient(135deg,#92400e,#78350f)}
.podium-name{font-weight:800;font-size:.7rem;text-align:center;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-bottom:3px}
.podium-pts{font-family:'Space Mono',monospace;font-weight:700;font-size:.72rem;color:var(--gold);margin-bottom:4px}
.podium-bar{width:100%;border-radius:8px 8px 0 0;display:flex;align-items:flex-start;justify-content:center;padding-top:7px;font-size:1rem}
.p1 .podium-bar{background:rgba(245,158,11,.2);height:106px}
.p2 .podium-bar{background:rgba(107,114,128,.15);height:72px}
.p3 .podium-bar{background:rgba(146,64,14,.14);height:48px}
.my-score-banner{background:rgba(0,0,0,.2);padding:.8rem 1.5rem;text-align:center;border-top:1px solid var(--border)}
.msb-lbl{font-size:.57rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.22);margin-bottom:4px}
.msb-val{font-family:'Space Mono',monospace;font-weight:700;font-size:2.1rem;color:var(--gold-l);line-height:1}
.final-lb-wrap{padding:.8rem 1rem}
.final-lb-head{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.28);margin-bottom:.6rem;display:flex;align-items:center;gap:6px}
.final-lb-head i{color:var(--gold)}
.f-lb-row{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;margin-bottom:4px;background:rgba(255,255,255,.04);border:1px solid var(--border)}
.f-lb-row.is-me{background:rgba(124,58,237,.1);border-color:rgba(124,58,237,.3)}
.f-lb-row.top1{background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.25)}
.f-lb-row.top2{background:rgba(156,163,175,.05);border-color:rgba(156,163,175,.2)}
.f-lb-row.top3{background:rgba(146,64,14,.05);border-color:rgba(146,64,14,.2)}
.flb-rank{width:23px;height:23px;border-radius:6px;background:rgba(255,255,255,.06);font-family:'Space Mono',monospace;font-size:.65rem;font-weight:700;color:rgba(255,255,255,.28);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.f-lb-row.top1 .flb-rank{background:var(--gold);color:#111}
.f-lb-row.top2 .flb-rank{background:#9ca3af;color:#111}
.f-lb-row.top3 .flb-rank{background:#92400e;color:#fff}
.flb-name{flex:1;font-weight:700;font-size:.8rem}
.flb-me{color:var(--purple-l);font-size:.66rem;margin-left:4px}
.flb-pts{font-family:'Space Mono',monospace;font-size:.85rem;color:var(--gold)}
.final-actions{padding:.8rem 1rem 1.35rem;display:flex;gap:.6rem}
.final-btn{flex:1;height:44px;border:none;border-radius:var(--r);font-family:'Syne',sans-serif;font-weight:800;font-size:.8rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:transform .18s,box-shadow .18s;text-decoration:none;letter-spacing:.02em}
.final-btn:hover{transform:translateY(-2px)}
.btn-play-again{background:linear-gradient(135deg,var(--purple),#5b21b6);color:#fff;box-shadow:0 4px 20px rgba(124,58,237,.35)}
.btn-home{background:rgba(255,255,255,.06);border:1px solid var(--border2);color:#fff}

/* ── UTILS ─────────────────────────────────────────────────── */
@keyframes spin{to{transform:rotate(360deg)}}.spin{animation:spin .7s linear infinite;display:inline-block}
</style>
</head>
<body>

<div class="glow-orb orb1"></div>
<div class="glow-orb orb2"></div>
<div class="glow-orb orb3"></div>
<div class="confetti-wrap" id="confettiWrap"></div>

<!-- Mobile sidebar toggle -->
<button class="sidebar-toggle-btn" id="sidebarToggleBtn" style="display:none"
        onclick="toggleSidebar()" aria-label="View rankings">
  <i class="fa-solid fa-ranking-star"></i>
</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="app-shell">

  <!-- ══ LIVE SIDEBAR ═══════════════════════════════════════════ -->
  <aside class="live-sidebar" id="liveSidebar">
    <div class="sidebar-header">
      <div class="sidebar-brand">
        <i class="fa-solid fa-ranking-star" style="margin-right:6px"></i>Live Rankings
      </div>
      <div class="sidebar-live-row">
        <div class="sidebar-live-dot">Live</div>
        <div class="sidebar-count-chip" id="sidebarCountChip">0 players</div>
      </div>
    </div>
    <div class="lb-list" id="sidebarLbList">
      <div class="lb-empty">
        <span class="lb-empty-icon">🏆</span>
        <span>Rankings appear<br>once the game starts</span>
      </div>
    </div>
  </aside>

  <!-- ══ MAIN ═══════════════════════════════════════════════════ -->
  <div class="main-content">

    <!-- JOIN ─────────────────────────────────────────────────── -->
    <div class="screen" id="screenJoin">
      <div class="join-wrap">
        <div class="kc-logo">Key<em>chele</em></div>
        <div class="kc-tagline">Enter your PIN &amp; jump in</div>
        <div class="join-card">
          <div class="join-card-top">
            <div class="join-step-lbl"  id="joinStepLbl">Step 1 of 2</div>
            <div class="join-step-title" id="joinStepTitle">Enter Game PIN</div>
          </div>
          <div class="join-card-body">
            <div class="step active" id="stepPin">
              <div class="field-lbl">6-digit PIN</div>
              <div id="joinError" class="error-msg" style="display:none">
                <i class="fa-solid fa-circle-exclamation"></i><span id="joinErrorText"></span>
              </div>
              <input type="tel" id="pinInput" class="pin-input" placeholder="000000"
                     maxlength="6" inputmode="numeric" autocomplete="off">
              <button class="join-btn btn-ghost" onclick="handlePinNext()">
                <i class="fa-solid fa-arrow-right"></i> Continue
              </button>
            </div>
            <div class="step" id="stepName">
              <button class="back-link" onclick="goBackToPin()">
                <i class="fa-solid fa-chevron-left"></i> Change PIN
              </button>
              <div class="field-lbl">Your nickname</div>
              <div id="nameError" class="error-msg" style="display:none">
                <i class="fa-solid fa-circle-exclamation"></i><span id="nameErrorText"></span>
              </div>
              <input type="text" id="nameInput" class="name-input"
                     placeholder="e.g. TriviaNinja" maxlength="40" autocomplete="off">
              <button class="join-btn btn-primary" id="joinBtn" onclick="handleJoin()">
                <i class="fa-solid fa-rocket"></i> Let's Go!
              </button>
            </div>
          </div>
        </div>
        <div class="join-footer">No account needed · Enter PIN from the host screen</div>
      </div>
    </div>

    <!-- WAITING ──────────────────────────────────────────────── -->
    <div class="screen hidden" id="screenWaiting">
      <div class="waiting-card">
        <div class="in-badge"><span class="dot"></span> You're In!</div>
        <div class="wait-title" id="waitingTitle">Loading…</div>
        <div class="wait-subtitle">Waiting for the host to start</div>
        <div class="wait-cartoon">
          <svg viewBox="0 0 140 140" xmlns="http://www.w3.org/2000/svg">
            <circle cx="70" cy="70" r="55" fill="#7c3aed" opacity=".9"/>
            <ellipse cx="54" cy="62" rx="10" ry="12" fill="white"/>
            <circle cx="56" cy="62" r="6" fill="#1e0a3c"/><circle cx="58" cy="59" r="2" fill="white"/>
            <ellipse cx="86" cy="62" rx="10" ry="12" fill="white"/>
            <circle cx="88" cy="62" r="6" fill="#1e0a3c"/><circle cx="90" cy="59" r="2" fill="white"/>
            <path d="M56 86 Q70 98 84 86" fill="none" stroke="white" stroke-width="3" stroke-linecap="round"/>
            <ellipse cx="44" cy="75" rx="10" ry="6" fill="#a78bfa" opacity=".5"/>
            <ellipse cx="96" cy="75" rx="10" ry="6" fill="#a78bfa" opacity=".5"/>
          </svg>
        </div>
        <div class="player-tag">Playing as <strong id="waitingName">—</strong></div>
        <div class="wait-status">
          Waiting for host
          <span class="dots"><span></span><span></span><span></span></span>
        </div>
        <div class="wait-meta">
          <div class="wm-box"><div class="wm-val" id="waitingQCount">—</div><div class="wm-lbl">Questions</div></div>
          <div class="wm-box"><div class="wm-val" id="waitingCat" style="font-size:.72rem;line-height:1.3">—</div><div class="wm-lbl">Category</div></div>
        </div>
      </div>
    </div>

    <!-- GAME ─────────────────────────────────────────────────── -->
    <div class="screen game-screen hidden" id="screenGame">

      <!-- topbar -->
      <div class="game-topbar">
        <div class="score-pill">
          <div class="score-icon"><i class="fa-solid fa-star"></i></div>
          <div>
            <div class="score-val" id="scoreDisplay">0</div>
            <div class="score-lbl">Points</div>
          </div>
        </div>
        <div class="q-pill">Q <strong id="qNum">1</strong> / <span id="qTotal">?</span></div>
        <div class="timer-wrap">
          <svg class="timer-svg" width="44" height="44" viewBox="0 0 44 44">
            <circle class="timer-bg" cx="22" cy="22" r="18"/>
            <circle class="timer-fg" id="timerCircle" cx="22" cy="22" r="18"
                    stroke-dasharray="113.1" stroke-dashoffset="0"/>
          </svg>
          <span class="timer-num" id="timerNum">—</span>
        </div>
      </div>

      <!-- ▼▼▼ MOBILE RANK BANNER — hidden on desktop ▼▼▼ -->
      <div class="mobile-rank-bar" id="mobileRankBar">
        <div class="mrb-left">
          <div class="mrb-rank-badge" id="mrbBadge">#–</div>
          <div class="mrb-texts">
            <div class="mrb-label">Your Position</div>
            <div class="mrb-rank-text" id="mrbText">Calculating…</div>
          </div>
        </div>
        <div class="mrb-right">
          <div class="mrb-score-pill" id="mrbScore">0 pts</div>
          <div class="mrb-live-dot"></div>
        </div>
      </div>
      <!-- ▲▲▲ END MOBILE RANK BANNER ▲▲▲ -->

      <div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:0%"></div></div>

      <div class="q-area">
        <img src="" alt="" class="q-img" id="qImg">
        <div class="q-text" id="qText">Loading…</div>
      </div>
      <div class="ans-grid" id="ansGrid"></div>
      <div class="wait-host-msg" id="waitingHostMsg">
        <span class="whm-icon">⏳</span>
        <div class="whm-text">Waiting for host…</div>
        <div class="whm-sub">Host will reveal answers &amp; move to the next question</div>
      </div>
    </div>

    <!-- FINAL ────────────────────────────────────────────────── -->
    <div class="screen final-screen hidden" id="screenFinal">
      <div class="final-card">
        <div class="final-head">
          <span class="final-trophy" id="finalTrophy">🏆</span>
          <div class="final-title">Game Over!</div>
          <div class="final-sub" id="finalSub">Here are the results!</div>
        </div>
        <div class="podium-stage" id="podiumStage"></div>
        <div class="my-score-banner">
          <div class="msb-lbl">Your Final Score</div>
          <div class="msb-val" id="myFinalScore">0</div>
        </div>
        <div class="final-lb-wrap">
          <div class="final-lb-head"><i class="fa-solid fa-trophy"></i> Full Leaderboard</div>
          <div id="lbRows"></div>
        </div>
        <div class="final-actions">
          <button class="final-btn btn-play-again" onclick="location.href='play.php'">
            <i class="fa-solid fa-rotate-right"></i> Play Again
          </button>
          <a href="/" class="final-btn btn-home">
            <i class="fa-solid fa-house"></i> Home
          </a>
        </div>
      </div>
    </div>

  </div><!-- /main-content -->
</div><!-- /app-shell -->

<!-- RESULT OVERLAY -->
<div class="result-overlay" id="resultOverlay">
  <div class="result-card">
    <span class="result-emoji" id="resultEmoji">✅</span>
    <div class="result-label" id="resultStatus">Correct!</div>
    <div class="result-pts-text" id="resultPtsText">+<strong>1000</strong> points</div>
    <div class="result-score-box">
      <div class="rsb-lbl">Your Score</div>
      <div class="rsb-val" id="resultTotal">0</div>
    </div>
    <div class="result-wait">
      <i class="fa-solid fa-hourglass-half spin" style="font-size:.78rem"></i> Waiting for host…
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════ -->
<script>
const API       = 'play.php';
const ANS_ICONS = ['▲','●','◆','■'];

let gameId=null, sessionId=null, playerName='', score=0;
let pollInt=null, timerInt=null, timeLeft=0, timerStartMs=0;
let gameStatus='lobby', currentQ=-1, answered=false, lastQShown=-1;
let prevLbScores={}, lastMyRank=-1;

/* ─── ordinal helper ───────────────────────────────────────── */
function ordinal(n){
  const s=['th','st','nd','rd'];
  const v=n%100;
  return n+(s[(v-20)%10]||s[v]||s[0]);
}

/* ─── SIDEBAR TOGGLE ───────────────────────────────────────── */
function toggleSidebar(){
  document.getElementById('liveSidebar').classList.toggle('visible');
  document.getElementById('sidebarOverlay').classList.toggle('show');
}

/* ─── UPDATE SIDEBAR + MOBILE RANK BANNER ─────────────────── */
function updateRankUI(lb){
  if(!lb||lb.length===0) return;

  /* ── sidebar ── */
  const total     = lb.length;
  const countChip = document.getElementById('sidebarCountChip');
  const list      = document.getElementById('sidebarLbList');
  countChip.textContent = total+' player'+(total===1?'':'s');

  const medals=['🥇','🥈','🥉'];
  list.innerHTML = lb.map((p,i)=>{
    const isMe = (p.player_name===playerName);
    const rc   = i===0?'rank-1':i===1?'rank-2':i===2?'rank-3':'';
    const pts  = parseInt(p.score)||0;
    const prev = prevLbScores[p.player_name]??pts;
    const diff = pts-prev;
    prevLbScores[p.player_name]=pts;
    const deltaHtml = diff>0?`<span class="lb-delta show up">+${diff}</span>`:'';
    return `<div class="lb-item ${rc}${isMe?' is-me':''}"
                 style="animation-delay:${i*.04}s">
      <div class="lb-rank-num">${i<3?medals[i]:i+1}</div>
      <div class="lb-avatar">${p.player_name.charAt(0).toUpperCase()}</div>
      <div class="lb-info">
        <div class="lb-name">${esc(p.player_name)}${isMe?'<span class="lb-you-chip">You</span>':''}</div>
        <div class="lb-pts">${pts.toLocaleString()} pts ${deltaHtml}</div>
      </div>
    </div>`;
  }).join('');

  /* ── mobile rank banner ── */
  const myIdx = lb.findIndex(p=>p.player_name===playerName);
  if(myIdx<0) return;

  const rank      = myIdx+1;
  const badge     = document.getElementById('mrbBadge');
  const text      = document.getElementById('mrbText');
  const scorePill = document.getElementById('mrbScore');

  /* badge styling */
  badge.className = 'mrb-rank-badge'+(rank===1?' g1':rank===2?' g2':rank===3?' g3':'');
  badge.textContent = rank<=3 ? ['🥇','🥈','🥉'][rank-1] : '#'+rank;

  /* descriptive text: "You are #3 of 7" style */
  let msg;
  if(total===1){
    msg = `You're the <span class="hi">only player</span>`;
  } else if(rank===1){
    msg = `<span class="hi">1st of ${total}</span> — You're leading! 🔥`;
  } else if(rank===total){
    msg = `#${rank} of <span class="hi">${total}</span> — Keep going!`;
  } else {
    msg = `<span class="hi">${ordinal(rank)}</span> of <span class="hi">${total}</span> players`;
  }
  text.innerHTML = msg;
  scorePill.textContent = (parseInt(score)||0).toLocaleString()+' pts';

  /* bounce badge when rank changes */
  if(lastMyRank!==-1 && lastMyRank!==rank){
    badge.classList.remove('rank-pop');
    void badge.offsetWidth;
    badge.classList.add('rank-pop');
    badge.addEventListener('animationend',()=>badge.classList.remove('rank-pop'),{once:true});
  }
  lastMyRank=rank;
}

/* ─── SCREEN SWITCHER ──────────────────────────────────────── */
function showScreen(id){
  ['screenJoin','screenWaiting','screenGame','screenFinal'].forEach(s=>{
    document.getElementById(s).classList.toggle('hidden',s!==id);
  });

  const inGame = ['screenWaiting','screenGame','screenFinal'].includes(id);
  document.getElementById('sidebarToggleBtn').style.display = inGame?'flex':'none';

  /* mobile rank bar: only on game screen */
  const mrb = document.getElementById('mobileRankBar');
  if(mrb) mrb.style.display = (id==='screenGame')?'flex':'none';
}

/* ─── INIT ─────────────────────────────────────────────────── */
window.addEventListener('DOMContentLoaded',()=>{
  const pi=document.getElementById('pinInput');
  pi.addEventListener('input',e=>{e.target.value=e.target.value.replace(/\D/g,'').slice(0,6);});
  pi.addEventListener('keydown',e=>{if(e.key==='Enter')handlePinNext();});
  document.getElementById('nameInput').addEventListener('keydown',e=>{if(e.key==='Enter')handleJoin();});
  pi.focus();
});

/* ─── JOIN FLOW ────────────────────────────────────────────── */
function handlePinNext(){
  const v=document.getElementById('pinInput').value.trim();
  if(!/^\d{6}$/.test(v)){shake(document.getElementById('pinInput'));return;}
  document.getElementById('stepPin').classList.remove('active');
  document.getElementById('stepName').classList.add('active');
  document.getElementById('joinStepLbl').textContent  ='Step 2 of 2';
  document.getElementById('joinStepTitle').textContent='Choose your nickname';
  setTimeout(()=>document.getElementById('nameInput').focus(),50);
}
function goBackToPin(){
  document.getElementById('stepName').classList.remove('active');
  document.getElementById('stepPin').classList.add('active');
  document.getElementById('joinStepLbl').textContent  ='Step 1 of 2';
  document.getElementById('joinStepTitle').textContent='Enter Game PIN';
  document.getElementById('nameError').style.display  ='none';
  setTimeout(()=>document.getElementById('pinInput').focus(),50);
}
async function handleJoin(){
  const pin =document.getElementById('pinInput').value.trim();
  const name=document.getElementById('nameInput').value.trim();
  if(!name){shake(document.getElementById('nameInput'));return;}
  const btn=document.getElementById('joinBtn');
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Joining…';
  const fd=new FormData();
  fd.append('action','join');fd.append('pin',pin);fd.append('player_name',name);
  try{
    const r=await fetch(API,{method:'POST',body:fd});
    if(!r.ok) throw new Error('Server error '+r.status);
    const d=await r.json();
    if(!d.ok){
      showErr('nameError','nameErrorText',d.error||'Could not join. Check the PIN.');
      btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-rocket"></i> Let\'s Go!';
      return;
    }
    gameId=d.game_id;sessionId=d.session_id;playerName=name;
    document.getElementById('waitingTitle').textContent  =d.title||'Game';
    document.getElementById('waitingName').textContent   =name;
    document.getElementById('waitingQCount').textContent =d.q_count;
    document.getElementById('waitingCat').textContent    =d.category||'General';
    showScreen('screenWaiting');
    startPolling();
  }catch(e){
    showErr('nameError','nameErrorText','Error: '+e.message);
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-rocket"></i> Let\'s Go!';
  }
}
function showErr(wid,tid,msg){
  document.getElementById(tid).textContent=msg;
  document.getElementById(wid).style.display='flex';
}
function shake(el){
  el.style.borderColor='var(--coral)';
  el.animate([{transform:'translateX(-6px)'},{transform:'translateX(6px)'},{transform:'translateX(-4px)'},{transform:'translateX(4px)'},{transform:'translateX(0)'}],{duration:300})
    .onfinish=()=>setTimeout(()=>el.style.borderColor='',1200);
}

/* ─── POLLING ──────────────────────────────────────────────── */
function startPolling(){poll();pollInt=setInterval(poll,1500);}

async function poll(){
  if(!gameId||!sessionId) return;
  try{
    const r=await fetch(`${API}?action=get_state&game_id=${gameId}&session_id=${sessionId}`);
    if(!r.ok) return;
    const d=await r.json();
    if(!d.ok) return;
    score=d.score;
    document.getElementById('scoreDisplay').textContent=score.toLocaleString();

    fetchAndUpdateLb(); /* always refresh rank UI */

    if(d.status==='ended'){clearInterval(pollInt);showFinal();return;}
    if(d.status==='question'&&d.current_q!==lastQShown){
      lastQShown=d.current_q;currentQ=d.current_q;
      answered=!!d.already_answered;
      showQuestion(d.question,d.current_q,d.q_count);
      gameStatus='question';
      if(answered) document.getElementById('waitingHostMsg').classList.add('show');
    }
    if(d.status==='results'&&gameStatus!=='results'){
      gameStatus='results';
      if(d.correct_answers) revealResults(d.correct_answers,d.player_answer);
    }
  }catch(e){}
}

async function fetchAndUpdateLb(){
  if(!gameId) return;
  try{
    const r=await fetch(`${API}?action=get_results&game_id=${gameId}`);
    const d=await r.json();
    if(d.ok&&d.leaderboard) updateRankUI(d.leaderboard);
  }catch(e){}
}

/* ─── QUESTION ─────────────────────────────────────────────── */
function showQuestion(q,idx,total){
  clearInterval(timerInt);
  document.getElementById('resultOverlay').classList.remove('show');
  document.getElementById('waitingHostMsg').classList.remove('show');
  showScreen('screenGame');
  document.getElementById('qNum').textContent  =idx+1;
  document.getElementById('qTotal').textContent=total;
  document.getElementById('progressFill').style.width=((idx+1)/total*100)+'%';
  const img=document.getElementById('qImg');
  if(q.image){img.src=q.image;img.style.display='block';}else{img.src='';img.style.display='none';}
  document.getElementById('qText').textContent=q.text||'Question';
  document.getElementById('ansGrid').innerHTML=q.answers.map((a,i)=>`
    <button class="ans-btn" data-idx="${i}" onclick="selectAnswer(${i})">
      <span class="ans-icon">${ANS_ICONS[i]}</span>
      <span>${esc(a.text||'(empty)')}</span>
    </button>`).join('');
  startTimer(q.timeLimit||20);
  timerStartMs=Date.now();
}
function startTimer(secs){
  clearInterval(timerInt);timeLeft=secs;
  const circ=document.getElementById('timerCircle'),numEl=document.getElementById('timerNum');
  const C=113.1;circ.classList.remove('urgent');
  function tick(){
    numEl.textContent=timeLeft;
    circ.style.strokeDashoffset=C*(1-timeLeft/secs);
    if(timeLeft<=5) circ.classList.add('urgent');
    if(timeLeft<=0) clearInterval(timerInt);
    timeLeft--;
  }
  tick();timerInt=setInterval(tick,1000);
}

/* ─── ANSWER ───────────────────────────────────────────────── */
async function selectAnswer(idx){
  if(answered) return;
  answered=true;clearInterval(timerInt);
  document.querySelectorAll('.ans-btn').forEach(b=>b.disabled=true);
  const timeTaken=((Date.now()-timerStartMs)/1000).toFixed(2);
  const fd=new FormData();
  fd.append('action','submit_answer');fd.append('game_id',gameId);
  fd.append('session_id',sessionId);fd.append('q_index',currentQ);
  fd.append('ans_index',idx);fd.append('time_taken',timeTaken);
  try{
    const r=await fetch(API,{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      score=d.total_score;
      document.getElementById('scoreDisplay').textContent=score.toLocaleString();
      const btn=document.querySelector(`.ans-btn[data-idx="${idx}"]`);
      if(btn) btn.classList.add(d.correct?'answered-correct':'answered-wrong');
    }
  }catch(e){}
  document.getElementById('waitingHostMsg').classList.add('show');
}

/* ─── RESULTS ──────────────────────────────────────────────── */
function revealResults(correctArr,playerAnswer){
  document.querySelectorAll('.ans-btn').forEach((b,i)=>{
    b.disabled=true;
    if(correctArr[i]) b.classList.add('reveal-correct');
    else if(!b.classList.contains('answered-correct')&&!b.classList.contains('answered-wrong'))
      b.classList.add('reveal-wrong');
  });
  const wasCorrect=playerAnswer&&playerAnswer.correct;
  const pts=playerAnswer?playerAnswer.pts:0;
  document.getElementById('resultEmoji').textContent  =playerAnswer?(wasCorrect?'✅':'❌'):'⏱️';
  document.getElementById('resultStatus').textContent =playerAnswer?(wasCorrect?'Correct!':'Wrong!'):"Time's up!";
  document.getElementById('resultPtsText').innerHTML  =pts>0?`+<strong>${pts.toLocaleString()}</strong> points`:'No points this round';
  document.getElementById('resultTotal').textContent  =score.toLocaleString();
  document.getElementById('waitingHostMsg').classList.remove('show');
  document.getElementById('resultOverlay').classList.add('show');
}

/* ─── FINAL ────────────────────────────────────────────────── */
async function showFinal(){
  clearInterval(timerInt);
  document.getElementById('resultOverlay').classList.remove('show');
  document.getElementById('myFinalScore').textContent=score.toLocaleString();
  try{
    const r=await fetch(`${API}?action=get_results&game_id=${gameId}`);
    const d=await r.json();
    if(d.ok){renderFinal(d.leaderboard);updateRankUI(d.leaderboard);}
  }catch(e){}
  showScreen('screenFinal');
  launchConfetti();
}
function renderFinal(lb){
  const top3=lb.slice(0,3);
  const order=[
    {p:top3[1],cls:'p2',crown:'🥈',n:2},
    {p:top3[0],cls:'p1',crown:'👑',n:1},
    {p:top3[2],cls:'p3',crown:'🥉',n:3}
  ].filter(o=>o.p);
  document.getElementById('podiumStage').innerHTML=order.map(o=>`
    <div class="podium-col ${o.cls}">
      <div class="podium-av"><span class="crown">${o.crown}</span>${o.p.player_name.charAt(0).toUpperCase()}</div>
      <div class="podium-name">${esc(o.p.player_name)}</div>
      <div class="podium-pts">${parseInt(o.p.score).toLocaleString()}</div>
      <div class="podium-bar">${o.n}</div>
    </div>`).join('');

  const myPos=lb.findIndex(p=>p.player_name===playerName&&parseInt(p.score)===score);
  document.getElementById('finalTrophy').textContent=myPos===0?'🏆':myPos<=2?'🥈':myPos<=4?'🌟':'🎮';
  document.getElementById('finalSub').textContent   =myPos===0?'You won! 🎉':myPos>=0?`You placed #${myPos+1}`:'Great game!';

  document.getElementById('lbRows').innerHTML=lb.map((p,i)=>{
    const isMe=(p.player_name===playerName);
    const rc  =i===0?'top1':i===1?'top2':i===2?'top3':'';
    return`<div class="f-lb-row ${rc}${isMe?' is-me':''}">
      <div class="flb-rank">${i+1}</div>
      <div class="flb-name">${esc(p.player_name)}${isMe?'<span class="flb-me">(You)</span>':''}</div>
      <div class="flb-pts">${parseInt(p.score).toLocaleString()}</div>
    </div>`;
  }).join('');
}
function launchConfetti(){
  const wrap  =document.getElementById('confettiWrap');
  const colors=['#f59e0b','#f43f5e','#7c3aed','#06b6d4','#22c55e','#3b82f6'];
  for(let i=0;i<130;i++){
    const el=document.createElement('div');el.className='confetti-piece';
    el.style.cssText=`left:${Math.random()*100}%;top:-10px;`
      +`width:${6+Math.random()*8}px;height:${10+Math.random()*10}px;`
      +`background:${colors[Math.floor(Math.random()*colors.length)]};`
      +`border-radius:${Math.random()>.5?'50%':'2px'};`
      +`animation-duration:${2+Math.random()*2.5}s;`
      +`animation-delay:${Math.random()*1.5}s;`;
    wrap.appendChild(el);
  }
  setTimeout(()=>{wrap.innerHTML='';},6000);
}
function esc(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
</script>
</body>
</html>