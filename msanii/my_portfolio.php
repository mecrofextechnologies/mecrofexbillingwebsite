<?php
/**
 * portfolio.php — Keychele Creator Portfolio
 *
 * ── MIGRATION REQUIRED (run once) ────────────────────────────────────────────
 *   ALTER TABLE portfolio ADD COLUMN project_url VARCHAR(500) NULL AFTER youtube_id;
 *   ALTER TABLE portfolio ADD COLUMN tech_stack  VARCHAR(300) NULL AFTER project_url;
 * ─────────────────────────────────────────────────────────────────────────────
 */
include 'includes/connect.php';
include 'check_user_page.php';

if (!isset($_SESSION['creator_id'])) {
    header("Location: ../login");
    exit;
}

$creator_id = $_SESSION['creator_id'];
$success_msg = '';
$error_msg   = '';

// ── Fetch creator info ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.full_name, c.username, c.email, mp.profile_pic
    FROM creators c
    LEFT JOIN my_page mp ON c.id = mp.creator_id
    WHERE c.id = ?
");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);

$display_name = $creator['full_name'] ?? $creator['username'] ?? 'Creator';
$parts        = array_filter(explode(' ', trim($display_name)));
$initials     = count($parts) >= 2
    ? strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1))
    : strtoupper(mb_substr($display_name, 0, 2));

// ────────────────────────────────────────────────────────────────────────────
//  CONSTANTS & WHITELISTS
// ────────────────────────────────────────────────────────────────────────────
const MAX_IMAGE_SIZE = 5242880;   // 5 MB
const MAX_DOC_SIZE   = 10485760;  // 10 MB

$ALLOWED_IMAGE_EXTS  = ['jpg','jpeg','png','gif','webp'];
$ALLOWED_IMAGE_MIMES = ['image/jpeg','image/png','image/gif','image/webp'];

$ALLOWED_DOC_EXTS  = ['pdf','doc','docx','ppt','pptx','xls','xlsx'];
$ALLOWED_DOC_MIMES = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

$UPLOAD_DIRS = [
    'visual'      => 'msanii/uploads/visual/',
    'photography' => 'msanii/uploads/photography/',
    'document'    => 'msanii/uploads/documents/',
    'web'         => 'msanii/uploads/web/',
];

// ────────────────────────────────────────────────────────────────────────────
//  SECURE UPLOAD HELPER
// ────────────────────────────────────────────────────────────────────────────
function secureUpload(array $file, array $allowedExts, array $allowedMimes,
                      int $maxBytes, string $uploadDir, bool $isImage = false): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $codes = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
        ];
        return ['error' => $codes[$file['error']] ?? 'Unknown upload error.'];
    }
    if ($file['size'] > $maxBytes) {
        return ['error' => 'File too large. Max ' . round($maxBytes/1048576) . ' MB.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        return ['error' => "File type .{$ext} is not allowed."];
    }
    if (!function_exists('finfo_open')) {
        return ['error' => 'Server fileinfo extension unavailable.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimes, true)) {
        return ['error' => "Detected MIME ({$mime}) not allowed."];
    }
    if ($isImage) {
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !in_array($info['mime'] ?? '', $allowedMimes, true)) {
            return ['error' => 'Invalid or tampered image file.'];
        }
    }
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return ['error' => 'Could not create upload directory.'];
    }
    $ht = $uploadDir . '.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht,
            "Options -Indexes\n<FilesMatch \"\\.ph(?:p[2-9]?|tml)\$\">\n  Deny from all\n</FilesMatch>\n");
    }
    try { $safe = bin2hex(random_bytes(16)) . '.' . $ext; }
    catch (Exception $e) { $safe = uniqid('', true) . '.' . $ext; }

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safe)) {
        return ['error' => 'Failed to save file. Please try again.'];
    }
    return ['ok' => $safe];
}

// ────────────────────────────────────────────────────────────────────────────
//  FORM HANDLING
// ────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    try {

        // ── DELETE ITEM ───────────────────────────────────────────────────
        if ($action === 'delete_item') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM portfolio WHERE id = ? AND creator_id = ?");
            $stmt->execute([$item_id, $creator_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                if (!empty($item['file_path']) && isset($UPLOAD_DIRS[$item['category']])) {
                    $fp = $UPLOAD_DIRS[$item['category']] . $item['file_path'];
                    if (file_exists($fp)) @unlink($fp);
                }
                $pdo->prepare("DELETE FROM portfolio WHERE id = ? AND creator_id = ?")->execute([$item_id, $creator_id]);
                $success_msg = "Item deleted successfully.";
            } else {
                $error_msg = "Item not found or access denied.";
            }
        }

        // ── EDIT ITEM ─────────────────────────────────────────────────────
        elseif ($action === 'edit_item') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $title   = htmlspecialchars(substr(trim($_POST['edit_title'] ?? ''), 0, 150), ENT_QUOTES, 'UTF-8');
            $desc    = htmlspecialchars(substr(trim($_POST['edit_desc']  ?? ''), 0, 500), ENT_QUOTES, 'UTF-8');
            $url     = filter_var(trim($_POST['edit_url'] ?? ''), FILTER_VALIDATE_URL) ?: null;
            $tech    = htmlspecialchars(substr(trim($_POST['edit_tech'] ?? ''), 0, 300), ENT_QUOTES, 'UTF-8');
            $stmt    = $pdo->prepare("UPDATE portfolio SET title=?, description=?, project_url=?, tech_stack=? WHERE id=? AND creator_id=?");
            $stmt->execute([$title, $desc, $url, $tech ?: null, $item_id, $creator_id]);
            $success_msg = "Item updated successfully.";
        }

        // ── ADD VISUAL ────────────────────────────────────────────────────
        elseif ($action === 'add_visual') {
            $uploaded = 0; $errors = [];
            if (empty($_FILES['visual_images']['name'][0])) {
                $error_msg = "Select at least one image to upload.";
            } else {
                $count = count($_FILES['visual_images']['tmp_name']);
                for ($i = 0; $i < $count; $i++) {
                    $single = [
                        'error'=>$_FILES['visual_images']['error'][$i],
                        'tmp_name'=>$_FILES['visual_images']['tmp_name'][$i],
                        'size'=>$_FILES['visual_images']['size'][$i],
                        'name'=>$_FILES['visual_images']['name'][$i],
                    ];
                    $res = secureUpload($single, $ALLOWED_IMAGE_EXTS, $ALLOWED_IMAGE_MIMES, MAX_IMAGE_SIZE, $UPLOAD_DIRS['visual'], true);
                    if (isset($res['ok'])) {
                        $title = htmlspecialchars(substr(trim($_POST['visual_titles'][$i] ?? 'Untitled'), 0, 150), ENT_QUOTES, 'UTF-8');
                        $pdo->prepare("INSERT INTO portfolio (creator_id,category,file_path,title,created_at) VALUES (?,?,?,?,NOW())")->execute([$creator_id,'visual',$res['ok'],$title]);
                        $uploaded++;
                    } else { $errors[] = basename($_FILES['visual_images']['name'][$i]).': '.$res['error']; }
                }
                if ($uploaded) $success_msg = "{$uploaded} visual work(s) added!";
                if ($errors)   $error_msg   = implode(' | ', $errors);
            }
        }

        // ── ADD VIDEO ─────────────────────────────────────────────────────
        elseif ($action === 'add_video') {
            $yt    = trim($_POST['youtube_url'] ?? '');
            $title = htmlspecialchars(substr(trim($_POST['video_title'] ?? ''), 0, 150), ENT_QUOTES, 'UTF-8');
            if (!$yt || !$title) {
                $error_msg = "YouTube URL and title are required.";
            } else {
                preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([A-Za-z0-9_\-]{11})/', $yt, $m);
                $vid = $m[1] ?? '';
                if (!$vid) { $error_msg = "Invalid YouTube URL."; }
                else {
                    $pdo->prepare("INSERT INTO portfolio (creator_id,category,youtube_id,title,created_at) VALUES (?,?,?,?,NOW())")->execute([$creator_id,'video',$vid,$title]);
                    $success_msg = "Video added!";
                }
            }
        }

        // ── ADD DOCUMENT ──────────────────────────────────────────────────
        elseif ($action === 'add_document') {
            if (empty($_FILES['doc_file']['name'])) {
                $error_msg = "Select a document to upload.";
            } else {
                $res = secureUpload($_FILES['doc_file'], $ALLOWED_DOC_EXTS, $ALLOWED_DOC_MIMES, MAX_DOC_SIZE, $UPLOAD_DIRS['document'], false);
                if (isset($res['ok'])) {
                    $title = htmlspecialchars(substr(trim($_POST['doc_title'] ?? 'Untitled'), 0, 150), ENT_QUOTES, 'UTF-8');
                    $desc  = htmlspecialchars(substr(trim($_POST['doc_desc']  ?? ''),         0, 500), ENT_QUOTES, 'UTF-8');
                    $pdo->prepare("INSERT INTO portfolio (creator_id,category,file_path,title,description,created_at) VALUES (?,?,?,?,?,NOW())")->execute([$creator_id,'document',$res['ok'],$title,$desc]);
                    $success_msg = "Document uploaded!";
                } else { $error_msg = $res['error']; }
            }
        }

        // ── ADD PHOTO ─────────────────────────────────────────────────────
        elseif ($action === 'add_photo') {
            $uploaded = 0; $errors = [];
            if (empty($_FILES['photo_images']['name'][0])) {
                $error_msg = "Select at least one photo.";
            } else {
                $count = count($_FILES['photo_images']['tmp_name']);
                for ($i = 0; $i < $count; $i++) {
                    $single = [
                        'error'=>$_FILES['photo_images']['error'][$i],
                        'tmp_name'=>$_FILES['photo_images']['tmp_name'][$i],
                        'size'=>$_FILES['photo_images']['size'][$i],
                        'name'=>$_FILES['photo_images']['name'][$i],
                    ];
                    $res = secureUpload($single, $ALLOWED_IMAGE_EXTS, $ALLOWED_IMAGE_MIMES, MAX_IMAGE_SIZE, $UPLOAD_DIRS['photography'], true);
                    if (isset($res['ok'])) {
                        $title = htmlspecialchars(substr(trim($_POST['photo_titles'][$i] ?? 'Untitled'), 0, 150), ENT_QUOTES, 'UTF-8');
                        $pdo->prepare("INSERT INTO portfolio (creator_id,category,file_path,title,created_at) VALUES (?,?,?,?,NOW())")->execute([$creator_id,'photography',$res['ok'],$title]);
                        $uploaded++;
                    } else { $errors[] = basename($_FILES['photo_images']['name'][$i]).': '.$res['error']; }
                }
                if ($uploaded) $success_msg = "{$uploaded} photo(s) added!";
                if ($errors)   $error_msg   = implode(' | ', $errors);
            }
        }

        // ── ADD WEB PROJECT ───────────────────────────────────────────────
        elseif ($action === 'add_web') {
            $project_url = filter_var(trim($_POST['project_url'] ?? ''), FILTER_VALIDATE_URL);
            $title       = htmlspecialchars(substr(trim($_POST['web_title'] ?? ''), 0, 150), ENT_QUOTES, 'UTF-8');
            $desc        = htmlspecialchars(substr(trim($_POST['web_desc']  ?? ''), 0, 500), ENT_QUOTES, 'UTF-8');
            $tech        = htmlspecialchars(substr(trim($_POST['web_tech']  ?? ''), 0, 300), ENT_QUOTES, 'UTF-8');

            if (!$project_url || !$title) {
                $error_msg = "Project URL and title are required.";
            } else {
                $screenshot = null;
                if (!empty($_FILES['web_screenshot']['name'])) {
                    $res = secureUpload($_FILES['web_screenshot'], $ALLOWED_IMAGE_EXTS, $ALLOWED_IMAGE_MIMES, MAX_IMAGE_SIZE, $UPLOAD_DIRS['web'], true);
                    if (isset($res['ok']))  { $screenshot = $res['ok']; }
                    else { $error_msg = $res['error']; }
                }
                if (!$error_msg) {
                    $pdo->prepare("INSERT INTO portfolio (creator_id,category,file_path,project_url,tech_stack,title,description,created_at) VALUES (?,?,?,?,?,?,?,NOW())")
                        ->execute([$creator_id,'web',$screenshot,$project_url,$tech ?: null,$title,$desc]);
                    $success_msg = "Web project added!";
                }
            }
        }

    } catch (Exception $e) {
        $error_msg = "Unexpected error. Please try again.";
        // error_log($e->getMessage());
    }
}

// ── Fetch all portfolio items ──────────────────────────────────────────────
$portfolio = [];
foreach (['visual','video','document','photography','web'] as $cat) {
    $stmt = $pdo->prepare("SELECT * FROM portfolio WHERE creator_id = ? AND category = ? ORDER BY created_at DESC");
    $stmt->execute([$creator_id, $cat]);
    $portfolio[$cat] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Portfolio — Keychele</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

  <style>
  @font-face {
    font-family: 'GlacialI';
    src: url('https://db.onlinewebfonts.com/t/74f7b46c5a6a4374c10b2cb3ef8b4fc1.woff2') format('woff2');
  }

  :root {
    --red:     #bf1339;
    --red-l:   #e8143f;
    --gold:    #fec411;
    --gold-d:  #d4a60a;
    --navy:    #052e64;
    --navy-l:  #0a4494;
    --purple:  #6d28d9;
    --purple-l:#7c3aed;
    --bg:      #eef1f8;
    --card:    #ffffff;
    --border:  rgba(5,46,100,0.1);
    --text:    #0f1923;
    --muted:   #6b7a99;
    --success: #00b37e;
    --r:       14px;
    --r-lg:    20px;
    --sh:      0 4px 24px rgba(5,46,100,0.10);
    --sh-lg:   0 12px 48px rgba(5,46,100,0.15);
    --trn:     0.22s ease;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'GlacialI','Trebuchet MS',sans-serif;
    background: var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
    padding-bottom: 80px;
    min-height: 100vh;
  }
  h1,h2,h3,h4,h5 { font-family: 'Montserrat', sans-serif; }

  /* PAGE */
  .page { padding: 1.5rem; max-width: 900px; margin: 0 auto; }
  @media (max-width:600px) { .page { padding: 1rem 0.9rem; } }

  /* HERO */
  .page-hero {
    position:relative; overflow:hidden;
    background:linear-gradient(120deg, var(--navy) 0%, #0a3a7a 55%, #0f4faa 100%);
    border-radius:var(--r-lg); padding:2rem 2.25rem; margin-bottom:1.75rem;
    box-shadow:var(--sh-lg), 0 0 0 1px rgba(255,255,255,0.05) inset;
    animation:heroIn 0.55s cubic-bezier(0.16,1,0.3,1) both;
  }
  @keyframes heroIn { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
  .page-hero::before {
    content:''; position:absolute; inset:0; pointer-events:none;
    background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);
    background-size:34px 34px;
  }
  .hero-orb1 { position:absolute;border-radius:50%;pointer-events:none;width:280px;height:280px;top:-100px;right:-60px;background:radial-gradient(circle,rgba(254,196,17,.16) 0%,transparent 65%); }
  .hero-orb2 { position:absolute;border-radius:50%;pointer-events:none;width:180px;height:180px;bottom:-70px;left:15%;background:radial-gradient(circle,rgba(191,19,57,.18) 0%,transparent 65%); }
  .hero-inner { position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:1.5rem; }
  .hero-tag {
    display:inline-flex;align-items:center;gap:6px;
    background:rgba(254,196,17,.14);border:1px solid rgba(254,196,17,.28);
    border-radius:100px;padding:4px 12px;font-family:'Montserrat',sans-serif;
    font-size:0.65rem;font-weight:700;letter-spacing:0.09em;text-transform:uppercase;
    color:var(--gold);margin-bottom:0.65rem;
  }
  .hero-title { font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(1.4rem,3vw,2rem);color:white;letter-spacing:-0.04em;line-height:1.15;margin-bottom:0.35rem; }
  .hero-sub   { font-size:0.83rem;color:rgba(255,255,255,.5); }
  .hero-avatar {
    width:72px;height:72px;border-radius:50%;
    background:linear-gradient(135deg,var(--red),var(--red-l));
    display:flex;align-items:center;justify-content:center;
    font-family:'Montserrat',sans-serif;font-weight:800;font-size:1.5rem;color:white;
    border:3px solid rgba(255,255,255,.2);box-shadow:0 0 0 5px rgba(191,19,57,.2);
    flex-shrink:0;overflow:hidden;
  }
  .hero-avatar img { width:100%;height:100%;object-fit:cover; }

  /* STATS */
  .stats-row { display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.75rem; }
  @media (max-width:700px) { .stats-row { grid-template-columns:repeat(3,1fr); } }
  @media (max-width:480px) { .stats-row { grid-template-columns:repeat(2,1fr); } }
  .stat-card {
    background:var(--card);border:1px solid var(--border);border-radius:var(--r);
    box-shadow:var(--sh);padding:1rem 1.1rem;
    display:flex;align-items:center;gap:12px;
    animation:cardIn 0.5s cubic-bezier(0.16,1,0.3,1) 0.1s both;
  }
  .stat-ico { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0; }
  .stat-num { font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.5rem;line-height:1;color:var(--text); }
  .stat-lbl { font-size:0.68rem;color:var(--muted);font-family:'Montserrat',sans-serif;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;margin-top:2px; }

  /* TABS */
  .tabs-wrap {
    display:flex;gap:0.4rem;background:var(--card);border:1px solid var(--border);
    border-radius:var(--r);padding:6px;margin-bottom:1.5rem;
    box-shadow:var(--sh);overflow-x:auto;scrollbar-width:none;
  }
  .tabs-wrap::-webkit-scrollbar { display:none; }
  .tab-btn {
    flex:1;min-width:110px;display:flex;align-items:center;justify-content:center;gap:7px;
    padding:10px 12px;border:none;border-radius:10px;
    font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.73rem;
    text-transform:uppercase;letter-spacing:0.04em;cursor:pointer;
    transition:all var(--trn);color:var(--muted);background:transparent;white-space:nowrap;
  }
  .tab-btn i { font-size:0.78rem; }
  .tab-btn.active { background:linear-gradient(135deg,var(--navy),var(--navy-l));color:white;box-shadow:0 4px 16px rgba(5,46,100,.28); }
  .tab-btn:not(.active):hover { background:rgba(5,46,100,.06);color:var(--navy); }

  /* SEC LABEL */
  .sec-label {
    font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;
    text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);
    display:flex;align-items:center;gap:8px;margin-bottom:1rem;
  }
  .sec-label::before { content:'';width:3px;height:14px;border-radius:3px;background:linear-gradient(180deg,var(--red),var(--gold));flex-shrink:0; }

  /* CARD */
  .card { background:var(--card);border-radius:var(--r-lg);border:1px solid var(--border);box-shadow:var(--sh);overflow:hidden;margin-bottom:1.25rem;animation:cardIn 0.5s cubic-bezier(0.16,1,0.3,1) 0.05s both; }
  @keyframes cardIn { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
  .card-head { padding:1.15rem 1.5rem 1rem;border-bottom:1px solid var(--border);background:#fafbfd;display:flex;align-items:center;gap:10px; }
  .card-head-ico { width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:0.88rem;flex-shrink:0; }
  .card-head-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.9rem;color:var(--text); }
  .card-head-sub   { font-size:0.72rem;color:var(--muted);margin-top:2px; }
  .card-body { padding:1.5rem; }

  /* FIELDS */
  .field { margin-bottom:1.15rem; }
  .field-label { display:block;font-family:'Montserrat',sans-serif;font-size:0.73rem;font-weight:700;color:#374151;margin-bottom:0.45rem;letter-spacing:0.01em; }
  .field-hint  { font-size:0.68rem;color:var(--muted);margin-top:5px; }
  .fi { position:relative; }
  .fi-icon { position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:0.82rem;color:#9ca3af;pointer-events:none;z-index:1; }

  input[type=text],input[type=url],input[type=email],textarea {
    width:100%;font-family:'GlacialI','Trebuchet MS',sans-serif;
    font-size:0.88rem;color:var(--text);background:#fafafa;
    border:1.5px solid rgba(5,46,100,.14);border-radius:10px;
    padding:11px 14px 11px 40px;outline:none;appearance:none;
    transition:border-color var(--trn),box-shadow var(--trn),background var(--trn);
    resize:vertical;
  }
  textarea { padding-top:12px;min-height:80px; }
  input[type=text].no-icon,input[type=url].no-icon,textarea.no-icon { padding-left:14px; }
  input:focus,textarea:focus { border-color:var(--navy);background:#fff;box-shadow:0 0 0 3px rgba(5,46,100,.08); }

  /* FILE DROP */
  .file-drop {
    position:relative;border:2px dashed rgba(5,46,100,.2);border-radius:12px;
    background:#f7f9fc;padding:1.5rem;text-align:center;cursor:pointer;
    transition:border-color var(--trn),background var(--trn);
  }
  .file-drop:hover,.file-drop.dragging { border-color:var(--navy);background:rgba(5,46,100,.04); }
  .file-drop input[type=file] { position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%; }
  .file-drop-ico  { font-size:2rem;color:var(--navy);opacity:.4;margin-bottom:0.5rem; }
  .file-drop-txt  { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;color:var(--muted); }
  .file-drop-hint { font-size:0.68rem;color:var(--muted);margin-top:4px; }
  .file-preview   { display:flex;flex-wrap:wrap;gap:8px;margin-top:10px; }
  .file-prev-chip {
    display:flex;align-items:center;gap:6px;background:rgba(5,46,100,.07);
    border-radius:8px;padding:5px 10px;font-size:0.72rem;
    font-family:'Montserrat',sans-serif;font-weight:600;color:var(--navy);max-width:180px;
  }
  .file-prev-chip span { overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }

  .form-grid { display:grid;grid-template-columns:1fr 1fr;gap:1rem; }
  @media (max-width:580px) { .form-grid { grid-template-columns:1fr; } }

  /* BUTTONS */
  .add-btn {
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    height:46px;padding:0 1.5rem;
    background:linear-gradient(135deg,var(--red),var(--red-l));
    border:none;border-radius:var(--r);color:white;
    font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.82rem;letter-spacing:0.03em;
    cursor:pointer;box-shadow:0 4px 16px rgba(191,19,57,.3);
    transition:transform 0.2s,box-shadow 0.2s;margin-top:0.25rem;
  }
  .add-btn:hover { transform:translateY(-2px);box-shadow:0 8px 24px rgba(191,19,57,.42); }
  .add-btn:active { transform:translateY(0); }
  .add-btn:disabled { opacity:.6;cursor:not-allowed;transform:none; }

  /* ITEM ACTION BTNS */
  .item-actions {
    display:flex;gap:6px;
  }
  .ia-btn {
    width:30px;height:30px;border-radius:8px;border:none;
    display:flex;align-items:center;justify-content:center;
    font-size:0.72rem;cursor:pointer;transition:all 0.2s;
    font-family:'Montserrat',sans-serif;
  }
  .ia-edit { background:rgba(5,46,100,.1);color:var(--navy); }
  .ia-edit:hover { background:var(--navy);color:white; }
  .ia-del  { background:rgba(191,19,57,.1);color:var(--red); }
  .ia-del:hover  { background:var(--red);color:white; }

  /* ── GALLERY ── */
  .gallery { display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px; }
  .gallery-item { position:relative;border-radius:12px;overflow:hidden;box-shadow:var(--sh);aspect-ratio:1;background:#dde2ec;cursor:pointer; }
  .gallery-item img { width:100%;height:100%;object-fit:cover;transition:transform 0.4s; }
  .gallery-item:hover img { transform:scale(1.08); }
  .gallery-item-overlay {
    position:absolute;inset:0;
    background:linear-gradient(to top,rgba(5,46,100,.8) 0%,transparent 60%);
    opacity:0;transition:opacity 0.3s;
    display:flex;flex-direction:column;justify-content:flex-end;padding:10px;gap:6px;
  }
  .gallery-item:hover .gallery-item-overlay { opacity:1; }
  .gallery-item-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.72rem;color:white;line-height:1.3; }
  .gallery-item-btns  { display:flex;gap:5px; }
  .gi-btn {
    width:26px;height:26px;border-radius:7px;border:none;
    display:flex;align-items:center;justify-content:center;
    font-size:0.65rem;cursor:pointer;transition:all 0.2s;color:white;
  }
  .gi-edit { background:rgba(255,255,255,.2); }
  .gi-edit:hover { background:rgba(255,255,255,.4); }
  .gi-del  { background:rgba(191,19,57,.5); }
  .gi-del:hover  { background:var(--red); }

  /* Photography masonry */
  .photo-gallery { columns:3 160px;gap:10px; }
  .photo-gallery .gallery-item { break-inside:avoid;aspect-ratio:unset;margin-bottom:10px; }
  .photo-gallery .gallery-item img { height:auto; }

  /* Video grid */
  .video-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.25rem; }
  .video-card { background:#0a0a0f;border-radius:14px;overflow:hidden;box-shadow:var(--sh);transition:transform 0.3s,box-shadow 0.3s;position:relative; }
  .video-card:hover { transform:translateY(-4px);box-shadow:var(--sh-lg); }
  .video-card iframe { display:block;width:100%;aspect-ratio:16/9; }
  .video-card-body { padding:12px 14px;background:white;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px; }
  .video-card-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.85rem;color:var(--text);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }

  /* Document list */
  .doc-list { display:flex;flex-direction:column;gap:10px; }
  .doc-item { display:flex;align-items:center;gap:14px;background:#f7f9fc;border:1px solid var(--border);border-radius:12px;padding:14px;transition:background var(--trn),box-shadow var(--trn); }
  .doc-item:hover { background:white;box-shadow:var(--sh); }
  .doc-ico { width:44px;height:44px;border-radius:10px;background:rgba(191,19,57,.08);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--red);flex-shrink:0; }
  .doc-info { flex:1;min-width:0; }
  .doc-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.85rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
  .doc-desc  { font-size:0.72rem;color:var(--muted);margin-top:2px; }
  .doc-badge { font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:700;padding:3px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:0.05em;background:rgba(5,46,100,.08);color:var(--navy);flex-shrink:0; }
  .doc-dl { width:34px;height:34px;border-radius:9px;background:var(--navy);color:white;display:flex;align-items:center;justify-content:center;font-size:0.75rem;text-decoration:none;transition:background var(--trn),transform var(--trn);flex-shrink:0; }
  .doc-dl:hover { background:var(--navy-l);transform:translateY(-2px); }

  /* WEB PROJECTS */
  .web-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.25rem; }
  .web-card {
    background:white;border:1px solid var(--border);border-radius:16px;
    overflow:hidden;box-shadow:var(--sh);transition:transform 0.3s,box-shadow 0.3s;
    display:flex;flex-direction:column;
  }
  .web-card:hover { transform:translateY(-4px);box-shadow:var(--sh-lg); }
  .web-card-thumb {
    aspect-ratio:16/9;background:linear-gradient(135deg,#0d1b3e 0%,#1a3a6e 100%);
    position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;
  }
  .web-card-thumb img { width:100%;height:100%;object-fit:cover; }
  .web-card-no-thumb { font-size:2.5rem;opacity:.25;color:white; }
  .web-card-body { padding:14px 16px;flex:1;display:flex;flex-direction:column;gap:6px; }
  .web-card-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.9rem;color:var(--text); }
  .web-card-desc  { font-size:0.78rem;color:var(--muted);line-height:1.5;flex:1; }
  .web-card-tech  { display:flex;flex-wrap:wrap;gap:5px;margin-top:4px; }
  .tech-tag {
    font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:700;
    padding:3px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:0.04em;
    background:rgba(109,40,217,.1);color:var(--purple);
  }
  .web-card-footer { padding:12px 16px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px; }
  .web-visit-btn {
    display:inline-flex;align-items:center;gap:6px;
    font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:700;
    color:var(--purple);text-decoration:none;
    background:rgba(109,40,217,.08);border-radius:8px;padding:6px 12px;
    transition:all 0.2s;
  }
  .web-visit-btn:hover { background:var(--purple);color:white; }

  /* EMPTY */
  .empty-state { text-align:center;padding:3rem 1rem; }
  .empty-ico   { font-size:3rem;color:var(--border);margin-bottom:1rem;display:block; }
  .empty-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:1rem;color:var(--muted);margin-bottom:0.5rem; }
  .empty-sub   { font-size:0.8rem;color:var(--muted);opacity:.7; }

  .tab-pane { display:none; }
  .tab-pane.active { display:block; }

  /* TOASTR */
  #toast-container > .toast { border-radius:12px !important;box-shadow:0 8px 32px rgba(0,0,0,.14) !important;font-family:'GlacialI',sans-serif !important;font-size:0.875rem !important;padding:14px 18px 14px 56px !important; }
  #toast-container > .toast-success { background-color:#064E3B !important;border-left:4px solid #10B981 !important; }
  #toast-container > .toast-error   { background-color:#7F1D1D !important;border-left:4px solid #EF4444 !important; }
  #toast-container > .toast-info    { background-color:var(--navy) !important;border-left:4px solid var(--gold) !important; }

  @keyframes spin { to { transform:rotate(360deg); } }
  .spinner { animation:spin 0.8s linear infinite;display:inline-block; }

  /* LIGHTBOX */
  .lb { display:none;position:fixed;inset:0;z-index:9000;background:rgba(5,18,44,.92);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:1rem; }
  .lb.open { display:flex; }
  .lb img { max-width:90vw;max-height:88vh;border-radius:12px;box-shadow:var(--sh-lg); }
  .lb-close { position:absolute;top:1.25rem;right:1.25rem;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.1);border:none;color:white;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.2s; }
  .lb-close:hover { background:rgba(255,255,255,.22); }

  /* EDIT MODAL */
  .modal-backdrop { display:none;position:fixed;inset:0;z-index:8000;background:rgba(5,18,44,.75);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:1rem; }
  .modal-backdrop.open { display:flex; }
  .modal {
    background:white;border-radius:20px;box-shadow:var(--sh-lg);
    width:100%;max-width:480px;overflow:hidden;
    animation:modalIn 0.3s cubic-bezier(0.16,1,0.3,1);
  }
  @keyframes modalIn { from{opacity:0;transform:scale(.95) translateY(12px)} to{opacity:1;transform:scale(1) translateY(0)} }
  .modal-head {
    padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;gap:10px;
    background:#fafbfd;
  }
  .modal-title { font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.95rem;color:var(--text); }
  .modal-close { width:32px;height:32px;border-radius:8px;border:none;background:rgba(5,46,100,.08);color:var(--navy);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.85rem;transition:all 0.2s; }
  .modal-close:hover { background:var(--red);color:white; }
  .modal-body { padding:1.5rem; }
  .modal-footer { padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;background:#fafbfd; }
  .modal-save {
    display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 1.25rem;
    background:linear-gradient(135deg,var(--navy),var(--navy-l));border:none;border-radius:10px;
    color:white;font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;cursor:pointer;
    transition:transform 0.2s,box-shadow 0.2s;
  }
  .modal-save:hover { transform:translateY(-2px);box-shadow:0 6px 20px rgba(5,46,100,.32); }
  .modal-cancel { display:inline-flex;align-items:center;height:40px;padding:0 1.25rem;background:rgba(5,46,100,.06);border:none;border-radius:10px;color:var(--muted);font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;cursor:pointer;transition:background 0.2s; }
  .modal-cancel:hover { background:rgba(5,46,100,.12); }

  /* DELETE CONFIRM */
  .del-modal .modal-head { background:rgba(191,19,57,.06); }
  .del-title { color:var(--red); }
  .del-confirm {
    display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 1.25rem;
    background:linear-gradient(135deg,var(--red),var(--red-l));border:none;border-radius:10px;
    color:white;font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;cursor:pointer;
    transition:transform 0.2s,box-shadow 0.2s;
  }
  .del-confirm:hover { transform:translateY(-2px);box-shadow:0 6px 20px rgba(191,19,57,.35); }
  </style>
</head>
<body>

<!-- ── LIGHTBOX ── -->
<div class="lb" id="lightbox" onclick="closeLb(event)">
  <button class="lb-close" onclick="closeLb()"><i class="fa-solid fa-xmark"></i></button>
  <img id="lbImg" src="" alt="">
</div>

<!-- ── EDIT MODAL ── -->
<div class="modal-backdrop" id="editModal">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title"><i class="fa-solid fa-pen-to-square" style="margin-right:8px;color:var(--navy)"></i>Edit Item</div>
      <button class="modal-close" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="editForm">
      <input type="hidden" name="action" value="edit_item">
      <input type="hidden" name="item_id" id="edit_item_id">
      <div class="modal-body">
        <div class="field">
          <label class="field-label">Title</label>
          <div class="fi"><i class="fi-icon fa-solid fa-tag"></i><input type="text" name="edit_title" id="edit_title" required placeholder="Item title"></div>
        </div>
        <div class="field">
          <label class="field-label">Description / Notes</label>
          <textarea name="edit_desc" id="edit_desc" class="no-icon" placeholder="Optional description or notes"></textarea>
        </div>
        <div class="field" id="edit_url_field" style="display:none">
          <label class="field-label">Live URL</label>
          <div class="fi"><i class="fi-icon fa-solid fa-link"></i><input type="url" name="edit_url" id="edit_url" placeholder="https://..."></div>
        </div>
        <div class="field" id="edit_tech_field" style="display:none;margin-bottom:0">
          <label class="field-label">Tech Stack</label>
          <div class="fi"><i class="fi-icon fa-solid fa-code"></i><input type="text" name="edit_tech" id="edit_tech" placeholder="e.g. Laravel, Vue.js, MySQL"></div>
          <div class="field-hint">Comma-separated list of technologies used.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-cancel" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="modal-save"><i class="fa-solid fa-check"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ── DELETE CONFIRM MODAL ── -->
<div class="modal-backdrop" id="delModal">
  <div class="modal del-modal">
    <div class="modal-head">
      <div class="modal-title del-title"><i class="fa-solid fa-triangle-exclamation" style="margin-right:8px"></i>Delete Item</div>
      <button class="modal-close" onclick="closeDelModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST" id="delForm">
      <input type="hidden" name="action" value="delete_item">
      <input type="hidden" name="item_id" id="del_item_id">
      <div class="modal-body">
        <p style="font-size:0.88rem;color:var(--muted);line-height:1.6">
          Are you sure you want to delete <strong id="del_item_name" style="color:var(--text)"></strong>?<br>
          This action cannot be undone and the file will be permanently removed.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-cancel" onclick="closeDelModal()">Cancel</button>
        <button type="submit" class="del-confirm"><i class="fa-solid fa-trash"></i> Delete</button>
      </div>
    </form>
  </div>
</div>

<div class="page">

  <!-- HERO -->
  <div class="page-hero">
    <div class="hero-orb1"></div><div class="hero-orb2"></div>
    <div class="hero-inner">
      <div>
        <div class="hero-tag"><i class="fa-solid fa-briefcase"></i> Portfolio</div>
        <h1 class="hero-title">My Portfolio</h1>
        <p class="hero-sub">Showcase your best work &amp; attract clients</p>
      </div>
      <div class="hero-avatar">
        <?php if (!empty($creator['profile_pic'])): ?>
          <img src="<?= htmlspecialchars($creator['profile_pic']) ?>" alt="Profile">
        <?php else: ?>
          <?= htmlspecialchars($initials) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats-row">
    <?php
    $statDefs = [
      ['cat'=>'visual',      'icon'=>'fa-image',          'label'=>'Visual',    'color'=>'#052e64','bg'=>'rgba(5,46,100,0.08)'],
      ['cat'=>'video',       'icon'=>'fa-film',            'label'=>'Videos',    'color'=>'#bf1339','bg'=>'rgba(191,19,57,0.08)'],
      ['cat'=>'document',    'icon'=>'fa-file-lines',      'label'=>'Docs',      'color'=>'#d4a60a','bg'=>'rgba(212,166,10,0.08)'],
      ['cat'=>'photography', 'icon'=>'fa-camera-retro',    'label'=>'Photos',    'color'=>'#00b37e','bg'=>'rgba(0,179,126,0.08)'],
      ['cat'=>'web',         'icon'=>'fa-globe',           'label'=>'Web',       'color'=>'#6d28d9','bg'=>'rgba(109,40,217,0.08)'],
    ];
    foreach ($statDefs as $sd): ?>
    <div class="stat-card">
      <div class="stat-ico" style="background:<?= $sd['bg'] ?>;color:<?= $sd['color'] ?>"><i class="fa-solid <?= $sd['icon'] ?>"></i></div>
      <div>
        <div class="stat-num"><?= count($portfolio[$sd['cat']]) ?></div>
        <div class="stat-lbl"><?= $sd['label'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- TABS -->
  <div class="tabs-wrap">
    <button class="tab-btn active" onclick="showTab('visual',this)"><i class="fa-solid fa-image"></i> Visual</button>
    <button class="tab-btn" onclick="showTab('video',this)"><i class="fa-solid fa-film"></i> Videos</button>
    <button class="tab-btn" onclick="showTab('document',this)"><i class="fa-solid fa-file-lines"></i> Docs</button>
    <button class="tab-btn" onclick="showTab('photo',this)"><i class="fa-solid fa-camera-retro"></i> Photos</button>
    <button class="tab-btn" onclick="showTab('web',this)"><i class="fa-solid fa-globe"></i> Web</button>
  </div>

  <!-- ══════════════════ TAB: VISUAL WORKS ══════════════════ -->
  <div id="tab-visual" class="tab-pane active">
    <div class="sec-label">Visual Works</div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(5,46,100,.08);color:var(--navy)"><i class="fa-solid fa-image"></i></div>
        <div><div class="card-head-title">Add Visual Work</div><div class="card-head-sub">Designs, artwork, illustrations, graphics</div></div>
      </div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="form-visual" onsubmit="setLoading(this)">
          <input type="hidden" name="action" value="add_visual">
          <div class="form-grid" style="margin-bottom:1rem">
            <div class="field" style="margin-bottom:0">
              <label class="field-label">Upload Images <span style="color:var(--red)">*</span></label>
              <div class="file-drop">
                <input type="file" name="visual_images[]" multiple accept="image/jpeg,image/png,image/gif,image/webp" required id="input-visual" onchange="previewFiles(this,'prev-visual')">
                <div class="file-drop-ico"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                <div class="file-drop-txt">Click or drag images here</div>
                <div class="file-drop-hint">JPG, PNG, WebP, GIF · Max 5 MB each</div>
              </div>
              <div class="file-preview" id="prev-visual"></div>
            </div>
            <div class="field" style="margin-bottom:0">
              <label class="field-label">Titles <span style="font-weight:400;color:var(--muted)">(one per image)</span></label>
              <div class="fi"><i class="fi-icon fa-solid fa-tag"></i><input type="text" name="visual_titles[]" placeholder="e.g. Brand Identity for Acme Co."></div>
              <div class="field-hint">Enter a title for each image separated by commas, or one that applies to all.</div>
            </div>
          </div>
          <button type="submit" class="add-btn"><i class="fa-solid fa-plus"></i> Add Visual Work</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(5,46,100,.08);color:var(--navy)"><i class="fa-solid fa-grid-2"></i></div>
        <div><div class="card-head-title">Your Visual Works</div><div class="card-head-sub"><?= count($portfolio['visual']) ?> item(s)</div></div>
      </div>
      <div class="card-body">
        <?php if (empty($portfolio['visual'])): ?>
          <div class="empty-state"><i class="fa-solid fa-image empty-ico"></i><div class="empty-title">No visual works yet</div><div class="empty-sub">Upload your first design or illustration above.</div></div>
        <?php else: ?>
          <div class="gallery">
            <?php foreach ($portfolio['visual'] as $item): ?>
              <div class="gallery-item" onclick="openLb('msanii/uploads/visual/<?= htmlspecialchars($item['file_path']) ?>')">
                <img src="msanii/uploads/visual/<?= htmlspecialchars($item['file_path']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy">
                <div class="gallery-item-overlay">
                  <div class="gallery-item-title"><?= htmlspecialchars($item['title'] ?: 'Untitled') ?></div>
                  <div class="gallery-item-btns" onclick="event.stopPropagation()">
                    <button class="gi-btn gi-edit" title="Edit" onclick='openEditModal(<?= $item["id"] ?>, <?= json_encode($item["title"]) ?>, <?= json_encode($item["description"] ?? "") ?>, "", "", false)'><i class="fa-solid fa-pen"></i></button>
                    <button class="gi-btn gi-del"  title="Delete" onclick='openDelModal(<?= $item["id"] ?>, <?= json_encode($item["title"] ?: "Untitled") ?>)'><i class="fa-solid fa-trash"></i></button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══════════════════ TAB: VIDEOS ══════════════════ -->
  <div id="tab-video" class="tab-pane">
    <div class="sec-label">Video Projects</div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(191,19,57,.08);color:var(--red)"><i class="fa-solid fa-film"></i></div>
        <div><div class="card-head-title">Add a Video</div><div class="card-head-sub">Paste a YouTube link — films, reels, demos, showreels, tutorials</div></div>
      </div>
      <div class="card-body">
        <form method="POST" id="form-video" onsubmit="setLoading(this)">
          <input type="hidden" name="action" value="add_video">
          <div class="form-grid" style="margin-bottom:1rem">
            <div class="field" style="margin-bottom:0">
              <label class="field-label">YouTube URL <span style="color:var(--red)">*</span></label>
              <div class="fi"><i class="fi-icon fa-brands fa-youtube"></i><input type="url" name="youtube_url" required placeholder="https://www.youtube.com/watch?v=..."></div>
            </div>
            <div class="field" style="margin-bottom:0">
              <label class="field-label">Project Title <span style="color:var(--red)">*</span></label>
              <div class="fi"><i class="fi-icon fa-solid fa-tag"></i><input type="text" name="video_title" required placeholder="e.g. Wedding Highlight Reel 2024"></div>
            </div>
          </div>
          <button type="submit" class="add-btn"><i class="fa-solid fa-plus"></i> Add Video</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(191,19,57,.08);color:var(--red)"><i class="fa-solid fa-play"></i></div>
        <div><div class="card-head-title">Your Videos</div><div class="card-head-sub"><?= count($portfolio['video']) ?> video(s)</div></div>
      </div>
      <div class="card-body">
        <?php if (empty($portfolio['video'])): ?>
          <div class="empty-state"><i class="fa-solid fa-film empty-ico"></i><div class="empty-title">No videos yet</div><div class="empty-sub">Add a YouTube link above to showcase your video work.</div></div>
        <?php else: ?>
          <div class="video-grid">
            <?php foreach ($portfolio['video'] as $item): ?>
              <div class="video-card">
                <iframe src="https://www.youtube.com/embed/<?= htmlspecialchars($item['youtube_id']) ?>" frameborder="0" allowfullscreen loading="lazy"></iframe>
                <div class="video-card-body">
                  <div class="video-card-title"><?= htmlspecialchars($item['title']) ?></div>
                  <div class="item-actions">
                    <button class="ia-btn ia-edit" title="Edit" onclick='openEditModal(<?= $item["id"] ?>, <?= json_encode($item["title"]) ?>, <?= json_encode($item["description"] ?? "") ?>, "", "", false)'><i class="fa-solid fa-pen"></i></button>
                    <button class="ia-btn ia-del"  title="Delete" onclick='openDelModal(<?= $item["id"] ?>, <?= json_encode($item["title"]) ?>)'><i class="fa-solid fa-trash"></i></button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══════════════════ TAB: DOCUMENTS ══════════════════ -->
  <div id="tab-document" class="tab-pane">
    <div class="sec-label">Documents &amp; Case Studies</div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(212,166,10,.1);color:var(--gold-d)"><i class="fa-solid fa-file-arrow-up"></i></div>
        <div><div class="card-head-title">Upload a Document</div><div class="card-head-sub">CVs, case studies, proposals, presentations, research papers</div></div>
      </div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="form-doc" onsubmit="setLoading(this)">
          <input type="hidden" name="action" value="add_document">
          <div class="form-grid" style="margin-bottom:1rem">
            <div class="field" style="margin-bottom:0">
              <label class="field-label">Document File <span style="color:var(--red)">*</span></label>
              <div class="file-drop">
                <input type="file" name="doc_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx" required id="input-doc" onchange="previewDoc(this)">
                <div class="file-drop-ico"><i class="fa-solid fa-file-lines"></i></div>
                <div class="file-drop-txt">Click to select a document</div>
                <div class="file-drop-hint">PDF, Word, PowerPoint, Excel · Max 10 MB</div>
              </div>
              <div id="prev-doc" style="margin-top:8px"></div>
            </div>
            <div>
              <div class="field">
                <label class="field-label">Document Title <span style="color:var(--red)">*</span></label>
                <div class="fi"><i class="fi-icon fa-solid fa-tag"></i><input type="text" name="doc_title" required placeholder="e.g. Brand Strategy Proposal"></div>
              </div>
              <div class="field" style="margin-bottom:0">
                <label class="field-label">Short Description</label>
                <textarea name="doc_desc" class="no-icon" placeholder="What is this document about? (optional)"></textarea>
              </div>
            </div>
          </div>
          <button type="submit" class="add-btn"><i class="fa-solid fa-upload"></i> Upload Document</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(212,166,10,.1);color:var(--gold-d)"><i class="fa-solid fa-folder-open"></i></div>
        <div><div class="card-head-title">Your Documents</div><div class="card-head-sub"><?= count($portfolio['document']) ?> document(s)</div></div>
      </div>
      <div class="card-body">
        <?php if (empty($portfolio['document'])): ?>
          <div class="empty-state"><i class="fa-solid fa-folder-open empty-ico"></i><div class="empty-title">No documents yet</div><div class="empty-sub">Upload a CV, case study, or any professional document above.</div></div>
        <?php else: ?>
          <div class="doc-list">
            <?php
            $docIcons = [
              'pdf'=>['fa-file-pdf','#ef4444'],'doc'=>['fa-file-word','#2563eb'],'docx'=>['fa-file-word','#2563eb'],
              'ppt'=>['fa-file-powerpoint','#ea580c'],'pptx'=>['fa-file-powerpoint','#ea580c'],
              'xls'=>['fa-file-excel','#16a34a'],'xlsx'=>['fa-file-excel','#16a34a'],
            ];
            foreach ($portfolio['document'] as $item):
              $ext = strtolower(pathinfo($item['file_path'], PATHINFO_EXTENSION));
              [$dIco,$dColor] = $docIcons[$ext] ?? ['fa-file-lines','var(--navy)'];
            ?>
            <div class="doc-item">
              <div class="doc-ico" style="color:<?= $dColor ?>"><i class="fa-solid <?= $dIco ?>"></i></div>
              <div class="doc-info">
                <div class="doc-title"><?= htmlspecialchars($item['title']) ?></div>
                <?php if (!empty($item['description'])): ?>
                  <div class="doc-desc"><?= htmlspecialchars($item['description']) ?></div>
                <?php endif; ?>
              </div>
              <span class="doc-badge"><?= strtoupper($ext) ?></span>
              <div class="item-actions">
                <button class="ia-btn ia-edit" title="Edit" onclick='openEditModal(<?= $item["id"] ?>, <?= json_encode($item["title"]) ?>, <?= json_encode($item["description"] ?? "") ?>, "", "", false)'><i class="fa-solid fa-pen"></i></button>
                <button class="ia-btn ia-del"  title="Delete" onclick='openDelModal(<?= $item["id"] ?>, <?= json_encode($item["title"]) ?>)'><i class="fa-solid fa-trash"></i></button>
              </div>
              <a href="msanii/uploads/documents/<?= htmlspecialchars($item['file_path']) ?>" class="doc-dl" download title="Download"><i class="fa-solid fa-arrow-down"></i></a>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══════════════════ TAB: PHOTOGRAPHY ══════════════════ -->
  <div id="tab-photo" class="tab-pane">
    <div class="sec-label">Photography</div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(0,179,126,.1);color:var(--success)"><i class="fa-solid fa-camera-retro"></i></div>
        <div><div class="card-head-title">Add Photos</div><div class="card-head-sub">Portraits, events, nature, street, product photography</div></div>
      </div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="form-photo" onsubmit="setLoading(this)">
          <input type="hidden" name="action" value="add_photo">
          <div class="form-grid" style="margin-bottom:1rem">
            <div class="field" style="margin-bottom:0">
              <label class="field-label">Upload Photos <span style="color:var(--red)">*</span></label>
              <div class="file-drop">
                <input type="file" name="photo_images[]" multiple accept="image/jpeg,image/png,image/webp" required id="input-photo" onchange="previewFiles(this,'prev-photo')">
                <div class="file-drop-ico"><i class="fa-solid fa-camera"></i></div>
                <div class="file-drop-txt">Click or drag photos here</div>
                <div class="file-drop-hint">JPG, PNG, WebP · Max 5 MB each</div>
              </div>
              <div class="file-preview" id="prev-photo"></div>
            </div>
            <div class="field" style="margin-bottom:0">
              <label class="field-label">Titles <span style="font-weight:400;color:var(--muted)">(one per photo)</span></label>
              <div class="fi"><i class="fi-icon fa-solid fa-tag"></i><input type="text" name="photo_titles[]" placeholder="e.g. Nairobi Street Life"></div>
              <div class="field-hint">Name your shots to help clients understand your style.</div>
            </div>
          </div>
          <button type="submit" class="add-btn"><i class="fa-solid fa-plus"></i> Add Photos</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(0,179,126,.1);color:var(--success)"><i class="fa-solid fa-images"></i></div>
        <div><div class="card-head-title">Your Photography</div><div class="card-head-sub"><?= count($portfolio['photography']) ?> photo(s)</div></div>
      </div>
      <div class="card-body">
        <?php if (empty($portfolio['photography'])): ?>
          <div class="empty-state"><i class="fa-solid fa-camera empty-ico"></i><div class="empty-title">No photos yet</div><div class="empty-sub">Upload your first photograph to build your visual story.</div></div>
        <?php else: ?>
          <div class="photo-gallery">
            <?php foreach ($portfolio['photography'] as $item): ?>
              <div class="gallery-item" onclick="openLb('msanii/uploads/photography/<?= htmlspecialchars($item['file_path']) ?>')">
                <img src="msanii/uploads/photography/<?= htmlspecialchars($item['file_path']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy">
                <div class="gallery-item-overlay">
                  <div class="gallery-item-title"><?= htmlspecialchars($item['title'] ?: 'Photo') ?></div>
                  <div class="gallery-item-btns" onclick="event.stopPropagation()">
                    <button class="gi-btn gi-edit" title="Edit" onclick='openEditModal(<?= $item["id"] ?>, <?= json_encode($item["title"]) ?>, <?= json_encode($item["description"] ?? "") ?>, "", "", false)'><i class="fa-solid fa-pen"></i></button>
                    <button class="gi-btn gi-del"  title="Delete" onclick='openDelModal(<?= $item["id"] ?>, <?= json_encode($item["title"] ?: "Photo") ?>)'><i class="fa-solid fa-trash"></i></button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══════════════════ TAB: WEB PROJECTS ══════════════════ -->
  <div id="tab-web" class="tab-pane">
    <div class="sec-label">Web Projects</div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(109,40,217,.1);color:var(--purple)"><i class="fa-solid fa-globe"></i></div>
        <div><div class="card-head-title">Add a Web Project</div><div class="card-head-sub">Websites, web apps, landing pages, e-commerce, dashboards, portfolios</div></div>
      </div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="form-web" onsubmit="setLoading(this)">
          <input type="hidden" name="action" value="add_web">
          <div class="form-grid" style="margin-bottom:1rem">
            <div>
              <div class="field">
                <label class="field-label">Project Title <span style="color:var(--red)">*</span></label>
                <div class="fi"><i class="fi-icon fa-solid fa-tag"></i><input type="text" name="web_title" required placeholder="e.g. Kivex Mall E-Commerce Platform"></div>
              </div>
              <div class="field">
                <label class="field-label">Live URL <span style="color:var(--red)">*</span></label>
                <div class="fi"><i class="fi-icon fa-solid fa-link"></i><input type="url" name="project_url" required placeholder="https://yourproject.com"></div>
              </div>
              <div class="field" style="margin-bottom:0">
                <label class="field-label">Tech Stack</label>
                <div class="fi"><i class="fi-icon fa-solid fa-code"></i><input type="text" name="web_tech" placeholder="e.g. PHP, Laravel, Vue.js, MySQL, Tailwind"></div>
                <div class="field-hint">Comma-separated list of technologies used.</div>
              </div>
            </div>
            <div>
              <div class="field">
                <label class="field-label">Screenshot / Preview Image</label>
                <div class="file-drop">
                  <input type="file" name="web_screenshot" accept="image/jpeg,image/png,image/webp" id="input-web" onchange="previewDoc(this,'prev-web')">
                  <div class="file-drop-ico"><i class="fa-solid fa-desktop"></i></div>
                  <div class="file-drop-txt">Click to upload screenshot</div>
                  <div class="file-drop-hint">JPG, PNG, WebP · Max 5 MB · Optional</div>
                </div>
                <div id="prev-web" style="margin-top:8px"></div>
              </div>
              <div class="field" style="margin-bottom:0">
                <label class="field-label">Description</label>
                <textarea name="web_desc" class="no-icon" placeholder="Brief description of the project, your role, key features…" style="min-height:72px"></textarea>
              </div>
            </div>
          </div>
          <button type="submit" class="add-btn" style="background:linear-gradient(135deg,var(--purple),var(--purple-l));box-shadow:0 4px 16px rgba(109,40,217,.3)">
            <i class="fa-solid fa-plus"></i> Add Web Project
          </button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="card-head-ico" style="background:rgba(109,40,217,.1);color:var(--purple)"><i class="fa-solid fa-layer-group"></i></div>
        <div><div class="card-head-title">Your Web Projects</div><div class="card-head-sub"><?= count($portfolio['web']) ?> project(s)</div></div>
      </div>
      <div class="card-body">
        <?php if (empty($portfolio['web'])): ?>
          <div class="empty-state"><i class="fa-solid fa-globe empty-ico"></i><div class="empty-title">No web projects yet</div><div class="empty-sub">Add your first website or web app above.</div></div>
        <?php else: ?>
          <div class="web-grid">
            <?php foreach ($portfolio['web'] as $item): ?>
              <div class="web-card">
                <div class="web-card-thumb">
                  <?php if (!empty($item['file_path'])): ?>
                    <img src="msanii/uploads/web/<?= htmlspecialchars($item['file_path']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy">
                  <?php else: ?>
                    <div class="web-card-no-thumb"><i class="fa-solid fa-globe"></i></div>
                  <?php endif; ?>
                </div>
                <div class="web-card-body">
                  <div class="web-card-title"><?= htmlspecialchars($item['title']) ?></div>
                  <?php if (!empty($item['description'])): ?>
                    <div class="web-card-desc"><?= htmlspecialchars($item['description']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($item['tech_stack'])): ?>
                    <div class="web-card-tech">
                      <?php foreach (array_slice(array_map('trim', explode(',', $item['tech_stack'])), 0, 6) as $tech): ?>
                        <?php if ($tech): ?><span class="tech-tag"><?= htmlspecialchars($tech) ?></span><?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="web-card-footer">
                  <?php if (!empty($item['project_url'])): ?>
                    <a href="<?= htmlspecialchars($item['project_url']) ?>" target="_blank" rel="noopener" class="web-visit-btn">
                      <i class="fa-solid fa-arrow-up-right-from-square"></i> Visit Site
                    </a>
                  <?php else: ?>
                    <span></span>
                  <?php endif; ?>
                  <div class="item-actions">
                    <button class="ia-btn ia-edit" title="Edit"
                      onclick='openEditModal(<?= $item["id"] ?>, <?= json_encode($item["title"]) ?>, <?= json_encode($item["description"] ?? "") ?>, <?= json_encode($item["project_url"] ?? "") ?>, <?= json_encode($item["tech_stack"] ?? "") ?>, true)'>
                      <i class="fa-solid fa-pen"></i>
                    </button>
                    <button class="ia-btn ia-del" title="Delete" onclick='openDelModal(<?= $item["id"] ?>, <?= json_encode($item["title"]) ?>)'>
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /page -->

<script>
/* ── Toastr ── */
toastr.options = { closeButton:true, progressBar:true, positionClass:'toast-top-right', timeOut:5500, extendedTimeOut:1500, showMethod:'slideDown', hideMethod:'fadeOut' };
<?php if ($success_msg): ?>
toastr.success('<strong><?= addslashes($success_msg) ?></strong>','<i class="fa-solid fa-circle-check" style="margin-right:6px"></i> Done',{timeOut:6000});
<?php endif; ?>
<?php if ($error_msg): ?>
toastr.error('<strong><?= addslashes($error_msg) ?></strong><br><small>Please review and try again.</small>','<i class="fa-solid fa-circle-exclamation" style="margin-right:6px"></i> Error',{timeOut:8000});
<?php endif; ?>

/* ── Tabs ── */
function showTab(name, btn) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}

/* ── File previews ── */
function previewFiles(input, previewId) {
  const c = document.getElementById(previewId); c.innerHTML = '';
  Array.from(input.files).forEach(f => {
    const chip = document.createElement('div'); chip.className='file-prev-chip';
    chip.innerHTML = `<i class="fa-solid fa-image" style="font-size:.75rem;flex-shrink:0"></i><span>${f.name}</span>`;
    c.appendChild(chip);
  });
}
function previewDoc(input, previewId) {
  const id = previewId || 'prev-doc';
  const c  = document.getElementById(id); c.innerHTML = '';
  if (!input.files.length) return;
  const f = input.files[0];
  const chip = document.createElement('div'); chip.className='file-prev-chip';
  chip.innerHTML = `<i class="fa-solid fa-file-lines" style="font-size:.75rem;flex-shrink:0"></i><span>${f.name}</span>`;
  c.appendChild(chip);
}

/* ── Loading ── */
function setLoading(form) {
  const btn = form.querySelector('button[type=submit]'); if (!btn) return;
  btn.disabled = true;
  btn.innerHTML = `<svg class="spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Uploading...`;
}

/* ── Drag ── */
document.querySelectorAll('.file-drop').forEach(z => {
  z.addEventListener('dragover',  e => { e.preventDefault(); z.classList.add('dragging'); });
  z.addEventListener('dragleave', () => z.classList.remove('dragging'));
  z.addEventListener('drop',      () => z.classList.remove('dragging'));
});

/* ── Lightbox ── */
function openLb(src) { document.getElementById('lbImg').src=src; document.getElementById('lightbox').classList.add('open'); }
function closeLb(e)  { if (!e||e.target===document.getElementById('lightbox')||e.currentTarget.tagName==='BUTTON') { document.getElementById('lightbox').classList.remove('open'); document.getElementById('lbImg').src=''; } }
document.addEventListener('keydown', e => { if (e.key==='Escape') { closeLb(); closeEditModal(); closeDelModal(); } });

/* ── Edit Modal ── */
function openEditModal(id, title, desc, url, tech, isWeb) {
  document.getElementById('edit_item_id').value = id;
  document.getElementById('edit_title').value   = title;
  document.getElementById('edit_desc').value    = desc || '';
  document.getElementById('edit_url').value     = url  || '';
  document.getElementById('edit_tech').value    = tech || '';
  document.getElementById('edit_url_field').style.display  = isWeb ? '' : 'none';
  document.getElementById('edit_tech_field').style.display = isWeb ? '' : 'none';
  document.getElementById('editModal').classList.add('open');
}
function closeEditModal() { document.getElementById('editModal').classList.remove('open'); }
document.getElementById('editModal').addEventListener('click', e => { if (e.target===e.currentTarget) closeEditModal(); });

/* ── Delete Modal ── */
function openDelModal(id, name) {
  document.getElementById('del_item_id').value    = id;
  document.getElementById('del_item_name').textContent = name;
  document.getElementById('delModal').classList.add('open');
}
function closeDelModal() { document.getElementById('delModal').classList.remove('open'); }
document.getElementById('delModal').addEventListener('click', e => { if (e.target===e.currentTarget) closeDelModal(); });
</script>
</body>
</html>