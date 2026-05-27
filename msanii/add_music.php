<?php
// ==================== ADD_MUSIC.PHP ====================
// Buffer ALL output from this point — including any layout HTML
// that index.php may have already sent before including this file.
ob_start();

include 'includes/connect.php';
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) die("Please login first.");

$success = $error = "";
$music_id = null;
$music_title = "";

// ── Helper: convert PHP ini shorthand to bytes ──
function return_bytes($val) {
    $val  = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $num  = (int)$val;
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
}

// ── Handle AJAX file upload ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_upload'])) {
    // Discard everything buffered so far (admin layout HTML from index.php)
    // so we return clean JSON with no HTML prefix.
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/json');

    // ── Capture ALL PHP errors and warnings into our response ──
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        echo json_encode([
            'success' => false,
            'error'   => "PHP Error [$errno]: $errstr",
            'debug'   => [
                'file' => $errfile,
                'line' => $errline,
            ]
        ]);
        exit;
    });

    // ── Check for PHP upload errors FIRST (upload_max_filesize / post_max_size hits) ──
    $upload_err_map = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize (' . ini_get('upload_max_filesize') . '). Ask hosting to raise it.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE set in form.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Try again.',
        UPLOAD_ERR_NO_FILE    => 'No audio file received by server.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server has no temp directory. Contact hosting.',
        UPLOAD_ERR_CANT_WRITE => 'Server cannot write to disk. Contact hosting.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
    ];

    $MAX_SIZE = 5 * 1024 * 1024; // 5MB

    // Debug info always collected
    $debug = [
        'php_upload_max_filesize' => ini_get('upload_max_filesize'),
        'php_post_max_size'       => ini_get('post_max_size'),
        'php_memory_limit'        => ini_get('memory_limit'),
        'php_version'             => PHP_VERSION,
        'files_received'          => array_keys($_FILES),
        'post_keys'               => array_keys($_POST),
        'session_creator_id'      => $_SESSION['creator_id'] ?? 'NOT SET',
    ];

    if (empty($_FILES['audio_file']['name'])) {
        // Possibly post_max_size was exceeded — $_FILES will be empty
        $content_length = $_SERVER['CONTENT_LENGTH'] ?? 0;
        $post_max       = return_bytes(ini_get('post_max_size'));
        $hint = ($content_length > $post_max)
            ? "Request size ({$content_length} bytes) exceeds server post_max_size (" . ini_get('post_max_size') . "). Raise post_max_size in php.ini."
            : "No file received. $_FILES is empty.";

        echo json_encode(['success' => false, 'error' => $hint, 'debug' => $debug]);
        exit;
    }

    $file     = $_FILES['audio_file'];
    $fileSize = $file['size'];
    $fileExt  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed  = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];

    $debug['file_name']      = $file['name'];
    $debug['file_size']      = $file['size'];
    $debug['file_type']      = $file['type'];
    $debug['file_ext']       = $fileExt;
    $debug['file_tmp']       = $file['tmp_name'];
    $debug['file_error']     = $file['error'];
    $debug['tmp_is_file']    = is_file($file['tmp_name']);
    $debug['tmp_readable']   = is_readable($file['tmp_name']);

    // PHP upload error code
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = $upload_err_map[$file['error']] ?? "Unknown upload error code: {$file['error']}";
        echo json_encode(['success' => false, 'error' => $msg, 'debug' => $debug]);
        exit;
    }

    if (!in_array($fileExt, $allowed)) {
        echo json_encode(['success' => false, 'error' => "Invalid file type \".$fileExt\". Allowed: MP3, WAV, OGG, M4A, AAC, FLAC.", 'debug' => $debug]);
        exit;
    }
    if ($fileSize > $MAX_SIZE) {
        echo json_encode(['success' => false, 'error' => 'File exceeds 5 MB limit (' . round($fileSize/1048576,2) . ' MB received). Please compress or trim your audio.', 'debug' => $debug]);
        exit;
    }

    $title        = trim($_POST['title'] ?? '');
    $artist       = trim($_POST['artist'] ?? '');
    $album        = trim($_POST['album'] ?? '');
    $genre        = $_POST['genre'] ?? 'Other';
    $description  = trim($_POST['description'] ?? '');
    $is_free      = isset($_POST['is_free']) ? 1 : 0;
    $price        = $is_free ? 0 : floatval($_POST['price'] ?? 0);
    $release_date = $_POST['release_date'] ?? date('Y-m-d');
    $visibility   = $_POST['visibility'] ?? 'public';

    if (empty($title)) {
        echo json_encode(['success' => false, 'error' => 'Track title is required.']);
        exit;
    }

    $audio_dir = "uploads/music/audio/";
    if (!is_dir($audio_dir)) {
        if (!mkdir($audio_dir, 0777, true)) {
            echo json_encode(['success' => false, 'error' => "Cannot create directory: $audio_dir — check server permissions.", 'debug' => array_merge($debug, ['cwd' => getcwd()])]);
            exit;
        }
    }
    $debug['audio_dir_exists']   = is_dir($audio_dir);
    $debug['audio_dir_writable'] = is_writable($audio_dir);

    $audio_filename = time() . '_' . $creator_id . '_' . uniqid() . '.' . $fileExt;
    $audio_full_path = $audio_dir . $audio_filename;

    if (!move_uploaded_file($file['tmp_name'], $audio_full_path)) {
        echo json_encode(['success' => false, 'error' => "Failed to move uploaded file to $audio_full_path. Check directory permissions.", 'debug' => array_merge($debug, ['target_path' => $audio_full_path, 'cwd' => getcwd()])]);
        exit;
    }

    $cover_filename = null;
    if (!empty($_FILES['cover_art']['name'])) {
        $cover    = $_FILES['cover_art'];
        $coverExt = strtolower(pathinfo($cover['name'], PATHINFO_EXTENSION));
        $allowedImg = ['jpg','jpeg','png','webp'];
        if (in_array($coverExt, $allowedImg) && $cover['size'] <= 5 * 1024 * 1024) {
            $cover_dir = "uploads/music/covers/";
            if (!is_dir($cover_dir)) mkdir($cover_dir, 0777, true);
            $cover_filename = time() . '_cover_' . uniqid() . '.' . $coverExt;
            if (!move_uploaded_file($cover['tmp_name'], $cover_dir . $cover_filename))
                $cover_filename = null;
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO music
                (creator_id, title, artist, album, genre, description, audio_file, cover_art,
                 is_free, price, release_date, visibility, status, created_at)
            VALUES
                (:creator_id, :title, :artist, :album, :genre, :description, :audio_file, :cover_art,
                 :is_free, :price, :release_date, :visibility, 'published', NOW())
        ");
        $stmt->execute([
            ':creator_id'   => $creator_id,
            ':title'        => $title,
            ':artist'       => $artist,
            ':album'        => $album,
            ':genre'        => $genre,
            ':description'  => $description,
            ':audio_file'   => $audio_filename,
            ':cover_art'    => $cover_filename,
            ':is_free'      => $is_free,
            ':price'        => $price,
            ':release_date' => $release_date,
            ':visibility'   => $visibility,
        ]);
        $music_id = $pdo->lastInsertId();
        echo json_encode([
            'success'   => true,
            'music_id'  => $music_id,
            'title'     => $title,
            'share_url' => 'https://keychele.co.ke/music/' . $music_id,
        ]);
    } catch (Exception $e) {
        @unlink($audio_full_path);
        echo json_encode([
            'success' => false,
            'error'   => 'Database error: ' . $e->getMessage(),
            'debug'   => array_merge($debug, [
                'exception_class' => get_class($e),
                'exception_code'  => $e->getCode(),
                'exception_file'  => $e->getFile(),
                'exception_line'  => $e->getLine(),
            ])
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Upload Music — Keychele</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,300;400;500;600;700;800&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
/* ═══════════════════════════
   RESET & TOKENS
═══════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  /* Light palette */
  --bg:          #f5f3ef;
  --bg2:         #ede9e2;
  --surface:     #ffffff;
  --surface2:    #faf9f7;
  --border:      #e2ddd6;
  --border2:     #ccc7be;

  /* Text */
  --txt:         #1a1714;
  --txt2:        #6b6560;
  --txt3:        #9e9890;
  --txt-inv:     #ffffff;

  /* Brand */
  --gold:        #c8880a;
  --gold-l:      #f5a623;
  --gold-bg:     #fef7e8;
  --gold-border: #f0d495;

  --red:         #b91c3b;
  --red-l:       #e8143f;
  --red-bg:      #fef2f4;
  --red-border:  #f4a7b4;

  --teal:        #0a7c5c;
  --teal-l:      #12a87d;
  --teal-bg:     #ecfdf5;
  --teal-border: #a7f3d0;

  --navy:        #1e3a6e;
  --purple:      #6d41b8;
  --purple-bg:   #f5f0ff;
  --purple-border: #c4b5fd;

  /* Geometry */
  --r-sm:  8px;
  --r:     12px;
  --r-lg:  16px;
  --r-xl:  22px;
  --r-2xl: 32px;

  /* Shadows */
  --sh-xs: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
  --sh:    0 4px 16px rgba(0,0,0,0.08), 0 1px 4px rgba(0,0,0,0.04);
  --sh-lg: 0 12px 40px rgba(0,0,0,0.1), 0 4px 12px rgba(0,0,0,0.05);
  --sh-xl: 0 24px 64px rgba(0,0,0,0.12);

  --trn: 0.22s cubic-bezier(0.4,0,0.2,1);
}

html { scroll-behavior: smooth; }

body {
  font-family: 'Instrument Sans', sans-serif;
  background: var(--bg);
  color: var(--txt);
  min-height: 100vh;
  -webkit-font-smoothing: antialiased;
}

h1,h2,h3,h4,.font-display { font-family: 'Bricolage Grotesque', sans-serif; }

/* ═══════════════════════════
   LAYOUT
═══════════════════════════ */
.page-shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* Top bar */
.topbar {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 0 1.5rem;
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: var(--sh-xs);
}
.topbar-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  font-family: 'Bricolage Grotesque', sans-serif;
  font-weight: 800;
  font-size: 1.15rem;
  color: var(--txt);
  text-decoration: none;
}
.topbar-brand-icon {
  width: 34px; height: 34px;
  background: linear-gradient(135deg, var(--red), var(--gold-l));
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 0.85rem;
}
.topbar-right {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.topbar-back {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 7px 14px;
  border-radius: var(--r);
  border: 1px solid var(--border);
  background: var(--surface2);
  color: var(--txt2);
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: all var(--trn);
}
.topbar-back:hover {
  border-color: var(--border2);
  color: var(--txt);
  background: var(--bg2);
}

/* Main content */
.content {
  flex: 1;
  max-width: 720px;
  width: 100%;
  margin: 0 auto;
  padding: 2.5rem 1.25rem 5rem;
}

/* ═══════════════════════════
   WIZARD HEADER
═══════════════════════════ */
.wizard-header {
  margin-bottom: 2.5rem;
  animation: fadeSlideUp 0.5s cubic-bezier(0.16,1,0.3,1) both;
}
.wizard-eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  background: var(--red-bg);
  border: 1px solid var(--red-border);
  border-radius: 100px;
  padding: 4px 12px;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--red);
  margin-bottom: 0.85rem;
}
.wizard-eyebrow .live-dot {
  width: 5px; height: 5px;
  border-radius: 50%;
  background: var(--red);
  animation: dotPulse 1.8s ease-in-out infinite;
}
@keyframes dotPulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.4;transform:scale(1.5)} }

.wizard-title {
  font-family: 'Bricolage Grotesque', sans-serif;
  font-weight: 800;
  font-size: clamp(1.8rem, 5vw, 2.6rem);
  color: var(--txt);
  letter-spacing: -0.04em;
  line-height: 1.05;
  margin-bottom: 0.5rem;
}
.wizard-title em {
  font-style: normal;
  background: linear-gradient(135deg, var(--red), var(--gold-l));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
.wizard-subtitle {
  font-size: 0.9rem;
  color: var(--txt2);
  line-height: 1.6;
}

/* ═══════════════════════════
   STEP PROGRESS BAR
═══════════════════════════ */
.step-progress {
  display: flex;
  align-items: center;
  gap: 0;
  margin-bottom: 2.5rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 1rem 1.25rem;
  box-shadow: var(--sh-xs);
  animation: fadeSlideUp 0.5s cubic-bezier(0.16,1,0.3,1) 0.08s both;
}

.sp-step {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  position: relative;
}
.sp-step:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 17px;
  left: calc(50% + 18px);
  right: calc(-50% + 18px);
  height: 2px;
  background: var(--border);
  border-radius: 2px;
  transition: background 0.4s ease;
}
.sp-step.done::after   { background: linear-gradient(90deg, var(--teal-l), var(--teal-l)); }
.sp-step.active::after { background: linear-gradient(90deg, var(--teal-l) 30%, var(--border)); }

.sp-dot {
  width: 34px; height: 34px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.72rem;
  font-weight: 700;
  background: var(--bg2);
  border: 2px solid var(--border);
  color: var(--txt3);
  z-index: 1;
  transition: all 0.3s cubic-bezier(0.34,1.56,0.64,1);
  position: relative;
}
.sp-step.done .sp-dot {
  background: var(--teal-bg);
  border-color: var(--teal-l);
  color: var(--teal);
}
.sp-step.active .sp-dot {
  background: var(--txt);
  border-color: var(--txt);
  color: #fff;
  box-shadow: 0 0 0 4px rgba(26,23,20,0.08);
  transform: scale(1.08);
}

.sp-label {
  font-size: 0.6rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--txt3);
  text-align: center;
  white-space: nowrap;
  transition: color 0.2s;
}
.sp-step.done   .sp-label { color: var(--teal); }
.sp-step.active .sp-label { color: var(--txt); }

/* Hide step labels on very small screens */
@media (max-width: 380px) {
  .sp-label { display: none; }
}

/* ═══════════════════════════
   STEP PANELS
═══════════════════════════ */
.step-panel {
  display: none;
  animation: stepIn 0.4s cubic-bezier(0.16,1,0.3,1) both;
}
.step-panel.active { display: block; }

@keyframes stepIn {
  from { opacity: 0; transform: translateX(18px); }
  to   { opacity: 1; transform: translateX(0); }
}
@keyframes stepInReverse {
  from { opacity: 0; transform: translateX(-18px); }
  to   { opacity: 1; transform: translateX(0); }
}
.step-panel.reverse { animation-name: stepInReverse; }

@keyframes fadeSlideUp {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ═══════════════════════════
   CARD
═══════════════════════════ */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  box-shadow: var(--sh);
  overflow: hidden;
  margin-bottom: 1.25rem;
}
.card-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 1.1rem 1.4rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface2);
}
.ch-icon {
  width: 38px; height: 38px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.88rem;
  flex-shrink: 0;
}
.chi-red    { background: var(--red-bg);    color: var(--red);    border: 1px solid var(--red-border); }
.chi-teal   { background: var(--teal-bg);   color: var(--teal);   border: 1px solid var(--teal-border); }
.chi-gold   { background: var(--gold-bg);   color: var(--gold);   border: 1px solid var(--gold-border); }
.chi-purple { background: var(--purple-bg); color: var(--purple); border: 1px solid var(--purple-border); }

.ch-title { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 0.92rem; color: var(--txt); }
.ch-sub   { font-size: 0.72rem; color: var(--txt2); margin-top: 2px; }

.card-body { padding: 1.4rem; }

/* ═══════════════════════════
   AUDIO DROP ZONE
═══════════════════════════ */
.audio-drop {
  position: relative;
  border: 2px dashed var(--border2);
  border-radius: var(--r-lg);
  background: var(--surface2);
  padding: 3rem 1.5rem;
  text-align: center;
  cursor: pointer;
  transition: all var(--trn);
}
.audio-drop:hover {
  border-color: var(--gold);
  background: var(--gold-bg);
  transform: translateY(-1px);
}
.audio-drop.drag-over {
  border-color: var(--gold);
  background: #fef7e8;
  transform: scale(1.01);
  box-shadow: var(--sh);
}
.audio-drop.has-file {
  border-color: var(--teal-l);
  background: var(--teal-bg);
}
.audio-drop input[type=file] {
  position: absolute; inset: 0;
  opacity: 0; width: 100%; height: 100%; cursor: pointer;
}

.ad-icon {
  width: 72px; height: 72px;
  border-radius: 50%;
  background: var(--bg2);
  border: 2px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 1.1rem;
  font-size: 1.6rem;
  color: var(--txt3);
  transition: all 0.3s;
}
.audio-drop:hover .ad-icon { background: var(--gold-bg); border-color: var(--gold-border); color: var(--gold); }
.audio-drop.has-file .ad-icon { background: var(--teal-bg); border-color: var(--teal-border); color: var(--teal); }

.ad-title { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 1rem; color: var(--txt); margin-bottom: 0.35rem; }
.ad-sub   { font-size: 0.8rem; color: var(--txt2); }

.ad-badges {
  display: flex; flex-wrap: wrap; gap: 6px; justify-content: center;
  margin-top: 1.1rem;
}
.ad-badge {
  padding: 3px 9px; border-radius: 6px;
  font-size: 0.62rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.07em;
  background: var(--bg2); color: var(--txt3);
  border: 1px solid var(--border);
}
.ad-badge.hi { background: var(--gold-bg); color: var(--gold); border-color: var(--gold-border); }

.ad-limit {
  display: inline-flex; align-items: center; gap: 6px;
  margin-top: 0.9rem;
  background: var(--red-bg); border: 1px solid var(--red-border);
  border-radius: var(--r-sm); padding: 5px 12px;
  font-size: 0.68rem; font-weight: 700; color: var(--red);
  letter-spacing: 0.04em;
}

/* File info bar */
.file-info-bar {
  display: none;
  align-items: center; gap: 12px;
  background: var(--teal-bg);
  border: 1px solid var(--teal-border);
  border-radius: var(--r-lg);
  padding: 12px 14px;
  margin-top: 1rem;
}
.file-info-bar.show { display: flex; }
.fib-icon {
  width: 36px; height: 36px; border-radius: 9px; flex-shrink: 0;
  background: rgba(10,124,92,0.12);
  display: flex; align-items: center; justify-content: center;
  font-size: 0.95rem; color: var(--teal);
}
.fib-name { font-weight: 700; font-size: 0.85rem; color: var(--txt); }
.fib-meta { font-size: 0.72rem; color: var(--txt2); margin-top: 1px; }
.fib-rm {
  margin-left: auto; flex-shrink: 0;
  width: 28px; height: 28px;
  border-radius: var(--r-sm);
  background: var(--red-bg); border: 1px solid var(--red-border);
  color: var(--red); display: flex; align-items: center; justify-content: center;
  font-size: 0.72rem; cursor: pointer; transition: background 0.15s;
}
.fib-rm:hover { background: var(--red-border); }

/* Waveform */
.waveform-wrap {
  display: none;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  overflow: hidden;
  margin-top: 1rem;
}
.waveform-wrap.show { display: block; }
.wf-canvas { width: 100%; height: 68px; display: block; }
.wf-foot {
  display: flex; align-items: center; justify-content: space-between;
  padding: 7px 14px 9px;
  font-size: 0.65rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.07em;
  color: var(--txt3);
}

/* ═══════════════════════════
   FORM FIELDS
═══════════════════════════ */
.field { margin-bottom: 1.1rem; }
.field:last-child { margin-bottom: 0; }
.field label {
  display: block;
  font-size: 0.72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.07em;
  color: var(--txt2); margin-bottom: 0.45rem;
}
.required-star { color: var(--red); margin-left: 2px; }

.fi { position: relative; }
.fi-icon {
  position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
  font-size: 0.8rem; color: var(--txt3); pointer-events: none; z-index: 1;
}
.fi-icon.top { top: 13px; transform: none; }

input[type=text], input[type=number], input[type=date], input[type=email],
select, textarea {
  width: 100%;
  font-family: 'Instrument Sans', sans-serif;
  font-size: 0.9rem; color: var(--txt);
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: var(--r);
  padding: 11px 13px 11px 40px;
  outline: none;
  appearance: none; -webkit-appearance: none;
  transition: border-color var(--trn), box-shadow var(--trn), background var(--trn);
}
input:focus, select:focus, textarea:focus {
  border-color: var(--gold);
  box-shadow: 0 0 0 3px rgba(200,136,10,0.12);
  background: #fff;
}
input::placeholder, textarea::placeholder { color: var(--txt3); }
.no-icon input, .no-icon select, .no-icon textarea { padding-left: 13px; }
textarea { padding-left: 13px !important; resize: vertical; min-height: 88px; }

select {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239e9890' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  background-size: 15px;
  padding-right: 34px;
  cursor: pointer;
}
select option { color: var(--txt); }

.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 540px) { .grid2 { grid-template-columns: 1fr; } }

.field-note { font-size: 0.7rem; color: var(--txt3); margin-top: 5px; }

/* Cover art */
.cover-layout { display: flex; gap: 1.25rem; align-items: flex-start; }
.cover-meta { flex: 1; display: flex; flex-direction: column; gap: 0.9rem; }

.cover-drop {
  position: relative;
  width: 132px; height: 132px; flex-shrink: 0;
  border-radius: var(--r-lg);
  border: 2px dashed var(--border2);
  background: var(--surface2);
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 5px; cursor: pointer; overflow: hidden;
  transition: all var(--trn);
}
.cover-drop:hover { border-color: var(--gold); background: var(--gold-bg); }
.cover-drop input[type=file] { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; }
.cover-drop img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.cd-icon  { font-size: 1.4rem; color: var(--txt3); }
.cd-label { font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--txt3); text-align: center; }

/* ── Toggle ── */
.toggle-row {
  display: flex; align-items: center; justify-content: space-between;
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 13px 15px;
  transition: border-color var(--trn);
}
.toggle-row:has(input:checked) {
  background: var(--teal-bg); border-color: var(--teal-border);
}
.tr-label { font-weight: 600; font-size: 0.9rem; color: var(--txt); }
.tr-sub   { font-size: 0.73rem; color: var(--txt2); margin-top: 2px; }

.toggle-sw { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle-sw input { opacity: 0; width: 0; height: 0; }
.sw-track {
  position: absolute; inset: 0; border-radius: 100px;
  background: var(--border2); cursor: pointer;
  transition: background 0.25s;
}
.sw-track::before {
  content: '';
  position: absolute;
  left: 3px; top: 50%; transform: translateY(-50%);
  width: 18px; height: 18px; border-radius: 50%;
  background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,0.2);
  transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1);
}
.toggle-sw input:checked + .sw-track { background: var(--teal-l); }
.toggle-sw input:checked + .sw-track::before { transform: translateY(-50%) translateX(20px); }

/* Price */
.price-wrap {
  display: none;
  flex-direction: column;
  gap: 0.5rem;
  margin-top: 0.9rem;
}
.price-wrap.show { display: flex; }
.price-field {
  display: flex;
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: var(--r);
  overflow: hidden;
  transition: border-color var(--trn), box-shadow var(--trn);
}
.price-field:focus-within { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(200,136,10,0.12); }
.price-curr {
  padding: 11px 14px;
  font-weight: 700; font-size: 0.85rem;
  color: var(--gold); background: var(--gold-bg);
  border-right: 1px solid var(--gold-border);
  flex-shrink: 0; white-space: nowrap;
}
.price-input {
  flex: 1; border: none !important; border-radius: 0 !important;
  box-shadow: none !important; padding-left: 12px !important;
  background: transparent !important;
}
.price-input:focus { box-shadow: none !important; }

/* Visibility pills */
.vis-group { display: flex; gap: 8px; flex-wrap: wrap; }
.vis-radio { display: none; }
.vis-radio + label {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 15px; border-radius: 10px;
  font-size: 0.8rem; font-weight: 600;
  background: var(--surface2); border: 1.5px solid var(--border);
  color: var(--txt2); cursor: pointer;
  transition: all 0.18s;
}
.vis-radio:checked + label {
  background: var(--gold-bg);
  border-color: var(--gold-border);
  color: var(--gold);
  box-shadow: var(--sh-xs);
}

/* ═══════════════════════════
   REVIEW PANEL
═══════════════════════════ */
.review-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  overflow: hidden;
  margin-bottom: 1.25rem;
  box-shadow: var(--sh);
}
.review-track {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.25rem 1.4rem;
  border-bottom: 1px solid var(--border);
}
.review-cover {
  width: 60px; height: 60px;
  border-radius: var(--r);
  background: var(--bg2);
  border: 1px solid var(--border);
  flex-shrink: 0;
  overflow: hidden;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem; color: var(--txt3);
}
.review-cover img { width: 100%; height: 100%; object-fit: cover; }

.review-track-title  { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 1rem; color: var(--txt); }
.review-track-artist { font-size: 0.8rem; color: var(--txt2); margin-top: 2px; }

.review-badge {
  margin-left: auto; flex-shrink: 0;
  padding: 4px 11px; border-radius: 100px;
  font-size: 0.68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.06em;
}
.rb-free { background: var(--teal-bg); color: var(--teal); border: 1px solid var(--teal-border); }
.rb-paid { background: var(--gold-bg); color: var(--gold); border: 1px solid var(--gold-border); }

.review-rows { padding: 0.5rem 0; }
.review-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 9px 1.4rem;
  border-bottom: 1px solid var(--border);
  font-size: 0.84rem;
}
.review-row:last-child { border-bottom: none; }
.rr-key { color: var(--txt2); display: flex; align-items: center; gap: 8px; }
.rr-val { color: var(--txt); font-weight: 600; text-align: right; }
.rr-val.empty { color: var(--txt3); font-weight: 400; font-style: italic; }

.review-waveform {
  padding: 0.75rem 1.4rem 1rem;
  border-top: 1px solid var(--border);
  background: var(--surface2);
}
.rwf-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--txt3); margin-bottom: 8px; }
.rwf-bars {
  display: flex; align-items: flex-end; gap: 2px; height: 36px;
}
.rwf-bar {
  flex: 1; border-radius: 2px;
  background: var(--border2);
  min-height: 4px;
  transition: background 0.2s;
}

/* ═══════════════════════════
   UPLOAD PROGRESS
═══════════════════════════ */
.upload-panel {
  display: none;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  padding: 2rem 1.4rem;
  margin-bottom: 1.25rem;
  box-shadow: var(--sh-lg);
  animation: fadeSlideUp 0.4s cubic-bezier(0.16,1,0.3,1) both;
}
.upload-panel.show { display: block; }

.up-stages {
  display: flex; gap: 0;
  margin-bottom: 1.75rem;
}
.up-stage {
  flex: 1; display: flex; flex-direction: column;
  align-items: center; gap: 5px;
  position: relative;
}
.up-stage:not(:last-child)::after {
  content: ''; position: absolute;
  top: 16px; left: calc(50% + 17px); right: calc(-50% + 17px);
  height: 2px; background: var(--border); transition: background 0.4s;
}
.up-stage.done::after   { background: var(--teal-l); }
.up-stage.active::after { background: linear-gradient(90deg, var(--teal-l), var(--border)); }

.us-dot {
  width: 32px; height: 32px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.7rem; font-weight: 700;
  background: var(--bg2); border: 2px solid var(--border);
  color: var(--txt3); z-index: 1; transition: all 0.3s;
}
.up-stage.done   .us-dot { background: var(--teal-bg); border-color: var(--teal-l); color: var(--teal); }
.up-stage.active .us-dot {
  background: var(--txt); border-color: var(--txt); color: #fff;
  box-shadow: 0 0 0 4px rgba(26,23,20,0.08);
  animation: stagePulse 1.2s ease-in-out infinite;
}
@keyframes stagePulse { 0%,100%{box-shadow:0 0 0 4px rgba(26,23,20,0.08)} 50%{box-shadow:0 0 0 7px rgba(26,23,20,0.04)} }

.us-label {
  font-size: 0.58rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--txt3); text-align: center;
}
.up-stage.done   .us-label { color: var(--teal); }
.up-stage.active .us-label { color: var(--txt); }

.up-title  { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 1.1rem; color: var(--txt); margin-bottom: 0.25rem; }
.up-status { font-size: 0.82rem; color: var(--txt2); margin-bottom: 1.4rem; }

/* Progress circle + stats */
.up-circle-row { display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1.25rem; }

.up-svg { flex-shrink: 0; }
.pr-bg   { stroke: var(--border); }
.pr-fill {
  stroke: var(--gold);
  stroke-linecap: round;
  transform: rotate(-90deg); transform-origin: 50% 50%;
  transition: stroke-dashoffset 0.35s ease;
}
.pr-pct {
  font-family: 'Bricolage Grotesque', sans-serif;
  font-weight: 800; font-size: 1.4rem;
  fill: var(--txt); dominant-baseline: middle; text-anchor: middle;
}
.pr-lbl {
  font-size: 0.52rem; fill: var(--txt3);
  dominant-baseline: middle; text-anchor: middle;
  font-family: 'Instrument Sans', sans-serif;
  letter-spacing: 0.1em; text-transform: uppercase;
}

.up-stats { flex: 1; display: flex; flex-direction: column; gap: 0.6rem; }
.us-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 12px; border-radius: 9px;
  background: var(--surface2); border: 1px solid var(--border);
}
.us-key { font-size: 0.73rem; color: var(--txt3); display: flex; align-items: center; gap: 7px; }
.us-val { font-weight: 700; font-size: 0.8rem; color: var(--txt); }

/* Linear bar */
.bar-track { width: 100%; height: 7px; border-radius: 100px; background: var(--bg2); overflow: hidden; margin-bottom: 0.55rem; }
.bar-fill  {
  height: 100%; border-radius: 100px;
  background: linear-gradient(90deg, var(--red), var(--gold-l));
  width: 0%; transition: width 0.35s ease;
  box-shadow: 0 0 8px rgba(200,136,10,0.35);
  position: relative;
}
.bar-fill::after {
  content: ''; position: absolute; inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
  animation: shimmer 1.5s linear infinite;
}
@keyframes shimmer { from{transform:translateX(-100%)} to{transform:translateX(200%)} }
.bar-labels { display: flex; justify-content: space-between; font-size: 0.7rem; color: var(--txt3); }

/* ═══════════════════════════
   SUCCESS SCREEN
═══════════════════════════ */
.success-screen { display: none; animation: fadeSlideUp 0.6s cubic-bezier(0.16,1,0.3,1) both; }
.success-screen.show { display: block; }

.success-card {
  background: linear-gradient(145deg, #e8faf4 0%, #f0fdf9 60%, #e8faf4 100%);
  border: 1px solid var(--teal-border);
  border-radius: var(--r-2xl);
  padding: 3rem 2rem 2.5rem;
  text-align: center;
  position: relative;
  overflow: hidden;
  margin-bottom: 1.25rem;
  box-shadow: var(--sh-lg);
}
.sc-check {
  width: 88px; height: 88px; border-radius: 50%;
  background: linear-gradient(135deg, var(--teal-l), #0a7c5c);
  margin: 0 auto 1.2rem;
  display: flex; align-items: center; justify-content: center;
  font-size: 2rem; color: #fff;
  box-shadow: 0 0 0 12px rgba(10,124,92,0.1), 0 12px 40px rgba(10,124,92,0.3);
  animation: checkPop 0.5s cubic-bezier(0.34,1.6,0.64,1) 0.1s both;
}
@keyframes checkPop { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }

.sc-title { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 2.2rem; color: var(--txt); letter-spacing: -0.04em; margin-bottom: 0.4rem; }
.sc-sub   { font-size: 0.9rem; color: var(--txt2); max-width: 340px; margin: 0 auto; line-height: 1.6; }

.share-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-xl); overflow: hidden; box-shadow: var(--sh); margin-bottom: 1.25rem; }
.sc-head { display: flex; align-items: center; gap: 12px; padding: 1.1rem 1.4rem; border-bottom: 1px solid var(--border); background: var(--surface2); }
.sc-body { padding: 1.4rem; }

.url-box {
  display: flex; align-items: center; gap: 10px;
  background: var(--surface2); border: 1.5px solid var(--border);
  border-radius: var(--r-lg); padding: 12px 14px;
  margin-bottom: 1.1rem;
}
.url-icon { width: 32px; height: 32px; border-radius: 8px; background: var(--gold-bg); border: 1px solid var(--gold-border); display: flex; align-items: center; justify-content: center; font-size: 0.78rem; color: var(--gold); flex-shrink: 0; }
.url-txt  { flex: 1; font-weight: 600; font-size: 0.82rem; color: var(--txt); word-break: break-all; user-select: all; }
.copy-btn {
  flex-shrink: 0;
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 16px; border-radius: 9px; border: none; cursor: pointer;
  font-family: 'Instrument Sans', sans-serif; font-weight: 700; font-size: 0.8rem;
  background: var(--txt); color: #fff;
  transition: all 0.18s; box-shadow: var(--sh-xs);
}
.copy-btn:hover { transform: translateY(-1px); box-shadow: var(--sh); }
.copy-btn.copied { background: var(--teal-l); }

.social-lbl { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--txt3); margin-bottom: 0.7rem; }
.social-row { display: flex; gap: 8px; flex-wrap: wrap; }
.soc-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 16px; border-radius: 9px;
  font-weight: 700; font-size: 0.8rem;
  text-decoration: none; border: none; cursor: pointer;
  transition: transform 0.15s, box-shadow 0.15s;
}
.soc-btn:hover { transform: translateY(-2px); box-shadow: var(--sh); }
.soc-wa    { background: #25d366; color: #fff; }
.soc-fb    { background: #1877f2; color: #fff; }
.soc-tw    { background: #000; color: #fff; }
.soc-email { background: var(--surface2); color: var(--txt); border: 1px solid var(--border); }

.post-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 1.1rem; }
@media (max-width: 480px) { .post-actions { grid-template-columns: 1fr; } }

.pa-btn {
  display: flex; align-items: center; gap: 10px;
  padding: 13px 15px; border-radius: var(--r-lg);
  font-weight: 600; font-size: 0.84rem;
  text-decoration: none; cursor: pointer; border: none;
  transition: transform 0.18s, box-shadow 0.18s;
}
.pa-btn:hover { transform: translateY(-2px); box-shadow: var(--sh); }
.pa-outline { background: var(--surface2); color: var(--txt); border: 1.5px solid var(--border); }
.pa-solid   { background: linear-gradient(135deg, var(--red), var(--red-l)); color: #fff; box-shadow: 0 4px 16px rgba(185,28,59,0.3); }
.pa-solid:hover { box-shadow: 0 8px 24px rgba(185,28,59,0.45); }
.pa-icon { width: 32px; height: 32px; border-radius: 8px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0; }
.pa-outline .pa-icon { background: var(--gold-bg); color: var(--gold); }

/* ═══════════════════════════
   NAV BUTTONS
═══════════════════════════ */
.step-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-top: 1.5rem;
}
.step-nav.end { justify-content: flex-end; }

.btn-prev {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 13px 24px; border-radius: var(--r-lg);
  font-family: 'Instrument Sans', sans-serif;
  font-weight: 700; font-size: 0.88rem;
  background: var(--surface2); color: var(--txt2);
  border: 1.5px solid var(--border);
  cursor: pointer; transition: all var(--trn);
}
.btn-prev:hover { background: var(--bg2); color: var(--txt); border-color: var(--border2); }

.btn-next {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 13px 28px; border-radius: var(--r-lg);
  font-family: 'Instrument Sans', sans-serif;
  font-weight: 700; font-size: 0.88rem;
  color: #fff; border: none; cursor: pointer;
  background: linear-gradient(135deg, var(--txt) 0%, #3d3831 100%);
  box-shadow: 0 4px 18px rgba(0,0,0,0.18);
  transition: transform 0.18s, box-shadow 0.18s;
  position: relative; overflow: hidden;
}
.btn-next::after {
  content: '';
  position: absolute; inset: 0;
  background: rgba(255,255,255,0);
  transition: background 0.18s;
}
.btn-next:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,0,0,0.22); }
.btn-next:active { transform: translateY(0); }

.btn-publish {
  background: linear-gradient(135deg, var(--red), var(--red-l));
  box-shadow: 0 4px 18px rgba(185,28,59,0.35);
}
.btn-publish:hover { box-shadow: 0 8px 28px rgba(185,28,59,0.5); }
.btn-publish:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

.btn-full { flex: 1; justify-content: center; }

/* Step counter */
.step-counter {
  font-size: 0.75rem; font-weight: 600; color: var(--txt3);
}

/* ═══════════════════════════
   TOAST
═══════════════════════════ */
.toast-wrap { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
.kc-toast {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-radius: var(--r-lg);
  min-width: 220px; max-width: 340px;
  font-size: 0.84rem; font-weight: 600;
  box-shadow: var(--sh-xl); border: 1px solid;
  transform: translateX(110%); opacity: 0;
  transition: all 0.35s cubic-bezier(0.34,1.4,0.64,1);
}
.kc-toast.show { transform: translateX(0); opacity: 1; }
.kc-toast-s { background: var(--teal-bg); border-color: var(--teal-border); color: var(--teal); }
.kc-toast-e { background: var(--red-bg);  border-color: var(--red-border);  color: var(--red); }
.kc-toast-i { background: var(--gold-bg); border-color: var(--gold-border); color: var(--gold); }

@keyframes spin { to { transform: rotate(360deg); } }
.spin { animation: spin 0.7s linear infinite; display: inline-block; }

/* ═══════════════════════════
   UTILITY
═══════════════════════════ */
.divider {
  height: 1px;
  background: var(--border);
  margin: 1.2rem 0;
}
</style>
</head>
<body>

<div class="toast-wrap" id="toastWrap"></div>

<div class="page-shell">

  <!-- TOP BAR -->
  <div class="topbar">
    <a href="index.php" class="topbar-brand">
      <div class="topbar-brand-icon"><i class="fa-solid fa-ticket"></i></div>
      Keychele
    </a>
    <div class="topbar-right">
      <a href="index.php?page=my_music" class="topbar-back">
        <i class="fa-solid fa-arrow-left"></i>
        <span>My Music</span>
      </a>
    </div>
  </div>

  <!-- MAIN -->
  <div class="content">

    <!-- HEADER -->
    <div class="wizard-header">
      <div class="wizard-eyebrow">
        <span class="live-dot"></span>
        Audio Studio
      </div>
      <h1 class="wizard-title">Upload Your <em>Music</em></h1>
      <p class="wizard-subtitle">Share your sound — set a price, go free, or release exclusively for your tribe.</p>
    </div>

    <!-- STEP PROGRESS -->
    <div class="step-progress" id="stepProgress">
      <div class="sp-step active" id="sp-0">
        <div class="sp-dot"><i class="fa-solid fa-music"></i></div>
        <div class="sp-label">Audio</div>
      </div>
      <div class="sp-step" id="sp-1">
        <div class="sp-dot"><i class="fa-solid fa-pen-nib"></i></div>
        <div class="sp-label">Details</div>
      </div>
      <div class="sp-step" id="sp-2">
        <div class="sp-dot"><i class="fa-solid fa-sack-dollar"></i></div>
        <div class="sp-label">Pricing</div>
      </div>
      <div class="sp-step" id="sp-3">
        <div class="sp-dot"><i class="fa-solid fa-rocket"></i></div>
        <div class="sp-label">Publish</div>
      </div>
    </div>

    <!-- UPLOAD PROGRESS PANEL -->
    <div class="upload-panel" id="uploadPanel">
      <div class="up-stages">
        <div class="up-stage active" id="st-validate"><div class="us-dot"><i class="fa-solid fa-shield-check"></i></div><div class="us-label">Validate</div></div>
        <div class="up-stage" id="st-upload">  <div class="us-dot"><i class="fa-solid fa-cloud-arrow-up"></i></div><div class="us-label">Upload</div></div>
        <div class="up-stage" id="st-process"> <div class="us-dot"><i class="fa-solid fa-microchip"></i></div>        <div class="us-label">Process</div></div>
        <div class="up-stage" id="st-done">    <div class="us-dot"><i class="fa-solid fa-check"></i></div>            <div class="us-label">Live</div></div>
      </div>
      <div class="up-title" id="upTitle">Uploading your track…</div>
      <div class="up-status" id="upStatus">Preparing upload…</div>
      <div class="up-circle-row">
        <svg class="up-svg" width="96" height="96" viewBox="0 0 96 96">
          <circle class="pr-bg"   cx="48" cy="48" r="38" stroke-width="7" fill="none"/>
          <circle class="pr-fill" cx="48" cy="48" r="38" stroke-width="7" fill="none"
                  stroke-dasharray="238.76" stroke-dashoffset="238.76" id="ringFill"/>
          <text class="pr-pct"   x="48" y="44" id="ringPct">0%</text>
          <text class="pr-lbl"   x="48" y="59">uploaded</text>
        </svg>
        <div class="up-stats">
          <div class="us-row"><span class="us-key"><i class="fa-solid fa-gauge-high"></i> Speed</span><span class="us-val" id="upSpeed">—</span></div>
          <div class="us-row"><span class="us-key"><i class="fa-regular fa-clock"></i> ETA</span><span class="us-val" id="upETA">—</span></div>
          <div class="us-row"><span class="us-key"><i class="fa-solid fa-database"></i> Transferred</span><span class="us-val" id="upTransfer">0 KB</span></div>
        </div>
      </div>
      <div class="bar-track"><div class="bar-fill" id="barFill"></div></div>
      <div class="bar-labels"><span id="barPct">0%</span><span>5 MB max</span></div>
    </div>

    <!-- SUCCESS SCREEN -->
    <div class="success-screen" id="successScreen">
      <div class="success-card">
        <div class="sc-check"><i class="fa-solid fa-music"></i></div>
        <div class="sc-title">Track Published!</div>
        <p class="sc-sub">Your music is live and ready for your audience to stream and purchase.</p>
      </div>
      <div class="share-card">
        <div class="sc-head">
          <div class="ch-icon chi-teal"><i class="fa-solid fa-share-nodes"></i></div>
          <div>
            <div class="ch-title">Share Your Track</div>
            <div class="ch-sub">Send this link to your fans</div>
          </div>
        </div>
        <div class="sc-body">
          <div class="url-box">
            <div class="url-icon"><i class="fa-solid fa-link"></i></div>
            <span class="url-txt" id="shareUrl">https://keychele.co.ke/music/—</span>
            <button class="copy-btn" id="copyBtn" onclick="copyLink()">
              <i class="fa-solid fa-copy"></i> Copy
            </button>
          </div>
          <div class="social-lbl">Share via</div>
          <div class="social-row" id="socialRow"></div>
          <div class="post-actions">
            <a href="index.php?page=my_music" class="pa-btn pa-outline">
              <div class="pa-icon"><i class="fa-solid fa-headphones"></i></div>
              <div><div>My Music</div><div style="font-size:0.7rem;color:var(--txt3);font-weight:400">All tracks</div></div>
            </a>
            <a href="index.php?page=add_music" class="pa-btn pa-solid">
              <div class="pa-icon"><i class="fa-solid fa-plus"></i></div>
              <div><div>Upload Another</div><div style="font-size:0.7rem;color:rgba(255,255,255,0.65);font-weight:400">New track</div></div>
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ STEP 1: AUDIO ══ -->
    <div class="step-panel active" id="panel-0">
      <div class="card">
        <div class="card-head">
          <div class="ch-icon chi-red"><i class="fa-solid fa-music"></i></div>
          <div>
            <div class="ch-title">Select Your Audio File</div>
            <div class="ch-sub">Drag & drop or click to browse — MP3, WAV, OGG, M4A, AAC, FLAC</div>
          </div>
        </div>
        <div class="card-body">
          <div class="audio-drop" id="audioDrop">
            <input type="file" id="audioInput" accept=".mp3,.wav,.ogg,.m4a,.aac,.flac,audio/*">
            <div class="ad-icon" id="adIcon"><i class="fa-solid fa-cloud-arrow-up" id="adIconI"></i></div>
            <div class="ad-title" id="adTitle">Drop your track here</div>
            <div class="ad-sub"   id="adSub">or click to browse files from your device</div>
            <div class="ad-badges">
              <span class="ad-badge hi">MP3</span>
              <span class="ad-badge">WAV</span>
              <span class="ad-badge">OGG</span>
              <span class="ad-badge">M4A</span>
              <span class="ad-badge">AAC</span>
              <span class="ad-badge">FLAC</span>
            </div>
            <div class="ad-limit"><i class="fa-solid fa-lock"></i> 5 MB Maximum</div>
          </div>

          <div class="file-info-bar" id="fileInfoBar">
            <div class="fib-icon"><i class="fa-solid fa-file-audio"></i></div>
            <div>
              <div class="fib-name" id="fibName">—</div>
              <div class="fib-meta" id="fibMeta">—</div>
            </div>
            <button class="fib-rm" type="button" onclick="clearAudio()">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>

          <div class="waveform-wrap" id="waveformWrap">
            <canvas class="wf-canvas" id="wfCanvas" height="68"></canvas>
            <div class="wf-foot">
              <span><i class="fa-solid fa-wave-square"></i> Waveform Preview</span>
              <span id="wfDuration">—</span>
            </div>
          </div>
        </div>
      </div>

      <div class="step-nav end">
        <div class="step-counter">Step 1 of 4</div>
        <button class="btn-next" onclick="gotoStep(1)">
          Continue <i class="fa-solid fa-arrow-right"></i>
        </button>
      </div>
    </div>

    <!-- ══ STEP 2: DETAILS ══ -->
    <div class="step-panel" id="panel-1">
      <div class="card">
        <div class="card-head">
          <div class="ch-icon chi-teal"><i class="fa-solid fa-pen-nib"></i></div>
          <div>
            <div class="ch-title">Track Information</div>
            <div class="ch-sub">Tell your audience what they're listening to</div>
          </div>
        </div>
        <div class="card-body">
          <div class="cover-layout">
            <div>
              <label style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--txt2);display:block;margin-bottom:0.45rem;">Cover Art</label>
              <div class="cover-drop" id="coverDrop">
                <input type="file" id="coverInput" accept="image/*" onchange="handleCover(this)">
                <i class="fa-solid fa-image cd-icon" id="coverIcon"></i>
                <div class="cd-label" id="coverLabel">Add<br>Artwork</div>
                <img id="coverPreview" style="display:none" alt="Cover">
              </div>
            </div>
            <div class="cover-meta">
              <div class="field">
                <label>Track Title <span class="required-star">*</span></label>
                <div class="fi"><i class="fi-icon fa-solid fa-music"></i>
                  <input type="text" id="f_title" placeholder="e.g. Nairobi Nights">
                </div>
              </div>
              <div class="field">
                <label>Artist / Stage Name <span class="required-star">*</span></label>
                <div class="fi"><i class="fi-icon fa-solid fa-microphone"></i>
                  <input type="text" id="f_artist" placeholder="e.g. DJ Maphorisa">
                </div>
              </div>
            </div>
          </div>

          <div class="divider"></div>

          <div class="grid2">
            <div class="field">
              <label>Album / EP</label>
              <div class="fi"><i class="fi-icon fa-solid fa-record-vinyl"></i>
                <input type="text" id="f_album" placeholder="Optional">
              </div>
            </div>
            <div class="field">
              <label>Genre</label>
              <div class="fi"><i class="fi-icon fa-solid fa-tag"></i>
                <select id="f_genre">
                  <option>Afrobeats</option><option>Bongo Flava</option>
                  <option>Gengetone</option><option>Gospel</option>
                  <option>Hip-Hop</option><option>R&B</option>
                  <option>Reggae</option><option>Amapiano</option>
                  <option>Drill</option><option>Electronic</option>
                  <option>Jazz</option><option>Classical</option>
                  <option>Podcast</option><option>Other</option>
                </select>
              </div>
            </div>
          </div>

          <div class="field">
            <label>Release Date</label>
            <div class="fi"><i class="fi-icon fa-solid fa-calendar"></i>
              <input type="date" id="f_release_date" value="<?= date('Y-m-d') ?>">
            </div>
          </div>

          <div class="field no-icon">
            <label>Description</label>
            <textarea id="f_description" placeholder="Describe your track — inspirations, mood, or anything your fans should know…"></textarea>
          </div>
        </div>
      </div>

      <div class="step-nav">
        <button class="btn-prev" onclick="gotoStep(0)">
          <i class="fa-solid fa-arrow-left"></i> Back
        </button>
        <div class="step-counter">Step 2 of 4</div>
        <button class="btn-next" onclick="gotoStep(2)">
          Continue <i class="fa-solid fa-arrow-right"></i>
        </button>
      </div>
    </div>

    <!-- ══ STEP 3: MONETISATION ══ -->
    <div class="step-panel" id="panel-2">
      <div class="card">
        <div class="card-head">
          <div class="ch-icon chi-gold"><i class="fa-solid fa-sack-dollar"></i></div>
          <div>
            <div class="ch-title">Pricing &amp; Access</div>
            <div class="ch-sub">Choose how fans access your music</div>
          </div>
        </div>
        <div class="card-body">

          <div class="toggle-row">
            <div>
              <div class="tr-label">Free Track</div>
              <div class="tr-sub">Anyone can stream at no cost — no purchase required</div>
            </div>
            <label class="toggle-sw">
              <input type="checkbox" id="isFreeToggle" checked onchange="toggleFree(this)">
              <span class="sw-track"></span>
            </label>
          </div>

          <div class="price-wrap" id="priceWrap">
            <div class="price-field">
              <div class="price-curr">KSh</div>
              <input type="number" class="price-input" id="f_price" min="0" step="50" placeholder="e.g. 150" value="0">
            </div>
            <div class="field-note">Keychele deducts 3.5% per sale — you keep the rest.</div>
          </div>

          <div class="divider"></div>

          <div class="field">
            <label>Visibility</label>
            <div class="vis-group">
              <input type="radio" name="vis" id="vis-public"  value="public"  class="vis-radio" checked>
              <label for="vis-public"><i class="fa-solid fa-globe"></i> Public</label>

              <input type="radio" name="vis" id="vis-tribe"   value="tribe"   class="vis-radio">
              <label for="vis-tribe"><i class="fa-solid fa-users"></i> Tribe Only</label>

              <input type="radio" name="vis" id="vis-private" value="private" class="vis-radio">
              <label for="vis-private"><i class="fa-solid fa-lock"></i> Private</label>
            </div>
            <div class="field-note" style="margin-top:0.55rem">Public = everyone · Tribe = your subscribers · Private = only you</div>
          </div>

        </div>
      </div>

      <div class="step-nav">
        <button class="btn-prev" onclick="gotoStep(1)">
          <i class="fa-solid fa-arrow-left"></i> Back
        </button>
        <div class="step-counter">Step 3 of 4</div>
        <button class="btn-next" onclick="gotoStep(3)">
          Review &amp; Publish <i class="fa-solid fa-arrow-right"></i>
        </button>
      </div>
    </div>

    <!-- ══ STEP 4: REVIEW ══ -->
    <div class="step-panel" id="panel-3">
      <div class="review-card">
        <div class="review-track">
          <div class="review-cover" id="reviewCover">
            <i class="fa-solid fa-music"></i>
          </div>
          <div>
            <div class="review-track-title"  id="reviewTitle">Untitled Track</div>
            <div class="review-track-artist" id="reviewArtist">Unknown Artist</div>
          </div>
          <div class="review-badge rb-free" id="reviewBadge">Free</div>
        </div>
        <div class="review-rows">
          <div class="review-row"><span class="rr-key"><i class="fa-solid fa-tag"></i> Genre</span>        <span class="rr-val" id="rv-genre">—</span></div>
          <div class="review-row"><span class="rr-key"><i class="fa-solid fa-record-vinyl"></i> Album</span><span class="rr-val" id="rv-album">—</span></div>
          <div class="review-row"><span class="rr-key"><i class="fa-solid fa-calendar"></i> Release</span>  <span class="rr-val" id="rv-date">—</span></div>
          <div class="review-row"><span class="rr-key"><i class="fa-solid fa-globe"></i> Visibility</span>  <span class="rr-val" id="rv-vis">—</span></div>
          <div class="review-row"><span class="rr-key"><i class="fa-solid fa-file-audio"></i> File</span>   <span class="rr-val" id="rv-file">—</span></div>
        </div>
        <div class="review-waveform">
          <div class="rwf-label"><i class="fa-solid fa-wave-square"></i> Waveform</div>
          <div class="rwf-bars" id="reviewBars"></div>
        </div>
      </div>

      <div class="card" style="margin-bottom:0;">
        <div class="card-body" style="padding:1rem 1.4rem;">
          <div style="display:flex;align-items:center;gap:10px;font-size:0.84rem;color:var(--txt2);">
            <i class="fa-solid fa-circle-check" style="color:var(--teal);font-size:1rem;"></i>
            Everything looks good? Hit publish to make your track live on Keychele.
          </div>
        </div>
      </div>

      <div class="step-nav">
        <button class="btn-prev" onclick="gotoStep(2)">
          <i class="fa-solid fa-arrow-left"></i> Back
        </button>
        <div class="step-counter">Step 4 of 4</div>
        <button class="btn-next btn-publish btn-full" id="publishBtn" onclick="doUpload()">
          <i class="fa-solid fa-rocket"></i> Publish to Keychele
        </button>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /page-shell -->


<script>
/* ══════════════════════════════════════
   GLOBALS
══════════════════════════════════════ */
const MAX_BYTES   = 5 * 1024 * 1024;
const ALLOWED_EXT = ['mp3','wav','ogg','m4a','aac','flac'];
let   selectedFile = null;
let   audioCtx     = null;
let   currentStep  = 0;
const TOTAL_STEPS  = 4;

/* ══════════════════════════════════════
   TOAST
══════════════════════════════════════ */
function toast(msg, type='s', ms=4000) {
  const wrap = document.getElementById('toastWrap');
  const el   = document.createElement('div');
  const ico  = type==='s'?'fa-circle-check':type==='e'?'fa-circle-exclamation':'fa-circle-info';
  el.className = `kc-toast kc-toast-${type}`;
  // Use innerHTML so error details with <br>/<code> render
  el.innerHTML = `<i class="fa-solid ${ico}" style="flex-shrink:0"></i><span>${msg}</span>`;
  wrap.appendChild(el);
  requestAnimationFrame(()=>requestAnimationFrame(()=>el.classList.add('show')));
  setTimeout(()=>{ el.classList.remove('show'); setTimeout(()=>el.remove(),400); }, ms);
}

/* ══════════════════════════════════════
   STEP NAVIGATION
══════════════════════════════════════ */
function gotoStep(to) {
  if (to > currentStep && !validateStep(currentStep)) return;

  const fromPanel = document.getElementById('panel-' + currentStep);
  const toPanel   = document.getElementById('panel-' + to);

  fromPanel.classList.remove('active');
  toPanel.classList.add('active');
  if (to < currentStep) toPanel.classList.add('reverse');
  else toPanel.classList.remove('reverse');

  // Update step dots
  for (let i=0; i<TOTAL_STEPS; i++) {
    const dot = document.getElementById('sp-' + i);
    dot.classList.remove('active','done');
    if (i < to)      dot.classList.add('done');
    else if (i===to) dot.classList.add('active');
  }

  currentStep = to;

  if (to === 3) buildReview();

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function validateStep(step) {
  if (step === 0) {
    if (!selectedFile) { toast('Please select an audio file first.','e'); return false; }
  }
  if (step === 1) {
    if (!document.getElementById('f_title').value.trim())  { toast('Track title is required.','e'); return false; }
    if (!document.getElementById('f_artist').value.trim()) { toast('Artist name is required.','e'); return false; }
  }
  return true;
}

/* ══════════════════════════════════════
   AUDIO DROP
══════════════════════════════════════ */
const audioDrop  = document.getElementById('audioDrop');
const audioInput = document.getElementById('audioInput');

audioDrop.addEventListener('dragover',  e=>{ e.preventDefault(); audioDrop.classList.add('drag-over'); });
audioDrop.addEventListener('dragleave', ()=> audioDrop.classList.remove('drag-over'));
audioDrop.addEventListener('drop', e=>{
  e.preventDefault(); audioDrop.classList.remove('drag-over');
  if (e.dataTransfer.files.length) processAudio(e.dataTransfer.files[0]);
});
audioInput.addEventListener('change', ()=>{ if (audioInput.files.length) processAudio(audioInput.files[0]); });

function processAudio(file) {
  const ext = file.name.split('.').pop().toLowerCase();
  if (!ALLOWED_EXT.includes(ext)) { toast('Invalid file type. Use MP3, WAV, OGG, M4A, AAC or FLAC.','e'); return; }
  if (file.size > MAX_BYTES) { toast(`File too large: ${fmtBytes(file.size)}. Max is 5 MB.`,'e',6000); return; }

  selectedFile = file;
  audioDrop.classList.add('has-file');
  document.getElementById('adIconI').className = 'fa-solid fa-circle-check';
  document.getElementById('adTitle').textContent = 'Track selected!';
  document.getElementById('adSub').textContent   = file.name;

  document.getElementById('fibName').textContent = file.name;
  document.getElementById('fibMeta').textContent = `${fmtBytes(file.size)} · ${ext.toUpperCase()}`;
  document.getElementById('fileInfoBar').classList.add('show');

  drawWaveform(file);
  toast(`"${file.name}" ready`,'i',2200);
}

function clearAudio() {
  selectedFile = null; audioInput.value = '';
  audioDrop.classList.remove('has-file');
  document.getElementById('adIconI').className = 'fa-solid fa-cloud-arrow-up';
  document.getElementById('adTitle').textContent = 'Drop your track here';
  document.getElementById('adSub').textContent   = 'or click to browse files from your device';
  document.getElementById('fileInfoBar').classList.remove('show');
  document.getElementById('waveformWrap').classList.remove('show');
}

/* ══════════════════════════════════════
   WAVEFORM
══════════════════════════════════════ */
let waveformData = [];

function drawWaveform(file) {
  const wrap   = document.getElementById('waveformWrap');
  const canvas = document.getElementById('wfCanvas');
  wrap.classList.add('show');
  canvas.width  = canvas.offsetWidth * (devicePixelRatio||1);
  canvas.height = 68 * (devicePixelRatio||1);
  canvas.style.width='100%'; canvas.style.height='68px';

  const reader = new FileReader();
  reader.onload = e=>{
    if (!audioCtx) audioCtx = new (window.AudioContext||window.webkitAudioContext)();
    audioCtx.decodeAudioData(e.target.result.slice(0), buf=>{
      const raw    = buf.getChannelData(0);
      const dur    = buf.duration;
      document.getElementById('wfDuration').textContent =
        `${Math.floor(dur/60)}:${String(Math.floor(dur%60)).padStart(2,'0')}`;

      const ctx  = canvas.getContext('2d');
      const W=canvas.width, H=canvas.height;
      const bars = Math.min(200, Math.floor(W/3));
      const step = Math.floor(raw.length/bars);
      ctx.clearRect(0,0,W,H);
      waveformData = [];

      for (let i=0;i<bars;i++){
        let sum=0;
        for (let j=0;j<step;j++) sum+=Math.abs(raw[i*step+j]||0);
        const amp=sum/step;
        waveformData.push(amp);
        const barH=Math.max(4, amp*H*2);
        const x=i*(W/bars), bw=W/bars-2;
        const g=ctx.createLinearGradient(0,(H-barH)/2,0,(H+barH)/2);
        g.addColorStop(0,`rgba(185,28,59,${0.4+amp*1.5})`);
        g.addColorStop(0.5,`rgba(200,136,10,${0.5+amp*1.5})`);
        g.addColorStop(1,`rgba(185,28,59,${0.3+amp*1.2})`);
        ctx.fillStyle=g;
        ctx.beginPath();
        ctx.roundRect(x,(H-barH)/2,bw,barH,2);
        ctx.fill();
      }
    }, ()=> drawFakeBars(canvas));
  };
  reader.readAsArrayBuffer(file);
}

function drawFakeBars(canvas){
  const ctx=canvas.getContext('2d'),W=canvas.width,H=canvas.height;
  waveformData=[];
  for(let i=0;i<120;i++){
    const amp=0.2+Math.random()*0.6; waveformData.push(amp);
    const barH=amp*H;
    ctx.fillStyle=`rgba(200,136,10,${0.2+amp*0.4})`;
    ctx.beginPath(); ctx.roundRect(i*(W/120),(H-barH)/2,W/120-2,barH,2); ctx.fill();
  }
}

/* ══════════════════════════════════════
   COVER ART
══════════════════════════════════════ */
function handleCover(input){
  if(!input.files.length) return;
  const prev=document.getElementById('coverPreview');
  prev.src=URL.createObjectURL(input.files[0]);
  prev.style.display='block';
  document.getElementById('coverIcon').style.display='none';
  document.getElementById('coverLabel').style.display='none';
}

/* ══════════════════════════════════════
   FREE TOGGLE
══════════════════════════════════════ */
function toggleFree(cb){
  document.getElementById('priceWrap').classList.toggle('show', !cb.checked);
}

/* ══════════════════════════════════════
   BUILD REVIEW
══════════════════════════════════════ */
function buildReview(){
  const title  = document.getElementById('f_title').value.trim()  || 'Untitled Track';
  const artist = document.getElementById('f_artist').value.trim() || 'Unknown Artist';
  const album  = document.getElementById('f_album').value.trim()  || null;
  const genre  = document.getElementById('f_genre').value;
  const date   = document.getElementById('f_release_date').value;
  const vis    = document.querySelector('input[name="vis"]:checked').value;
  const isFree = document.getElementById('isFreeToggle').checked;
  const price  = document.getElementById('f_price').value;

  document.getElementById('reviewTitle').textContent  = title;
  document.getElementById('reviewArtist').textContent = artist;
  document.getElementById('rv-genre').textContent     = genre;
  document.getElementById('rv-album').textContent     = album || '—';
  document.getElementById('rv-album').className       = 'rr-val' + (album ? '' : ' empty');
  document.getElementById('rv-date').textContent      = date || '—';
  document.getElementById('rv-vis').textContent       = {public:'Public',tribe:'Tribe Only',private:'Private'}[vis]||vis;
  document.getElementById('rv-file').textContent      = selectedFile ? `${selectedFile.name} (${fmtBytes(selectedFile.size)})` : '—';

  const badge = document.getElementById('reviewBadge');
  if (isFree){
    badge.textContent='Free'; badge.className='review-badge rb-free';
  } else {
    badge.textContent='KSh '+price; badge.className='review-badge rb-paid';
  }

  // Cover preview
  const rc = document.getElementById('reviewCover');
  const coverInput = document.getElementById('coverInput');
  if (coverInput.files.length){
    rc.innerHTML=`<img src="${URL.createObjectURL(coverInput.files[0])}" alt="cover">`;
  } else {
    rc.innerHTML='<i class="fa-solid fa-music"></i>';
  }

  // Mini review waveform
  const barCont = document.getElementById('reviewBars');
  barCont.innerHTML='';
  const points = waveformData.length ? waveformData : Array.from({length:40},()=>0.2+Math.random()*0.6);
  const sample = points.filter((_,i)=>i%Math.ceil(points.length/40)===0).slice(0,40);
  sample.forEach(amp=>{
    const d=document.createElement('div');
    d.className='rwf-bar';
    d.style.height=Math.max(10,amp*100)+'%';
    d.style.background=`rgba(200,136,10,${0.3+amp*0.7})`;
    barCont.appendChild(d);
  });
}

/* ══════════════════════════════════════
   UPLOAD
══════════════════════════════════════ */
function doUpload(){
  const title  = document.getElementById('f_title').value.trim();
  const artist = document.getElementById('f_artist').value.trim();
  if (!selectedFile)  { toast('No audio file selected.','e'); return; }
  if (!title)         { toast('Track title is required.','e'); return; }
  if (!artist)        { toast('Artist name is required.','e'); return; }

  const fd = new FormData();
  fd.append('ajax_upload','1');
  fd.append('audio_file', selectedFile);
  fd.append('title',       title);
  fd.append('artist',      artist);
  fd.append('album',       document.getElementById('f_album').value.trim());
  fd.append('genre',       document.getElementById('f_genre').value);
  fd.append('description', document.getElementById('f_description').value.trim());
  fd.append('release_date',document.getElementById('f_release_date').value);
  fd.append('visibility',  document.querySelector('input[name="vis"]:checked').value);

  const isFree = document.getElementById('isFreeToggle').checked;
  if (isFree) fd.append('is_free','1');
  else fd.append('price', document.getElementById('f_price').value||'0');

  const ci = document.getElementById('coverInput');
  if (ci.files.length) fd.append('cover_art', ci.files[0]);

  // Hide form panels, show upload panel
  for (let i=0;i<TOTAL_STEPS;i++) document.getElementById('panel-'+i).style.display='none';
  document.getElementById('stepProgress').style.display='none';
  document.getElementById('uploadPanel').classList.add('show');
  document.getElementById('publishBtn').disabled=true;

  setUpStage('validate');
  setProgress(0);

  setTimeout(()=>{
    setUpStage('upload');
    document.getElementById('upTitle').textContent  = 'Uploading your track…';
    document.getElementById('upStatus').textContent = 'Transferring audio to Keychele servers…';
    runXHR(fd, selectedFile.size);
  }, 900);
}

function runXHR(fd, total){
  const xhr=new XMLHttpRequest();
  let lastLoaded=0, lastTime=Date.now(), speeds=[];

  xhr.upload.addEventListener('progress', e=>{
    if (!e.lengthComputable) return;
    const now=Date.now(), dt=(now-lastTime)/1000, dl=e.loaded-lastLoaded;
    if (dt>0.2){
      const spd=dl/dt; speeds.push(spd); if(speeds.length>6) speeds.shift();
      const avg=speeds.reduce((a,b)=>a+b,0)/speeds.length;
      const eta=(total-e.loaded)/avg;
      document.getElementById('upSpeed').textContent   = fmtSpeed(avg);
      document.getElementById('upETA').textContent     = fmtETA(eta);
      document.getElementById('upTransfer').textContent= `${fmtBytes(e.loaded)} / ${fmtBytes(total)}`;
      lastLoaded=e.loaded; lastTime=now;
    }
    const pct=(e.loaded/e.total)*88;
    setProgress(pct);
    if (pct>=75) { setUpStage('process'); document.getElementById('upTitle').textContent='Processing…'; }
  });

  xhr.addEventListener('load',()=>{
    setProgress(100);
    document.getElementById('upSpeed').textContent ='—';
    document.getElementById('upETA').textContent   ='Complete!';
    document.getElementById('upTransfer').textContent=fmtBytes(total)+' / '+fmtBytes(total);
    setUpStage('done');

    // Try parsing JSON — if it fails, show the raw response for diagnosis
    let res;
    try {
      res = JSON.parse(xhr.responseText);
    } catch(e) {
      const preview = xhr.responseText.substring(0,400).replace(/</g,'&lt;');
      uploadErr(
        `Server returned non-JSON response (HTTP ${xhr.status}). ` +
        `Raw output: <br><code style="font-size:0.72rem;word-break:break-all;">${preview}</code>`
      );
      console.error('Raw server response:', xhr.responseText);
      return;
    }

    if (res.success) {
      document.getElementById('upTitle').textContent  ='Published!';
      document.getElementById('upStatus').textContent ='Your track is now live on Keychele.';
      setTimeout(()=>showSuccess(res.share_url, res.title), 900);
    } else {
      // Build detailed error with debug info if present
      let msg = res.error || 'Upload failed. Please try again.';
      if (res.debug) {
        console.group('🔴 Upload Debug Info');
        Object.entries(res.debug).forEach(([k,v]) => console.log(`${k}:`, v));
        console.groupEnd();
        // Append key debug fields to the visible error
        const d = res.debug;
        const extras = [];
        if (d.php_upload_max_filesize) extras.push(`upload_max_filesize: ${d.php_upload_max_filesize}`);
        if (d.php_post_max_size)       extras.push(`post_max_size: ${d.php_post_max_size}`);
        if (d.audio_dir_writable === false) extras.push('audio dir NOT writable');
        if (d.session_creator_id === 'NOT SET') extras.push('⚠️ Session lost — please re-login');
        if (extras.length) msg += `<br><small style="opacity:0.75">${extras.join(' · ')}</small>`;
      }
      uploadErr(msg);
    }
  });

  xhr.addEventListener('error', ()=>uploadErr('Network error. Check your connection.'));
  xhr.addEventListener('abort', ()=>uploadErr('Upload cancelled.'));
  xhr.open('POST','index.php?page=add_music');
  xhr.send(fd);
}

function uploadErr(msg){
  document.getElementById('uploadPanel').classList.remove('show');
  document.getElementById('stepProgress').style.display='';
  for(let i=0;i<TOTAL_STEPS;i++){
    const sp=document.getElementById('panel-'+i);
    sp.style.display='';
    sp.classList.remove('active');
  }
  document.getElementById('panel-3').classList.add('active');
  document.getElementById('publishBtn').disabled=false;
  toast(msg,'e',12000); // long duration so user can read details
}

/* ══════════════════════════════════════
   PROGRESS HELPERS
══════════════════════════════════════ */
const CIRC = 238.76;
function setProgress(pct){
  document.getElementById('ringFill').style.strokeDashoffset = CIRC - (pct/100*CIRC);
  document.getElementById('ringPct').textContent  = Math.round(pct)+'%';
  document.getElementById('barFill').style.width  = pct+'%';
  document.getElementById('barPct').textContent   = Math.round(pct)+'%';
}

function setUpStage(name){
  const order=['validate','upload','process','done'];
  const idx=order.indexOf(name);
  order.forEach((s,i)=>{
    const el=document.getElementById('st-'+s);
    el.classList.remove('active','done');
    if(i<idx)        el.classList.add('done');
    else if(i===idx) el.classList.add('active');
  });
}

/* ══════════════════════════════════════
   SUCCESS
══════════════════════════════════════ */
function showSuccess(url, title){
  document.getElementById('uploadPanel').classList.remove('show');
  document.getElementById('successScreen').classList.add('show');
  document.getElementById('shareUrl').textContent=url;
  const enc=encodeURIComponent(url), tx=encodeURIComponent(`Listen to "${title}" on Keychele! `);
  document.getElementById('socialRow').innerHTML=`
    <a href="https://wa.me/?text=${tx}${enc}" target="_blank" rel="noopener" class="soc-btn soc-wa"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
    <a href="https://www.facebook.com/sharer/sharer.php?u=${enc}" target="_blank" rel="noopener" class="soc-btn soc-fb"><i class="fa-brands fa-facebook-f"></i> Facebook</a>
    <a href="https://twitter.com/intent/tweet?text=${tx}&url=${enc}" target="_blank" rel="noopener" class="soc-btn soc-tw"><i class="fa-brands fa-x-twitter"></i> X</a>
    <a href="mailto:?subject=${encodeURIComponent('New music: '+title)}&body=${encodeURIComponent('Listen here: '+url)}" class="soc-btn soc-email"><i class="fa-solid fa-envelope"></i> Email</a>
  `;
  toast('🎵 Track published!','s',5000);
  window.scrollTo({top:0,behavior:'smooth'});
}

/* ══════════════════════════════════════
   COPY LINK
══════════════════════════════════════ */
function copyLink(){
  const url=document.getElementById('shareUrl').textContent.trim();
  const btn=document.getElementById('copyBtn');
  const ok=()=>{
    btn.innerHTML='<i class="fa-solid fa-check"></i> Copied!';
    btn.classList.add('copied');
    toast('Link copied!','s',2000);
    setTimeout(()=>{ btn.innerHTML='<i class="fa-solid fa-copy"></i> Copy'; btn.classList.remove('copied'); },2500);
  };
  if(navigator.clipboard) navigator.clipboard.writeText(url).then(ok).catch(()=>fbCopy(url,ok));
  else fbCopy(url,ok);
}
function fbCopy(url,cb){
  const ta=Object.assign(document.createElement('textarea'),{value:url});
  Object.assign(ta.style,{position:'fixed',opacity:'0'});
  document.body.appendChild(ta); ta.focus(); ta.select();
  try{document.execCommand('copy');cb();}catch(e){}
  ta.remove();
}

/* ══════════════════════════════════════
   FORMAT HELPERS
══════════════════════════════════════ */
function fmtBytes(b){ if(b<1024)return b+' B'; if(b<1048576)return(b/1024).toFixed(1)+' KB'; return(b/1048576).toFixed(2)+' MB'; }
function fmtSpeed(bps){ if(bps<1024)return bps.toFixed(0)+' B/s'; if(bps<1048576)return(bps/1024).toFixed(1)+' KB/s'; return(bps/1048576).toFixed(2)+' MB/s'; }
function fmtETA(s){ if(!isFinite(s)||s<=0)return 'Almost done…'; if(s<60)return Math.ceil(s)+'s'; return Math.floor(s/60)+'m '+Math.ceil(s%60)+'s'; }

/* ══════════════════════════════════════
   CANVAS POLYFILL
══════════════════════════════════════ */
if(!CanvasRenderingContext2D.prototype.roundRect){
  CanvasRenderingContext2D.prototype.roundRect=function(x,y,w,h,r){
    this.beginPath();
    this.moveTo(x+r,y); this.arcTo(x+w,y,x+w,y+h,r); this.arcTo(x+w,y+h,x,y+h,r);
    this.arcTo(x,y+h,x,y,r); this.arcTo(x,y,x+w,y,r); this.closePath();
  };
}

/* ══════════════════════════════════════
   INIT
══════════════════════════════════════ */
(function(){
  const d=document.getElementById('f_release_date');
  if(d&&!d.value) d.value=new Date().toISOString().split('T')[0];
})();
</script>
</body>
</html>