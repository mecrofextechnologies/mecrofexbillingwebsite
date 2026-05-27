<?php
include 'includes/connect.php';
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) die("Please login first.");

/* ── Creator page check ── */
$pageStmt = $pdo->prepare("SELECT id FROM my_page WHERE creator_id = ? LIMIT 1");
$pageStmt->execute([$creator_id]);
$has_page = (bool)$pageStmt->fetchColumn();

/* ══════════════════════════════════════════════════════
   ALTER TABLE — add deposit + settings columns if missing
   Uses SHOW COLUMNS for MySQL 5.6+ compatibility
══════════════════════════════════════════════════════ */
try {
    /* ── event_ticket_types: deposit columns ── */
    $depEnabledCol = $pdo->query("SHOW COLUMNS FROM event_ticket_types LIKE 'deposit_enabled'")->fetchAll();
    if (empty($depEnabledCol)) {
        $pdo->exec("ALTER TABLE event_ticket_types
            ADD COLUMN deposit_enabled TINYINT(1) NOT NULL DEFAULT 0");
    }
    $depAmountCol = $pdo->query("SHOW COLUMNS FROM event_ticket_types LIKE 'deposit_amount'")->fetchAll();
    if (empty($depAmountCol)) {
        $pdo->exec("ALTER TABLE event_ticket_types
            ADD COLUMN deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    }

    /* ── tickets: visibility / view / remaining settings columns ── */
    $showViewsCol = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'show_views'")->fetchAll();
    if (empty($showViewsCol)) {
        $pdo->exec("ALTER TABLE tickets
            ADD COLUMN show_views TINYINT(1) NOT NULL DEFAULT 1
            COMMENT 'If 1, total view count is visible on the public homepage listing'");
    }
    $showRemainingCol = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'show_remaining'")->fetchAll();
    if (empty($showRemainingCol)) {
        $pdo->exec("ALTER TABLE tickets
            ADD COLUMN show_remaining TINYINT(1) NOT NULL DEFAULT 1
            COMMENT 'If 1, remaining ticket count is visible to the public'");
    }
    $visibilityCol = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'visibility'")->fetchAll();
    if (empty($visibilityCol)) {
        $pdo->exec("ALTER TABLE tickets
            ADD COLUMN visibility ENUM('public','private') NOT NULL DEFAULT 'public'
            COMMENT 'public = listed; private = accessible only via direct link'");
    }
} catch (PDOException $e) { /* silently ignore if table doesn't exist yet */ }

/* ── State variables ── */
$success      = $error = '';
$event_id     = null;
$event_title  = '';
$ticket_url   = '';
$error_step   = 3;   // which step panel to restore on error

/* ── Default ticket data ── */
$old_post = [];
$old_tickets_data = [
    ['name' => 'Regular', 'price' => '0', 'qty' => '', 'is_free' => false,
     'deposit_enabled' => false, 'deposit_amount' => '']
];

/* ── Default settings ── */
$old_settings = [
    'show_views'     => '1',
    'show_remaining' => '1',
    'visibility'     => 'public',
];

/* ═══════════════════════════════════════════
   SECURE FILE-UPLOAD HELPER
═══════════════════════════════════════════ */
function secureUploadBanner(array $file): ?string
{
    $max_bytes  = 3 * 1024 * 1024;
    $target_dir = "uploads/banners/";

    if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK)
        throw new RuntimeException("Upload error code: {$file['error']}");
    if ($file['size'] > $max_bytes)
        throw new RuntimeException("Banner must be 3 MB or smaller.");

    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($mime, $allowed, true))
        throw new RuntimeException("Only JPEG, PNG, WEBP or GIF images are allowed.");

    switch ($mime) {
        case 'image/jpeg': $img = @imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png':  $img = @imagecreatefrompng($file['tmp_name']);  break;
        case 'image/webp': $img = @imagecreatefromwebp($file['tmp_name']); break;
        case 'image/gif':  $img = @imagecreatefromgif($file['tmp_name']);  break;
        default:           $img = false;
    }
    if ($img === false)
        throw new RuntimeException("Could not process the uploaded image. Please try a different file.");

    if (!is_dir($target_dir)) mkdir($target_dir, 0750, true);

    $safe_name   = bin2hex(random_bytes(16)) . '.jpg';
    $destination = $target_dir . $safe_name;

    if (!imagejpeg($img, $destination, 88)) {
        imagedestroy($img);
        throw new RuntimeException("Failed to save the banner image.");
    }
    imagedestroy($img);

    $htaccess = $target_dir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess,
            "Options -Indexes\n<FilesMatch \"\\.php$\">\n  Deny from all\n</FilesMatch>\n");
    }
    return $safe_name;
}

/* ═══════════════════════════════════════════
   PROCESS POST
═══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $old_post = $_POST;

    /* ── Rebuild ticket data from POST ── */
    $submitted_types = $_POST['ticket_type'] ?? [];
    if (!empty($submitted_types)) {
        $old_tickets_data = [];
        foreach ($submitted_types as $i => $tname) {
            $old_tickets_data[] = [
                'name'            => $tname,
                'price'           => $_POST['ticket_price'][$i]    ?? '0',
                'qty'             => $_POST['ticket_qty'][$i]       ?? '',
                'is_free'         => ($_POST['is_free'][$i]         ?? '0') === '1',
                'deposit_enabled' => ($_POST['deposit_enabled'][$i] ?? '0') === '1',
                'deposit_amount'  => $_POST['deposit_amount'][$i]   ?? '',
            ];
        }
    }

    /* ── Rebuild settings from POST ── */
    $old_settings = [
        'show_views'     => ($_POST['show_views']     ?? '0') === '1' ? '1' : '0',
        'show_remaining' => ($_POST['show_remaining'] ?? '0') === '1' ? '1' : '0',
        'visibility'     => in_array($_POST['visibility'] ?? '', ['public','private']) ? $_POST['visibility'] : 'public',
    ];

    if (!$has_page) {
        $error      = "You must create your creator page before you can create events.";
        $error_step = 1;
    } else {
        $title       = trim($_POST['title']       ?? '');
        $venue       = trim($_POST['venue']        ?? '');
        $start_date  = $_POST['start_date']        ?? '';
        $start_time  = $_POST['start_time']        ?? '';
        $end_date    = $_POST['end_date']          ?? '';
        $end_time    = $_POST['end_time']          ?? '';
        $category    = $_POST['category']          ?? '';
        $description = trim($_POST['description']  ?? '');
        $deadline    = $_POST['deadline']          ?: null;

        /* ── Settings ── */
        $show_views     = ($_POST['show_views']     ?? '0') === '1' ? 1 : 0;
        $show_remaining = ($_POST['show_remaining'] ?? '0') === '1' ? 1 : 0;
        $visibility     = in_array($_POST['visibility'] ?? '', ['public','private'])
                          ? $_POST['visibility'] : 'public';

        try {
            $banner_name = secureUploadBanner($_FILES['banner'] ?? []);

            $pdo->beginTransaction();

            /* ── Duplicate title guard ── */
            $checkStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM tickets WHERE creator_id = ? AND title = ?");
            $checkStmt->execute([$creator_id, $title]);
            if ($checkStmt->fetchColumn() > 0) {
                $error_step = 1;
                throw new Exception("An event with this title already exists. Please choose a different title.");
            }

            /* ── Insert event ── */
            $stmt = $pdo->prepare("INSERT INTO tickets
                (creator_id,title,venue,start_date,start_time,end_date,end_time,
                 category,banner,description,deadline,show_views,show_remaining,visibility)
                VALUES
                (:creator_id,:title,:venue,:start_date,:start_time,:end_date,:end_time,
                 :category,:banner,:description,:deadline,:show_views,:show_remaining,:visibility)");
            $stmt->execute([
                ':creator_id'     => $creator_id,
                ':title'          => $title,
                ':venue'          => $venue,
                ':start_date'     => $start_date,
                ':start_time'     => $start_time,
                ':end_date'       => $end_date,
                ':end_time'       => $end_time,
                ':category'       => $category,
                ':banner'         => $banner_name,
                ':description'    => $description,
                ':deadline'       => $deadline,
                ':show_views'     => $show_views,
                ':show_remaining' => $show_remaining,
                ':visibility'     => $visibility,
            ]);
            $event_id = $pdo->lastInsertId();

            /* ── Ticket types ── */
            $ticket_types      = $_POST['ticket_type']      ?? [];
            $ticket_prices     = $_POST['ticket_price']     ?? [];
            $ticket_qty_arr    = $_POST['ticket_qty']        ?? [];
            $is_free_flags     = $_POST['is_free']           ?? [];
            $deposit_enabled_f = $_POST['deposit_enabled']   ?? [];
            $deposit_amounts   = $_POST['deposit_amount']    ?? [];

            foreach ($ticket_types as $i => $type_name) {
                $type_name = trim($type_name);
                if ($type_name === '') continue;

                $is_free = ($is_free_flags[$i]     ?? '0') === '1';
                $price   = $is_free ? 0.00 : floatval($ticket_prices[$i] ?? 0);
                $qty     = !empty($ticket_qty_arr[$i]) ? (int)$ticket_qty_arr[$i] : 0;

                $dep_on  = ($deposit_enabled_f[$i] ?? '0') === '1' && !$is_free;
                $dep_amt = $dep_on ? floatval($deposit_amounts[$i] ?? 0) : 0.00;

                if ($dep_on && $dep_amt <= 0) {
                    $error_step = 3;
                    throw new Exception(
                        "Deposit amount for \"{$type_name}\" must be greater than zero.");
                }
                if ($dep_on && $dep_amt >= $price) {
                    $error_step = 3;
                    throw new Exception(
                        "Deposit for \"{$type_name}\" must be less than the ticket price (KSh "
                        . number_format($price, 2) . ").");
                }

                $stmt = $pdo->prepare("INSERT INTO event_ticket_types
                    (event_id,type_name,price,quantity_available,is_free,deposit_enabled,deposit_amount)
                    VALUES (:event_id,:type_name,:price,:qty,:is_free,:deposit_enabled,:deposit_amount)");
                $stmt->execute([
                    ':event_id'        => $event_id,
                    ':type_name'       => $type_name,
                    ':price'           => $price,
                    ':qty'             => $qty,
                    ':is_free'         => $is_free ? 1 : 0,
                    ':deposit_enabled' => $dep_on  ? 1 : 0,
                    ':deposit_amount'  => $dep_amt,
                ]);
            }

            $pdo->commit();
            $success     = "Event & tickets created successfully!";
            $event_title = $title;
            $ticket_url  = "https://keychele.co.ke/ticket/" . $event_id;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Event Ticket — Keychele</title>

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
    font-weight: 400;
  }

  :root {
    --red:     #bf1339; --red-d:  #96102e; --red-l:  #e8143f;
    --gold:    #fec411; --gold-d: #d4a60a;
    --navy:    #052e64; --navy-l: #0a4494;
    --teal:    #0891b2; --teal-l: #06b6d4;
    --purple:  #7c3aed; --purple-l: #8b5cf6;
    --bg:      #eef1f8; --card:   #ffffff;
    --border:  rgba(5,46,100,0.1);
    --text:    #0f1923;  --muted: #6b7a99;
    --success: #00b37e; --warning:#f59e0b;
    --r: 14px; --r-lg: 20px;
    --sh:    0 4px 24px rgba(5,46,100,0.10);
    --sh-lg: 0 12px 48px rgba(5,46,100,0.14);
    --trn:   0.22s ease;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'GlacialI', 'Trebuchet MS', sans-serif;
    background: var(--bg); color: var(--text);
    -webkit-font-smoothing: antialiased; padding-bottom: 80px;
  }
  h1,h2,h3,h4,h5,.mf { font-family: 'Montserrat', sans-serif; }
  .page { padding: 1.5rem; max-width: 900px; margin: 0 auto; }
  @media (max-width:600px) { .page { padding: 1rem 0.9rem; } }

  /* ── HERO ── */
  .page-hero {
    position:relative;overflow:hidden;
    background:linear-gradient(120deg,var(--navy) 0%,#0a3a7a 55%,#0f4faa 100%);
    border-radius:var(--r-lg);padding:2rem 2.25rem;margin-bottom:1.75rem;
    box-shadow:var(--sh-lg),0 0 0 1px rgba(255,255,255,0.05) inset;
    animation:heroIn 0.55s cubic-bezier(0.16,1,0.3,1) both;
  }
  @keyframes heroIn { from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)} }
  .page-hero::before {
    content:'';position:absolute;inset:0;pointer-events:none;
    background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);
    background-size:34px 34px;
  }
  .page-hero-orb1{position:absolute;border-radius:50%;pointer-events:none;width:280px;height:280px;top:-100px;right:-60px;background:radial-gradient(circle,rgba(254,196,17,0.16) 0%,transparent 65%)}
  .page-hero-orb2{position:absolute;border-radius:50%;pointer-events:none;width:180px;height:180px;bottom:-70px;left:25%;background:radial-gradient(circle,rgba(191,19,57,0.18) 0%,transparent 65%)}
  .page-hero-inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:1rem}
  .page-hero-tag{display:inline-flex;align-items:center;gap:6px;background:rgba(254,196,17,0.14);border:1px solid rgba(254,196,17,0.28);border-radius:100px;padding:4px 12px;font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;letter-spacing:0.09em;text-transform:uppercase;color:var(--gold);margin-bottom:0.7rem}
  .page-hero-title{font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(1.4rem,3vw,2rem);color:white;letter-spacing:-0.04em;line-height:1.15;margin-bottom:0.4rem}
  .page-hero-sub{font-size:0.83rem;color:rgba(255,255,255,0.55)}
  .page-hero-icon{font-size:3.5rem;color:rgba(255,255,255,0.12);flex-shrink:0}
  @media(max-width:480px){.page-hero-icon{display:none}}

  /* ── STEPPER ── */
  .stepper{display:flex;align-items:center;gap:0;background:#fff;border:1px solid var(--border);border-radius:var(--r-lg);padding:1rem 1.25rem;margin-bottom:1.5rem;box-shadow:var(--sh);overflow-x:auto}
  .step-item{display:flex;align-items:center;gap:0;flex-shrink:0}
  .step-pill{display:flex;align-items:center;gap:8px;padding:6px 13px;border-radius:100px;font-family:'Montserrat',sans-serif;font-size:0.73rem;font-weight:700;transition:all 0.3s;cursor:default;white-space:nowrap}
  .step-pill .sp-num{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:800;flex-shrink:0}
  .step-pill.active{background:var(--navy);color:#fff}
  .step-pill.active .sp-num{background:rgba(255,255,255,0.2);color:#fff}
  .step-pill.done{background:rgba(0,179,126,0.12);color:var(--success)}
  .step-pill.done .sp-num{background:var(--success);color:#fff}
  .step-pill.pending{background:#f4f6fb;color:var(--muted)}
  .step-pill.pending .sp-num{background:#e2e8f0;color:var(--muted)}
  .step-connector{width:22px;height:2px;background:#e2e8f0;margin:0 3px;flex-shrink:0}
  .step-connector.done-line{background:var(--success)}

  /* ── STEP PANELS ── */
  .step-panel{display:none;animation:panelIn 0.38s cubic-bezier(0.16,1,0.3,1) both}
  .step-panel.active{display:block}
  @keyframes panelIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

  /* ── STEP NAV ── */
  .step-nav{display:flex;gap:10px;justify-content:space-between;margin-top:1.25rem}
  .btn-prev,.btn-next,.btn-submit{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:var(--r);font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.85rem;letter-spacing:0.02em;cursor:pointer;border:none;transition:transform 0.2s,box-shadow 0.2s,background 0.2s}
  .btn-prev{background:#f0f4ff;color:var(--navy);border:1.5px solid var(--border)}
  .btn-prev:hover{background:#e2eaff}
  .btn-next{background:linear-gradient(135deg,var(--navy),var(--navy-l));color:#fff;box-shadow:0 4px 16px rgba(5,46,100,0.3)}
  .btn-next:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(5,46,100,0.4)}
  .btn-submit{background:linear-gradient(135deg,var(--red),var(--red-l));color:#fff;box-shadow:0 4px 16px rgba(191,19,57,0.35)}
  .btn-submit:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(191,19,57,0.5)}
  .btn-submit:disabled{opacity:0.7;cursor:not-allowed;transform:none}

  /* ── NO-PAGE GATE ── */
  .no-page-gate{background:var(--card);border-radius:var(--r-lg);border:1.5px solid rgba(254,196,17,0.3);box-shadow:var(--sh-lg);overflow:hidden;animation:heroIn 0.5s cubic-bezier(0.16,1,0.3,1) 0.1s both}
  .npg-top{background:linear-gradient(120deg,var(--navy) 0%,#0a3a7a 100%);padding:2.5rem 2rem;text-align:center;position:relative;overflow:hidden}
  .npg-top::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);background-size:30px 30px}
  .npg-top-orb{position:absolute;border-radius:50%;pointer-events:none;width:260px;height:260px;top:-100px;right:-60px;background:radial-gradient(circle,rgba(254,196,17,0.14) 0%,transparent 65%)}
  .npg-icon-wrap{position:relative;z-index:1;width:80px;height:80px;border-radius:22px;background:linear-gradient(135deg,rgba(254,196,17,0.2),rgba(254,196,17,0.06));border:1.5px solid rgba(254,196,17,0.3);display:flex;align-items:center;justify-content:center;margin:0 auto 1.1rem;box-shadow:0 8px 28px rgba(254,196,17,0.15)}
  .npg-icon-wrap i{font-size:2rem;color:var(--gold)}
  .npg-title{position:relative;z-index:1;font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(1.2rem,2.5vw,1.6rem);color:white;letter-spacing:-0.03em;margin-bottom:0.5rem}
  .npg-subtitle{position:relative;z-index:1;font-size:0.85rem;color:rgba(255,255,255,0.5);max-width:380px;margin:0 auto;line-height:1.6}
  .npg-body{padding:2rem}
  .npg-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem}
  @media(max-width:600px){.npg-steps{grid-template-columns:1fr}}
  .npg-step{background:#fafbfd;border:1px solid var(--border);border-radius:var(--r);padding:1.1rem;text-align:center}
  .npg-step-num{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--navy),var(--navy-l));color:white;font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.85rem;display:flex;align-items:center;justify-content:center;margin:0 auto 0.75rem}
  .npg-step-title{font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;color:var(--text);margin-bottom:0.3rem}
  .npg-step-text{font-size:0.73rem;color:var(--muted);line-height:1.5}
  .npg-cta-wrap{display:flex;flex-direction:column;align-items:center;gap:0.9rem}
  .npg-cta-btn{display:inline-flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--navy),var(--navy-l));color:white;text-decoration:none;font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.92rem;letter-spacing:0.02em;padding:14px 32px;border-radius:var(--r);box-shadow:0 6px 24px rgba(5,46,100,0.35);transition:transform 0.2s,box-shadow 0.2s}
  .npg-cta-btn:hover{transform:translateY(-2px);box-shadow:0 10px 32px rgba(5,46,100,0.45)}
  .npg-cta-btn .btn-ico{width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:center;font-size:0.85rem}
  .npg-note{font-size:0.75rem;color:var(--muted);display:flex;align-items:center;gap:5px}

  /* ── SEC LABEL ── */
  .sec-label{font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);display:flex;align-items:center;gap:8px;margin-bottom:1rem}
  .sec-label::before{content:'';width:3px;height:14px;border-radius:3px;background:linear-gradient(180deg,var(--red),var(--gold));flex-shrink:0}

  /* ── CARD ── */
  .card{background:var(--card);border-radius:var(--r-lg);border:1px solid var(--border);box-shadow:var(--sh);overflow:hidden;margin-bottom:1.25rem;animation:cardIn 0.5s cubic-bezier(0.16,1,0.3,1) both}
  @keyframes cardIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
  .card-head{padding:1.25rem 1.5rem 1rem;border-bottom:1px solid var(--border);background:#fafbfd;display:flex;align-items:center;gap:10px}
  .card-head-ico{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:0.88rem;flex-shrink:0}
  .card-head-title{font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.92rem;color:var(--text)}
  .card-head-sub{font-size:0.74rem;color:var(--muted);margin-top:2px}
  .card-body{padding:1.5rem}

  /* ── FORM FIELDS ── */
  .field{margin-bottom:1.15rem}
  .field label,.field-label{display:block;font-family:'Montserrat',sans-serif;font-size:0.75rem;font-weight:700;color:#374151;margin-bottom:0.45rem;letter-spacing:0.01em}
  .field-hint{font-size:0.7rem;color:var(--muted);margin-top:5px}
  .fi{position:relative}
  .fi-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:0.82rem;color:#9ca3af;pointer-events:none;z-index:1}
  input[type=text],input[type=date],input[type=time],input[type=number],input[type=email],select,textarea{
    width:100%;font-family:'GlacialI','Trebuchet MS',sans-serif;font-size:0.88rem;color:var(--text);
    background:#fafafa;border:1.5px solid rgba(5,46,100,0.14);border-radius:10px;
    padding:11px 14px 11px 40px;outline:none;
    transition:border-color var(--trn),box-shadow var(--trn),background var(--trn);
    appearance:none;-webkit-appearance:none;
  }
  input[type=text]:focus,input[type=date]:focus,input[type=time]:focus,
  input[type=number]:focus,select:focus,textarea:focus{
    border-color:var(--navy);background:#fff;box-shadow:0 0 0 3px rgba(5,46,100,0.08);
  }
  input.field-error,select.field-error,textarea.field-error{
    border-color:var(--red) !important;background:#fff8f8 !important;
    box-shadow:0 0 0 3px rgba(191,19,57,0.08) !important;
  }
  .no-ico input,.no-ico select,.no-ico textarea{padding-left:14px}
  textarea{resize:vertical;min-height:100px;padding-left:14px !important}
  select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b7a99' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-size:16px;padding-right:36px}

  /* ── FILE DROP ── */
  .file-drop{border:2px dashed rgba(5,46,100,0.18);border-radius:10px;padding:1.25rem;text-align:center;cursor:pointer;transition:border-color 0.2s,background 0.2s;background:#fafafa;position:relative;overflow:hidden}
  .file-drop:hover{border-color:var(--navy);background:#f0f4ff}
  .file-drop input[type=file]{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer;border:none;padding:0}
  .file-drop-icon{font-size:1.5rem;color:var(--navy);opacity:0.5;margin-bottom:6px}
  .file-drop-text{font-family:'Montserrat',sans-serif;font-size:0.78rem;font-weight:600;color:var(--muted)}
  .file-drop-sub{font-size:0.68rem;color:var(--muted);margin-top:3px}
  .upload-progress-wrap{display:none;margin-top:10px;background:rgba(5,46,100,0.07);border-radius:100px;overflow:hidden;height:6px}
  .upload-progress-wrap.visible{display:block}
  .upload-progress-bar{height:100%;width:0%;background:linear-gradient(90deg,var(--navy),var(--teal-l));border-radius:100px;transition:width 0.25s ease}
  .upload-progress-label{font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;color:var(--navy);margin-top:5px;display:none}
  .upload-progress-label.visible{display:block}
  .upload-size-error{display:none;margin-top:7px;font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:700;color:var(--red);background:rgba(191,19,57,0.07);border:1px solid rgba(191,19,57,0.18);border-radius:7px;padding:6px 10px}
  .upload-size-error.visible{display:block}
  .banner-preview{display:none;margin-top:10px;border-radius:9px;overflow:hidden;border:1.5px solid var(--border);max-height:120px;width:100%;object-fit:cover}
  .banner-preview.visible{display:block}

  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
  @media(max-width:560px){.grid2{grid-template-columns:1fr}}
  .grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem}
  @media(max-width:640px){.grid3{grid-template-columns:1fr 1fr}}
  @media(max-width:420px){.grid3{grid-template-columns:1fr}}

  /* ── FEE CALCULATOR ── */
  .calc-card{background:linear-gradient(135deg,var(--navy) 0%,#0a3a7a 100%);border-radius:var(--r-lg);border:1px solid rgba(255,255,255,0.08);box-shadow:var(--sh-lg);overflow:hidden;margin-bottom:1.25rem}
  .calc-head{padding:1.25rem 1.5rem 1rem;border-bottom:1px solid rgba(255,255,255,0.08);display:flex;align-items:center;gap:10px}
  .calc-head-ico{width:38px;height:38px;border-radius:9px;background:rgba(254,196,17,0.15);border:1px solid rgba(254,196,17,0.25);display:flex;align-items:center;justify-content:center;font-size:0.9rem;color:var(--gold);flex-shrink:0}
  .calc-head-title{font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.92rem;color:white}
  .calc-head-sub{font-size:0.72rem;color:rgba(255,255,255,0.45);margin-top:2px}
  .calc-body{padding:1.5rem}
  .calc-info{background:rgba(254,196,17,0.1);border:1px solid rgba(254,196,17,0.22);border-radius:10px;padding:1rem 1.1rem;margin-bottom:1.25rem}
  .calc-info-title{font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;color:var(--gold);margin-bottom:0.5rem;display:flex;align-items:center;gap:6px}
  .calc-info-text{font-size:0.78rem;color:rgba(255,255,255,0.65);line-height:1.65}
  .calc-info-text strong{color:var(--gold);font-family:'Montserrat',sans-serif}
  .calc-input-wrap{background:rgba(255,255,255,0.06);border:1.5px solid rgba(255,255,255,0.12);border-radius:10px;padding:14px;margin-bottom:1.1rem}
  .calc-input-label{font-family:'Montserrat',sans-serif;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.45);margin-bottom:8px;display:block}
  .calc-input-row{position:relative}
  .calc-prefix{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.82rem;color:rgba(255,255,255,0.4);pointer-events:none}
  #calcPrice{width:100%;background:rgba(255,255,255,0.08);border:1.5px solid rgba(255,255,255,0.15);border-radius:9px;padding:12px 14px 12px 44px;color:white;font-family:'Montserrat',sans-serif;font-weight:700;font-size:1.2rem;outline:none;transition:border-color 0.2s,box-shadow 0.2s;appearance:none}
  #calcPrice:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(254,196,17,0.12)}
  #calcPrice::placeholder{color:rgba(255,255,255,0.25);font-size:1rem}
  .calc-results{display:grid;grid-template-columns:1fr 1fr;gap:0.8rem}
  @media(max-width:480px){.calc-results{grid-template-columns:1fr}}
  .calc-res-box{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:14px 16px;transition:background 0.2s}
  .calc-res-box.highlight{background:rgba(0,179,126,0.15);border-color:rgba(0,179,126,0.3)}
  .calc-res-box.danger{background:rgba(191,19,57,0.15);border-color:rgba(191,19,57,0.28)}
  .crb-label{font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.4);margin-bottom:5px;display:flex;align-items:center;gap:5px}
  .crb-label i{font-size:0.65rem}
  .crb-val{font-family:'Montserrat',sans-serif;font-weight:800;font-size:1.2rem;letter-spacing:-0.03em;color:white;line-height:1}
  .calc-res-box.highlight .crb-val{color:#2ecc9a}
  .calc-res-box.danger .crb-val{color:#ff8fa3}
  .crb-sub{font-size:0.67rem;color:rgba(255,255,255,0.35);margin-top:4px}
  .calc-res-full{grid-column:1/-1;background:rgba(254,196,17,0.12);border:1px solid rgba(254,196,17,0.25);border-radius:10px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:1rem}
  .crf-left .crb-label{color:rgba(255,255,255,0.5)}
  .crf-left .crb-val{color:var(--gold);font-size:1.4rem}
  .crf-icon{width:46px;height:46px;border-radius:12px;background:rgba(254,196,17,0.15);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:var(--gold);flex-shrink:0}
  .calc-breakdown{margin-top:1rem;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:10px;overflow:hidden}
  .cbd-row{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,0.06);font-size:0.8rem}
  .cbd-row:last-child{border-bottom:none}
  .cbd-row-label{display:flex;align-items:center;gap:7px;color:rgba(255,255,255,0.55);font-family:'GlacialI',sans-serif}
  .cbd-row-label .dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
  .cbd-row-val{font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.82rem;color:white}
  .cbd-row-val.green{color:#2ecc9a}

  /* ── TICKET TYPES ── */
  .tt-block{background:#fafbfd;border:1.5px solid var(--border);border-radius:var(--r);padding:1.25rem;position:relative;margin-bottom:0.85rem;transition:border-color 0.2s,box-shadow 0.2s}
  .tt-block:hover{border-color:rgba(5,46,100,0.2);box-shadow:0 2px 12px rgba(5,46,100,0.07)}
  .tt-block-top{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem}
  .tt-name-input{font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.95rem;color:var(--text);background:transparent;border:none;border-bottom:2px solid var(--border);outline:none;flex:1;padding:4px 0;transition:border-color 0.2s}
  .tt-name-input:focus{border-color:var(--navy)}
  .tt-free-label{display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap;user-select:none}
  .tt-free-cb{width:18px;height:18px;accent-color:var(--success);cursor:pointer;flex-shrink:0}
  .tt-free-text{font-family:'Montserrat',sans-serif;font-size:0.75rem;font-weight:700;color:var(--success)}
  .tt-remove-btn{width:30px;height:30px;border-radius:7px;background:rgba(191,19,57,0.08);border:1px solid rgba(191,19,57,0.15);display:flex;align-items:center;justify-content:center;color:var(--red);font-size:0.75rem;cursor:pointer;flex-shrink:0;transition:background 0.18s}
  .tt-remove-btn:hover{background:rgba(191,19,57,0.18)}
  .tt-remove-btn.hidden{display:none}
  .tt-number-input{width:100%;padding:10px 14px !important;border-radius:10px !important}
  .add-tt-btn{display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;background:rgba(5,46,100,0.06);border:1.5px dashed rgba(5,46,100,0.2);color:var(--navy);font-family:'Montserrat',sans-serif;font-size:0.8rem;font-weight:700;cursor:pointer;width:100%;justify-content:center;transition:background 0.18s,border-color 0.18s;margin-bottom:1.25rem}
  .add-tt-btn:hover{background:rgba(5,46,100,0.1);border-color:var(--navy)}

  /* ── DEPOSIT PANEL ── */
  .deposit-panel{display:none;margin-top:1rem;background:linear-gradient(135deg,rgba(8,145,178,0.06),rgba(6,182,212,0.04));border:1.5px solid rgba(8,145,178,0.22);border-radius:10px;padding:1rem 1.1rem;animation:panelIn 0.3s cubic-bezier(0.16,1,0.3,1) both}
  .deposit-panel.open{display:block}
  .deposit-panel-title{font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:0.08em;display:flex;align-items:center;gap:6px;margin-bottom:0.75rem}
  .deposit-hint{font-size:0.71rem;color:var(--muted);margin-top:5px;line-height:1.5}
  .dep-toggle-row{display:flex;align-items:center;gap:10px;margin-bottom:0}
  .dep-toggle-label{display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none}
  .dep-toggle-cb{width:18px;height:18px;accent-color:var(--teal);cursor:pointer;flex-shrink:0}
  .dep-toggle-text{font-family:'Montserrat',sans-serif;font-size:0.77rem;font-weight:700;color:var(--teal)}
  .dep-badge{display:none;align-items:center;gap:4px;background:rgba(8,145,178,0.12);border:1px solid rgba(8,145,178,0.25);border-radius:100px;padding:3px 9px;font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;color:var(--teal);white-space:nowrap}
  .dep-badge.visible{display:inline-flex}

  /* ══════════════════════════════════════
     SETTINGS STEP STYLES
  ══════════════════════════════════════ */

  /* Toggle Switch */
  .setting-row {
    display:flex;align-items:flex-start;justify-content:space-between;gap:1.25rem;
    padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);
    transition:background 0.2s;
  }
  .setting-row:last-child{border-bottom:none}
  .setting-row:hover{background:#fafbff}
  .setting-info{flex:1;min-width:0}
  .setting-title{font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.88rem;color:var(--text);margin-bottom:4px;display:flex;align-items:center;gap:8px}
  .setting-title .s-ico{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:0.75rem;flex-shrink:0}
  .setting-desc{font-size:0.76rem;color:var(--muted);line-height:1.55}
  .setting-badge{display:inline-flex;align-items:center;gap:4px;font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:0.07em}
  .setting-badge.recommended{background:rgba(0,179,126,0.1);border:1px solid rgba(0,179,126,0.25);color:var(--success)}
  .setting-badge.privacy{background:rgba(124,58,237,0.1);border:1px solid rgba(124,58,237,0.25);color:var(--purple)}

  /* The toggle switch */
  .toggle-wrap{flex-shrink:0;display:flex;align-items:center;gap:0;padding-top:3px}
  .toggle-switch{position:relative;width:46px;height:26px;display:inline-block}
  .toggle-switch input{opacity:0;width:0;height:0;position:absolute}
  .toggle-knob{position:absolute;inset:0;border-radius:100px;background:#dde3f0;cursor:pointer;transition:background 0.28s}
  .toggle-knob::before{content:'';position:absolute;left:3px;top:3px;width:20px;height:20px;border-radius:50%;background:white;box-shadow:0 2px 6px rgba(0,0,0,0.2);transition:transform 0.28s,box-shadow 0.28s}
  .toggle-switch input:checked + .toggle-knob{background:var(--success)}
  .toggle-switch input:checked + .toggle-knob::before{transform:translateX(20px);box-shadow:0 2px 8px rgba(0,179,126,0.4)}
  .toggle-switch input:focus-visible + .toggle-knob{outline:3px solid rgba(0,179,126,0.4);outline-offset:2px}

  /* Visibility selector */
  .vis-options{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-top:1rem}
  .vis-opt{position:relative;cursor:pointer}
  .vis-opt input[type=radio]{position:absolute;opacity:0;inset:0;width:100%;height:100%;cursor:pointer;margin:0}
  .vis-opt-card{
    border:2px solid var(--border);border-radius:var(--r);padding:1.1rem 1rem;
    text-align:center;background:#fafbfd;transition:all 0.22s;
    display:flex;flex-direction:column;align-items:center;gap:0.55rem;
  }
  .vis-opt input[type=radio]:checked ~ .vis-opt-card{
    border-color:var(--navy);background:#f0f4ff;box-shadow:0 0 0 3px rgba(5,46,100,0.08);
  }
  .vis-opt.private input[type=radio]:checked ~ .vis-opt-card{
    border-color:var(--purple);background:rgba(124,58,237,0.05);box-shadow:0 0 0 3px rgba(124,58,237,0.08);
  }
  .vis-opt-card:hover{border-color:rgba(5,46,100,0.3);background:#f5f7ff}
  .vis-opt.private .vis-opt-card:hover{border-color:rgba(124,58,237,0.3);background:rgba(124,58,237,0.04)}
  .vis-opt-ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin:0 auto}
  .vis-opt.public .vis-opt-ico{background:rgba(5,46,100,0.08);color:var(--navy)}
  .vis-opt.private .vis-opt-ico{background:rgba(124,58,237,0.08);color:var(--purple)}
  .vis-opt input[type=radio]:checked ~ .vis-opt-card .vis-opt-ico{background:rgba(5,46,100,0.12);color:var(--navy)}
  .vis-opt.private input[type=radio]:checked ~ .vis-opt-card .vis-opt-ico{background:rgba(124,58,237,0.15);color:var(--purple)}
  .vis-opt-label{font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.88rem;color:var(--text)}
  .vis-opt-sub{font-size:0.7rem;color:var(--muted);line-height:1.45}
  .vis-check{width:18px;height:18px;border-radius:50%;border:2px solid #dde3f0;display:flex;align-items:center;justify-content:center;color:transparent;font-size:0.55rem;transition:all 0.22s;flex-shrink:0;margin-top:2px}
  .vis-opt input[type=radio]:checked ~ .vis-opt-card .vis-check{background:var(--navy);border-color:var(--navy);color:white}
  .vis-opt.private input[type=radio]:checked ~ .vis-opt-card .vis-check{background:var(--purple);border-color:var(--purple);color:white}

  /* Settings summary preview */
  .settings-summary{background:linear-gradient(135deg,rgba(5,46,100,0.04),rgba(5,46,100,0.01));border:1px solid var(--border);border-radius:var(--r);padding:1.1rem 1.25rem;margin-top:1.25rem}
  .ss-title{font-family:'Montserrat',sans-serif;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:var(--muted);margin-bottom:0.85rem;display:flex;align-items:center;gap:6px}
  .ss-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:0.55rem}
  .ss-row:last-child{margin-bottom:0}
  .ss-row-label{font-size:0.8rem;color:var(--muted);display:flex;align-items:center;gap:7px}
  .ss-row-label i{font-size:0.78rem;width:14px;text-align:center;color:var(--navy);opacity:0.6}
  .ss-badge{display:inline-flex;align-items:center;gap:4px;font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;padding:3px 10px;border-radius:100px}
  .ss-badge.on{background:rgba(0,179,126,0.12);border:1px solid rgba(0,179,126,0.28);color:var(--success)}
  .ss-badge.off{background:rgba(107,122,153,0.1);border:1px solid rgba(107,122,153,0.2);color:var(--muted)}
  .ss-badge.pub{background:rgba(5,46,100,0.1);border:1px solid rgba(5,46,100,0.2);color:var(--navy)}
  .ss-badge.priv{background:rgba(124,58,237,0.1);border:1px solid rgba(124,58,237,0.25);color:var(--purple)}

  /* private warning */
  .private-notice{display:none;margin-top:1rem;background:rgba(124,58,237,0.07);border:1.5px solid rgba(124,58,237,0.2);border-radius:10px;padding:0.9rem 1rem;animation:panelIn 0.28s ease both}
  .private-notice.show{display:flex;gap:10px;align-items:flex-start}
  .private-notice i{color:var(--purple);font-size:0.88rem;margin-top:1px;flex-shrink:0}
  .private-notice-text{font-size:0.77rem;color:#4c1d95;line-height:1.55}
  .private-notice-text strong{font-family:'Montserrat',sans-serif;font-weight:700}

  /* ══════════════════════════════════════
     CREATION PROGRESS OVERLAY
  ══════════════════════════════════════ */
  .creation-overlay{
    position:fixed;inset:0;z-index:99999;
    background:rgba(5,20,50,0.94);
    backdrop-filter:blur(10px);
    display:none;align-items:center;justify-content:center;
  }
  .creation-overlay.active{display:flex}
  .creation-card{
    background:white;border-radius:24px;
    padding:2.5rem 2rem 2.5rem;
    width:min(460px,92vw);text-align:center;
    box-shadow:0 32px 80px rgba(0,0,0,0.4);
    animation:cardIn 0.4s cubic-bezier(0.16,1,0.3,1) both;
  }
  .creation-spin-wrap{
    width:72px;height:72px;border-radius:50%;
    background:linear-gradient(135deg,var(--navy),var(--navy-l));
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 1.25rem;
    box-shadow:0 8px 28px rgba(5,46,100,0.35);
    position:relative;
  }
  .creation-spin-wrap::after{
    content:'';position:absolute;inset:-4px;border-radius:50%;
    border:3px solid transparent;
    border-top-color:var(--gold);
    animation:spin 1s linear infinite;
  }
  .creation-spin-wrap i{font-size:1.6rem;color:white;z-index:1}
  .creation-title{font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.2rem;color:var(--text);letter-spacing:-0.03em;margin-bottom:0.35rem}
  .creation-sub{font-size:0.8rem;color:var(--muted);margin-bottom:1.5rem}
  .creation-bar-wrap{
    background:#f0f4ff;border-radius:100px;
    height:10px;overflow:hidden;margin-bottom:0.7rem;
    border:1px solid rgba(5,46,100,0.1);
  }
  .creation-bar-fill{
    height:100%;width:0%;border-radius:100px;
    background:linear-gradient(90deg,var(--navy),var(--teal-l),var(--gold));
    background-size:200% 100%;
    transition:width 0.6s cubic-bezier(0.4,0,0.2,1);
    animation:shimmer 2s linear infinite;
  }
  @keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
  .creation-pct{font-family:'Montserrat',sans-serif;font-weight:800;font-size:1.5rem;color:var(--navy);letter-spacing:-0.04em;margin-bottom:0.3rem}
  .creation-stage{font-size:0.78rem;color:var(--muted);font-family:'Montserrat',sans-serif;font-weight:600;min-height:1.2em}
  .creation-steps-mini{display:flex;justify-content:center;gap:6px;margin-top:1.25rem;flex-wrap:wrap}
  .csm-step{display:flex;align-items:center;gap:5px;font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;color:var(--muted);padding:4px 10px;border-radius:100px;background:#f4f6fb;border:1px solid var(--border);transition:all 0.3s}
  .csm-step.done{background:rgba(0,179,126,0.1);border-color:rgba(0,179,126,0.3);color:var(--success)}
  .csm-step.active{background:rgba(5,46,100,0.08);border-color:rgba(5,46,100,0.25);color:var(--navy)}
  .csm-step i{font-size:0.6rem}

  /* ── SUCCESS SCREEN ── */
  .success-screen{animation:heroIn 0.6s cubic-bezier(0.16,1,0.3,1) both}
  .success-burst{background:linear-gradient(135deg,#052e64 0%,#0a4494 50%,#0f5bc4 100%);border-radius:var(--r-lg);padding:3rem 2rem 2.5rem;text-align:center;position:relative;overflow:hidden;margin-bottom:1.25rem;box-shadow:var(--sh-lg)}
  .success-burst::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);background-size:30px 30px}
  .sb-orb1{position:absolute;border-radius:50%;pointer-events:none;width:320px;height:320px;top:-130px;right:-80px;background:radial-gradient(circle,rgba(254,196,17,0.14) 0%,transparent 65%)}
  .sb-orb2{position:absolute;border-radius:50%;pointer-events:none;width:200px;height:200px;bottom:-80px;left:10%;background:radial-gradient(circle,rgba(0,179,126,0.2) 0%,transparent 65%)}
  .sb-check{position:relative;z-index:1;width:88px;height:88px;border-radius:50%;background:linear-gradient(135deg,var(--success),#00d68f);margin:0 auto 1.25rem;display:flex;align-items:center;justify-content:center;font-size:2.2rem;color:#fff;box-shadow:0 0 0 12px rgba(0,179,126,0.15),0 12px 40px rgba(0,179,126,0.4);animation:checkPop 0.5s cubic-bezier(0.34,1.5,0.64,1) 0.2s both}
  @keyframes checkPop{from{transform:scale(0) rotate(-20deg);opacity:0}to{transform:scale(1) rotate(0deg);opacity:1}}
  .sb-title{position:relative;z-index:1;font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(1.5rem,4vw,2.2rem);color:#fff;letter-spacing:-0.04em;margin-bottom:0.4rem}
  .sb-sub{position:relative;z-index:1;font-size:0.88rem;color:rgba(255,255,255,0.55);max-width:360px;margin:0 auto}
  .sb-dots{position:absolute;inset:0;z-index:0;pointer-events:none;overflow:hidden}
  .sb-dot{position:absolute;border-radius:50%;animation:dotFloat linear infinite}
  @keyframes dotFloat{0%{transform:translateY(100%) rotate(0deg);opacity:0}10%{opacity:1}90%{opacity:1}100%{transform:translateY(-120%) rotate(360deg);opacity:0}}
  .share-card{background:#fff;border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--sh);overflow:hidden;margin-bottom:1.25rem}
  .share-card-head{padding:1.1rem 1.5rem;background:linear-gradient(90deg,rgba(5,46,100,0.04),rgba(5,46,100,0.01));border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
  .share-card-ico{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--navy),var(--navy-l));display:flex;align-items:center;justify-content:center;font-size:0.9rem;color:#fff;flex-shrink:0}
  .share-card-title{font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.92rem;color:var(--text)}
  .share-card-sub{font-size:0.73rem;color:var(--muted);margin-top:1px}
  .share-card-body{padding:1.5rem}
  .event-info-pill{display:flex;align-items:center;gap:10px;background:#f8faff;border:1px solid var(--border);border-radius:12px;padding:12px 16px;margin-bottom:1.25rem}
  .eip-ico{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,rgba(5,46,100,0.1),rgba(5,46,100,0.05));display:flex;align-items:center;justify-content:center;font-size:1rem;color:var(--navy);flex-shrink:0}
  .eip-title{font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.88rem;color:var(--text)}
  .eip-sub{font-size:0.73rem;color:var(--muted);margin-top:2px}
  .url-copy-wrap{display:flex;align-items:center;gap:10px;background:#f4f7ff;border:2px solid rgba(5,46,100,0.14);border-radius:14px;padding:14px 16px;margin-bottom:1.25rem;transition:border-color 0.2s,box-shadow 0.2s}
  .url-copy-wrap:focus-within{border-color:var(--navy);box-shadow:0 0 0 3px rgba(5,46,100,0.08)}
  .url-link-icon{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--navy),var(--navy-l));display:flex;align-items:center;justify-content:center;font-size:0.8rem;color:#fff;flex-shrink:0}
  .url-text{flex:1;font-size:0.82rem;color:var(--navy);font-family:'Montserrat',sans-serif;font-weight:600;word-break:break-all;line-height:1.4;user-select:all}
  .copy-link-btn{flex-shrink:0;display:flex;align-items:center;gap:7px;background:linear-gradient(135deg,var(--navy),var(--navy-l));color:#fff;border:none;border-radius:10px;padding:10px 20px;font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.8rem;cursor:pointer;white-space:nowrap;transition:transform 0.18s,box-shadow 0.18s;box-shadow:0 4px 14px rgba(5,46,100,0.3)}
  .copy-link-btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(5,46,100,0.4)}
  .copy-link-btn.copied{background:linear-gradient(135deg,var(--success),#00d68f);box-shadow:0 4px 14px rgba(0,179,126,0.4)}
  .social-label{font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);margin-bottom:0.75rem}
  .social-row{display:flex;gap:10px;flex-wrap:wrap}
  .soc-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:10px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;cursor:pointer;border:none;text-decoration:none;transition:transform 0.15s,box-shadow 0.15s}
  .soc-btn:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,0.15)}
  .soc-wa{background:#25d366;color:#fff}
  .soc-fb{background:#1877f2;color:#fff}
  .soc-tw{background:#000;color:#fff}
  .soc-email{background:#f0f4ff;color:var(--navy);border:1.5px solid var(--border)}
  .post-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:1.25rem}
  @media(max-width:480px){.post-actions{grid-template-columns:1fr}}
  .pa-btn{display:flex;align-items:center;gap:9px;padding:13px 16px;border-radius:var(--r);font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.83rem;text-decoration:none;transition:transform 0.18s,box-shadow 0.18s}
  .pa-btn:hover{transform:translateY(-2px)}
  .pa-btn-outline{background:#fff;color:var(--navy);border:1.5px solid var(--border)}
  .pa-btn-outline:hover{border-color:var(--navy);box-shadow:0 4px 14px rgba(5,46,100,0.1)}
  .pa-btn-solid{background:linear-gradient(135deg,var(--red),var(--red-l));color:#fff;box-shadow:0 4px 16px rgba(191,19,57,0.3)}
  .pa-btn-solid:hover{box-shadow:0 8px 24px rgba(191,19,57,0.45)}
  .pa-icon{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;font-size:0.85rem;flex-shrink:0}
  .pa-btn-outline .pa-icon{background:rgba(5,46,100,0.07);color:var(--navy)}

  /* ── TOASTR ── */
  #toast-container>.toast{border-radius:11px !important;box-shadow:0 8px 32px rgba(0,0,0,0.14) !important;font-family:'GlacialI',sans-serif !important;font-size:0.875rem !important;padding:14px 18px 14px 54px !important}
  #toast-container>.toast-success{background-color:#064E3B !important;border-left:4px solid #10B981 !important}
  #toast-container>.toast-error{background-color:#7F1D1D !important;border-left:4px solid #EF4444 !important}
  #toast-container>.toast-info{background-color:var(--navy) !important;border-left:4px solid var(--gold) !important}

  @keyframes spin{to{transform:rotate(360deg)}}
  .spinner{animation:spin 0.8s linear infinite}

  .c-toast{position:fixed;bottom:22px;right:22px;z-index:9999;display:flex;align-items:center;gap:9px;padding:13px 18px;border-radius:12px;font-size:0.85rem;font-weight:600;box-shadow:0 8px 28px rgba(0,0,0,.12);transform:translateY(14px);opacity:0;transition:all .28s cubic-bezier(.34,1.5,.64,1);pointer-events:none;border:1px solid;font-family:'Montserrat',sans-serif}
  .c-toast.show{transform:translateY(0);opacity:1}
  .c-toast-s{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}
  </style>
</head>
<body>

<!-- ══ CREATION PROGRESS OVERLAY ══ -->
<div class="creation-overlay" id="creationOverlay">
  <div class="creation-card">
    <div class="creation-spin-wrap">
      <i class="fa-solid fa-calendar-plus"></i>
    </div>
    <div class="creation-title">Creating Your Event</div>
    <div class="creation-sub" id="creationSub">Preparing your event details…</div>
    <div class="creation-bar-wrap">
      <div class="creation-bar-fill" id="creationBarFill"></div>
    </div>
    <div class="creation-pct" id="creationPct">0%</div>
    <div class="creation-stage" id="creationStage">Starting…</div>
    <div class="creation-steps-mini" id="creationStepsMini">
      <div class="csm-step active" id="csmStep0"><i class="fa-solid fa-check-circle"></i> Validating</div>
      <div class="csm-step"        id="csmStep1"><i class="fa-solid fa-image"></i> Banner</div>
      <div class="csm-step"        id="csmStep2"><i class="fa-solid fa-database"></i> Event</div>
      <div class="csm-step"        id="csmStep3"><i class="fa-solid fa-ticket"></i> Tickets</div>
      <div class="csm-step"        id="csmStep4"><i class="fa-solid fa-flag-checkered"></i> Done</div>
    </div>
  </div>
</div>

<div id="cToast" class="c-toast c-toast-s">
  <i class="fa-solid fa-circle-check"></i>
  <span id="cToastMsg">Link copied!</span>
</div>

<div class="page">

  <!-- HERO -->
  <div class="page-hero">
    <div class="page-hero-orb1"></div>
    <div class="page-hero-orb2"></div>
    <div class="page-hero-inner">
      <div>
        <div class="page-hero-tag"><i class="fa-solid fa-ticket"></i> Ticketing</div>
        <h1 class="page-hero-title">Create Event Ticket</h1>
        <p class="page-hero-sub">Free &amp; paid tickets · Deposits · M-Pesa checkout ready</p>
      </div>
      <div class="page-hero-icon"><i class="fa-solid fa-calendar-plus"></i></div>
    </div>
  </div>

<?php if (!$has_page): ?>
  <!-- NO-PAGE GATE -->
  <div class="no-page-gate">
    <div class="npg-top">
      <div class="npg-top-orb"></div>
      <div class="npg-icon-wrap"><i class="fa-solid fa-store-slash"></i></div>
      <div class="npg-title">Your Creator Page Isn't Set Up Yet</div>
      <div class="npg-subtitle">You need a public creator page before you can create events and sell tickets on Keychele.</div>
    </div>
    <div class="npg-body">
      <div class="npg-steps">
        <div class="npg-step"><div class="npg-step-num">1</div><div class="npg-step-title">Create Your Page</div><div class="npg-step-text">Set up your public profile — name, bio, and branding</div></div>
        <div class="npg-step"><div class="npg-step-num">2</div><div class="npg-step-title">Create Events</div><div class="npg-step-text">Come back here to set up your event and ticket tiers</div></div>
        <div class="npg-step"><div class="npg-step-num">3</div><div class="npg-step-title">Start Selling</div><div class="npg-step-text">Share your link — fans pay via M-Pesa instantly</div></div>
      </div>
      <div class="npg-cta-wrap">
        <a href="https://keychele.co.ke/msanii/index.php?page=create_page" class="npg-cta-btn">
          <span class="btn-ico"><i class="fa-solid fa-store"></i></span>
          Set Up My Creator Page
          <i class="fa-solid fa-arrow-right" style="font-size:0.8rem;opacity:0.7"></i>
        </a>
        <div class="npg-note"><i class="fa-solid fa-circle-info" style="color:var(--gold)"></i> It only takes a minute</div>
      </div>
    </div>
  </div>

<?php elseif ($success && $event_id): ?>
  <!-- ══ SUCCESS + SHARE SCREEN ══ -->
  <div class="success-screen">
    <div class="success-burst">
      <div class="sb-orb1"></div><div class="sb-orb2"></div>
      <div class="sb-dots" id="sbDots"></div>
      <div class="sb-check"><i class="fa-solid fa-check"></i></div>
      <div class="sb-title">Event Created!</div>
      <p class="sb-sub">Your event is live and ready to accept ticket sales. Share the link with your fans now.</p>
    </div>
    <div class="share-card">
      <div class="share-card-head">
        <div class="share-card-ico"><i class="fa-solid fa-share-nodes"></i></div>
        <div>
          <div class="share-card-title">Share Your Ticket Link</div>
          <div class="share-card-sub">Copy and send this link to your fans so they can buy tickets</div>
        </div>
      </div>
      <div class="share-card-body">
        <div class="event-info-pill">
          <div class="eip-ico"><i class="fa-solid fa-calendar-star"></i></div>
          <div>
            <div class="eip-title"><?= h($event_title) ?></div>
            <div class="eip-sub">Ticket ID #<?= $event_id ?> &nbsp;·&nbsp; Created just now</div>
          </div>
        </div>
        <div class="url-copy-wrap">
          <div class="url-link-icon"><i class="fa-solid fa-link"></i></div>
          <span class="url-text" id="ticketUrl"><?= h($ticket_url) ?></span>
          <button class="copy-link-btn" id="copyBtn" onclick="copyTicketLink()">
            <i class="fa-solid fa-copy"></i> Copy Link
          </button>
        </div>
        <div class="social-label">Share directly via</div>
        <div class="social-row">
          <?php
            $enc_url   = urlencode($ticket_url);
            $enc_text  = urlencode("Get your tickets for {$event_title}! ");
            $enc_title = urlencode("Get your tickets for {$event_title}!");
          ?>
          <a href="https://wa.me/?text=<?= $enc_text . $enc_url ?>" target="_blank" rel="noopener" class="soc-btn soc-wa"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
          <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $enc_url ?>" target="_blank" rel="noopener" class="soc-btn soc-fb"><i class="fa-brands fa-facebook-f"></i> Facebook</a>
          <a href="https://twitter.com/intent/tweet?text=<?= $enc_title ?>&url=<?= $enc_url ?>" target="_blank" rel="noopener" class="soc-btn soc-tw"><i class="fa-brands fa-x-twitter"></i> X</a>
          <a href="mailto:?subject=<?= urlencode("Get tickets for {$event_title}!") ?>&body=<?= urlencode("Check out and buy tickets here: {$ticket_url}") ?>" class="soc-btn soc-email"><i class="fa-solid fa-envelope"></i> Email</a>
        </div>
        <div class="post-actions">
          <a href="?page=my_tickets" class="pa-btn pa-btn-outline">
            <div class="pa-icon"><i class="fa-solid fa-list-check"></i></div>
            <div><div style="font-size:0.82rem">My Events</div><div style="font-size:0.68rem;color:var(--muted);font-family:'GlacialI',sans-serif;font-weight:400">View all your events</div></div>
          </a>
          <a href="?page=create_ticket" class="pa-btn pa-btn-solid">
            <div class="pa-icon"><i class="fa-solid fa-plus"></i></div>
            <div><div style="font-size:0.82rem">Create Another</div><div style="font-size:0.68rem;color:rgba(255,255,255,0.65);font-family:'GlacialI',sans-serif;font-weight:400">New event ticket</div></div>
          </a>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- ══ MULTI-STEP FORM (4 steps) ══ -->
  <div class="stepper" id="stepperBar">
    <div class="step-item"><div class="step-pill active" id="pill-1"><div class="sp-num">1</div><span>Event Info</span></div></div>
    <div class="step-connector" id="conn-1"></div>
    <div class="step-item"><div class="step-pill pending" id="pill-2"><div class="sp-num">2</div><span>Pricing</span></div></div>
    <div class="step-connector" id="conn-2"></div>
    <div class="step-item"><div class="step-pill pending" id="pill-3"><div class="sp-num">3</div><span>Tickets</span></div></div>
    <div class="step-connector" id="conn-3"></div>
    <div class="step-item"><div class="step-pill pending" id="pill-4"><div class="sp-num">4</div><span>Settings</span></div></div>
  </div>

  <form method="POST" enctype="multipart/form-data" id="createForm" novalidate>

    <!-- ══════════════ STEP 1 — Event Info ══════════════ -->
    <div class="step-panel active" id="step-1">
      <div class="sec-label">Event Details</div>
      <div class="card">
        <div class="card-head">
          <div class="card-head-ico" style="background:rgba(5,46,100,0.08);color:var(--navy)"><i class="fa-solid fa-calendar-days"></i></div>
          <div>
            <div class="card-head-title">Basic Information</div>
            <div class="card-head-sub">Tell people what your event is about</div>
          </div>
        </div>
        <div class="card-body">

          <div class="field">
            <label>Event Title *</label>
            <div class="fi"><i class="fi-icon fa-solid fa-pen"></i>
              <input type="text" name="title" id="f_title" required
                     placeholder="e.g. Nairobi Comedy Night 2025"
                     value="<?= h($old_post['title'] ?? '') ?>"
                     class="<?= ($error && $error_step===1 && empty($old_post['title'])) ? 'field-error' : '' ?>">
            </div>
          </div>

          <div class="field">
            <label>Venue / Location *</label>
            <div class="fi"><i class="fi-icon fa-solid fa-location-dot"></i>
              <input type="text" name="venue" id="f_venue" required
                     placeholder="e.g. KICC, Nairobi"
                     value="<?= h($old_post['venue'] ?? '') ?>">
            </div>
          </div>

          <div class="grid2">
            <div class="field">
              <label>Start Date *</label>
              <div class="fi"><i class="fi-icon fa-solid fa-calendar"></i>
                <input type="date" name="start_date" id="f_start_date" required
                       value="<?= h($old_post['start_date'] ?? '') ?>">
              </div>
            </div>
            <div class="field">
              <label>Start Time *</label>
              <div class="fi"><i class="fi-icon fa-regular fa-clock"></i>
                <input type="time" name="start_time" id="f_start_time" required
                       value="<?= h($old_post['start_time'] ?? '') ?>">
              </div>
            </div>
          </div>

          <div class="grid2">
            <div class="field">
              <label>End Date *</label>
              <div class="fi"><i class="fi-icon fa-solid fa-calendar-check"></i>
                <input type="date" name="end_date" id="f_end_date" required
                       value="<?= h($old_post['end_date'] ?? '') ?>">
              </div>
            </div>
            <div class="field">
              <label>End Time *</label>
              <div class="fi"><i class="fi-icon fa-regular fa-clock"></i>
                <input type="time" name="end_time" id="f_end_time" required
                       value="<?= h($old_post['end_time'] ?? '') ?>">
              </div>
            </div>
          </div>

          <div class="field">
            <label>Ticket Sales Deadline *</label>
            <div class="fi"><i class="fi-icon fa-solid fa-hourglass-half"></i>
              <input type="date" name="deadline" id="f_deadline" required
                     value="<?= h($old_post['deadline'] ?? '') ?>">
            </div>
            <div class="field-hint">When ticket sales close — usually on or before the event day.</div>
          </div>

          <div class="field">
            <label>Event Category</label>
            <div class="fi"><i class="fi-icon fa-solid fa-tag"></i>
              <select name="category">
                <?php
                $cats = ['Concert','Comedy','Conference','Meetup / Hangout','Party / Club Event','Fitness / Sports','Education / Workshop','Other'];
                $selCat = $old_post['category'] ?? '';
                foreach ($cats as $c) {
                    $sel = $c === $selCat ? ' selected' : '';
                    echo "<option{$sel}>" . h($c) . "</option>";
                }
                ?>
              </select>
            </div>
          </div>

          <!-- Banner -->
          <div class="field">
            <label>Event Banner <span style="font-weight:400;color:var(--muted)">(max 3 MB · JPEG/PNG/WEBP/GIF)</span></label>
            <?php if ($error): ?>
            <div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);border-radius:8px;padding:8px 12px;margin-bottom:8px;font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:700;color:#d97706;display:flex;align-items:center;gap:6px;">
              <i class="fa-solid fa-triangle-exclamation"></i>
              Please re-select your banner image (browser security prevents preserving file inputs).
            </div>
            <?php endif; ?>
            <div class="file-drop" id="fileDrop">
              <input type="file" name="banner" accept="image/jpeg,image/png,image/webp,image/gif"
                     id="bannerInput" onchange="handleFileChange(this)">
              <div class="file-drop-icon" id="fileDropIcon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
              <div class="file-drop-text" id="fileLabel">Click or drag to upload banner</div>
              <div class="file-drop-sub">PNG, JPG, WEBP — Recommended 1200×628px</div>
              <div class="upload-size-error" id="uploadSizeError">
                <i class="fa-solid fa-triangle-exclamation"></i> File too large — maximum is 3 MB.
              </div>
              <div class="upload-progress-wrap" id="uploadProgressWrap">
                <div class="upload-progress-bar" id="uploadProgressBar"></div>
              </div>
              <div class="upload-progress-label" id="uploadProgressLabel"></div>
              <img class="banner-preview" id="bannerPreview" src="" alt="Banner preview">
            </div>
          </div>

          <div class="field no-ico">
            <label>Event Description</label>
            <textarea name="description" placeholder="Describe your event — what to expect, dress code, special guests, etc."><?= h($old_post['description'] ?? '') ?></textarea>
          </div>

        </div>
      </div>
      <div class="step-nav">
        <div></div>
        <button type="button" class="btn-next" onclick="goStep(2)">
          Next: Pricing <i class="fa-solid fa-arrow-right"></i>
        </button>
      </div>
    </div>

    <!-- ══════════════ STEP 2 — Fee Calculator ══════════════ -->
    <div class="step-panel" id="step-2">
      <div class="sec-label">Fee Calculator</div>
      <div class="calc-card">
        <div class="calc-head">
          <div class="calc-head-ico"><i class="fa-solid fa-calculator"></i></div>
          <div>
            <div class="calc-head-title">Earnings Calculator — Before You Set Prices</div>
            <div class="calc-head-sub">See exactly what you earn per ticket sold</div>
          </div>
        </div>
        <div class="calc-body">
          <div class="calc-info">
            <div class="calc-info-title"><i class="fa-solid fa-circle-info"></i> How Keychele Ticket Fees Work</div>
            <div class="calc-info-text">
              Keychele charges a <strong>3.5% platform fee</strong> on every paid ticket.
              This is automatically deducted at purchase time.<br><br>
              <strong>Example:</strong> Ticket at <strong>KSh 1,000</strong> → Keychele deducts <strong>KSh 35 (3.5%)</strong> → You receive <strong>KSh 965</strong>.<br><br>
              <strong>Free tickets</strong> and <strong>deposits</strong> have <strong>zero fees</strong>.
            </div>
          </div>
          <div class="calc-input-wrap">
            <span class="calc-input-label">Enter a ticket price to calculate</span>
            <div class="calc-input-row">
              <span class="calc-prefix">KSh</span>
              <input type="number" id="calcPrice" min="0" step="50" placeholder="e.g. 1000" oninput="runCalc()">
            </div>
          </div>
          <div class="calc-results" id="calcResults" style="display:none">
            <div class="calc-res-box danger">
              <div class="crb-label"><i class="fa-solid fa-minus"></i> Platform Fee (3.5%)</div>
              <div class="crb-val" id="calcFee">KSh 0</div>
              <div class="crb-sub">Deducted by Keychele</div>
            </div>
            <div class="calc-res-box highlight">
              <div class="crb-label"><i class="fa-solid fa-vault"></i> You Receive</div>
              <div class="crb-val" id="calcYou">KSh 0</div>
              <div class="crb-sub">Credited to your wallet</div>
            </div>
            <div class="calc-res-full">
              <div class="crf-left">
                <div class="crb-label"><i class="fa-solid fa-money-bill-wave"></i> Buyer Pays</div>
                <div class="crb-val" id="calcBuyer">KSh 0</div>
                <div class="crb-sub">Full price — no hidden charges to buyer</div>
              </div>
              <div class="crf-icon"><i class="fa-solid fa-receipt"></i></div>
            </div>
            <div class="calc-breakdown" style="grid-column:1/-1">
              <div class="cbd-row" style="background:rgba(5,46,100,0.04)">
                <span style="font-family:'Montserrat',sans-serif;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--muted);">Projected Earnings</span>
                <span style="font-size:0.65rem;color:var(--muted)">After 3.5% fee</span>
              </div>
              <div class="cbd-row"><span class="cbd-row-label"><span class="dot" style="background:#fec411"></span>10 tickets sold</span><span class="cbd-row-val green" id="proj10">—</span></div>
              <div class="cbd-row"><span class="cbd-row-label"><span class="dot" style="background:#52c9a8"></span>50 tickets sold</span><span class="cbd-row-val green" id="proj50">—</span></div>
              <div class="cbd-row"><span class="cbd-row-label"><span class="dot" style="background:#2ecc9a"></span>100 tickets sold</span><span class="cbd-row-val green" id="proj100">—</span></div>
            </div>
          </div>
          <div id="calcEmpty" style="text-align:center;padding:1.5rem 1rem;color:var(--muted);font-family:'Montserrat',sans-serif;font-size:0.8rem;">
            Enter a ticket price above to see your earnings breakdown
          </div>
        </div>
      </div>
      <div class="step-nav">
        <button type="button" class="btn-prev" onclick="goStep(1)"><i class="fa-solid fa-arrow-left"></i> Back</button>
        <button type="button" class="btn-next" onclick="goStep(3)">Next: Set Tickets <i class="fa-solid fa-arrow-right"></i></button>
      </div>
    </div>

    <!-- ══════════════ STEP 3 — Ticket Types ══════════════ -->
    <div class="step-panel" id="step-3">
      <div class="sec-label">Ticket Types &amp; Pricing</div>
      <div class="card">
        <div class="card-head">
          <div class="card-head-ico" style="background:rgba(191,19,57,0.08);color:var(--red)"><i class="fa-solid fa-ticket"></i></div>
          <div>
            <div class="card-head-title">Define Your Ticket Tiers</div>
            <div class="card-head-sub">Create tiers — Regular, VIP, Early Bird, Free, etc. Add optional deposits to any paid tier.</div>
          </div>
        </div>
        <div class="card-body">

          <div id="ttContainer">
          <?php foreach ($old_tickets_data as $ti => $tt): ?>
            <?php
              $isFree     = !empty($tt['is_free']);
              $depEnabled = !empty($tt['deposit_enabled']);
              $isFirst    = ($ti === 0);
              $removeClass = $isFirst && count($old_tickets_data) === 1 ? ' hidden' : '';
            ?>
            <div class="tt-block" data-index="<?= $ti ?>">
              <div class="tt-block-top">
                <input type="text"
                       name="ticket_type[<?= $ti ?>]"
                       value="<?= h($tt['name']) ?>"
                       class="tt-name-input"
                       required
                       placeholder="Ticket name e.g. VIP, Regular">

                <span class="dep-badge<?= $depEnabled ? ' visible' : '' ?>" id="depBadge<?= $ti ?>">
                  <i class="fa-solid fa-down-to-line" style="font-size:0.6rem"></i> Deposit ON
                </span>

                <label class="tt-free-label">
                  <input type="hidden"   name="is_free[<?= $ti ?>]" value="0">
                  <input type="checkbox" name="is_free[<?= $ti ?>]" value="1"
                         class="tt-free-cb is-free-cb"<?= $isFree ? ' checked' : '' ?>>
                  <span class="tt-free-text">Free</span>
                </label>

                <button type="button" class="tt-remove-btn<?= $removeClass ?>"
                        onclick="removeTicketType(this)">
                  <i class="fa-solid fa-trash-can"></i>
                </button>
              </div>

              <div class="grid2">
                <div class="field no-ico">
                  <label>Price (KES)</label>
                  <input type="number"
                         name="ticket_price[<?= $ti ?>]"
                         min="0" step="50"
                         value="<?= h($tt['price']) ?>"
                         class="tt-number-input price-input"
                         placeholder="e.g. 500"
                         <?= $isFree ? 'disabled style="opacity:0.5;cursor:not-allowed;background:#e8e8e8;"' : '' ?>>
                </div>
                <div class="field no-ico">
                  <label>Quantity (0 = unlimited)</label>
                  <input type="number"
                         name="ticket_qty[<?= $ti ?>]"
                         min="0"
                         value="<?= h($tt['qty']) ?>"
                         class="tt-number-input"
                         placeholder="e.g. 300">
                </div>
              </div>

              <!-- Deposit panel -->
              <div class="deposit-panel<?= $depEnabled ? ' open' : '' ?>" id="depPanel<?= $ti ?>">
                <div class="deposit-panel-title">
                  <i class="fa-solid fa-down-to-line"></i> Deposit / Part-Payment Option
                </div>
                <div class="dep-toggle-row">
                  <label class="dep-toggle-label">
                    <input type="hidden"   name="deposit_enabled[<?= $ti ?>]" value="0">
                    <input type="checkbox" name="deposit_enabled[<?= $ti ?>]" value="1"
                           class="dep-toggle-cb tt-dep-cb"
                           data-badge="depBadge<?= $ti ?>"
                           onchange="toggleDepositAmount(this)"
                           <?= $depEnabled ? ' checked' : '' ?>>
                    <span class="dep-toggle-text">Allow buyers to pay a deposit now &amp; balance later</span>
                  </label>
                </div>
                <div class="dep-amount-wrap" style="<?= $depEnabled ? 'display:block' : 'display:none' ?>;margin-top:0.85rem;">
                  <div class="field no-ico" style="margin-bottom:0">
                    <label>Deposit Amount (KES) *</label>
                    <input type="number"
                           name="deposit_amount[<?= $ti ?>]"
                           min="1" step="50"
                           value="<?= h($tt['deposit_amount']) ?>"
                           class="tt-number-input deposit-input<?= ($error && $depEnabled && empty($tt['deposit_amount'])) ? ' field-error' : '' ?>"
                           placeholder="e.g. 500"
                           style="border:1.5px solid rgba(8,145,178,0.35)">
                    <div class="deposit-hint">Must be less than the ticket price. Buyers pay this now and the balance before the event deadline.</div>
                  </div>
                </div>
              </div>

              <!-- Deposit trigger button -->
              <div class="dep-trigger-row"
                   style="margin-top:0.75rem;<?= $isFree ? 'display:none;' : '' ?>">
                <button type="button"
                        class="dep-open-btn"
                        onclick="toggleDepositPanel(this)"
                        style="display:inline-flex;align-items:center;gap:6px;
                               background:rgba(8,145,178,<?= $depEnabled ? '0.14' : '0.07' ?>);
                               border:1.5px solid rgba(8,145,178,0.22);border-radius:8px;
                               padding:7px 14px;color:var(--teal);font-family:'Montserrat',sans-serif;
                               font-weight:700;font-size:0.73rem;cursor:pointer;transition:background 0.18s;">
                  <i class="fa-solid fa-down-to-line" style="font-size:0.72rem"></i>
                  <span class="dep-btn-label"><?= $depEnabled ? 'Remove Deposit Option' : 'Add Deposit Option' ?></span>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
          </div><!-- /ttContainer -->

          <button type="button" class="add-tt-btn" onclick="addTicketType()">
            <i class="fa-solid fa-plus"></i> Add Another Ticket Type
          </button>

          <div class="step-nav" style="margin-top:0">
            <button type="button" class="btn-prev" onclick="goStep(2)">
              <i class="fa-solid fa-arrow-left"></i> Back
            </button>
            <button type="button" class="btn-next" onclick="goStep(4)">
              Next: Settings <i class="fa-solid fa-arrow-right"></i>
            </button>
          </div>

        </div>
      </div>
    </div>

    <!-- ══════════════ STEP 4 — Settings ══════════════ -->
    <div class="step-panel" id="step-4">
      <div class="sec-label">Visibility &amp; Display Settings</div>

      <!-- Visibility Card -->
      <div class="card">
        <div class="card-head">
          <div class="card-head-ico" style="background:rgba(124,58,237,0.08);color:var(--purple)"><i class="fa-solid fa-eye"></i></div>
          <div>
            <div class="card-head-title">Event Visibility</div>
            <div class="card-head-sub">Control who can discover and access your event</div>
          </div>
        </div>
        <div class="card-body" style="padding-bottom:1.75rem">

          <!-- Hidden field default; overridden by radio -->
          <div style="margin-bottom:0.6rem">
            <label style="font-family:'Montserrat',sans-serif;font-size:0.75rem;font-weight:700;color:#374151;display:block;margin-bottom:0.9rem">
              Who can find this event?
            </label>
            <div class="vis-options">
              <!-- PUBLIC -->
              <label class="vis-opt public">
                <input type="radio" name="visibility" value="public"
                       <?= ($old_settings['visibility'] === 'public') ? 'checked' : '' ?>
                       onchange="updateVisibility(this)">
                <div class="vis-opt-card">
                  <div class="vis-opt-ico"><i class="fa-solid fa-globe"></i></div>
                  <div class="vis-opt-label">Public</div>
                  <div class="vis-opt-sub">Listed on the homepage. Anyone can discover and buy tickets.</div>
                  <div class="vis-check"><i class="fa-solid fa-check"></i></div>
                </div>
              </label>
              <!-- PRIVATE -->
              <label class="vis-opt private">
                <input type="radio" name="visibility" value="private"
                       <?= ($old_settings['visibility'] === 'private') ? 'checked' : '' ?>
                       onchange="updateVisibility(this)">
                <div class="vis-opt-card">
                  <div class="vis-opt-ico"><i class="fa-solid fa-lock"></i></div>
                  <div class="vis-opt-label">Private</div>
                  <div class="vis-opt-sub">Not listed publicly. Only accessible via your direct link.</div>
                  <div class="vis-check"><i class="fa-solid fa-check"></i></div>
                </div>
              </label>
            </div>
          </div>

          <!-- Private warning notice -->
          <div class="private-notice <?= ($old_settings['visibility'] === 'private') ? 'show' : '' ?>" id="privateNotice">
            <i class="fa-solid fa-lock"></i>
            <div class="private-notice-text">
              <strong>Private event:</strong> This ticket will NOT appear on the Keychele homepage or search results. Share your link directly with invited guests — only people with the link can view and purchase tickets.
            </div>
          </div>

        </div>
      </div>

      <!-- Display Settings Card -->
      <div class="card">
        <div class="card-head">
          <div class="card-head-ico" style="background:rgba(8,145,178,0.08);color:var(--teal)"><i class="fa-solid fa-chart-bar"></i></div>
          <div>
            <div class="card-head-title">Public Display Settings</div>
            <div class="card-head-sub">Choose what stats are visible to people browsing your event</div>
          </div>
        </div>
        <div style="padding:0">

          <!-- show_views -->
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-title">
                <div class="s-ico" style="background:rgba(254,196,17,0.12);color:var(--gold)">
                  <i class="fa-solid fa-eye"></i>
                </div>
                Show View Count
                <span class="setting-badge recommended">Recommended</span>
              </div>
              <div class="setting-desc">
                Display the total number of times your event page has been viewed on the homepage listing and event detail page. Builds social proof — high views encourage more buyers.
              </div>
            </div>
            <div class="toggle-wrap">
              <!-- Hidden field before toggle so unchecked = '0' -->
              <input type="hidden" name="show_views" value="0">
              <label class="toggle-switch">
                <input type="checkbox" name="show_views" value="1"
                       id="toggleViews"
                       <?= ($old_settings['show_views'] === '1') ? 'checked' : '' ?>
                       onchange="updateSummary()">
                <span class="toggle-knob"></span>
              </label>
            </div>
          </div>

          <!-- show_remaining -->
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-title">
                <div class="s-ico" style="background:rgba(191,19,57,0.08);color:var(--red)">
                  <i class="fa-solid fa-ticket"></i>
                </div>
                Show Remaining Tickets
                <span class="setting-badge recommended">Recommended</span>
              </div>
              <div class="setting-desc">
                Publicly show how many tickets are still available for each tier. Creates urgency — "Only 12 left!" encourages faster purchases and reduces hesitation.
              </div>
            </div>
            <div class="toggle-wrap">
              <input type="hidden" name="show_remaining" value="0">
              <label class="toggle-switch">
                <input type="checkbox" name="show_remaining" value="1"
                       id="toggleRemaining"
                       <?= ($old_settings['show_remaining'] === '1') ? 'checked' : '' ?>
                       onchange="updateSummary()">
                <span class="toggle-knob"></span>
              </label>
            </div>
          </div>

        </div>
      </div>

      <!-- Settings summary preview -->
      <div class="settings-summary">
        <div class="ss-title"><i class="fa-solid fa-sliders"></i> Current Settings Preview</div>
        <div class="ss-row">
          <span class="ss-row-label"><i class="fa-solid fa-globe"></i> Visibility</span>
          <span class="ss-badge <?= ($old_settings['visibility'] === 'private') ? 'priv' : 'pub' ?>" id="ssVisibility">
            <?= ($old_settings['visibility'] === 'private')
              ? '<i class="fa-solid fa-lock" style="font-size:0.6rem"></i> Private'
              : '<i class="fa-solid fa-globe" style="font-size:0.6rem"></i> Public' ?>
          </span>
        </div>
        <div class="ss-row">
          <span class="ss-row-label"><i class="fa-solid fa-eye"></i> View count shown</span>
          <span class="ss-badge <?= ($old_settings['show_views'] === '1') ? 'on' : 'off' ?>" id="ssViews">
            <?= ($old_settings['show_views'] === '1') ? '✓ Visible' : '✕ Hidden' ?>
          </span>
        </div>
        <div class="ss-row">
          <span class="ss-row-label"><i class="fa-solid fa-ticket"></i> Remaining tickets shown</span>
          <span class="ss-badge <?= ($old_settings['show_remaining'] === '1') ? 'on' : 'off' ?>" id="ssRemaining">
            <?= ($old_settings['show_remaining'] === '1') ? '✓ Visible' : '✕ Hidden' ?>
          </span>
        </div>
      </div>

      <div class="step-nav">
        <button type="button" class="btn-prev" onclick="goStep(3)">
          <i class="fa-solid fa-arrow-left"></i> Back
        </button>
        <button type="submit" class="btn-submit" id="submitBtn">
          <i class="fa-solid fa-calendar-plus"></i>
          Create Event &amp; Tickets
        </button>
      </div>
    </div>

  </form>
<?php endif; ?>
</div><!-- /page -->

<script>
/* ════════════════════════════════════════════════
   STEP NAVIGATION  (now 4 steps)
════════════════════════════════════════════════ */
var currentStep = 1, totalSteps = 4;

function goStep(n) {
  /* ── Step 1 → 2 validation ── */
  if (n === 2 && currentStep === 1) {
    var ids = ['f_title','f_venue','f_start_date','f_start_time','f_end_date','f_end_time','f_deadline'];
    var bad = ids.map(function(id){ return document.getElementById(id); })
                 .filter(function(el){ return el && !el.value.trim(); });
    if (bad.length) {
      bad.forEach(function(el){
        el.classList.add('field-error');
        el.addEventListener('input', function(){ this.classList.remove('field-error'); }, {once:true});
      });
      bad[0].focus();
      toastr.error('Please fill in all required fields before continuing.', '', { timeOut:3000 });
      return;
    }
    if (document.getElementById('uploadSizeError').classList.contains('visible')) {
      toastr.error('Please remove the oversized banner file before continuing.', '', { timeOut:3000 });
      return;
    }
  }

  /* ── Step 3 → 4: quick ticket name check ── */
  if (n === 4 && currentStep === 3) {
    var nameInputs = document.querySelectorAll('#ttContainer .tt-name-input');
    var emptyName = false;
    nameInputs.forEach(function(inp){
      if (!inp.value.trim()) {
        inp.classList.add('field-error');
        inp.addEventListener('input', function(){ this.classList.remove('field-error'); }, {once:true});
        emptyName = true;
      }
    });
    if (emptyName) {
      toastr.error('Please give every ticket type a name before continuing.', '', { timeOut:3500 });
      return;
    }
  }

  document.getElementById('step-' + currentStep).classList.remove('active');
  document.getElementById('step-' + n).classList.add('active');

  for (var i = 1; i <= totalSteps; i++) {
    var pill = document.getElementById('pill-' + i);
    var conn = document.getElementById('conn-' + i);
    if (i < n) {
      pill.className = 'step-pill done';
      pill.querySelector('.sp-num').innerHTML = '<i class="fa-solid fa-check" style="font-size:0.65rem"></i>';
      if (conn) conn.className = 'step-connector done-line';
    } else if (i === n) {
      pill.className = 'step-pill active';
      pill.querySelector('.sp-num').textContent = i;
      if (conn) conn.className = 'step-connector';
    } else {
      pill.className = 'step-pill pending';
      pill.querySelector('.sp-num').textContent = i;
      if (conn) conn.className = 'step-connector';
    }
  }
  currentStep = n;
  window.scrollTo({ top:0, behavior:'smooth' });
}

/* ════════════════════════════════════════════════
   SETTINGS — visibility & summary live update
════════════════════════════════════════════════ */
function updateVisibility(radio) {
  var notice = document.getElementById('privateNotice');
  var ssBadge = document.getElementById('ssVisibility');
  if (radio.value === 'private') {
    notice.classList.add('show');
    ssBadge.className = 'ss-badge priv';
    ssBadge.innerHTML = '<i class="fa-solid fa-lock" style="font-size:0.6rem"></i> Private';
  } else {
    notice.classList.remove('show');
    ssBadge.className = 'ss-badge pub';
    ssBadge.innerHTML = '<i class="fa-solid fa-globe" style="font-size:0.6rem"></i> Public';
  }
}

function updateSummary() {
  var viewsCb     = document.getElementById('toggleViews');
  var remainingCb = document.getElementById('toggleRemaining');
  var ssViews     = document.getElementById('ssViews');
  var ssRemaining = document.getElementById('ssRemaining');

  if (viewsCb.checked) {
    ssViews.className = 'ss-badge on';
    ssViews.textContent = '✓ Visible';
  } else {
    ssViews.className = 'ss-badge off';
    ssViews.textContent = '✕ Hidden';
  }

  if (remainingCb.checked) {
    ssRemaining.className = 'ss-badge on';
    ssRemaining.textContent = '✓ Visible';
  } else {
    ssRemaining.className = 'ss-badge off';
    ssRemaining.textContent = '✕ Hidden';
  }
}

/* ════════════════════════════════════════════════
   FEE CALCULATOR
════════════════════════════════════════════════ */
var FEE_RATE = 0.035;
function fmt(n) {
  return 'KSh ' + parseFloat(n).toLocaleString('en-KE', { minimumFractionDigits:2, maximumFractionDigits:2 });
}
function runCalc() {
  var price = parseFloat(document.getElementById('calcPrice').value) || 0;
  var results = document.getElementById('calcResults');
  var empty   = document.getElementById('calcEmpty');
  if (price <= 0) { results.style.display='none'; empty.style.display='block'; return; }
  var fee = price * FEE_RATE, you = price - fee;
  document.getElementById('calcFee').textContent   = fmt(fee);
  document.getElementById('calcYou').textContent   = fmt(you);
  document.getElementById('calcBuyer').textContent = fmt(price);
  document.getElementById('proj10').textContent    = fmt(you * 10);
  document.getElementById('proj50').textContent    = fmt(you * 50);
  document.getElementById('proj100').textContent   = fmt(you * 100);
  results.style.display = 'grid';
  empty.style.display   = 'none';
}

/* ════════════════════════════════════════════════
   BANNER UPLOAD
════════════════════════════════════════════════ */
var MAX_BYTES = 3 * 1024 * 1024;
function handleFileChange(input) {
  var label   = document.getElementById('fileLabel');
  var pWrap   = document.getElementById('uploadProgressWrap');
  var pBar    = document.getElementById('uploadProgressBar');
  var pLbl    = document.getElementById('uploadProgressLabel');
  var sizeErr = document.getElementById('uploadSizeError');
  var preview = document.getElementById('bannerPreview');
  var drop    = document.getElementById('fileDrop');

  pWrap.classList.remove('visible'); pLbl.classList.remove('visible');
  sizeErr.classList.remove('visible'); preview.classList.remove('visible');
  pBar.style.width = '0%';

  if (!input.files || !input.files[0]) return;
  var file = input.files[0];

  if (file.size > MAX_BYTES) {
    sizeErr.classList.add('visible');
    label.textContent = 'File too large — please choose another';
    drop.style.borderColor = 'var(--red)';
    drop.style.background  = 'rgba(191,19,57,0.04)';
    input.value = ''; return;
  }
  var allowed = ['image/jpeg','image/png','image/webp','image/gif'];
  if (!allowed.includes(file.type)) {
    toastr.error('Only JPEG, PNG, WEBP or GIF images are accepted.', '', { timeOut:4000 });
    input.value = ''; return;
  }

  label.textContent = file.name;
  drop.style.borderColor = 'var(--navy)';
  drop.style.background  = '#f0f4ff';
  pWrap.classList.add('visible'); pLbl.classList.add('visible');
  pLbl.textContent = 'Reading file…';

  var reader = new FileReader(), fake = 0;
  var ticker = setInterval(function() {
    fake = Math.min(fake + Math.random() * 18 + 6, 85);
    pBar.style.width = fake + '%';
    pLbl.textContent = 'Processing… ' + Math.round(fake) + '%';
  }, 80);

  reader.onload = function(e) {
    clearInterval(ticker);
    pBar.style.width = '100%';
    pLbl.textContent = '✓ Ready — ' + (file.size / 1024).toFixed(1) + ' KB';
    preview.src = e.target.result;
    preview.classList.add('visible');
    var ico = document.getElementById('fileDropIcon').querySelector('i');
    ico.className = 'fa-solid fa-circle-check'; ico.style.color = 'var(--success)';
  };
  reader.onerror = function() { clearInterval(ticker); pLbl.textContent = 'Could not read file.'; };
  reader.readAsDataURL(file);
}

/* ════════════════════════════════════════════════
   TICKET TYPES
════════════════════════════════════════════════ */
var ttCount = <?= count($old_tickets_data) ?>;

function buildTicketBlock(index) {
  return (
    '<div class="tt-block" style="animation:cardIn 0.3s cubic-bezier(0.16,1,0.3,1) both">' +
      '<div class="tt-block-top">' +
        '<input type="text" name="ticket_type[' + index + ']" required class="tt-name-input" placeholder="e.g. VIP, Early Bird, Student">' +
        '<span class="dep-badge" id="depBadge' + index + '"><i class="fa-solid fa-down-to-line" style="font-size:0.6rem"></i> Deposit ON</span>' +
        '<label class="tt-free-label">' +
          '<input type="hidden" name="is_free[' + index + ']" value="0">' +
          '<input type="checkbox" name="is_free[' + index + ']" value="1" class="tt-free-cb is-free-cb">' +
          '<span class="tt-free-text">Free</span>' +
        '</label>' +
        '<button type="button" class="tt-remove-btn" onclick="removeTicketType(this)"><i class="fa-solid fa-trash-can"></i></button>' +
      '</div>' +
      '<div class="grid2">' +
        '<div class="field no-ico"><label>Price (KES)</label>' +
          '<input type="number" name="ticket_price[' + index + ']" min="0" step="50" value="0" class="tt-number-input price-input" placeholder="e.g. 800">' +
        '</div>' +
        '<div class="field no-ico"><label>Quantity (0 = unlimited)</label>' +
          '<input type="number" name="ticket_qty[' + index + ']" min="0" class="tt-number-input" placeholder="e.g. 100">' +
        '</div>' +
      '</div>' +
      '<div class="deposit-panel" id="depPanel' + index + '">' +
        '<div class="deposit-panel-title"><i class="fa-solid fa-down-to-line"></i> Deposit / Part-Payment Option</div>' +
        '<div class="dep-toggle-row"><label class="dep-toggle-label">' +
          '<input type="hidden" name="deposit_enabled[' + index + ']" value="0">' +
          '<input type="checkbox" name="deposit_enabled[' + index + ']" value="1" class="dep-toggle-cb tt-dep-cb" data-badge="depBadge' + index + '" onchange="toggleDepositAmount(this)">' +
          '<span class="dep-toggle-text">Allow buyers to pay a deposit now &amp; balance later</span>' +
        '</label></div>' +
        '<div class="dep-amount-wrap" style="display:none;margin-top:0.85rem;">' +
          '<div class="field no-ico" style="margin-bottom:0"><label>Deposit Amount (KES) *</label>' +
            '<input type="number" name="deposit_amount[' + index + ']" min="1" step="50" class="tt-number-input deposit-input" placeholder="e.g. 500" style="border:1.5px solid rgba(8,145,178,0.35)">' +
            '<div class="deposit-hint">Must be less than the ticket price. Buyers pay this now and the remaining balance before the event deadline.</div>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div class="dep-trigger-row" style="margin-top:0.75rem;">' +
        '<button type="button" class="dep-open-btn" onclick="toggleDepositPanel(this)" ' +
          'style="display:inline-flex;align-items:center;gap:6px;background:rgba(8,145,178,0.07);' +
          'border:1.5px solid rgba(8,145,178,0.22);border-radius:8px;padding:7px 14px;color:var(--teal);' +
          'font-family:\'Montserrat\',sans-serif;font-weight:700;font-size:0.73rem;cursor:pointer;transition:background 0.18s;">' +
          '<i class="fa-solid fa-down-to-line" style="font-size:0.72rem"></i>' +
          '<span class="dep-btn-label">Add Deposit Option</span>' +
        '</button>' +
      '</div>' +
    '</div>'
  );
}

function addTicketType() {
  var container = document.getElementById('ttContainer');
  var div = document.createElement('div');
  div.innerHTML = buildTicketBlock(ttCount);
  container.appendChild(div.firstChild);
  ttCount++;
  document.querySelectorAll('.tt-remove-btn').forEach(function(b){ b.classList.remove('hidden'); });
  toastr.info('New ticket type added', '', { timeOut:1800, positionClass:'toast-bottom-right' });
}

function removeTicketType(btn) {
  var block = btn.closest('.tt-block');
  block.style.opacity='0'; block.style.transform='translateY(-8px)';
  block.style.transition='all 0.22s ease';
  setTimeout(function() {
    block.remove();
    var rem = document.querySelectorAll('.tt-remove-btn');
    if (rem.length === 1) rem[0].classList.add('hidden');
  }, 220);
}

/* ── Free ticket toggle ── */
document.addEventListener('change', function(e) {
  if (!e.target.classList.contains('is-free-cb')) return;
  var block      = e.target.closest('.tt-block');
  var priceInput = block.querySelector('.price-input');
  var depTrigger = block.querySelector('.dep-trigger-row');
  var depPanel   = block.querySelector('.deposit-panel');
  var depCb      = block.querySelector('.tt-dep-cb');
  var depBadge   = depCb ? document.getElementById(depCb.dataset.badge) : null;

  if (e.target.checked) {
    priceInput.value = 0; priceInput.disabled = true;
    priceInput.style.cssText += 'opacity:0.5;cursor:not-allowed;background:#e8e8e8;';
    if (depTrigger) depTrigger.style.display = 'none';
    if (depPanel)   depPanel.classList.remove('open');
    if (depCb)      { depCb.checked = false; }
    if (depBadge)   depBadge.classList.remove('visible');
    block.querySelectorAll('.deposit-input').forEach(function(i){ i.disabled = true; i.value = ''; });
  } else {
    priceInput.disabled = false;
    priceInput.style.cssText = priceInput.style.cssText
      .replace('opacity:0.5;','').replace('cursor:not-allowed;','').replace('background:#e8e8e8;','');
    if (depTrigger) depTrigger.style.display = '';
    block.querySelectorAll('.deposit-input').forEach(function(i){ i.disabled = false; });
  }
});

/* ── Deposit panel toggle ── */
function toggleDepositPanel(btn) {
  var block = btn.closest('.tt-block');
  var panel = block.querySelector('.deposit-panel');
  var label = btn.querySelector('.dep-btn-label');
  if (panel.classList.contains('open')) {
    panel.classList.remove('open');
    label.textContent = 'Add Deposit Option';
    btn.style.background = 'rgba(8,145,178,0.07)';
    var cb = panel.querySelector('.tt-dep-cb');
    if (cb) { cb.checked = false; toggleDepositAmount(cb); }
  } else {
    panel.classList.add('open');
    label.textContent = 'Remove Deposit Option';
    btn.style.background = 'rgba(8,145,178,0.14)';
  }
}

function toggleDepositAmount(cb) {
  var panel      = cb.closest('.deposit-panel');
  var amountWrap = panel.querySelector('.dep-amount-wrap');
  var badge      = document.getElementById(cb.dataset.badge);
  if (cb.checked) {
    amountWrap.style.display = 'block';
    if (badge) badge.classList.add('visible');
  } else {
    amountWrap.style.display = 'none';
    if (badge) badge.classList.remove('visible');
    var inp = panel.querySelector('.deposit-input');
    if (inp) inp.value = '';
  }
}

/* ════════════════════════════════════════════════
   CREATION PROGRESS OVERLAY
════════════════════════════════════════════════ */
var progressStages = [
  { pct: 1,  label: 'Preparing your submission…',   step: 0, delay: 0 },
  { pct: 5,  label: 'Validating event details…',    step: 0, delay: 350 },
  { pct: 30, label: 'Processing banner image…',     step: 1, delay: 900 },
  { pct: 50, label: 'Creating event record…',       step: 2, delay: 1700 },
  { pct: 70, label: 'Setting up ticket types…',     step: 3, delay: 2500 },
  { pct: 85, label: 'Saving settings…',             step: 3, delay: 3400 },
  { pct: 92, label: 'Almost there…',                step: 4, delay: 4200 },
  { pct: 97, label: 'Wrapping up…',                 step: 4, delay: 5000 },
];

function startCreationProgress() {
  var overlay = document.getElementById('creationOverlay');
  overlay.classList.add('active');
  var fill  = document.getElementById('creationBarFill');
  var pct   = document.getElementById('creationPct');
  var stage = document.getElementById('creationStage');
  var steps = document.querySelectorAll('.csm-step');

  progressStages.forEach(function(s) {
    setTimeout(function() {
      fill.style.width   = s.pct + '%';
      pct.textContent    = s.pct + '%';
      stage.textContent  = s.label;
      steps.forEach(function(el, idx) {
        el.className = 'csm-step' + (idx < s.step ? ' done' : (idx === s.step ? ' active' : ''));
      });
    }, s.delay);
  });
}

/* ════════════════════════════════════════════════
   FORM SUBMIT — VALIDATE + PROGRESS
════════════════════════════════════════════════ */
var createForm = document.getElementById('createForm');
if (createForm) {
  createForm.addEventListener('submit', function(e) {
    var valid = true;
    document.querySelectorAll('.tt-block').forEach(function(block) {
      var depCb  = block.querySelector('.tt-dep-cb');
      var priceI = block.querySelector('.price-input');
      var depI   = block.querySelector('.deposit-input');
      var nameI  = block.querySelector('.tt-name-input');
      if (depCb && depCb.checked && depI && priceI) {
        var price = parseFloat(priceI.value) || 0;
        var dep   = parseFloat(depI.value)   || 0;
        var name  = nameI ? nameI.value.trim() : 'This ticket';
        if (dep <= 0) {
          depI.classList.add('field-error');
          toastr.error('Deposit for "' + name + '" must be greater than zero.', '', { timeOut:4500 });
          depI.focus(); valid = false;
        } else if (dep >= price) {
          depI.classList.add('field-error');
          toastr.error('Deposit for "' + name + '" must be less than KSh ' + price + '.', '', { timeOut:4500 });
          depI.focus(); valid = false;
        }
      }
    });
    if (!valid) { e.preventDefault(); return; }

    startCreationProgress();

    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Creating…';
  });
}

/* ════════════════════════════════════════════════
   COPY LINK
════════════════════════════════════════════════ */
function copyTicketLink() {
  var url = document.getElementById('ticketUrl').textContent.trim();
  var btn = document.getElementById('copyBtn');
  function onSuccess() {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    btn.classList.add('copied');
    showCToast('Link copied to clipboard!');
    setTimeout(function(){ btn.innerHTML='<i class="fa-solid fa-copy"></i> Copy Link'; btn.classList.remove('copied'); }, 2500);
  }
  function fallback() {
    try {
      var ta = document.createElement('textarea');
      ta.value = url; ta.setAttribute('readonly','');
      ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
      document.body.appendChild(ta); ta.focus(); ta.select(); ta.setSelectionRange(0, ta.value.length);
      if (document.execCommand('copy')) onSuccess();
      document.body.removeChild(ta);
    } catch(err){}
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(onSuccess).catch(fallback);
  } else { fallback(); }
}
function showCToast(msg) {
  var el = document.getElementById('cToast');
  document.getElementById('cToastMsg').textContent = msg;
  el.classList.add('show');
  setTimeout(function(){ el.classList.remove('show'); }, 3000);
}

/* ════════════════════════════════════════════════
   SUCCESS CONFETTI
════════════════════════════════════════════════ */
var dotsEl = document.getElementById('sbDots');
if (dotsEl) {
  var cols = ['#fec411','#00b37e','#fff','#bf1339','#48b6e3','#a78bfa'];
  for (var di = 0; di < 18; di++) {
    var d = document.createElement('div'); d.className = 'sb-dot';
    var sz = Math.random() * 8 + 4;
    d.style.cssText = ['width:'+sz+'px','height:'+sz+'px','left:'+(Math.random()*100)+'%',
      'background:'+cols[Math.floor(Math.random()*cols.length)],
      'opacity:'+(Math.random()*0.5+0.2),
      'animation-duration:'+(Math.random()*8+6)+'s',
      'animation-delay:'+(Math.random()*6)+'s'].join(';');
    dotsEl.appendChild(d);
  }
}

/* ════════════════════════════════════════════════
   TOASTR CONFIG
════════════════════════════════════════════════ */
toastr.options = {
  closeButton: true, progressBar: true,
  positionClass: 'toast-top-right', timeOut: 5500,
  extendedTimeOut: 1500, showMethod: 'slideDown', hideMethod: 'fadeOut'
};

/* ════════════════════════════════════════════════
   ON ERROR — jump to error step & show toast
════════════════════════════════════════════════ */
<?php if ($error): ?>
window.addEventListener('DOMContentLoaded', function() {
  goStep(<?= (int)$error_step ?>);
  toastr.error(
    '<strong><?= addslashes(h($error)) ?></strong><br><small>Only fix the highlighted field — all other data is preserved.</small>',
    '<i class="fa-solid fa-circle-exclamation" style="margin-right:6px"></i> Something went wrong',
    { timeOut: 9000, extendedTimeOut: 3000 }
  );
});
<?php endif; ?>
</script>

</body>
</html>