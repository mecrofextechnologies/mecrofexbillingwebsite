<?php
include 'includes/connect.php';
// session_start(); already started in index.php
$creator_id = $_SESSION['creator_id'];

if (!is_dir("uploads")) mkdir("uploads", 0777, true);

/* ── FETCH CREATOR (always pull full_name from creators table) ── */
$creator_stmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(TRIM(full_name),''), CONCAT('@', username)) AS full_name,
           username
    FROM creators WHERE id = ?
");
$creator_stmt->execute([$creator_id]);
$creator   = $creator_stmt->fetch(PDO::FETCH_ASSOC);
$full_name = $creator['full_name'] ?? 'Creator';
$username  = $creator['username']  ?? '';
$page_link = $username;

/* ── FETCH EXISTING my_page ── */
$stmt = $pdo->prepare("SELECT * FROM my_page WHERE creator_id = ?");
$stmt->execute([$creator_id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

$currentPic    = $page['profile_pic'] ?? '';
$currentBanner = $page['banner']      ?? '';
$initial       = strtoupper(substr($full_name, 0, 1));

/* ── HANDLE POST ── */
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bio    = trim($_POST['bio']    ?? '');
    $phone  = trim($_POST['phone']  ?? '');
    $county = trim($_POST['county'] ?? '');

    $profile_pic = $page['profile_pic'] ?? null;
    $banner      = $page['banner']      ?? null;

    if (empty($bio) || empty($phone) || empty($county)) {
        $error_msg = "Please fill in all required fields (Bio, Phone, County).";
    } else {

        // Profile pic upload
        if (!empty($_FILES['profile_pic']['name'])) {
            $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $pic_name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], "uploads/$pic_name")) {
                    $profile_pic = $pic_name;
                    $currentPic  = $pic_name;
                }
            }
        }

        // Banner upload
        if (!empty($_FILES['banner']['name'])) {
            $ext = strtolower(pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $banner_name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['banner']['tmp_name'], "uploads/$banner_name")) {
                    $banner        = $banner_name;
                    $currentBanner = $banner_name;
                }
            }
        }

        try {
            if ($page) {
                $pdo->prepare("
                    UPDATE my_page
                    SET bio=?, phone=?, county=?, profile_pic=?, banner=?, page_link=?, updated_at=NOW()
                    WHERE creator_id=?
                ")->execute([$bio, $phone, $county, $profile_pic, $banner, $page_link, $creator_id]);
            } else {
                $pdo->prepare("
                    INSERT INTO my_page (creator_id, full_name, bio, phone, county, profile_pic, banner, page_link, created_at)
                    VALUES (?,?,?,?,?,?,?,?,NOW())
                ")->execute([$creator_id, $full_name, $bio, $phone, $county, $profile_pic, $banner, $page_link]);
            }
            // Reload page record
            $stmt = $pdo->prepare("SELECT * FROM my_page WHERE creator_id = ?");
            $stmt->execute([$creator_id]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);
            $success_msg = "Your page has been " . ($page ? "updated" : "created") . " successfully!";
        } catch (Exception $e) {
            error_log("create_page error: " . $e->getMessage());
            $error_msg = "Something went wrong. Please try again.";
        }
    }
}

$counties = [
    "Mombasa","Kwale","Kilifi","Tana River","Lamu","Taita-Taveta",
    "Garissa","Wajir","Mandera","Marsabit","Isiolo","Meru","Tharaka-Nithi",
    "Embu","Kitui","Machakos","Makueni","Nyandarua","Nyeri","Kirinyaga",
    "Murang'a","Kiambu","Turkana","West Pokot","Samburu","Trans-Nzoia",
    "Uasin Gishu","Elgeyo-Marakwet","Nandi","Baringo","Laikipia","Nakuru",
    "Narok","Kajiado","Kericho","Bomet","Kakamega","Vihiga","Bungoma",
    "Busia","Siaya","Kisumu","Homa Bay","Migori","Kisii","Nyamira","Nairobi"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= $page ? 'Update My Page' : 'Set Up My Page' ?> — Keychele</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
/* ══════════════════════════════════
   TOKENS — identical to dashboard
══════════════════════════════════ */
:root {
  --red:     #bf1339;
  --red-d:   #96102e;
  --gold:    #fec411;
  --gold-d:  #d4a60a;
  --navy:    #052e64;
  --navy-l:  #0a4494;
  --navy-xl: #e8eef8;
  --bg:      #eef1f8;
  --card:    #ffffff;
  --border:  rgba(5,46,100,.08);
  --text:    #0f1923;
  --muted:   #6b7a99;
  --success: #00b37e;
  --r:       14px;
  --r-lg:    20px;
  --sh-sm:   0 2px 8px rgba(5,46,100,.07);
  --sh:      0 6px 28px rgba(5,46,100,.11);
  --sh-lg:   0 16px 56px rgba(5,46,100,.16);
  --ff:      'Montserrat', sans-serif;
}

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body {
  font-family:var(--ff);
  background:var(--bg);
  color:var(--text);
  -webkit-font-smoothing:antialiased;
}
img { display:block; max-width:100%; }

/* ── PAGE WRAP ── */
.pg-wrap {
  max-width: 680px;
  margin: 0 auto;
  padding: 1.5rem 1.25rem 5rem;
}
@media(max-width:600px){ .pg-wrap { padding: 1rem .9rem 5rem; } }

/* ── SECTION HEADER ── */
.sec-hd {
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:.9rem;
}
.sec-title {
  font-weight:700; font-size:.72rem;
  text-transform:uppercase; letter-spacing:.1em;
  color:var(--muted);
  display:flex; align-items:center; gap:8px;
}
.sec-title::before {
  content:''; width:3px; height:14px; border-radius:3px;
  background:linear-gradient(180deg,var(--red),var(--gold));
}
.view-link {
  font-size:.7rem; font-weight:700; color:var(--red);
  display:flex; align-items:center; gap:4px; text-decoration:none;
  transition:gap .2s;
}
.view-link:hover { gap:8px; }
.view-link i { font-size:.55rem; }

/* ── ALERTS ── */
.alert {
  border-radius:var(--r); padding:.9rem 1.2rem;
  display:flex; align-items:flex-start; gap:10px;
  font-size:.83rem; font-weight:600; margin-bottom:1.25rem;
  animation:slide-up .4s cubic-bezier(.16,1,.3,1) both;
}
@keyframes slide-up { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
.alert-success { background:rgba(0,179,126,.1); border:1px solid rgba(0,179,126,.25); color:#065f46; }
.alert-success i { color:var(--success); margin-top:1px; flex-shrink:0; }
.alert-error   { background:rgba(191,19,57,.08); border:1px solid rgba(191,19,57,.2); color:var(--red-d); }
.alert-error i { color:var(--red); margin-top:1px; flex-shrink:0; }

/* ── FORM CARDS ── */
.form-card {
  background:var(--card);
  border-radius:var(--r-lg);
  border:1px solid var(--border);
  box-shadow:var(--sh);
  overflow:hidden;
  margin-bottom:1rem;
  animation:slide-up .5s cubic-bezier(.16,1,.3,1) both;
}
.form-card + .form-card { animation-delay:.06s; }

.form-card-hd {
  padding:1.1rem 1.5rem;
  border-bottom:1px solid var(--border);
  background:#fafbfd;
  display:flex; align-items:center; gap:10px;
}
.form-card-ico {
  width:32px; height:32px; border-radius:9px;
  display:flex; align-items:center; justify-content:center; font-size:.78rem;
  flex-shrink:0;
}
.form-card-ttl  { font-weight:700; font-size:.85rem; color:var(--text); }
.form-card-sub  { font-size:.7rem; color:var(--muted); margin-top:1px; }
.form-body { padding:1.5rem; }

/* ── UPLOAD ROW ── */
.upload-row {
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:14px; margin-bottom:.25rem;
}
@media(max-width:480px){ .upload-row { grid-template-columns:1fr; } }

.upload-box {
  border:2px dashed var(--border);
  border-radius:var(--r);
  padding:1.25rem 1rem;
  text-align:center; cursor:pointer;
  background:#fafbff; position:relative;
  transition:border-color .2s, background .2s;
  min-height:130px;
  display:flex; flex-direction:column; align-items:center; justify-content:center;
}
.upload-box:hover,
.upload-box.has-file { border-color:var(--red); background:rgba(191,19,57,.03); }

.upload-box input[type=file] {
  position:absolute; inset:0; opacity:0; cursor:pointer;
  width:100%; height:100%;
}

/* Profile avatar preview */
.upload-av-preview {
  width:64px; height:64px; border-radius:50%; object-fit:cover;
  border:3px solid #fff; box-shadow:0 2px 10px rgba(0,0,0,.12);
  margin:0 auto 8px; display:none;
}
.upload-av-preview.show { display:block; }

.upload-av-fb {
  width:64px; height:64px; border-radius:50%;
  background:linear-gradient(135deg,var(--navy),var(--navy-l));
  display:flex; align-items:center; justify-content:center;
  font-weight:900; font-size:1.5rem; color:var(--gold);
  margin:0 auto 8px;
}

/* Banner preview */
.upload-banner-preview {
  width:100%; height:72px; border-radius:8px; object-fit:cover;
  margin-bottom:8px; display:none;
}
.upload-banner-preview.show { display:block; }

.upload-banner-ico { font-size:1.6rem; color:var(--muted); margin-bottom:6px; }
.upload-lbl  { font-size:.72rem; font-weight:700; color:var(--text); margin-bottom:2px; }
.upload-hint { font-size:.63rem; color:var(--muted); }

/* ── FORM FIELDS ── */
.fg { margin-bottom:1.1rem; }
.fg:last-child { margin-bottom:0; }

.fg label {
  display:block; font-size:.7rem; font-weight:700;
  color:var(--navy); letter-spacing:.06em; text-transform:uppercase;
  margin-bottom:7px;
}
.fg label .req { color:var(--red); }
.fg label .opt { color:var(--muted); font-weight:500; text-transform:none; font-size:.65rem; }

.fg-wrap { position:relative; }

.form-input,
.form-select,
.form-textarea {
  width:100%; border:1.5px solid var(--border); border-radius:var(--r);
  font-family:var(--ff); font-size:.875rem; color:var(--text);
  background:#fafbff; outline:none;
  transition:border-color .2s, box-shadow .2s, background .2s;
}
.form-input,
.form-select  { height:48px; padding:0 16px 0 42px; }
.form-select  { appearance:none; cursor:pointer; }
.form-textarea {
  padding:14px 16px 14px 42px;
  resize:vertical; min-height:110px; line-height:1.65;
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
  border-color:var(--red); background:#fff;
  box-shadow:0 0 0 3px rgba(191,19,57,.1);
}
.form-input::placeholder,
.form-textarea::placeholder { color:var(--muted); }
.form-input[readonly] {
  background:var(--navy-xl); color:var(--navy); cursor:not-allowed;
}

.fg-icon {
  position:absolute; left:14px; top:50%; transform:translateY(-50%);
  color:var(--muted); font-size:.78rem; pointer-events:none;
  transition:color .2s;
}
/* shift icon to top for textarea */
.fg-wrap.ta-wrap .fg-icon { top:16px; transform:none; }
.fg-wrap:has(:focus) .fg-icon { color:var(--red); }

/* 2-col field row */
.fields-row {
  display:grid; grid-template-columns:1fr 1fr; gap:14px;
}
@media(max-width:500px){ .fields-row { grid-template-columns:1fr; } }

/* ── DIVIDER ── */
.form-divider { height:1px; background:var(--border); margin:1.4rem 0; }

/* ── LINK DISPLAY ── */
.link-display {
  display:flex; align-items:center; gap:10px;
  background:linear-gradient(120deg,var(--navy) 0%,#093576 55%,#0f4faa 100%);
  border-radius:var(--r); padding:13px 16px;
  position:relative; overflow:hidden; margin-bottom:1.35rem;
}
.link-display::after {
  content:''; position:absolute; inset:0;
  background-image:radial-gradient(rgba(255,255,255,.04) 1px,transparent 1px);
  background-size:22px 22px; pointer-events:none;
}
.link-display::before {
  content:''; position:absolute; top:0; left:0; right:0; height:3px;
  background:linear-gradient(90deg,var(--gold),var(--red),var(--gold));
}
.link-text {
  flex:1; font-size:.78rem; font-weight:700;
  color:rgba(255,255,255,.7); font-family:'Courier New',monospace;
  word-break:break-all;
}
.link-text span { color:var(--gold); }
.link-copy-btn {
  display:inline-flex; align-items:center; gap:5px;
  padding:7px 14px; border-radius:50px;
  background:rgba(254,196,17,.18); color:var(--gold);
  border:1.5px solid rgba(254,196,17,.3);
  font-size:.7rem; font-weight:700;
  cursor:pointer; transition:all .2s;
  white-space:nowrap; font-family:var(--ff);
}
.link-copy-btn:hover { background:rgba(254,196,17,.28); }

/* ── SUBMIT BUTTON ── */
.submit-btn {
  width:100%; height:54px; border:none; border-radius:var(--r);
  background:linear-gradient(135deg,var(--red),var(--red-d));
  color:#fff; font-family:var(--ff); font-size:.95rem; font-weight:800;
  display:flex; align-items:center; justify-content:center; gap:10px;
  cursor:pointer;
  box-shadow:0 6px 22px rgba(191,19,57,.38);
  transition:all .2s; letter-spacing:.2px;
}
.submit-btn:hover {
  background:linear-gradient(135deg,var(--red-d),#7a0c22);
  transform:translateY(-2px); box-shadow:0 10px 30px rgba(191,19,57,.45);
}
.submit-btn:active { transform:translateY(0); }
</style>
</head>
<body>

<div class="pg-wrap">

  <!-- Section header -->
  <div class="sec-hd">
    <div class="sec-title"><?= $page ? 'Update My Page' : 'Set Up My Page' ?></div>
    <?php if ($page): ?>
    <a href="index.php?page=my_page" class="view-link">
      View My Page <i class="fas fa-arrow-right"></i>
    </a>
    <?php endif; ?>
  </div>

  <!-- Alerts -->
  <?php if ($success_msg): ?>
  <div class="alert alert-success">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?>
  </div>
  <?php endif; ?>

  <?php if ($error_msg): ?>
  <div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?>
  </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" id="pageForm">

    <!-- ── MEDIA UPLOADS ── -->
    <div class="form-card">
      <div class="form-card-hd">
        <div class="form-card-ico" style="background:rgba(5,46,100,.08);color:var(--navy);">
          <i class="fas fa-image"></i>
        </div>
        <div>
          <div class="form-card-ttl">Profile Media</div>
          <div class="form-card-sub">Upload your profile photo and banner (both optional)</div>
        </div>
      </div>
      <div class="form-body">
        <div class="upload-row">

          <!-- Profile pic -->
          <div class="upload-box" id="picBox">
            <input type="file" name="profile_pic" accept="image/*"
                   id="picInput" onchange="previewPic(this)">

            <?php if (!empty($currentPic)): ?>
              <img src="uploads/<?= htmlspecialchars($currentPic) ?>"
                   class="upload-av-preview show" id="picPreview" alt="Profile"
                   onerror="this.classList.remove('show');this.nextElementSibling.style.display='flex';">
              <div class="upload-av-fb" id="picFb" style="display:none;"><?= $initial ?></div>
            <?php else: ?>
              <img class="upload-av-preview" id="picPreview" alt="">
              <div class="upload-av-fb" id="picFb"><?= $initial ?></div>
            <?php endif; ?>

            <div class="upload-lbl">Profile Photo</div>
            <div class="upload-hint">JPG · PNG · WEBP</div>
          </div>

          <!-- Banner -->
          <div class="upload-box" id="bannerBox">
            <input type="file" name="banner" accept="image/*"
                   id="bannerInput" onchange="previewBanner(this)">

            <?php if (!empty($currentBanner)): ?>
              <img src="uploads/<?= htmlspecialchars($currentBanner) ?>"
                   class="upload-banner-preview show" id="bannerPreview" alt="Banner"
                   onerror="this.classList.remove('show');document.getElementById('bannerIco').style.display='block';">
              <div class="upload-banner-ico" id="bannerIco" style="display:none;">
                <i class="fas fa-panorama"></i>
              </div>
            <?php else: ?>
              <img class="upload-banner-preview" id="bannerPreview" alt="">
              <div class="upload-banner-ico" id="bannerIco"><i class="fas fa-panorama"></i></div>
            <?php endif; ?>

            <div class="upload-lbl">Page Banner</div>
            <div class="upload-hint">Wide image recommended</div>
          </div>

        </div>
      </div>
    </div>

    <!-- ── PROFILE INFO ── -->
    <div class="form-card">
      <div class="form-card-hd">
        <div class="form-card-ico" style="background:rgba(191,19,57,.1);color:var(--red);">
          <i class="fas fa-user"></i>
        </div>
        <div>
          <div class="form-card-ttl">Profile Information</div>
          <div class="form-card-sub">Fields marked <span style="color:var(--red)">*</span> are required</div>
        </div>
      </div>
      <div class="form-body">

        <!-- Full name readonly -->
        <div class="fg">
          <label>Full Name</label>
          <div class="fg-wrap">
            <input type="text" class="form-input" readonly
                   value="<?= htmlspecialchars($full_name) ?>">
            <i class="fas fa-user fg-icon"></i>
          </div>
        </div>

        <!-- Bio -->
        <div class="fg">
          <label>Bio <span class="req">*</span></label>
          <div class="fg-wrap ta-wrap">
            <textarea name="bio" class="form-textarea" required
                      placeholder="Tell fans about yourself — your work, passion, and what they can expect..."><?= htmlspecialchars($page['bio'] ?? '') ?></textarea>
            <i class="fas fa-pen fg-icon"></i>
          </div>
        </div>

        <!-- Phone + County -->
        <div class="fields-row">
          <div class="fg">
            <label>Phone <span class="req">*</span></label>
            <div class="fg-wrap">
              <input type="tel" name="phone" class="form-input" required
                     placeholder="0712 345 678"
                     value="<?= htmlspecialchars($page['phone'] ?? '') ?>">
              <i class="fas fa-phone fg-icon"></i>
            </div>
          </div>

          <div class="fg">
            <label>County <span class="req">*</span></label>
            <div class="fg-wrap">
              <select name="county" class="form-select" required>
                <option value="">Select county</option>
                <?php foreach ($counties as $c): ?>
                  <option value="<?= $c ?>" <?= (($page['county'] ?? '') === $c) ? 'selected' : '' ?>>
                    <?= $c ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <i class="fas fa-map-marker-alt fg-icon"></i>
            </div>
          </div>
        </div>

        <div class="form-divider"></div>

        <!-- Public link -->
        <div class="fg">
          <label>Your Public Page Link</label>
          <div class="link-display">
            <div class="link-text">
              keychele.co.ke/<span><?= htmlspecialchars($username) ?></span>
            </div>
            <button type="button" class="link-copy-btn" onclick="copyLink()">
              <i class="fas fa-copy"></i> Copy
            </button>
          </div>
        </div>

        <button type="submit" class="submit-btn">
          <i class="fas fa-<?= $page ? 'save' : 'plus' ?>"></i>
          <?= $page ? 'Update Page' : 'Create My Page' ?>
        </button>

      </div>
    </div>

  </form>
</div>

<script>
function previewPic(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById('picPreview');
    const fb  = document.getElementById('picFb');
    img.src = e.target.result;
    img.classList.add('show');
    if (fb) fb.style.display = 'none';
    document.getElementById('picBox').classList.add('has-file');
  };
  reader.readAsDataURL(input.files[0]);
}

function previewBanner(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById('bannerPreview');
    const ico = document.getElementById('bannerIco');
    img.src = e.target.result;
    img.classList.add('show');
    if (ico) ico.style.display = 'none';
    document.getElementById('bannerBox').classList.add('has-file');
  };
  reader.readAsDataURL(input.files[0]);
}

function copyLink() {
  navigator.clipboard.writeText('https://keychele.co.ke/<?= htmlspecialchars($username) ?>').then(() => {
    const btn = document.querySelector('.link-copy-btn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
    btn.style.cssText = 'background:rgba(0,179,126,.25);color:#00b37e;border-color:rgba(0,179,126,.4);';
    setTimeout(() => { btn.innerHTML = orig; btn.style.cssText = ''; }, 2000);
  });
}
</script>

</body>
</html>