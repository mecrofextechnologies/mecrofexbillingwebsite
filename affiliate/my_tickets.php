<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'includes/connect.php';

$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    header("Location: login.php");
    exit();
}

$message = '';
$message_type = 'success';

if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message      = urldecode($_GET['msg']);
    $message_type = $_GET['type'] === 'success' ? 'success' : 'error';
}

date_default_timezone_set('Africa/Nairobi');
$now = new DateTime('now', new DateTimeZone('Africa/Nairobi'));

$stmt = $pdo->prepare("
    SELECT * FROM tickets 
    WHERE creator_id = ? 
    ORDER BY start_date DESC, start_time DESC
");
$stmt->execute([$creator_id]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($tickets);
$upcoming = 0; $past = 0;
foreach ($tickets as $t) {
    try {
        $edt = new DateTime($t['start_date'].' '.$t['start_time'], new DateTimeZone('Africa/Nairobi'));
        if ($edt < $now) $past++; else $upcoming++;
    } catch(Exception $e) { $upcoming++; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Tickets — Keychele</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background: #f4f6fb;
      color: #1e2535;
      min-height: 100vh;
    }

    /* ── Preloader ── */
    #preloader {
      position: fixed; inset: 0; z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      background: #f4f6fb;
      transition: opacity .35s;
    }
    .loader-ring {
      width: 40px; height: 40px;
      border: 3px solid #e2e8f0;
      border-top-color: #6c63ff;
      border-radius: 50%;
      animation: spin .7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Layout ── */
    .page { max-width: 1180px; margin: 0 auto; padding: 28px 20px 60px; }

    /* ── Hero ── */
    .hero {
      background: linear-gradient(135deg, #6c63ff 0%, #48b6e3 100%);
      border-radius: 20px;
      padding: 34px 40px;
      margin-bottom: 28px;
      display: flex; align-items: center; justify-content: space-between;
      gap: 16px; flex-wrap: wrap;
      box-shadow: 0 8px 32px rgba(108,99,255,.22);
      position: relative; overflow: hidden;
    }
    .hero::after {
      content: '';
      position: absolute; right: -30px; top: -40px;
      width: 200px; height: 200px;
      background: rgba(255,255,255,.07);
      border-radius: 50%;
      pointer-events: none;
    }
    .hero-text { position: relative; z-index: 1; }
    .hero-title { font-size: clamp(1.5rem,4vw,2.1rem); font-weight: 800; color: #fff; line-height: 1.2; }
    .hero-sub   { color: rgba(255,255,255,.82); margin-top: 6px; font-size: .92rem; }
    .hero-icon  { font-size: 3.2rem; color: rgba(255,255,255,.22); position: relative; z-index: 1; }

    /* ── Stats ── */
    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 14px; margin-bottom: 24px;
    }
    .stat-card {
      background: #fff;
      border: 1px solid #e8ecf4;
      border-radius: 14px;
      padding: 18px 20px;
      box-shadow: 0 1px 6px rgba(0,0,0,.05);
    }
    .stat-num   { font-size: 1.75rem; font-weight: 800; color: #1e2535; }
    .stat-label { font-size: .7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; margin-top: 3px; }
    .stat-card.upcoming .stat-num { color: #22c55e; }
    .stat-card.past     .stat-num { color: #94a3b8; }

    /* ── Alert ── */
    .alert {
      display: flex; align-items: center; gap: 10px;
      padding: 13px 18px; border-radius: 12px;
      margin-bottom: 20px; font-size: .88rem;
      border-left: 4px solid;
    }
    .alert-success { background: #f0fdf4; border-color: #22c55e; color: #166534; }
    .alert-error   { background: #fef2f2; border-color: #ef4444; color: #991b1b; }

    /* ── Toolbar ── */
    .toolbar {
      display: flex; gap: 12px; flex-wrap: wrap;
      align-items: center; justify-content: space-between;
      margin-bottom: 20px;
    }
    .search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 360px; }
    .search-wrap input {
      width: 100%;
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 11px 16px 11px 42px;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: .88rem;
      color: #1e2535;
      outline: none;
      transition: border-color .2s, box-shadow .2s;
      box-shadow: 0 1px 4px rgba(0,0,0,.04);
    }
    .search-wrap input::placeholder { color: #b0bec5; }
    .search-wrap input:focus { border-color: #6c63ff; box-shadow: 0 0 0 3px rgba(108,99,255,.12); }
    .search-wrap i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #b0bec5; font-size: .82rem; }

    .btn-create {
      display: inline-flex; align-items: center; gap: 8px;
      background: #6c63ff; color: #fff;
      padding: 11px 22px; border-radius: 12px;
      font-weight: 700; font-size: .88rem;
      text-decoration: none; border: none; cursor: pointer;
      box-shadow: 0 4px 14px rgba(108,99,255,.3);
      transition: background .18s, transform .18s, box-shadow .18s;
    }
    .btn-create:hover { background: #5a52e0; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(108,99,255,.38); }

    /* ── Table card ── */
    .card {
      background: #fff;
      border: 1px solid #e8ecf4;
      border-radius: 18px;
      overflow: hidden;
      box-shadow: 0 2px 12px rgba(0,0,0,.06);
    }

    table { width: 100%; border-collapse: collapse; }
    thead th {
      background: #f8fafc;
      padding: 13px 20px;
      text-align: left;
      font-size: .7rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: #94a3b8;
      border-bottom: 1px solid #e8ecf4;
      white-space: nowrap;
    }
    tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .12s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #fafbff; }
    tbody td { padding: 15px 20px; font-size: .88rem; vertical-align: middle; }

    .event-thumb {
      width: 46px; height: 46px;
      border-radius: 10px; object-fit: cover;
      border: 1px solid #e8ecf4; flex-shrink: 0;
    }
    .thumb-placeholder {
      width: 46px; height: 46px;
      border-radius: 10px;
      background: #f1f5f9;
      display: flex; align-items: center; justify-content: center;
      color: #cbd5e1; flex-shrink: 0;
    }
    .event-title    { font-weight: 600; color: #1e2535; }
    .event-venue-sm { font-size: .78rem; color: #94a3b8; margin-top: 2px; }

    .badge {
      display: inline-block;
      padding: 4px 11px; border-radius: 999px;
      font-size: .72rem; font-weight: 700;
      letter-spacing: .03em;
    }
    .badge-upcoming { background: #dcfce7; color: #16a34a; }
    .badge-past     { background: #f1f5f9; color: #94a3b8; }

    /* ── Action icons ── */
    .action-icons { display: flex; align-items: center; justify-content: center; gap: 20px; }
    .act-btn {
      background: none; border: none; cursor: pointer;
      font-size: 1rem; padding: 4px;
      transition: transform .15s, color .15s;
      text-decoration: none;
      display: inline-flex;
    }
    .act-btn:hover { transform: scale(1.2); }
    .act-edit   { color: #3b82f6; }
    .act-edit:hover   { color: #1d4ed8; }
    .act-share  { color: #22c55e; }
    .act-share:hover  { color: #15803d; }
    .act-share.copied { color: #6c63ff; }
    .act-delete { color: #ef4444; }
    .act-delete:hover { color: #b91c1c; }

    /* ── Card footer ── */
    .card-footer {
      padding: 12px 24px;
      border-top: 1px solid #f1f5f9;
      background: #fafbff;
      font-size: .78rem;
      color: #94a3b8;
      text-align: right;
    }

    /* ── Empty state ── */
    .empty { padding: 68px 20px; text-align: center; }
    .empty-icon {
      width: 76px; height: 76px; border-radius: 18px;
      background: #f1f5f9; margin: 0 auto 18px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.8rem; color: #cbd5e1;
    }
    .empty h3 { font-size: 1.15rem; font-weight: 700; color: #1e2535; margin-bottom: 7px; }
    .empty p  { color: #94a3b8; font-size: .88rem; margin-bottom: 22px; }

    /* ── Toast ── */
    .toast {
      position: fixed; bottom: 22px; right: 22px; z-index: 9999;
      display: flex; align-items: center; gap: 9px;
      padding: 13px 18px; border-radius: 12px;
      font-size: .85rem; font-weight: 600;
      box-shadow: 0 8px 28px rgba(0,0,0,.12);
      transform: translateY(14px); opacity: 0;
      transition: all .28s cubic-bezier(.34,1.5,.64,1);
      pointer-events: none; border: 1px solid;
    }
    .toast.show { transform: translateY(0); opacity: 1; }
    .toast-s { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
    .toast-e { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }

    @media(max-width:768px)  { .hide-md { display: none !important; } }
    @media(max-width:1024px) { .hide-lg { display: none !important; } }
  </style>
</head>
<body>

<!-- Preloader -->
<div id="preloader"><div class="loader-ring"></div></div>

<!-- Toast -->
<div id="toast" class="toast">
  <i id="toast-icon"></i>
  <span id="toast-msg"></span>
</div>

<!-- ── Page ── -->
<div class="page">

  <!-- Hero -->
  <div class="hero">
    <div class="hero-text">
      <div class="hero-title">My Tickets</div>
      <div class="hero-sub">Manage your events &amp; share with your audience</div>
    </div>
    <i class="fa-solid fa-ticket-simple hero-icon"></i>
  </div>

  <?php if ($message): ?>
  <div class="alert alert-<?= $message_type ?>">
    <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
    <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats">
    <div class="stat-card">
      <div class="stat-num"><?= $total ?></div>
      <div class="stat-label">Total Events</div>
    </div>
    <div class="stat-card upcoming">
      <div class="stat-num"><?= $upcoming ?></div>
      <div class="stat-label">Upcoming</div>
    </div>
    <div class="stat-card past">
      <div class="stat-num"><?= $past ?></div>
      <div class="stat-label">Past</div>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="toolbar">
    <div class="search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="searchInput" placeholder="Search events…">
    </div>
    <a href="?page=create_ticket" class="btn-create">
      <i class="fa-solid fa-plus"></i> Create Event
    </a>
  </div>

  <!-- Table card -->
  <div class="card">
    <?php if (empty($tickets)): ?>
      <div class="empty">
        <div class="empty-icon"><i class="fa-solid fa-ticket-simple"></i></div>
        <h3>No events yet</h3>
        <p>Create your first event and start selling tickets!</p>
        <a href="?page=create_ticket" class="btn-create" style="display:inline-flex">
          <i class="fa-solid fa-plus"></i> Create Event
        </a>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Event</th>
              <th class="hide-md">Venue</th>
              <th class="hide-lg">Date &amp; Time</th>
              <th style="text-align:center">Status</th>
              <th style="text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tickets as $ticket):
              $event_dt = null;
              try {
                $event_dt = new DateTime($ticket['start_date'].' '.$ticket['start_time'], new DateTimeZone('Africa/Nairobi'));
              } catch(Exception $e) {}
              $is_past    = $event_dt && $event_dt < $now;
              $ticket_url = "https://keychele.co.ke/ticket/" . $ticket['id'];
            ?>
            <tr class="event-row"
                data-title="<?= htmlspecialchars(strtolower($ticket['title'] ?? '')) ?>"
                data-venue="<?= htmlspecialchars(strtolower($ticket['venue'] ?? '')) ?>">

              <td>
                <div style="display:flex;align-items:center;gap:12px">
                  <?php if (!empty($ticket['banner'])): ?>
                    <img src="uploads/banners/<?= htmlspecialchars($ticket['banner']) ?>"
                         class="event-thumb" alt="">
                  <?php else: ?>
                    <div class="thumb-placeholder">
                      <i class="fa-solid fa-ticket-simple"></i>
                    </div>
                  <?php endif; ?>
                  <div>
                    <div class="event-title"><?= htmlspecialchars($ticket['title'] ?? 'Untitled') ?></div>
                    <div class="event-venue-sm"><?= htmlspecialchars($ticket['venue'] ?? '—') ?></div>
                  </div>
                </div>
              </td>

              <td class="hide-md" style="color:#64748b"><?= htmlspecialchars($ticket['venue'] ?? '—') ?></td>

              <td class="hide-lg" style="font-size:.83rem">
                <div style="font-weight:600;color:#1e2535">
                  <?= $ticket['start_date'] ? date('d M Y', strtotime($ticket['start_date'])) : 'TBD' ?>
                </div>
                <div style="color:#94a3b8"><?= $ticket['start_time'] ?: '—' ?> – <?= $ticket['end_time'] ?: '—' ?></div>
              </td>

              <td style="text-align:center">
                <span class="badge <?= $is_past ? 'badge-past' : 'badge-upcoming' ?>">
                  <?= $is_past ? 'Past' : 'Upcoming' ?>
                </span>
              </td>

              <td>
                <div class="action-icons">
                  <!-- Edit -->
                  <a href="?page=edit_ticket&id=<?= $ticket['id'] ?>"
                     class="act-btn act-edit" title="Edit event">
                    <i class="fa-solid fa-pen-to-square"></i>
                  </a>

                  <!-- Copy link — copies URL directly, no modal -->
                  <button
                    class="act-btn act-share share-trigger"
                    title="Copy ticket link"
                    data-url="<?= htmlspecialchars($ticket_url, ENT_QUOTES) ?>">
                    <i class="fa-solid fa-link"></i>
                  </button>

                  <!-- Delete -->
                  <a href="delete_ticket.php?id=<?= $ticket['id'] ?>"
                     class="act-btn act-delete" title="Delete event"
                     onclick="return confirm('Delete this event? This cannot be undone.')">
                    <i class="fa-solid fa-trash-can"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer">
        <?= $total ?> event<?= $total !== 1 ? 's' : '' ?> &nbsp;&middot;&nbsp;
        <?= $upcoming ?> upcoming &nbsp;&middot;&nbsp; <?= $past ?> past
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
// ── Preloader
window.addEventListener('load', function () {
  var p = document.getElementById('preloader');
  p.style.opacity = '0';
  setTimeout(function () { p.style.display = 'none'; }, 380);
});

// ── Search
document.getElementById('searchInput').addEventListener('input', function () {
  var t = this.value.toLowerCase().trim();
  document.querySelectorAll('.event-row').forEach(function (r) {
    r.style.display = (!t || r.dataset.title.includes(t) || r.dataset.venue.includes(t)) ? '' : 'none';
  });
});

// ── Toast
function showToast(msg, type) {
  var el = document.getElementById('toast');
  document.getElementById('toast-icon').className =
    type === 's' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
  document.getElementById('toast-msg').textContent = msg;
  el.className = 'toast toast-' + type + ' show';
  setTimeout(function () { el.classList.remove('show'); }, 3000);
}

// ── Copy link — clicking share button copies URL directly
document.querySelectorAll('.share-trigger').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var url = this.dataset.url;
    var icon = this.querySelector('i');
    var self = this;

    function onSuccess() {
      icon.className = 'fa-solid fa-check';
      self.classList.add('copied');
      showToast('Link copied to clipboard!', 's');
      setTimeout(function () {
        icon.className = 'fa-solid fa-link';
        self.classList.remove('copied');
      }, 2500);
    }

    function onFail() {
      showToast('Could not copy — please copy the link manually.', 'e');
    }

    function execCommandFallback() {
      try {
        var ta = document.createElement('textarea');
        ta.value = url;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
        document.body.appendChild(ta);
        ta.focus(); ta.select(); ta.setSelectionRange(0, ta.value.length);
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (ok) { onSuccess(); } else { onFail(); }
      } catch (err) { onFail(); }
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(onSuccess).catch(execCommandFallback);
    } else {
      execCommandFallback();
    }
  });
});

<?php if ($message): ?>
window.addEventListener('load', function () {
  showToast(<?= json_encode($message) ?>, '<?= $message_type === 'success' ? 's' : 'e' ?>');
});
<?php endif; ?>
</script>
</body>
</html>