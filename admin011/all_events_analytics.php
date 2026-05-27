<?php
// all_events_analytics.php
include '../msanii/includes/connect.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], [1, 2])) {
    header("Location: ../login.php");
    exit();
}

$total_revenue       = 0;
$total_tickets_sold  = 0;
$total_unique_buyers = 0;
$event_data          = [];
$daily_data          = [];
$completed_purchases = [];
$error               = null;

try {
    $query = "
        SELECT tp.*, t.title AS ticket_title, t.venue, t.category, t.price
        FROM ticket_purchases tp
        LEFT JOIN tickets t ON tp.ticket_id = t.id
        WHERE LOWER(TRIM(tp.status)) = 'completed'
        ORDER BY tp.created_at DESC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $completed_purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $global_buyers = [];

    foreach ($completed_purchases as $row) {
        $amount   = floatval($row['amount_paid'] ?? 0);
        $quantity = intval($row['quantity'] ?? 1);

        $total_revenue      += $amount;
        $total_tickets_sold += $quantity;

        $buyer_key = trim(strtolower(
            $row['buyer_email'] ?: $row['buyer_phone'] ?: $row['buyer_name'] ?: uniqid('anon_')
        ));
        if (!isset($global_buyers[$buyer_key])) {
            $global_buyers[$buyer_key] = true;
            $total_unique_buyers++;
        }

        $event_name = !empty($row['ticket_title']) ? trim($row['ticket_title']) : 'Unknown Event';

        if (!isset($event_data[$event_name])) {
            $event_data[$event_name] = [
                'revenue'       => 0,
                'tickets'       => 0,
                'purchases'     => 0,
                'unique_buyers' => 0,
                'venue'         => $row['venue'] ?? '—',
                'category'      => $row['category'] ?? '—',
                'buyers_list'   => [],
                'buyer_keys'    => []
            ];
        }

        $event_data[$event_name]['revenue']   += $amount;
        $event_data[$event_name]['tickets']   += $quantity;
        $event_data[$event_name]['purchases']++;

        $event_data[$event_name]['buyers_list'][] = [
            'name'     => $row['buyer_name']  ?? '—',
            'email'    => $row['buyer_email'] ?? '—',
            'phone'    => $row['buyer_phone'] ?? '—',
            'quantity' => $quantity,
            'total'    => $amount,
            'date'     => date('d M Y • H:i', strtotime($row['created_at'])),
        ];

        if (!in_array($buyer_key, $event_data[$event_name]['buyer_keys'])) {
            $event_data[$event_name]['buyer_keys'][] = $buyer_key;
            $event_data[$event_name]['unique_buyers']++;
        }

        $date_key = date('Y-m-d', strtotime($row['created_at']));
        if (!isset($daily_data[$date_key])) {
            $daily_data[$date_key] = [
                'date'    => date('d M Y', strtotime($row['created_at'])),
                'revenue' => 0,
                'tickets' => 0
            ];
        }
        $daily_data[$date_key]['revenue'] += $amount;
        $daily_data[$date_key]['tickets'] += $quantity;
    }

    uasort($event_data, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

    ksort($daily_data);
    $chart_labels  = array_column($daily_data, 'date');
    $chart_revenue = array_column($daily_data, 'revenue');
    $chart_tickets = array_column($daily_data, 'tickets');
    $event_labels  = array_keys($event_data);
    $event_revenue = array_column($event_data, 'revenue');

} catch (PDOException $e) {
    $error = "Database error: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>All Events Analytics — Keychele Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ══════════════════════════════════════════
   DESIGN TOKENS
══════════════════════════════════════════ */
:root {
  --bg:          #f0f2f8;
  --surface:     #ffffff;
  --surface-2:   #f7f8fc;
  --border:      #e4e8f0;
  --border-dark: #d0d6e8;

  --navy:        #0d1b3e;
  --blue:        #2563eb;
  --blue-h:      #1d4ed8;
  --blue-l:      #dbeafe;
  --blue-ll:     #eff6ff;

  --emerald:     #059669;
  --emerald-l:   #d1fae5;
  --amber:       #d97706;
  --amber-l:     #fef3c7;
  --red:         #dc2626;
  --red-l:       #fee2e2;
  --sky:         #0284c7;
  --sky-l:       #e0f2fe;
  --violet:      #7c3aed;
  --violet-l:    #ede9fe;
  --slate:       #64748b;
  --slate-l:     #f1f5f9;
  --pink:        #db2777;
  --pink-l:      #fce7f3;

  --text:        #0d1b3e;
  --text-2:      #4b5580;
  --text-3:      #8b95b8;

  --radius:      14px;
  --radius-sm:   9px;
  --shadow-sm:   0 1px 3px rgba(13,27,62,.06), 0 2px 8px rgba(13,27,62,.04);
  --shadow:      0 2px 8px rgba(13,27,62,.08), 0 8px 24px rgba(13,27,62,.06);
  --shadow-lg:   0 8px 32px rgba(13,27,62,.18);

  --font:        'Outfit', sans-serif;
  --mono:        'JetBrains Mono', monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  font-family: var(--font);
  color: var(--text);
  font-size: 14px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}

.shell { padding: 28px; max-width: 1680px; margin: 0 auto; }

/* ── PAGE HEADER ── */
.pg-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  margin-bottom: 26px; gap: 16px; flex-wrap: wrap;
}
.pg-header-left h1 {
  font-size: 1.55rem; font-weight: 800; letter-spacing: -.03em; color: var(--navy);
  display: flex; align-items: center; gap: 10px;
}
.pg-header-left h1 .icon-wrap {
  width: 38px; height: 38px; background: var(--blue); border-radius: 10px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: .85rem; color: #fff; flex-shrink: 0;
}
.pg-header-left p { color: var(--text-2); font-size: .82rem; margin-top: 3px; padding-left: 48px; }

/* ── KPI GRID ── */
.kpi-grid {
  display: grid; grid-template-columns: repeat(5, 1fr);
  gap: 14px; margin-bottom: 26px;
}
@media (max-width: 1100px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 600px)  { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

.kpi {
  background: var(--surface); border-radius: var(--radius);
  box-shadow: var(--shadow-sm); padding: 18px 20px;
  position: relative; overflow: hidden;
  transition: transform .2s, box-shadow .2s;
  border: 1px solid var(--border);
}
.kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
.kpi-bar {
  position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
  border-radius: var(--radius) 0 0 var(--radius);
}
.kpi.c-blue   .kpi-bar { background: var(--blue); }
.kpi.c-green  .kpi-bar { background: var(--emerald); }
.kpi.c-sky    .kpi-bar { background: var(--sky); }
.kpi.c-violet .kpi-bar { background: var(--violet); }
.kpi.c-pink   .kpi-bar { background: var(--pink); }
.kpi.c-amber  .kpi-bar { background: var(--amber); }

.kpi-icon {
  width: 36px; height: 36px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem; margin-bottom: 12px;
}
.kpi.c-blue   .kpi-icon { background: var(--blue-l);   color: var(--blue); }
.kpi.c-green  .kpi-icon { background: var(--emerald-l);color: var(--emerald); }
.kpi.c-sky    .kpi-icon { background: var(--sky-l);    color: var(--sky); }
.kpi.c-violet .kpi-icon { background: var(--violet-l); color: var(--violet); }
.kpi.c-pink   .kpi-icon { background: var(--pink-l);   color: var(--pink); }
.kpi.c-amber  .kpi-icon { background: var(--amber-l);  color: var(--amber); }

.kpi-label {
  font-size: .7rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--text-3); margin-bottom: 4px;
}
.kpi-value { font-size: 1.8rem; font-weight: 800; line-height: 1; letter-spacing: -.04em; }
.kpi-value.sm { font-size: 1.3rem; }
.kpi.c-blue   .kpi-value { color: var(--blue); }
.kpi.c-green  .kpi-value { color: var(--emerald); }
.kpi.c-sky    .kpi-value { color: var(--sky); }
.kpi.c-violet .kpi-value { color: var(--violet); }
.kpi.c-pink   .kpi-value { color: var(--pink); }
.kpi.c-amber  .kpi-value { color: var(--amber); }
.kpi-sub { font-size: .72rem; color: var(--text-3); margin-top: 3px; }

/* ── CARD ── */
.card {
  background: var(--surface); border-radius: var(--radius);
  box-shadow: var(--shadow-sm); border: 1px solid var(--border); overflow: hidden;
}
.card-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border);
  flex-wrap: wrap; background: var(--surface-2);
}
.card-title { font-size: .92rem; font-weight: 700; color: var(--navy); display: flex; align-items: center; gap: 8px; }
.card-title i { color: var(--blue); font-size: .8rem; }
.card-meta { font-size: .76rem; color: var(--text-3); }

/* ── CHARTS ROW ── */
.charts-row {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 16px; margin-bottom: 20px;
}
@media (max-width: 860px) { .charts-row { grid-template-columns: 1fr; } }

.chart-body {
  padding: 20px; min-height: 260px;
  display: flex; align-items: center; justify-content: center;
}
.chart-body canvas { width: 100% !important; }

/* ── TABLE ── */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
  padding: 10px 14px; background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-size: .68rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: var(--text-3); white-space: nowrap; user-select: none;
}
tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--blue-ll); }
td { padding: 11px 14px; font-size: .82rem; color: var(--text); vertical-align: middle; }

/* ── EVENT CELL ── */
.event-cell { display: flex; align-items: center; gap: 10px; }
.event-avatar {
  width: 34px; height: 34px; border-radius: 9px;
  background: var(--violet-l); color: var(--violet);
  font-weight: 800; font-size: .68rem; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}
.event-name  { font-weight: 600; font-size: .82rem; line-height: 1.25; }
.event-venue { font-size: .69rem; color: var(--text-3); font-family: var(--mono); }

/* ── BADGES ── */
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 10px; border-radius: 100px;
  font-size: .67rem; font-weight: 700; letter-spacing: .02em; white-space: nowrap;
}
.badge-cat { background: var(--sky-l); color: #075985; }

/* ── AMOUNT ── */
.amt-rev   { font-weight: 700; color: var(--blue); }
.amt-green { font-weight: 600; color: var(--emerald); }

/* ── BUYERS BTN ── */
.btn-buyers {
  height: 30px; padding: 0 12px; border: 1.5px solid var(--blue);
  border-radius: var(--radius-sm); background: var(--blue-ll);
  font-family: var(--font); font-size: .76rem; font-weight: 600;
  color: var(--blue); cursor: pointer;
  display: inline-flex; align-items: center; gap: 5px;
  transition: all .15s;
}
.btn-buyers:hover { background: var(--blue); color: #fff; }

/* ── EMPTY ── */
.empty { text-align: center; padding: 60px 20px; color: var(--text-3); }
.empty i { font-size: 2rem; opacity: .3; display: block; margin-bottom: 12px; }
.empty p  { font-size: .85rem; }

/* ── ALERTS ── */
.alert-err-ui {
  background: var(--red-l); border: 1px solid #fca5a5;
  border-radius: var(--radius-sm); padding: 12px 16px;
  color: #991b1b; font-size: .84rem; margin-bottom: 20px;
  display: flex; align-items: center; gap: 8px;
}

/* ── MODAL OVERLAY ── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(13,27,62,.45); backdrop-filter: blur(4px);
  z-index: 9000; align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.open { display: flex; }

.modal-box {
  background: var(--surface); border-radius: var(--radius);
  box-shadow: var(--shadow-lg); width: 100%; max-width: 900px;
  max-height: 88vh; display: flex; flex-direction: column;
  animation: modalIn .2s ease;
}
@keyframes modalIn {
  from { opacity: 0; transform: translateY(20px) scale(.97); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

.modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px; border-bottom: 1px solid var(--border);
  background: var(--surface-2); border-radius: var(--radius) var(--radius) 0 0;
  gap: 12px; flex-wrap: wrap;
}
.modal-head-title {
  font-size: .95rem; font-weight: 800; color: var(--navy);
  display: flex; align-items: center; gap: 8px;
}
.modal-head-title i { color: var(--blue); }
.modal-event-name {
  font-size: .78rem; font-weight: 500; color: var(--text-2);
  background: var(--blue-l); padding: 2px 10px; border-radius: 100px;
}
.modal-close {
  width: 32px; height: 32px; border: 1.5px solid var(--border-dark);
  border-radius: 8px; background: var(--surface); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: .75rem; color: var(--text-3); transition: all .15s; flex-shrink: 0;
}
.modal-close:hover { border-color: var(--red); color: var(--red); background: var(--red-l); }

.modal-body-scroll {
  overflow-y: auto; flex: 1;
}
.modal-body-scroll table { min-width: 620px; }
.modal-body-scroll thead th {
  position: sticky; top: 0; z-index: 1;
  background: var(--surface-2);
}
.modal-foot {
  padding: 14px 22px; border-top: 1px solid var(--border);
  background: var(--surface-2); border-radius: 0 0 var(--radius) var(--radius);
  display: flex; justify-content: flex-end;
}
.btn-close-modal {
  height: 34px; padding: 0 18px; border: 1.5px solid var(--border-dark);
  border-radius: var(--radius-sm); background: var(--surface);
  font-family: var(--font); font-size: .8rem; font-weight: 600;
  color: var(--text-2); cursor: pointer; transition: all .15s;
}
.btn-close-modal:hover { border-color: var(--navy); color: var(--navy); }

@media (max-width: 680px) {
  .shell { padding: 14px; }
}
</style>
</head>
<body>
<div class="shell">

  <!-- ── PAGE HEADER ── -->
  <div class="pg-header">
    <div class="pg-header-left">
      <h1>
        <span class="icon-wrap"><i class="fas fa-chart-line"></i></span>
        All Events Analytics
      </h1>
      <p>Completed payments only · Grouped by ticket / event title · All time</p>
    </div>
  </div>

  <!-- ── ERROR ── -->
  <?php if ($error): ?>
    <div class="alert-err-ui"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
  <?php endif; ?>

  <!-- ── KPI STRIP ── -->
  <div class="kpi-grid">
    <div class="kpi c-blue">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-wallet"></i></div>
      <div class="kpi-label">Total Revenue</div>
      <div class="kpi-value sm">KSh <?= number_format($total_revenue, 0) ?></div>
      <div class="kpi-sub">completed only</div>
    </div>
    <div class="kpi c-green">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-ticket-alt"></i></div>
      <div class="kpi-label">Tickets Sold</div>
      <div class="kpi-value"><?= number_format($total_tickets_sold) ?></div>
      <div class="kpi-sub">all events</div>
    </div>
    <div class="kpi c-sky">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-users"></i></div>
      <div class="kpi-label">Unique Buyers</div>
      <div class="kpi-value"><?= number_format($total_unique_buyers) ?></div>
      <div class="kpi-sub">global unique</div>
    </div>
    <div class="kpi c-violet">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-calendar-check"></i></div>
      <div class="kpi-label">Events / Tickets</div>
      <div class="kpi-value"><?= number_format(count($event_data)) ?></div>
      <div class="kpi-sub">distinct titles</div>
    </div>
    <div class="kpi c-pink">
      <div class="kpi-bar"></div>
      <div class="kpi-icon"><i class="fas fa-receipt"></i></div>
      <div class="kpi-label">Transactions</div>
      <div class="kpi-value"><?= number_format(count($completed_purchases)) ?></div>
      <div class="kpi-sub">successful</div>
    </div>
  </div>

  <!-- ── CHARTS ── -->
  <div class="charts-row" style="margin-bottom:20px">
    <!-- Trend -->
    <div class="card">
      <div class="card-head">
        <div class="card-title"><i class="fas fa-chart-area"></i> Revenue &amp; Tickets Trend</div>
        <div class="card-meta">daily view</div>
      </div>
      <div class="chart-body">
        <?php if (!empty($chart_labels)): ?>
          <canvas id="trendChart"></canvas>
        <?php else: ?>
          <div class="empty" style="padding:40px 20px">
            <i class="fas fa-chart-area"></i>
            <p>No trend data yet</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- By Event -->
    <div class="card">
      <div class="card-head">
        <div class="card-title"><i class="fas fa-chart-bar"></i> Revenue by Event</div>
        <div class="card-meta">sorted desc</div>
      </div>
      <div class="chart-body">
        <?php if (!empty($event_data)): ?>
          <canvas id="eventChart"></canvas>
        <?php else: ?>
          <div class="empty" style="padding:40px 20px">
            <i class="fas fa-ticket-alt"></i>
            <p>No completed sales</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── MAIN TABLE CARD ── -->
  <div class="card">
    <div class="card-head">
      <div class="card-title">
        <i class="fas fa-ticket-alt"></i>
        Events &amp; Tickets
        <span style="font-weight:400;font-size:.76rem;color:var(--text-3)">
          (<?= number_format(count($event_data)) ?> total)
        </span>
      </div>
      <div class="card-meta"><?= number_format(count($completed_purchases)) ?> completed transactions</div>
    </div>

    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:36px">#</th>
            <th>Ticket / Event</th>
            <th>Category</th>
            <th class="text-right">Revenue</th>
            <th class="text-right" style="text-align:right">Tickets</th>
            <th class="text-right" style="text-align:right">Purchases</th>
            <th class="text-right" style="text-align:right">Unique Buyers</th>
            <th style="text-align:right">Buyers</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($event_data)): ?>
            <?php $sn = 1; foreach ($event_data as $event_name => $stats):
              $initials = strtoupper(implode('', array_map(
                fn($w) => substr($w, 0, 1),
                array_slice(explode(' ', $event_name), 0, 2)
              )));
            ?>
            <tr>
              <td style="color:var(--text-3);font-size:.75rem;font-family:var(--mono)"><?= $sn++ ?></td>
              <td>
                <div class="event-cell">
                  <div class="event-avatar"><?= htmlspecialchars($initials) ?></div>
                  <div>
                    <div class="event-name"><?= htmlspecialchars($event_name) ?></div>
                    <div class="event-venue"><i class="fas fa-map-marker-alt" style="font-size:.6rem;margin-right:3px"></i><?= htmlspecialchars($stats['venue']) ?></div>
                  </div>
                </div>
              </td>
              <td>
                <?php if ($stats['category'] !== '—'): ?>
                  <span class="badge badge-cat">
                    <i class="fas fa-tag" style="font-size:.55rem"></i>
                    <?= htmlspecialchars($stats['category']) ?>
                  </span>
                <?php else: ?>
                  <span style="color:var(--text-3);font-size:.76rem">—</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right">
                <span class="amt-rev">KSh <?= number_format($stats['revenue'], 2) ?></span>
              </td>
              <td style="text-align:right;font-weight:600">
                <?= number_format($stats['tickets']) ?>
              </td>
              <td style="text-align:right;color:var(--text-2)">
                <?= number_format($stats['purchases']) ?>
              </td>
              <td style="text-align:right">
                <span class="amt-green"><?= number_format($stats['unique_buyers']) ?></span>
              </td>
              <td style="text-align:right">
                <?php if (!empty($stats['buyers_list'])): ?>
                  <button class="btn-buyers view-buyers"
                          data-event="<?= htmlspecialchars($event_name) ?>"
                          data-buyers='<?= htmlspecialchars(json_encode($stats['buyers_list'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                    <i class="fas fa-users"></i> View
                  </button>
                <?php else: ?>
                  <span style="color:var(--text-3);font-size:.76rem">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="8">
              <div class="empty">
                <i class="fas fa-ticket-alt"></i>
                <p>No completed payments found.</p>
              </div>
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div><!-- /card -->

</div><!-- /shell -->

<!-- ── BUYERS MODAL ── -->
<div class="modal-overlay" id="buyersOverlay">
  <div class="modal-box">
    <div class="modal-head">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1;min-width:0">
        <div class="modal-head-title"><i class="fas fa-users"></i> Buyers</div>
        <div class="modal-event-name" id="modalEventName">—</div>
      </div>
      <button class="modal-close" id="modalCloseBtn" aria-label="Close">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-body-scroll">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th style="text-align:right">Qty</th>
            <th style="text-align:right">Total Paid</th>
            <th>Purchase Date</th>
          </tr>
        </thead>
        <tbody id="modalBuyersBody"></tbody>
      </table>
    </div>
    <div class="modal-foot">
      <button class="btn-close-modal" id="modalCloseBtn2">
        <i class="fas fa-times" style="margin-right:5px;font-size:.75rem"></i> Close
      </button>
    </div>
  </div>
</div>

<script>
/* ── CHARTS ── */
const chartDefaults = {
  font: 'Outfit, sans-serif',
  gridColor: 'rgba(13,27,62,.06)',
  textColor: '#8b95b8'
};

const trendCtx = document.getElementById('trendChart')?.getContext('2d');
if (trendCtx) {
  new Chart(trendCtx, {
    type: 'line',
    data: {
      labels: <?= json_encode($chart_labels) ?>,
      datasets: [
        {
          label: 'Revenue (KSh)',
          data: <?= json_encode($chart_revenue) ?>,
          borderColor: '#2563eb',
          backgroundColor: 'rgba(37,99,235,.08)',
          borderWidth: 2.5,
          tension: 0.35,
          fill: true,
          pointRadius: 3,
          pointBackgroundColor: '#2563eb'
        },
        {
          label: 'Tickets Sold',
          data: <?= json_encode($chart_tickets) ?>,
          borderColor: '#059669',
          backgroundColor: 'transparent',
          borderWidth: 2,
          tension: 0.35,
          fill: false,
          yAxisID: 'y1',
          pointRadius: 3,
          pointBackgroundColor: '#059669'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          labels: { font: { family: 'Outfit', size: 11 }, color: '#4b5580', boxWidth: 12 }
        }
      },
      scales: {
        x:  { ticks: { font: { family: 'Outfit', size: 10 }, color: chartDefaults.textColor }, grid: { color: chartDefaults.gridColor } },
        y:  { position: 'left',  ticks: { font: { family: 'Outfit', size: 10 }, color: chartDefaults.textColor }, grid: { color: chartDefaults.gridColor } },
        y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { font: { family: 'Outfit', size: 10 }, color: '#059669' } }
      }
    }
  });
}

const eventCtx = document.getElementById('eventChart')?.getContext('2d');
if (eventCtx) {
  new Chart(eventCtx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($event_labels) ?>,
      datasets: [{
        label: 'Revenue (KSh)',
        data: <?= json_encode($event_revenue) ?>,
        backgroundColor: 'rgba(124,58,237,.75)',
        borderRadius: 6,
        borderSkipped: false
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      indexAxis: 'y',
      plugins: {
        legend: { display: false }
      },
      scales: {
        x: {
          ticks: { font: { family: 'Outfit', size: 10 }, color: chartDefaults.textColor },
          grid:  { color: chartDefaults.gridColor },
          title: { display: true, text: 'Revenue (KSh)', font: { family: 'Outfit', size: 10 }, color: chartDefaults.textColor }
        },
        y: {
          ticks: { font: { family: 'Outfit', size: 10 }, color: chartDefaults.textColor },
          grid:  { color: chartDefaults.gridColor }
        }
      }
    }
  });
}

/* ── BUYERS MODAL ── */
const overlay    = document.getElementById('buyersOverlay');
const modalName  = document.getElementById('modalEventName');
const modalBody  = document.getElementById('modalBuyersBody');

function openModal(eventName, buyers) {
  modalName.textContent = eventName;
  modalBody.innerHTML   = '';

  if (!buyers.length) {
    modalBody.innerHTML = `<tr><td colspan="7"><div class="empty" style="padding:40px 20px"><i class="fas fa-users"></i><p>No buyers recorded for this event.</p></div></td></tr>`;
  } else {
    buyers.forEach((b, i) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td style="color:var(--text-3);font-size:.74rem;font-family:var(--mono)">${i+1}</td>
        <td style="font-weight:600">${b.name}</td>
        <td style="font-family:var(--mono);font-size:.76rem;color:var(--text-2)">${b.email}</td>
        <td style="font-family:var(--mono);font-size:.76rem">${b.phone}</td>
        <td style="text-align:right;font-weight:600">${b.quantity}</td>
        <td style="text-align:right"><span style="font-weight:700;color:var(--blue)">KSh ${Number(b.total).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2})}</span></td>
        <td style="font-size:.76rem;color:var(--text-2)">${b.date}</td>
      `;
      modalBody.appendChild(tr);
    });
  }
  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal() {
  overlay.classList.remove('open');
  document.body.style.overflow = '';
}

document.querySelectorAll('.view-buyers').forEach(btn => {
  btn.addEventListener('click', function () {
    const eventName = this.getAttribute('data-event');
    const buyers    = JSON.parse(this.getAttribute('data-buyers'));
    openModal(eventName, buyers);
  });
});

document.getElementById('modalCloseBtn').addEventListener('click',  closeModal);
document.getElementById('modalCloseBtn2').addEventListener('click', closeModal);
overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>