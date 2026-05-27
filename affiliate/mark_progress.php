<?php
// mark_progress.php - Final rewritten version with fixes
// Last updated: Jan 2026 fixes for day calc, row existence, POST mismatch, null marked_at

date_default_timezone_set('Africa/Nairobi');
include 'includes/connect.php';

// Development debug (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit;
}

$challenge = null;
$today_progress = null;
$current_day = 0;
$success = $error = "";
$today = date('Y-m-d');
$current_hour = (int) date('H');

// CSRF protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch creator info
$creator_email = '';
$creator_name = 'Champion';
try {
    $stmt = $pdo->prepare("SELECT full_name, email FROM creators WHERE id = ?");
    $stmt->execute([$creator_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $creator_name = $user['full_name'] ?? 'Champion';
        $creator_email = $user['email'] ?? '';
    }
} catch (PDOException $e) {
    error_log("Failed to fetch creator info: " . $e->getMessage());
}

// Fetch active challenge
try {
    $stmt = $pdo->prepare("
        SELECT id, title, duration, start_date, end_date
        FROM challenges
        WHERE creator_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$creator_id]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($challenge) {
        // Clean start date (ignore time part)
        $start_date_clean = date('Y-m-d', strtotime($challenge['start_date']));
        $start_ts = strtotime($start_date_clean);
        $today_ts = strtotime($today);

        if ($today_ts < $start_ts) {
            $current_day = 0;
        } else {
            $days_passed = floor(($today_ts - $start_ts) / 86400) + 1;
            $current_day = min($days_passed, (int)$challenge['duration']);
        }

        if ($current_day > 0) {
            // Get or create today's progress row
            $stmt = $pdo->prepare("
                SELECT day, completed, marked_at
                FROM challenge_progress
                WHERE challenge_id = ? AND day = ?
            ");
            $stmt->execute([$challenge['id'], $current_day]);
            $today_progress = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$today_progress) {
                $stmt = $pdo->prepare("
                    INSERT INTO challenge_progress (challenge_id, day, completed, marked_at)
                    VALUES (?, ?, 0, NULL)
                ");
                $stmt->execute([$challenge['id'], $current_day]);
                // Re-fetch after insert
                $stmt->execute([$challenge['id'], $current_day]);
                $today_progress = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
} catch (PDOException $e) {
    $error = "Database error: " . htmlspecialchars($e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_day']) && $challenge && $current_day > 0) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = "Security check failed. Please try again.";
    } else {
        $day_to_mark = (int)($_POST['mark_day'] ?? 0);

        // Debug info for the common error
        $debug_info = "POST mark_day: $day_to_mark | Current day: $current_day";

        if ($day_to_mark !== $current_day) {
            $error = "You can only mark today's progress (Day $current_day).<br><small>$debug_info</small>";
        } elseif ($current_hour < 22) {
            $error = "Daily progress can only be marked after 10:00 PM EAT. Come back later!";
        } elseif ($today_progress && $today_progress['completed']) {
            $error = "Day $current_day is already marked as completed.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    UPDATE challenge_progress
                    SET completed = 1, marked_at = NOW()
                    WHERE challenge_id = ? AND day = ?
                ");
                $stmt->execute([$challenge['id'], $current_day]);

                $rows_affected = $stmt->rowCount();
                $pdo->commit();

                if ($rows_affected === 1) {
                    $success = "Day $current_day marked successfully! You're unstoppable! 🔥";

                    // Refresh progress data
                    $stmt = $pdo->prepare("
                        SELECT day, completed, marked_at
                        FROM challenge_progress
                        WHERE challenge_id = ? AND day = ?
                    ");
                    $stmt->execute([$challenge['id'], $current_day]);
                    $today_progress = $stmt->fetch(PDO::FETCH_ASSOC);

                    // Email sending (your original code restored)
                    if (!empty($creator_email) && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                        require '../vendor/autoload.php';
                        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host = 'mail.keychele.co.ke';
                            $mail->SMTPAuth = true;
                            $mail->Username = 'notifications@keychele.co.ke';
                            $mail->Password = 'King20241064.';
                            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = 587;
                            $mail->SMTPOptions = [
                                'ssl' => [
                                    'verify_peer' => false,
                                    'verify_peer_name' => false,
                                    'allow_self_signed' => true
                                ]
                            ];
                            $mail->setFrom('notifications@keychele.co.ke', 'Keychele');
                            $mail->addAddress($creator_email, $creator_name);
                            $mail->isHTML(true);
                            $mail->CharSet = 'UTF-8';

                            $next_day = $current_day + 1;
                            $is_last_day = ($current_day === (int)$challenge['duration']);

                            if ($is_last_day) {
                                $mail->Subject = "🏆 Congratulations! You Completed the Challenge!";
                                $mail->Body = "
                                <div style='font-family:Arial,sans-serif; max-width:600px; margin:0 auto; padding:20px; background:#f9fafb; border-radius:12px;'>
                                    <h2 style='color:#6d28d9; text-align:center;'>🏆 YOU DID IT!</h2>
                                    <p style='font-size:18px; color:#333;'>Dear <strong>$creator_name</strong>,</p>
                                    <p style='font-size:16px; line-height:1.6;'>
                                        Congratulations! You successfully completed <strong>Day $current_day</strong> — the FINAL day of your
                                        <strong>{$challenge['title']}</strong> challenge!
                                    </p>
                                    <p style='font-size:18px; font-weight:bold; color:#10b981; text-align:center; margin:25px 0;'>
                                        You're a giant! You earned the trophy! 🏆🔥
                                    </p>
                                    <p style='text-align:center; margin:30px 0;'>
                                        <a href='https://keychele.co.ke/msanii/index.php?page=create_challenge'
                                           style='background:#6d28d9; color:white; padding:14px 28px; text-decoration:none; border-radius:8px; font-weight:bold;'>
                                            Start a New Challenge Now!
                                        </a>
                                    </p>
                                    <p style='font-size:14px; color:#555; text-align:center; margin-top:30px;'>
                                        Keep shining — the world needs more people like you!<br>
                                        Team Keychele
                                    </p>
                                </div>";
                            } else {
                                $mail->Subject = "Great Job! Day $current_day Completed!";
                                $mail->Body = "
                                <div style='font-family:Arial,sans-serif; max-width:600px; margin:0 auto; padding:20px; background:#f9fafb; border-radius:12px;'>
                                    <h2 style='color:#6d28d9; text-align:center;'>You Made It!</h2>
                                    <p style='font-size:18px; color:#333;'>Dear <strong>$creator_name</strong>,</p>
                                    <p style='font-size:16px; line-height:1.6;'>
                                        Congratulations! You crushed <strong>Day $current_day</strong> of your
                                        <strong>{$challenge['title']}</strong> challenge!
                                    </p>
                                    <p style='font-size:20px; font-weight:bold; color:#10b981; text-align:center; margin:25px 0;'>
                                        You're a giant! 💪
                                    </p>
                                    <p style='text-align:center; font-size:16px;'>
                                        Let's keep the momentum going!<br>
                                        <strong>Next up: Day $next_day</strong>
                                    </p>
                                    <p style='text-align:center; margin:30px 0;'>
                                        <a href='https://keychele.co.ke/msanii/index.php?page=challenge_progress'
                                           style='background:#6d28d9; color:white; padding:12px 24px; text-decoration:none; border-radius:8px;'>
                                            View Your Progress
                                        </a>
                                    </p>
                                    <p style='font-size:14px; color:#555; text-align:center; margin-top:30px;'>
                                        Keep pushing — you're doing amazing!<br>
                                        Team Keychele
                                    </p>
                                </div>";
                            }

                            $mail->AltBody = strip_tags($mail->Body);
                            $mail->send();
                        } catch (Exception $e) {
                            error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
                        }
                    }

                    // Regenerate CSRF
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } else {
                    $error = "No rows updated (Challenge ID: {$challenge['id']}, Day: $current_day). $debug_info";
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Failed to mark progress: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mark Today's Progress | Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- Preloader -->
<div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm transition-opacity duration-300">
  <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
    <div class="h-5 w-5 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
    <span class="text-gray-700 font-medium">Loading...</span>
  </div>
</div>

<div class="p-4 md:p-6 max-w-3xl mx-auto">
  <div class="bg-gradient-to-r from-purple-600 to-indigo-600 p-6 md:p-8 rounded-2xl shadow-lg text-white mb-8">
    <h1 class="text-2xl md:text-3xl font-bold">Mark Today's Progress</h1>
    <p class="opacity-90 mt-1">Confirm your commitment — one day at a time</p>
  </div>

  <?php if ($success): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-5 rounded-xl mb-6 flex items-center gap-3">
      <i class="fa-solid fa-check-circle text-2xl"></i>
      <span><?= htmlspecialchars($success) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-5 rounded-xl mb-6 flex items-center gap-3">
      <i class="fa-solid fa-circle-exclamation text-2xl"></i>
      <span><?= $error ?></span>
    </div>
  <?php endif; ?>

  <?php if (!$challenge): ?>
    <div class="bg-white rounded-2xl shadow-md p-8 text-center">
      <i class="fa-solid fa-hourglass-half text-6xl text-yellow-500 mb-6"></i>
      <h2 class="text-2xl font-bold text-gray-800 mb-4">No Active Challenge</h2>
      <p class="text-gray-600 mb-6">Start a challenge to begin tracking your daily wins.</p>
      <a href="index.php?page=create_challenge" class="inline-flex items-center px-6 py-3 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition">
        <i class="fa-solid fa-plus mr-2"></i> Create Challenge
      </a>
    </div>

  <?php elseif ($current_day === 0): ?>
    <div class="bg-white rounded-2xl shadow-md p-8 text-center">
      <i class="fa-solid fa-calendar-xmark text-6xl text-yellow-600 mb-6"></i>
      <h2 class="text-2xl font-bold text-gray-800 mb-4">Challenge Starts Soon</h2>
      <p class="text-gray-600 mb-2">Begins on: <strong><?= date('M d, Y', strtotime($challenge['start_date'])) ?></strong></p>
      <p class="text-sm text-gray-500">Come back on or after the start date to mark progress.</p>
    </div>

  <?php else: ?>
    <div class="bg-white rounded-2xl shadow-md overflow-hidden">
      <div class="bg-gradient-to-r from-indigo-700 to-purple-700 px-6 py-5 text-white">
        <h2 class="text-xl md:text-2xl font-bold"><?= htmlspecialchars($challenge['title']) ?></h2>
        <p class="mt-1 opacity-90">Day <?= $current_day ?> / <?= $challenge['duration'] ?></p>
      </div>

      <div class="p-6 md:p-8">
        <div class="text-center mb-8">
          <div class="inline-block bg-indigo-50 rounded-full px-8 py-5 mb-4 shadow-sm">
            <p class="text-4xl md:text-5xl font-bold text-indigo-700">Day <?= $current_day ?></p>
            <p class="text-indigo-600 mt-1"><?= date('M d, Y') ?> – Today</p>
          </div>
        </div>

        <?php if ($today_progress && $today_progress['completed']): ?>
          <div class="bg-green-50 border border-green-200 rounded-xl p-6 text-center mb-8">
            <i class="fa-solid fa-circle-check text-6xl text-green-500 mb-4"></i>
            <h3 class="text-xl font-bold text-green-800 mb-2">Completed Today!</h3>
            <p class="text-green-700">
              <?php
              $marked_at = $today_progress['marked_at'];
              if ($marked_at) {
                  echo 'Marked on ' . date('M d, Y \a\t h:i A', strtotime($marked_at));
              } else {
                  echo 'Marked (time not recorded)';
              }
              ?>
            </p>
          </div>
        <?php else: ?>
          <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 mb-8">
            <h3 class="text-xl font-bold text-yellow-800 mb-6 text-center">Did you complete today's challenge?</h3>

            <?php if ($current_hour < 22): ?>
              <div class="text-center">
                <div class="text-2xl font-bold text-red-600 mb-4">
                  <i class="fa-solid fa-lock mr-3"></i> Locked until 10 PM
                </div>
                <p class="text-lg text-gray-800 mb-2">Current time: <?= date('h:i A') ?></p>
                <p class="text-gray-700">
                  You can mark this day only after <strong>10:00 PM EAT</strong>.<br>
                  Come back later tonight!
                </p>
              </div>
            <?php else: ?>
              <div class="text-center">
                <p class="text-lg text-gray-700 mb-6 leading-relaxed">
                  Confirm you've completed today's task.<br>
                  <span class="font-semibold text-gray-800">This cannot be undone — be honest with yourself.</span>
                </p>
                <form method="POST">
                  <input type="hidden" name="mark_day" value="<?= $current_day ?>">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                  <button type="submit"
                          class="px-12 py-6 bg-green-600 hover:bg-green-700 text-white text-xl md:text-2xl font-bold rounded-xl shadow-xl transition transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-green-300">
                    <i class="fa-solid fa-check mr-3"></i>
                    YES — Mark Day <?= $current_day ?> Done
                  </button>
                </form>
                <p class="mt-6 text-sm text-gray-600">
                  Only confirm if you've truly completed today's challenge.
                </p>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="text-center mt-8">
          <a href="index.php?page=challenge_progress" class="text-indigo-600 hover:text-indigo-800 font-medium text-lg">
            <i class="fa-solid fa-chart-line mr-2"></i> View Full Challenge Progress
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
window.addEventListener("load", function () {
    const loader = document.getElementById("preloader");
    loader.style.opacity = "0";
    setTimeout(() => { loader.style.display = "none"; }, 400);
});
</script>
</body>
</html>