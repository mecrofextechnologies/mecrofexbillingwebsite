<?php
include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'];

$stmt = $pdo->prepare("
    SELECT mp.*,
           COALESCE(NULLIF(TRIM(c.full_name), ''), mp.full_name, CONCAT('@', c.username)) AS full_name,
           c.username,
           c.email
    FROM my_page mp
    JOIN creators c ON mp.creator_id = c.id
    WHERE mp.creator_id = ?
    LIMIT 1
");
$stmt->execute([$creator_id]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

$displayName = $page['full_name']   ?? 'Creator';
$username    = $page['username']    ?? '';
$initial     = strtoupper(substr($displayName, 0, 1));
$profilePic  = $page['profile_pic'] ?? '';
$banner      = $page['banner']      ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>My Page — Keychele</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
:root {
  --red:#bf1339;--red-d:#96102e;--gold:#fec411;--gold-d:#d4a60a;
  --navy:#052e64;--navy-l:#0a4494;--navy-xl:#e8eef8;
  --bg:#eef1f8;--card:#ffffff;--border:rgba(5,46,100,.08);
  --text:#0f1923;--muted:#6b7a99;--success:#00b37e;
  --r:14px;--r-lg:20px;
  --sh-sm:0 2px 8px rgba(5,46,100,.07);
  --sh:0 6px 28px rgba(5,46,100,.11);
  --sh-lg:0 16px 56px rgba(5,46,100,.16);
  --ff:'Montserrat',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--ff);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;padding-bottom:80px;}
img{display:block;max-width:100%;}
a{text-decoration:none;color:inherit;}

#preloader{position:fixed;inset:0;z-index:9999;background:var(--navy);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:1.25rem;transition:opacity .5s,visibility .5s;}
#preloader.gone{opacity:0;visibility:hidden;pointer-events:none;}
.pre-wordmark{font-weight:900;font-size:1.8rem;color:#fff;letter-spacing:-.05em;}
.pre-wordmark em{color:var(--gold);font-style:normal;}
.pre-track{width:140px;height:3px;border-radius:10px;background:rgba(255,255,255,.12);overflow:hidden;}
.pre-fill{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--red),var(--gold));animation:load-fill 1.5s cubic-bezier(.4,0,.2,1) forwards;}
@keyframes load-fill{from{width:0}to{width:100%}}

.pg-wrap{max-width:900px;margin:0 auto;padding:1.5rem 1.25rem;}
@media(max-width:600px){.pg-wrap{padding:1rem .9rem;}}

.sec-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:.9rem;}
.sec-title{font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);display:flex;align-items:center;gap:8px;}
.sec-title::before{content:'';width:3px;height:14px;border-radius:3px;background:linear-gradient(180deg,var(--red),var(--gold));}

@keyframes slide-up{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}

.empty-card{background:var(--card);border-radius:var(--r-lg);border:1px solid var(--border);box-shadow:var(--sh);padding:3.5rem 2rem;text-align:center;animation:slide-up .5s cubic-bezier(.16,1,.3,1) both;}
.empty-icon{width:80px;height:80px;border-radius:50%;background:var(--navy-xl);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;font-size:2rem;color:var(--navy);}
.empty-card h2{font-weight:800;font-size:1.35rem;color:var(--navy);margin-bottom:.5rem;}
.empty-card p{font-size:.9rem;color:var(--muted);margin-bottom:1.75rem;line-height:1.6;}
.btn-primary{display:inline-flex;align-items:center;gap:8px;padding:13px 28px;background:var(--red);color:#fff;border-radius:50px;font-weight:700;font-size:.88rem;border:none;cursor:pointer;box-shadow:0 6px 20px rgba(191,19,57,.35);transition:all .2s;}
.btn-primary:hover{background:var(--red-d);transform:translateY(-2px);}

/* ── BANNER ── */
.banner-card{position:relative;border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh-lg);margin-bottom:1.5rem;animation:slide-up .5s cubic-bezier(.16,1,.3,1) both;}
.banner-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--gold),var(--red),var(--gold));z-index:3;}
.banner-img{width:100%;height:220px;object-fit:cover;display:block;}
@media(min-width:600px){.banner-img{height:280px;}}
.banner-placeholder{width:100%;height:220px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-l) 60%,#1a5fba 100%);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:10px;color:rgba(255,255,255,.35);font-size:.8rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;position:relative;overflow:hidden;}
.banner-placeholder::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.04) 1px,transparent 1px);background-size:28px 28px;}
@media(min-width:600px){.banner-placeholder{height:280px;}}
.banner-grad{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(5,46,100,.75) 100%);pointer-events:none;z-index:1;}
.banner-pub-btn{position:absolute;top:20px;right:20px;z-index:4;display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:50px;background:rgba(255,255,255,.15);color:#fff;font-size:.72rem;font-weight:700;letter-spacing:.3px;border:1.5px solid rgba(255,255,255,.25);backdrop-filter:blur(8px);transition:all .2s;}
.banner-pub-btn:hover{background:rgba(255,255,255,.25);}
.banner-profile-row{position:absolute;bottom:0;left:0;right:0;z-index:2;display:flex;align-items:flex-end;justify-content:space-between;padding:0 24px 20px;}
.avatar-ring{width:90px;height:90px;border-radius:50%;padding:3px;background:linear-gradient(135deg,var(--gold),var(--red));box-shadow:0 6px 24px rgba(0,0,0,.35);flex-shrink:0;}
@media(min-width:600px){.avatar-ring{width:110px;height:110px;}}
.avatar-ring img,.avatar-ring .av-fb{width:100%;height:100%;border-radius:50%;object-fit:cover;border:4px solid #fff;background:var(--navy-xl);}
.av-fb{display:flex;align-items:center;justify-content:center;font-weight:900;font-size:2rem;color:var(--navy);}
.banner-name-block{text-align:right;padding-bottom:4px;}
.banner-name{font-weight:900;font-size:clamp(1.1rem,3vw,1.6rem);color:#fff;line-height:1.15;text-shadow:0 2px 10px rgba(0,0,0,.4);}
.banner-handle{font-size:.78rem;color:rgba(255,255,255,.6);font-weight:600;margin-top:3px;}

/* ── DETAIL CARD ── */
.detail-card{background:var(--card);border-radius:var(--r);border:1px solid var(--border);box-shadow:var(--sh-sm);overflow:hidden;margin-bottom:1rem;animation:slide-up .5s .08s cubic-bezier(.16,1,.3,1) both;}
.detail-hd{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.35rem;border-bottom:1px solid var(--border);background:#fafbfd;}
.detail-hd-title{display:flex;align-items:center;gap:8px;font-weight:700;font-size:.82rem;color:var(--text);}
.detail-ico{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.7rem;}
.edit-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:50px;background:var(--red);color:#fff;font-size:.72rem;font-weight:700;transition:all .2s;}
.edit-btn:hover{background:var(--red-d);transform:translateY(-1px);}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;}
@media(max-width:540px){.info-grid{grid-template-columns:1fr;}}
.info-row{display:flex;align-items:flex-start;gap:12px;padding:1rem 1.35rem;border-bottom:1px solid rgba(5,46,100,.05);border-right:1px solid rgba(5,46,100,.05);}
.info-row:nth-child(even){border-right:none;}
@media(max-width:540px){.info-row{border-right:none;}}
.info-ico-wrap{width:34px;height:34px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.78rem;}
.info-lbl{font-size:.65rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;}
.info-val{font-size:.88rem;font-weight:600;color:var(--text);line-height:1.3;}

/* ── BIO ── */
.bio-card{background:var(--card);border-radius:var(--r);border:1px solid var(--border);box-shadow:var(--sh-sm);overflow:hidden;margin-bottom:1rem;animation:slide-up .5s .12s cubic-bezier(.16,1,.3,1) both;}
.bio-body{padding:1.25rem 1.35rem;}
.bio-text{font-size:.88rem;color:var(--muted);line-height:1.8;}

/* ── LINK CARD ── */
.link-card{background:linear-gradient(120deg,var(--navy) 0%,#093576 50%,#0f4faa 100%);border-radius:var(--r);box-shadow:var(--sh);padding:1.25rem 1.4rem;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:1rem;position:relative;overflow:hidden;animation:slide-up .5s .16s cubic-bezier(.16,1,.3,1) both;}
.link-card::after{content:'';position:absolute;inset:0;background-image:radial-gradient(rgba(255,255,255,.04) 1px,transparent 1px);background-size:24px 24px;pointer-events:none;}
.link-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--gold),var(--red),var(--gold));}
.link-url{font-size:.82rem;font-weight:700;color:rgba(255,255,255,.8);font-family:'Courier New',monospace;word-break:break-all;flex:1;}
.link-url span{color:var(--gold);}
.copy-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:50px;background:rgba(254,196,17,.18);color:var(--gold);border:1.5px solid rgba(254,196,17,.3);font-size:.72rem;font-weight:700;cursor:pointer;transition:all .2s;white-space:nowrap;}
.copy-btn:hover{background:rgba(254,196,17,.28);}

/* ── ACTIVITY ── */
.activity-card{background:var(--card);border-radius:var(--r);border:1px solid var(--border);box-shadow:var(--sh-sm);overflow:hidden;animation:slide-up .5s .2s cubic-bezier(.16,1,.3,1) both;}
.activity-body{padding:2.5rem 1.5rem;text-align:center;}
.activity-icon{width:54px;height:54px;border-radius:14px;background:var(--navy-xl);color:var(--navy);display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin:0 auto .9rem;opacity:.7;}
.activity-body p{font-size:.82rem;color:var(--muted);}

/* ── MOBILE NAV ── */
.mob-nav{position:fixed;bottom:0;left:0;right:0;height:60px;background:var(--card);border-top:1px solid var(--border);box-shadow:0 -4px 20px rgba(5,46,100,.08);z-index:100;display:flex;align-items:center;justify-content:space-around;}
@media(min-width:768px){.mob-nav{display:none;}}
.mn-item{display:flex;flex-direction:column;align-items:center;gap:2px;text-decoration:none;color:var(--muted);font-weight:700;font-size:.53rem;letter-spacing:.04em;text-transform:uppercase;transition:color .2s;}
.mn-item.active,.mn-item:hover{color:var(--red);}
.mn-item i{font-size:1.05rem;}
.mn-fab{width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,var(--red),#f04060);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.15rem;margin-top:-14px;box-shadow:0 4px 18px rgba(191,19,57,.45);text-decoration:none;transition:transform .2s,box-shadow .2s;}
.mn-fab:hover{transform:scale(1.08) translateY(-2px);}
</style>
</head>
<body>

<div id="preloader">
  <div class="pre-wordmark">Key<em>chele</em></div>
  <div class="pre-track"><div class="pre-fill"></div></div>
</div>

<div class="pg-wrap">

  <?php if (!$page): ?>
  <div class="sec-hd"><div class="sec-title">My Page</div></div>
  <div class="empty-card">
    <div class="empty-icon"><i class="fas fa-store"></i></div>
    <h2>You haven't set up your page yet</h2>
    <p>Start earning from your fans by creating your public creator page — it only takes a minute.</p>
    <a href="index.php?page=create_page" class="btn-primary">
      <i class="fas fa-plus"></i> Set Up My Page
    </a>
  </div>

  <?php else: ?>

  <div class="banner-card">

    <?php if (!empty($banner)): ?>
      <?php
        // ✅ FIX: this file uses includes/connect.php so uploads live in uploads/ not msanii/uploads/
        $bannerSrc = 'uploads/' . htmlspecialchars($banner);
      ?>
      <img src="<?= $bannerSrc ?>"
           class="banner-img" alt="Banner"
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
      <div class="banner-placeholder" style="display:none;">
        <i class="fas fa-image" style="font-size:2rem;"></i>
        <span>Banner Image</span>
      </div>
    <?php else: ?>
      <div class="banner-placeholder">
        <i class="fas fa-image" style="font-size:2rem;"></i>
        <span>No banner uploaded</span>
      </div>
    <?php endif; ?>

    <div class="banner-grad"></div>

    <?php if (!empty($username)): ?>
    <a href="/<?= htmlspecialchars($username) ?>" target="_blank" class="banner-pub-btn">
      <i class="fas fa-external-link-alt"></i> View Public Page
    </a>
    <?php endif; ?>

    <div class="banner-profile-row">
      <div class="avatar-ring">
        <?php
          // ✅ FIX: same — uploads/ not msanii/uploads/
          $picSrc = !empty($profilePic) ? 'uploads/' . htmlspecialchars($profilePic) : '';
        ?>
        <?php if (!empty($picSrc)): ?>
          <img src="<?= $picSrc ?>"
               alt="<?= htmlspecialchars($displayName) ?>"
               onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
          <div class="av-fb" style="display:none;"><?= $initial ?></div>
        <?php else: ?>
          <div class="av-fb"><?= $initial ?></div>
        <?php endif; ?>
      </div>
      <div class="banner-name-block">
        <div class="banner-name"><?= htmlspecialchars($displayName) ?></div>
        <?php if (!empty($username)): ?>
        <div class="banner-handle">@<?= htmlspecialchars($username) ?></div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /banner-card -->

  <?php if (!empty($username)): ?>
  <div class="link-card">
    <div class="link-url">keychele.co.ke/<span><?= htmlspecialchars($username) ?></span></div>
    <button class="copy-btn" onclick="copyLink()">
      <i class="fas fa-copy"></i> Copy Link
    </button>
  </div>
  <?php endif; ?>

  <div class="sec-hd" style="margin-top:1.5rem;">
    <div class="sec-title">Profile Details</div>
  </div>

  <div class="detail-card">
    <div class="detail-hd">
      <div class="detail-hd-title">
        <div class="detail-ico" style="background:rgba(191,19,57,.1);color:var(--red);">
          <i class="fas fa-user"></i>
        </div>
        Creator Information
      </div>
      <a href="index.php?page=create_page" class="edit-btn">
        <i class="fas fa-pen"></i> Edit
      </a>
    </div>
    <div class="info-grid">
      <div class="info-row">
        <div class="info-ico-wrap" style="background:rgba(5,46,100,.08);color:var(--navy);"><i class="fas fa-user"></i></div>
        <div><div class="info-lbl">Full Name</div><div class="info-val"><?= htmlspecialchars($displayName) ?></div></div>
      </div>
      <div class="info-row">
        <div class="info-ico-wrap" style="background:rgba(5,46,100,.08);color:var(--navy);"><i class="fas fa-at"></i></div>
        <div><div class="info-lbl">Username</div><div class="info-val">@<?= htmlspecialchars($username) ?></div></div>
      </div>
      <div class="info-row">
        <div class="info-ico-wrap" style="background:rgba(0,179,126,.1);color:var(--success);"><i class="fas fa-phone"></i></div>
        <div><div class="info-lbl">Phone</div><div class="info-val"><?= htmlspecialchars($page['phone'] ?? 'Not set') ?></div></div>
      </div>
      <div class="info-row">
        <div class="info-ico-wrap" style="background:rgba(254,196,17,.15);color:var(--gold-d);"><i class="fas fa-map-marker-alt"></i></div>
        <div><div class="info-lbl">County</div><div class="info-val"><?= htmlspecialchars($page['county'] ?? 'Not set') ?></div></div>
      </div>
    </div>
  </div>

  <div class="sec-hd" style="margin-top:1.5rem;">
    <div class="sec-title">About</div>
    <a href="index.php?page=create_page" style="font-size:.7rem;font-weight:700;color:var(--red);display:flex;align-items:center;gap:4px;">
      Edit <i class="fas fa-arrow-right" style="font-size:.55rem;"></i>
    </a>
  </div>
  <div class="bio-card">
    <div class="bio-body">
      <p class="bio-text"><?= nl2br(htmlspecialchars($page['bio'] ?? 'No bio written yet. Click Edit to add one.')) ?></p>
    </div>
  </div>

  <div class="sec-hd" style="margin-top:1.5rem;">
    <div class="sec-title">Activity</div>
  </div>
  <div class="activity-card">
    <div class="activity-body">
      <div class="activity-icon"><i class="fas fa-chart-bar"></i></div>
      <p>Posts, media, and fan activity coming soon!</p>
    </div>
  </div>

  <?php endif; ?>

</div>

<nav class="mob-nav">
  <a href="dashboard.php" class="mn-item"><i class="fas fa-house"></i>Home</a>
  <a href="#" class="mn-item"><i class="fas fa-wallet"></i>Wallet</a>
  <a href="#" class="mn-fab"><i class="fas fa-plus"></i></a>
  <a href="#" class="mn-item"><i class="fas fa-bell"></i>Alerts</a>
  <a href="index.php?page=my_page" class="mn-item active"><i class="fas fa-user"></i>Profile</a>
</nav>

<script>
window.addEventListener('load', () => {
  setTimeout(() => document.getElementById('preloader').classList.add('gone'), 1400);
});
function copyLink() {
  navigator.clipboard.writeText('https://keychele.co.ke/<?= htmlspecialchars($username) ?>').then(() => {
    const btn = document.querySelector('.copy-btn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
    btn.style.background = 'rgba(0,179,126,.25)';
    btn.style.color = '#00b37e';
    btn.style.borderColor = 'rgba(0,179,126,.4)';
    setTimeout(() => { btn.innerHTML = orig; btn.style.background=''; btn.style.color=''; btn.style.borderColor=''; }, 2000);
  });
}
</script>
</body>
</html>
