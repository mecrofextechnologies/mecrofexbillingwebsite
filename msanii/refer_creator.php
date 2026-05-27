<?php
include 'includes/connect.php';
$creator_id = isset($_SESSION['creator_id']) ? (int)$_SESSION['creator_id'] : null;
if (!$creator_id) die("Please login first.");

/* ── Fetch creator info ── */
$stmt = $pdo->prepare("SELECT username, full_name, email FROM creators WHERE id = ? LIMIT 1");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$creator) die("Creator not found.");

$username    = $creator['username']  ?? '';
$full_name   = $creator['full_name'] ?? $username;
$ref_code    = $username . '_' . $creator_id;
$refer_link  = "https://keychele.co.ke/register?refer=" . urlencode($ref_code);

/* ── Referral stats ── */

/* Total creators referred */
try {
    $refStmt = $pdo->prepare("SELECT COUNT(*) FROM creators WHERE CAST(referred_by AS UNSIGNED) = ?");
    $refStmt->bindValue(1, (int)$creator_id, PDO::PARAM_INT);
    $refStmt->execute();
    $total_referred = (int)$refStmt->fetchColumn();
} catch (PDOException $e) { $total_referred = 0; }

/* ── Earnings: split by status correctly ── */

/* Lifetime total (ALL statuses — the true grand total ever credited to this referrer) */
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM referral_earnings WHERE referrer_id = ?");
    $stmt->execute([$creator_id]);
    $total_earned = (float)$stmt->fetchColumn();
} catch (PDOException $e) { $total_earned = 0.00; }

/* Paid / credited to wallet (status = 'paid' OR 'completed' OR 'credited') */
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM referral_earnings
        WHERE referrer_id = ?
          AND status IN ('paid', 'completed', 'credited')
    ");
    $stmt->execute([$creator_id]);
    $paid_earned = (float)$stmt->fetchColumn();
} catch (PDOException $e) { $paid_earned = 0.00; }

/* Pending disbursement (status = 'pending') */
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM referral_earnings
        WHERE referrer_id = ?
          AND status = 'pending'
    ");
    $stmt->execute([$creator_id]);
    $pending_earned = (float)$stmt->fetchColumn();
} catch (PDOException $e) { $pending_earned = 0.00; }

/* This month's earnings */
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM referral_earnings
        WHERE referrer_id = ?
          AND YEAR(created_at)  = YEAR(CURDATE())
          AND MONTH(created_at) = MONTH(CURDATE())
    ");
    $stmt->execute([$creator_id]);
    $month_earned = (float)$stmt->fetchColumn();
} catch (PDOException $e) { $month_earned = 0.00; }

/* ── Paginated referred creators list ── */
$per_page    = 10;
$page        = max(1, (int)($_GET['rpage'] ?? 1));
$offset      = ($page - 1) * $per_page;
$total_pages = $total_referred > 0 ? (int)ceil($total_referred / $per_page) : 1;

try {
    $recStmt = $pdo->prepare(
        "SELECT username, full_name, date_created
         FROM creators
         WHERE CAST(referred_by AS UNSIGNED) = ?
         ORDER BY date_created DESC
         LIMIT ? OFFSET ?"
    );
    $recStmt->bindValue(1, (int)$creator_id, PDO::PARAM_INT);
    $recStmt->bindValue(2, (int)$per_page,   PDO::PARAM_INT);
    $recStmt->bindValue(3, (int)$offset,     PDO::PARAM_INT);
    $recStmt->execute();
    $recent_refs = $recStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_refs = [];
    error_log("refer_creator.php referrals fetch error: " . $e->getMessage());
}

/* ── Recent earnings rows (last 10) ── */
try {
    $earnRowsStmt = $pdo->prepare("
        SELECT re.amount, re.status, re.created_at,
               c.username AS ref_username, c.full_name AS ref_fullname
        FROM referral_earnings re
        LEFT JOIN creators c ON c.id = re.referred_id
        WHERE re.referrer_id = ?
        ORDER BY re.created_at DESC
        LIMIT 10
    ");
    $earnRowsStmt->execute([$creator_id]);
    $earn_rows = $earnRowsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $earn_rows = []; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

/* ── DEBUG — remove once working ── */
if (isset($_GET['debug'])) {
    echo "<pre style='background:#111;color:#0f0;padding:1rem;font-size:0.85rem'>";
    echo "creator_id    : " . var_export($creator_id, true) . "\n";
    echo "ref_code      : " . var_export($ref_code, true) . "\n";
    echo "total_referred: $total_referred\n";
    echo "total_earned  : $total_earned\n";
    echo "paid_earned   : $paid_earned\n";
    echo "pending_earned: $pending_earned\n";
    echo "month_earned  : $month_earned\n";
    $raw = $pdo->prepare("SELECT id, username, referred_by FROM creators WHERE CAST(referred_by AS UNSIGNED) = ? LIMIT 5");
    $raw->execute([$creator_id]);
    echo "Referred creators (raw):\n"; print_r($raw->fetchAll(PDO::FETCH_ASSOC));
    $rawE = $pdo->prepare("SELECT id, referrer_id, referred_id, amount, status FROM referral_earnings WHERE referrer_id = ? LIMIT 5");
    $rawE->execute([$creator_id]);
    echo "Earnings rows (raw):\n"; print_r($rawE->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>"; exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Refer &amp; Earn — Keychele</title>

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
    --bg:      #eef1f8; --card:   #ffffff;
    --border:  rgba(5,46,100,0.1);
    --text:    #0f1923; --muted:  #6b7a99;
    --success: #00b37e; --warning:#f59e0b;
    --purple:  #5c1a8a; --purple-l:#7c2fb0;
    --r: 14px; --r-lg: 20px;
    --sh:    0 4px 24px rgba(5,46,100,0.10);
    --sh-lg: 0 12px 48px rgba(5,46,100,0.14);
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'GlacialI', 'Trebuchet MS', sans-serif;
    background: var(--bg); color: var(--text);
    -webkit-font-smoothing: antialiased; padding-bottom: 80px;
  }
  h1,h2,h3,h4,h5,.mf { font-family: 'Montserrat', sans-serif; }
  .page { padding: 1.5rem; max-width: 960px; margin: 0 auto; }
  @media (max-width:600px) { .page { padding: 1rem 0.9rem; } }

  /* ── KEYFRAMES ── */
  @keyframes heroIn  { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
  @keyframes cardIn  { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
  @keyframes pulseDot{ 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.4;transform:scale(0.7)} }
  @keyframes pulseGold {
    0%,100%{box-shadow:0 0 0 0 rgba(254,196,17,0.4)}
    50%    {box-shadow:0 0 0 10px rgba(254,196,17,0)}
  }

  /* ── HERO ── */
  .page-hero {
    position:relative; overflow:hidden;
    background:linear-gradient(120deg,#3a0a5c 0%,#5c1a8a 45%,#7c2fb0 100%);
    border-radius:var(--r-lg); padding:2rem 2.25rem; margin-bottom:1.75rem;
    box-shadow:var(--sh-lg),0 0 0 1px rgba(255,255,255,0.05) inset;
    animation:heroIn 0.55s cubic-bezier(0.16,1,0.3,1) both;
  }
  .page-hero::before {
    content:''; position:absolute; inset:0; pointer-events:none;
    background-image:linear-gradient(rgba(255,255,255,0.04) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(255,255,255,0.04) 1px,transparent 1px);
    background-size:34px 34px;
  }
  .ph-orb1{position:absolute;border-radius:50%;pointer-events:none;width:300px;height:300px;top:-110px;right:-70px;background:radial-gradient(circle,rgba(254,196,17,0.18) 0%,transparent 65%)}
  .ph-orb2{position:absolute;border-radius:50%;pointer-events:none;width:200px;height:200px;bottom:-80px;left:20%;background:radial-gradient(circle,rgba(191,19,57,0.2) 0%,transparent 65%)}
  .ph-orb3{position:absolute;border-radius:50%;pointer-events:none;width:150px;height:150px;top:10px;left:-40px;background:radial-gradient(circle,rgba(124,47,176,0.5) 0%,transparent 70%)}
  .ph-inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:1rem}
  .ph-tag{display:inline-flex;align-items:center;gap:6px;background:rgba(254,196,17,0.15);border:1px solid rgba(254,196,17,0.3);border-radius:100px;padding:4px 12px;font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;letter-spacing:0.09em;text-transform:uppercase;color:var(--gold);margin-bottom:0.7rem}
  .ph-title{font-family:'Montserrat',sans-serif;font-weight:900;font-size:clamp(1.4rem,3vw,2rem);color:white;letter-spacing:-0.04em;line-height:1.15;margin-bottom:0.4rem}
  .ph-sub{font-size:0.83rem;color:rgba(255,255,255,0.55)}
  .ph-icon{font-size:3.5rem;color:rgba(255,255,255,0.12);flex-shrink:0}
  @media(max-width:480px){.ph-icon{display:none}}

  /* ── THANK YOU BANNER ── */
  .thankyou-banner {
    background:linear-gradient(135deg,rgba(254,196,17,0.12) 0%,rgba(254,196,17,0.05) 100%);
    border:1.5px solid rgba(254,196,17,0.35); border-radius:var(--r-lg);
    padding:1.15rem 1.5rem; margin-bottom:1.5rem;
    display:flex; align-items:flex-start; gap:14px;
    animation:heroIn 0.55s cubic-bezier(0.16,1,0.3,1) 0.1s both;
    box-shadow:0 4px 20px rgba(254,196,17,0.1);
  }
  .ty-icon{width:44px;height:44px;border-radius:12px;background:rgba(254,196,17,0.18);border:1.5px solid rgba(254,196,17,0.3);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--gold);flex-shrink:0;margin-top:2px}
  .ty-title{font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.9rem;color:#92650a;margin-bottom:4px}
  .ty-text{font-size:0.82rem;color:#a07820;line-height:1.65}
  .ty-text strong{font-family:'Montserrat',sans-serif;font-weight:800;color:#7a5010}

  /* ── STATS ROW — 4 cards ── */
  .stats-row {
    display:grid; grid-template-columns:repeat(4,1fr);
    gap:1rem; margin-bottom:1.5rem;
    animation:heroIn 0.55s cubic-bezier(0.16,1,0.3,1) 0.15s both;
  }
  @media(max-width:720px){ .stats-row{grid-template-columns:repeat(2,1fr)} }
  @media(max-width:380px){ .stats-row{grid-template-columns:1fr} }

  .stat-card {
    background:var(--card); border-radius:var(--r-lg);
    border:1px solid var(--border); box-shadow:var(--sh);
    padding:1.2rem 1.3rem; position:relative; overflow:hidden;
    transition:transform 0.2s,box-shadow 0.2s;
  }
  .stat-card:hover{transform:translateY(-3px);box-shadow:var(--sh-lg)}
  .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r-lg) var(--r-lg) 0 0}
  .stat-card::after {content:'';position:absolute;bottom:-20px;right:-20px;width:70px;height:70px;border-radius:50%;opacity:0.1;pointer-events:none}

  /* Purple — Creators Referred */
  .sc-purple::before { background:linear-gradient(90deg,#7c2fb0,#a855f7) }
  .sc-purple::after  { background:#7c2fb0 }
  .sc-purple .stat-ico { background:rgba(124,47,176,0.1);color:#7c2fb0 }

  /* Green — Total Earned (lifetime) */
  .sc-green::before  { background:linear-gradient(90deg,#059669,var(--success)) }
  .sc-green::after   { background:var(--success) }
  .sc-green .stat-ico{ background:rgba(0,179,126,0.1);color:var(--success) }

  /* Navy — Paid to Wallet */
  .sc-navy::before   { background:linear-gradient(90deg,var(--navy),var(--navy-l)) }
  .sc-navy::after    { background:var(--navy) }
  .sc-navy .stat-ico { background:rgba(5,46,100,0.1);color:var(--navy) }

  /* Gold — Pending */
  .sc-gold::before   { background:linear-gradient(90deg,var(--gold-d),var(--gold)) }
  .sc-gold::after    { background:var(--gold-d) }
  .sc-gold .stat-ico { background:rgba(254,196,17,0.12);color:#b45309 }

  .stat-ico  { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:0.9rem;margin-bottom:0.75rem }
  .stat-val  { font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.35rem;letter-spacing:-0.04em;color:var(--text);line-height:1 }
  .stat-label{ font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--muted);margin-top:5px }
  .stat-sub  { font-size:0.69rem;color:var(--muted);margin-top:3px;line-height:1.5 }

  /* Pending pulse dot */
  .stat-pending-dot {
    display:inline-flex;align-items:center;gap:5px;
    background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.25);
    border-radius:100px;padding:2px 9px;margin-top:7px;
    font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:700;color:#b45309;
  }
  .stat-pending-dot::before {
    content:'';width:5px;height:5px;border-radius:50%;
    background:#d97706;animation:pulseDot 1.5s infinite;flex-shrink:0;
  }

  /* Wallet note on paid card */
  .stat-wallet-note {
    display:inline-flex;align-items:center;gap:5px;margin-top:7px;
    font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:700;
    color:var(--success);background:rgba(0,179,126,0.08);
    border:1px solid rgba(0,179,126,0.2);border-radius:100px;padding:2px 9px;
  }

  /* ── SECTION LABEL ── */
  .sec-label{font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--muted);display:flex;align-items:center;gap:8px;margin-bottom:1rem}
  .sec-label::before{content:'';width:3px;height:14px;border-radius:3px;background:linear-gradient(180deg,#7c2fb0,var(--gold));flex-shrink:0}

  /* ── CARD ── */
  .card{background:var(--card);border-radius:var(--r-lg);border:1px solid var(--border);box-shadow:var(--sh);overflow:hidden;margin-bottom:1.25rem;animation:cardIn 0.5s cubic-bezier(0.16,1,0.3,1) both}
  .card-head{padding:1.25rem 1.5rem 1rem;border-bottom:1px solid var(--border);background:#fafbfd;display:flex;align-items:center;gap:10px}
  .card-head-ico{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:0.88rem;flex-shrink:0}
  .card-head-title{font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.92rem;color:var(--text)}
  .card-head-sub{font-size:0.74rem;color:var(--muted);margin-top:2px}
  .card-head-right{margin-left:auto;font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:700;color:#7c2fb0;background:rgba(124,47,176,0.08);border:1px solid rgba(124,47,176,0.18);border-radius:100px;padding:4px 12px}
  .card-body{padding:1.5rem}

  /* ── REFERRAL LINK BOX ── */
  .ref-link-outer{
    background:linear-gradient(135deg,#3a0a5c 0%,#5c1a8a 60%,#6d22a3 100%);
    border-radius:var(--r-lg);padding:2rem;margin-bottom:1.25rem;
    box-shadow:var(--sh-lg);position:relative;overflow:hidden;
    animation:cardIn 0.5s cubic-bezier(0.16,1,0.3,1) 0.1s both;
  }
  .ref-link-outer::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);background-size:28px 28px}
  .rlo-orb1{position:absolute;width:220px;height:220px;border-radius:50%;top:-80px;right:-60px;background:radial-gradient(circle,rgba(254,196,17,0.14) 0%,transparent 65%);pointer-events:none}
  .rlo-orb2{position:absolute;width:160px;height:160px;border-radius:50%;bottom:-60px;left:5%;background:radial-gradient(circle,rgba(191,19,57,0.18) 0%,transparent 65%);pointer-events:none}
  .rlo-inner{position:relative;z-index:1}
  .rlo-top{display:flex;align-items:center;gap:12px;margin-bottom:1.5rem}
  .rlo-icon-wrap{width:52px;height:52px;border-radius:14px;background:rgba(254,196,17,0.15);border:1.5px solid rgba(254,196,17,0.3);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:var(--gold);flex-shrink:0}
  .rlo-title{font-family:'Montserrat',sans-serif;font-weight:900;font-size:1.1rem;color:white;letter-spacing:-0.03em;margin-bottom:3px}
  .rlo-sub{font-size:0.78rem;color:rgba(255,255,255,0.5)}

  /* ── COPY LINK BOX ── */
  .copy-box{background:rgba(255,255,255,0.07);border:1.5px solid rgba(255,255,255,0.14);border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px;margin-bottom:1.25rem;transition:border-color 0.2s}
  .copy-box:focus-within{border-color:rgba(254,196,17,0.5)}
  .copy-link-icon{width:36px;height:36px;border-radius:9px;background:rgba(254,196,17,0.15);display:flex;align-items:center;justify-content:center;font-size:0.8rem;color:var(--gold);flex-shrink:0}
  .copy-link-text{flex:1;font-size:0.8rem;color:rgba(255,255,255,0.85);font-family:'Montserrat',sans-serif;font-weight:600;word-break:break-all;line-height:1.5;user-select:all}
  .copy-btn-main{flex-shrink:0;display:flex;align-items:center;gap:7px;background:linear-gradient(135deg,var(--gold-d),var(--gold));color:#1a0a00;border:none;border-radius:10px;padding:11px 22px;font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.8rem;cursor:pointer;white-space:nowrap;transition:transform 0.18s,box-shadow 0.18s;box-shadow:0 4px 14px rgba(254,196,17,0.35)}
  .copy-btn-main:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(254,196,17,0.5)}
  .copy-btn-main.copied{background:linear-gradient(135deg,#059669,var(--success));color:#fff;box-shadow:0 4px 14px rgba(0,179,126,0.4)}
  @media(max-width:520px){.copy-box{flex-wrap:wrap}.copy-btn-main{width:100%;justify-content:center}}

  /* ── SOCIAL SHARE ── */
  .share-label{font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.4);margin-bottom:0.75rem}
  .social-row{display:flex;gap:9px;flex-wrap:wrap}
  .soc-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:10px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.78rem;cursor:pointer;border:none;text-decoration:none;transition:transform 0.15s,box-shadow 0.15s}
  .soc-btn:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,0.25)}
  .soc-wa   { background:#25d366;color:#fff }
  .soc-fb   { background:#1877f2;color:#fff }
  .soc-tw   { background:#000;color:#fff }
  .soc-email{ background:rgba(255,255,255,0.12);color:rgba(255,255,255,0.85);border:1px solid rgba(255,255,255,0.18) }
  .soc-email:hover{background:rgba(255,255,255,0.2)}
  .soc-sms  { background:rgba(37,211,102,0.15);color:#25d366;border:1px solid rgba(37,211,102,0.3) }
  .soc-sms:hover{background:rgba(37,211,102,0.25)}

  /* ── HOW IT WORKS ── */
  .how-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem}
  @media(max-width:620px){.how-grid{grid-template-columns:1fr}}
  .how-step{background:#fafbfd;border:1px solid var(--border);border-radius:var(--r);padding:1.25rem;text-align:center;position:relative;transition:transform 0.2s,box-shadow 0.2s}
  .how-step:hover{transform:translateY(-3px);box-shadow:var(--sh)}
  @media(min-width:621px){
    .how-step::after{content:'→';position:absolute;right:-14px;top:50%;transform:translateY(-50%);font-size:1.2rem;color:var(--muted);font-family:'Montserrat',sans-serif;font-weight:900;z-index:2}
    .how-step:last-child::after{display:none}
  }
  .how-step-num{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#5c1a8a,#7c2fb0);color:white;font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.88rem;display:flex;align-items:center;justify-content:center;margin:0 auto 0.85rem;box-shadow:0 4px 14px rgba(124,47,176,0.35)}
  .how-step-ico{font-size:1.3rem;margin-bottom:0.5rem;display:block}
  .how-step-title{font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.82rem;color:var(--text);margin-bottom:0.35rem}
  .how-step-text{font-size:0.74rem;color:var(--muted);line-height:1.6}

  /* ── EARNINGS INFO ── */
  .earn-info{background:linear-gradient(135deg,rgba(5,46,100,0.05),rgba(5,46,100,0.02));border:1.5px solid rgba(5,46,100,0.12);border-radius:var(--r);padding:1.1rem 1.25rem;display:flex;align-items:flex-start;gap:14px}
  .earn-info-ico{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,rgba(5,46,100,0.1),rgba(5,46,100,0.05));display:flex;align-items:center;justify-content:center;font-size:0.95rem;color:var(--navy);flex-shrink:0;margin-top:2px}
  .earn-info-title{font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.82rem;color:var(--navy);margin-bottom:4px}
  .earn-info-text{font-size:0.78rem;color:var(--muted);line-height:1.7}
  .earn-info-text strong{font-family:'Montserrat',sans-serif;font-weight:700;color:var(--navy)}

  /* ── WALLET NOTE ── */
  .wallet-note {
    display:flex;align-items:flex-start;gap:13px;
    background:linear-gradient(120deg,rgba(5,46,100,0.05),rgba(5,46,100,0.02));
    border:1.5px solid rgba(5,46,100,0.12);
    border-radius:var(--r);padding:1rem 1.25rem;margin-top:1.25rem;
  }
  .wn-icon{width:38px;height:38px;flex-shrink:0;border-radius:10px;background:linear-gradient(135deg,var(--purple),var(--purple-l));display:flex;align-items:center;justify-content:center;font-size:0.88rem;color:white;box-shadow:0 3px 10px rgba(92,26,138,0.3)}
  .wn-title{font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.8rem;color:var(--navy);margin-bottom:3px}
  .wn-text{font-size:0.75rem;color:var(--muted);line-height:1.65}
  .wn-text strong{font-family:'Montserrat',sans-serif;font-weight:700;color:var(--navy)}
  .wn-pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
  .wn-pill{display:inline-flex;align-items:center;gap:5px;border-radius:8px;padding:4px 10px;font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700}
  .wn-pill-g{background:rgba(0,179,126,0.1);color:var(--success);border:1px solid rgba(0,179,126,0.2)}
  .wn-pill-p{background:rgba(92,26,138,0.08);color:var(--purple);border:1px solid rgba(92,26,138,0.18)}
  .wn-pill-y{background:rgba(245,158,11,0.1);color:#d97706;border:1px solid rgba(245,158,11,0.25)}

  /* ── EARNINGS HISTORY TABLE ── */
  .earn-table{width:100%;border-collapse:collapse}
  .earn-table thead tr{background:#f4f7ff}
  .earn-table th{padding:10px 14px;text-align:left;font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:var(--muted);border-bottom:1.5px solid var(--border)}
  .earn-table td{padding:11px 14px;font-size:0.82rem;color:var(--text);border-bottom:1px solid var(--border)}
  .earn-table tbody tr:last-child td{border-bottom:none}
  .earn-table tbody tr{transition:background 0.15s}
  .earn-table tbody tr:hover{background:#f8faff}
  .earn-amount{font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.88rem}
  .earn-amount.positive{color:var(--success)}

  /* Status badges */
  .bdg{display:inline-flex;align-items:center;gap:5px;font-family:'Montserrat',sans-serif;font-size:0.6rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;padding:3px 9px;border-radius:100px;white-space:nowrap}
  .bdg::before{content:'';width:5px;height:5px;border-radius:50%;flex-shrink:0}
  .b-paid     {background:rgba(0,179,126,0.1);color:var(--success);border:1px solid rgba(0,179,126,0.2)}
  .b-paid::before{background:var(--success)}
  .b-completed{background:rgba(0,179,126,0.1);color:var(--success);border:1px solid rgba(0,179,126,0.2)}
  .b-completed::before{background:var(--success)}
  .b-credited {background:rgba(5,46,100,0.08);color:var(--navy-l);border:1px solid rgba(5,46,100,0.15)}
  .b-credited::before{background:var(--navy-l)}
  .b-pending  {background:rgba(245,158,11,0.1);color:var(--warning);border:1px solid rgba(245,158,11,0.2)}
  .b-pending::before{background:var(--warning);animation:pulseDot 1.5s infinite}
  .b-failed   {background:rgba(191,19,57,0.08);color:var(--red);border:1px solid rgba(191,19,57,0.2)}
  .b-failed::before{background:var(--red)}

  /* ── REFERRALS TABLE ── */
  .ref-table{width:100%;border-collapse:collapse}
  .ref-table thead tr{background:#f4f7ff}
  .ref-table th{padding:10px 14px;text-align:left;font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:var(--muted);border-bottom:1.5px solid var(--border)}
  .ref-table td{padding:11px 14px;font-size:0.83rem;color:var(--text);border-bottom:1px solid var(--border)}
  .ref-table tbody tr:last-child td{border-bottom:none}
  .ref-table tbody tr{transition:background 0.15s}
  .ref-table tbody tr:hover{background:#f8faff}
  .ref-avatar{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#5c1a8a,#7c2fb0);display:inline-flex;align-items:center;justify-content:center;font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.72rem;color:white;margin-right:10px;vertical-align:middle;flex-shrink:0}
  .ref-name-wrap{display:flex;align-items:center}
  .ref-username{font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.82rem;color:var(--text)}
  .ref-handle{font-size:0.7rem;color:var(--muted);margin-top:1px}
  .ref-date{font-size:0.75rem;color:var(--muted);white-space:nowrap}
  .ref-badge-new{display:inline-flex;align-items:center;gap:4px;background:rgba(0,179,126,0.1);border:1px solid rgba(0,179,126,0.25);border-radius:100px;padding:2px 8px;font-family:'Montserrat',sans-serif;font-size:0.62rem;font-weight:700;color:var(--success)}

  /* Empty state */
  .empty-refs{text-align:center;padding:3rem 1rem;color:var(--muted)}
  .empty-refs-ico{width:64px;height:64px;border-radius:18px;background:rgba(124,47,176,0.08);border:1.5px solid rgba(124,47,176,0.15);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:rgba(124,47,176,0.4);margin:0 auto 1rem}
  .empty-refs p{font-family:'Montserrat',sans-serif;font-size:0.85rem;font-weight:700;color:var(--muted);margin-bottom:0.35rem}
  .empty-refs small{font-size:0.74rem;color:var(--muted);opacity:0.7}

  /* ── PAGINATION ── */
  .pagination{display:flex;align-items:center;justify-content:center;gap:6px;padding:1.25rem 1rem;border-top:1px solid var(--border)}
  .pag-btn{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;border-radius:8px;font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.78rem;text-decoration:none;color:var(--muted);background:#f4f7ff;border:1px solid var(--border);transition:all 0.18s;padding:0 10px}
  .pag-btn:hover{background:rgba(124,47,176,0.08);border-color:rgba(124,47,176,0.25);color:#7c2fb0}
  .pag-btn.active{background:linear-gradient(135deg,#5c1a8a,#7c2fb0);color:white;border-color:transparent;box-shadow:0 4px 12px rgba(124,47,176,0.3)}
  .pag-btn.disabled{opacity:0.35;pointer-events:none}
  .pag-info{font-family:'Montserrat',sans-serif;font-size:0.72rem;font-weight:600;color:var(--muted);margin:0 8px}

  /* ── TOAST ── */
  #toast-container>.toast{border-radius:11px !important;box-shadow:0 8px 32px rgba(0,0,0,0.14) !important;font-family:'GlacialI',sans-serif !important;font-size:0.875rem !important;padding:14px 18px 14px 54px !important}
  #toast-container>.toast-success{background-color:#064E3B !important;border-left:4px solid #10B981 !important}
  #toast-container>.toast-info{background-color:var(--navy) !important;border-left:4px solid var(--gold) !important}
  .c-toast{position:fixed;bottom:22px;right:22px;z-index:9999;display:flex;align-items:center;gap:9px;padding:13px 18px;border-radius:12px;font-size:0.85rem;font-weight:600;box-shadow:0 8px 28px rgba(0,0,0,.12);transform:translateY(14px);opacity:0;transition:all .28s cubic-bezier(.34,1.5,.64,1);pointer-events:none;border:1px solid;font-family:'Montserrat',sans-serif}
  .c-toast.show{transform:translateY(0);opacity:1}
  .c-toast-s{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}

  /* ── QR HINT ── */
  .qr-hint{display:inline-flex;align-items:center;gap:6px;background:rgba(124,47,176,0.08);border:1px solid rgba(124,47,176,0.2);border-radius:100px;padding:5px 13px;font-family:'Montserrat',sans-serif;font-size:0.7rem;font-weight:700;color:#7c2fb0;margin-top:0.75rem;cursor:pointer;transition:background 0.18s}
  .qr-hint:hover{background:rgba(124,47,176,0.15)}
  .pulse-gold{animation:pulseGold 2.5s ease-in-out infinite}
  </style>
</head>
<body>

<div id="cToast" class="c-toast c-toast-s">
  <i class="fa-solid fa-circle-check"></i>
  <span id="cToastMsg">Link copied!</span>
</div>

<div class="page">

  <!-- ── HERO ── -->
  <div class="page-hero">
    <div class="ph-orb1"></div>
    <div class="ph-orb2"></div>
    <div class="ph-orb3"></div>
    <div class="ph-inner">
      <div>
        <div class="ph-tag"><i class="fa-solid fa-user-plus"></i> Referral Program</div>
        <h1 class="ph-title">Refer &amp; Earn</h1>
        <p class="ph-sub">Invite creators · Earn 1% of every ticket they sell · Forever</p>
      </div>
      <div class="ph-icon"><i class="fa-solid fa-people-arrows"></i></div>
    </div>
  </div>

  <!-- ── THANK YOU NOTE ── -->
  <div class="thankyou-banner">
    <div class="ty-icon"><i class="fa-solid fa-heart"></i></div>
    <div>
      <div class="ty-title">Thank you for choosing Keychele, <?= h($full_name) ?>!</div>
      <div class="ty-text">
        Refer other creators and <strong>earn 1% of every ticket sold</strong> by every creator you bring on board — for life.
        There's no limit on how many creators you can refer or how much you can earn.
        Share your unique link on social media, WhatsApp, or anywhere your audience hangs out.
      </div>
    </div>
  </div>

  <!-- ── STATS — 4 cards ── -->
  <div class="stats-row">

    <!-- Creators Referred -->
    <div class="stat-card sc-purple">
      <div class="stat-ico"><i class="fa-solid fa-users"></i></div>
      <div class="stat-val"><?= number_format($total_referred) ?></div>
      <div class="stat-label">Creators Referred</div>
      <div class="stat-sub">Total sign-ups via your link</div>
    </div>

    <!-- Total Earned (ALL statuses — grand lifetime total) -->
    <div class="stat-card sc-green">
      <div class="stat-ico"><i class="fa-solid fa-trophy"></i></div>
      <div class="stat-val">KSh <?= number_format($total_earned, 2) ?></div>
      <div class="stat-label">Total Earned</div>
      <div class="stat-sub">Lifetime referral commissions</div>
    </div>

    <!-- Paid to Wallet (credited/paid status only) -->
    <div class="stat-card sc-navy">
      <div class="stat-ico"><i class="fa-solid fa-vault"></i></div>
      <div class="stat-val">KSh <?= number_format($paid_earned, 2) ?></div>
      <div class="stat-label">Paid to Wallet</div>
      <div class="stat-sub">Added to your balance</div>
      <div class="stat-wallet-note"><i class="fa-solid fa-circle-check" style="font-size:0.55rem"></i> In your withdrawable balance</div>
    </div>

    <!-- Pending Payout -->
    <div class="stat-card sc-gold">
      <div class="stat-ico"><i class="fa-solid fa-hourglass-half"></i></div>
      <div class="stat-val">KSh <?= number_format($pending_earned, 2) ?></div>
      <div class="stat-label">Pending Payout</div>
      <div class="stat-sub">Awaiting disbursement to wallet</div>
      <?php if ($pending_earned > 0): ?>
      <div class="stat-pending-dot">Processing — credited soon</div>
      <?php endif; ?>
    </div>

  </div>

  <!-- ── YOUR REFERRAL LINK ── -->
  <div class="sec-label">Your Unique Referral Link</div>
  <div class="ref-link-outer">
    <div class="rlo-orb1"></div>
    <div class="rlo-orb2"></div>
    <div class="rlo-inner">
      <div class="rlo-top">
        <div class="rlo-icon-wrap"><i class="fa-solid fa-link"></i></div>
        <div>
          <div class="rlo-title">Your Referral Link</div>
          <div class="rlo-sub">Share this link — anyone who registers through it is credited to you forever</div>
        </div>
      </div>

      <div class="copy-box">
        <div class="copy-link-icon"><i class="fa-solid fa-link"></i></div>
        <span class="copy-link-text" id="referralUrl"><?= h($refer_link) ?></span>
        <button class="copy-btn-main pulse-gold" id="mainCopyBtn" onclick="copyReferLink()">
          <i class="fa-solid fa-copy"></i> Copy Link
        </button>
      </div>

      <div class="share-label">Share directly via</div>
      <?php
        $share_text  = "Join me on Keychele — Kenya's ticketing platform for creators! Sell your event tickets with M-Pesa checkout. Sign up here:";
        $enc_url     = urlencode($refer_link);
        $enc_text    = urlencode($share_text . ' ');
        $enc_subject = urlencode("Join Keychele — Start Selling Event Tickets!");
        $enc_body    = urlencode("Hey!\n\nI'm on Keychele, a great platform for event creators in Kenya. You can sell tickets and collect payments via M-Pesa.\n\nSign up here: " . $refer_link);
      ?>
      <div class="social-row">
        <a href="https://wa.me/?text=<?= $enc_text . $enc_url ?>" target="_blank" rel="noopener" class="soc-btn soc-wa">
          <i class="fa-brands fa-whatsapp"></i> WhatsApp
        </a>
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $enc_url ?>" target="_blank" rel="noopener" class="soc-btn soc-fb">
          <i class="fa-brands fa-facebook-f"></i> Facebook
        </a>
        <a href="https://twitter.com/intent/tweet?text=<?= $enc_text ?>&url=<?= $enc_url ?>" target="_blank" rel="noopener" class="soc-btn soc-tw">
          <i class="fa-brands fa-x-twitter"></i> X / Twitter
        </a>
        <a href="mailto:?subject=<?= $enc_subject ?>&body=<?= $enc_body ?>" class="soc-btn soc-email">
          <i class="fa-solid fa-envelope"></i> Email
        </a>
        <a href="sms:?body=<?= $enc_text . $enc_url ?>" class="soc-btn soc-sms">
          <i class="fa-solid fa-comment-sms"></i> SMS
        </a>
      </div>

      <div>
        <span class="qr-hint" onclick="toastr.info('QR code feature coming soon!','',{timeOut:2500})">
          <i class="fa-solid fa-qrcode"></i> Generate QR Code (coming soon)
        </span>
      </div>
    </div>
  </div>

  <!-- ── HOW IT WORKS ── -->
  <div class="sec-label">How It Works</div>
  <div class="card" style="margin-bottom:1.25rem">
    <div class="card-head">
      <div class="card-head-ico" style="background:rgba(124,47,176,0.1);color:#7c2fb0"><i class="fa-solid fa-circle-info"></i></div>
      <div>
        <div class="card-head-title">Referral Program Explained</div>
        <div class="card-head-sub">Simple steps to start earning passive income</div>
      </div>
    </div>
    <div class="card-body">
      <div class="how-grid">
        <div class="how-step">
          <div class="how-step-num">1</div>
          <span class="how-step-ico">🔗</span>
          <div class="how-step-title">Share Your Link</div>
          <div class="how-step-text">Copy your unique link and post it on WhatsApp, Instagram, Facebook, or anywhere your creator friends hang out.</div>
        </div>
        <div class="how-step">
          <div class="how-step-num">2</div>
          <span class="how-step-ico">🎉</span>
          <div class="how-step-title">They Sign Up</div>
          <div class="how-step-text">When a new creator registers via your link, they are automatically linked to your account as your referral — forever.</div>
        </div>
        <div class="how-step">
          <div class="how-step-num">3</div>
          <span class="how-step-ico">💰</span>
          <div class="how-step-title">You Earn 1%</div>
          <div class="how-step-text">Every time they sell a ticket, you earn <strong>1%</strong> of the sale value — automatically credited to your Keychele wallet.</div>
        </div>
      </div>

      <div style="margin-top:1.25rem">
        <div class="earn-info">
          <div class="earn-info-ico"><i class="fa-solid fa-percent"></i></div>
          <div>
            <div class="earn-info-title">Your Earnings Breakdown</div>
            <div class="earn-info-text">
              For every ticket sold by a creator you referred:
              <strong>You earn 1% of the ticket price</strong>.
              For example — if they sell a KSh 1,000 ticket, you earn <strong>KSh 10</strong>.
              If that creator sells 200 tickets at KSh 1,500 each, that's <strong>KSh 3,000</strong> straight to your wallet — without lifting a finger.
              Referral earnings are tracked in real-time and paid out with your regular earnings.
              <br><br>
              <strong>There is no cap</strong> on how many creators you can refer or how much you can earn.
            </div>
          </div>
        </div>
      </div>

      <!-- Wallet note -->
      <div class="wallet-note">
        <div class="wn-icon"><i class="fa-solid fa-vault"></i></div>
        <div>
          <div class="wn-title">All Referral Earnings Are Added to Your Wallet Balance</div>
          <div class="wn-text">
            Every commission you earn is <strong>automatically credited to your Keychele wallet</strong> the moment a referred creator's ticket sale is confirmed.
            You can withdraw your combined earnings — ticket revenue, support, and referral commissions — at any time.
          </div>
          <div class="wn-pills">
            <span class="wn-pill wn-pill-g"><i class="fa-solid fa-bolt" style="font-size:0.55rem"></i> Credited instantly on sale</span>
            <span class="wn-pill wn-pill-p"><i class="fa-solid fa-wallet" style="font-size:0.55rem"></i> Part of your withdrawable balance</span>
            <?php if ($pending_earned > 0): ?>
            <span class="wn-pill wn-pill-y"><i class="fa-solid fa-hourglass-half" style="font-size:0.55rem"></i> KSh <?= number_format($pending_earned, 2) ?> pending — processing shortly</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ── RECENT EARNINGS HISTORY ── -->
  <?php if (!empty($earn_rows)): ?>
  <div class="sec-label">Recent Earnings</div>
  <div class="card" style="margin-bottom:1.25rem">
    <div class="card-head">
      <div class="card-head-ico" style="background:rgba(0,179,126,0.1);color:var(--success)"><i class="fa-solid fa-coins"></i></div>
      <div>
        <div class="card-head-title">Earnings History</div>
        <div class="card-head-sub">Latest 10 referral commission entries</div>
      </div>
      <div class="card-head-right">KSh <?= number_format($total_earned, 2) ?> lifetime</div>
    </div>
    <div style="padding:0;overflow-x:auto">
      <table class="earn-table">
        <thead>
          <tr>
            <th>From Creator</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($earn_rows as $er):
            $ref_name = trim(($er['ref_fullname'] ?? '') ?: ($er['ref_username'] ?? ''));
            $ref_init = strtoupper(substr($ref_name ?: '?', 0, 2));
            $status   = strtolower($er['status'] ?? 'pending');
            $bdg_cls  = match($status) {
              'paid','completed' => 'b-paid',
              'credited'         => 'b-credited',
              'pending'          => 'b-pending',
              default            => 'b-pending'
            };
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <div class="ref-avatar" style="margin:0"><?= h($ref_init) ?></div>
                <div>
                  <div style="font-family:'Montserrat',sans-serif;font-weight:700;font-size:0.81rem"><?= h($ref_name ?: 'Unknown') ?></div>
                  <?php if (!empty($er['ref_username'])): ?>
                  <div style="font-size:0.69rem;color:var(--muted)">@<?= h($er['ref_username']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td><span class="earn-amount positive">+ KSh <?= number_format((float)$er['amount'], 2) ?></span></td>
            <td><span class="bdg <?= $bdg_cls ?>"><?= ucfirst($status) ?></span></td>
            <td style="color:var(--muted);font-size:0.72rem;white-space:nowrap">
              <i class="fa-regular fa-clock" style="margin-right:4px;opacity:0.5"></i>
              <?= date('d M Y, H:i', strtotime($er['created_at'])) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── ALL REFERRALS TABLE ── -->
  <div class="sec-label">Creators You've Referred</div>
  <div class="card">
    <div class="card-head">
      <div class="card-head-ico" style="background:rgba(0,179,126,0.1);color:var(--success)"><i class="fa-solid fa-users"></i></div>
      <div>
        <div class="card-head-title">All Referred Creators</div>
        <div class="card-head-sub">Creators who joined Keychele through your link</div>
      </div>
      <?php if ($total_referred > 0): ?>
      <div class="card-head-right"><?= number_format($total_referred) ?> total</div>
      <?php endif; ?>
    </div>
    <div style="padding:0">
      <?php if (!empty($recent_refs)): ?>
      <table class="ref-table">
        <thead>
          <tr>
            <th>Creator</th>
            <th>Joined</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $seven_days_ago = strtotime('-7 days');
          foreach ($recent_refs as $ref):
            $initials  = strtoupper(substr($ref['full_name'] ?: $ref['username'], 0, 2));
            $joined_ts = strtotime($ref['date_created']);
            $joined    = date('d M Y', $joined_ts);
            $is_new    = $joined_ts >= $seven_days_ago;
          ?>
          <tr>
            <td>
              <div class="ref-name-wrap">
                <div class="ref-avatar"><?= h($initials) ?></div>
                <div>
                  <div style="display:flex;align-items:center;gap:7px;">
                    <span class="ref-username"><?= h($ref['full_name'] ?: $ref['username']) ?></span>
                    <?php if ($is_new): ?>
                    <span class="ref-badge-new"><i class="fa-solid fa-bolt" style="font-size:0.55rem"></i> New</span>
                    <?php endif; ?>
                  </div>
                  <div class="ref-handle">@<?= h($ref['username']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <span class="ref-date">
                <i class="fa-regular fa-calendar" style="margin-right:5px;opacity:0.5"></i><?= $joined ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- ── PAGINATION ── -->
      <?php if ($total_pages > 1): ?>
      <div class="pagination">
        <a href="?rpage=<?= $page - 1 ?>" class="pag-btn <?= $page <= 1 ? 'disabled' : '' ?>">
          <i class="fa-solid fa-chevron-left" style="font-size:0.7rem"></i>
        </a>

        <?php
          $ws = max(1, $page - 2);
          $we = min($total_pages, $page + 2);
          if ($ws > 1): ?>
            <a href="?rpage=1" class="pag-btn">1</a>
            <?php if ($ws > 2): ?><span class="pag-info">…</span><?php endif; ?>
          <?php endif; ?>

          <?php for ($i = $ws; $i <= $we; $i++): ?>
            <a href="?rpage=<?= $i ?>" class="pag-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>

          <?php if ($we < $total_pages): ?>
            <?php if ($we < $total_pages - 1): ?><span class="pag-info">…</span><?php endif; ?>
            <a href="?rpage=<?= $total_pages ?>" class="pag-btn"><?= $total_pages ?></a>
          <?php endif; ?>

        <a href="?rpage=<?= $page + 1 ?>" class="pag-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
          <i class="fa-solid fa-chevron-right" style="font-size:0.7rem"></i>
        </a>

        <span class="pag-info">
          <?= (($page - 1) * $per_page) + 1 ?>–<?= min($page * $per_page, $total_referred) ?> of <?= number_format($total_referred) ?>
        </span>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <div class="empty-refs">
        <div class="empty-refs-ico"><i class="fa-solid fa-user-plus"></i></div>
        <p>No referrals yet</p>
        <small>Share your referral link — creators who sign up through it will appear here</small>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /page -->

<script>
function copyReferLink() {
  var url = document.getElementById('referralUrl').textContent.trim();
  var btn = document.getElementById('mainCopyBtn');

  function onSuccess() {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
    btn.classList.add('copied');
    btn.classList.remove('pulse-gold');
    showCToast('Referral link copied to clipboard!');
    setTimeout(function () {
      btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy Link';
      btn.classList.remove('copied');
      btn.classList.add('pulse-gold');
    }, 2600);
  }

  function fallback() {
    try {
      var ta = document.createElement('textarea');
      ta.value = url; ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
      document.body.appendChild(ta); ta.focus(); ta.select();
      ta.setSelectionRange(0, ta.value.length);
      if (document.execCommand('copy')) onSuccess();
      document.body.removeChild(ta);
    } catch (err) {}
  }

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(onSuccess).catch(fallback);
  } else { fallback(); }
}

function showCToast(msg) {
  var el = document.getElementById('cToast');
  document.getElementById('cToastMsg').textContent = msg;
  el.classList.add('show');
  setTimeout(function () { el.classList.remove('show'); }, 3000);
}

toastr.options = {
  closeButton: true, progressBar: true,
  positionClass: 'toast-top-right', timeOut: 5500,
  extendedTimeOut: 1500, showMethod: 'slideDown', hideMethod: 'fadeOut'
};
</script>

</body>
</html>