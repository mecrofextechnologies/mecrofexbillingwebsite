<?php
// shared_challenge.php - Public view (no login required) - Enhanced & Mobile-Friendly

date_default_timezone_set('Africa/Nairobi');
include 'includes/connect.php';

// Get challenge ID from URL
$challenge_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$error = null;
$challenge = null;
$creator_name = 'a fellow challenger';
$current_day = 1;
$days_completed = 0;
$percentage = 0;
$progress = [];
$views = 0;
$participants_count = 0;

if ($challenge_id <= 0) {
    $error = "Invalid challenge link.";
} else {
    // Increment view count
    $pdo->prepare("UPDATE challenges SET views = views + 1 WHERE id = ?")->execute([$challenge_id]);

    // Fetch challenge + creator + views
    $stmt = $pdo->prepare("
        SELECT 
            c.id, c.title, c.description, c.duration, c.start_date, c.end_date, c.status, c.views,
            cr.full_name, cr.username
        FROM challenges c
        JOIN creators cr ON c.creator_id = cr.id
        WHERE c.id = ? AND c.status IN ('active', 'completed')
    ");
    $stmt->execute([$challenge_id]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$challenge) {
        $error = "Challenge not found or no longer active.";
    } else {
        $creator_name = !empty($challenge['full_name']) ? htmlspecialchars($challenge['full_name']) : 'a fellow challenger';
        $views = (int)$challenge['views'];

        // Current day
        $today = date('Y-m-d');
        $start_ts = strtotime($challenge['start_date']);
        $today_ts = strtotime($today);
        $days_passed = floor(($today_ts - $start_ts) / 86400) + 1;
        $current_day = max(1, min($days_passed, $challenge['duration']));

        // Progress
        $stmt = $pdo->prepare("
            SELECT day, completed
            FROM challenge_progress
            WHERE challenge_id = ?
            ORDER BY day ASC
        ");
        $stmt->execute([$challenge_id]);
        $progress_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($progress_rows as $row) {
            $progress[$row['day']] = $row['completed'];
        }

        $days_completed = count(array_filter($progress));
        $percentage = $challenge['duration'] > 0 ? round(($days_completed / $challenge['duration']) * 100, 1) : 0;

        // Correct count: distinct creators with at least 1 completed day (across any challenge)
        $stmt_part = $pdo->prepare("
            SELECT COUNT(DISTINCT c.creator_id)
            FROM challenge_progress cp
            JOIN challenges c ON cp.challenge_id = c.id
            WHERE cp.completed = 1
        ");
        $stmt_part->execute();
        $participants_count = $stmt_part->fetchColumn() ?? 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Shared Challenge | Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <style>
    .float-ball {
      animation: float 10s ease-in-out infinite;
      opacity: 0.7;
      mix-blend-mode: screen;
    }
    @keyframes float {
      0%, 100% { transform: translateY(0) rotate(0deg) scale(1); }
      50% { transform: translateY(-60px) rotate(180deg) scale(1.2); }
    }
    .calendar-scroll {
      overflow-x: auto;
      scroll-snap-type: x mandatory;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: thin;
      scrollbar-color: #a78bfa #e5e7eb;
    }
    .calendar-scroll::-webkit-scrollbar {
      height: 8px;
    }
    .calendar-scroll::-webkit-scrollbar-thumb {
      background: #a78bfa;
      border-radius: 4px;
    }
    .glass {
      background: rgba(255, 255, 255, 0.8);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.3);
    }
    .hover-lift {
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .hover-lift:hover {
      transform: translateY(-5px);
      box-shadow: 0 30px 60px rgba(167, 139, 250, 0.2);
    }
  </style>
</head>
<body class="bg-gradient-to-br from-purple-50 via-indigo-50 to-blue-50 min-h-screen relative overflow-x-hidden">

<!-- Floating animated balls -->
<div class="absolute inset-0 pointer-events-none z-0">
  <div class="float-ball absolute top-10 left-10 w-40 h-40 bg-purple-400/30 rounded-full blur-3xl"></div>
  <div class="float-ball absolute bottom-20 right-10 w-56 h-56 bg-indigo-400/25 rounded-full blur-3xl" style="animation-delay: 2s;"></div>
  <div class="float-ball absolute top-1/3 left-1/4 w-72 h-72 bg-blue-400/20 rounded-full blur-3xl" style="animation-delay: 4s;"></div>
</div>

<div class="relative z-10 container mx-auto px-4 sm:px-6 py-10 md:py-16 max-w-6xl">

  <?php if (isset($error)): ?>
    <div class="glass rounded-3xl shadow-2xl p-10 md:p-16 text-center hover-lift">
      <i class="fa-solid fa-link-slash text-8xl text-red-400 mb-8 animate-pulse"></i>
      <h1 class="text-4xl md:text-5xl font-extrabold text-gray-800 mb-6">Oops...</h1>
      <p class="text-xl md:text-2xl text-gray-600 mb-10"><?= htmlspecialchars($error) ?></p>
      <a href="index.php?page=challenges" 
         class="inline-flex items-center px-10 py-5 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white text-xl md:text-2xl font-bold rounded-2xl shadow-xl transition transform hover:scale-105">
        <i class="fa-solid fa-trophy mr-4"></i> Explore All Challenges
      </a>
    </div>

  <?php else: ?>

    <!-- Hero / Intro -->
    <div class="glass rounded-3xl shadow-2xl p-8 md:p-12 mb-12 md:mb-16 text-center hover-lift">
      <h1 class="text-4xl sm:text-5xl md:text-6xl font-extrabold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent mb-6 md:mb-8 leading-tight">
        Hey there! 👋
      </h1>
      <p class="text-xl sm:text-2xl md:text-3xl text-gray-700 leading-relaxed max-w-4xl mx-auto">
        This is <strong class="font-extrabold text-purple-700"><?= $creator_name ?></strong>'s 
        <span class="block md:inline font-extrabold text-indigo-700 mt-3 md:mt-0">
          Day <?= $current_day ?> of <?= $challenge['duration'] ?>
        </span>
        <br class="block md:hidden">
        <span class="text-3xl sm:text-4xl md:text-5xl font-extrabold bg-gradient-to-r from-purple-600 to-indigo-600 bg-clip-text text-transparent mt-4 md:mt-0 block">
          "<?= htmlspecialchars($challenge['title']) ?>"
        </span>
      </p>
      <p class="text-lg sm:text-xl md:text-2xl text-gray-600 mt-8 max-w-3xl mx-auto">
        Help <?= $creator_name ?> stay strong and crush this goal — every view and cheer gives real motivation! 💪🔥
      </p>
      <p class="text-base md:text-lg text-gray-500 mt-6">
        Viewed by <strong class="text-purple-700"><?= number_format($views) ?></strong> people so far
      </p>
    </div>

    <!-- Description -->
    <div class="glass rounded-3xl shadow-2xl p-8 md:p-12 mb-12 md:mb-16 hover-lift">
      <h3 class="text-2xl md:text-3xl font-extrabold text-gray-800 mb-6">About This Challenge</h3>
      <p class="text-lg md:text-xl text-gray-700 leading-relaxed">
        <?= nl2br(htmlspecialchars($challenge['description'] ?? 'No description provided yet — but the journey is real!')) ?>
      </p>
    </div>

    <!-- Progress Stats -->
    <div class="glass rounded-3xl shadow-2xl p-8 md:p-12 mb-12 md:mb-16 hover-lift">
      <h3 class="text-2xl md:text-3xl font-extrabold text-gray-800 mb-8 text-center">Current Progress</h3>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-8 md:gap-12 mb-10 md:mb-12 text-center">
        <div>
          <div class="text-6xl md:text-7xl font-extrabold text-purple-600 mb-2"><?= $days_completed ?></div>
          <p class="text-lg md:text-xl text-gray-600 font-medium">Days Completed</p>
        </div>
        <div>
          <div class="text-6xl md:text-7xl font-extrabold text-indigo-600 mb-2"><?= $percentage ?>%</div>
          <p class="text-lg md:text-xl text-gray-600 font-medium">Progress</p>
        </div>
        <div>
          <div class="text-6xl md:text-7xl font-extrabold text-emerald-600 mb-2">Day <?= $current_day ?></div>
          <p class="text-lg md:text-xl text-gray-600 font-medium">Today</p>
        </div>
      </div>

      <!-- Big Progress Bar -->
      <div class="mb-10 md:mb-12">
        <div class="w-full bg-gray-200/60 rounded-full h-5 md:h-6 overflow-hidden shadow-inner">
          <div class="bg-gradient-to-r from-purple-500 via-indigo-500 to-blue-500 h-5 md:h-6 rounded-full transition-all duration-1000 ease-out"
               style="width: <?= $percentage ?>%"></div>
        </div>
      </div>

      <!-- Calendar Grid - Scrollable on mobile -->
      <h3 class="text-2xl md:text-3xl font-extrabold text-gray-800 mb-6 md:mb-8 text-center">Progress Calendar</h3>
      <div class="calendar-scroll snap-x snap-mandatory overflow-x-auto pb-6 md:pb-8">
        <div class="grid grid-cols-7 gap-2 md:gap-4 min-w-max">
          <?php for ($day = 1; $day <= $challenge['duration']; $day++): 
            $is_completed = isset($progress[$day]) && $progress[$day];
            $is_today     = ($day === $current_day);
            $is_future    = ($day > $current_day);
            $bg = $is_completed ? 'bg-green-100 border-2 border-green-400 shadow-md' : 
                  ($is_today     ? 'bg-indigo-100 border-2 border-indigo-500 ring-2 ring-indigo-300 animate-pulse shadow-md' : 
                  ($is_future    ? 'bg-gray-100 text-gray-400 shadow-sm' : 'bg-red-50 border border-red-200 opacity-70 shadow-sm'));
          ?>
            <div class="snap-start aspect-square rounded-2xl flex flex-col items-center justify-center text-base md:text-lg font-medium <?= $bg ?> hover:scale-105 transition-transform">
              <span class="font-extrabold"><?= $day ?></span>
              <?php if ($is_completed): ?>
                <i class="fa-solid fa-check text-green-600 mt-1 text-xl md:text-2xl"></i>
              <?php elseif ($is_today): ?>
                <i class="fa-solid fa-star text-indigo-600 mt-1 text-xl md:text-2xl animate-bounce"></i>
              <?php endif; ?>
            </div>
          <?php endfor; ?>
        </div>
      </div>
      <p class="text-center text-sm md:text-base text-gray-600 mt-6 md:mt-8 font-medium">
        Green = Completed • Indigo = Today • Gray = Future • Light red = Missed
      </p>
    </div>

    <!-- Call to Action -->
    <div class="glass rounded-3xl shadow-2xl p-10 md:p-16 text-center hover-lift">
      <h2 class="text-3xl md:text-4xl font-extrabold text-gray-800 mb-6 md:mb-8">
        Inspired by <?= $creator_name ?>'s Journey?
      </h2>
      <p class="text-lg md:text-xl text-gray-700 mb-8 md:mb-10 max-w-4xl mx-auto leading-relaxed">
        Want to participate and start your own challenge? Register now, create your goal, and join thousands transforming their lives — just like this one!
      </p>
      <a href="index.php?page=register" 
         class="inline-flex items-center px-12 py-6 md:py-8 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white text-2xl md:text-3xl font-extrabold rounded-2xl shadow-2xl transition transform hover:scale-105">
        <i class="fa-solid fa-user-plus mr-4 md:mr-6 text-3xl md:text-4xl"></i> Register & Start Now
      </a>
      <p class="mt-6 md:mt-8 text-gray-600 text-lg">
        It's free, quick, and life-changing.
      </p>
    </div>

  <?php endif; ?>

</div>

<!-- Preloader fade -->
<script>
window.addEventListener("load", () => {
    document.getElementById("preloader").style.opacity = "0";
    setTimeout(() => { document.getElementById("preloader").style.display = "none"; }, 400);
});
</script>

</body>
</html>