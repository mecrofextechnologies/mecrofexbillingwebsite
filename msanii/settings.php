<?php
/*
 * settings.php  –  Keychele Creator Dashboard
 * Loaded via: msanii/index.php?page=settings
 * Requires:   $_SESSION['creator_id'], $pdo
 */

if (empty($_SESSION['creator_id'])) {
    header('Location: login.php'); exit;
}
$creator_id = (int)$_SESSION['creator_id'];

// ── AJAX handler ─────────────────────────────────────────────────
// Detect AJAX by header OR by a plain _ajax POST field (fallback for
// some server configs that strip X-Requested-With)
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || (isset($_POST['_ajax']) && $_POST['_ajax'] === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    // Prevent any accidental HTML output from the parent shell
    while (ob_get_level()) ob_end_clean();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_notification_setting') {
        $field   = $_POST['field'] ?? '';
        $value   = isset($_POST['value']) ? (int)(bool)$_POST['value'] : 0;
        $allowed = ['notify_ticket_views'];

        if (!in_array($field, $allowed, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid field.']); exit;
        }
        try {
            $pdo->prepare("
                INSERT INTO creator_notification_settings (creator_id, `{$field}`)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE `{$field}` = VALUES(`{$field}`), updated_at = NOW()
            ")->execute([$creator_id, $value]);
            echo json_encode(['success' => true, 'value' => $value]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'mark_notifs_read') {
        try {
            $pdo->prepare("UPDATE creator_notifications SET is_read = 1 WHERE creator_id = ?")->execute([$creator_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
}

// ── Load notification settings ───────────────────────────────────
try {
    $ns = $pdo->prepare("SELECT * FROM creator_notification_settings WHERE creator_id = ?");
    $ns->execute([$creator_id]);
    $notif_settings = $ns->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $notif_settings = []; }

$notify_ticket_views = isset($notif_settings['notify_ticket_views'])
    ? (bool)$notif_settings['notify_ticket_views'] : true;

// ── Unread count ─────────────────────────────────────────────────
try {
    $uc = $pdo->prepare("SELECT COUNT(*) FROM creator_notifications WHERE creator_id = ? AND is_read = 0");
    $uc->execute([$creator_id]);
    $unread_count = (int)$uc->fetchColumn();
} catch (PDOException $e) { $unread_count = 0; }

// ── Recent notifications (last 40) ───────────────────────────────
try {
    $rn = $pdo->prepare("
        SELECT cn.*, t.title AS event_title, t.id AS ticket_event_id
        FROM creator_notifications cn
        LEFT JOIN tickets t ON cn.ticket_id = t.id
        WHERE cn.creator_id = ?
        ORDER BY cn.created_at DESC
        LIMIT 40
    ");
    $rn->execute([$creator_id]);
    $recent_notifs = $rn->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $recent_notifs = []; }

// ── Build the AJAX endpoint URL robustly ─────────────────────────
// Works whether the page is loaded as ?page=settings or directly
$_stg_ajax_url = strtok($_SERVER['REQUEST_URI'], '?')
    . '?page=settings&_t=' . time();
?>

<style>
/* ══════════════════════════════════════════════
   Settings Page — Keychele Creator Dashboard
══════════════════════════════════════════════ */
.stg-wrap  { max-width: 860px; margin: 0 auto; padding: 28px 0 70px; }

.stg-page-hd { margin-bottom: 28px; }
.stg-page-hd h2 {
    font-family: 'Sora', sans-serif; font-weight: 800; font-size: 22px;
    color: var(--navy, #032d5d); margin: 0 0 4px;
    display: flex; align-items: center; gap: 10px;
}
.stg-page-hd h2 i { color: #f59e0b; font-size: 20px; }
.stg-page-hd p { font-size: 13px; color: var(--text-muted, #94a3b8); margin: 0; }
.stg-hd-bar { width: 40px; height: 4px; background: #fec411; border-radius: 2px; margin-top: 10px; }

.stg-tabs {
    display: flex; gap: 4px;
    background: #f1f5f9; border-radius: 14px;
    padding: 5px; margin-bottom: 26px;
    border: 1px solid #e2e8f0;
    width: fit-content; flex-wrap: wrap;
}
.stg-tab {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px; border-radius: 10px;
    font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600;
    color: #64748b; background: transparent; border: none;
    cursor: pointer; transition: all .22s cubic-bezier(.4,0,.2,1);
    position: relative; white-space: nowrap;
}
.stg-tab i { font-size: 12px; }
.stg-tab:hover { color: var(--navy, #032d5d); background: rgba(255,255,255,.7); }
.stg-tab.active {
    background: #fff; color: var(--navy, #032d5d);
    box-shadow: 0 2px 10px rgba(3,45,93,.10); font-weight: 700;
}
.stg-tab.active i { color: #fec411; }
.stg-notif-badge {
    background: #dc2626; color: #fff;
    font-size: 10px; font-weight: 800; min-width: 18px; height: 18px;
    border-radius: 9px; padding: 0 5px;
    display: inline-flex; align-items: center; justify-content: center;
}

.stg-panel { display: none; animation: stgFadeIn .25s ease both; }
.stg-panel.active { display: block; }
@keyframes stgFadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

.stg-card {
    background: #fff; border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(3,45,93,.08);
    overflow: hidden; margin-bottom: 20px;
}
.stg-card-hd {
    display: flex; align-items: center; gap: 14px;
    padding: 20px 24px; border-bottom: 1.5px solid #f1f5f9;
    background: #fafbfe;
}
.stg-card-ico {
    width: 42px; height: 42px; border-radius: 11px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 17px;
}
.stg-ico-navy   { background: rgba(3,45,93,.1);    color: var(--navy, #032d5d); }
.stg-ico-amber  { background: rgba(245,158,11,.12); color: #d97706; }
.stg-ico-indigo { background: rgba(79,70,229,.10);  color: #4f46e5; }
.stg-card-ttl { font-family: 'Sora', sans-serif; font-weight: 700; font-size: 15px; color: var(--navy, #032d5d); }
.stg-card-sub { font-size: 12px; color: #94a3b8; margin-top: 3px; }

.stg-row {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 20px 24px; border-bottom: 1px solid #f8fafc; gap: 24px;
}
.stg-row:last-child { border-bottom: none; }
.stg-row-info h4 {
    font-family: 'Sora', sans-serif; font-weight: 700; font-size: 14px;
    color: var(--navy, #032d5d); margin: 0 0 4px;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.stg-row-info p { font-size: 12.5px; color: #64748b; line-height: 1.6; margin: 0; }

.stg-status {
    font-family: 'Sora', sans-serif; font-size: 10px; font-weight: 800;
    letter-spacing: .8px; padding: 3px 10px; border-radius: 20px; transition: all .25s;
}
.stg-status.on  { background: #d1fae5; color: #065f46; }
.stg-status.off { background: #fee2e2; color: #991b1b; }

.stg-toggle      { position: relative; width: 52px; height: 28px; flex-shrink: 0; cursor: pointer; margin-top: 2px; }
.stg-toggle input{ opacity: 0; width: 0; height: 0; position: absolute; }
.stg-slider {
    position: absolute; inset: 0; background: #dde3f0;
    border-radius: 28px; transition: background .25s;
}
.stg-slider::before {
    content: ''; position: absolute;
    width: 22px; height: 22px; border-radius: 50%; background: #fff;
    top: 3px; left: 3px; box-shadow: 0 2px 6px rgba(0,0,0,.18);
    transition: transform .25s;
}
.stg-toggle input:checked + .stg-slider { background: var(--navy, #032d5d); }
.stg-toggle input:checked + .stg-slider::before { transform: translateX(24px); }

.stg-callout {
    display: flex; align-items: flex-start; gap: 12px;
    border-radius: 10px; padding: 14px 16px; margin: 4px 24px 18px;
}
.stg-callout-navy  { background: #eff6ff; border: 1.5px solid #bfdbfe; }
.stg-callout-amber { background: #fffbeb; border: 1.5px solid #fde68a; }
.stg-callout i { font-size: 14px; margin-top: 1px; flex-shrink: 0; }
.stg-callout-navy  i { color: #2563eb; }
.stg-callout-amber i { color: #d97706; }
.stg-callout p { font-size: 12.5px; line-height: 1.6; margin: 0; }
.stg-callout-navy  p { color: #1e40af; }
.stg-callout-amber p { color: #78350f; }

.stg-log-empty { padding: 46px 24px; text-align: center; color: #94a3b8; }
.stg-log-empty i { font-size: 38px; display: block; margin-bottom: 12px; opacity: .3; }
.stg-log-empty p { font-size: 13.5px; }

.stg-notif-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 15px 24px; border-bottom: 1px solid #f8fafc;
    transition: background .15s; position: relative;
}
.stg-notif-item:last-child { border-bottom: none; }
.stg-notif-item:hover { background: #f8faff; }
.stg-notif-item.unread::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; background: #fec411; border-radius: 0 3px 3px 0;
}
.stg-notif-ico {
    width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 14px;
}
.stg-ico-view  { background: #eff6ff; color: #2563eb; }
.stg-ico-group { background: #ede9fe; color: #7c3aed; }
.stg-notif-body { flex: 1; min-width: 0; }
.stg-notif-msg  { font-size: 13px; color: #475569; line-height: 1.55; }
.stg-notif-msg strong { color: var(--navy, #032d5d); }
.stg-notif-evt {
    font-size: 11.5px; color: #94a3b8; margin-top: 3px;
    display: flex; align-items: center; gap: 5px;
}
.stg-notif-evt a { color: var(--navy, #032d5d); font-weight: 600; text-decoration: none; }
.stg-notif-evt a:hover { text-decoration: underline; }
.stg-notif-time { font-size: 11px; color: #94a3b8; white-space: nowrap; text-align: right; flex-shrink: 0; margin-top: 2px; }
.stg-unread-dot { width: 8px; height: 8px; border-radius: 50%; background: #fec411; flex-shrink: 0; margin-top: 6px; }

.stg-log-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 24px; border-bottom: 1px solid #f1f5f9;
    background: #fafbfe; gap: 12px; flex-wrap: wrap;
}
.stg-log-bar-ttl { font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .07em; }
.stg-mark-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 8px; border: 1.5px solid #e2e8f0;
    background: #fff; font-family: 'Sora', sans-serif;
    font-size: 11.5px; font-weight: 600; color: #64748b;
    cursor: pointer; transition: all .2s;
}
.stg-mark-btn:hover { border-color: var(--navy, #032d5d); color: var(--navy, #032d5d); background: #f0f4fa; }
.stg-unread-badge {
    display: inline-flex; align-items: center; justify-content: center;
    background: #dc2626; color: #fff; font-family: 'Sora', sans-serif;
    font-size: 10px; font-weight: 800; min-width: 20px; height: 20px;
    border-radius: 10px; padding: 0 5px; margin-left: 6px;
}

.stg-toast {
    position: fixed; top: 22px; right: 22px; z-index: 9999;
    background: var(--navy, #032d5d); color: #fff;
    padding: 13px 20px; border-radius: 12px; font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 600;
    display: flex; align-items: center; gap: 10px;
    box-shadow: 0 8px 28px rgba(3,45,93,.30);
    transform: translateY(-90px); opacity: 0;
    transition: all .35s cubic-bezier(.34,1.56,.64,1);
    pointer-events: none; min-width: 220px;
}
.stg-toast.show { transform: translateY(0); opacity: 1; pointer-events: auto; }
.stg-toast.ok i { color: #6ee7b7; }
.stg-toast.err  { background: #b91c1c; }
.stg-toast.err i { color: #fca5a5; }

.stg-divider { height: 1px; background: #f1f5f9; margin: 4px 24px; }

@media (max-width: 640px) {
    .stg-wrap { padding: 14px 0 60px; }
    .stg-tabs { width: 100%; }
    .stg-tab  { flex: 1; justify-content: center; padding: 9px 12px; font-size: 12px; }
    .stg-row  { padding: 16px; }
    .stg-card-hd { padding: 15px 16px; }
    .stg-notif-item { padding: 13px 16px; }
    .stg-callout { margin: 4px 16px 14px; }
    .stg-log-bar { padding: 10px 16px; }
}
</style>

<!-- Toast -->
<div id="stgToast" class="stg-toast ok">
    <i class="fas fa-check-circle"></i>
    <span id="stgToastMsg">Saved</span>
</div>

<div class="stg-wrap">

    <div class="stg-page-hd">
        <h2><i class="fas fa-sliders"></i> Settings</h2>
        <p>Manage your account preferences and notification behaviour.</p>
        <div class="stg-hd-bar"></div>
    </div>

    <div class="stg-tabs" role="tablist">
        <button class="stg-tab active" data-tab="general" role="tab" aria-selected="true">
            <i class="fas fa-user-gear"></i> General
        </button>
        <button class="stg-tab" data-tab="notifications" role="tab" aria-selected="false">
            <i class="fas fa-bell"></i> Notifications
            <?php if ($unread_count > 0): ?>
                <span class="stg-notif-badge" id="tabUnreadBadge"><?= $unread_count ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- ── TAB 1: GENERAL ── -->
    <div class="stg-panel active" id="panel-general">
        <div class="stg-card">
            <div class="stg-card-hd">
                <div class="stg-card-ico stg-ico-navy"><i class="fas fa-circle-user"></i></div>
                <div>
                    <div class="stg-card-ttl">Account</div>
                    <div class="stg-card-sub">Your profile and login preferences</div>
                </div>
            </div>

            <div class="stg-row">
                <div class="stg-row-info">
                    <h4><i class="fas fa-id-card" style="color:#94a3b8;font-size:12px"></i> Profile</h4>
                    <p>Update your display name, bio and social links from your <strong>My Page</strong> section.</p>
                </div>
                <a href="index.php?page=my_page"
                   style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;background:#f1f5f9;color:#032d5d;font-family:'Sora',sans-serif;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #e2e8f0;white-space:nowrap;"
                   onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                    <i class="fas fa-arrow-right" style="font-size:11px"></i> Go to My Page
                </a>
            </div>

            <div class="stg-divider"></div>

            <div class="stg-row">
                <div class="stg-row-info">
                    <h4><i class="fas fa-lock" style="color:#94a3b8;font-size:12px"></i> Password</h4>
                    <p>Change your account password from the security section.</p>
                </div>
                <a href="index.php?page=change_password"
                   style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;background:#f1f5f9;color:#032d5d;font-family:'Sora',sans-serif;font-size:12px;font-weight:700;text-decoration:none;border:1.5px solid #e2e8f0;white-space:nowrap;"
                   onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                    <i class="fas fa-arrow-right" style="font-size:11px"></i> Change Password
                </a>
            </div>
        </div>
    </div>

    <!-- ── TAB 2: NOTIFICATIONS ── -->
    <div class="stg-panel" id="panel-notifications">

        <div class="stg-card">
            <div class="stg-card-hd">
                <div class="stg-card-ico stg-ico-amber"><i class="fas fa-ticket-alt"></i></div>
                <div>
                    <div class="stg-card-ttl">Ticket Page Alerts</div>
                    <div class="stg-card-sub">Email alerts when people view your event ticket pages</div>
                </div>
            </div>

            <div class="stg-row">
                <div class="stg-row-info">
                    <h4>
                        Ticket Page View Alerts
                        <span class="stg-status <?= $notify_ticket_views ? 'on' : 'off' ?>"
                              id="statusTicketViews">
                            <?= $notify_ticket_views ? 'ON' : 'OFF' ?>
                        </span>
                    </h4>
                    <p>
                        Get an email every time someone lands on one of your ticket pages.
                        Views are grouped into <strong>15-minute windows</strong> — so if 10 people
                        visit in the same window you get one email saying
                        <em style="color:#032d5d">"1 person from Nairobi and 9 others viewed your ticket."</em>
                    </p>
                </div>
                <label class="stg-toggle" title="Toggle ticket page view alerts">
                    <input type="checkbox"
                           id="togTicketViews"
                           <?= $notify_ticket_views ? 'checked' : '' ?>
                           onchange="stgSave('notify_ticket_views', this.checked, 'statusTicketViews', this)">
                    <span class="stg-slider"></span>
                </label>
            </div>

            <div class="stg-callout stg-callout-navy">
                <i class="fas fa-circle-info"></i>
                <p>
                    <strong>What you receive:</strong> Each alert shows the visitor's location, device,
                    browser and OS. The first visit in a window triggers the email immediately;
                    additional visits in the same window are counted and summarised.
                    Toggle <strong>OFF</strong> to stop all view-alert emails instantly.
                </p>
            </div>
        </div>

        <!-- ── Recent Notifications log ── -->
        <div class="stg-card">
            <div class="stg-card-hd">
                <div class="stg-card-ico stg-ico-indigo"><i class="fas fa-clock-rotate-left"></i></div>
                <div>
                    <div class="stg-card-ttl">
                        Recent Alerts
                        <?php if ($unread_count > 0): ?>
                            <span class="stg-unread-badge" id="unreadBadge"><?= $unread_count ?> unread</span>
                        <?php endif; ?>
                    </div>
                    <div class="stg-card-sub">Last 40 view alerts across all your events</div>
                </div>
            </div>

            <div class="stg-log-bar">
                <span class="stg-log-bar-ttl">
                    <i class="fas fa-list" style="margin-right:5px"></i> Activity Log
                </span>
                <?php if ($unread_count > 0): ?>
                    <button class="stg-mark-btn" id="markReadBtn" onclick="markAllRead()">
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                <?php endif; ?>
            </div>

            <?php if (empty($recent_notifs)): ?>
                <div class="stg-log-empty">
                    <i class="fas fa-bell-slash"></i>
                    <p>No alerts yet. When people view your ticket pages,<br>notifications will appear here.</p>
                </div>
            <?php else: ?>
                <div id="notifList">
                <?php foreach ($recent_notifs as $notif):
                    $ts    = strtotime($notif['created_at']);
                    $diff  = time() - $ts;
                    $lbl   = $diff < 60      ? 'Just now'
                           : ($diff < 3600   ? floor($diff/60).'m ago'
                           : ($diff < 86400  ? floor($diff/3600).'h ago'
                           : ($diff < 604800 ? floor($diff/86400).'d ago'
                           : date('d M Y', $ts))));
                    $unread  = !(bool)$notif['is_read'];
                    $many    = str_contains($notif['message'], ' others');
                    $ico_cls = $many ? 'stg-ico-group' : 'stg-ico-view';
                    $ico_fa  = $many ? 'fa-users'      : 'fa-eye';
                ?>
                <div class="stg-notif-item <?= $unread ? 'unread' : '' ?>">
                    <div class="stg-notif-ico <?= $ico_cls ?>">
                        <i class="fas <?= $ico_fa ?>"></i>
                    </div>
                    <div class="stg-notif-body">
                        <div class="stg-notif-msg"><?= htmlspecialchars($notif['message']) ?></div>
                        <?php if (!empty($notif['event_title'])): ?>
                        <div class="stg-notif-evt">
                            <i class="fas fa-ticket-alt" style="font-size:10px;color:#fec411"></i>
                            <?php if (!empty($notif['ticket_event_id'])): ?>
                                <a href="view_ticket_details.php?event_id=<?= (int)$notif['ticket_event_id'] ?>">
                                    <?= htmlspecialchars($notif['event_title']) ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($notif['event_title']) ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0">
                        <div class="stg-notif-time"><?= $lbl ?></div>
                        <?php if ($unread): ?>
                            <div class="stg-unread-dot"></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /panel-notifications -->

</div><!-- /stg-wrap -->

<script>
// ── AJAX endpoint — passed from PHP to avoid hardcoding ───────────
const STG_AJAX_URL = <?= json_encode($_stg_ajax_url) ?>;

// ── Tab switching ─────────────────────────────────────────────────
document.querySelectorAll('.stg-tab').forEach(tab => {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.stg-tab').forEach(t => {
            t.classList.remove('active');
            t.setAttribute('aria-selected', 'false');
        });
        document.querySelectorAll('.stg-panel').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        this.setAttribute('aria-selected', 'true');
        const panel = document.getElementById('panel-' + this.dataset.tab);
        if (panel) panel.classList.add('active');
        if (this.dataset.tab === 'notifications') markAllRead(true);
        history.replaceState(null, '', '#' + this.dataset.tab);
    });
});

(function () {
    const hash = location.hash.replace('#', '');
    if (hash) {
        const target = document.querySelector('.stg-tab[data-tab="' + hash + '"]');
        if (target) target.click();
    }
})();

// ── Core AJAX helper ──────────────────────────────────────────────
function stgPost(body) {
    return fetch(STG_AJAX_URL, {
        method: 'POST',
        headers: {
            'Content-Type':     'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body + '&_ajax=1',
    })
    .then(r => {
        // If the server returned HTML instead of JSON, surface a clear error
        const ct = r.headers.get('Content-Type') || '';
        if (!ct.includes('application/json')) {
            return r.text().then(txt => {
                console.error('Non-JSON response:', txt.substring(0, 300));
                throw new Error('Server returned HTML instead of JSON. Check PHP error log.');
            });
        }
        return r.json();
    });
}

// ── Toast helper ──────────────────────────────────────────────────
function stgToast(msg, isOk = true) {
    const toast = document.getElementById('stgToast');
    const msgEl = document.getElementById('stgToastMsg');
    const ico   = toast.querySelector('i');
    toast.className = 'stg-toast ' + (isOk ? 'ok' : 'err') + ' show';
    ico.className   = 'fas ' + (isOk ? 'fa-check-circle' : 'fa-exclamation-circle');
    msgEl.textContent = msg;
    clearTimeout(window._stgToastTimer);
    window._stgToastTimer = setTimeout(() => { toast.className = 'stg-toast ok'; }, 2800);
}

// ── Save toggle ───────────────────────────────────────────────────
function stgSave(field, value, statusId, toggle) {
    const statusEl = statusId ? document.getElementById(statusId) : null;

    // Optimistic UI update
    if (statusEl) {
        statusEl.textContent = value ? 'ON' : 'OFF';
        statusEl.className   = 'stg-status ' + (value ? 'on' : 'off');
    }

    stgPost(
        'action=save_notification_setting'
        + '&field=' + encodeURIComponent(field)
        + '&value=' + (value ? 1 : 0)
    )
    .then(d => {
        if (d.success) {
            stgToast(value ? 'Alerts turned ON' : 'Alerts turned OFF', true);
        } else {
            // Revert
            if (toggle)   toggle.checked     = !value;
            if (statusEl) {
                statusEl.textContent = value ? 'OFF' : 'ON';
                statusEl.className   = 'stg-status ' + (value ? 'off' : 'on');
            }
            stgToast(d.message || 'Failed to save — try again', false);
        }
    })
    .catch(err => {
        // Revert
        if (toggle)   toggle.checked     = !value;
        if (statusEl) {
            statusEl.textContent = value ? 'OFF' : 'ON';
            statusEl.className   = 'stg-status ' + (value ? 'off' : 'on');
        }
        stgToast('Save failed — please try again', false);
        console.error('stgSave error:', err);
    });
}

// ── Mark all read ─────────────────────────────────────────────────
function markAllRead(silent = false) {
    const unread = document.querySelectorAll('.stg-notif-item.unread');
    if (!unread.length) return;

    stgPost('action=mark_notifs_read')
    .then(d => {
        if (!d.success) return;
        unread.forEach(item => {
            item.classList.remove('unread');
            const dot = item.querySelector('.stg-unread-dot');
            if (dot) dot.remove();
        });
        ['tabUnreadBadge','unreadBadge'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        const btn = document.getElementById('markReadBtn');
        if (btn) btn.style.display = 'none';
        if (!silent) stgToast('All notifications marked as read', true);
    })
    .catch(() => {});
}
</script>