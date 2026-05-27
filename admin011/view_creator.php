<?php
// view_creator.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';
include '../msanii/includes/connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($creator_id < 1) {
    header("Location: index.php?page=all_creators");
    exit();
}

/* ── Handle Change Password POST ── */
$pw_success = '';
$pw_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $new_password     = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (strlen($new_password) < 6) {
        $pw_error = 'Password must be at least 6 characters.';
    } elseif ($new_password !== $confirm_password) {
        $pw_error = 'Passwords do not match.';
    } else {
        try {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt_pw = $pdo->prepare("
                UPDATE creators
                SET password = ?, must_change_password = 1, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt_pw->execute([$hashed, $creator_id]);
            $pw_success = 'Password updated. The creator will be prompted to change it on next login.';
        } catch (PDOException $e) {
            $pw_error = 'Database error: ' . htmlspecialchars($e->getMessage());
        }
    }
}

/* ── Handle Send Ticket Link POST ── */
$tl_success = '';
$tl_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_ticket_link'])) {
    $ticket_id   = (int)($_POST['ticket_id'] ?? 0);
    $send_to     = trim($_POST['send_to'] ?? '');
    $extra_email = trim($_POST['extra_email'] ?? '');

    if ($ticket_id < 1) {
        $tl_error = 'Please select a ticket.';
    } else {
        $stmt_tk = $pdo->prepare("SELECT id, title, venue, start_date, start_time FROM tickets WHERE id = ? AND creator_id = ?");
        $stmt_tk->execute([$ticket_id, $creator_id]);
        $tkt = $stmt_tk->fetch(PDO::FETCH_ASSOC);

        if (!$tkt) {
            $tl_error = 'Ticket not found or does not belong to this creator.';
        } else {
            $stmt_cr = $pdo->prepare("SELECT full_name, email FROM creators WHERE id = ?");
            $stmt_cr->execute([$creator_id]);
            $cr = $stmt_cr->fetch(PDO::FETCH_ASSOC);

            $ticket_url  = "https://keychele.co.ke/ticket/" . $tkt['id'];
            $event_title = $tkt['title'];
            $event_date  = $tkt['start_date'] ? date('d M Y', strtotime($tkt['start_date'])) : 'TBD';
            $event_time  = $tkt['start_time'] ?: '';
            $event_venue = $tkt['venue'] ?: '';

            $recipients = [];
            if (!empty($cr['email'])) $recipients[] = ['email' => $cr['email'], 'name' => $cr['full_name']];
            if (!empty($extra_email) && filter_var($extra_email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = ['email' => $extra_email, 'name' => $cr['full_name']];
            }

            if (empty($recipients)) {
                $tl_error = 'No valid email address to send to.';
            } else {
                $sent_count = 0;
                $fail_count = 0;

                foreach ($recipients as $rcpt) {
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = 'mail.keychele.co.ke';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'notifications@keychele.co.ke';
                        $mail->Password   = 'King20241064.';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = 587;
                        $mail->SMTPOptions = ['ssl' => [
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                            'allow_self_signed' => true,
                        ]];

                        $mail->setFrom('notifications@keychele.co.ke', 'Keychele Platform');
                        $mail->addAddress($rcpt['email'], $rcpt['name']);
                        $mail->isHTML(true);
                        $mail->CharSet = 'UTF-8';
                        $mail->Subject = "Your Ticket Link — {$event_title}";

                        $mail->Body = "
                        <div style='font-family:Arial,sans-serif;max-width:580px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #E8E4DF;'>
                          <div style='background:linear-gradient(135deg,#052e64 0%,#0a4494 60%,#0f5bc4 100%);padding:40px 32px;text-align:center;'>
                            <h1 style='color:#ffffff;font-size:24px;margin:0 0 8px;'>Your Event is Live!</h1>
                            <p style='color:rgba(255,255,255,0.6);font-size:14px;margin:0;'>Share this link with your fans to start selling tickets</p>
                          </div>
                          <div style='padding:28px 32px 0;'>
                            <div style='background:#f8faff;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:24px;'>
                              <table style='width:100%;border-collapse:collapse;font-size:14px;'>
                                <tr><td style='padding:6px 0;color:#64748b;font-weight:600;width:90px;'>Event</td><td style='padding:6px 0;color:#0f1923;font-weight:700;'>" . htmlspecialchars($event_title) . "</td></tr>
                                <tr><td style='padding:6px 0;color:#64748b;font-weight:600;border-top:1px solid #e2e8f0;'>Venue</td><td style='padding:6px 0;color:#374151;border-top:1px solid #e2e8f0;'>" . htmlspecialchars($event_venue) . "</td></tr>
                                <tr><td style='padding:6px 0;color:#64748b;font-weight:600;border-top:1px solid #e2e8f0;'>Date</td><td style='padding:6px 0;color:#374151;border-top:1px solid #e2e8f0;'>{$event_date}" . ($event_time ? " at {$event_time}" : "") . "</td></tr>
                              </table>
                            </div>
                          </div>
                          <div style='padding:0 32px 24px;'>
                            <p style='font-size:13px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:10px;'>Your Ticket Link</p>
                            <div style='background:#f4f7ff;border:2px solid #c7d5f5;border-radius:12px;padding:16px 18px;margin-bottom:18px;word-break:break-all;'>
                              <a href='{$ticket_url}' style='color:#052e64;font-size:14px;font-weight:700;text-decoration:none;'>{$ticket_url}</a>
                            </div>
                            <div style='text-align:center;margin-bottom:24px;'>
                              <a href='{$ticket_url}' style='display:inline-block;background:linear-gradient(135deg,#052e64 0%,#0a4494 100%);color:#ffffff;font-size:15px;font-weight:800;text-decoration:none;padding:15px 44px;border-radius:12px;'>View Ticket Page →</a>
                            </div>
                          </div>
                          <div style='background:#f9fafb;border-top:1px solid #e5e7eb;padding:20px 32px;text-align:center;'>
                            <p style='color:#9ca3af;font-size:12px;margin:0;'>© " . date('Y') . " Keychele. All rights reserved.</p>
                          </div>
                        </div>";

                        $mail->AltBody = "Hi {$rcpt['name']},\n\nYour ticket link: {$ticket_url}\n\n© " . date('Y') . " Keychele";
                        $mail->send();
                        $sent_count++;
                    } catch (Exception $e) {
                        $fail_count++;
                        error_log("Ticket link email failed to {$rcpt['email']}: " . $mail->ErrorInfo);
                    }
                }

                if ($sent_count > 0 && $fail_count === 0) {
                    $tl_success = "Ticket link sent successfully to " . implode(', ', array_column($recipients, 'email')) . ".";
                } elseif ($sent_count > 0) {
                    $tl_success = "Sent to {$sent_count} address(es). {$fail_count} failed — check server logs.";
                } else {
                    $tl_error = "Failed to send email. Please check the mail server configuration.";
                }
            }
        }
    }
}

/* ── Handle Update Page Details POST ── */
$page_success = '';
$page_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_page'])) {
    $pg_full_name    = trim($_POST['pg_full_name']    ?? '');
    $pg_bio          = trim($_POST['pg_bio']          ?? '');
    $pg_page_link    = trim($_POST['pg_page_link']    ?? '');
    $pg_phone        = trim($_POST['pg_phone']        ?? '');
    $pg_mpesa_number = trim($_POST['pg_mpesa_number'] ?? '');
    $pg_county       = trim($_POST['pg_county']       ?? '');
    $pg_profile_pic  = trim($_POST['pg_profile_pic']  ?? '');
    $pg_banner       = trim($_POST['pg_banner']       ?? '');

    try {
        // Check if a my_page row already exists for this creator
        $stmt_chk = $pdo->prepare("SELECT id FROM my_page WHERE creator_id = ?");
        $stmt_chk->execute([$creator_id]);
        $existing_page = $stmt_chk->fetch(PDO::FETCH_ASSOC);

        if ($existing_page) {
            $stmt_pg = $pdo->prepare("
                UPDATE my_page
                SET full_name = ?, bio = ?, page_link = ?, phone = ?,
                    mpesa_number = ?, county = ?, profile_pic = ?, banner = ?,
                    updated_at = NOW()
                WHERE creator_id = ?
            ");
            $stmt_pg->execute([
                $pg_full_name, $pg_bio, $pg_page_link, $pg_phone,
                $pg_mpesa_number, $pg_county, $pg_profile_pic, $pg_banner,
                $creator_id
            ]);
        } else {
            $stmt_pg = $pdo->prepare("
                INSERT INTO my_page
                    (creator_id, full_name, bio, page_link, phone,
                     mpesa_number, county, profile_pic, banner, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt_pg->execute([
                $creator_id, $pg_full_name, $pg_bio, $pg_page_link, $pg_phone,
                $pg_mpesa_number, $pg_county, $pg_profile_pic, $pg_banner
            ]);
        }
        $page_success = 'Page details updated successfully.';
    } catch (PDOException $e) {
        $page_error = 'Database error: ' . htmlspecialchars($e->getMessage());
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, username, phone, email,
               date_created, must_change_password, updated_at
        FROM creators WHERE id = ?
    ");
    $stmt->execute([$creator_id]);
    $creator = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$creator) {
        header("Location: index.php?page=all_creators");
        exit();
    }

    /* Withdrawals */
    $stmt_w = $pdo->prepare("
        SELECT id, mpesa_number, amount, charge, total_amount,
               transaction_code, status, requested_at, processed_at,
               transaction_id, notes
        FROM withdrawal_requests
        WHERE creator_id = ?
        ORDER BY requested_at DESC
    ");
    $stmt_w->execute([$creator_id]);
    $withdrawals = $stmt_w->fetchAll(PDO::FETCH_ASSOC);

    $total_successful_withdrawals = 0;
    foreach ($withdrawals as $w) {
        $s = strtolower($w['status'] ?? '');
        if ($s === 'completed' || $s === 'successful') {
            $total_successful_withdrawals += (float)($w['total_amount'] ?? 0);
        }
    }

    /* Balance */
    $stmt_b = $pdo->prepare("
        SELECT amount, date FROM my_balance
        WHERE creator_id = ? ORDER BY date DESC LIMIT 1
    ");
    $stmt_b->execute([$creator_id]);
    $balance_row     = $stmt_b->fetch(PDO::FETCH_ASSOC);
    $current_balance = $balance_row ? (float)$balance_row['amount'] : 0;

    /* Tickets */
    $stmt_t = $pdo->prepare("
        SELECT id, title, venue, start_date, start_time, end_date, end_time,
               category, banner, description, created_at
        FROM tickets WHERE creator_id = ? ORDER BY created_at DESC
    ");
    $stmt_t->execute([$creator_id]);
    $tickets_created = $stmt_t->fetchAll(PDO::FETCH_ASSOC);

    /* Completed sales */
    $stmt_sales = $pdo->prepare("
        SELECT tp.*, t.title AS ticket_title
        FROM ticket_purchases tp
        LEFT JOIN tickets t ON tp.ticket_id = t.id
        WHERE t.creator_id = ? AND tp.status = 'completed'
        ORDER BY tp.created_at DESC
    ");
    $stmt_sales->execute([$creator_id]);
    $ticket_sales = $stmt_sales->fetchAll(PDO::FETCH_ASSOC);

    $total_revenue = array_sum(array_column($ticket_sales, 'amount_paid'));

    /* my_page details */
    $stmt_mp = $pdo->prepare("
        SELECT id, full_name, bio, profile_pic, page_link, created_at, updated_at,
               phone, mpesa_number, county, banner
        FROM my_page WHERE creator_id = ?
    ");
    $stmt_mp->execute([$creator_id]);
    $my_page = $stmt_mp->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Database error: " . htmlspecialchars($e->getMessage());
}

// Determine which tab should be active on load
$active_tab = 'overview';
if ($pw_success   || $pw_error)   $active_tab = 'change-password';
if ($tl_success   || $tl_error)   $active_tab = 'send-link';
if ($page_success || $page_error) $active_tab = 'page-details';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($creator['full_name'] ?? 'Creator') ?> • Keychele Admin</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    :root {
      --crimson:       #C8102E;
      --crimson-deep:  #9B0B22;
      --crimson-light: #FF3356;
      --ink:           #0D0D0D;
      --ink-soft:      #1A1A2E;
      --slate:         #64748B;
      --mist:          #F5F4F1;
      --white:         #FFFFFF;
      --border:        #E8E4DF;
      --success:       #059669;
      --warning:       #D97706;
      --navy:          #052e64;
      --navy-l:        #0a4494;
      --teal:          #0d9488;
      --teal-l:        #14b8a6;
      --font-d:        'Syne', sans-serif;
      --font-b:        'DM Sans', sans-serif;
      --r:             14px;
      --r-sm:          9px;
      --shadow:        0 8px 40px rgba(13,13,13,0.10), 0 2px 8px rgba(13,13,13,0.05);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { font-family: var(--font-b); background: var(--mist); color: var(--ink); -webkit-font-smoothing: antialiased; }

    /* HERO */
    .hero { background:linear-gradient(135deg,var(--ink-soft) 0%,#2a1060 45%,var(--crimson-deep) 100%);padding:3rem 0 7rem;position:relative;overflow:hidden;text-align:center; }
    .hero::before { content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='50' height='50' viewBox='0 0 50 50' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='25' cy='25' r='1' fill='%23ffffff' fill-opacity='0.04'/%3E%3C/svg%3E"); }
    .hero::after { content:'';position:absolute;bottom:-1px;left:0;right:0;height:72px;background:var(--mist);clip-path:ellipse(58% 100% at 50% 100%); }
    .hero-ring { position:absolute;top:-100px;right:-100px;width:420px;height:420px;border-radius:50%;border:1px solid rgba(255,255,255,0.05);pointer-events:none; }
    .hero-ring::after { content:'';position:absolute;inset:50px;border-radius:50%;border:1px solid rgba(200,16,46,0.2); }
    .hero-inner { position:relative;z-index:2; }
    .avatar-wrap { display:inline-block;position:relative;margin-bottom:1.1rem; }
    .avatar { width:100px;height:100px;border-radius:50%;border:3px solid rgba(255,255,255,0.18);object-fit:cover; }
    .avatar-dot { position:absolute;bottom:3px;right:3px;width:18px;height:18px;border-radius:50%;background:#4ADE80;border:3px solid var(--ink-soft); }
    .hero-name { font-family:var(--font-d);font-weight:800;font-size:clamp(1.5rem,4vw,2rem);color:white;letter-spacing:-0.03em;margin-bottom:0.25rem; }
    .hero-handle { font-size:0.88rem;color:rgba(255,255,255,0.45);margin-bottom:0.7rem; }
    .hero-meta { display:flex;justify-content:center;flex-wrap:wrap;gap:1.25rem;font-size:0.78rem;color:rgba(255,255,255,0.55); }
    .hero-meta span { display:flex;align-items:center;gap:6px; }
    .hero-meta i { color:rgba(255,255,255,0.3); }

    .wrap { max-width:1060px;margin:0 auto;padding:0 1.25rem 3rem; }

    /* STATS */
    .stats-row { display:grid;grid-template-columns:repeat(4,1fr);gap:0.85rem;margin-top:-3.2rem;margin-bottom:1.75rem;position:relative;z-index:10; }
    @media (max-width:700px) { .stats-row { grid-template-columns:1fr 1fr; } }
    .s-card { background:var(--white);border:1px solid var(--border);border-radius:var(--r);padding:1.2rem 1rem;text-align:center;box-shadow:var(--shadow);transition:transform 0.2s; }
    .s-card:hover { transform:translateY(-2px); }
    .s-icon { width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;margin:0 auto 0.65rem;font-size:0.95rem; }
    .ic-r { background:rgba(200,16,46,0.1);color:var(--crimson); }
    .ic-b { background:rgba(59,130,246,0.1);color:#3B82F6; }
    .ic-g { background:rgba(5,150,105,0.1);color:var(--success); }
    .ic-a { background:rgba(217,119,6,0.1);color:var(--warning); }
    .ic-p { background:rgba(5,46,100,0.1);color:var(--navy); }
    .ic-t { background:rgba(13,148,136,0.1);color:var(--teal); }
    .s-num { font-family:var(--font-d);font-weight:800;font-size:1.3rem;color:var(--ink);letter-spacing:-0.03em;line-height:1;margin-bottom:0.25rem; }
    .s-lbl { font-size:0.68rem;color:var(--slate);font-weight:600;text-transform:uppercase;letter-spacing:0.04em; }

    /* ACTION BAR */
    .act-bar { display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.25rem; }
    .breadcrumb { font-size:0.75rem;color:var(--slate);display:flex;align-items:center;gap:6px; }
    .breadcrumb a { color:var(--slate);text-decoration:none; }
    .breadcrumb a:hover { color:var(--crimson); }
    .breadcrumb i { font-size:0.55rem;color:#CBD5E1; }
    .btn-outline { height:38px;padding:0 1rem;border-radius:8px;font-family:var(--font-b);font-size:0.78rem;font-weight:600;cursor:pointer;border:1.5px solid var(--border);background:var(--white);color:var(--slate);display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all 0.18s; }
    .btn-outline:hover { border-color:#CBD5E1;color:var(--ink); }
    .btn-solid { height:38px;padding:0 1.15rem;border-radius:8px;font-family:var(--font-b);font-size:0.78rem;font-weight:700;cursor:pointer;border:none;background:linear-gradient(135deg,var(--crimson),var(--crimson-light));color:white;display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all 0.18s;box-shadow:0 2px 10px rgba(200,16,46,0.28); }
    .btn-solid:hover { transform:translateY(-1px);box-shadow:0 4px 16px rgba(200,16,46,0.4); }

    /* MAIN CARD */
    .main-card { background:var(--white);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden; }

    /* TABS */
    .tab-nav { display:flex;border-bottom:1px solid var(--border);padding:0 1.5rem;overflow-x:auto;scrollbar-width:none; }
    .tab-nav::-webkit-scrollbar { display:none; }
    .tab-btn { display:flex;align-items:center;gap:6px;padding:0.9rem 1rem;font-family:var(--font-b);font-size:0.79rem;font-weight:600;color:var(--slate);background:none;border:none;border-bottom:2.5px solid transparent;margin-bottom:-1px;cursor:pointer;white-space:nowrap;transition:all 0.18s; }
    .tab-btn:hover { color:var(--ink); }
    .tab-btn.active { color:var(--crimson);border-bottom-color:var(--crimson); }
    .tab-btn i { font-size:0.75rem; }

    /* TAB CONTENT */
    .tc-area { padding:1.75rem; }
    .tab-pane { display:none;animation:ti 0.22s ease both; }
    .tab-pane.active { display:block; }
    @keyframes ti { from{opacity:0;transform:translateY(7px);}to{opacity:1;transform:translateY(0);} }

    /* SECTION HEAD */
    .sh { display:flex;align-items:center;gap:10px;margin-bottom:1.35rem;padding-bottom:0.85rem;border-bottom:1px solid var(--border); }
    .sh-icon { width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.8rem;flex-shrink:0; }
    .sh h5 { font-family:var(--font-d);font-weight:700;font-size:0.95rem;color:var(--ink); }
    .sh p  { font-size:0.75rem;color:var(--slate); }

    /* INFO GRID */
    .info-grid { display:grid;grid-template-columns:1fr 1fr;gap:0.65rem; }
    @media (max-width:560px) { .info-grid { grid-template-columns:1fr; } }
    .info-item { background:var(--mist);border:1px solid var(--border);border-radius:var(--r-sm);padding:0.85rem 1rem; }
    .ii-lbl { font-size:0.67rem;font-weight:700;color:var(--slate);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.28rem; }
    .ii-val { font-size:0.88rem;font-weight:600;color:var(--ink); }

    /* BADGES */
    .badge { display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:100px;font-size:0.67rem;font-weight:700;letter-spacing:0.02em; }
    .bg-ok  { background:rgba(5,150,105,0.1);color:var(--success); }
    .bg-err { background:rgba(200,16,46,0.09);color:var(--crimson); }
    .bg-w   { background:rgba(217,119,6,0.1);color:var(--warning); }
    .bg-sl  { background:rgba(100,116,139,0.1);color:var(--slate); }
    .bg-t   { background:rgba(13,148,136,0.1);color:var(--teal); }

    /* TABLE */
    .t-wrap { border:1px solid var(--border);border-radius:var(--r-sm);overflow:hidden;overflow-x:auto; }
    table { width:100%;border-collapse:separate;border-spacing:0;font-size:0.81rem; }
    thead th { background:var(--mist);padding:0.65rem 0.85rem;font-size:0.66rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--slate);border-bottom:1px solid var(--border);white-space:nowrap; }
    tbody tr:hover td { background:#FAFAF9; }
    tbody td { padding:0.72rem 0.85rem;border-bottom:1px solid #F1EDE8;color:#374151;vertical-align:middle; }
    tbody tr:last-child td { border-bottom:none; }
    .te { text-align:right; }

    /* EMPTY */
    .empty { text-align:center;padding:3rem 1rem;color:var(--slate); }
    .empty-ic { width:52px;height:52px;border-radius:14px;background:var(--mist);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;margin:0 auto 0.9rem;font-size:1.3rem;color:#CBD5E1; }
    .empty p { font-size:0.83rem; }

    /* BALANCE HERO */
    .bal-hero { background:linear-gradient(135deg,var(--ink-soft),var(--crimson-deep));border-radius:var(--r);padding:2.5rem 1.75rem;text-align:center;position:relative;overflow:hidden; }
    .bal-hero::before { content:'';position:absolute;inset:0;background:radial-gradient(circle at 70% 30%,rgba(255,51,86,0.15),transparent 60%); }
    .bal-lbl { font-size:0.72rem;font-weight:700;color:rgba(255,255,255,0.45);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.65rem; }
    .bal-amt { font-family:var(--font-d);font-weight:800;font-size:clamp(2.2rem,6vw,3.2rem);color:white;letter-spacing:-0.04em;line-height:1;position:relative; }
    .bal-amt sup { font-size:0.38em;vertical-align:super;color:rgba(255,255,255,0.5); }
    .bal-upd { font-size:0.75rem;color:rgba(255,255,255,0.35);margin-top:0.65rem; }

    /* WITHDRAWAL SUMMARY */
    .w-sum { display:flex;align-items:center;justify-content:space-between;background:rgba(5,150,105,0.07);border:1px solid rgba(5,150,105,0.2);border-radius:var(--r-sm);padding:0.8rem 1rem;margin-bottom:1.1rem; }
    .w-sum span { font-size:0.8rem;color:var(--slate);font-weight:500; }
    .w-sum strong { font-family:var(--font-d);font-size:0.95rem;color:var(--success);font-weight:700; }

    /* ALERTS */
    .k-alert { display:flex;align-items:flex-start;gap:9px;padding:0.85rem 1rem;border-radius:var(--r-sm);font-size:0.81rem;margin-bottom:1.1rem;line-height:1.55; }
    .k-alert i { margin-top:2px;flex-shrink:0; }
    .k-ok  { background:rgba(5,150,105,0.08);border:1px solid rgba(5,150,105,0.22);color:#065F46; }
    .k-err { background:rgba(200,16,46,0.07);border:1px solid rgba(200,16,46,0.18);color:#7F1D1D; }

    /* PASSWORD FORM */
    .pw-wrap { max-width:440px; }
    .pw-info { background:rgba(200,16,46,0.05);border:1px solid rgba(200,16,46,0.15);border-radius:var(--r-sm);padding:0.8rem 1rem;font-size:0.78rem;color:#7F1D1D;line-height:1.6;margin-bottom:1.35rem; }
    .pw-field { margin-bottom:1.1rem; }
    .pw-field label { display:block;font-size:0.72rem;font-weight:700;color:#374151;margin-bottom:0.45rem;text-transform:uppercase;letter-spacing:0.04em; }
    .pw-in-wrap { position:relative; }
    .pw-in-wrap input { width:100%;height:48px;padding:0 46px 0 15px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-family:var(--font-b);font-size:0.92rem;color:var(--ink);background:var(--mist);outline:none;transition:all 0.18s; }
    .pw-in-wrap input:focus { border-color:var(--crimson);background:var(--white);box-shadow:0 0 0 3px rgba(200,16,46,0.08); }
    .pw-toggle { position:absolute;right:13px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9CA3AF;cursor:pointer;font-size:0.82rem;transition:color 0.18s;padding:0; }
    .pw-toggle:hover { color:var(--ink); }
    .str-track { height:4px;background:var(--border);border-radius:3px;overflow:hidden;margin-top:0.55rem; }
    .str-fill  { height:100%;width:0%;border-radius:3px;transition:width 0.3s,background 0.3s; }
    .str-lbl   { font-size:0.7rem;margin-top:4px;color:var(--slate); }
    .match-hint { font-size:0.73rem;margin-top:5px;min-height:1rem;font-weight:600; }
    .m-ok  { color:var(--success); }
    .m-err { color:var(--crimson); }
    .btn-pw { height:48px;padding:0 1.75rem;border-radius:var(--r-sm);background:linear-gradient(135deg,var(--crimson),var(--crimson-light));color:white;font-family:var(--font-b);font-size:0.88rem;font-weight:700;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 18px rgba(200,16,46,0.32);transition:all 0.18s;letter-spacing:0.01em; }
    .btn-pw:hover { transform:translateY(-1px);box-shadow:0 7px 24px rgba(200,16,46,0.42); }

    /* SEND TICKET LINK */
    .stl-wrap { max-width:560px; }
    .stl-info { background:linear-gradient(135deg,rgba(5,46,100,0.06),rgba(5,46,100,0.02));border:1px solid rgba(5,46,100,0.14);border-radius:var(--r-sm);padding:1rem 1.1rem;margin-bottom:1.35rem;display:flex;align-items:flex-start;gap:10px; }
    .stl-info-ico { width:34px;height:34px;border-radius:8px;flex-shrink:0;background:rgba(5,46,100,0.1);display:flex;align-items:center;justify-content:center;font-size:0.85rem;color:var(--navy); }
    .stl-info p { font-size:0.8rem;color:#374151;line-height:1.65; }
    .stl-info strong { color:var(--ink); }
    .stl-field { margin-bottom:1.1rem; }
    .stl-field label { display:block;font-size:0.72rem;font-weight:700;color:#374151;margin-bottom:0.45rem;text-transform:uppercase;letter-spacing:0.04em; }
    .stl-field select, .stl-field input[type=email] { width:100%;height:46px;padding:0 14px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-family:var(--font-b);font-size:0.88rem;color:var(--ink);background:var(--mist);outline:none;transition:all 0.18s;appearance:none;-webkit-appearance:none; }
    .stl-field select:focus, .stl-field input[type=email]:focus { border-color:var(--navy);background:var(--white);box-shadow:0 0 0 3px rgba(5,46,100,0.08); }
    .stl-field .field-hint { font-size:0.72rem;color:var(--slate);margin-top:5px; }
    .ticket-preview { background:#f8faff;border:1px solid rgba(5,46,100,0.14);border-radius:var(--r-sm);padding:0.85rem 1rem;margin-bottom:1.1rem;display:none; }
    .ticket-preview.visible { display:flex;align-items:center;gap:10px; }
    .tp-ico { width:36px;height:36px;border-radius:8px;flex-shrink:0;background:linear-gradient(135deg,var(--navy),var(--navy-l));display:flex;align-items:center;justify-content:center;font-size:0.8rem;color:white; }
    .tp-title { font-size:0.85rem;font-weight:700;color:var(--ink); }
    .tp-url   { font-size:0.72rem;color:var(--slate);margin-top:2px;word-break:break-all; }
    .btn-send-link { height:48px;padding:0 2rem;border-radius:var(--r-sm);background:linear-gradient(135deg,var(--navy),var(--navy-l));color:white;font-family:var(--font-b);font-size:0.9rem;font-weight:700;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:9px;box-shadow:0 4px 18px rgba(5,46,100,0.3);transition:all 0.18s;letter-spacing:0.01em; }
    .btn-send-link:hover  { transform:translateY(-1px);box-shadow:0 7px 24px rgba(5,46,100,0.42); }
    .btn-send-link:active { transform:translateY(0); }
    .btn-send-link:disabled { opacity:0.65;cursor:not-allowed;transform:none; }
    .sent-ticket-row { display:flex;align-items:center;justify-content:space-between;padding:0.65rem 0.85rem;border-bottom:1px solid #F1EDE8;font-size:0.8rem; }
    .sent-ticket-row:last-child { border-bottom:none; }
    .str-title { font-weight:600;color:var(--ink); }
    .str-url   { font-size:0.72rem;color:var(--slate);margin-top:2px; }
    .copy-mini { display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:6px;background:rgba(5,46,100,0.07);color:var(--navy);font-size:0.72rem;font-weight:700;cursor:pointer;white-space:nowrap;border:none;transition:background 0.18s; }
    .copy-mini:hover { background:rgba(5,46,100,0.13); }
    .copy-mini.copied { background:rgba(5,150,105,0.1);color:var(--success); }

    /* ── PAGE DETAILS STYLES ── */
    .pg-layout { display:grid;grid-template-columns:1fr 1fr;gap:1.5rem; }
    @media (max-width:680px) { .pg-layout { grid-template-columns:1fr; } }

    /* Page preview card (left side when no page) */
    .pg-empty-card {
      background: linear-gradient(135deg, rgba(13,148,136,0.06), rgba(13,148,136,0.02));
      border: 1.5px dashed rgba(13,148,136,0.3);
      border-radius: var(--r);
      padding: 2rem 1.5rem;
      text-align: center;
    }
    .pg-empty-card .empty-ic { background: rgba(13,148,136,0.08); border-color: rgba(13,148,136,0.2); color: var(--teal); }
    .pg-empty-card p { color: var(--slate); font-size: 0.82rem; line-height: 1.6; }

    /* Page profile card (shown when page exists) */
    .pg-profile-card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: var(--r);
      overflow: hidden;
      box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    }
    .pg-banner-wrap {
      position: relative;
      height: 110px;
      background: linear-gradient(135deg, var(--ink-soft), var(--teal));
      overflow: hidden;
    }
    .pg-banner-wrap img { width:100%;height:100%;object-fit:cover;display:block; }
    .pg-banner-placeholder { width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:2rem;color:rgba(255,255,255,0.2); }
    .pg-avatar-row { display:flex;align-items:flex-end;gap:1rem;padding:0 1.25rem;margin-top:-30px;margin-bottom:0.75rem; }
    .pg-avatar { width:60px;height:60px;border-radius:50%;border:3px solid var(--white);object-fit:cover;box-shadow:0 2px 10px rgba(0,0,0,0.15);background:var(--mist); }
    .pg-avatar-placeholder { width:60px;height:60px;border-radius:50%;border:3px solid var(--white);background:linear-gradient(135deg,var(--teal),var(--teal-l));display:flex;align-items:center;justify-content:center;color:white;font-size:1.2rem;font-family:var(--font-d);font-weight:800;flex-shrink:0;box-shadow:0 2px 10px rgba(0,0,0,0.15); }
    .pg-name-col { padding-bottom:4px; }
    .pg-name { font-family:var(--font-d);font-weight:800;font-size:1rem;color:var(--ink);line-height:1.2; }
    .pg-link { font-size:0.72rem;color:var(--teal);font-weight:600; }
    .pg-body { padding:0 1.25rem 1.25rem; }
    .pg-bio { font-size:0.82rem;color:#374151;line-height:1.65;margin-bottom:0.85rem;padding:0.75rem;background:var(--mist);border-radius:var(--r-sm);border:1px solid var(--border); }
    .pg-meta-grid { display:grid;grid-template-columns:1fr 1fr;gap:0.5rem; }
    .pg-meta-item { background:var(--mist);border:1px solid var(--border);border-radius:var(--r-sm);padding:0.6rem 0.8rem; }
    .pg-meta-lbl { font-size:0.62rem;font-weight:700;color:var(--slate);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px; }
    .pg-meta-val { font-size:0.8rem;font-weight:600;color:var(--ink);word-break:break-word; }
    .pg-dates { font-size:0.72rem;color:var(--slate);padding-top:0.75rem;border-top:1px solid var(--border);margin-top:0.75rem;display:flex;gap:1.25rem;flex-wrap:wrap; }

    /* Edit form */
    .pg-form-wrap { max-width:100%; }
    .pg-form-grid { display:grid;grid-template-columns:1fr 1fr;gap:1rem; }
    @media (max-width:560px) { .pg-form-grid { grid-template-columns:1fr; } }
    .pg-form-field { margin-bottom:0; }
    .pg-form-field.full { grid-column:1/-1; }
    .pg-form-field label { display:block;font-size:0.7rem;font-weight:700;color:#374151;margin-bottom:0.4rem;text-transform:uppercase;letter-spacing:0.04em; }
    .pg-form-field input[type=text],
    .pg-form-field input[type=url],
    .pg-form-field input[type=tel],
    .pg-form-field select,
    .pg-form-field textarea {
      width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:var(--r-sm);
      font-family:var(--font-b);font-size:0.88rem;color:var(--ink);background:var(--mist);
      outline:none;transition:all 0.18s;
    }
    .pg-form-field textarea { resize:vertical;min-height:90px;line-height:1.55; }
    .pg-form-field input:focus,
    .pg-form-field select:focus,
    .pg-form-field textarea:focus { border-color:var(--teal);background:var(--white);box-shadow:0 0 0 3px rgba(13,148,136,0.09); }
    .pg-form-field .field-hint { font-size:0.7rem;color:var(--slate);margin-top:4px; }

    .img-preview-row { display:flex;align-items:center;gap:0.75rem;margin-top:0.5rem; }
    .img-thumb { width:44px;height:44px;border-radius:8px;object-fit:cover;border:1px solid var(--border);background:var(--mist); display:none; }
    .img-thumb.show { display:block; }

    .btn-save-page {
      height:48px;padding:0 2rem;border-radius:var(--r-sm);
      background:linear-gradient(135deg,var(--teal),var(--teal-l));
      color:white;font-family:var(--font-b);font-size:0.9rem;font-weight:700;
      border:none;cursor:pointer;display:inline-flex;align-items:center;gap:9px;
      box-shadow:0 4px 18px rgba(13,148,136,0.32);transition:all 0.18s;
    }
    .btn-save-page:hover { transform:translateY(-1px);box-shadow:0 7px 24px rgba(13,148,136,0.45); }

    /* CARD FOOTER */
    .card-foot { border-top:1px solid var(--border);background:var(--mist);padding:0.9rem 1.75rem;display:flex;align-items:center;justify-content:space-between;font-size:0.73rem;color:var(--slate); }

    @keyframes spin { to { transform: rotate(360deg); } }
    .spinner { animation: spin 0.8s linear infinite; }
  </style>
</head>
<body>

<!-- HERO -->
<div class="hero">
  <div class="hero-ring"></div>
  <div class="wrap hero-inner">
    <div class="avatar-wrap">
      <img src="https://ui-avatars.com/api/?name=<?= urlencode($creator['full_name'] ?? 'C') ?>&background=C8102E&color=fff&size=200&bold=true&font-size=0.4"
           class="avatar" alt="Avatar">
      <div class="avatar-dot"></div>
    </div>
    <h1 class="hero-name"><?= htmlspecialchars($creator['full_name'] ?? '—') ?></h1>
    <p class="hero-handle">@<?= htmlspecialchars($creator['username'] ?? '—') ?></p>
    <div class="hero-meta">
      <span><i class="fas fa-envelope"></i><?= htmlspecialchars($creator['email'] ?? '—') ?></span>
      <span><i class="fas fa-phone"></i><?= htmlspecialchars($creator['phone'] ?? '—') ?></span>
      <span><i class="fas fa-calendar-alt"></i>Joined <?= date('M Y', strtotime($creator['date_created'])) ?></span>
    </div>
  </div>
</div>

<div class="wrap">

  <!-- STATS -->
  <div class="stats-row">
    <div class="s-card">
      <div class="s-icon ic-r"><i class="fas fa-ticket-alt"></i></div>
      <div class="s-num"><?= count($tickets_created ?? []) ?></div>
      <div class="s-lbl">Events</div>
    </div>
    <div class="s-card">
      <div class="s-icon ic-b"><i class="fas fa-shopping-cart"></i></div>
      <div class="s-num"><?= count($ticket_sales ?? []) ?></div>
      <div class="s-lbl">Sales</div>
    </div>
    <div class="s-card">
      <div class="s-icon ic-g"><i class="fas fa-wallet"></i></div>
      <div class="s-num">KSh <?= number_format(($current_balance ?? 0)/1000, 1) ?>K</div>
      <div class="s-lbl">Balance</div>
    </div>
    <div class="s-card">
      <div class="s-icon ic-a"><i class="fas fa-money-bill-wave"></i></div>
      <div class="s-num">KSh <?= number_format(($total_successful_withdrawals ?? 0)/1000, 1) ?>K</div>
      <div class="s-lbl">Withdrawn</div>
    </div>
  </div>

  <!-- ACTION BAR -->
  <div class="act-bar">
    <div class="breadcrumb">
      <a href="index.php?page=all_creators">Creators</a>
      <i class="fas fa-chevron-right"></i>
      <span><?= htmlspecialchars($creator['full_name'] ?? 'Creator') ?></span>
    </div>
    <div style="display:flex;gap:0.6rem">
      <a href="index.php?page=all_creators" class="btn-outline">
        <i class="fas fa-arrow-left"></i> Back
      </a>
      <a href="index.php?page=edit_creator&id=<?= $creator['id'] ?>" class="btn-solid">
        <i class="fas fa-edit"></i> Edit
      </a>
    </div>
  </div>

  <?php if (isset($error)): ?>
    <div class="k-alert k-err" style="margin-bottom:1rem"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
  <?php endif; ?>

  <!-- MAIN CARD -->
  <div class="main-card">

    <div class="tab-nav" id="tabNav">
      <button class="tab-btn <?= $active_tab==='overview'        ? 'active':'' ?>" data-tab="overview">        <i class="fas fa-id-card"></i>Overview</button>
      <button class="tab-btn <?= $active_tab==='page-details'    ? 'active':'' ?>" data-tab="page-details">    <i class="fas fa-store"></i>Page Details</button>
      <button class="tab-btn <?= $active_tab==='withdrawals'     ? 'active':'' ?>" data-tab="withdrawals">     <i class="fas fa-arrow-up-right-from-square"></i>Withdrawals</button>
      <button class="tab-btn <?= $active_tab==='balance'         ? 'active':'' ?>" data-tab="balance">         <i class="fas fa-wallet"></i>Balance</button>
      <button class="tab-btn <?= $active_tab==='tickets'         ? 'active':'' ?>" data-tab="tickets">         <i class="fas fa-ticket-alt"></i>Tickets</button>
      <button class="tab-btn <?= $active_tab==='sales'           ? 'active':'' ?>" data-tab="sales">           <i class="fas fa-chart-line"></i>Sales</button>
      <button class="tab-btn <?= $active_tab==='buyers'          ? 'active':'' ?>" data-tab="buyers">          <i class="fas fa-users"></i>Buyers</button>
      <button class="tab-btn <?= $active_tab==='send-link'       ? 'active':'' ?>" data-tab="send-link">       <i class="fas fa-paper-plane"></i>Send Link</button>
      <button class="tab-btn <?= $active_tab==='change-password' ? 'active':'' ?>" data-tab="change-password"><i class="fas fa-key"></i>Password</button>
    </div>

    <div class="tc-area">

      <!-- ── OVERVIEW ── -->
      <div class="tab-pane <?= $active_tab==='overview' ? 'active':'' ?>" id="tab-overview">
        <div class="sh">
          <div class="sh-icon ic-r"><i class="fas fa-id-card"></i></div>
          <div><h5>Creator Profile</h5><p>Account details and status</p></div>
        </div>
        <div class="info-grid">
          <div class="info-item"><div class="ii-lbl">Full Name</div><div class="ii-val"><?= htmlspecialchars($creator['full_name']??'—') ?></div></div>
          <div class="info-item"><div class="ii-lbl">Username</div><div class="ii-val">@<?= htmlspecialchars($creator['username']??'—') ?></div></div>
          <div class="info-item"><div class="ii-lbl">Email</div><div class="ii-val"><?= htmlspecialchars($creator['email']??'—') ?></div></div>
          <div class="info-item"><div class="ii-lbl">Phone</div><div class="ii-val"><?= htmlspecialchars($creator['phone']??'—') ?></div></div>
          <div class="info-item"><div class="ii-lbl">Date Created</div><div class="ii-val"><?= date('d M Y • H:i', strtotime($creator['date_created'])) ?></div></div>
          <div class="info-item"><div class="ii-lbl">Last Updated</div><div class="ii-val"><?= $creator['updated_at'] ? date('d M Y • H:i', strtotime($creator['updated_at'])) : 'Never' ?></div></div>
          <div class="info-item" style="grid-column:1/-1">
            <div class="ii-lbl">Must Change Password</div>
            <div class="ii-val" style="margin-top:5px">
              <?php if ($creator['must_change_password']==1): ?>
                <span class="badge bg-err"><i class="fas fa-circle" style="font-size:.4rem"></i> Required on next login</span>
              <?php else: ?>
                <span class="badge bg-ok"><i class="fas fa-circle" style="font-size:.4rem"></i> Not required</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════
           ── PAGE DETAILS TAB ──
      ══════════════════════════════════════════ -->
      <div class="tab-pane <?= $active_tab==='page-details' ? 'active':'' ?>" id="tab-page-details">
        <div class="sh">
          <div class="sh-icon ic-t"><i class="fas fa-store"></i></div>
          <div>
            <h5>Page Details</h5>
            <p><?= $my_page ? 'Public creator page — view &amp; edit below' : 'No page set up yet — fill in the form to create one' ?></p>
          </div>
        </div>

        <?php if ($page_success): ?>
          <div class="k-alert k-ok"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($page_success) ?></span></div>
        <?php endif; ?>
        <?php if ($page_error): ?>
          <div class="k-alert k-err"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($page_error) ?></span></div>
        <?php endif; ?>

        <div class="pg-layout">

          <!-- LEFT: current page preview -->
          <div>
            <?php if ($my_page): ?>
              <div class="pg-profile-card">
                <!-- Banner -->
                <div class="pg-banner-wrap">
                  <?php if (!empty($my_page['banner'])): ?>
                    <img src="<?= htmlspecialchars($my_page['banner']) ?>" alt="Banner" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="pg-banner-placeholder" style="display:none"><i class="fas fa-image"></i></div>
                  <?php else: ?>
                    <div class="pg-banner-placeholder"><i class="fas fa-image"></i></div>
                  <?php endif; ?>
                </div>

                <!-- Avatar + name -->
                <div class="pg-avatar-row">
                  <?php if (!empty($my_page['profile_pic'])): ?>
                    <img src="<?= htmlspecialchars($my_page['profile_pic']) ?>" class="pg-avatar" alt="Profile"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="pg-avatar-placeholder" style="display:none"><?= strtoupper(substr($my_page['full_name']??'C',0,1)) ?></div>
                  <?php else: ?>
                    <div class="pg-avatar-placeholder"><?= strtoupper(substr($my_page['full_name']??'C',0,1)) ?></div>
                  <?php endif; ?>
                  <div class="pg-name-col">
                    <div class="pg-name"><?= htmlspecialchars($my_page['full_name']??'—') ?></div>
                    <?php if (!empty($my_page['page_link'])): ?>
                      <a href="<?= htmlspecialchars($my_page['page_link']) ?>" class="pg-link" target="_blank" rel="noopener">
                        <i class="fas fa-link" style="font-size:.6rem"></i> <?= htmlspecialchars($my_page['page_link']) ?>
                      </a>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="pg-body">
                  <?php if (!empty($my_page['bio'])): ?>
                    <div class="pg-bio"><?= nl2br(htmlspecialchars($my_page['bio'])) ?></div>
                  <?php endif; ?>

                  <div class="pg-meta-grid">
                    <div class="pg-meta-item">
                      <div class="pg-meta-lbl"><i class="fas fa-phone" style="margin-right:4px"></i>Phone</div>
                      <div class="pg-meta-val"><?= htmlspecialchars($my_page['phone']??'—') ?></div>
                    </div>
                    <div class="pg-meta-item">
                      <div class="pg-meta-lbl"><i class="fas fa-mobile-alt" style="margin-right:4px"></i>M-Pesa</div>
                      <div class="pg-meta-val"><?= htmlspecialchars($my_page['mpesa_number']??'—') ?></div>
                    </div>
                    <div class="pg-meta-item" style="grid-column:1/-1">
                      <div class="pg-meta-lbl"><i class="fas fa-map-marker-alt" style="margin-right:4px"></i>County</div>
                      <div class="pg-meta-val"><?= htmlspecialchars($my_page['county']??'—') ?></div>
                    </div>
                  </div>

                  <div class="pg-dates">
                    <span><i class="fas fa-plus-circle" style="margin-right:4px;color:var(--teal)"></i>Created: <?= date('d M Y', strtotime($my_page['created_at'])) ?></span>
                    <span><i class="fas fa-clock" style="margin-right:4px;color:var(--slate)"></i>Updated: <?= $my_page['updated_at'] ? date('d M Y • H:i', strtotime($my_page['updated_at'])) : 'Never' ?></span>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <div class="pg-empty-card">
                <div class="empty-ic"><i class="fas fa-store-slash"></i></div>
                <p>This creator hasn't set up their public page yet.<br>Use the form on the right to create one for them.</p>
              </div>
            <?php endif; ?>
          </div>

          <!-- RIGHT: edit / create form -->
          <div class="pg-form-wrap">
            <div style="font-size:0.72rem;font-weight:700;color:var(--slate);text-transform:uppercase;letter-spacing:0.07em;margin-bottom:1rem;display:flex;align-items:center;gap:7px;">
              <span style="width:22px;height:22px;border-radius:6px;background:rgba(13,148,136,0.1);display:inline-flex;align-items:center;justify-content:center;color:var(--teal);font-size:0.65rem"><i class="fas fa-pen"></i></span>
              <?= $my_page ? 'Edit Page Details' : 'Create Page' ?>
            </div>

            <form method="POST" action="?page=view_creator&id=<?= $creator_id ?>" id="pageForm">
              <input type="hidden" name="update_page" value="1">

              <div class="pg-form-grid">

                <div class="pg-form-field">
                  <label for="pg_full_name">Display Name</label>
                  <input type="text" id="pg_full_name" name="pg_full_name" maxlength="120"
                         placeholder="Public display name"
                         value="<?= htmlspecialchars($my_page['full_name'] ?? $creator['full_name'] ?? '') ?>">
                </div>

                <div class="pg-form-field">
                  <label for="pg_phone">Phone Number</label>
                  <input type="tel" id="pg_phone" name="pg_phone" maxlength="20"
                         placeholder="+254 7XX XXX XXX"
                         value="<?= htmlspecialchars($my_page['phone'] ?? $creator['phone'] ?? '') ?>">
                </div>

                <div class="pg-form-field">
                  <label for="pg_mpesa_number">M-Pesa Number</label>
                  <input type="tel" id="pg_mpesa_number" name="pg_mpesa_number" maxlength="20"
                         placeholder="e.g. 0712345678"
                         value="<?= htmlspecialchars($my_page['mpesa_number'] ?? '') ?>">
                </div>

                <div class="pg-form-field">
                  <label for="pg_county">County</label>
                  <select id="pg_county" name="pg_county">
                    <option value="">— Select County —</option>
                    <?php
                    $counties = ["Baringo","Bomet","Bungoma","Busia","Elgeyo Marakwet","Embu","Garissa","Homa Bay","Isiolo","Kajiado","Kakamega","Kericho","Kiambu","Kilifi","Kirinyaga","Kisii","Kisumu","Kitui","Kwale","Laikipia","Lamu","Machakos","Makueni","Mandera","Marsabit","Meru","Migori","Mombasa","Murang'a","Nairobi","Nakuru","Nandi","Narok","Nyamira","Nyandarua","Nyeri","Samburu","Siaya","Taita Taveta","Tana River","Tharaka Nithi","Trans Nzoia","Turkana","Uasin Gishu","Vihiga","Wajir","West Pokot"];
                    foreach ($counties as $c) {
                        $sel = (($my_page['county'] ?? '') === $c) ? 'selected' : '';
                        echo "<option value=\"".htmlspecialchars($c)."\" {$sel}>".htmlspecialchars($c)."</option>";
                    }
                    ?>
                  </select>
                </div>

                <div class="pg-form-field full">
                  <label for="pg_page_link">Page Link / URL</label>
                  <input type="url" id="pg_page_link" name="pg_page_link" maxlength="255"
                         placeholder="https://keychele.co.ke/page/username"
                         value="<?= htmlspecialchars($my_page['page_link'] ?? '') ?>">
                  <div class="field-hint">The public URL fans will use to find this creator's page.</div>
                </div>

                <div class="pg-form-field full">
                  <label for="pg_bio">Bio / Description</label>
                  <textarea id="pg_bio" name="pg_bio" maxlength="1000"
                            placeholder="Tell fans about this creator — events they run, music they make, etc."><?= htmlspecialchars($my_page['bio'] ?? '') ?></textarea>
                </div>

                <div class="pg-form-field full">
                  <label for="pg_profile_pic">Profile Picture URL</label>
                  <input type="url" id="pg_profile_pic" name="pg_profile_pic" maxlength="500"
                         placeholder="https://…/photo.jpg"
                         value="<?= htmlspecialchars($my_page['profile_pic'] ?? '') ?>"
                         oninput="previewImg(this,'ppThumb')">
                  <div class="img-preview-row">
                    <img id="ppThumb" class="img-thumb <?= !empty($my_page['profile_pic']) ? 'show' : '' ?>"
                         src="<?= htmlspecialchars($my_page['profile_pic'] ?? '') ?>" alt="Profile preview"
                         onerror="this.classList.remove('show')">
                    <span class="field-hint" style="margin-top:0">Paste an image URL to preview.</span>
                  </div>
                </div>

                <div class="pg-form-field full">
                  <label for="pg_banner">Banner Image URL</label>
                  <input type="url" id="pg_banner" name="pg_banner" maxlength="500"
                         placeholder="https://…/banner.jpg"
                         value="<?= htmlspecialchars($my_page['banner'] ?? '') ?>"
                         oninput="previewImg(this,'bnThumb')">
                  <div class="img-preview-row">
                    <img id="bnThumb" class="img-thumb <?= !empty($my_page['banner']) ? 'show' : '' ?>"
                         src="<?= htmlspecialchars($my_page['banner'] ?? '') ?>" alt="Banner preview"
                         style="width:80px;height:44px;border-radius:6px;object-fit:cover"
                         onerror="this.classList.remove('show')">
                    <span class="field-hint" style="margin-top:0">Paste an image URL to preview.</span>
                  </div>
                </div>

              </div><!-- /pg-form-grid -->

              <div style="margin-top:1.35rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                <button type="submit" class="btn-save-page" id="savePageBtn">
                  <i class="fas fa-floppy-disk"></i>
                  <?= $my_page ? 'Save Changes' : 'Create Page' ?>
                </button>
                <?php if ($my_page && !empty($my_page['page_link'])): ?>
                  <a href="<?= htmlspecialchars($my_page['page_link']) ?>" target="_blank" rel="noopener"
                     style="font-size:0.8rem;color:var(--teal);font-weight:600;display:inline-flex;align-items:center;gap:5px;text-decoration:none;">
                    <i class="fas fa-arrow-up-right-from-square"></i> View Live Page
                  </a>
                <?php endif; ?>
              </div>
            </form>
          </div>

        </div><!-- /pg-layout -->
      </div>
      <!-- ══ END PAGE DETAILS ══ -->

      <!-- ── WITHDRAWALS ── -->
      <div class="tab-pane <?= $active_tab==='withdrawals' ? 'active':'' ?>" id="tab-withdrawals">
        <div class="sh">
          <div class="sh-icon ic-a"><i class="fas fa-money-bill-wave"></i></div>
          <div><h5>Withdrawal Requests</h5><p>M-Pesa disbursement history</p></div>
        </div>
        <?php if (!empty($withdrawals)): ?>
          <div class="w-sum">
            <span><i class="fas fa-check-circle" style="color:var(--success);margin-right:5px"></i>Total successful</span>
            <strong>KSh <?= number_format($total_successful_withdrawals, 2) ?></strong>
          </div>
          <div class="t-wrap">
            <table>
              <thead><tr><th>Date</th><th>M-Pesa</th><th class="te">Amount</th><th class="te">Charge</th><th class="te">Total</th><th>Status</th><th>Processed</th><th>Tx Code</th></tr></thead>
              <tbody>
                <?php foreach ($withdrawals as $w):
                  $s = strtolower($w['status']??'');
                  $bc = (strpos($s,'complete')!==false||strpos($s,'success')!==false) ? 'bg-ok' : (strpos($s,'pending')!==false ? 'bg-sl' : 'bg-w');
                ?>
                  <tr>
                    <td style="white-space:nowrap"><?= date('d M Y', strtotime($w['requested_at'])) ?><br><span style="color:var(--slate);font-size:.7rem"><?= date('H:i', strtotime($w['requested_at'])) ?></span></td>
                    <td><?= htmlspecialchars($w['mpesa_number']??'—') ?></td>
                    <td class="te" style="font-weight:600">KSh <?= number_format($w['amount']??0,2) ?></td>
                    <td class="te" style="color:var(--slate)">KSh <?= number_format($w['charge']??0,2) ?></td>
                    <td class="te" style="font-weight:700">KSh <?= number_format($w['total_amount']??0,2) ?></td>
                    <td><span class="badge <?= $bc ?>"><?= htmlspecialchars(ucfirst($w['status']??'Pending')) ?></span></td>
                    <td style="font-size:.75rem;white-space:nowrap"><?= $w['processed_at'] ? date('d M Y H:i', strtotime($w['processed_at'])) : '—' ?></td>
                    <td style="font-family:monospace;font-size:.76rem"><?= htmlspecialchars($w['transaction_code']??'—') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty"><div class="empty-ic"><i class="fas fa-money-bill-transfer"></i></div><p>No withdrawal requests found.</p></div>
        <?php endif; ?>
      </div>

      <!-- ── BALANCE ── -->
      <div class="tab-pane <?= $active_tab==='balance' ? 'active':'' ?>" id="tab-balance">
        <div class="sh">
          <div class="sh-icon ic-g"><i class="fas fa-wallet"></i></div>
          <div><h5>Current Balance</h5><p>Available creator earnings</p></div>
        </div>
        <div class="bal-hero">
          <div class="bal-lbl">Available Balance</div>
          <div class="bal-amt"><sup>KSh </sup><?= number_format($current_balance, 2) ?></div>
          <div class="bal-upd"><?= $balance_row ? 'Last updated '.date('d M Y • H:i', strtotime($balance_row['date'])) : 'No balance record found' ?></div>
        </div>
      </div>

      <!-- ── TICKETS ── -->
      <div class="tab-pane <?= $active_tab==='tickets' ? 'active':'' ?>" id="tab-tickets">
        <div class="sh">
          <div class="sh-icon ic-r"><i class="fas fa-ticket-alt"></i></div>
          <div><h5>Tickets Created</h5><p><?= count($tickets_created??[]) ?> event<?= count($tickets_created??[])!==1?'s':'' ?></p></div>
        </div>
        <?php if (!empty($tickets_created)): ?>
          <div class="t-wrap">
            <table>
              <thead><tr><th>Title</th><th>Venue</th><th>Date</th><th class="te">Sold</th><th class="te">Buyers</th><th class="te">Revenue</th><th>Created</th></tr></thead>
              <tbody>
                <?php foreach ($tickets_created as $t):
                  $ss = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) AS s, COUNT(DISTINCT COALESCE(buyer_email,buyer_phone,buyer_name)) AS b, COALESCE(SUM(amount_paid),0) AS r FROM ticket_purchases WHERE ticket_id=? AND status='completed'");
                  $ss->execute([$t['id']]);
                  $st = $ss->fetch(PDO::FETCH_ASSOC);
                ?>
                  <tr>
                    <td style="font-weight:600;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($t['title']??'—') ?></td>
                    <td style="color:var(--slate)"><?= htmlspecialchars($t['venue']??'—') ?></td>
                    <td style="white-space:nowrap;font-size:.78rem"><?= $t['start_date'] ? date('d M Y', strtotime($t['start_date'])) : '—' ?><?= $t['start_time'] ? '<br><span style="color:var(--slate)">'.$t['start_time'].'</span>' : '' ?></td>
                    <td class="te" style="font-weight:700;color:var(--crimson)"><?= number_format($st['s']) ?></td>
                    <td class="te"><?= number_format($st['b']) ?></td>
                    <td class="te" style="font-weight:700;color:var(--success)">KSh <?= number_format($st['r'],2) ?></td>
                    <td style="color:var(--slate);font-size:.76rem"><?= date('d M y', strtotime($t['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty"><div class="empty-ic"><i class="fas fa-ticket-alt"></i></div><p>No tickets created yet.</p></div>
        <?php endif; ?>
      </div>

      <!-- ── SALES ── -->
      <div class="tab-pane <?= $active_tab==='sales' ? 'active':'' ?>" id="tab-sales">
        <div class="sh">
          <div class="sh-icon ic-b"><i class="fas fa-chart-line"></i></div>
          <div><h5>Completed Sales</h5><p><?= count($ticket_sales??[]) ?> transactions · KSh <?= number_format($total_revenue??0, 2) ?></p></div>
        </div>
        <?php if (!empty($ticket_sales)): ?>
          <div class="t-wrap">
            <table>
              <thead><tr><th>Event</th><th>Buyer</th><th class="te">Qty</th><th class="te">Amount</th><th>Date</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($ticket_sales as $s): ?>
                  <tr>
                    <td style="font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($s['ticket_title']??'—') ?></td>
                    <td><?= htmlspecialchars($s['buyer_name']?:($s['buyer_email']??'—')) ?></td>
                    <td class="te"><?= $s['quantity']??1 ?></td>
                    <td class="te" style="font-weight:700">KSh <?= number_format($s['amount_paid']??0,2) ?></td>
                    <td style="white-space:nowrap;font-size:.78rem"><?= date('d M Y H:i', strtotime($s['created_at'])) ?></td>
                    <td><span class="badge bg-ok"><i class="fas fa-circle" style="font-size:.38rem"></i>Completed</span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty"><div class="empty-ic"><i class="fas fa-chart-line"></i></div><p>No completed sales yet.</p></div>
        <?php endif; ?>
      </div>

      <!-- ── BUYERS ── -->
      <div class="tab-pane <?= $active_tab==='buyers' ? 'active':'' ?>" id="tab-buyers">
        <div class="sh">
          <div class="sh-icon ic-b"><i class="fas fa-users"></i></div>
          <div><h5>All Buyers</h5><p>Completed purchase records</p></div>
        </div>
        <?php if (!empty($ticket_sales)): ?>
          <div class="t-wrap">
            <table>
              <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Event</th><th class="te">Qty</th><th class="te">Paid</th><th>Date</th></tr></thead>
              <tbody>
                <?php foreach ($ticket_sales as $p): ?>
                  <tr>
                    <td style="font-weight:600"><?= htmlspecialchars($p['buyer_name']??'—') ?></td>
                    <td style="color:var(--slate);font-size:.78rem"><?= htmlspecialchars($p['buyer_email']??'—') ?></td>
                    <td style="color:var(--slate);font-size:.78rem"><?= htmlspecialchars($p['buyer_phone']??'—') ?></td>
                    <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($p['ticket_title']??'—') ?></td>
                    <td class="te"><?= $p['quantity']??1 ?></td>
                    <td class="te" style="font-weight:700">KSh <?= number_format($p['amount_paid']??0,2) ?></td>
                    <td style="white-space:nowrap;font-size:.76rem"><?= date('d M Y H:i', strtotime($p['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty"><div class="empty-ic"><i class="fas fa-users"></i></div><p>No buyers found.</p></div>
        <?php endif; ?>
      </div>

      <!-- ── SEND TICKET LINK ── -->
      <div class="tab-pane <?= $active_tab==='send-link' ? 'active':'' ?>" id="tab-send-link">
        <div class="sh">
          <div class="sh-icon ic-p"><i class="fas fa-paper-plane"></i></div>
          <div>
            <h5>Send Ticket Link</h5>
            <p>Email the ticket URL to <?= htmlspecialchars($creator['full_name']??'this creator') ?> so they can share it with fans</p>
          </div>
        </div>

        <?php if ($tl_success): ?>
          <div class="k-alert k-ok"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($tl_success) ?></span></div>
        <?php endif; ?>
        <?php if ($tl_error): ?>
          <div class="k-alert k-err"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($tl_error) ?></span></div>
        <?php endif; ?>

        <div class="stl-wrap">
          <div class="stl-info">
            <div class="stl-info-ico"><i class="fas fa-circle-info"></i></div>
            <p>
              Select one of <strong><?= htmlspecialchars($creator['full_name']??'this creator') ?>'s</strong> tickets below.
              The ticket URL will be emailed to their registered address
              <strong>(<?= htmlspecialchars($creator['email']??'—') ?>)</strong>.
              You can optionally CC an extra email address.
            </p>
          </div>

          <?php if (empty($tickets_created)): ?>
            <div class="empty" style="padding:2rem 1rem">
              <div class="empty-ic"><i class="fas fa-ticket-alt"></i></div>
              <p>This creator has no tickets yet. They need to create an event first.</p>
            </div>
          <?php else: ?>

          <form method="POST" action="?page=view_creator&id=<?= $creator_id ?>" id="sendLinkForm">
            <input type="hidden" name="send_ticket_link" value="1">

            <div class="stl-field">
              <label>Select Ticket / Event *</label>
              <select name="ticket_id" id="ticketSelect" required onchange="updateTicketPreview(this)">
                <option value="">— Choose an event —</option>
                <?php foreach ($tickets_created as $t): ?>
                  <option value="<?= $t['id'] ?>"
                          data-title="<?= htmlspecialchars($t['title']??'', ENT_QUOTES) ?>"
                          data-url="https://keychele.co.ke/ticket/<?= $t['id'] ?>"
                          data-date="<?= $t['start_date'] ? date('d M Y', strtotime($t['start_date'])) : 'TBD' ?>">
                    <?= htmlspecialchars($t['title']??'Untitled') ?>
                    <?= $t['start_date'] ? ' — '.date('d M Y', strtotime($t['start_date'])) : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="field-hint">The link <code>keychele.co.ke/ticket/{id}</code> will be included in the email.</div>
            </div>

            <div class="ticket-preview" id="ticketPreview">
              <div class="tp-ico"><i class="fas fa-ticket-alt"></i></div>
              <div>
                <div class="tp-title" id="previewTitle"></div>
                <div class="tp-url"  id="previewUrl"></div>
              </div>
            </div>

            <div class="stl-field">
              <label>Primary Recipient (Creator's Email)</label>
              <input type="text" value="<?= htmlspecialchars($creator['email']??'—') ?>"
                     style="height:46px;padding:0 14px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-family:var(--font-b);font-size:0.88rem;color:var(--slate);background:#f3f4f6;width:100%;cursor:not-allowed;" readonly>
            </div>

            <div class="stl-field">
              <label>Additional Email (Optional CC)</label>
              <input type="email" name="extra_email" placeholder="e.g. manager@example.com" style="height:46px;">
              <div class="field-hint">Leave blank to send only to the creator's registered email.</div>
            </div>

            <button type="submit" class="btn-send-link" id="sendBtn">
              <i class="fas fa-paper-plane"></i> Send Ticket Link
            </button>
          </form>

          <div style="margin-top:2rem">
            <div style="font-size:0.72rem;font-weight:700;color:var(--slate);text-transform:uppercase;letter-spacing:0.07em;margin-bottom:0.75rem;">All Ticket Links — Quick Copy</div>
            <div class="t-wrap">
              <?php foreach ($tickets_created as $t):
                $turl = "https://keychele.co.ke/ticket/" . $t['id'];
              ?>
              <div class="sent-ticket-row">
                <div>
                  <div class="str-title"><?= htmlspecialchars($t['title']??'Untitled') ?></div>
                  <div class="str-url"><?= htmlspecialchars($turl) ?></div>
                </div>
                <button class="copy-mini" onclick="copyUrl(this, '<?= htmlspecialchars($turl, ENT_QUOTES) ?>')">
                  <i class="fas fa-copy"></i> Copy
                </button>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <?php endif; ?>
        </div>
      </div>

      <!-- ── CHANGE PASSWORD ── -->
      <div class="tab-pane <?= $active_tab==='change-password' ? 'active':'' ?>" id="tab-change-password">
        <div class="sh">
          <div class="sh-icon ic-r"><i class="fas fa-key"></i></div>
          <div><h5>Change Password</h5><p>Set a new password for <?= htmlspecialchars($creator['full_name']??'creator') ?></p></div>
        </div>

        <?php if ($pw_success): ?>
          <div class="k-alert k-ok"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($pw_success) ?></span></div>
        <?php endif; ?>
        <?php if ($pw_error): ?>
          <div class="k-alert k-err"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($pw_error) ?></span></div>
        <?php endif; ?>

        <div class="pw-wrap">
          <div class="pw-info">
            <strong>⚠️ Admin action:</strong> Password is hashed with <strong>BCrypt</strong> — identical to the registration flow,
            ensuring seamless login verification. The creator's <em>Must Change Password</em> flag will be set to
            <strong>Yes</strong> so they update credentials on next login.
          </div>

          <form method="POST" action="?page=view_creator&id=<?= $creator_id ?>">
            <input type="hidden" name="change_password" value="1">

            <div class="pw-field">
              <label for="new_password">New Password</label>
              <div class="pw-in-wrap">
                <input type="password" id="new_password" name="new_password"
                       placeholder="Enter new password" minlength="6" required autocomplete="new-password">
                <button type="button" class="pw-toggle" id="toggleNew">
                  <i class="fas fa-eye" id="icoNew"></i>
                </button>
              </div>
              <div class="str-track"><div class="str-fill" id="strFill"></div></div>
              <div class="str-lbl" id="strLbl"></div>
            </div>

            <div class="pw-field">
              <label for="confirm_password">Confirm Password</label>
              <div class="pw-in-wrap">
                <input type="password" id="confirm_password" name="confirm_password"
                       placeholder="Repeat new password" minlength="6" required autocomplete="new-password">
                <button type="button" class="pw-toggle" id="toggleConfirm">
                  <i class="fas fa-eye" id="icoConfirm"></i>
                </button>
              </div>
              <div class="match-hint" id="matchHint"></div>
            </div>

            <button type="submit" class="btn-pw">
              <i class="fas fa-lock"></i> Update Password
            </button>
          </form>
        </div>
      </div>

    </div><!-- /tc-area -->

    <div class="card-foot">
      <span>Creator ID: <strong>#<?= $creator['id'] ?></strong></span>
      <span>Joined <strong><?= date('F Y', strtotime($creator['date_created'])) ?></strong></span>
    </div>
  </div><!-- /main-card -->

</div><!-- /wrap -->

<script>
/* ── TABS ── */
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const pane = document.getElementById('tab-' + btn.dataset.tab);
    if (pane) pane.classList.add('active');
  });
});

/* ── TICKET PREVIEW ── */
function updateTicketPreview(sel) {
  const opt     = sel.options[sel.selectedIndex];
  const preview = document.getElementById('ticketPreview');
  if (!sel.value) { preview.classList.remove('visible'); return; }
  document.getElementById('previewTitle').textContent =
    opt.dataset.title + (opt.dataset.date ? ' — ' + opt.dataset.date : '');
  document.getElementById('previewUrl').textContent = opt.dataset.url;
  preview.classList.add('visible');
}

/* ── SEND BUTTON SPINNER ── */
const sendForm = document.getElementById('sendLinkForm');
if (sendForm) {
  sendForm.addEventListener('submit', function() {
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    btn.innerHTML = `<svg class="spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Sending…`;
  });
}

/* ── SAVE PAGE BUTTON SPINNER ── */
const pageForm = document.getElementById('pageForm');
if (pageForm) {
  pageForm.addEventListener('submit', function() {
    const btn = document.getElementById('savePageBtn');
    btn.disabled = true;
    btn.innerHTML = `<svg class="spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Saving…`;
  });
}

/* ── IMAGE PREVIEW ── */
function previewImg(input, thumbId) {
  const thumb = document.getElementById(thumbId);
  if (!input.value) { thumb.classList.remove('show'); return; }
  thumb.src = input.value;
  thumb.onload  = () => thumb.classList.add('show');
  thumb.onerror = () => thumb.classList.remove('show');
}

/* ── COPY URL ── */
function copyUrl(btn, url) {
  function onSuccess() {
    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
    btn.classList.add('copied');
    setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; btn.classList.remove('copied'); }, 2500);
  }
  function fallback() {
    const ta = document.createElement('textarea');
    ta.value = url; ta.setAttribute('readonly','');
    ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
    document.body.appendChild(ta);
    ta.focus(); ta.select(); ta.setSelectionRange(0, url.length);
    if (document.execCommand('copy')) onSuccess();
    document.body.removeChild(ta);
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(onSuccess).catch(fallback);
  } else { fallback(); }
}

/* ── PASSWORD TOGGLES ── */
function mkToggle(btnId, inputId, iconId) {
  const btn   = document.getElementById(btnId);
  const input = document.getElementById(inputId);
  const ico   = document.getElementById(iconId);
  if (!btn) return;
  btn.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    ico.classList.toggle('fa-eye',       !show);
    ico.classList.toggle('fa-eye-slash',  show);
  });
}
mkToggle('toggleNew',     'new_password',     'icoNew');
mkToggle('toggleConfirm', 'confirm_password', 'icoConfirm');

/* ── STRENGTH METER ── */
const LEVELS = [
  {max:1,col:'#EF4444',txt:'Very weak'},
  {max:2,col:'#F97316',txt:'Weak'},
  {max:3,col:'#EAB308',txt:'Fair'},
  {max:4,col:'#3B82F6',txt:'Strong'},
  {max:5,col:'#059669',txt:'Very strong'},
];
const pwIn   = document.getElementById('new_password');
const fill   = document.getElementById('strFill');
const strLbl = document.getElementById('strLbl');

if (pwIn) {
  pwIn.addEventListener('input', function () {
    const v = this.value;
    if (!v) { fill.style.width='0%'; strLbl.textContent=''; return; }
    let sc = 0;
    if (v.length >= 6)          sc++;
    if (v.length >= 10)         sc++;
    if (/[A-Z]/.test(v))        sc++;
    if (/[0-9]/.test(v))        sc++;
    if (/[^A-Za-z0-9]/.test(v)) sc++;
    const lvl = LEVELS.find(l => sc <= l.max) || LEVELS[4];
    fill.style.width      = (sc/5*100) + '%';
    fill.style.background = lvl.col;
    strLbl.textContent    = lvl.txt;
    strLbl.style.color    = lvl.col;
  });
}

/* ── MATCH INDICATOR ── */
const confIn    = document.getElementById('confirm_password');
const matchHint = document.getElementById('matchHint');
if (confIn) {
  confIn.addEventListener('input', function () {
    if (!this.value) { matchHint.textContent = ''; return; }
    const ok = this.value === pwIn.value;
    matchHint.textContent = ok ? '✓ Passwords match' : '✗ Passwords do not match';
    matchHint.className   = 'match-hint ' + (ok ? 'm-ok' : 'm-err');
  });
}
</script>
</body>
</html>