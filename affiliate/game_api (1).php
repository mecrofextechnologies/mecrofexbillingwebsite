<?php
/**
 * game_api.php — Real-time game state API
 * Actions: get_state, join, submit_answer, host_control, get_results
 */
include 'includes/connect.php';
header('Content-Type: application/json');

// Ensure game_state table exists with current_question tracking
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
    current_question INT          DEFAULT -1,
    play_count      INT UNSIGNED DEFAULT 0,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_creator (creator_id),
    INDEX idx_pin     (game_pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS game_sessions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id      INT UNSIGNED NOT NULL,
    player_name  VARCHAR(80)  NOT NULL,
    score        INT          DEFAULT 0,
    answers_json LONGTEXT     DEFAULT '[]',
    joined_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_game (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
ALTER TABLE creator_games
  MODIFY COLUMN status ENUM('draft','lobby','question','results','ended') DEFAULT 'draft'
" );

// Add current_question column if it doesn't exist
try {
    $pdo->exec("ALTER TABLE creator_games ADD COLUMN current_question INT DEFAULT -1");
} catch (Exception $e) { /* column already exists */ }

function jsonOut($data) {
    echo json_encode($data);
    exit;
}

$action  = $_REQUEST['action'] ?? '';
$game_id = (int)($_REQUEST['game_id'] ?? 0);

// ════════════════════════════════════════
// JOIN — player joins lobby
// ════════════════════════════════════════
if ($action === 'join') {
    $pin         = trim($_POST['pin'] ?? '');
    $player_name = trim($_POST['player_name'] ?? '');

    if (!preg_match('/^\d{6}$/', $pin) || empty($player_name)) {
        jsonOut(['ok' => false, 'error' => 'Invalid PIN or name']);
    }

    $gs = $pdo->prepare("SELECT id, title, category, questions_json, status, current_question FROM creator_games WHERE game_pin = ? AND status != 'ended' AND status != 'draft' LIMIT 1");
    $gs->execute([$pin]);
    $game = $gs->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        jsonOut(['ok' => false, 'error' => 'Game not found or not open. Check the PIN.']);
    }

    // Register player (upsert by name+game_id)
    $check = $pdo->prepare("SELECT id FROM game_sessions WHERE game_id = ? AND player_name = ? LIMIT 1");
    $check->execute([$game['id'], $player_name]);
    $session_id = $check->fetchColumn();

    if (!$session_id) {
        $ins = $pdo->prepare("INSERT INTO game_sessions (game_id, player_name, score, answers_json) VALUES (?,?,0,'[]')");
        $ins->execute([$game['id'], $player_name]);
        $session_id = $pdo->lastInsertId();
    }

    $questions = json_decode($game['questions_json'], true);
    jsonOut([
        'ok'          => true,
        'session_id'  => (int)$session_id,
        'game_id'     => (int)$game['id'],
        'title'       => $game['title'],
        'category'    => $game['category'],
        'q_count'     => count($questions),
        'status'      => $game['status'],
        'current_q'   => (int)$game['current_question'],
    ]);
}

// ════════════════════════════════════════
// GET STATE — player polls for game state
// ════════════════════════════════════════
if ($action === 'get_state') {
    $session_id = (int)($_GET['session_id'] ?? 0);
    if (!$game_id || !$session_id) jsonOut(['ok' => false]);

    $gs = $pdo->prepare("SELECT g.status, g.current_question, g.questions_json, s.score, s.answers_json
        FROM creator_games g
        JOIN game_sessions s ON s.game_id = g.id
        WHERE g.id = ? AND s.id = ?");
    $gs->execute([$game_id, $session_id]);
    $row = $gs->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonOut(['ok' => false, 'error' => 'Session not found']);

    $questions   = json_decode($row['questions_json'], true);
    $answers_log = json_decode($row['answers_json'], true) ?: [];
    $cur         = (int)$row['current_question'];
    $q_count     = count($questions);
    $already_answered = isset($answers_log[$cur]);

    $state = [
        'ok'               => true,
        'status'           => $row['status'],
        'current_q'        => $cur,
        'q_count'          => $q_count,
        'score'            => (int)$row['score'],
        'already_answered' => $already_answered,
    ];

    // If question phase, send the question data
    if ($row['status'] === 'question' && $cur >= 0 && $cur < $q_count) {
        $q = $questions[$cur];
        // Strip correct flags from answers before sending to player
        $safe_answers = array_map(fn($a) => ['text' => $a['text']], $q['answers'] ?? []);
        if ($q['type'] === 'true_false') {
            $safe_answers = [['text' => 'True'], ['text' => 'False']];
        }
        $state['question'] = [
            'text'      => $q['text'] ?? '',
            'type'      => $q['type'] ?? 'quiz',
            'timeLimit' => (int)($q['timeLimit'] ?? 20),
            'image'     => $q['image'] ?? null,
            'answers'   => array_slice($safe_answers, 0, 4),
        ];
    }

    // If results phase, reveal correct answers
    if ($row['status'] === 'results' && $cur >= 0 && $cur < $q_count) {
        $q = $questions[$cur];
        $answers = $q['answers'] ?? [];
        if ($q['type'] === 'true_false') {
            $answers = [
                ['text' => 'True',  'correct' => $answers[0]['correct'] ?? false],
                ['text' => 'False', 'correct' => $answers[1]['correct'] ?? false],
            ];
        }
        $state['correct_answers'] = array_map(fn($a) => (bool)($a['correct'] ?? false), array_slice($answers, 0, 4));
        $state['player_answer']   = $answers_log[$cur] ?? null;
    }

    jsonOut($state);
}

// ════════════════════════════════════════
// SUBMIT ANSWER — player submits answer
// ════════════════════════════════════════
if ($action === 'submit_answer') {
    $session_id = (int)($_POST['session_id'] ?? 0);
    $q_index    = (int)($_POST['q_index'] ?? 0);
    $ans_index  = (int)($_POST['ans_index'] ?? 0);
    $time_taken = (float)($_POST['time_taken'] ?? 0);

    if (!$game_id || !$session_id) jsonOut(['ok' => false]);

    // Get game + session
    $gs = $pdo->prepare("SELECT g.questions_json, g.current_question, g.status, s.score, s.answers_json
        FROM creator_games g JOIN game_sessions s ON s.game_id = g.id
        WHERE g.id = ? AND s.id = ? LIMIT 1");
    $gs->execute([$game_id, $session_id]);
    $row = $gs->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['status'] !== 'question' || (int)$row['current_question'] !== $q_index) {
        jsonOut(['ok' => false, 'error' => 'Not accepting answers right now']);
    }

    $questions   = json_decode($row['questions_json'], true);
    $answers_log = json_decode($row['answers_json'], true) ?: [];

    // Don't allow double-answering
    if (isset($answers_log[$q_index])) {
        jsonOut(['ok' => false, 'error' => 'Already answered']);
    }

    $q = $questions[$q_index];
    $answers = $q['answers'] ?? [];
    if ($q['type'] === 'true_false') {
        $answers = [
            ['text' => 'True',  'correct' => $answers[0]['correct'] ?? false],
            ['text' => 'False', 'correct' => $answers[1]['correct'] ?? false],
        ];
    }

    $is_correct = !empty($answers[$ans_index]['correct']);
    $time_limit = (int)($q['timeLimit'] ?? 20);
    $pts = 0;
    if ($is_correct) {
        $pts = max(500, round(1000 - ($time_taken / $time_limit) * 500));
    }

    $answers_log[$q_index] = ['ans' => $ans_index, 'correct' => $is_correct, 'pts' => $pts];
    $new_score = (int)$row['score'] + $pts;

    $upd = $pdo->prepare("UPDATE game_sessions SET score = ?, answers_json = ? WHERE id = ?");
    $upd->execute([$new_score, json_encode($answers_log), $session_id]);

    jsonOut(['ok' => true, 'correct' => $is_correct, 'pts' => $pts, 'total_score' => $new_score]);
}

// ════════════════════════════════════════
// HOST CONTROL — creator controls game
// ════════════════════════════════════════
if ($action === 'host_control') {
    $creator_id = $_SESSION['creator_id'] ?? null;
    if (!$creator_id) jsonOut(['ok' => false, 'error' => 'Not authenticated']);

    $command = $_POST['command'] ?? '';

    // Verify creator owns this game
    $chk = $pdo->prepare("SELECT id, questions_json, current_question, status FROM creator_games WHERE id = ? AND creator_id = ? LIMIT 1");
    $chk->execute([$game_id, $creator_id]);
    $game = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$game) jsonOut(['ok' => false, 'error' => 'Not found']);

    $questions = json_decode($game['questions_json'], true);
    $q_count   = count($questions);
    $cur       = (int)$game['current_question'];

    if ($command === 'start') {
        // Move from draft/lobby to first question
        $pdo->prepare("UPDATE creator_games SET status='lobby', current_question=-1 WHERE id=?")->execute([$game_id]);
        jsonOut(['ok' => true, 'status' => 'lobby']);
    }

    if ($command === 'next_question') {
        $next = $cur + 1;
        if ($next >= $q_count) {
            // End game
            $pdo->prepare("UPDATE creator_games SET status='ended', current_question=? WHERE id=?")->execute([$q_count, $game_id]);
            $pdo->prepare("UPDATE creator_games SET play_count = play_count + 1 WHERE id=?")->execute([$game_id]);
            jsonOut(['ok' => true, 'status' => 'ended']);
        } else {
            $pdo->prepare("UPDATE creator_games SET status='question', current_question=? WHERE id=?")->execute([$next, $game_id]);
            jsonOut(['ok' => true, 'status' => 'question', 'current_q' => $next]);
        }
    }

    if ($command === 'show_results') {
        // After time up or host clicks — show correct answers
        $pdo->prepare("UPDATE creator_games SET status='results' WHERE id=?")->execute([$game_id]);
        jsonOut(['ok' => true, 'status' => 'results']);
    }

    if ($command === 'end_game') {
        $pdo->prepare("UPDATE creator_games SET status='ended' WHERE id=?")->execute([$game_id]);
        jsonOut(['ok' => true, 'status' => 'ended']);
    }

    jsonOut(['ok' => false, 'error' => 'Unknown command']);
}

// ════════════════════════════════════════
// GET RESULTS — leaderboard + answer stats
// ════════════════════════════════════════
if ($action === 'get_results') {
    $creator_id = $_SESSION['creator_id'] ?? null;

    $gs = $pdo->prepare("SELECT g.status, g.current_question, g.questions_json,
        (SELECT COUNT(*) FROM game_sessions WHERE game_id = g.id) as player_count
        FROM creator_games g WHERE g.id = ?");
    $gs->execute([$game_id]);
    $game = $gs->fetch(PDO::FETCH_ASSOC);
    if (!$game) jsonOut(['ok' => false]);

    $questions = json_decode($game['questions_json'], true);
    $cur = (int)$game['current_question'];

    // Leaderboard
    $lb = $pdo->prepare("SELECT player_name, score FROM game_sessions WHERE game_id = ? ORDER BY score DESC LIMIT 20");
    $lb->execute([$game_id]);
    $leaderboard = $lb->fetchAll(PDO::FETCH_ASSOC);

    // Answer distribution for current question
    $answer_stats = [];
    if ($cur >= 0 && $cur < count($questions)) {
        $sessions = $pdo->prepare("SELECT answers_json FROM game_sessions WHERE game_id = ?");
        $sessions->execute([$game_id]);
        $all_sessions = $sessions->fetchAll(PDO::FETCH_COLUMN);
        $q = $questions[$cur];
        $ans_count = $q['type'] === 'true_false' ? 2 : min(4, count($q['answers']));
        $dist = array_fill(0, $ans_count, 0);
        $total_answered = 0;
        foreach ($all_sessions as $aj) {
            $log = json_decode($aj, true) ?: [];
            if (isset($log[$cur])) {
                $ai = $log[$cur]['ans'] ?? -1;
                if ($ai >= 0 && $ai < $ans_count) { $dist[$ai]++; $total_answered++; }
            }
        }
        $answer_stats = ['dist' => $dist, 'total' => $total_answered];
    }

    jsonOut([
        'ok'           => true,
        'status'       => $game['status'],
        'current_q'    => $cur,
        'q_count'      => count($questions),
        'player_count' => (int)$game['player_count'],
        'leaderboard'  => $leaderboard,
        'answer_stats' => $answer_stats,
    ]);
}

// ════════════════════════════════════════
// GET LOBBY PLAYERS — for waiting room
// ════════════════════════════════════════
if ($action === 'get_players') {
    if (!$game_id) jsonOut(['ok' => false]);
    $gs = $pdo->prepare("SELECT g.status, g.current_question, (SELECT COUNT(*) FROM game_sessions WHERE game_id=g.id) as cnt FROM creator_games g WHERE g.id=?");
    $gs->execute([$game_id]);
    $game = $gs->fetch(PDO::FETCH_ASSOC);

    $players = $pdo->prepare("SELECT player_name FROM game_sessions WHERE game_id=? ORDER BY joined_at DESC LIMIT 50");
    $players->execute([$game_id]);

    jsonOut([
        'ok'        => true,
        'status'    => $game['status'] ?? 'lobby',
        'current_q' => (int)($game['current_question'] ?? -1),
        'count'     => (int)($game['cnt'] ?? 0),
        'players'   => $players->fetchAll(PDO::FETCH_COLUMN),
    ]);
}

jsonOut(['ok' => false, 'error' => 'Unknown action']);
