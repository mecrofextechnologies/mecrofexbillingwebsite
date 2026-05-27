<?php
session_start();
include '../msanii/includes/connect.php';

/* CSRF TOKEN */
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* RATE LIMIT */
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}

$error_message = "";
$success_message = "";

/* PROCESS LOGIN */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "Invalid session token. Please refresh the page.";
    }

    elseif ($_SESSION['login_attempts'] >= 5) {
        $error_message = "Too many failed attempts. Please wait 5 seconds.";
        sleep(5);
    }

    else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = trim($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error_message = "Email and password are required.";
        } 
        else {
            $stmt = $pdo->prepare("
                SELECT id, full_name, email, password, must_change_password
                FROM creators
                WHERE email = :email
                LIMIT 1
            ");
            $stmt->execute(['email' => $email]);
            $creator = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$creator || !password_verify($password, $creator['password'])) {
                $_SESSION['login_attempts']++;
                $error_message = "Invalid email or password.";
            } 
            else {
                $_SESSION['login_attempts'] = 0;
                session_regenerate_id(true);

                $_SESSION['creator_id']    = $creator['id'];
                $_SESSION['creator_name']  = $creator['full_name'];
                $_SESSION['creator_email'] = $creator['email'];

                $success_message = "Login successful! Redirecting...";

                if ($creator['must_change_password'] == 1) {
                    $_SESSION['force_password_change'] = true;
                    header("refresh:2;url=msanii/index.php?page=change_password");
                } else {
                    header("refresh:2;url=index.php");
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Keychele — Creator Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet"/>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --crimson: #C8102E;
    --crimson-deep: #9B0B22;
    --crimson-light: #FF3356;
    --ink: #0D0D0D;
    --ink-soft: #1A1A2E;
    --slate: #64748B;
    --mist: #F8F7F5;
    --white: #FFFFFF;
    --border: #E8E4DF;
    --font-display: 'Syne', sans-serif;
    --font-body: 'DM Sans', sans-serif;
    --shadow-card: 0 32px 80px rgba(13,13,13,0.12), 0 8px 24px rgba(13,13,13,0.06);
    --shadow-btn: 0 4px 20px rgba(200,16,46,0.4);
    --radius: 20px;
    --radius-input: 10px;
  }

  html, body {
    height: 100%;
    font-family: var(--font-body);
    background: var(--mist);
    color: var(--ink);
    -webkit-font-smoothing: antialiased;
  }

  /* ── LAYOUT ── */
  .page-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: stretch;
  }

  /* ── LEFT PANEL ── */
  .panel-left {
    display: none;
    flex: 0 0 42%;
    position: relative;
    overflow: hidden;
    background: var(--ink-soft);
  }

  @media (min-width: 1024px) {
    .panel-left { display: flex; flex-direction: column; }
  }

  .panel-left-img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0.35;
  }

  .panel-left-gradient {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(200,16,46,0.9) 0%, rgba(13,13,13,0.95) 100%);
    mix-blend-mode: multiply;
  }

  .panel-left-content {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    height: 100%;
    padding: 3rem;
  }

  .panel-left-logo {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .panel-left-logo img {
    width: 44px;
    height: 44px;
    object-fit: contain;
    filter: brightness(0) invert(1);
  }

  .panel-left-logo span {
    font-family: var(--font-display);
    font-weight: 800;
    font-size: 1.25rem;
    color: white;
    letter-spacing: -0.02em;
  }

  .panel-left-hero {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 1.5rem;
  }

  .panel-left-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 100px;
    padding: 6px 16px;
    font-size: 0.75rem;
    font-weight: 500;
    color: rgba(255,255,255,0.8);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    width: fit-content;
    backdrop-filter: blur(10px);
  }

  .panel-left-eyebrow::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #4ADE80;
    animation: pulse-dot 2s infinite;
  }

  @keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.4); }
  }

  .panel-left-headline {
    font-family: var(--font-display);
    font-weight: 800;
    font-size: clamp(2rem, 3vw, 3rem);
    line-height: 1.1;
    color: white;
    letter-spacing: -0.03em;
  }

  .panel-left-headline em {
    font-style: normal;
    color: var(--crimson-light);
  }

  .panel-left-desc {
    font-size: 0.95rem;
    line-height: 1.7;
    color: rgba(255,255,255,0.6);
    max-width: 320px;
  }

  /* Testimonial / feature cards */
  .feature-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }

  .feature-item {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: 12px 16px;
    backdrop-filter: blur(10px);
  }

  .feature-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(200,16,46,0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .feature-icon svg {
    width: 16px;
    height: 16px;
    color: #FF8FA3;
  }

  .feature-text {
    font-size: 0.82rem;
    color: rgba(255,255,255,0.7);
    font-weight: 400;
    line-height: 1.4;
  }

  .feature-text strong {
    color: white;
    font-weight: 600;
    display: block;
    font-size: 0.85rem;
  }

  .panel-left-footer {
    font-size: 0.75rem;
    color: rgba(255,255,255,0.3);
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .panel-left-footer::before {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255,255,255,0.1);
  }

  /* ── RIGHT PANEL ── */
  .panel-right {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1.5rem;
    background: var(--mist);
    position: relative;
  }

  .panel-right::before {
    content: '';
    position: absolute;
    top: -200px;
    right: -200px;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200,16,46,0.06) 0%, transparent 70%);
    pointer-events: none;
  }

  .form-card {
    width: 100%;
    max-width: 420px;
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--shadow-card);
    padding: 2.5rem;
    position: relative;
    overflow: hidden;
    animation: card-enter 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
  }

  @keyframes card-enter {
    from { opacity: 0; transform: translateY(24px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .form-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--crimson), var(--crimson-light), #FF8FA3);
  }

  /* ── MOBILE BRAND ── */
  .mobile-brand {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 1.75rem;
  }

  @media (min-width: 1024px) {
    .mobile-brand { display: none; }
  }

  .mobile-brand img {
    width: 36px;
    height: 36px;
    object-fit: contain;
  }

  .mobile-brand span {
    font-family: var(--font-display);
    font-weight: 800;
    font-size: 1.15rem;
    color: var(--ink);
    letter-spacing: -0.02em;
  }

  /* ── FORM HEADER ── */
  .form-header {
    margin-bottom: 2rem;
  }

  .form-title {
    font-family: var(--font-display);
    font-weight: 800;
    font-size: 1.65rem;
    color: var(--ink);
    letter-spacing: -0.03em;
    line-height: 1.2;
    margin-bottom: 0.4rem;
  }

  .form-subtitle {
    font-size: 0.875rem;
    color: var(--slate);
  }

  /* ── FIELDS ── */
  .field {
    margin-bottom: 1.1rem;
  }

  .field label {
    display: block;
    font-size: 0.8rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.45rem;
    letter-spacing: 0.01em;
  }

  .field-input-wrap {
    position: relative;
  }

  .field-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    color: #9CA3AF;
    pointer-events: none;
    transition: color 0.2s;
  }

  .toggle-pw {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    color: #9CA3AF;
    display: flex;
    align-items: center;
    transition: color 0.2s;
  }

  .toggle-pw:hover { color: var(--crimson); }

  .field input {
    width: 100%;
    height: 48px;
    padding: 0 42px 0 40px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-input);
    font-family: var(--font-body);
    font-size: 0.9rem;
    color: var(--ink);
    background: #FAFAFA;
    outline: none;
    transition: all 0.2s;
  }

  .field input::placeholder { color: #C4C4C4; }

  .field input:focus {
    border-color: var(--crimson);
    background: var(--white);
    box-shadow: 0 0 0 3px rgba(200,16,46,0.08);
  }

  /* ── FORGOT ── */
  .forgot-row {
    display: flex;
    justify-content: flex-end;
    margin-top: -0.5rem;
    margin-bottom: 1rem;
  }

  .forgot-row a {
    font-size: 0.78rem;
    color: var(--crimson);
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s;
  }

  .forgot-row a:hover { color: var(--crimson-deep); text-decoration: underline; }

  /* ── BUTTON ── */
  .btn-primary {
    width: 100%;
    height: 50px;
    border: none;
    border-radius: var(--radius-input);
    background: linear-gradient(135deg, var(--crimson) 0%, var(--crimson-light) 100%);
    color: white;
    font-family: var(--font-body);
    font-size: 0.9rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    cursor: pointer;
    box-shadow: var(--shadow-btn);
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 0.25rem;
  }

  .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 28px rgba(200,16,46,0.5);
  }

  .btn-primary:active { transform: translateY(0); }

  .btn-primary:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
  }

  /* ── DIVIDER ── */
  .divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 1.5rem 0 1.25rem;
  }

  .divider::before, .divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
  }

  .divider span {
    font-size: 0.75rem;
    color: #9CA3AF;
    font-weight: 500;
    white-space: nowrap;
  }

  /* ── SIGNUP LINK ── */
  .signup-link {
    text-align: center;
    font-size: 0.82rem;
    color: var(--slate);
  }

  .signup-link a {
    color: var(--crimson);
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s;
  }

  .signup-link a:hover {
    color: var(--crimson-deep);
    text-decoration: underline;
  }

  /* ── ATTEMPT INDICATOR ── */
  .attempts-bar {
    display: flex;
    gap: 5px;
    margin-top: 1rem;
  }

  .attempt-pip {
    flex: 1;
    height: 3px;
    border-radius: 3px;
    background: var(--border);
    transition: background 0.3s;
  }

  .attempt-pip.used { background: var(--crimson); }

  /* ── SPINNER ── */
  @keyframes spin { to { transform: rotate(360deg); } }
  .spinner { animation: spin 0.8s linear infinite; }

  /* ── TOASTR ── */
  #toast-container > .toast {
    border-radius: 10px !important;
    box-shadow: 0 8px 32px rgba(0,0,0,0.12) !important;
    font-family: var(--font-body) !important;
    font-size: 0.875rem !important;
    padding: 14px 18px 14px 52px !important;
  }

  #toast-container > .toast-success {
    background-color: #064E3B !important;
    border-left: 4px solid #10B981 !important;
  }

  #toast-container > .toast-error {
    background-color: #7F1D1D !important;
    border-left: 4px solid #EF4444 !important;
  }

  /* ── RESPONSIVE ── */
  @media (max-width: 480px) {
    .form-card { padding: 2rem 1.5rem; border-radius: 16px; }
    .form-title { font-size: 1.4rem; }
  }
</style>
</head>
<body>

<div class="page-wrapper">

  <!-- LEFT PANEL -->
  <div class="panel-left">
    <img src="https://images.unsplash.com/photo-1492684223066-81342ee5ff30?w=900" class="panel-left-img" alt="">
    <div class="panel-left-gradient"></div>
    <div class="panel-left-content">
      <div class="panel-left-logo">
        <img src="../images/logo/KECHELE LOGO.png" alt="Keychele">
        <span>Keychele</span>
      </div>

      <div class="panel-left-hero">
        <div class="panel-left-eyebrow">Creator Dashboard</div>
        <h1 class="panel-left-headline">
          Your stage<br>awaits<br><em>you.</em>
        </h1>
        <p class="panel-left-desc">
          Log in to manage your events, track ticket sales, and grow your audience across Kenya.
        </p>
        <div class="feature-list">
          <div class="feature-item">
            <div class="feature-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="feature-text">
              <strong>Event Management</strong>
              Create and publish events in minutes
            </div>
          </div>
          <div class="feature-item">
            <div class="feature-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div class="feature-text">
              <strong>Real-time Analytics</strong>
              Track revenue and attendance live
            </div>
          </div>
          <div class="feature-item">
            <div class="feature-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
            </div>
            <div class="feature-text">
              <strong>Instant Payouts</strong>
              Receive earnings directly to M-Pesa
            </div>
          </div>
        </div>
      </div>

      <div class="panel-left-footer">© 2025 Keychele</div>
    </div>
  </div>

  <!-- RIGHT PANEL -->
  <div class="panel-right">
    <div class="form-card">

      <!-- Mobile brand -->
      <div class="mobile-brand">
        <img src="images/logo/KECHELE LOGO.png" alt="Keychele">
        <span>Keychele</span>
      </div>

      <div class="form-header">
        <h2 class="form-title">Welcome back</h2>
        <p class="form-subtitle">Sign in to access your creator dashboard.</p>
      </div>

      <form method="POST" id="loginForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <div class="field">
          <label>Email Address</label>
          <div class="field-input-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input type="email" name="email" placeholder="you@example.com" required autocomplete="email">
          </div>
        </div>

        <div class="field">
          <label>Password</label>
          <div class="field-input-wrap">
            <svg class="field-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" name="password" id="passwordInput" placeholder="••••••••" required autocomplete="current-password">
            <button type="button" class="toggle-pw" id="togglePw" aria-label="Toggle password visibility">
              <svg id="eyeIcon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div class="forgot-row">
          <a href="forgot-password">Forgot password?</a>
        </div>

        <!-- Login attempts visual -->
        <?php if($_SESSION['login_attempts'] > 0): ?>
        <div class="attempts-bar">
          <?php for($i = 0; $i < 5; $i++): ?>
            <div class="attempt-pip <?= $i < $_SESSION['login_attempts'] ? 'used' : '' ?>"></div>
          <?php endfor; ?>
        </div>
        <p style="font-size:0.72rem;color:var(--slate);margin-top:5px;margin-bottom:10px;">
          <?= 5 - $_SESSION['login_attempts'] ?> attempt<?= (5 - $_SESSION['login_attempts']) !== 1 ? 's' : '' ?> remaining
        </p>
        <?php endif; ?>

        <button type="submit" class="btn-primary" id="loginBtn">
          <span id="btnText">Sign In</span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>

        <div class="divider"><span>New to Keychele?</span></div>

        <div class="signup-link">
          <a href="register">Create a creator account →</a>
        </div>
      </form>

    </div>
  </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script>
/* Password toggle */
const togglePw = document.getElementById('togglePw');
const pwInput  = document.getElementById('passwordInput');
const eyeIcon  = document.getElementById('eyeIcon');

const eyeOpen   = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
const eyeClosed = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`;

togglePw.addEventListener('click', () => {
  const isHidden = pwInput.type === 'password';
  pwInput.type = isHidden ? 'text' : 'password';
  eyeIcon.innerHTML = isHidden ? eyeClosed : eyeOpen;
});

/* Submit spinner */
document.getElementById("loginForm").addEventListener("submit", function() {
  const btn = document.getElementById("loginBtn");
  btn.disabled = true;
  btn.innerHTML = `
    <svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
    </svg>
    Signing in...
  `;
});

/* Toastr */
toastr.options = {
  closeButton: true,
  progressBar: true,
  positionClass: "toast-top-right",
  timeOut: 4500
};

<?php if(!empty($error_message)): ?>
toastr.error('<?= addslashes($error_message) ?>');
<?php endif; ?>

<?php if(!empty($success_message)): ?>
toastr.success('<?= addslashes($success_message) ?>');
<?php endif; ?>
</script>
</body>
</html>