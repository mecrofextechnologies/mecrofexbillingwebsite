<?php
/*
 ══════════════════════════════════════════════════════════════════
  create_terms_of_service.php  —  Keychele Admin
  Manage Terms of Service & Privacy Policy (versioned, sectioned)
 ══════════════════════════════════════════════════════════════════

 SQL — run once (or use the Install Tables button below):
 ─────────────────────────────────────────────────────────
 CREATE TABLE IF NOT EXISTS legal_documents (
   id             INT AUTO_INCREMENT PRIMARY KEY,
   doc_type       ENUM('terms','privacy') NOT NULL,
   version        VARCHAR(30)  NOT NULL DEFAULT '1.0',
   title          VARCHAR(255) NOT NULL,
   intro          TEXT,
   contact_email  VARCHAR(180),
   effective_date DATE,
   is_active      TINYINT(1)   NOT NULL DEFAULT 0,
   created_by     INT,
   created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
   updated_at     DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
   INDEX idx_type_active (doc_type, is_active)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

 CREATE TABLE IF NOT EXISTS legal_sections (
   id             INT AUTO_INCREMENT PRIMARY KEY,
   document_id    INT          NOT NULL,
   sort_order     SMALLINT     NOT NULL DEFAULT 0,
   section_title  VARCHAR(255) NOT NULL,
   section_body   MEDIUMTEXT   NOT NULL,
   FOREIGN KEY (document_id) REFERENCES legal_documents(id) ON DELETE CASCADE
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 ──────────────────────────────────────────────────────────────────
*/

include '../msanii/includes/connect.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$successMsg = $errorMsg = '';

// ── Auto-install tables if missing ───────────────────────────
function installLegalTables(PDO $pdo): bool {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS legal_documents (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                doc_type       ENUM('terms','privacy') NOT NULL,
                version        VARCHAR(30)  NOT NULL DEFAULT '1.0',
                title          VARCHAR(255) NOT NULL,
                intro          TEXT,
                contact_email  VARCHAR(180),
                effective_date DATE,
                is_active      TINYINT(1)   NOT NULL DEFAULT 0,
                created_by     INT,
                created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_type_active (doc_type, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS legal_sections (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                document_id   INT          NOT NULL,
                sort_order    SMALLINT     NOT NULL DEFAULT 0,
                section_title VARCHAR(255) NOT NULL,
                section_body  MEDIUMTEXT   NOT NULL,
                FOREIGN KEY (document_id) REFERENCES legal_documents(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (PDOException $e) {
        error_log("LegalTables install error: " . $e->getMessage());
        return false;
    }
}
installLegalTables($pdo);

$dash_page = $_GET['page'] ?? null;
$active_tab = in_array($_GET['tab'] ?? '', ['terms','privacy']) ? $_GET['tab'] : 'terms';

function legalUrl(array $p, ?string $dp): string {
    if ($dp !== null) $p['page'] = $dp;
    return '?' . http_build_query($p);
}

// ── POST HANDLERS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $post_tab = $_POST['tab'] ?? 'terms';

    // ── Save / Update document ──────────────────────────────
    if ($action === 'save_document') {
        $doc_type      = in_array($post_tab, ['terms','privacy']) ? $post_tab : 'terms';
        $title         = trim($_POST['title']         ?? '');
        $version       = trim($_POST['version']       ?? '1.0');
        $intro         = trim($_POST['intro']         ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');
        $eff_date      = trim($_POST['effective_date'] ?? '') ?: null;
        $update_id     = (int)($_POST['update_id'] ?? 0);  // 0 = new version

        $sec_titles = $_POST['sec_title'] ?? [];
        $sec_bodies = $_POST['sec_body']  ?? [];
        $sec_orders = $_POST['sec_order'] ?? [];

        if (empty($title))   { $errorMsg = "Document title is required."; }
        elseif (empty($version)) { $errorMsg = "Version is required."; }
        elseif (empty($sec_titles)) { $errorMsg = "Add at least one section."; }
        else {
            try {
                $pdo->beginTransaction();

                if ($update_id > 0) {
                    // Update existing document
                    $pdo->prepare("
                        UPDATE legal_documents
                        SET title=?, version=?, intro=?, contact_email=?, effective_date=?,
                            updated_at=NOW(), created_by=?
                        WHERE id=? AND doc_type=?
                    ")->execute([$title, $version, $intro, $contact_email, $eff_date,
                                  $_SESSION['admin_id'], $update_id, $doc_type]);
                    $doc_id = $update_id;
                    // Delete old sections, re-insert
                    $pdo->prepare("DELETE FROM legal_sections WHERE document_id=?")->execute([$doc_id]);
                } else {
                    // New document (new version)
                    $pdo->prepare("
                        INSERT INTO legal_documents
                            (doc_type, version, title, intro, contact_email, effective_date, is_active, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, 0, ?)
                    ")->execute([$doc_type, $version, $title, $intro, $contact_email, $eff_date,
                                  $_SESSION['admin_id']]);
                    $doc_id = (int)$pdo->lastInsertId();
                }

                // Insert sections
                $ins = $pdo->prepare("
                    INSERT INTO legal_sections (document_id, sort_order, section_title, section_body)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($sec_titles as $idx => $stitle) {
                    $stitle = trim($stitle);
                    $sbody  = trim($sec_bodies[$idx] ?? '');
                    $sord   = (int)($sec_orders[$idx] ?? $idx);
                    if ($stitle === '' && $sbody === '') continue;
                    $ins->execute([$doc_id, $sord, $stitle, $sbody]);
                }

                $pdo->commit();
                $verb = $update_id > 0 ? 'updated' : 'created';
                $successMsg = "Document <strong>{$title}</strong> v{$version} {$verb} successfully."
                            . ($update_id === 0 ? " Don't forget to set it as active when ready." : '');
            } catch (Exception $e) {
                $pdo->rollBack();
                $errorMsg = "Save error: " . htmlspecialchars($e->getMessage());
            }
        }
    }

    // ── Set Active ──────────────────────────────────────────
    if ($action === 'set_active') {
        $doc_id   = (int)($_POST['doc_id']   ?? 0);
        $doc_type = in_array($post_tab, ['terms','privacy']) ? $post_tab : 'terms';
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE legal_documents SET is_active=0 WHERE doc_type=?")->execute([$doc_type]);
            $pdo->prepare("UPDATE legal_documents SET is_active=1 WHERE id=? AND doc_type=?")->execute([$doc_id, $doc_type]);
            $pdo->commit();
            $successMsg = "Document set as the active " . ucfirst($doc_type) . " policy.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errorMsg = "Activation error: " . htmlspecialchars($e->getMessage());
        }
    }

    // ── Delete document (non-active only) ───────────────────
    if ($action === 'delete_document') {
        $doc_id   = (int)($_POST['doc_id'] ?? 0);
        $doc_type = in_array($post_tab, ['terms','privacy']) ? $post_tab : 'terms';
        try {
            $chk = $pdo->prepare("SELECT is_active FROM legal_documents WHERE id=? AND doc_type=?");
            $chk->execute([$doc_id, $doc_type]);
            $row = $chk->fetch();
            if (!$row)          throw new Exception("Document not found.");
            if ($row['is_active']) throw new Exception("Cannot delete the active document. Set another version as active first.");
            $pdo->prepare("DELETE FROM legal_documents WHERE id=?")->execute([$doc_id]);
            $successMsg = "Document version deleted.";
        } catch (Exception $e) {
            $errorMsg = htmlspecialchars($e->getMessage());
        }
    }

    // Redirect to keep tab
    if (!$errorMsg) {
        $rp = ['tab' => $post_tab];
        if ($dash_page) $rp['page'] = $dash_page;
        header("Location: ?" . http_build_query($rp) . ($successMsg ? '&ok=1' : ''));
        exit;
    }
}

if (isset($_GET['ok'])) {
    $successMsg = "Changes saved successfully.";
}

// ── Fetch active document for current tab (for editing) ───────
$active_doc      = null;
$active_sections = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM legal_documents WHERE doc_type=? AND is_active=1 LIMIT 1");
    $stmt->execute([$active_tab]);
    $active_doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($active_doc) {
        $ss = $pdo->prepare("SELECT * FROM legal_sections WHERE document_id=? ORDER BY sort_order ASC, id ASC");
        $ss->execute([$active_doc['id']]);
        $active_sections = $ss->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { /* tables may not exist yet */ }

// ── If editing a specific version (from history) ─────────────
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_doc = null; $edit_sections = [];
if ($edit_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM legal_documents WHERE id=? AND doc_type=?");
        $stmt->execute([$edit_id, $active_tab]);
        $edit_doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($edit_doc) {
            $ss = $pdo->prepare("SELECT * FROM legal_sections WHERE document_id=? ORDER BY sort_order ASC, id ASC");
            $ss->execute([$edit_id]);
            $edit_sections = $ss->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {}
}
$form_doc      = $edit_doc      ?: $active_doc;
$form_sections = $edit_sections ?: $active_sections;

// ── Version history for current tab ───────────────────────────
$history = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.*, (SELECT COUNT(*) FROM legal_sections s WHERE s.document_id=d.id) AS sec_count
        FROM legal_documents d
        WHERE d.doc_type=?
        ORDER BY d.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$active_tab]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

function timeAgoLegal(?string $d): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '—';
    $diff = time() - strtotime($d);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60).'m ago';
    if ($diff < 86400)  return floor($diff/3600).'h ago';
    if ($diff < 604800) return floor($diff/86400).'d ago';
    return date('d M Y', strtotime($d));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Legal Documents — Keychele Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
/* ══════════════════════════════════════════
   DESIGN TOKENS  (shared with admin shell)
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
  --violet:      #7c3aed;
  --violet-l:    #ede9fe;
  --slate:       #64748b;
  --slate-l:     #f1f5f9;
  --teal:        #0d9488;
  --teal-l:      #ccfbf1;
  --indigo:      #4f46e5;
  --indigo-l:    #e0e7ff;

  --text:        #0d1b3e;
  --text-2:      #4b5580;
  --text-3:      #8b95b8;

  --radius:      14px;
  --radius-sm:   9px;
  --shadow-sm:   0 1px 3px rgba(13,27,62,.06), 0 2px 8px rgba(13,27,62,.04);
  --shadow:      0 2px 8px rgba(13,27,62,.08), 0 8px 24px rgba(13,27,62,.06);

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

.shell { padding: 28px; max-width: 1400px; margin: 0 auto; }

/* ── PAGE HEADER ── */
.pg-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:26px; gap:16px; flex-wrap:wrap; }
.pg-header-left h1 {
  font-size:1.55rem; font-weight:800; letter-spacing:-.03em; color:var(--navy);
  display:flex; align-items:center; gap:10px;
}
.pg-header-left h1 .icon-wrap {
  width:38px; height:38px; background:var(--indigo); border-radius:10px;
  display:inline-flex; align-items:center; justify-content:center;
  font-size:.85rem; color:#fff; flex-shrink:0;
}
.pg-header-left p { color:var(--text-2); font-size:.82rem; margin-top:3px; padding-left:48px; }

/* ── TABS ── */
.tab-nav {
  display:flex; gap:4px; margin-bottom:24px;
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); padding:6px; width:fit-content;
  box-shadow:var(--shadow-sm);
}
.tab-btn {
  padding:8px 20px; border-radius:10px; border:none; cursor:pointer;
  font-family:var(--font); font-size:.84rem; font-weight:600;
  color:var(--text-2); background:transparent; transition:all .15s;
  display:flex; align-items:center; gap:7px; text-decoration:none;
}
.tab-btn i { font-size:.75rem; }
.tab-btn:hover { color:var(--text); background:var(--bg); }
.tab-btn.active { background:var(--indigo); color:#fff; box-shadow:0 2px 8px rgba(79,70,229,.35); }

/* ── TWO-COLUMN LAYOUT ── */
.layout { display:grid; grid-template-columns:1fr 420px; gap:20px; align-items:start; }
@media (max-width:1100px) { .layout { grid-template-columns:1fr; } }

/* ── CARD ── */
.card {
  background:var(--surface); border-radius:var(--radius);
  box-shadow:var(--shadow-sm); border:1px solid var(--border); overflow:hidden;
}
.card-head {
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; padding:16px 20px; border-bottom:1px solid var(--border);
  background:var(--surface-2); flex-wrap:wrap;
}
.card-title { font-size:.92rem; font-weight:700; color:var(--navy); display:flex; align-items:center; gap:8px; }
.card-title i { color:var(--indigo); font-size:.8rem; }
.card-body { padding:20px; }

/* ── FORM ELEMENTS ── */
.form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media (max-width:600px) { .form-grid-2 { grid-template-columns:1fr; } }

.fg { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
.fg:last-child { margin-bottom:0; }
label {
  font-size:.72rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.06em; color:var(--text-3);
  display:flex; align-items:center; gap:5px;
}
label .req { color:var(--red); }
.fi, .fta, .fsel {
  border:1.5px solid var(--border-dark); border-radius:var(--radius-sm);
  font-family:var(--font); font-size:.84rem; color:var(--text);
  background:var(--surface); outline:none; width:100%;
  transition:border .16s, box-shadow .16s;
}
.fi   { height:40px; padding:0 12px; }
.fta  { padding:10px 12px; resize:vertical; min-height:90px; line-height:1.6; }
.fsel { height:40px; padding:0 10px; cursor:pointer; }
.fi:focus, .fta:focus, .fsel:focus {
  border-color:var(--indigo); box-shadow:0 0 0 3px rgba(79,70,229,.1);
}
.fi::placeholder, .fta::placeholder { color:var(--text-3); font-size:.82rem; }
.field-hint { font-size:.72rem; color:var(--text-3); line-height:1.4; }

/* ── SECTION CARDS ── */
.sections-list { display:flex; flex-direction:column; gap:12px; }

.sec-card {
  background:var(--surface-2); border:1.5px solid var(--border);
  border-radius:12px; overflow:hidden; transition:border .15s, box-shadow .15s;
}
.sec-card:focus-within { border-color:var(--indigo); box-shadow:0 0 0 3px rgba(79,70,229,.08); }

.sec-card-head {
  display:flex; align-items:center; gap:8px;
  padding:10px 14px; background:var(--bg);
  border-bottom:1px solid var(--border); cursor:grab;
}
.sec-card-head:active { cursor:grabbing; }
.sec-drag-handle { color:var(--text-3); font-size:.7rem; cursor:grab; padding:2px 4px; }
.sec-order-badge {
  min-width:22px; height:22px; border-radius:6px;
  background:var(--indigo-l); color:var(--indigo);
  font-size:.68rem; font-weight:800;
  display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;
}
.sec-title-input {
  flex:1; border:none; background:transparent;
  font-family:var(--font); font-size:.85rem; font-weight:600; color:var(--text);
  outline:none;
}
.sec-title-input::placeholder { color:var(--text-3); font-weight:400; }
.sec-card-body { padding:12px 14px; }
.sec-body-input {
  width:100%; border:none; background:transparent;
  font-family:var(--font); font-size:.83rem; color:var(--text-2);
  line-height:1.7; resize:vertical; min-height:100px; outline:none;
}
.sec-body-input::placeholder { color:var(--text-3); font-size:.8rem; }
.sec-actions { display:flex; gap:6px; }
.sec-btn {
  width:26px; height:26px; border:none; border-radius:6px;
  display:flex; align-items:center; justify-content:center;
  font-size:.65rem; cursor:pointer; transition:all .13s; flex-shrink:0;
}
.sec-btn-up   { background:var(--blue-l);    color:var(--blue);  }
.sec-btn-down { background:var(--slate-l);   color:var(--slate); }
.sec-btn-del  { background:var(--red-l);     color:var(--red);   }
.sec-btn:hover { filter:brightness(.9); }

/* ── ADD SECTION ── */
.btn-add-section {
  width:100%; padding:11px; border:2px dashed var(--border-dark);
  border-radius:12px; background:transparent; cursor:pointer;
  font-family:var(--font); font-size:.82rem; font-weight:600; color:var(--text-3);
  display:flex; align-items:center; justify-content:center; gap:7px;
  transition:all .15s; margin-top:10px;
}
.btn-add-section:hover { border-color:var(--indigo); color:var(--indigo); background:var(--indigo-l); }

/* ── SAVE BUTTON ── */
.btn-save {
  width:100%; height:44px; border:none; border-radius:var(--radius-sm);
  background:var(--indigo); color:#fff;
  font-family:var(--font); font-size:.9rem; font-weight:700;
  cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;
  transition:background .15s, transform .12s;
}
.btn-save:hover { background:#3730a3; transform:translateY(-1px); }
.btn-save:active { transform:translateY(0); }
.btn-save-ghost {
  width:100%; height:40px; border:1.5px solid var(--border-dark); border-radius:var(--radius-sm);
  background:var(--surface); color:var(--text-2);
  font-family:var(--font); font-size:.82rem; font-weight:600;
  cursor:pointer; display:flex; align-items:center; justify-content:center; gap:7px;
  transition:all .15s;
}
.btn-save-ghost:hover { border-color:var(--blue); color:var(--blue); background:var(--blue-ll); }

/* ── MODE TOGGLE ── */
.mode-tabs {
  display:flex; border:1.5px solid var(--border-dark); border-radius:var(--radius-sm);
  overflow:hidden; margin-bottom:16px;
}
.mode-tab {
  flex:1; padding:8px; border:none; background:transparent;
  font-family:var(--font); font-size:.78rem; font-weight:600; color:var(--text-3);
  cursor:pointer; transition:all .14s; display:flex; align-items:center; justify-content:center; gap:5px;
}
.mode-tab.active { background:var(--indigo); color:#fff; }

/* ── STATUS BADGE ── */
.badge {
  display:inline-flex; align-items:center; gap:4px; padding:3px 10px;
  border-radius:100px; font-size:.68rem; font-weight:700;
}
.badge-active   { background:var(--emerald-l); color:#065f46; }
.badge-draft    { background:var(--amber-l);   color:#92400e; }
.badge-inactive { background:var(--slate-l);   color:var(--slate); }

/* ── HISTORY TABLE ── */
.tbl-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; }
thead th {
  padding:9px 14px; background:var(--surface-2); border-bottom:1px solid var(--border);
  font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em;
  color:var(--text-3); white-space:nowrap;
}
tbody tr { border-bottom:1px solid var(--border); transition:background .1s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:var(--blue-ll); }
tbody tr.row-active { background:#f0fdf4; }
tbody tr.row-active:hover { background:#dcfce7; }
td { padding:11px 14px; font-size:.82rem; color:var(--text); vertical-align:middle; }

/* ── ACTION BUTTONS ── */
.btn-tbl {
  height:30px; padding:0 12px; border:none; border-radius:7px;
  font-family:var(--font); font-size:.75rem; font-weight:600;
  cursor:pointer; display:inline-flex; align-items:center; gap:5px; transition:all .13s;
}
.btn-tbl-edit   { background:var(--blue-l);    color:var(--blue);    text-decoration:none; }
.btn-tbl-edit:hover   { background:var(--blue);    color:#fff; }
.btn-tbl-act    { background:var(--emerald-l); color:var(--emerald); }
.btn-tbl-act:hover    { background:var(--emerald); color:#fff; }
.btn-tbl-del    { background:var(--red-l);     color:var(--red);     }
.btn-tbl-del:hover    { background:var(--red);     color:#fff; }

/* ── INFO BOX ── */
.info-box {
  background:var(--indigo-l); border:1px solid #a5b4fc;
  border-radius:var(--radius-sm); padding:12px 14px;
  color:#3730a3; font-size:.8rem; display:flex; gap:9px; margin-bottom:14px;
}
.info-box i { flex-shrink:0; margin-top:2px; }

/* ── ACTIVE INDICATOR ── */
.active-indicator {
  background: linear-gradient(135deg, #f0fdf4, #dcfce7);
  border:1px solid #6ee7b7; border-radius:var(--radius-sm);
  padding:12px 16px; margin-bottom:16px;
  display:flex; align-items:center; gap:10px;
}
.active-indicator .dot {
  width:8px; height:8px; border-radius:50%; background:var(--emerald);
  animation:pulse-dot 2s infinite;
}
@keyframes pulse-dot {
  0%,100% { box-shadow:0 0 0 0 rgba(5,150,105,.4); }
  50%      { box-shadow:0 0 0 6px rgba(5,150,105,0); }
}
.active-indicator .label { font-size:.8rem; font-weight:700; color:#065f46; }
.active-indicator .ver   { font-size:.72rem; color:#059669; font-family:var(--mono); }

/* ── ALERTS ── */
.alert-success {
  background:var(--emerald-l); border:1px solid #6ee7b7;
  border-radius:var(--radius-sm); padding:12px 16px;
  color:#065f46; font-size:.84rem; margin-bottom:16px;
  display:flex; align-items:center; gap:8px;
}
.alert-err {
  background:var(--red-l); border:1px solid #fca5a5;
  border-radius:var(--radius-sm); padding:12px 16px;
  color:#991b1b; font-size:.84rem; margin-bottom:16px;
  display:flex; align-items:center; gap:8px;
}

/* ── EMPTY ── */
.empty { text-align:center; padding:40px 20px; color:var(--text-3); }
.empty i { font-size:1.8rem; opacity:.3; display:block; margin-bottom:10px; }
.empty p { font-size:.82rem; }

/* ── VERSION PILL ── */
.ver-pill {
  display:inline-flex; align-items:center;
  background:var(--indigo-l); color:var(--indigo);
  font-size:.7rem; font-weight:700; font-family:var(--mono);
  padding:2px 9px; border-radius:100px;
}

/* ── SECTION COUNT ── */
.sec-count { font-size:.74rem; color:var(--text-3); }

/* ── DIVIDER ── */
.divider { height:1px; background:var(--border); margin:16px 0; }

/* ── SWEETALERT ── */
.swal2-popup { font-family:var(--font) !important; border-radius:16px !important; }
.swal2-title { font-family:var(--font) !important; font-weight:800 !important; }
.swal2-confirm { background:var(--indigo) !important; border-radius:8px !important; font-family:var(--font) !important; font-weight:600 !important; }
.swal2-cancel  { border-radius:8px !important; font-family:var(--font) !important; font-weight:600 !important; }

@media (max-width:680px) { .shell { padding:14px; } }
</style>
</head>
<body>
<div class="shell">

  <!-- ── PAGE HEADER ── -->
  <div class="pg-header">
    <div class="pg-header-left">
      <h1>
        <span class="icon-wrap"><i class="fas fa-file-contract"></i></span>
        Legal Documents
      </h1>
      <p>Create and manage Terms of Service &amp; Privacy Policy — versioned, sectioned, live-switchable</p>
    </div>
  </div>

  <!-- ── ALERTS ── -->
  <?php if ($successMsg): ?>
    <div class="alert-success"><i class="fas fa-check-circle"></i><span><?= $successMsg ?></span></div>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
    <div class="alert-err"><i class="fas fa-exclamation-circle"></i><?= $errorMsg ?></div>
  <?php endif; ?>

  <!-- ── TABS ── -->
  <div class="tab-nav">
    <a href="<?= legalUrl(['tab'=>'terms'], $dash_page) ?>"
       class="tab-btn <?= $active_tab==='terms' ? 'active' : '' ?>">
      <i class="fas fa-file-contract"></i> Terms of Service
    </a>
    <a href="<?= legalUrl(['tab'=>'privacy'], $dash_page) ?>"
       class="tab-btn <?= $active_tab==='privacy' ? 'active' : '' ?>">
      <i class="fas fa-shield-halved"></i> Privacy Policy
    </a>
  </div>

  <div class="layout">

    <!-- ── LEFT: EDITOR ── -->
    <div>
      <div class="card">
        <div class="card-head">
          <div class="card-title">
            <i class="fas fa-<?= $active_tab === 'terms' ? 'file-contract' : 'shield-halved' ?>"></i>
            <?= $active_tab === 'terms' ? 'Terms of Service Editor' : 'Privacy Policy Editor' ?>
          </div>
          <?php if ($edit_id > 0 && $edit_doc): ?>
            <a href="<?= legalUrl(['tab'=>$active_tab], $dash_page) ?>"
               style="font-size:.75rem;color:var(--text-3);text-decoration:none;display:flex;align-items:center;gap:4px">
              <i class="fas fa-times"></i> Cancel edit
            </a>
          <?php endif; ?>
        </div>
        <div class="card-body">

          <!-- Mode: New or Edit -->
          <?php if ($form_doc && !$edit_id): ?>
            <div class="active-indicator">
              <div class="dot"></div>
              <div>
                <div class="label">Currently Active: <?= htmlspecialchars($form_doc['title']) ?></div>
                <div class="ver">v<?= htmlspecialchars($form_doc['version']) ?> · Effective <?= $form_doc['effective_date'] ? date('d M Y', strtotime($form_doc['effective_date'])) : 'immediately' ?></div>
              </div>
            </div>
          <?php endif; ?>

          <!-- Mode selector -->
          <div class="mode-tabs" id="modeTabs">
            <button type="button" class="mode-tab active" id="btnModeNew" onclick="setMode('new')">
              <i class="fas fa-plus-circle"></i> New Version
            </button>
            <button type="button" class="mode-tab" id="btnModeEdit" onclick="setMode('edit')"
                    <?= !$form_doc ? 'disabled style="opacity:.4;cursor:not-allowed"' : '' ?>>
              <i class="fas fa-pen"></i> Edit Active
            </button>
          </div>

          <form method="POST" id="docForm">
            <?php if ($dash_page): ?>
              <input type="hidden" name="page" value="<?= htmlspecialchars($dash_page) ?>">
            <?php endif; ?>
            <input type="hidden" name="action" value="save_document">
            <input type="hidden" name="tab"    value="<?= $active_tab ?>">
            <input type="hidden" name="update_id" id="updateId" value="<?= $edit_id ?: 0 ?>">

            <!-- Metadata -->
            <div class="form-grid-2">
              <div class="fg">
                <label><i class="fas fa-heading"></i> Document Title <span class="req">*</span></label>
                <input type="text" name="title" class="fi" required
                       placeholder="e.g. Keychele Terms of Service"
                       value="<?= htmlspecialchars($form_doc['title'] ?? ($active_tab === 'terms' ? 'Keychele Terms of Service' : 'Keychele Privacy Policy')) ?>">
              </div>
              <div class="fg">
                <label><i class="fas fa-tag"></i> Version <span class="req">*</span></label>
                <input type="text" name="version" class="fi" required
                       placeholder="e.g. 1.0, 2.1, 2024-Q1"
                       id="versionInput"
                       value="<?= htmlspecialchars($form_doc['version'] ?? '1.0') ?>">
                <span class="field-hint">Increment when making significant changes.</span>
              </div>
            </div>

            <div class="form-grid-2">
              <div class="fg">
                <label><i class="fas fa-calendar"></i> Effective Date</label>
                <input type="date" name="effective_date" class="fi"
                       value="<?= htmlspecialchars($form_doc['effective_date'] ?? date('Y-m-d')) ?>">
                <span class="field-hint">Leave blank to apply immediately.</span>
              </div>
              <div class="fg">
                <label><i class="fas fa-envelope"></i> Contact / Support Email</label>
                <input type="email" name="contact_email" class="fi"
                       placeholder="support@keychele.co.ke"
                       value="<?= htmlspecialchars($form_doc['contact_email'] ?? 'support@keychele.co.ke') ?>">
                <span class="field-hint">Referenced in the document footer section.</span>
              </div>
            </div>

            <div class="fg">
              <label><i class="fas fa-align-left"></i> Introduction / Preamble</label>
              <textarea name="intro" class="fta" rows="3"
                        placeholder="Write a brief introduction paragraph shown at the top of the document before the sections…"><?= htmlspecialchars($form_doc['intro'] ?? '') ?></textarea>
              <span class="field-hint">Optional. Appears before the first numbered section.</span>
            </div>

            <div class="divider"></div>

            <!-- Sections Editor -->
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
              <div style="font-size:.82rem;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:6px">
                <i class="fas fa-list-ul" style="color:var(--indigo);font-size:.75rem"></i>
                Sections
                <span style="font-size:.72rem;font-weight:400;color:var(--text-3)" id="secCountLabel"></span>
              </div>
              <div style="font-size:.74rem;color:var(--text-3);display:flex;align-items:center;gap:4px">
                <i class="fas fa-arrows-up-down" style="font-size:.65rem"></i> Use arrows to reorder
              </div>
            </div>

            <div class="sections-list" id="sectionsList">
              <?php
              $init_sections = !empty($form_sections) ? $form_sections : [
                ['section_title' => 'Acceptance of Terms',      'section_body' => ''],
                ['section_title' => 'Description of Service',   'section_body' => ''],
                ['section_title' => 'User Obligations',         'section_body' => ''],
                ['section_title' => 'Intellectual Property',    'section_body' => ''],
                ['section_title' => 'Limitation of Liability',  'section_body' => ''],
                ['section_title' => 'Changes to These Terms',   'section_body' => ''],
                ['section_title' => 'Contact Us',               'section_body' => ''],
              ];
              if ($active_tab === 'privacy' && empty($form_sections)) {
                $init_sections = [
                  ['section_title' => 'Information We Collect',      'section_body' => ''],
                  ['section_title' => 'How We Use Your Information',  'section_body' => ''],
                  ['section_title' => 'Sharing of Information',      'section_body' => ''],
                  ['section_title' => 'Cookies & Tracking',          'section_body' => ''],
                  ['section_title' => 'Data Retention',              'section_body' => ''],
                  ['section_title' => 'Your Rights',                 'section_body' => ''],
                  ['section_title' => 'Security',                    'section_body' => ''],
                  ['section_title' => 'Changes to This Policy',      'section_body' => ''],
                  ['section_title' => 'Contact Us',                  'section_body' => ''],
                ];
              }
              foreach ($init_sections as $si => $sec): ?>
                <div class="sec-card" data-idx="<?= $si ?>">
                  <div class="sec-card-head">
                    <span class="sec-drag-handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="sec-order-badge sec-order-num"><?= $si + 1 ?></span>
                    <input type="text" name="sec_title[]" class="sec-title-input"
                           placeholder="Section title…"
                           value="<?= htmlspecialchars($sec['section_title']) ?>" required>
                    <input type="hidden" name="sec_order[]" class="sec-order-val" value="<?= $si ?>">
                    <div class="sec-actions">
                      <button type="button" class="sec-btn sec-btn-up"   onclick="moveSection(this,-1)" title="Move up">
                        <i class="fas fa-chevron-up"></i>
                      </button>
                      <button type="button" class="sec-btn sec-btn-down" onclick="moveSection(this,1)"  title="Move down">
                        <i class="fas fa-chevron-down"></i>
                      </button>
                      <button type="button" class="sec-btn sec-btn-del"  onclick="deleteSection(this)"  title="Remove section">
                        <i class="fas fa-trash"></i>
                      </button>
                    </div>
                  </div>
                  <div class="sec-card-body">
                    <textarea name="sec_body[]" class="sec-body-input"
                              placeholder="Write the content for this section. You may use plain text or light markdown-style formatting."
                              oninput="autoResize(this)"><?= htmlspecialchars($sec['section_body']) ?></textarea>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <button type="button" class="btn-add-section" id="btnAddSection" onclick="addSection()">
              <i class="fas fa-plus-circle"></i> Add Section
            </button>

            <div class="divider"></div>

            <!-- Save / Update buttons -->
            <div style="display:flex;flex-direction:column;gap:8px">
              <button type="submit" class="btn-save" id="btnSaveDoc">
                <i class="fas fa-floppy-disk"></i>
                <span id="saveBtnLabel">Save as New Version</span>
              </button>
              <button type="button" class="btn-save-ghost" onclick="resetForm()">
                <i class="fas fa-rotate-left"></i> Reset / Clear Form
              </button>
            </div>
          </form>

        </div>
      </div>
    </div><!-- /left col -->

    <!-- ── RIGHT: SIDEBAR PANELS ── -->
    <div style="display:flex;flex-direction:column;gap:16px">

      <!-- Quick Tips -->
      <div class="card">
        <div class="card-head">
          <div class="card-title"><i class="fas fa-lightbulb"></i> Tips</div>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
          <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <div>New versions are saved as <strong>drafts</strong>. Go to the history below and click <strong>Set Active</strong> to publish.</div>
          </div>
          <div style="font-size:.8rem;color:var(--text-2);display:flex;flex-direction:column;gap:8px">
            <div style="display:flex;gap:7px">
              <i class="fas fa-tag" style="color:var(--indigo);font-size:.7rem;margin-top:3px;flex-shrink:0"></i>
              <span>Use version labels like <code style="background:var(--bg);padding:1px 5px;border-radius:4px;font-size:.76rem">1.0</code>, <code style="background:var(--bg);padding:1px 5px;border-radius:4px;font-size:.76rem">2.1</code>, or <code style="background:var(--bg);padding:1px 5px;border-radius:4px;font-size:.76rem">2025-Q1</code></span>
            </div>
            <div style="display:flex;gap:7px">
              <i class="fas fa-list-ul" style="color:var(--indigo);font-size:.7rem;margin-top:3px;flex-shrink:0"></i>
              <span>Each section becomes a numbered clause in the public document.</span>
            </div>
            <div style="display:flex;gap:7px">
              <i class="fas fa-history" style="color:var(--indigo);font-size:.7rem;margin-top:3px;flex-shrink:0"></i>
              <span>Previous versions are kept for audit purposes. Only one version is live at a time.</span>
            </div>
            <div style="display:flex;gap:7px">
              <i class="fas fa-shield-halved" style="color:var(--indigo);font-size:.7rem;margin-top:3px;flex-shrink:0"></i>
              <span>Terms and Privacy are managed separately — switch tabs above.</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Active Document Stats -->
      <?php if ($active_doc): ?>
      <div class="card">
        <div class="card-head">
          <div class="card-title">
            <i class="fas fa-circle-check"></i>
            Active <?= ucfirst($active_tab) ?>
          </div>
          <span class="badge badge-active"><i class="fas fa-check" style="font-size:.55rem"></i> Live</span>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
          <div>
            <div style="font-size:.88rem;font-weight:700;color:var(--navy)"><?= htmlspecialchars($active_doc['title']) ?></div>
            <div style="font-size:.75rem;color:var(--text-3);margin-top:2px">
              v<?= htmlspecialchars($active_doc['version']) ?>
              <?php if ($active_doc['effective_date']): ?>
                · Effective <?= date('d M Y', strtotime($active_doc['effective_date'])) ?>
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:8px;font-size:.78rem;flex-wrap:wrap">
            <div style="background:var(--bg);border-radius:8px;padding:8px 12px;flex:1;text-align:center">
              <div style="font-size:1.3rem;font-weight:800;color:var(--indigo)"><?= count($active_sections) ?></div>
              <div style="color:var(--text-3)">Sections</div>
            </div>
            <div style="background:var(--bg);border-radius:8px;padding:8px 12px;flex:1;text-align:center">
              <div style="font-size:1.3rem;font-weight:800;color:var(--indigo)"><?= count($history) ?></div>
              <div style="color:var(--text-3)">Versions</div>
            </div>
          </div>
          <?php if ($active_doc['contact_email']): ?>
          <div style="font-size:.76rem;color:var(--text-3);display:flex;align-items:center;gap:5px">
            <i class="fas fa-envelope" style="color:var(--indigo)"></i>
            <?= htmlspecialchars($active_doc['contact_email']) ?>
          </div>
          <?php endif; ?>
          <div style="font-size:.72rem;color:var(--text-3)">
            Last updated <?= timeAgoLegal($active_doc['updated_at']) ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /right col -->

  </div><!-- /layout -->


  <!-- ── VERSION HISTORY ── -->
  <div class="card" style="margin-top:20px">
    <div class="card-head">
      <div class="card-title">
        <i class="fas fa-clock-rotate-left"></i>
        Version History —
        <span style="font-weight:400;color:var(--text-3);font-size:.78rem">
          <?= ucfirst($active_tab) ?>
          (<?= count($history) ?> version<?= count($history) !== 1 ? 's' : '' ?>)
        </span>
      </div>
    </div>

    <?php if (!empty($history)): ?>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Version</th>
            <th>Title</th>
            <th>Sections</th>
            <th>Effective</th>
            <th>Status</th>
            <th>Created</th>
            <th>Last Updated</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
          <tr class="<?= $h['is_active'] ? 'row-active' : '' ?>">
            <td>
              <span class="ver-pill"><?= htmlspecialchars($h['version']) ?></span>
            </td>
            <td style="font-weight:<?= $h['is_active'] ? '700' : '400' ?>;color:<?= $h['is_active'] ? 'var(--navy)' : 'var(--text)' ?>">
              <?= htmlspecialchars($h['title']) ?>
              <?php if ($h['is_active']): ?>
                <i class="fas fa-star" style="color:var(--amber);font-size:.65rem;margin-left:4px" title="Active"></i>
              <?php endif; ?>
            </td>
            <td><span class="sec-count"><?= (int)$h['sec_count'] ?> section<?= $h['sec_count'] != 1 ? 's' : '' ?></span></td>
            <td style="font-size:.79rem;color:var(--text-3)">
              <?= $h['effective_date'] ? date('d M Y', strtotime($h['effective_date'])) : '<span style="font-style:italic">—</span>' ?>
            </td>
            <td>
              <?php if ($h['is_active']): ?>
                <span class="badge badge-active"><i class="fas fa-circle" style="font-size:.5rem"></i> Active</span>
              <?php else: ?>
                <span class="badge badge-inactive"><i class="fas fa-circle" style="font-size:.5rem"></i> Draft</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.78rem;color:var(--text-3);white-space:nowrap"><?= timeAgoLegal($h['created_at']) ?></td>
            <td style="font-size:.78rem;color:var(--text-3);white-space:nowrap"><?= timeAgoLegal($h['updated_at']) ?></td>
            <td style="text-align:right">
              <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">
                <!-- Edit -->
                <a href="<?= legalUrl(['tab'=>$active_tab,'edit'=>$h['id']], $dash_page) ?>"
                   class="btn-tbl btn-tbl-edit">
                  <i class="fas fa-pen"></i> Edit
                </a>
                <!-- Set Active (if not already) -->
                <?php if (!$h['is_active']): ?>
                  <form method="POST" class="set-active-form" style="display:inline"
                        data-title="<?= htmlspecialchars($h['title']) ?>"
                        data-ver="<?= htmlspecialchars($h['version']) ?>">
                    <?php if ($dash_page): ?>
                      <input type="hidden" name="page" value="<?= htmlspecialchars($dash_page) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action"  value="set_active">
                    <input type="hidden" name="tab"     value="<?= $active_tab ?>">
                    <input type="hidden" name="doc_id"  value="<?= $h['id'] ?>">
                    <button type="button" class="btn-tbl btn-tbl-act set-active-btn">
                      <i class="fas fa-check-circle"></i> Set Active
                    </button>
                  </form>
                  <!-- Delete -->
                  <form method="POST" class="del-doc-form" style="display:inline"
                        data-title="<?= htmlspecialchars($h['title']) ?>"
                        data-ver="<?= htmlspecialchars($h['version']) ?>">
                    <?php if ($dash_page): ?>
                      <input type="hidden" name="page" value="<?= htmlspecialchars($dash_page) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action" value="delete_document">
                    <input type="hidden" name="tab"    value="<?= $active_tab ?>">
                    <input type="hidden" name="doc_id" value="<?= $h['id'] ?>">
                    <button type="button" class="btn-tbl btn-tbl-del del-doc-btn">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <span style="font-size:.72rem;color:var(--emerald);display:flex;align-items:center;gap:4px;padding:0 8px">
                    <i class="fas fa-lock"></i> Published
                  </span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="empty">
        <i class="fas fa-file-circle-plus"></i>
        <p>No <?= $active_tab === 'terms' ? 'Terms of Service' : 'Privacy Policy' ?> documents yet. Create the first version above.</p>
      </div>
    <?php endif; ?>
  </div>

</div><!-- /shell -->

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
// ── STATE ────────────────────────────────────────────────────
let currentMode  = 'new';
const activeHasDoc = <?= $form_doc ? 'true' : 'false' ?>;
const activeDocId  = <?= $form_doc ? (int)$form_doc['id'] : 0 ?>;
const editId       = <?= $edit_id ?: 0 ?>;

// ── MODE SWITCHER ────────────────────────────────────────────
function setMode(mode) {
    currentMode = mode;
    document.getElementById('btnModeNew').classList.toggle('active', mode === 'new');
    document.getElementById('btnModeEdit').classList.toggle('active', mode === 'edit');

    const updateId  = document.getElementById('updateId');
    const saveLabel = document.getElementById('saveBtnLabel');
    const verInput  = document.getElementById('versionInput');

    if (mode === 'edit' && activeHasDoc) {
        updateId.value  = activeDocId;
        saveLabel.textContent = 'Save Changes to This Version';
    } else {
        updateId.value  = editId || 0;
        saveLabel.textContent = editId ? 'Save Changes' : 'Save as New Version';
    }
}

// If arriving via edit link, start in edit mode
if (editId > 0) {
    setMode('edit');
    document.getElementById('updateId').value = editId;
    document.getElementById('saveBtnLabel').textContent = 'Save Changes';
}

// ── SECTION COUNTER ─────────────────────────────────────────
function updateSecCount() {
    const cards = document.querySelectorAll('.sec-card');
    document.getElementById('secCountLabel').textContent = `(${cards.length})`;
    // Re-number order badges and hidden inputs
    cards.forEach((card, i) => {
        const badge = card.querySelector('.sec-order-num');
        const inp   = card.querySelector('.sec-order-val');
        if (badge) badge.textContent = i + 1;
        if (inp)   inp.value = i;
    });
}
updateSecCount();

// ── ADD SECTION ─────────────────────────────────────────────
function addSection() {
    const list  = document.getElementById('sectionsList');
    const cards = list.querySelectorAll('.sec-card');
    const idx   = cards.length;

    const card = document.createElement('div');
    card.className = 'sec-card';
    card.dataset.idx = idx;
    card.innerHTML = `
      <div class="sec-card-head">
        <span class="sec-drag-handle"><i class="fas fa-grip-vertical"></i></span>
        <span class="sec-order-badge sec-order-num">${idx + 1}</span>
        <input type="text" name="sec_title[]" class="sec-title-input"
               placeholder="Section title…" required>
        <input type="hidden" name="sec_order[]" class="sec-order-val" value="${idx}">
        <div class="sec-actions">
          <button type="button" class="sec-btn sec-btn-up"   onclick="moveSection(this,-1)"><i class="fas fa-chevron-up"></i></button>
          <button type="button" class="sec-btn sec-btn-down" onclick="moveSection(this,1)"><i class="fas fa-chevron-down"></i></button>
          <button type="button" class="sec-btn sec-btn-del"  onclick="deleteSection(this)"><i class="fas fa-trash"></i></button>
        </div>
      </div>
      <div class="sec-card-body">
        <textarea name="sec_body[]" class="sec-body-input"
                  placeholder="Write the content for this section…"
                  oninput="autoResize(this)"></textarea>
      </div>`;
    list.appendChild(card);
    updateSecCount();
    card.querySelector('.sec-title-input').focus();

    // Animate in
    card.style.opacity = '0'; card.style.transform = 'translateY(8px)';
    requestAnimationFrame(() => {
        card.style.transition = 'opacity .2s, transform .2s';
        card.style.opacity = '1'; card.style.transform = 'translateY(0)';
    });
}

// ── MOVE SECTION ─────────────────────────────────────────────
function moveSection(btn, dir) {
    const card  = btn.closest('.sec-card');
    const list  = document.getElementById('sectionsList');
    const cards = Array.from(list.querySelectorAll('.sec-card'));
    const idx   = cards.indexOf(card);
    const target = idx + dir;
    if (target < 0 || target >= cards.length) return;
    if (dir === -1) list.insertBefore(card, cards[target]);
    else            list.insertBefore(cards[target], card);
    updateSecCount();
}

// ── DELETE SECTION ───────────────────────────────────────────
async function deleteSection(btn) {
    const card  = btn.closest('.sec-card');
    const title = card.querySelector('.sec-title-input').value.trim() || 'this section';
    const list  = document.getElementById('sectionsList');
    if (list.querySelectorAll('.sec-card').length <= 1) {
        await Swal.fire({ icon:'warning', title:'Minimum 1 Section', text:'A document must have at least one section.', confirmButtonColor:'#4f46e5' });
        return;
    }
    const res = await Swal.fire({
        icon:'question', title:'Remove Section?',
        html:`Remove <strong>${title}</strong>?`,
        showCancelButton:true,
        confirmButtonText:'Yes, Remove',
        confirmButtonColor:'#dc2626',
        cancelButtonText:'Cancel',
    });
    if (res.isConfirmed) {
        card.style.transition = 'opacity .15s, transform .15s';
        card.style.opacity = '0'; card.style.transform = 'translateX(-10px)';
        setTimeout(() => { card.remove(); updateSecCount(); }, 150);
    }
}

// ── AUTO-RESIZE TEXTAREA ─────────────────────────────────────
function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}
document.querySelectorAll('.sec-body-input').forEach(autoResize);

// ── RESET FORM ───────────────────────────────────────────────
async function resetForm() {
    const res = await Swal.fire({
        icon:'warning', title:'Reset Form?',
        text:'This will clear all unsaved changes.',
        showCancelButton:true,
        confirmButtonText:'Yes, Reset',
        confirmButtonColor:'#dc2626',
    });
    if (res.isConfirmed) {
        document.getElementById('docForm').reset();
        document.querySelectorAll('.sec-body-input').forEach(el => {
            el.style.height = 'auto';
        });
    }
}

// ── SET ACTIVE CONFIRM ──────────────────────────────────────
document.querySelectorAll('.set-active-btn').forEach(btn => {
    btn.addEventListener('click', async function () {
        const form  = this.closest('.set-active-form');
        const title = form.dataset.title;
        const ver   = form.dataset.ver;
        const res   = await Swal.fire({
            icon: 'question', title: 'Set as Active?',
            html: `Publish <strong>${title}</strong> (v${ver}) as the live document?<br><span style="font-size:.8rem;color:#4b5580">The currently active version will be deactivated.</span>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check-circle" style="margin-right:5px"></i> Yes, Publish',
            cancelButtonText:  'Cancel',
            confirmButtonColor: '#059669',
        });
        if (res.isConfirmed) form.submit();
    });
});

// ── DELETE CONFIRM ───────────────────────────────────────────
document.querySelectorAll('.del-doc-btn').forEach(btn => {
    btn.addEventListener('click', async function () {
        const form  = this.closest('.del-doc-form');
        const title = form.dataset.title;
        const ver   = form.dataset.ver;
        const res   = await Swal.fire({
            icon: 'warning', title: 'Delete Version?',
            html: `Permanently delete <strong>${title}</strong> v${ver}?<br><span style="font-size:.8rem;color:#991b1b">This cannot be undone.</span>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-trash" style="margin-right:5px"></i> Delete',
            cancelButtonText:  'Cancel',
            confirmButtonColor: '#dc2626',
        });
        if (res.isConfirmed) form.submit();
    });
});

// ── SAVE FORM CONFIRM ────────────────────────────────────────
document.getElementById('docForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const title   = this.querySelector('[name=title]').value.trim();
    const version = this.querySelector('[name=version]').value.trim();
    const secCount = this.querySelectorAll('.sec-card').length;
    const updateId = parseInt(document.getElementById('updateId').value) || 0;
    const label    = updateId > 0 ? 'update' : 'create new version of';

    const res = await Swal.fire({
        icon: 'question', title: 'Save Document?',
        html: `${updateId > 0 ? 'Update' : 'Save new version of'} <strong>${title}</strong> v<strong>${version}</strong> with <strong>${secCount}</strong> section${secCount !== 1 ? 's' : ''}?`,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-floppy-disk" style="margin-right:5px"></i> Save',
        cancelButtonText:  'Review first',
        confirmButtonColor: '#4f46e5',
    });
    if (res.isConfirmed) this.submit();
});
</script>
</body>
</html>
