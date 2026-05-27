<?php
// This file can be: index.php?page=challenge_progress
// or kept as challenge_progress.php (depending on your setup)
// but all links now point to index.php?page=...

date_default_timezone_set('Africa/Nairobi');
include 'includes/connect.php';

// Start session (important for authentication)
session_start();

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit;
}

$challenge = null;
$progress = [];
$current_day = 0;
$days_completed = 0;
$percentage = 0;
$start_date = $end_date = $today = date('Y-m-d');
$error = "";

try {
    $stmt = $pdo->prepare("
        SELECT id, title, description, duration, start_date, end_date, status, created_at
        FROM challenges
        WHERE creator_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$creator_id]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($challenge) {
        $start_date = $challenge['start_date'];
        $end_date   = $challenge['end_date'];
        $duration   = (int)$challenge['duration'];

        $start_ts   = strtotime($start_date);
        $today_ts   = strtotime($today);

        if ($today_ts < $start_ts) {
            $current_day = 0;
        } else {
            $days_passed = floor(($today_ts - $start_ts) / 86400) + 1;
            $current_day = min($days_passed, $duration);
        }

        $stmt = $pdo->prepare("
            SELECT day, completed, marked_at
            FROM challenge_progress
            WHERE challenge_id = ?
            ORDER BY day ASC
        ");
        $stmt->execute([$challenge['id']]);
        $progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($progress as $row) {
            if ($row['completed']) $days_completed++;
        }

        $percentage = $duration > 0 ? round(($days_completed / $duration) * 100, 1) : 0;
    }
} catch (PDOException $e) {
    $error = "Error loading progress: " . htmlspecialchars($e->getMessage());
}

// Check if today is completed
$today_completed = false;
if ($current_day > 0) {
    foreach ($progress as $p) {
        if ($p['day'] == $current_day && $p['completed']) {
            $today_completed = true;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Challenge Progress - Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
</head>
<body class="bg-gray-50 min-h-screen">

<div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm">
  <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
    <div class="h-5 w-5 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
    <span class="text-gray-700 font-medium">Loading your journey...</span>
  </div>
</div>

<div class="p-4 md:p-6 max-w-5xl mx-auto">

  <div class="bg-gradient-to-r from-indigo-600 to-purple-600 p-6 md:p-8 rounded-2xl shadow-lg text-white mb-8">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
      <div>
        <h1 class="text-2xl md:text-3xl font-bold">My Challenge Progress</h1>
        <p class="opacity-90 mt-1">Stay accountable • Track daily wins</p>
      </div>
      <i class="fa-solid fa-trophy text-5xl md:text-6xl opacity-80"></i>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-xl mb-6"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (!$challenge): ?>
    <div class="bg-white rounded-2xl shadow-md p-10 text-center">
      <i class="fa-solid fa-face-smile-wink text-7xl text-yellow-500 mb-6 block"></i>
      <h2 class="text-2xl font-bold text-gray-800 mb-4">No Active Challenge Yet</h2>
      <p class="text-gray-600 mb-8">Create a challenge to start building momentum!</p>
      <a href="index.php?page=create_challenge" 
         class="inline-flex items-center px-8 py-4 bg-purple-600 text-white font-semibold rounded-xl hover:bg-purple-700 transition">
        <i class="fa-solid fa-plus mr-3"></i> Start New Challenge
      </a>
    </div>

  <?php else: ?>

    <div class="space-y-8">

      <!-- Main Challenge Card -->
      <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
        <div class="bg-gradient-to-r from-purple-700 to-indigo-700 px-6 py-6 text-white">
          <h2 class="text-2xl md:text-3xl font-bold"><?= htmlspecialchars($challenge['title']) ?></h2>
          <?php if (!empty($challenge['description'])): ?>
            <p class="mt-3 opacity-90 text-lg"><?= nl2br(htmlspecialchars($challenge['description'])) ?></p>
          <?php endif; ?>
        </div>

        <div class="p-6 md:p-8">

          <div class="grid grid-cols-2 md:grid-cols-4 gap-5 mb-10">
            <div class="bg-purple-50 p-5 rounded-xl text-center">
              <p class="text-sm text-purple-700">Duration</p>
              <p class="text-3xl font-bold text-purple-800"><?= $challenge['duration'] ?> days</p>
            </div>
            <div class="bg-purple-50 p-5 rounded-xl text-center">
              <p class="text-sm text-purple-700">Started</p>
              <p class="text-xl font-bold"><?= date('M d, Y', strtotime($start_date)) ?></p>
            </div>
            <div class="bg-purple-50 p-5 rounded-xl text-center">
              <p class="text-sm text-purple-700">Ends</p>
              <p class="text-xl font-bold"><?= date('M d, Y', strtotime($end_date)) ?></p>
            </div>
            <div class="bg-indigo-50 p-5 rounded-xl text-center">
              <p class="text-sm text-indigo-700">Today</p>
              <p class="text-xl font-bold text-indigo-700"><?= date('M d') ?></p>
            </div>
          </div>

          <!-- Progress Bar -->
          <div class="mb-10">
            <div class="flex justify-between items-center mb-3">
              <h3 class="text-xl font-semibold text-gray-800">Your Progress</h3>
              <span class="text-2xl font-bold text-emerald-600"><?= $percentage ?>%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-5">
              <div class="bg-gradient-to-r from-emerald-500 to-teal-500 h-5 rounded-full transition-all duration-1000 ease-out"
                   style="width: <?= $percentage ?>%"></div>
            </div>
            <p class="text-center mt-3 text-gray-700 font-medium">
              <?= $days_completed ?> of <?= $challenge['duration'] ?> days completed
            </p>
          </div>

          <!-- Today's Action / Question -->
          <?php if ($current_day > 0 && $current_day <= $challenge['duration']): ?>
            <div class="bg-gradient-to-br from-indigo-50 to-purple-50 border border-indigo-200 rounded-2xl p-6 md:p-8 mb-10">
              <div class="text-center md:text-left">
                <h3 class="text-2xl font-bold text-indigo-800 mb-4">Day <?= $current_day ?> – Today</h3>

                <?php if ($today_completed): ?>
                  <div class="bg-green-100 border border-green-300 rounded-xl p-6 text-center">
                    <i class="fa-solid fa-circle-check text-6xl text-green-600 mb-4"></i>
                    <h4 class="text-xl font-bold text-green-800">Completed! Great job today! 🎉</h4>
                    <p class="mt-3 text-green-700">
                      Marked on <?= date('M d, Y h:i A', strtotime($today_progress['marked_at'] ?? 'now')) ?>
                    </p>
                  </div>
                <?php else: ?>
                  <p class="text-xl text-gray-800 mb-6">
                    <strong>Did you achieve today's challenge?</strong><br>
                    <span class="text-lg text-gray-700">Be honest — this helps you build real discipline.</span>
                  </p>

                  <p class="text-sm text-amber-700 mb-6">
                    <i class="fa-solid fa-bell mr-2"></i>
                    Mark after completing the task.<br>
                    Reminder email sent ~10 PM EAT if not marked.
                  </p>

                  <a href="index.php?page=mark_progress&day=<?= $current_day ?>"
                     class="inline-flex items-center px-10 py-5 bg-green-600 hover:bg-green-700 text-white text-xl font-bold rounded-xl shadow-lg transition transform hover:scale-105">
                    <i class="fa-solid fa-check mr-3"></i>
                    YES — I Completed Day <?= $current_day ?>
                  </a>
                <?php endif; ?>
              </div>
            </div>
          <?php elseif ($current_day == 0): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-8 text-center mb-10">
              <i class="fa-solid fa-calendar-day text-6xl text-amber-600 mb-6"></i>
              <h3 class="text-2xl font-bold text-amber-800 mb-4">
                Challenge Starts <?= date('M d, Y', strtotime($start_date)) ?>
              </h3>
              <p class="text-lg text-amber-700">Get ready — your journey begins soon!</p>
            </div>
          <?php endif; ?>

          <!-- Calendar Grid -->
          <h3 class="text-xl font-semibold text-gray-800 mb-5">Daily Overview</h3>
          <div class="grid grid-cols-7 sm:grid-cols-10 lg:grid-cols-14 gap-2.5">
            <?php for ($day = 1; $day <= $challenge['duration']; $day++):
              $is_completed = false;
              foreach ($progress as $p) {
                if ($p['day'] == $day && $p['completed']) {
                  $is_completed = true;
                  break;
                }
              }
              $is_today  = ($day == $current_day);
              $is_future = ($day > $current_day);
              $is_missed = (!$is_completed && !$is_today && !$is_future);
            ?>
              <div class="aspect-square rounded-lg flex flex-col items-center justify-center text-sm font-medium shadow-sm
                          <?php
                            if ($is_completed)     echo 'bg-green-100 border-2 border-green-400 text-green-800';
                            elseif ($is_today)     echo 'bg-indigo-100 border-2 border-indigo-500 text-indigo-900 ring-2 ring-indigo-300';
                            elseif ($is_future)    echo 'bg-gray-100 text-gray-500';
                            elseif ($is_missed)    echo 'bg-red-50 border border-red-300 text-red-700 opacity-80';
                            else                   echo 'bg-gray-50';
                          ?>">
                <span class="font-bold text-base"><?= $day ?></span>
                <?php if ($is_completed): ?>
                  <i class="fa-solid fa-check text-green-600 mt-1"></i>
                <?php elseif ($is_today): ?>
                  <i class="fa-solid fa-star text-indigo-600 mt-1"></i>
                <?php endif; ?>
              </div>
            <?php endfor; ?>
          </div>

          <p class="text-xs text-gray-500 mt-5 text-center">
            Green = Done • Indigo = Today • Gray = Upcoming • Light red = Missed
          </p>

        </div>
      </div>

      <!-- Quick Actions -->
      <div class="flex flex-col sm:flex-row gap-4 justify-center">
        <a href="index.php?page=mark_progress"
           class="flex-1 bg-purple-600 hover:bg-purple-700 text-white py-5 rounded-xl font-semibold text-center transition flex items-center justify-center gap-3 shadow-md">
          <i class="fa-solid fa-check-double text-xl"></i> Mark Progress
        </a>
        <a href="index.php?page=create_challenge"
           class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-800 py-5 rounded-xl font-semibold text-center transition flex items-center justify-center gap-3">
          <i class="fa-solid fa-plus"></i> Start New Challenge
        </a>
      </div>

    </div>
  <?php endif; ?>

</div>

<script>
window.addEventListener("load", () => {
    document.getElementById("preloader").style.opacity = "0";
    setTimeout(() => { document.getElementById("preloader").style.display = "none"; }, 400);
});
</script>

</body>
</html>