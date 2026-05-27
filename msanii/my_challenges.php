<?php
// my_challenges.php - Final version with TABLE layout + Copy Share Link

date_default_timezone_set('Africa/Nairobi');
include 'includes/connect.php';

// No session_start() here — assuming index.php already started it
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit;
}

// Fetch creator info
$stmt = $pdo->prepare("SELECT full_name, username FROM creators WHERE id = ?");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['full_name' => 'User', 'username' => ''];

$full_name = htmlspecialchars($creator['full_name']);
$username  = htmlspecialchars($creator['username']);

// Fetch ALL challenges + cancellation info
$stmt = $pdo->prepare("
    SELECT 
        c.id, c.title, c.description, c.duration, c.start_date, c.end_date, 
        c.status, c.created_at,
        COALESCE((
            SELECT COUNT(*) 
            FROM challenge_progress cp 
            WHERE cp.challenge_id = c.id AND cp.completed = 1
        ), 0) AS days_completed,
        cc.cancel_reason, cc.cancelled_at
    FROM challenges c
    LEFT JOIN challenge_cancellations cc 
           ON cc.creator_id = c.creator_id 
          AND cc.challenge_title = c.title 
          AND cc.start_date = c.start_date
    WHERE c.creator_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$creator_id]);
$challenges = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Challenges | Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- Preloader -->
<div id="preloader" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm transition-opacity duration-300">
  <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
    <div class="h-5 w-5 border-2 border-purple-500 border-t-transparent rounded-full animate-spin"></div>
    <span class="text-gray-700 font-medium">Loading your challenges...</span>
  </div>
</div>

<div class="container mx-auto px-4 py-8 max-w-7xl">

  <!-- Header -->
  <div class="bg-gradient-to-r from-purple-700 to-indigo-700 text-white p-8 rounded-3xl shadow-2xl mb-10">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
      <div>
        <h1 class="text-3xl md:text-4xl font-bold">My Challenges</h1>
        <p class="mt-2 opacity-90">Track your journey — active, completed, and past ones</p>
      </div>
      <a href="index.php?page=create_challenge" 
         class="bg-white text-purple-700 hover:bg-purple-100 px-8 py-4 rounded-2xl font-bold shadow-lg transition transform hover:scale-105 flex items-center gap-3">
        <i class="fa-solid fa-plus"></i> Start New Challenge
      </a>
    </div>
  </div>

  <?php if (empty($challenges)): ?>
    <!-- No challenges -->
    <div class="bg-white rounded-3xl shadow-xl p-12 text-center">
      <i class="fa-solid fa-trophy text-8xl text-yellow-400 mb-6 animate-bounce"></i>
      <h2 class="text-3xl font-bold text-gray-800 mb-4">No Challenges Yet</h2>
      <p class="text-gray-600 text-lg mb-8 max-w-lg mx-auto">
        Start your first challenge today and begin building unstoppable habits!
      </p>
      <a href="index.php?page=create_challenge" 
         class="inline-flex items-center px-10 py-5 bg-purple-600 hover:bg-purple-700 text-white text-xl font-bold rounded-2xl shadow-2xl transition transform hover:scale-105">
        <i class="fa-solid fa-rocket mr-3"></i> Launch Your First Challenge
      </a>
    </div>

  <?php else: ?>

    <!-- Responsive Table -->
    <div class="overflow-x-auto bg-white rounded-3xl shadow-xl">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white">
          <tr>
            <th class="px-6 py-5 text-left text-sm font-semibold uppercase tracking-wider">Challenge</th>
            <th class="px-6 py-5 text-left text-sm font-semibold uppercase tracking-wider hidden md:table-cell">Duration</th>
            <th class="px-6 py-5 text-left text-sm font-semibold uppercase tracking-wider hidden md:table-cell">Start Date</th>
            <th class="px-6 py-5 text-left text-sm font-semibold uppercase tracking-wider">Progress</th>
            <th class="px-6 py-5 text-left text-sm font-semibold uppercase tracking-wider">Status</th>
            <th class="px-6 py-5 text-center text-sm font-semibold uppercase tracking-wider">Actions</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <?php foreach ($challenges as $ch): 
            $days_completed = $ch['days_completed'] ?? 0;
            $percentage = $ch['duration'] > 0 ? round(($days_completed / $ch['duration']) * 100, 1) : 0;

            $status_class = match($ch['status']) {
                'active'    => 'bg-green-100 text-green-800',
                'completed' => 'bg-emerald-100 text-emerald-800',
                'cancelled' => 'bg-red-100 text-red-800',
                default     => 'bg-gray-100 text-gray-800'
            };

            $status_text = match($ch['status']) {
                'active'    => 'Active',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                default     => ucfirst($ch['status'] ?? 'Unknown')
            };

            // Public share link (simple ID-based for now)
            $share_url = "https://keychele.co.ke/msanii/shared_challenge.php?id=" . $ch['id'];
          ?>
            <tr class="hover:bg-gray-50 transition-colors">
              <!-- Challenge Title + Description -->
              <td class="px-6 py-5 whitespace-nowrap">
                <div class="text-sm font-medium text-gray-900 line-clamp-1">
                  <?= htmlspecialchars($ch['title']) ?>
                </div>
                <?php if (!empty($ch['description'])): ?>
                  <div class="text-sm text-gray-500 line-clamp-1">
                    <?= htmlspecialchars(substr($ch['description'], 0, 80)) ?>...
                  </div>
                <?php endif; ?>
              </td>

              <!-- Duration -->
              <td class="px-6 py-5 whitespace-nowrap hidden md:table-cell">
                <div class="text-sm text-gray-900"><?= $ch['duration'] ?> days</div>
              </td>

              <!-- Start Date -->
              <td class="px-6 py-5 whitespace-nowrap hidden md:table-cell">
                <div class="text-sm text-gray-900">
                  <?= date('M d, Y', strtotime($ch['start_date'])) ?>
                </div>
              </td>

              <!-- Progress -->
              <td class="px-6 py-5 whitespace-nowrap">
                <div class="flex items-center gap-3">
                  <div class="flex-1 bg-gray-200 rounded-full h-2.5">
                    <div class="bg-gradient-to-r from-green-500 to-emerald-600 h-2.5 rounded-full transition-all"
                         style="width: <?= $percentage ?>%"></div>
                  </div>
                  <span class="text-sm font-medium text-gray-700"><?= $percentage ?>%</span>
                </div>
              </td>

              <!-- Status -->
              <td class="px-6 py-5 whitespace-nowrap">
                <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $status_class ?>">
                  <?= $status_text ?>
                </span>
              </td>

              <!-- Actions -->
              <td class="px-6 py-5 whitespace-nowrap text-center text-sm font-medium">
                <div class="flex flex-col sm:flex-row gap-2 justify-center">
                  <?php if ($ch['status'] === 'active'): ?>
                    <a href="index.php?page=mark_progress&challenge=<?= $ch['id'] ?>"
                       class="text-green-600 hover:text-green-900">
                      <i class="fa-solid fa-check"></i> Mark
                    </a>
                  <?php endif; ?>

                  <a href="index.php?page=challenge_progress&id=<?= $ch['id'] ?>"
                     class="text-indigo-600 hover:text-indigo-900">
                    <i class="fa-solid fa-eye"></i> View
                  </a>

                  <button onclick="copyToClipboard('<?= $share_url ?>', this)"
                          class="text-blue-600 hover:text-blue-900">
                    <i class="fa-solid fa-share-nodes"></i> Share
                  </button>
                </div>
              </td>
            </tr>

            <!-- Cancellation Reason (expanded row) -->
            <?php if ($ch['status'] === 'cancelled' && !empty($ch['cancel_reason'])): ?>
              <tr class="bg-red-50">
                <td colspan="6" class="px-6 py-4 text-sm text-red-800">
                  <strong>Cancelled on <?= date('M d, Y', strtotime($ch['cancelled_at'])) ?></strong><br>
                  Reason: <?= htmlspecialchars($ch['cancel_reason']) ?>
                </td>
              </tr>
            <?php endif; ?>

          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

</div>

<!-- Copy to Clipboard Script -->
<script>
function copyToClipboard(text, button) {
  navigator.clipboard.writeText(text).then(() => {
    const original = button.innerHTML;
    button.innerHTML = '<i class="fa-solid fa-check mr-1"></i> Copied!';
    button.classList.add('text-green-600');
    setTimeout(() => {
      button.innerHTML = original;
      button.classList.remove('text-green-600');
    }, 2000);
  }).catch(err => {
    alert('Failed to copy: ' + err);
  });
}

// Preloader fade-out
window.addEventListener("load", () => {
    const loader = document.getElementById("preloader");
    loader.style.opacity = "0";
    setTimeout(() => { loader.style.display = "none"; }, 400);
});
</script>

</body>
</html>