<?php
include 'msanii/includes/connect.php';


// Get creator_id from URL
$creator_id = $_GET['creator'] ?? '';
if (empty($creator_id) || !is_numeric($creator_id)) {
    die("<h1 style='text-align:center;margin-top:80px;font-family:sans-serif;color:#b81236;'>Invalid Request</h1>");
}

// ── FIX: pull full_name from creators table, not my_page ──
$stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(TRIM(c.full_name), ''), mp.full_name, CONCAT('@', c.username)) AS full_name,
           mp.profile_pic,
           c.username
    FROM my_page mp
    JOIN creators c ON mp.creator_id = c.id
    WHERE mp.creator_id = ?
    LIMIT 1
");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$creator) {
    die("<h1 style='text-align:center;margin-top:80px;font-family:sans-serif;'>Creator Not Found</h1>");
}

// Safe name — never null
$displayName = $creator['full_name'] ?? ('Creator');
$profilePic  = $creator['profile_pic'] ?? 'default.png';
$username    = $creator['username']    ?? '';
$initial     = strtoupper(substr($displayName, 0, 1));

// Handle form submission
$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name']  ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));

    if (empty($name) || empty($email)) {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            $check = $pdo->prepare("SELECT id FROM my_team WHERE creator_id = ? AND email = ?");
            $check->execute([$creator_id, $email]);

            if ($check->rowCount() > 0) {
                $error = "You're already in this tribe!";
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO my_team (creator_id, name, email, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $ins->execute([$creator_id, $name, $email]);
                $success = true;
            }
        } catch (Exception $e) {
            error_log("join-tribe error: " . $e->getMessage());
            $error = "Something went wrong. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Join <?= htmlspecialchars($displayName) ?>'s Tribe | Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
/* ═══════════════════════════════
   TOKENS
═══════════════════════════════ */
:root{
  --navy:    #032d5d;
  --navy-d:  #021e3f;
  --navy-m:  #0a4a99;
  --navy-l:  #e8eef8;
  --accent:  #b81236;
  --accent-d:#8f0e2a;
  --accent-l:#fce4eb;
  --gold:    #f5c842;
  --white:   #ffffff;
  --off:     #f4f6fc;
  --g100:    #eef1f9;
  --g200:    #dde3f0;
  --g400:    #8a97b8;
  --g600:    #4a5578;
  --ff:      'Playfair Display', Georgia, serif;
  --fb:      'DM Sans', system-ui, sans-serif;
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html,body{min-height:100%;font-family:var(--fb);-webkit-font-smoothing:antialiased;}

body{
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:24px 16px 48px;
  position:relative;
  overflow-x:hidden;
  background:var(--navy-d);
}

/* Animated BG mesh */
body::before{
  content:'';
  position:fixed;inset:0;
  background:
    radial-gradient(ellipse 80% 60% at 20% 30%, rgba(184,18,54,.22) 0%, transparent 65%),
    radial-gradient(ellipse 60% 70% at 80% 70%, rgba(10,74,153,.30) 0%, transparent 65%),
    radial-gradient(ellipse 50% 50% at 50% 50%, rgba(245,200,66,.06) 0%, transparent 70%);
  z-index:0;
  animation:meshshift 12s ease-in-out infinite alternate;
}
@keyframes meshshift{
  from{background-position:0% 0%;}
  to{background-position:100% 100%;}
}

/* Dot grid texture */
body::after{
  content:'';
  position:fixed;inset:0;
  background-image:radial-gradient(rgba(255,255,255,.045) 1px, transparent 1px);
  background-size:30px 30px;
  z-index:0;
  pointer-events:none;
}

/* ═══════════════════════════════
   CARD
═══════════════════════════════ */
.tribe-wrap{
  position:relative;z-index:1;
  width:100%;max-width:460px;
}

.card{
  background:var(--white);
  border-radius:24px;
  overflow:hidden;
  box-shadow:
    0 24px 80px rgba(2,30,63,.45),
    0 4px 16px rgba(0,0,0,.15);
}

/* ── TOP HEADER ── */
.card-head{
  position:relative;
  padding:44px 32px 70px;
  background:linear-gradient(135deg,var(--navy-d) 0%,var(--navy-m) 60%,var(--accent) 100%);
  text-align:center;
  overflow:hidden;
}
/* gold top bar */
.card-head::before{
  content:'';
  position:absolute;top:0;left:0;right:0;height:4px;
  background:linear-gradient(90deg,var(--gold),var(--accent),var(--gold));
}
/* dot texture on header */
.card-head::after{
  content:'';
  position:absolute;inset:0;
  background-image:radial-gradient(rgba(255,255,255,.06) 1px, transparent 1px);
  background-size:24px 24px;
  pointer-events:none;
}

.back-btn{
  position:absolute;top:20px;left:20px;z-index:2;
  width:38px;height:38px;border-radius:50%;
  background:rgba(255,255,255,.12);
  border:1.5px solid rgba(255,255,255,.2);
  display:flex;align-items:center;justify-content:center;
  color:rgba(255,255,255,.85);font-size:15px;
  text-decoration:none;
  transition:all .2s;
  backdrop-filter:blur(8px);
}
.back-btn:hover{background:rgba(255,255,255,.22);color:#fff;}

/* Fire badge top-right */
.fire-badge{
  position:absolute;top:20px;right:20px;z-index:2;
  background:rgba(245,200,66,.15);
  border:1px solid rgba(245,200,66,.3);
  border-radius:50px;
  padding:5px 12px;
  font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;
  color:var(--gold);
  backdrop-filter:blur(6px);
  display:flex;align-items:center;gap:6px;
}

/* Avatar */
.avatar-outer{
  position:relative;
  display:inline-block;
  margin-bottom:-56px;
  z-index:5;
}
.avatar-ring{
  width:110px;height:110px;
  border-radius:50%;
  padding:3px;
  background:linear-gradient(135deg,var(--gold),var(--accent));
  box-shadow:0 8px 32px rgba(0,0,0,.35);
  display:inline-block;
}
.avatar-ring img,
.avatar-ring .avatar-fb{
  width:100%;height:100%;
  border-radius:50%;object-fit:cover;
  border:4px solid var(--white);
  background:var(--navy-l);
}
.avatar-ring .avatar-fb{
  display:flex;align-items:center;justify-content:center;
  font-family:var(--ff);font-size:38px;font-weight:900;
  color:var(--navy);
}

/* ── BODY ── */
.card-body{
  padding:68px 32px 36px;
  text-align:center;
}

.join-eyebrow{
  display:inline-flex;align-items:center;gap:6px;
  background:var(--accent-l);
  border:1px solid rgba(184,18,54,.2);
  color:var(--accent);
  font-size:10px;font-weight:800;letter-spacing:2px;text-transform:uppercase;
  padding:5px 14px;border-radius:50px;
  margin-bottom:14px;
}

.join-title{
  font-family:var(--ff);
  font-size:clamp(22px,5vw,30px);
  font-weight:900;
  color:var(--navy);
  line-height:1.2;
  margin-bottom:10px;
}
.join-title .name{color:var(--accent);}

.join-sub{
  font-size:14px;color:var(--g600);
  line-height:1.7;max-width:340px;margin:0 auto 28px;
}

/* Perks row */
.perks{
  display:flex;justify-content:center;gap:6px;
  flex-wrap:wrap;margin-bottom:28px;
}
.perk{
  display:inline-flex;align-items:center;gap:5px;
  font-size:11px;font-weight:700;color:var(--navy);
  background:var(--navy-l);
  border:1px solid var(--g200);
  border-radius:50px;padding:5px 12px;
}
.perk i{color:var(--accent);font-size:10px;}

/* ── FORM ── */
.form-group{
  margin-bottom:18px;
  text-align:left;
}
.form-label{
  display:block;
  font-size:12px;font-weight:700;
  color:var(--navy);
  letter-spacing:.5px;text-transform:uppercase;
  margin-bottom:7px;
}
.form-input{
  width:100%;
  height:52px;
  padding:0 18px 0 46px;
  border:1.5px solid var(--g200);
  border-radius:12px;
  font-family:var(--fb);font-size:14px;
  color:var(--navy);background:#fafbff;
  outline:none;
  transition:border-color .2s,box-shadow .2s,background .2s;
}
.form-input:focus{
  border-color:var(--accent);
  background:var(--white);
  box-shadow:0 0 0 3px rgba(184,18,54,.1);
}
.form-input::placeholder{color:var(--g400);}

.input-wrap{position:relative;}
.input-icon{
  position:absolute;left:16px;top:50%;transform:translateY(-50%);
  color:var(--g400);font-size:14px;pointer-events:none;
  transition:color .2s;
}
.form-input:focus + .input-icon{color:var(--accent);}

/* Submit button */
.submit-btn{
  width:100%;height:56px;
  background:linear-gradient(135deg,var(--accent),var(--accent-d));
  color:var(--white);
  border:none;border-radius:14px;
  font-family:var(--fb);font-size:15px;font-weight:800;
  letter-spacing:.3px;
  display:flex;align-items:center;justify-content:center;gap:10px;
  cursor:pointer;
  box-shadow:0 6px 24px rgba(184,18,54,.4);
  transition:all .2s;
  margin-top:8px;
}
.submit-btn:hover{
  background:linear-gradient(135deg,var(--accent-d),#6e0920);
  transform:translateY(-2px);
  box-shadow:0 10px 32px rgba(184,18,54,.5);
}
.submit-btn:active{transform:translateY(0);}

.form-note{
  text-align:center;font-size:12px;color:var(--g400);
  margin-top:16px;
  display:flex;align-items:center;justify-content:center;gap:6px;
}
.form-note i{font-size:11px;}

/* ── FOOTER ── */
.page-footer{
  text-align:center;margin-top:24px;
  font-size:12px;color:rgba(255,255,255,.35);
}
.page-footer a{color:var(--gold);font-weight:700;text-decoration:none;}
.page-footer a:hover{text-decoration:underline;}

/* ── RESPONSIVE ── */
@media(max-width:480px){
  .card-body{padding:62px 20px 28px;}
  .card-head{padding:36px 20px 60px;}
}
</style>
</head>
<body>

<?php if ($success): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  Swal.fire({
    icon: 'success',
    title: '🔥 You\'re In!',
    html: '<p style="font-size:15px;color:#4a5578;">Welcome to <strong style="color:#032d5d;"><?= addslashes(htmlspecialchars($displayName)) ?></strong>\'s tribe! Get ready for exclusive content.</p>',
    timer: 3500,
    timerProgressBar: true,
    showConfirmButton: false,
    background: '#ffffff',
    iconColor: '#16a34a',
    customClass:{popup:'swal-tribe'}
  }).then(() => {
    window.location.href = '/<?= htmlspecialchars($username) ?>';
  });
});
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  Swal.fire({
    icon: 'error',
    title: 'Oops!',
    text: '<?= addslashes($error) ?>',
    confirmButtonColor: '#b81236',
    background: '#ffffff',
  });
});
</script>
<?php endif; ?>

<div class="tribe-wrap">
  <div class="card">

    <!-- Header -->
    <div class="card-head">
      <a href="/<?= htmlspecialchars($username) ?>" class="back-btn" title="Back to profile">
        <i class="fas fa-arrow-left"></i>
      </a>
      <div class="fire-badge">
        <i class="fas fa-fire"></i> Exclusive Tribe
      </div>

      <!-- Avatar -->
      <div class="avatar-outer">
        <div class="avatar-ring">
          <img src="msanii/uploads/<?= htmlspecialchars($profilePic) ?>"
               alt="<?= htmlspecialchars($displayName) ?>"
               onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
          <div class="avatar-fb" style="display:none;"><?= $initial ?></div>
        </div>
      </div>
    </div>

    <!-- Body -->
    <div class="card-body">

      <div class="join-eyebrow">
        <i class="fas fa-users"></i> Join the community
      </div>

      <h1 class="join-title">
        Join <span class="name"><?= htmlspecialchars($displayName) ?></span>'s Tribe
      </h1>

      <p class="join-sub">
        Get exclusive updates, behind-the-scenes content, and early access to new drops!
      </p>

      <!-- Perks -->
      <div class="perks">
        <span class="perk"><i class="fas fa-bolt"></i> Early Access</span>
        <span class="perk"><i class="fas fa-star"></i> Exclusive Content</span>
        <span class="perk"><i class="fas fa-bell"></i> Event Alerts</span>
      </div>

      <!-- Form -->
      <form method="POST">
        <div class="form-group">
          <label class="form-label">Your Full Name</label>
          <div class="input-wrap">
            <input type="text" name="name" required
                   class="form-input"
                   placeholder="e.g. Jane Doe"
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            <i class="fas fa-user input-icon"></i>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <div class="input-wrap">
            <input type="email" name="email" required
                   class="form-input"
                   placeholder="you@example.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            <i class="fas fa-envelope input-icon"></i>
          </div>
        </div>

        <button type="submit" class="submit-btn">
          <i class="fas fa-fire"></i>
          Join The Tribe Now
        </button>

        <p class="form-note">
          <i class="fas fa-lock"></i>
          We respect your privacy. Unsubscribe anytime.
        </p>
      </form>

    </div><!-- /card-body -->
  </div><!-- /card -->

  <div class="page-footer">
    <p>© Keychele <?= date('Y') ?> · <a href="index.php">Back to Keychele</a></p>
  </div>
</div>

</body>
</html>