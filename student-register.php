<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$connect_path = file_exists('msanii/includes/connect.php')
    ? 'msanii/includes/connect.php'
    : 'includes/connect.php';
include_once $connect_path;

$success = false;
$errors  = [];
$values  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['full_name']          = trim($_POST['full_name']          ?? '');
    $values['adm_number']         = trim($_POST['adm_number']         ?? '');
    $values['faculty']            = trim($_POST['faculty']            ?? '');
    $values['year_of_completion'] = trim($_POST['year_of_completion'] ?? '');

    if (empty($values['full_name']))          $errors['full_name']          = 'Full name is required.';
    if (empty($values['adm_number']))         $errors['adm_number']         = 'Admission number is required.';
    if (empty($values['faculty']))            $errors['faculty']            = 'Faculty is required.';
    if (empty($values['year_of_completion'])) $errors['year_of_completion'] = 'Year of completion is required.';

    if (empty($errors)) {
        try {
            $chk = $pdo->prepare("SELECT id FROM students WHERE adm_number = ?");
            $chk->execute([$values['adm_number']]);
            if ($chk->rowCount() > 0) {
                $errors['adm_number'] = 'This admission number is already registered.';
            } else {
                $pdo->prepare("
                    INSERT INTO students (full_name, adm_number, faculty, picture_no, year_of_completion, status, created_at)
                    VALUES (?, ?, ?, NULL, ?, 'pending', NOW())
                ")->execute([
                    $values['full_name'],
                    $values['adm_number'],
                    $values['faculty'],
                    $values['year_of_completion'],
                ]);
                $success = true;
                $values  = [];
            }
        } catch (Exception $e) {
            error_log("Student register: " . $e->getMessage());
            $errors['general'] = 'Something went wrong. Please try again.';
        }
    }
}

$faculties = [
    "Faculty of Arts & Social Sciences",
    "Faculty of Business & Economics",
    "Faculty of Computing & Informatics",
    "Faculty of Education",
    "Faculty of Engineering & Technology",
    "Faculty of Health Sciences",
    "Faculty of Law",
    "Faculty of Science",
    "Faculty of Agriculture",
    "Faculty of Built Environment & Architecture",
];

$years = range((int)date('Y') + 5, 2000);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Student Registration — Keychele</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
:root{
  --navy:#052e64;--navy-d:#021e3f;--navy-m:#0a4494;--navy-xl:#e8eef8;
  --red:#bf1339;--red-d:#96102e;--gold:#fec411;
  --bg:#eef1f8;--card:#fff;--border:rgba(5,46,100,.09);
  --text:#0f1923;--muted:#6b7a99;--success:#00b37e;
  --r:14px;--r-lg:22px;
  --sh-lg:0 20px 64px rgba(5,46,100,.16);
  --ff:'Montserrat',sans-serif;--fd:'DM Serif Display',Georgia,serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{
  font-family:var(--ff);background:var(--bg);color:var(--text);
  min-height:100vh;padding:2.5rem 1rem 4rem;-webkit-font-smoothing:antialiased;
  background-image:
    radial-gradient(ellipse 70% 50% at 10% 20%,rgba(191,19,57,.06) 0%,transparent 60%),
    radial-gradient(ellipse 60% 60% at 90% 80%,rgba(5,46,100,.08) 0%,transparent 60%);
}

/* HEADER */
.page-header{max-width:540px;margin:0 auto 2.25rem;text-align:center;animation:fade-dn .55s cubic-bezier(.16,1,.3,1) both;}
@keyframes fade-dn{from{opacity:0;transform:translateY(-18px)}to{opacity:1;transform:translateY(0)}}
.header-badge{
  display:inline-flex;align-items:center;gap:7px;
  background:linear-gradient(135deg,var(--navy),var(--navy-m));
  color:var(--gold);padding:6px 18px;border-radius:50px;
  font-size:.67rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;
  margin-bottom:1rem;box-shadow:0 4px 16px rgba(5,46,100,.22);
}
.page-title{font-family:var(--fd);font-size:clamp(1.7rem,4vw,2.5rem);color:var(--navy);line-height:1.15;margin-bottom:.5rem;}
.page-title em{color:var(--red);font-style:italic;}
.page-sub{font-size:.84rem;color:var(--muted);line-height:1.7;max-width:380px;margin:0 auto;}

/* CARD */
.form-card{
  max-width:540px;margin:0 auto;
  background:var(--card);border-radius:var(--r-lg);
  border:1px solid var(--border);box-shadow:var(--sh-lg);overflow:hidden;
  animation:slide-up .55s .05s cubic-bezier(.16,1,.3,1) both;
}
@keyframes slide-up{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:translateY(0)}}
.form-card::before{content:'';display:block;height:4px;background:linear-gradient(90deg,var(--gold),var(--red),var(--gold));}

.form-body{padding:2rem;}
@media(max-width:480px){.form-body{padding:1.5rem 1.25rem;}}

/* FIELDS */
.fg{margin-bottom:1.25rem;}
.fg label{
  display:block;font-size:.68rem;font-weight:800;
  color:var(--navy);letter-spacing:.07em;text-transform:uppercase;margin-bottom:7px;
}
.fg label .req{color:var(--red);margin-left:2px;}
.fg-wrap{position:relative;}

.form-input,.form-select{
  width:100%;height:52px;
  border:1.5px solid var(--border);border-radius:var(--r);
  font-family:var(--ff);font-size:.9rem;color:var(--text);
  background:#fafbff;outline:none;
  padding:0 16px 0 46px;
  transition:border-color .2s,box-shadow .2s,background .2s;
}
.form-select{appearance:none;cursor:pointer;padding-right:36px;}
.form-input:focus,.form-select:focus{border-color:var(--navy);background:var(--card);box-shadow:0 0 0 3px rgba(5,46,100,.1);}
.form-input.err,.form-select.err{border-color:var(--red);box-shadow:0 0 0 3px rgba(191,19,57,.08);}
.form-input::placeholder{color:var(--muted);opacity:.7;}
.form-input[readonly]{background:var(--navy-xl);color:var(--muted);cursor:not-allowed;font-style:italic;}

.fg-icon{position:absolute;left:15px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.8rem;pointer-events:none;transition:color .2s;}
.fg-wrap:has(:not([readonly]):focus) .fg-icon{color:var(--navy);}
.sel-arrow{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.62rem;pointer-events:none;}

.err-msg{font-size:.7rem;color:var(--red);font-weight:600;margin-top:5px;display:flex;align-items:center;gap:4px;}

/* PICTURE NO NOTICE */
.pic-notice{
  display:flex;align-items:flex-start;gap:10px;
  background:var(--navy-xl);border:1px solid rgba(5,46,100,.15);
  border-radius:var(--r);padding:.85rem 1.1rem;
  font-size:.77rem;color:var(--navy);line-height:1.6;margin-top:8px;
}
.pic-notice i{color:var(--gold);font-size:.85rem;margin-top:2px;flex-shrink:0;}

/* GENERAL ERROR */
.alert-error{
  border-radius:var(--r);padding:.85rem 1.1rem;margin-bottom:1.25rem;
  display:flex;align-items:flex-start;gap:9px;
  font-size:.82rem;font-weight:600;
  background:rgba(191,19,57,.07);border:1px solid rgba(191,19,57,.2);color:var(--red-d);
}

/* DIVIDER */
.form-divider{height:1px;background:var(--border);margin:1.5rem 0;}

/* SUBMIT */
.submit-btn{
  width:100%;height:56px;border:none;border-radius:var(--r);
  background:linear-gradient(135deg,var(--navy),var(--navy-m));
  color:#fff;font-family:var(--ff);font-size:1rem;font-weight:800;
  display:flex;align-items:center;justify-content:center;gap:10px;
  cursor:pointer;box-shadow:0 6px 24px rgba(5,46,100,.3);
  transition:all .2s;margin-top:1.75rem;
}
.submit-btn:hover{transform:translateY(-2px);box-shadow:0 10px 32px rgba(5,46,100,.4);}
.submit-btn:active{transform:translateY(0);}

/* SUCCESS */
.success-body{padding:3rem 2rem;text-align:center;}
.success-icon{
  width:88px;height:88px;border-radius:50%;
  background:rgba(0,179,126,.12);color:var(--success);
  display:flex;align-items:center;justify-content:center;font-size:2.2rem;
  margin:0 auto 1.4rem;animation:pop .5s cubic-bezier(.16,1,.3,1) both;
}
@keyframes pop{from{opacity:0;transform:scale(.6)}to{opacity:1;transform:scale(1)}}
.success-title{font-family:var(--fd);font-size:1.7rem;color:var(--navy);margin-bottom:.6rem;}
.success-sub{font-size:.86rem;color:var(--muted);line-height:1.7;max-width:340px;margin:0 auto 1.75rem;}
.pending-tag{
  display:inline-flex;align-items:center;gap:8px;
  background:var(--navy-xl);border:1px solid rgba(5,46,100,.15);
  border-radius:var(--r);padding:.75rem 1.4rem;
  font-size:.82rem;font-weight:700;color:var(--navy);margin-bottom:1.75rem;
}
.pending-tag i{color:var(--gold);}
.btn-again{
  display:inline-flex;align-items:center;gap:8px;
  padding:12px 28px;background:var(--navy);color:#fff;
  border-radius:50px;font-weight:700;font-size:.85rem;
  text-decoration:none;transition:all .2s;
  box-shadow:0 4px 16px rgba(5,46,100,.22);
}
.btn-again:hover{background:var(--navy-d);transform:translateY(-2px);}

.page-footer{text-align:center;margin-top:1.75rem;font-size:.73rem;color:var(--muted);}
.page-footer a{color:var(--navy);font-weight:700;text-decoration:none;}
</style>
</head>
<body>

<div class="page-header">
  <div class="header-badge"><i class="fas fa-graduation-cap"></i> Student Registration</div>
  <h1 class="page-title">Register Your <em>Details</em></h1>
  <p class="page-sub">Fill in your information below. Your Picture Number will be assigned by an administrator after review.</p>
</div>

<div class="form-card">

  <?php if ($success): ?>
  <div class="success-body">
    <div class="success-icon"><i class="fas fa-check"></i></div>
    <div class="success-title">Registration Submitted!</div>
    <p class="success-sub">Your details have been received. Your <strong>Picture Number</strong> will be assigned once an administrator reviews your registration.</p>
    <div class="pending-tag"><i class="fas fa-clock"></i> Pending Admin Review</div><br>
    <a href="student-register.php" class="btn-again"><i class="fas fa-plus"></i> Register Another</a>
  </div>

  <?php else: ?>
  <div class="form-body">

    <?php if (!empty($errors['general'])): ?>
    <div class="alert-error">
      <i class="fas fa-exclamation-circle" style="flex-shrink:0;margin-top:1px;"></i>
      <?= htmlspecialchars($errors['general']) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="regForm">

      <!-- 1. Full Name -->
      <div class="fg">
        <label>Full Name <span class="req">*</span></label>
        <div class="fg-wrap">
          <input type="text" name="full_name"
                 class="form-input <?= isset($errors['full_name']) ? 'err' : '' ?>"
                 placeholder="e.g. Jane Achieng Omondi"
                 value="<?= htmlspecialchars($values['full_name'] ?? '') ?>">
          <i class="fas fa-user fg-icon"></i>
        </div>
        <?php if (isset($errors['full_name'])): ?>
          <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= $errors['full_name'] ?></div>
        <?php endif; ?>
      </div>

      <!-- 2. Adm Number -->
      <div class="fg">
        <label>Admission Number <span class="req">*</span></label>
        <div class="fg-wrap">
          <input type="text" name="adm_number"
                 class="form-input <?= isset($errors['adm_number']) ? 'err' : '' ?>"
                 placeholder="e.g. ADM/2021/001"
                 value="<?= htmlspecialchars($values['adm_number'] ?? '') ?>">
          <i class="fas fa-id-badge fg-icon"></i>
        </div>
        <?php if (isset($errors['adm_number'])): ?>
          <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= $errors['adm_number'] ?></div>
        <?php endif; ?>
      </div>

      <!-- 3. Faculty -->
      <div class="fg">
        <label>Faculty <span class="req">*</span></label>
        <div class="fg-wrap">
          <select name="faculty" class="form-select <?= isset($errors['faculty']) ? 'err' : '' ?>">
            <option value="">Select your faculty</option>
            <?php foreach ($faculties as $f): ?>
              <option value="<?= $f ?>" <?= ($values['faculty'] ?? '') === $f ? 'selected' : '' ?>><?= $f ?></option>
            <?php endforeach; ?>
          </select>
          <i class="fas fa-building-columns fg-icon"></i>
          <i class="fas fa-chevron-down sel-arrow"></i>
        </div>
        <?php if (isset($errors['faculty'])): ?>
          <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= $errors['faculty'] ?></div>
        <?php endif; ?>
      </div>

      <!-- 4. Picture No (admin only — readonly) -->
      <div class="fg">
        <label>Picture Number</label>
        <div class="fg-wrap">
          <input type="text" class="form-input" readonly
                 placeholder="To be assigned by administrator">
          <i class="fas fa-camera fg-icon"></i>
        </div>
        <div class="pic-notice">
          <i class="fas fa-circle-info"></i>
          <span>This field is filled by an <strong>administrator only</strong> after your registration is approved. You cannot enter it yourself.</span>
        </div>
      </div>

      <div class="form-divider"></div>

      <!-- 5. Year of Completion -->
      <div class="fg">
        <label>Year of Completion <span class="req">*</span></label>
        <div class="fg-wrap">
          <select name="year_of_completion" class="form-select <?= isset($errors['year_of_completion']) ? 'err' : '' ?>">
            <option value="">Select year</option>
            <?php foreach ($years as $y): ?>
              <option value="<?= $y ?>" <?= ((int)($values['year_of_completion'] ?? 0)) === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
          </select>
          <i class="fas fa-calendar fg-icon"></i>
          <i class="fas fa-chevron-down sel-arrow"></i>
        </div>
        <?php if (isset($errors['year_of_completion'])): ?>
          <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= $errors['year_of_completion'] ?></div>
        <?php endif; ?>
      </div>

      <button type="submit" class="submit-btn">
        <i class="fas fa-paper-plane"></i> Submit Registration
      </button>

    </form>
  </div>
  <?php endif; ?>

</div>

<div class="page-footer">
  © Keychele <?= date('Y') ?> · <a href="index.php">Back to Home</a>
</div>

</body>
</html>

<?php
/*
════════════════════════════════════════════════════
  SQL — CREATE TABLE
════════════════════════════════════════════════════

CREATE TABLE students (

    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Entered by student
    full_name           VARCHAR(150)    NOT NULL,
    adm_number          VARCHAR(60)     NOT NULL UNIQUE,
    faculty             VARCHAR(150)    NOT NULL,
    year_of_completion  YEAR            NOT NULL,

    -- Assigned by ADMIN only (NULL until approved)
    picture_no          VARCHAR(60)     DEFAULT NULL UNIQUE,

    -- Workflow
    status              ENUM('pending','approved','rejected')
                        NOT NULL DEFAULT 'pending',
    admin_notes         TEXT            DEFAULT NULL,

    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_adm_number (adm_number),
    INDEX idx_status     (status),
    INDEX idx_faculty    (faculty),
    INDEX idx_year       (year_of_completion)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ADMIN: assign picture number + approve
-- UPDATE students
-- SET    picture_no  = 'PIC-2025-001',
--        status      = 'approved'
-- WHERE  id = 1;

-- View all pending
-- SELECT id, full_name, adm_number, faculty, year_of_completion, created_at
-- FROM   students WHERE status = 'pending' ORDER BY created_at ASC;

════════════════════════════════════════════════════
*/
?>