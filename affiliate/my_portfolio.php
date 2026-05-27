<?php
include 'includes/connect.php';
include 'check_user_page.php';

if (!isset($_SESSION['creator_id'])) {
    header("Location: ../login");
    exit;
}

$creator_id = $_SESSION['creator_id'];
$success_msg = '';
$error_msg = '';

// Fetch creator info
$stmt = $pdo->prepare("SELECT username, full_name FROM creators WHERE id = ?");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_graphic') {
            if (!empty($_FILES['images']['name'][0])) {
                $target_dir = "msanii/uploads/";
                if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

                foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['images']['error'][$key] === 0) {
                        $filename = time() . "_" . uniqid() . "_" . basename($_FILES['images']['name'][$key]);
                        $target_file = $target_dir . $filename;
                        if (move_uploaded_file($tmp_name, $target_file)) {
                            $stmt = $pdo->prepare("INSERT INTO portfolio (creator_id, category, file_path, title, created_at) VALUES (?, 'graphic', ?, ?, NOW())");
                            $stmt->execute([$creator_id, $filename, $_POST['titles'][$key] ?? 'Untitled']);
                        }
                    }
                }
                $success_msg = "Graphic design work added successfully!";
            }
        }

        if ($action === 'add_video') {
            $youtube_url = trim($_POST['youtube_url'] ?? '');
            $title = trim($_POST['video_title'] ?? '');

            if ($youtube_url && $title) {
                preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $youtube_url, $match);
                $video_id = $match[1] ?? '';

                if ($video_id) {
                    $stmt = $pdo->prepare("INSERT INTO portfolio (creator_id, category, youtube_id, title, created_at) VALUES (?, 'video', ?, ?, NOW())");
                    $stmt->execute([$creator_id, $video_id, $title]);
                    $success_msg = "Video project added successfully!";
                } else {
                    $error_msg = "Invalid YouTube URL.";
                }
            }
        }

        if ($action === 'add_web') {
            $project_url = trim($_POST['project_url'] ?? '');
            $title = trim($_POST['web_title'] ?? '');
            $description = trim($_POST['web_desc'] ?? '');

            if ($project_url && $title) {
                $stmt = $pdo->prepare("INSERT INTO portfolio (creator_id, category, project_url, title, description, created_at) VALUES (?, 'web', ?, ?, ?, NOW())");
                $stmt->execute([$creator_id, $project_url, $title, $description]);
                $success_msg = "Web project added successfully!";
            }
        }

        if ($action === 'add_photo') {
            if (!empty($_FILES['photo_images']['name'][0])) {
                $target_dir = "msanii/uploads/portfolio/";
                if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

                foreach ($_FILES['photo_images']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['photo_images']['error'][$key] === 0) {
                        $filename = time() . "_" . uniqid() . "_" . basename($_FILES['photo_images']['name'][$key]);
                        $target_file = $target_dir . $filename;
                        if (move_uploaded_file($tmp_name, $target_file)) {
                            $stmt = $pdo->prepare("INSERT INTO portfolio (creator_id, category, file_path, title, created_at) VALUES (?, 'photography', ?, ?, NOW())");
                            $stmt->execute([$creator_id, $filename, $_POST['photo_titles'][$key] ?? 'Untitled']);
                        }
                    }
                }
                $success_msg = "Photography work added successfully!";
            }
        }
    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Fetch portfolio items
$portfolio = [];
$categories = ['graphic', 'video', 'web', 'photography'];
foreach ($categories as $cat) {
    $stmt = $pdo->prepare("SELECT * FROM portfolio WHERE creator_id = ? AND category = ? ORDER BY created_at DESC");
    $stmt->execute([$creator_id, $cat]);
    $portfolio[$cat] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Portfolio - Keychele</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4/bootstrap-4.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    primary: '#b81236',
                    secondary: '#032d5d',
                }
            }
        }
    }
  </script>
  <style>
    .gradient-bg { background: linear-gradient(135deg, #b81236 0%, #032d5d 100%); }
    .card-hover { transition: all 0.3s ease; }
    .card-hover:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1); }
  </style>
</head>

<body class="bg-gray-50 min-h-screen">

<!-- Loading Overlay -->
<div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm">
  <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
    <div class="h-5 w-5 border-2 border-green-500 border-t-transparent rounded-full animate-spin"></div>
    <span class="text-gray-700 font-medium">Loading...Please wait!</span>
  </div>
</div>
<script>
window.addEventListener("load", function () {
    const loader = document.getElementById("preloader");
    loader.style.opacity = "0";
    loader.style.transition = "opacity 0.3s ease";
    setTimeout(() => { loader.style.display = "none"; }, 300);
});
</script>
<main class="container mx-auto px-4 py-8 md:py-12 max-w-6xl">

  <!-- Header -->
  <div class="gradient-bg text-white p-8 rounded-2xl shadow-lg mb-10 text-center card-hover">
    <h1 class="text-4xl font-bold mb-3">My Portfolio</h1>
    <p class="text-lg opacity-90">Showcase your best work to attract clients</p>
  </div>

  <!-- Tabs -->
  <div class="flex flex-wrap gap-4 mb-8 justify-center">
    <button onclick="showTab('graphic')" class="tab-btn active px-6 py-3 rounded-xl font-semibold bg-primary text-white">Graphic Design</button>
    <button onclick="showTab('video')" class="tab-btn px-6 py-3 rounded-xl font-semibold bg-gray-200 text-gray-700 hover:bg-primary hover:text-white">Videography</button>
    <button onclick="showTab('web')" class="tab-btn px-6 py-3 rounded-xl font-semibold bg-gray-200 text-gray-700 hover:bg-primary hover:text-white">Web Projects</button>
    <button onclick="showTab('photo')" class="tab-btn px-6 py-3 rounded-xl font-semibold bg-gray-200 text-gray-700 hover:bg-primary hover:text-white">Photography</button>
  </div>

  <!-- Graphic Design -->
  <div id="graphic" class="tab-content">
    <div class="bg-white rounded-2xl shadow-md p-8 card-hover">
      <h2 class="text-2xl font-bold text-secondary mb-6 flex items-center gap-3">
        <i class="fas fa-palette"></i> Graphic Design Works
      </h2>
      <form method="POST" enctype="multipart/form-data" class="mb-8 border-b pb-8">
        <input type="hidden" name="action" value="add_graphic">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Upload Images (Multiple)</label>
            <input type="file" name="images[]" multiple accept="image/*" required class="w-full px-4 py-3 border rounded-xl">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Titles (optional)</label>
            <input type="text" name="titles[]" placeholder="e.g. Logo Design for Brand X" class="w-full px-4 py-3 border rounded-xl">
          </div>
        </div>
        <button type="submit" class="bg-primary text-white px-6 py-3 rounded-xl font-bold hover:bg-red-700">
          Add Graphic Work
        </button>
      </form>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
        <?php foreach ($portfolio['graphic'] as $item): ?>
          <div class="group relative overflow-hidden rounded-xl shadow-lg">
            <img src="msanii/uploads/<?= htmlspecialchars($item['file_path']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="w-full h-48 object-cover group-hover:scale-110 transition">
            <div class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent opacity-0 group-hover:opacity-100 transition flex items-end p-4">
              <p class="text-white font-medium"><?= htmlspecialchars($item['title']) ?: 'Untitled' ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Videography -->
  <div id="video" class="tab-content hidden">
    <div class="bg-white rounded-2xl shadow-md p-8 card-hover">
      <h2 class="text-2xl font-bold text-secondary mb-6 flex items-center gap-3">
        <i class="fas fa-video"></i> Video Projects
      </h2>
      <form method="POST" class="mb-8 border-b pb-8">
        <input type="hidden" name="action" value="add_video">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <input type="text" name="youtube_url" placeholder="YouTube URL" required class="w-full px-4 py-3 border rounded-xl mb-4">
          <input type="text" name="video_title" placeholder="Project Title" required class="w-full px-4 py-3 border rounded-xl mb-4">
        </div>
        <button type="submit" class="bg-primary text-white px-6 py-3 rounded-xl font-bold hover:bg-red-700">
          Add Video
        </button>
      </form>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <?php foreach ($portfolio['video'] as $item): ?>
          <div class="bg-gray-900 rounded-xl overflow-hidden shadow-lg">
            <iframe width="100%" height="315" src="https://www.youtube.com/embed/<?= htmlspecialchars($item['youtube_id']) ?>" frameborder="0" allowfullscreen></iframe>
            <div class="p-4 bg-white">
              <h3 class="font-bold text-lg"><?= htmlspecialchars($item['title']) ?></h3>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Web Projects -->
  <div id="web" class="tab-content hidden">
    <div class="bg-white rounded-2xl shadow-md p-8 card-hover">
      <h2 class="text-2xl font-bold text-secondary mb-6 flex items-center gap-3">
        <i class="fas fa-code"></i> Web Development Projects
      </h2>
      <form method="POST" class="mb-8 border-b pb-8">
        <input type="hidden" name="action" value="add_web">
        <input type="text" name="project_url" placeholder="Project URL (e.g. https://example.com)" required class="w-full px-4 py-3 border rounded-xl mb-4">
        <input type="text" name="web_title" placeholder="Project Title" required class="w-full px-4 py-3 border rounded-xl mb-4">
        <textarea name="web_desc" placeholder="Short description" class="w-full px-4 py-3 border rounded-xl mb-4" rows="3"></textarea>
        <button type="submit" class="bg-primary text-white px-6 py-3 rounded-xl font-bold hover:bg-red-700">
          Add Web Project
        </button>
      </form>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php foreach ($portfolio['web'] as $item): ?>
          <div class="border rounded-xl overflow-hidden shadow-lg hover:shadow-xl transition">
            <div class="bg-gray-200 border-2 border-dashed rounded-xl w-full h-48 flex items-center justify-center">
              <i class="fas fa-globe text-6xl text-gray-400"></i>
            </div>
            <div class="p-6">
              <h3 class="font-bold text-xl mb-2"><?= htmlspecialchars($item['title']) ?></h3>
              <p class="text-gray-600 mb-4"><?= htmlspecialchars($item['description'] ?: 'No description') ?></p>
              <a href="<?= htmlspecialchars($item['project_url']) ?>" target="_blank" class="text-primary font-semibold hover:underline">
                Visit Website
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Photography -->
  <div id="photo" class="tab-content hidden">
    <div class="bg-white rounded-2xl shadow-md p-8 card-hover">
      <h2 class="text-2xl font-bold text-secondary mb-6 flex items-center gap-3">
        <i class="fas fa-camera"></i> Photography
      </h2>
      <form method="POST" enctype="multipart/form-data" class="mb-8 border-b pb-8">
        <input type="hidden" name="action" value="add_photo">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Upload Photos (Multiple)</label>
            <input type="file" name="photo_images[]" multiple accept="image/*" required class="w-full px-4 py-3 border rounded-xl">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Titles (optional)</label>
            <input type="text" name="photo_titles[]" placeholder="e.g. Wedding Shoot 2024" class="w-full px-4 py-3 border rounded-xl">
          </div>
        </div>
        <button type="submit" class="bg-primary text-white px-6 py-3 rounded-xl font-bold hover:bg-red-700">
          Add Photos
        </button>
      </form>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
        <?php foreach ($portfolio['photography'] as $item): ?>
          <div class="group relative overflow-hidden rounded-xl shadow-lg">
            <img src="msanii/uploads/portfolio/<?= htmlspecialchars($item['file_path']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="w-full h-56 object-cover group-hover:scale-110 transition">
            <div class="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent opacity-0 group-hover:opacity-100 transition flex items-end p-4">
              <p class="text-white font-medium"><?= htmlspecialchars($item['title']) ?: 'Photo' ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</main>

<script>
// Preloader
window.addEventListener("load", () => {
  document.getElementById("preloader").style.display = "none";
});

// Tab functionality
function showTab(tabName) {
  document.querySelectorAll('.tab-content').forEach(tab => tab.classList.add('hidden'));
  document.getElementById(tabName).classList.remove('hidden');
  
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.classList.remove('bg-primary', 'text-white');
    btn.classList.add('bg-gray-200', 'text-gray-700');
  });
  
  event.target.classList.add('bg-primary', 'text-white');
  event.target.classList.remove('bg-gray-200', 'text-gray-700');
}

// SweetAlert
<?php if ($success_msg): ?>
Swal.fire({
  icon: 'success',
  title: 'Success!',
  text: "<?= addslashes($success_msg) ?>",
  confirmButtonColor: '#b81236'
});
<?php elseif ($error_msg): ?>
Swal.fire({
  icon: 'error',
  title: 'Error',
  text: "<?= addslashes($error_msg) ?>",
  confirmButtonColor: '#b81236'
});
<?php endif; ?>
</script>

</body>
</html>