<?php
include 'includes/connect.php';
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) die("Please login first.");

// ── Check if creator has set up their page ──
$pageStmt = $pdo->prepare("SELECT id FROM my_page WHERE creator_id = ? LIMIT 1");
$pageStmt->execute([$creator_id]);
$has_page = (bool)$pageStmt->fetchColumn();

$success    = $error     = "";
$event_id   = null;
$event_title = "";
$ticket_url = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$has_page) {
        $error = "You must create your creator page before you can create events.";
    } else {
        $title       = trim($_POST['title'] ?? '');
        $venue       = trim($_POST['venue'] ?? '');
        $start_date  = $_POST['start_date'] ?? '';
        $start_time  = $_POST['start_time'] ?? '';
        $end_date    = $_POST['end_date'] ?? '';
        $end_time    = $_POST['end_time'] ?? '';
        $category    = $_POST['category'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $deadline    = $_POST['deadline'] ?? null;

        $banner_name = null;
        if (!empty($_FILES['banner']['name'])) {
            $target_dir = "uploads/banners/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $banner_name = time() . "_" . basename($_FILES['banner']['name']);
            move_uploaded_file($_FILES['banner']['tmp_name'], $target_dir . $banner_name);
        }

        try {
            $pdo->beginTransaction();

            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE creator_id = ? AND title = ?");
            $checkStmt->execute([$creator_id, $title]);
            if ($checkStmt->fetchColumn() > 0) {
                throw new Exception("An event with this title already exists. Please edit the existing one instead.");
            }

            $stmt = $pdo->prepare("INSERT INTO tickets
                (creator_id, title, venue, start_date, start_time, end_date, end_time, category, banner, description, deadline)
                VALUES (:creator_id, :title, :venue, :start_date, :start_time, :end_date, :end_time, :category, :banner, :description, :deadline)");
            $stmt->execute([
                ':creator_id'  => $creator_id,
                ':title'       => $title,
                ':venue'       => $venue,
                ':start_date'  => $start_date,
                ':start_time'  => $start_time,
                ':end_date'    => $end_date,
                ':end_time'    => $end_time,
                ':category'    => $category,
                ':banner'      => $banner_name,
                ':description' => $description,
                ':deadline'    => $deadline
            ]);
            $event_id = $pdo->lastInsertId();

            $ticket_types  = $_POST['ticket_type'] ?? [];
            $ticket_prices = $_POST['ticket_price'] ?? [];
            $ticket_qty    = $_POST['ticket_qty'] ?? [];
            $is_free_flags = $_POST['is_free'] ?? [];

            foreach ($ticket_types as $i => $type_name) {
                $type_name = trim($type_name);
                if (empty($type_name)) continue;
                $is_free = isset($is_free_flags[$i]) && $is_free_flags[$i] === '1';
                $price   = $is_free ? 0 : (floatval($ticket_prices[$i] ?? 0));
                $qty     = !empty($ticket_qty[$i]) ? (int)$ticket_qty[$i] : 0;

                $stmt = $pdo->prepare("INSERT INTO event_ticket_types
                    (event_id, type_name, price, quantity_available, is_free)
                    VALUES (:event_id, :type_name, :price, :qty, :is_free)");
                $stmt->execute([
                    ':event_id'  => $event_id,
                    ':type_name' => $type_name,
                    ':price'     => $price,
                    ':qty'       => $qty,
                    ':is_free'   => $is_free ? 1 : 0
                ]);
            }

            $pdo->commit();
            $success     = "Event & tickets created successfully!";
            $event_title = $title;
            $ticket_url  = "https://keychele.co.ke/ticket/" . $event_id;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
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
    --red:      #bf1339;
    --red-d:    #96102e;
    --red-l:    #e8143f;
    --gold:     #fec411;
    --gold-d:   #d4a60a;
    --navy:     #052e64;
    --navy-l:   #0a4494;
    --bg:       #eef1f8;
    --card:     #ffffff;
    --border:   rgba(5,46,100,0.1);
    --text:     #0f1923;
    --muted:    #6b7a99;
    --success:  #00b37e;
    --warning:  #f59e0b;
    --r:        14px;
    --r-lg:     20px;
    --sh:       0 4px 24px rgba(5,46,100,0.10);
    --sh-lg:    0 12px 48px rgba(5,46,100,0.14);
    --trn:      0.22s ease;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'GlacialI', 'Trebuchet MS', sans-serif;
    background: var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
    padding-bottom: 80px;
  }

  h1,h2,h3,h4,h5,.mf { font-family: 'Montserrat', sans-serif; }

  .page { padding: 1.5rem; max-width: 900px; margin: 0 auto; }
  @media (max-width: 600px) { .page { padding: 1rem 0.9rem; } }

  /* ── HERO ── */
  .page-hero {
    position: relative; overflow: hidden;
    background: linear-gradient(120deg, var(--navy) 0%, #0a3a7a 55%, #0f4faa 100%);
    border-radius: var(--r-lg);
    padding: 2rem 2.25rem;
    margin-bottom: 1.75rem;
    box-shadow: var(--sh-lg), 0 0 0 1px rgba(255,255,255,0.05) inset;
    animation: heroIn 0.55s cubic-bezier(0.16,1,0.3,1) both;
  }
  @keyframes heroIn { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
  .page-hero::before {
    content:''; position:absolute; inset:0; pointer-events:none;
    background-image: linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),
                      linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);
    background-size: 34px 34px;
  }
  .page-hero-orb1 { position:absolute;border-radius:50%;pointer-events:none;width:280px;height:280px;top:-100px;right:-60px;background:radial-gradient(circle,rgba(254,196,17,0.16) 0%,transparent 65%); }
  .page-hero-orb2 { position:absolute;border-radius:50%;pointer-events:none;width:180px;height:180px;bottom:-70px;left:25%;background:radial-gradient(circle,rgba(191,19,57,0.18) 0%,transparent 65%); }
  .page-hero-inner { position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:1rem; }
  .page-hero-tag { display:inline-flex;align-items:center;gap:6px;background:rgba(254,196,17,0.14);border:1px solid rgba(254,196,17,0.28);border-radius:100px;padding:4px 12px;font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;letter-spacing:0.09em;text-transform:uppercase;color:var(--gold);margin-bottom:0.7rem; }
  .page-hero-title { font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(1.4rem,3vw,2rem);color:white;letter-spacing:-0.04em;line-height:1.15;margin-bottom:0.4rem; }
  .page-hero-sub { font-size:0.83rem;color:rgba(255,255,255,0.55); }
  .page-hero-icon { font-size:3.5rem;color:rgba(255,255,255,0.12);flex-shrink:0; }
  @media (max-width:480px) { .page-hero-icon { display:none; } }

  /* ── STEPPER ── */
  .stepper {
    display: flex; align-items: center; gap: 0;
    background: #fff; border: 1px solid var(--border);
    border-radius: var(--r-lg); padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--sh);
    overflow-x: auto;
  }
  .step-item { display:flex;align-items:center;gap:0;flex-shrink:0; }
  .step-pill {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 14px; border-radius: 100px;
    font-family: 'Montserrat', sans-serif; font-size: 0.75rem; font-weight: 700;
    transition: all 0.3s;
    cursor: default;
  }
  .step-pill .sp-num {
    width: 22px; height: 22px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.7rem; font-weight: 800; flex-shrink: 0;
  }
  .step-pill.active   { background: var(--navy); color: #fff; }
  .step-pill.active .sp-num { background: rgba(255,255,255,0.2); color: #fff; }
  .step-pill.done     { background: rgba(0,179,126,0.12); color: var(--success); }
  .step-pill.done .sp-num { background: var(--success); color: #fff; }
  .step-pill.pending  { background: #f4f6fb; color: var(--muted); }
  .step-pill.pending .sp-num { background: #e2e8f0; color: var(--muted); }
  .step-connector { width: 28px; height: 2px; background: #e2e8f0; margin: 0 4px; flex-shrink: 0; }
  .step-connector.done-line { background: var(--success); }

  /* ── STEP PANELS ── */
  .step-panel { display: none; animation: panelIn 0.38s cubic-bezier(0.16,1,0.3,1) both; }
  .step-panel.active { display: block; }
  @keyframes panelIn { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }

  /* ── STEP NAV ── */
  .step-nav {
    display: flex; gap: 10px; justify-content: space-between;
    margin-top: 1.25rem;
  }
  .btn-prev, .btn-next, .btn-submit {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 24px; border-radius: var(--r);
    font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 0.85rem;
    letter-spacing: 0.02em; cursor: pointer; border: none;
    transition: transform 0.2s, box-shadow 0.2s, background 0.2s;
  }
  .btn-prev { background: #f0f4ff; color: var(--navy); border: 1.5px solid var(--border); }
  .btn-prev:hover { background: #e2eaff; }
  .btn-next { background: linear-gradient(135deg, var(--navy), var(--navy-l)); color: #fff; box-shadow: 0 4px 16px rgba(5,46,100,0.3); }
  .btn-next:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(5,46,100,0.4); }
  .btn-submit { background: linear-gradient(135deg, var(--red), var(--red-l)); color: #fff; box-shadow: 0 4px 16px rgba(191,19,57,0.35); }
  .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(191,19,57,0.5); }
  .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

  /* ── NO-PAGE GATE ── */
  .no-page-gate { background:var(--card);border-radius:var(--r-lg);border:1.5px solid rgba(254,196,17,0.3);box-shadow:var(--sh-lg);overflow:hidden;animation:heroIn 0.5s cubic-bezier(0.16,1,0.3,1) 0.1s both; }
  .npg-top { background:linear-gradient(120deg,var(--navy) 0%,#0a3a7a 100%);padding:2.5rem 2rem;text-align:center;position:relative;overflow:hidden; }
  .npg-top::before { content:'';position:absolute;inset:0;pointer-events:none;background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);background-size:30px 30px; }
  .npg-top-orb { position:absolute;border-radius:50%;pointer-events:none;width:260px;height:260px;top:-100px;right:-60px;background:radial-gradient(circle,rgba(254,196,17,0.14) 0%,transparent 65%); }
  .npg-icon-wrap { position:relative;z-index:1;width:80px;height:80px;border-radius:22px;background:linear-gradient(135deg,rgba(254,196,17,0.2),rgba(254,196,17,0.06));border:1.5px solid rgba(254,196,17,0.3);display:flex;align-items:center;justify-content:center;margin:0 auto 1.1rem;box-shadow:0 8px 28px rgba(254,196,17,0.15); }
  .npg-icon-wrap i { font-size:2rem;color:var(--gold); }
  .npg-title { position:relative;z-index:1;font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(1.2rem,2.5vw,1.6rem);color:white;letter-spacing:-0.03em;margin-bottom:0.5rem; }
  .npg-subtitle { position:relative;z-index:1;font-size:0.85rem;color:rgba(255,255,255,0.5);max-width:380px;margin:0 auto;line-height:1.6; }
  .npg-body { padding:2rem; }
  .npg-steps { display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem; }
  @media (max-width:600px) { .npg-steps { grid-template-columns:1fr; } }
  .npg-step { background:#fafbfd;border:1px solid var(--border);border-radius:var(--r);padding:1.1rem;text-align:center; }
  .npg-step-num { width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--navy),var(--navy-l));color:white;font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.85rem;display:flex;align-items:center;justify-content:center;margin:0 auto 0.75rem; }
  .npg-step-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;color:var(--text);margin-bottom:0.3rem; }
  .npg-step-text { font-size:0.73rem;color:var(--muted);line-height:1.5; }
  .npg-cta-wrap { display:flex;flex-direction:column;align-items:center;gap:0.9rem; }
  .npg-cta-btn { display:inline-flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--navy),var(--navy-l));color:white;text-decoration:none;font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.92rem;letter-spacing:0.02em;padding:14px 32px;border-radius:var(--r);box-shadow:0 6px 24px rgba(5,46,100,0.35);transition:transform 0.2s,box-shadow 0.2s; }
  .npg-cta-btn:hover { transform:translateY(-2px);box-shadow:0 10px 32px rgba(5,46,100,0.45); }
  .npg-cta-btn .btn-ico { width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:center;font-size:0.85rem; }
  .npg-note { font-size:0.75rem;color:var(--muted);display:flex;align-items:center;gap:5px; }

  /* ── SEC LABEL ── */
  .sec-label { font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);display:flex;align-items:center;gap:8px;margin-bottom:1rem; }
  .sec-label::before { content:'';width:3px;height:14px;border-radius:3px;background:linear-gradient(180deg,var(--red),var(--gold));flex-shrink:0; }

  /* ── CARD ── */
  .card { background:var(--card);border-radius:var(--r-lg);border:1px solid var(--border);box-shadow:var(--sh);overflow:hidden;margin-bottom:1.25rem;animation:cardIn 0.5s cubic-bezier(0.16,1,0.3,1) both; }
  @keyframes cardIn { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
  .card-head { padding:1.25rem 1.5rem 1rem;border-bottom:1px solid var(--border);background:#fafbfd;display:flex;align-items:center;gap:10px; }
  .card-head-ico { width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:0.88rem;flex-shrink:0; }
  .card-head-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.92rem;color:var(--text); }
  .card-head-sub { font-size:0.74rem;color:var(--muted);margin-top:2px; }
  .card-body { padding:1.5rem; }

  /* ── FORM ── */
  .field { margin-bottom:1.15rem; }
  .field label, .field-label { display:block;font-family:'Montserrat',sans-serif;font-size:0.75rem;font-weight:700;color:#374151;margin-bottom:0.45rem;letter-spacing:0.01em; }
  .field-hint { font-size:0.7rem;color:var(--muted);margin-top:5px; }
  .fi { position:relative; }
  .fi-icon { position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:0.82rem;color:#9ca3af;pointer-events:none;z-index:1; }
  input[type=text],input[type=date],input[type=time],input[type=number],input[type=email],select,textarea {
    width:100%;font-family:'GlacialI','Trebuchet MS',sans-serif;font-size:0.88rem;color:var(--text);
    background:#fafafa;border:1.5px solid rgba(5,46,100,0.14);border-radius:10px;
    padding:11px 14px 11px 40px;outline:none;
    transition:border-color var(--trn),box-shadow var(--trn),background var(--trn);
    appearance:none;-webkit-appearance:none;
  }
  input[type=text]:focus,input[type=date]:focus,input[type=time]:focus,input[type=number]:focus,select:focus,textarea:focus { border-color:var(--navy);background:#fff;box-shadow:0 0 0 3px rgba(5,46,100,0.08); }
  .no-ico input,.no-ico select,.no-ico textarea { padding-left:14px; }
  textarea { resize:vertical;min-height:100px;padding-left:14px !important; }
  select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b7a99' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-size:16px;padding-right:36px; }
  .file-drop { border:2px dashed rgba(5,46,100,0.18);border-radius:10px;padding:1.25rem;text-align:center;cursor:pointer;transition:border-color 0.2s,background 0.2s;background:#fafafa;position:relative; }
  .file-drop:hover { border-color:var(--navy);background:#f0f4ff; }
  .file-drop input[type=file] { position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer;border:none;padding:0; }
  .file-drop-icon { font-size:1.5rem;color:var(--navy);opacity:0.5;margin-bottom:6px; }
  .file-drop-text { font-family:'Montserrat',sans-serif;font-size:0.78rem;font-weight:600;color:var(--muted); }
  .file-drop-sub { font-size:0.68rem;color:var(--muted);margin-top:3px; }
  .grid2 { display:grid;grid-template-columns:1fr 1fr;gap:1rem; }
  @media (max-width:560px) { .grid2 { grid-template-columns:1fr; } }

  /* ── FEE CALCULATOR ── */
  .calc-card { background:linear-gradient(135deg,var(--navy) 0%,#0a3a7a 100%);border-radius:var(--r-lg);border:1px solid rgba(255,255,255,0.08);box-shadow:var(--sh-lg);overflow:hidden;margin-bottom:1.25rem; }
  .calc-head { padding:1.25rem 1.5rem 1rem;border-bottom:1px solid rgba(255,255,255,0.08);display:flex;align-items:center;gap:10px; }
  .calc-head-ico { width:38px;height:38px;border-radius:9px;background:rgba(254,196,17,0.15);border:1px solid rgba(254,196,17,0.25);display:flex;align-items:center;justify-content:center;font-size:0.9rem;color:var(--gold);flex-shrink:0; }
  .calc-head-title { font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.92rem;color:white; }
  .calc-head-sub { font-size:0.72rem;color:rgba(255,255,255,0.45);margin-top:2px; }
  .calc-body { padding:1.5rem; }
  .calc-info { background:rgba(254,196,17,0.1);border:1px solid rgba(254,196,17,0.22);border-radius:10px;padding:1rem 1.1rem;margin-bottom:1.25rem; }
  .calc-info-title { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.8rem;color:var(--gold);margin-bottom:0.5rem;display:flex;align-items:center;gap:6px; }
  .calc-info-text { font-size:0.78rem;color:rgba(255,255,255,0.65);line-height:1.65; }
  .calc-info-text strong { color:var(--gold);font-family:'Montserrat',sans-serif; }
  .calc-input-wrap { background:rgba(255,255,255,0.06);border:1.5px solid rgba(255,255,255,0.12);border-radius:10px;padding:14px;margin-bottom:1.1rem; }
  .calc-input-label { font-family:'Montserrat',sans-serif;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:rgba(255,255,255,0.45);margin-bottom:8px;display:block; }
  .calc-input-row { position:relative; }
  .calc-prefix { position:absolute;left:12px;top:50%;transform:translateY(-50%);font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.82rem;color:rgba(255,255,255,0.4);pointer-events:none; }
  #calcPrice { width:100%;background:rgba(255,255,255,0.08);border:1.5px solid rgba(255,255,255,0.15);border-radius:9px;padding:12px 14px 12px 44px;color:white;font-family:'Montserrat',sans-serif;font-weight:700;font-size:1.2rem;outline:none;transition:border-color 0.2s,box-shadow 0.2s;appearance:none; }
  #calcPrice:focus { border-color:var(--gold);box-shadow:0 0 0 3px rgba(254,196,17,0.12); }
  #calcPrice::placeholder { color:rgba(255,255,255,0.25);font-size:1rem; }
  .calc-results { display:grid;grid-template-columns:1fr 1fr;gap:0.8rem; }
  @media (max-width:480px) { .calc-results { grid-template-columns:1fr; } }
  .calc-res-box { background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:14px 16px;transition:background 0.2s; }
  .calc-res-box.highlight { background:rgba(0,179,126,0.15);border-color:rgba(0,179,126,0.3); }
  .calc-res-box.danger    { background:rgba(191,19,57,0.15);border-color:rgba(191,19,57,0.28); }
  .crb-label { font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.4);margin-bottom:5px;display:flex;align-items:center;gap:5px; }
  .crb-label i { font-size:0.65rem; }
  .crb-val { font-family:'Montserrat',sans-serif;font-weight:800;font-size:1.2rem;letter-spacing:-0.03em;color:white;line-height:1; }
  .calc-res-box.highlight .crb-val { color:#2ecc9a; }
  .calc-res-box.danger .crb-val    { color:#ff8fa3; }
  .crb-sub { font-size:0.67rem;color:rgba(255,255,255,0.35);margin-top:4px; }
  .calc-res-full { grid-column:1/-1;background:rgba(254,196,17,0.12);border:1px solid rgba(254,196,17,0.25);border-radius:10px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:1rem; }
  .crf-left .crb-label { color:rgba(255,255,255,0.5); }
  .crf-left .crb-val { color:var(--gold);font-size:1.4rem; }
  .crf-icon { width:46px;height:46px;border-radius:12px;background:rgba(254,196,17,0.15);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:var(--gold);flex-shrink:0; }
  .calc-breakdown { margin-top:1rem;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:10px;overflow:hidden; }
  .cbd-row { display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,0.06);font-size:0.8rem; }
  .cbd-row:last-child { border-bottom:none; }
  .cbd-row-label { display:flex;align-items:center;gap:7px;color:rgba(255,255,255,0.55);font-family:'GlacialI',sans-serif; }
  .cbd-row-label .dot { width:6px;height:6px;border-radius:50%;flex-shrink:0; }
  .cbd-row-val { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.82rem;color:white; }
  .cbd-row-val.green { color:#2ecc9a; }

  /* ── TICKET TYPES ── */
  .tt-block { background:#fafbfd;border:1.5px solid var(--border);border-radius:var(--r);padding:1.25rem;position:relative;margin-bottom:0.85rem;transition:border-color 0.2s,box-shadow 0.2s; }
  .tt-block:hover { border-color:rgba(5,46,100,0.2);box-shadow:0 2px 12px rgba(5,46,100,0.07); }
  .tt-block-top { display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem; }
  .tt-name-input { font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.95rem;color:var(--text);background:transparent;border:none;border-bottom:2px solid var(--border);outline:none;flex:1;padding:4px 0;transition:border-color 0.2s; }
  .tt-name-input:focus { border-color:var(--navy); }
  .tt-free-label { display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap;user-select:none; }
  .tt-free-cb { width:18px;height:18px;accent-color:var(--success);cursor:pointer;flex-shrink:0; }
  .tt-free-text { font-family:'Montserrat',sans-serif;font-size:0.75rem;font-weight:700;color:var(--success); }
  .tt-remove-btn { width:30px;height:30px;border-radius:7px;background:rgba(191,19,57,0.08);border:1px solid rgba(191,19,57,0.15);display:flex;align-items:center;justify-content:center;color:var(--red);font-size:0.75rem;cursor:pointer;flex-shrink:0;transition:background 0.18s,color 0.18s; }
  .tt-remove-btn:hover { background:rgba(191,19,57,0.18); }
  .tt-remove-btn.hidden { display:none; }
  .tt-number-input { width:100%;padding:10px 14px !important;border-radius:10px !important; }
  .add-tt-btn { display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;background:rgba(5,46,100,0.06);border:1.5px dashed rgba(5,46,100,0.2);color:var(--navy);font-family:'Montserrat',sans-serif;font-size:0.8rem;font-weight:700;cursor:pointer;width:100%;justify-content:center;transition:background 0.18s,border-color 0.18s;margin-bottom:1.25rem; }
  .add-tt-btn:hover { background:rgba(5,46,100,0.1);border-color:var(--navy); }

  /* ── SUCCESS SCREEN ── */
  .success-screen {
    animation: heroIn 0.6s cubic-bezier(0.16,1,0.3,1) both;
  }

  .success-burst {
    background: linear-gradient(135deg, #052e64 0%, #0a4494 50%, #0f5bc4 100%);
    border-radius: var(--r-lg);
    padding: 3rem 2rem 2.5rem;
    text-align: center;
    position: relative; overflow: hidden;
    margin-bottom: 1.25rem;
    box-shadow: var(--sh-lg);
  }
  .success-burst::before {
    content:''; position:absolute;inset:0;pointer-events:none;
    background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);
    background-size:30px 30px;
  }
  .sb-orb1 { position:absolute;border-radius:50%;pointer-events:none;width:320px;height:320px;top:-130px;right:-80px;background:radial-gradient(circle,rgba(254,196,17,0.14) 0%,transparent 65%); }
  .sb-orb2 { position:absolute;border-radius:50%;pointer-events:none;width:200px;height:200px;bottom:-80px;left:10%;background:radial-gradient(circle,rgba(0,179,126,0.2) 0%,transparent 65%); }

  .sb-check {
    position: relative; z-index: 1;
    width: 88px; height: 88px; border-radius: 50%;
    background: linear-gradient(135deg, var(--success), #00d68f);
    margin: 0 auto 1.25rem;
    display: flex; align-items: center; justify-content: center;
    font-size: 2.2rem; color: #fff;
    box-shadow: 0 0 0 12px rgba(0,179,126,0.15), 0 12px 40px rgba(0,179,126,0.4);
    animation: checkPop 0.5s cubic-bezier(0.34,1.5,0.64,1) 0.2s both;
  }
  @keyframes checkPop {
    from { transform: scale(0) rotate(-20deg); opacity: 0; }
    to   { transform: scale(1) rotate(0deg);   opacity: 1; }
  }

  .sb-title {
    position: relative; z-index: 1;
    font-family: 'Montserrat', sans-serif;
    font-weight: 900; font-size: clamp(1.5rem, 4vw, 2.2rem);
    color: #fff; letter-spacing: -0.04em; margin-bottom: 0.4rem;
  }
  .sb-sub {
    position: relative; z-index: 1;
    font-size: 0.88rem; color: rgba(255,255,255,0.55);
    max-width: 360px; margin: 0 auto;
  }

  /* Confetti dots decoration */
  .sb-dots {
    position: absolute; inset: 0; z-index: 0; pointer-events: none; overflow: hidden;
  }
  .sb-dot {
    position: absolute; border-radius: 50%;
    animation: dotFloat linear infinite;
  }
  @keyframes dotFloat {
    0%   { transform: translateY(100%) rotate(0deg);   opacity: 0; }
    10%  { opacity: 1; }
    90%  { opacity: 1; }
    100% { transform: translateY(-120%) rotate(360deg); opacity: 0; }
  }

  /* Share card */
  .share-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    box-shadow: var(--sh);
    overflow: hidden;
    margin-bottom: 1.25rem;
  }
  .share-card-head {
    padding: 1.1rem 1.5rem;
    background: linear-gradient(90deg, rgba(5,46,100,0.04), rgba(5,46,100,0.01));
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
  }
  .share-card-ico {
    width: 38px; height: 38px; border-radius: 10px;
    background: linear-gradient(135deg, var(--navy), var(--navy-l));
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem; color: #fff; flex-shrink: 0;
  }
  .share-card-title { font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 0.92rem; color: var(--text); }
  .share-card-sub   { font-size: 0.73rem; color: var(--muted); margin-top: 1px; }

  .share-card-body { padding: 1.5rem; }

  /* Event info pill */
  .event-info-pill {
    display: flex; align-items: center; gap: 10px;
    background: #f8faff; border: 1px solid var(--border);
    border-radius: 12px; padding: 12px 16px;
    margin-bottom: 1.25rem;
  }
  .eip-ico {
    width: 40px; height: 40px; border-radius: 10px;
    background: linear-gradient(135deg, rgba(5,46,100,0.1), rgba(5,46,100,0.05));
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; color: var(--navy); flex-shrink: 0;
  }
  .eip-title { font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.88rem; color: var(--text); }
  .eip-sub   { font-size: 0.73rem; color: var(--muted); margin-top: 2px; }

  /* URL copy row */
  .url-copy-wrap {
    display: flex; align-items: center; gap: 10px;
    background: #f4f7ff;
    border: 2px solid rgba(5,46,100,0.14);
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 1.25rem;
    transition: border-color 0.2s, box-shadow 0.2s;
  }
  .url-copy-wrap:focus-within { border-color: var(--navy); box-shadow: 0 0 0 3px rgba(5,46,100,0.08); }
  .url-link-icon {
    width: 36px; height: 36px; border-radius: 9px;
    background: linear-gradient(135deg, var(--navy), var(--navy-l));
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; color: #fff; flex-shrink: 0;
  }
  .url-text {
    flex: 1; font-size: 0.82rem; color: var(--navy);
    font-family: 'Montserrat', sans-serif; font-weight: 600;
    word-break: break-all; line-height: 1.4;
    user-select: all;
  }
  .copy-link-btn {
    flex-shrink: 0;
    display: flex; align-items: center; gap: 7px;
    background: linear-gradient(135deg, var(--navy), var(--navy-l));
    color: #fff; border: none; border-radius: 10px;
    padding: 10px 20px;
    font-family: 'Montserrat', sans-serif; font-weight: 800; font-size: 0.8rem;
    cursor: pointer; white-space: nowrap;
    transition: transform 0.18s, box-shadow 0.18s, background 0.18s;
    box-shadow: 0 4px 14px rgba(5,46,100,0.3);
  }
  .copy-link-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(5,46,100,0.4); }
  .copy-link-btn.copied { background: linear-gradient(135deg, var(--success), #00d68f); box-shadow: 0 4px 14px rgba(0,179,126,0.4); }

  /* Social share row */
  .social-label { font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);margin-bottom:0.75rem; }
  .social-row { display:flex;gap:10px;flex-wrap:wrap; }
  .soc-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 18px; border-radius: 10px;
    font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.8rem;
    cursor: pointer; border: none; text-decoration: none;
    transition: transform 0.15s, box-shadow 0.15s;
  }
  .soc-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.15); }
  .soc-wa    { background: #25d366; color: #fff; }
  .soc-fb    { background: #1877f2; color: #fff; }
  .soc-tw    { background: #000;    color: #fff; }
  .soc-email { background: #f0f4ff; color: var(--navy); border: 1.5px solid var(--border); }

  /* Action links */
  .post-actions {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
    margin-top: 1.25rem;
  }
  @media (max-width: 480px) { .post-actions { grid-template-columns: 1fr; } }
  .pa-btn {
    display: flex; align-items: center; gap: 9px;
    padding: 13px 16px; border-radius: var(--r);
    font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.83rem;
    text-decoration: none; transition: transform 0.18s, box-shadow 0.18s;
  }
  .pa-btn:hover { transform: translateY(-2px); }
  .pa-btn-outline { background: #fff; color: var(--navy); border: 1.5px solid var(--border); }
  .pa-btn-outline:hover { border-color: var(--navy); box-shadow: 0 4px 14px rgba(5,46,100,0.1); }
  .pa-btn-solid { background: linear-gradient(135deg, var(--red), var(--red-l)); color: #fff; box-shadow: 0 4px 16px rgba(191,19,57,0.3); }
  .pa-btn-solid:hover { box-shadow: 0 8px 24px rgba(191,19,57,0.45); }
  .pa-icon { width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;font-size:0.85rem;flex-shrink:0; }
  .pa-btn-outline .pa-icon { background: rgba(5,46,100,0.07); color: var(--navy); }

  /* ── TOASTR ── */
  #toast-container > .toast { border-radius:11px !important;box-shadow:0 8px 32px rgba(0,0,0,0.14) !important;font-family:'GlacialI',sans-serif !important;font-size:0.875rem !important;padding:14px 18px 14px 54px !important; }
  #toast-container > .toast-success { background-color:#064E3B !important;border-left:4px solid #10B981 !important; }
  #toast-container > .toast-error   { background-color:#7F1D1D !important;border-left:4px solid #EF4444 !important; }
  #toast-container > .toast-info    { background-color:var(--navy) !important;border-left:4px solid var(--gold) !important; }

  @keyframes spin { to { transform: rotate(360deg); } }
  .spinner { animation: spin 0.8s linear infinite; }

  /* ── TOAST (custom) ── */
  .c-toast {
    position: fixed; bottom: 22px; right: 22px; z-index: 9999;
    display: flex; align-items: center; gap: 9px;
    padding: 13px 18px; border-radius: 12px;
    font-size: 0.85rem; font-weight: 600;
    box-shadow: 0 8px 28px rgba(0,0,0,.12);
    transform: translateY(14px); opacity: 0;
    transition: all .28s cubic-bezier(.34,1.5,.64,1);
    pointer-events: none; border: 1px solid;
    font-family: 'Montserrat', sans-serif;
  }
  .c-toast.show { transform: translateY(0); opacity: 1; }
  .c-toast-s { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
  </style>
</head>
<body>

<!-- Custom toast -->
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
        <div class="page-hero-tag">
          <i class="fa-solid fa-ticket"></i> Ticketing
        </div>
        <h1 class="page-hero-title">Create Event Ticket</h1>
        <p class="page-hero-sub">Free &amp; paid tickets supported — M-Pesa checkout ready</p>
      </div>
      <div class="page-hero-icon">
        <i class="fa-solid fa-calendar-plus"></i>
      </div>
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
        <div class="npg-note"><i class="fa-solid fa-circle-info" style="color:var(--gold)"></i> It only takes a minute — you'll be redirected back here after setup</div>
      </div>
    </div>
  </div>

  <?php elseif ($success && $event_id): ?>
  <!-- ══ SUCCESS + SHARE SCREEN ══ -->
  <div class="success-screen">

    <!-- Burst header -->
    <div class="success-burst">
      <div class="sb-orb1"></div>
      <div class="sb-orb2"></div>
      <!-- Floating dots -->
      <div class="sb-dots" id="sbDots"></div>
      <div class="sb-check"><i class="fa-solid fa-check"></i></div>
      <div class="sb-title">Event Created!</div>
      <p class="sb-sub">Your event is live and ready to accept ticket sales. Share the link with your fans now.</p>
    </div>

    <!-- Share card -->
    <div class="share-card">
      <div class="share-card-head">
        <div class="share-card-ico"><i class="fa-solid fa-share-nodes"></i></div>
        <div>
          <div class="share-card-title">Share Your Ticket Link</div>
          <div class="share-card-sub">Copy and send this link to your fans so they can buy tickets</div>
        </div>
      </div>
      <div class="share-card-body">

        <!-- Event name pill -->
        <div class="event-info-pill">
          <div class="eip-ico"><i class="fa-solid fa-calendar-star"></i></div>
          <div>
            <div class="eip-title"><?= htmlspecialchars($event_title) ?></div>
            <div class="eip-sub">Ticket ID #<?= $event_id ?> &nbsp;·&nbsp; Created just now</div>
          </div>
        </div>

        <!-- URL row -->
        <div class="url-copy-wrap">
          <div class="url-link-icon"><i class="fa-solid fa-link"></i></div>
          <span class="url-text" id="ticketUrl"><?= htmlspecialchars($ticket_url) ?></span>
          <button class="copy-link-btn" id="copyBtn" onclick="copyTicketLink()">
            <i class="fa-solid fa-copy"></i> Copy Link
          </button>
        </div>

        <!-- Social share -->
        <div class="social-label">Share directly via</div>
        <div class="social-row">
          <?php
            $enc_url   = urlencode($ticket_url);
            $enc_text  = urlencode("Get your tickets for {$event_title}! ");
            $enc_title = urlencode("Get your tickets for {$event_title}!");
          ?>
          <a href="https://wa.me/?text=<?= $enc_text . $enc_url ?>"
             target="_blank" rel="noopener" class="soc-btn soc-wa">
            <i class="fa-brands fa-whatsapp"></i> WhatsApp
          </a>
          <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $enc_url ?>"
             target="_blank" rel="noopener" class="soc-btn soc-fb">
            <i class="fa-brands fa-facebook-f"></i> Facebook
          </a>
          <a href="https://twitter.com/intent/tweet?text=<?= $enc_title ?>&url=<?= $enc_url ?>"
             target="_blank" rel="noopener" class="soc-btn soc-tw">
            <i class="fa-brands fa-x-twitter"></i> X
          </a>
          <a href="mailto:?subject=<?= urlencode("Get tickets for {$event_title}!") ?>&body=<?= urlencode("Check out and buy tickets here: {$ticket_url}") ?>"
             class="soc-btn soc-email">
            <i class="fa-solid fa-envelope"></i> Email
          </a>
        </div>

        <!-- Actions -->
        <div class="post-actions">
          <a href="?page=my_tickets" class="pa-btn pa-btn-outline">
            <div class="pa-icon"><i class="fa-solid fa-list-check"></i></div>
            <div>
              <div style="font-size:0.82rem">My Events</div>
              <div style="font-size:0.68rem;color:var(--muted);font-family:'GlacialI',sans-serif;font-weight:400">View all your events</div>
            </div>
          </a>
          <a href="?page=create_ticket" class="pa-btn pa-btn-solid">
            <div class="pa-icon"><i class="fa-solid fa-plus"></i></div>
            <div>
              <div style="font-size:0.82rem">Create Another</div>
              <div style="font-size:0.68rem;color:rgba(255,255,255,0.65);font-family:'GlacialI',sans-serif;font-weight:400">New event ticket</div>
            </div>
          </a>
        </div>

      </div>
    </div>

  </div>

  <?php else: ?>
  <!-- ══ FORM WITH STEPS ══ -->

  <!-- Stepper -->
  <div class="stepper" id="stepperBar">
    <div class="step-item">
      <div class="step-pill active" id="pill-1">
        <div class="sp-num">1</div>
        <span>Event Info</span>
      </div>
    </div>
    <div class="step-connector" id="conn-1"></div>
    <div class="step-item">
      <div class="step-pill pending" id="pill-2">
        <div class="sp-num">2</div>
        <span>Pricing</span>
      </div>
    </div>
    <div class="step-connector" id="conn-2"></div>
    <div class="step-item">
      <div class="step-pill pending" id="pill-3">
        <div class="sp-num">3</div>
        <span>Tickets</span>
      </div>
    </div>
  </div>

  <form method="POST" enctype="multipart/form-data" id="createForm">

    <!-- ══ STEP 1: Event Info ══ -->
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
              <input type="text" name="title" id="f_title" required placeholder="e.g. Nairobi Comedy Night 2025">
            </div>
          </div>

          <div class="field">
            <label>Venue / Location *</label>
            <div class="fi"><i class="fi-icon fa-solid fa-location-dot"></i>
              <input type="text" name="venue" id="f_venue" required placeholder="e.g. KICC, Nairobi">
            </div>
          </div>

          <div class="grid2">
            <div class="field">
              <label>Start Date *</label>
              <div class="fi"><i class="fi-icon fa-solid fa-calendar"></i>
                <input type="date" name="start_date" id="f_start_date" required>
              </div>
            </div>
            <div class="field">
              <label>Start Time *</label>
              <div class="fi"><i class="fi-icon fa-regular fa-clock"></i>
                <input type="time" name="start_time" id="f_start_time" required>
              </div>
            </div>
          </div>

          <div class="grid2">
            <div class="field">
              <label>End Date *</label>
              <div class="fi"><i class="fi-icon fa-solid fa-calendar-check"></i>
                <input type="date" name="end_date" id="f_end_date" required>
              </div>
            </div>
            <div class="field">
              <label>End Time *</label>
              <div class="fi"><i class="fi-icon fa-regular fa-clock"></i>
                <input type="time" name="end_time" id="f_end_time" required>
              </div>
            </div>
          </div>

          <div class="field">
            <label>Ticket Sales Deadline *</label>
            <div class="fi"><i class="fi-icon fa-solid fa-hourglass-half"></i>
              <input type="date" name="deadline" id="f_deadline" required>
            </div>
            <div class="field-hint">When ticket sales close — usually on or before the event day.</div>
          </div>

          <div class="field">
            <label>Event Category</label>
            <div class="fi"><i class="fi-icon fa-solid fa-tag"></i>
              <select name="category">
                <option>Concert</option>
                <option>Comedy</option>
                <option>Conference</option>
                <option>Meetup / Hangout</option>
                <option>Party / Club Event</option>
                <option>Fitness / Sports</option>
                <option>Education / Workshop</option>
                <option>Other</option>
              </select>
            </div>
          </div>

          <div class="field">
            <label>Event Banner</label>
            <div class="file-drop" id="fileDrop">
              <input type="file" name="banner" accept="image/*" id="bannerInput" onchange="handleFileChange(this)">
              <div class="file-drop-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
              <div class="file-drop-text" id="fileLabel">Click or drag to upload banner</div>
              <div class="file-drop-sub">PNG, JPG, WEBP — Recommended 1200×628px</div>
            </div>
          </div>

          <div class="field no-ico">
            <label>Event Description</label>
            <textarea name="description" placeholder="Describe your event — what to expect, dress code, special guests, etc."></textarea>
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

    <!-- ══ STEP 2: Fee Calculator ══ -->
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
              Keychele charges a <strong>3.5% platform fee</strong> on every paid ticket purchase.
              This fee is automatically deducted from the ticket price at the time of purchase.<br><br>
              <strong>Example:</strong> If you set a ticket at <strong>KSh 1,000</strong>, the buyer pays KSh 1,000.
              Keychele deducts <strong>KSh 35 (3.5%)</strong>, and <strong>KSh 965</strong> is credited directly to your wallet balance.<br><br>
              <strong>Free tickets</strong> have <strong>zero fees</strong> — completely free for both you and your audience.
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
                <div class="crb-sub">Full ticket price — no hidden charges to buyer</div>
              </div>
              <div class="crf-icon"><i class="fa-solid fa-receipt"></i></div>
            </div>
            <div class="calc-breakdown" style="grid-column:1/-1">
              <div class="cbd-row" style="background:rgba(255,255,255,0.04)">
                <span class="cbd-row-label" style="color:rgba(255,255,255,0.55);font-family:'Montserrat',sans-serif;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Projected Earnings</span>
                <span class="cbd-row-val" style="font-size:0.65rem;color:rgba(255,255,255,0.35)">After 3.5% fee</span>
              </div>
              <div class="cbd-row"><span class="cbd-row-label"><span class="dot" style="background:#fec411"></span>10 tickets sold</span><span class="cbd-row-val green" id="proj10">—</span></div>
              <div class="cbd-row"><span class="cbd-row-label"><span class="dot" style="background:#52c9a8"></span>50 tickets sold</span><span class="cbd-row-val green" id="proj50">—</span></div>
              <div class="cbd-row"><span class="cbd-row-label"><span class="dot" style="background:#2ecc9a"></span>100 tickets sold</span><span class="cbd-row-val green" id="proj100">—</span></div>
            </div>
          </div>

          <div id="calcEmpty" style="text-align:center;padding:1.5rem 1rem;color:rgba(255,255,255,0.3);font-family:'Montserrat',sans-serif;font-size:0.8rem;">
            Enter a ticket price above to see your earnings breakdown
          </div>

        </div>
      </div>

      <div class="step-nav">
        <button type="button" class="btn-prev" onclick="goStep(1)">
          <i class="fa-solid fa-arrow-left"></i> Back
        </button>
        <button type="button" class="btn-next" onclick="goStep(3)">
          Next: Set Tickets <i class="fa-solid fa-arrow-right"></i>
        </button>
      </div>
    </div>

    <!-- ══ STEP 3: Ticket Types ══ -->
    <div class="step-panel" id="step-3">
      <div class="sec-label">Ticket Types &amp; Pricing</div>

      <div class="card">
        <div class="card-head">
          <div class="card-head-ico" style="background:rgba(191,19,57,0.08);color:var(--red)"><i class="fa-solid fa-ticket"></i></div>
          <div>
            <div class="card-head-title">Define Your Ticket Tiers</div>
            <div class="card-head-sub">Create multiple tiers — Regular, VIP, Early Bird, Free, etc.</div>
          </div>
        </div>
        <div class="card-body">

          <div id="ttContainer">
            <div class="tt-block">
              <div class="tt-block-top">
                <input type="text" name="ticket_type[]" value="Regular"
                       class="tt-name-input" required placeholder="Ticket name e.g. VIP, Regular">
                <label class="tt-free-label">
                  <input type="checkbox" name="is_free[]" value="1" class="tt-free-cb is-free-cb">
                  <span class="tt-free-text">Free</span>
                </label>
                <button type="button" class="tt-remove-btn hidden" onclick="removeTicketType(this)">
                  <i class="fa-solid fa-trash-can"></i>
                </button>
              </div>
              <div class="grid2">
                <div class="field no-ico">
                  <label>Price (KES)</label>
                  <input type="number" name="ticket_price[]" min="0" step="50" value="0"
                         class="tt-number-input price-input" placeholder="e.g. 500">
                </div>
                <div class="field no-ico">
                  <label>Quantity (0 = unlimited)</label>
                  <input type="number" name="ticket_qty[]" min="0"
                         class="tt-number-input" placeholder="e.g. 300">
                </div>
              </div>
            </div>
          </div>

          <button type="button" class="add-tt-btn" onclick="addTicketType()">
            <i class="fa-solid fa-plus"></i> Add Another Ticket Type
          </button>

          <div class="step-nav" style="margin-top:0">
            <button type="button" class="btn-prev" onclick="goStep(2)">
              <i class="fa-solid fa-arrow-left"></i> Back
            </button>
            <button type="submit" class="btn-submit" id="submitBtn">
              <i class="fa-solid fa-calendar-plus"></i>
              Create Event &amp; Tickets
            </button>
          </div>

        </div>
      </div>
    </div>

  </form>
  <?php endif; ?>

</div><!-- /page -->

<script>
/* ── STEP NAVIGATION ── */
var currentStep = 1;
var totalSteps  = 3;

function goStep(n) {
  if (n === 2 && currentStep === 1) {
    // Validate step 1 required fields
    var title = document.getElementById('f_title');
    var venue = document.getElementById('f_venue');
    var sd    = document.getElementById('f_start_date');
    var st    = document.getElementById('f_start_time');
    var ed    = document.getElementById('f_end_date');
    var et    = document.getElementById('f_end_time');
    var dl    = document.getElementById('f_deadline');
    var invalid = [title,venue,sd,st,ed,et,dl].filter(function(el){ return !el.value.trim(); });
    if (invalid.length) {
      invalid[0].focus();
      toastr.error('Please fill in all required fields before continuing.', '', { timeOut: 3000 });
      return;
    }
  }

  // Hide current, show new
  document.getElementById('step-' + currentStep).classList.remove('active');
  document.getElementById('step-' + n).classList.add('active');

  // Update stepper pills
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
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ── FEE CALCULATOR ── */
var FEE_RATE = 0.035;

function fmt(n) {
  return 'KSh ' + parseFloat(n).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function runCalc() {
  var price   = parseFloat(document.getElementById('calcPrice').value) || 0;
  var results = document.getElementById('calcResults');
  var empty   = document.getElementById('calcEmpty');
  if (price <= 0) { results.style.display = 'none'; empty.style.display = 'block'; return; }
  var fee = price * FEE_RATE;
  var you = price - fee;
  document.getElementById('calcFee').textContent   = fmt(fee);
  document.getElementById('calcYou').textContent   = fmt(you);
  document.getElementById('calcBuyer').textContent = fmt(price);
  document.getElementById('proj10').textContent    = fmt(you * 10);
  document.getElementById('proj50').textContent    = fmt(you * 50);
  document.getElementById('proj100').textContent   = fmt(you * 100);
  results.style.display = 'grid';
  empty.style.display   = 'none';
}

/* ── FILE UPLOAD ── */
function handleFileChange(input) {
  var label = document.getElementById('fileLabel');
  var icon  = document.querySelector('.file-drop-icon i');
  if (input.files && input.files[0]) {
    label.textContent = input.files[0].name;
    icon.className = 'fa-solid fa-image-portrait';
    document.getElementById('fileDrop').style.borderColor = 'var(--navy)';
    document.getElementById('fileDrop').style.background  = '#f0f4ff';
  }
}

/* ── TICKET TYPES ── */
var ttCount = 1;

function addTicketType() {
  var container = document.getElementById('ttContainer');
  var div = document.createElement('div');
  div.className = 'tt-block';
  div.style.animation = 'cardIn 0.3s cubic-bezier(0.16,1,0.3,1) both';
  div.innerHTML = `
    <div class="tt-block-top">
      <input type="text" name="ticket_type[]" required class="tt-name-input" placeholder="e.g. VIP, Early Bird, Student">
      <label class="tt-free-label">
        <input type="checkbox" name="is_free[]" value="1" class="tt-free-cb is-free-cb">
        <span class="tt-free-text">Free</span>
      </label>
      <button type="button" class="tt-remove-btn" onclick="removeTicketType(this)">
        <i class="fa-solid fa-trash-can"></i>
      </button>
    </div>
    <div class="grid2">
      <div class="field no-ico">
        <label>Price (KES)</label>
        <input type="number" name="ticket_price[]" min="0" step="50" value="0" class="tt-number-input price-input" placeholder="e.g. 800">
      </div>
      <div class="field no-ico">
        <label>Quantity (0 = unlimited)</label>
        <input type="number" name="ticket_qty[]" min="0" class="tt-number-input" placeholder="e.g. 100">
      </div>
    </div>
  `;
  container.appendChild(div);
  ttCount++;
  document.querySelectorAll('.tt-remove-btn').forEach(function(b){ b.classList.remove('hidden'); });
  toastr.info('New ticket type added', '', { timeOut: 1800, positionClass: 'toast-bottom-right' });
}

function removeTicketType(btn) {
  var block = btn.closest('.tt-block');
  block.style.opacity = '0'; block.style.transform = 'translateY(-8px)';
  block.style.transition = 'all 0.22s ease';
  setTimeout(function() {
    block.remove(); ttCount--;
    if (ttCount === 1) {
      var remaining = document.querySelector('.tt-remove-btn');
      if (remaining) remaining.classList.add('hidden');
    }
  }, 200);
}

document.addEventListener('change', function(e) {
  if (e.target.classList.contains('is-free-cb')) {
    var block      = e.target.closest('.tt-block');
    var priceInput = block.querySelector('.price-input');
    if (e.target.checked) {
      priceInput.value = 0; priceInput.disabled = true;
      priceInput.style.opacity = '0.5'; priceInput.style.cursor = 'not-allowed';
      priceInput.style.background = '#e8e8e8';
    } else {
      priceInput.disabled = false;
      priceInput.style.opacity = '1'; priceInput.style.cursor = '';
      priceInput.style.background = '';
    }
  }
});

/* ── FORM SUBMIT SPINNER ── */
var createForm = document.getElementById('createForm');
if (createForm) {
  createForm.addEventListener('submit', function() {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = `
      <svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
      </svg>
      Creating Event...
    `;
  });
}

/* ── COPY LINK ── */
function copyTicketLink() {
  var url = document.getElementById('ticketUrl').textContent.trim();
  var btn = document.getElementById('copyBtn');

  function onSuccess() {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    btn.classList.add('copied');
    showCToast('Link copied to clipboard!');
    setTimeout(function() {
      btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy Link';
      btn.classList.remove('copied');
    }, 2500);
  }

  function fallback() {
    try {
      var ta = document.createElement('textarea');
      ta.value = url; ta.setAttribute('readonly','');
      ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
      document.body.appendChild(ta);
      ta.focus(); ta.select(); ta.setSelectionRange(0, ta.value.length);
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      if (ok) onSuccess();
    } catch(e) {}
  }

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(onSuccess).catch(fallback);
  } else { fallback(); }
}

/* ── CUSTOM TOAST ── */
function showCToast(msg) {
  var el = document.getElementById('cToast');
  document.getElementById('cToastMsg').textContent = msg;
  el.classList.add('show');
  setTimeout(function(){ el.classList.remove('show'); }, 3000);
}

/* ── SUCCESS SCREEN: floating dots ── */
var dotsEl = document.getElementById('sbDots');
if (dotsEl) {
  var colors = ['#fec411','#00b37e','#fff','#bf1339','#48b6e3','#a78bfa'];
  for (var i = 0; i < 18; i++) {
    var d = document.createElement('div');
    d.className = 'sb-dot';
    var size = Math.random() * 8 + 4;
    d.style.cssText = [
      'width:' + size + 'px',
      'height:' + size + 'px',
      'left:' + (Math.random() * 100) + '%',
      'background:' + colors[Math.floor(Math.random() * colors.length)],
      'opacity:' + (Math.random() * 0.5 + 0.2),
      'animation-duration:' + (Math.random() * 8 + 6) + 's',
      'animation-delay:' + (Math.random() * 6) + 's'
    ].join(';');
    dotsEl.appendChild(d);
  }
}

/* ── TOASTR CONFIG ── */
toastr.options = {
  closeButton: true, progressBar: true,
  positionClass: 'toast-top-right', timeOut: 5000,
  extendedTimeOut: 1500, showMethod: 'slideDown', hideMethod: 'fadeOut'
};

<?php if ($error): ?>
toastr.error(
  '<strong><?= addslashes($error) ?></strong><br><small>Please fix the issue and try again.</small>',
  '<i class="fa-solid fa-circle-exclamation" style="margin-right:6px"></i> Something went wrong',
  { timeOut: 8000 }
);
<?php endif; ?>
</script>

</body>
</html>