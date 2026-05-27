<?php
/* ════════════════════════════════════════════════════════════
   terms-of-service.php — Keychele Public Legal Documents
════════════════════════════════════════════════════════════ */
include 'msanii/includes/connect.php';
date_default_timezone_set('Africa/Nairobi');

// ── Fetch active Terms of Service ────────────────────────────
$terms = null; $termsSections = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM legal_documents WHERE doc_type='terms' AND is_active=1 LIMIT 1");
    $stmt->execute();
    $terms = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($terms) {
        $ss = $pdo->prepare("SELECT * FROM legal_sections WHERE document_id=? ORDER BY sort_order ASC, id ASC");
        $ss->execute([$terms['id']]);
        $termsSections = $ss->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// ── Fetch active Privacy Policy ───────────────────────────────
$privacy = null; $privacySections = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM legal_documents WHERE doc_type='privacy' AND is_active=1 LIMIT 1");
    $stmt->execute();
    $privacy = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($privacy) {
        $ss = $pdo->prepare("SELECT * FROM legal_sections WHERE document_id=? ORDER BY sort_order ASC, id ASC");
        $ss->execute([$privacy['id']]);
        $privacySections = $ss->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// ── Active tab from URL ───────────────────────────────────────
$active_tab = in_array($_GET['tab'] ?? '', ['terms','privacy']) ? $_GET['tab'] : 'terms';

function fmtEffDate(?string $d): string {
    if (!$d || $d === '0000-00-00') return 'Effective immediately';
    return 'Effective ' . date('F j, Y', strtotime($d));
}
function slugify(string $s): string {
    return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $s));
}
function nl2p(string $text): string {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $paras = array_filter(array_map('trim', explode("\n\n", $text)));
    if (empty($paras)) return '<p>' . nl2br($text) . '</p>';
    return implode('', array_map(fn($p) => '<p>' . nl2br($p) . '</p>', $paras));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terms &amp; Privacy — Keychele</title>
    <meta name="description" content="Read Keychele's Terms of Service and Privacy Policy. Understand how we protect your data and govern use of the platform.">
    <meta name="robots" content="index, follow">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    <!-- Bootstrap -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/tooplate-waso-strategy.css" rel="stylesheet">

    <link rel="icon" href="/favicon.ico" type="image/x-icon">

<style>
/* ══════════════════════════════════════════════════════════
   BRAND TOKENS — identical to index.php
══════════════════════════════════════════════════════════ */
:root {
    --navy:    #052e64;
    --navy-d:  #031e44;
    --navy-m:  #0a4a99;
    --navy-l:  #e8eef8;
    --navy-xl: #f2f5fc;
    --gold:    #fec411;
    --gold-d:  #d9a70a;
    --gold-l:  #fffbea;
    --cr:      #bf1339;
    --cr-d:    #96102d;
    --cr-l:    #fce8ec;
    --white:   #ffffff;
    --g50:     #f8f9fd;
    --g100:    #eef1f9;
    --g200:    #dce2f0;
    --g400:    #8a97b8;
    --g600:    #4a5578;
    --green:   #16a34a;
    --fh:      'Montserrat', system-ui, sans-serif;
    --radius:  14px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: var(--g50);
    font-family: var(--fh);
    color: var(--navy);
    -webkit-font-smoothing: antialiased;
}

/* ══════════════════════════════════════════════════════════
   HERO BANNER
══════════════════════════════════════════════════════════ */
.tos-hero {
    background: linear-gradient(135deg, var(--navy-d) 0%, var(--navy) 55%, #1a3a6e 100%);
    padding: 70px 0 60px;
    position: relative;
    overflow: hidden;
}
.tos-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 60% 80% at 80% 50%, rgba(254,196,17,.07) 0%, transparent 70%),
        radial-gradient(ellipse 40% 60% at 10% 80%, rgba(191,19,57,.08) 0%, transparent 60%);
    pointer-events: none;
}
/* grid texture */
.tos-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size: 40px 40px;
    pointer-events: none;
}
.tos-hero-inner {
    position: relative;
    z-index: 2;
    text-align: center;
}
.tos-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: rgba(254,196,17,.14);
    border: 1px solid rgba(254,196,17,.35);
    color: var(--gold);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 3px;
    text-transform: uppercase;
    padding: 6px 18px;
    border-radius: 30px;
    margin-bottom: 18px;
}
.tos-hero-title {
    font-size: clamp(1.9rem, 4.5vw, 3rem);
    font-weight: 900;
    color: #fff;
    line-height: 1.15;
    margin-bottom: 14px;
    letter-spacing: -.02em;
}
.tos-hero-title span { color: var(--gold); }
.tos-hero-sub {
    font-size: clamp(13px, 1.5vw, 15px);
    color: rgba(255,255,255,.72);
    max-width: 520px;
    margin: 0 auto 28px;
    line-height: 1.7;
}
/* Doc meta pills */
.tos-meta-strip {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 10px;
}
.tos-meta-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.15);
    color: rgba(255,255,255,.8);
    font-size: 11px;
    font-weight: 600;
    padding: 5px 14px;
    border-radius: 30px;
    backdrop-filter: blur(6px);
}
.tos-meta-pill i { color: var(--gold); font-size: 10px; }

/* ══════════════════════════════════════════════════════════
   TAB SWITCHER — same pill nav as index hub
══════════════════════════════════════════════════════════ */
.tos-tabs-outer {
    background: var(--white);
    border-bottom: 1px solid var(--g100);
    position: sticky;
    top: 0;
    z-index: 200;
    box-shadow: 0 2px 18px rgba(5,46,100,.07);
}
.tos-tabs-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 0 20px;
    height: 58px;
    max-width: 1100px;
    margin: 0 auto;
}
.tos-tab-nav {
    display: flex;
    align-items: center;
    gap: 4px;
    background: var(--g50);
    border: 1.5px solid var(--g100);
    border-radius: 50px;
    padding: 4px;
}
.tos-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 18px;
    border: none;
    border-radius: 50px;
    background: transparent;
    font-family: var(--fh);
    font-size: 12px;
    font-weight: 700;
    color: var(--g600);
    cursor: pointer;
    transition: all .2s;
    text-decoration: none;
    white-space: nowrap;
}
.tos-tab-btn i { font-size: 11px; }
.tos-tab-btn.active {
    background: var(--navy);
    color: #fff;
    box-shadow: 0 4px 14px rgba(5,46,100,.22);
}
.tos-tab-btn.active i { color: var(--gold); }
.tos-updated-label {
    font-size: 11px;
    color: var(--g400);
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.tos-updated-label i { color: var(--navy-m); font-size: 10px; }
@media (max-width: 520px) {
    .tos-updated-label { display: none; }
    .tos-tabs-inner { justify-content: center; }
    .tos-tab-btn { padding: 7px 14px; font-size: 11px; }
}

/* ══════════════════════════════════════════════════════════
   MAIN LAYOUT — sidebar TOC + content
══════════════════════════════════════════════════════════ */
.tos-layout {
    max-width: 1100px;
    margin: 0 auto;
    padding: 40px 20px 80px;
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 32px;
    align-items: start;
}
@media (max-width: 860px) {
    .tos-layout { grid-template-columns: 1fr; }
    .tos-toc-wrap { display: none; }
}

/* ══════════════════════════════════════════════════════════
   SIDEBAR — Table of Contents
══════════════════════════════════════════════════════════ */
.tos-toc-wrap {
    position: sticky;
    top: 74px;
}
.tos-toc {
    background: var(--white);
    border: 1.5px solid var(--g100);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: 0 4px 18px rgba(5,46,100,.06);
}
.tos-toc-head {
    background: linear-gradient(135deg, var(--navy), var(--navy-m));
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.tos-toc-head span {
    font-size: 11px;
    font-weight: 800;
    color: #fff;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.tos-toc-head i { color: var(--gold); font-size: 11px; }
.tos-toc-body { padding: 10px 0; }
.tos-toc-item {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 18px;
    font-size: 12px;
    font-weight: 600;
    color: var(--g600);
    text-decoration: none;
    transition: all .15s;
    cursor: pointer;
    border-left: 3px solid transparent;
    line-height: 1.35;
}
.tos-toc-item:hover {
    color: var(--navy);
    background: var(--navy-xl);
    border-left-color: var(--gold);
}
.tos-toc-item.active {
    color: var(--navy);
    background: var(--navy-l);
    border-left-color: var(--cr);
    font-weight: 700;
}
.tos-toc-num {
    flex-shrink: 0;
    width: 22px;
    height: 22px;
    border-radius: 6px;
    background: var(--g100);
    color: var(--navy);
    font-size: 9px;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background .15s, color .15s;
}
.tos-toc-item.active .tos-toc-num { background: var(--cr); color: #fff; }
.tos-toc-item:hover .tos-toc-num  { background: var(--navy-l); }

/* Contact box inside TOC */
.tos-toc-contact {
    margin: 10px 12px 12px;
    background: var(--gold-l);
    border: 1px solid rgba(254,196,17,.4);
    border-radius: 10px;
    padding: 12px 14px;
}
.tos-toc-contact p {
    font-size: 11px;
    color: var(--g600);
    line-height: 1.55;
    margin: 0;
}
.tos-toc-contact a {
    color: var(--cr);
    font-weight: 700;
    word-break: break-all;
}
.tos-toc-divider { height: 1px; background: var(--g100); margin: 6px 18px; }

/* ══════════════════════════════════════════════════════════
   CONTENT PANELS
══════════════════════════════════════════════════════════ */
.tos-panel { display: none; }
.tos-panel.active { display: block; }

/* ── Doc header card ── */
.tos-doc-header {
    background: var(--white);
    border: 1.5px solid var(--g100);
    border-radius: var(--radius);
    padding: 28px 32px;
    margin-bottom: 24px;
    box-shadow: 0 4px 18px rgba(5,46,100,.05);
    position: relative;
    overflow: hidden;
}
.tos-doc-header::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 5px;
    background: linear-gradient(to bottom, var(--navy), var(--cr));
    border-radius: 4px 0 0 4px;
}
.tos-doc-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--navy), var(--navy-m));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: var(--gold);
    margin-bottom: 14px;
    box-shadow: 0 4px 16px rgba(5,46,100,.2);
}
.tos-doc-title {
    font-size: clamp(1.2rem, 3vw, 1.6rem);
    font-weight: 900;
    color: var(--navy);
    line-height: 1.2;
    margin-bottom: 8px;
    letter-spacing: -.02em;
}
.tos-doc-intro {
    font-size: 14px;
    color: var(--g600);
    line-height: 1.8;
    margin-bottom: 18px;
    max-width: 680px;
}
.tos-doc-meta-row {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.tos-doc-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}
.tos-badge-ver  { background: var(--navy-l);   color: var(--navy);  }
.tos-badge-date { background: var(--gold-l);   color: var(--gold-d); border: 1px solid rgba(254,196,17,.3); }
.tos-badge-live { background: #dcfce7; color: #166534; }

/* ── Section card ── */
.tos-section {
    background: var(--white);
    border: 1.5px solid var(--g100);
    border-radius: var(--radius);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(5,46,100,.04);
    transition: border-color .2s, box-shadow .2s;
    scroll-margin-top: 80px;
}
.tos-section:target,
.tos-section.highlighted {
    border-color: rgba(254,196,17,.5);
    box-shadow: 0 0 0 3px rgba(254,196,17,.12), 0 4px 18px rgba(5,46,100,.08);
}
.tos-section-head {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 24px;
    cursor: pointer;
    user-select: none;
    transition: background .15s;
    border-bottom: 1px solid transparent;
}
.tos-section-head:hover { background: var(--g50); }
.tos-section.open .tos-section-head {
    background: var(--navy-xl);
    border-bottom-color: var(--g100);
}
.tos-sec-num {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: var(--navy);
    color: var(--gold);
    font-size: 14px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 3px 10px rgba(5,46,100,.2);
    transition: background .15s;
}
.tos-section.open .tos-sec-num { background: var(--cr); }
.tos-sec-title-wrap { flex: 1; min-width: 0; }
.tos-sec-title {
    font-size: 15px;
    font-weight: 800;
    color: var(--navy);
    line-height: 1.25;
}
.tos-sec-tag {
    display: inline-block;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--g400);
    margin-top: 2px;
}
.tos-sec-toggle {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    border: 1.5px solid var(--g200);
    background: var(--white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    color: var(--g400);
    transition: all .2s;
}
.tos-section.open .tos-sec-toggle {
    background: var(--cr);
    border-color: var(--cr);
    color: #fff;
    transform: rotate(180deg);
}
.tos-section-body {
    padding: 0 24px;
    max-height: 0;
    overflow: hidden;
    transition: max-height .35s cubic-bezier(.4,0,.2,1), padding .25s;
}
.tos-section.open .tos-section-body {
    max-height: 2000px;
    padding: 20px 24px 24px;
}
.tos-section-body p {
    font-size: 14px;
    color: var(--g600);
    line-height: 1.85;
    margin-bottom: 12px;
}
.tos-section-body p:last-child { margin-bottom: 0; }

/* ── Expand / Collapse all ── */
.tos-expand-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 8px;
}
.tos-expand-info {
    font-size: 12px;
    color: var(--g400);
    display: flex;
    align-items: center;
    gap: 5px;
}
.tos-expand-info i { font-size: 11px; }
.tos-expand-btns { display: flex; gap: 6px; }
.tos-expand-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border: 1.5px solid var(--g200);
    border-radius: 20px;
    background: var(--white);
    font-family: var(--fh);
    font-size: 11px;
    font-weight: 700;
    color: var(--g600);
    cursor: pointer;
    transition: all .15s;
}
.tos-expand-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--navy-l); }
.tos-expand-btn.primary { background: var(--navy); color: #fff; border-color: var(--navy); }
.tos-expand-btn.primary:hover { background: var(--cr); border-color: var(--cr); }

/* ── Empty state ── */
.tos-empty {
    text-align: center;
    padding: 70px 20px;
    background: var(--white);
    border: 1.5px dashed var(--g200);
    border-radius: var(--radius);
}
.tos-empty-icon {
    width: 70px;
    height: 70px;
    border-radius: 20px;
    background: var(--g100);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: var(--g400);
    margin: 0 auto 18px;
}
.tos-empty h3 { font-size: 18px; font-weight: 800; color: var(--navy); margin-bottom: 8px; }
.tos-empty p  { font-size: 14px; color: var(--g400); max-width: 340px; margin: 0 auto; line-height: 1.6; }

/* ══════════════════════════════════════════════════════════
   BOTTOM CTA / CONTACT CARD
══════════════════════════════════════════════════════════ */
.tos-contact-card {
    background: linear-gradient(135deg, var(--navy-d), var(--navy));
    border-radius: var(--radius);
    padding: 36px 32px;
    text-align: center;
    margin-top: 30px;
    position: relative;
    overflow: hidden;
}
.tos-contact-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse 70% 60% at 80% 30%, rgba(254,196,17,.08) 0%, transparent 70%);
    pointer-events: none;
}
.tos-contact-card-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: rgba(254,196,17,.15);
    border: 1px solid rgba(254,196,17,.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: var(--gold);
    margin: 0 auto 16px;
}
.tos-contact-card h3 {
    font-size: 1.2rem;
    font-weight: 900;
    color: #fff;
    margin-bottom: 8px;
}
.tos-contact-card p {
    font-size: 13px;
    color: rgba(255,255,255,.65);
    line-height: 1.7;
    margin-bottom: 20px;
    max-width: 420px;
    margin-left: auto;
    margin-right: auto;
}
.tos-contact-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 12px 28px;
    background: var(--cr);
    color: #fff;
    border-radius: 50px;
    font-family: var(--fh);
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    transition: all .2s;
    box-shadow: 0 6px 20px rgba(191,19,57,.35);
}
.tos-contact-btn:hover { background: var(--cr-d); color: #fff; transform: translateY(-2px); }

/* ══════════════════════════════════════════════════════════
   PRINT BUTTON
══════════════════════════════════════════════════════════ */
.tos-print-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    background: transparent;
    border: 1.5px solid rgba(255,255,255,.3);
    border-radius: 20px;
    font-family: var(--fh);
    font-size: 11px;
    font-weight: 700;
    color: rgba(255,255,255,.7);
    cursor: pointer;
    transition: all .2s;
}
.tos-print-btn:hover { background: rgba(255,255,255,.12); color: #fff; border-color: rgba(255,255,255,.5); }

/* ══════════════════════════════════════════════════════════
   PROGRESS BAR
══════════════════════════════════════════════════════════ */
.tos-progress-bar {
    position: fixed;
    top: 0;
    left: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--cr), var(--gold));
    z-index: 9999;
    width: 0%;
    transition: width .1s linear;
    border-radius: 0 2px 2px 0;
    pointer-events: none;
}

/* ══════════════════════════════════════════════════════════
   MOBILE FLOATING TOC TOGGLE
══════════════════════════════════════════════════════════ */
.tos-toc-fab {
    display: none;
    position: fixed;
    bottom: 24px;
    right: 20px;
    z-index: 1000;
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: var(--navy);
    color: var(--gold);
    border: none;
    cursor: pointer;
    font-size: 18px;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 24px rgba(5,46,100,.35);
    transition: all .2s;
}
.tos-toc-fab:hover { background: var(--cr); color: #fff; }
@media (max-width: 860px) { .tos-toc-fab { display: flex; } }

/* Mobile TOC drawer */
.tos-toc-drawer {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 2000;
    background: rgba(5,30,68,.55);
    backdrop-filter: blur(4px);
}
.tos-toc-drawer.open { display: flex; align-items: flex-end; }
.tos-toc-drawer-inner {
    width: 100%;
    background: var(--white);
    border-radius: 20px 20px 0 0;
    max-height: 75vh;
    overflow-y: auto;
    animation: drawerUp .3s cubic-bezier(.16,1,.3,1);
}
@keyframes drawerUp {
    from { transform: translateY(100%); }
    to   { transform: translateY(0); }
}
.tos-toc-drawer-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--g100);
    position: sticky;
    top: 0;
    background: var(--white);
    z-index: 1;
}
.tos-toc-drawer-head span { font-size: 14px; font-weight: 800; color: var(--navy); }
.tos-drawer-close {
    width: 30px; height: 30px; border-radius: 8px;
    border: 1.5px solid var(--g200); background: var(--g50);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; color: var(--g400); cursor: pointer;
}

/* ══════════════════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════════════════ */
@media (max-width: 600px) {
    .tos-layout { padding: 24px 14px 60px; }
    .tos-doc-header { padding: 20px 18px; }
    .tos-section-head { padding: 14px 16px; gap: 10px; }
    .tos-section.open .tos-section-body { padding: 14px 16px 18px; }
    .tos-sec-num { width: 30px; height: 30px; font-size: 12px; border-radius: 8px; }
    .tos-sec-title { font-size: 13px; }
    .tos-section-body p { font-size: 13px; }
    .tos-contact-card { padding: 28px 20px; }
    .tos-hero { padding: 50px 0 44px; }
}

@media print {
    .tos-tabs-outer, .tos-toc-wrap, .tos-hero .tos-meta-strip,
    .tos-expand-btns, .tos-contact-card,
    .tos-toc-fab, .tos-progress-bar, .tos-print-btn { display: none !important; }
    .tos-layout { display: block !important; padding: 0; }
    .tos-section-body { max-height: none !important; padding: 12px 0 16px !important; }
    .tos-section { break-inside: avoid; border: none; box-shadow: none; }
    .tos-section-head { background: none !important; border-bottom: 1px solid #ddd; }
}
</style>
</head>
<body>

<!-- Reading progress bar -->
<div class="tos-progress-bar" id="tosProgress"></div>

<?php include_once('includes/header.php'); ?>
<?php include_once('includes/nav.php'); ?>

<main>

<!-- ════════════════════════════════════════════════════════
     HERO
════════════════════════════════════════════════════════ -->
<section class="tos-hero">
    <div class="container tos-hero-inner">
        <div class="tos-eyebrow">
            <i class="fas fa-shield-halved"></i> Legal Documents
        </div>
        <h1 class="tos-hero-title">
            Terms &amp; <span>Privacy</span>
        </h1>
        <p class="tos-hero-sub">
            Everything you need to know about how Keychele works, what we
            collect, and how we protect your information.
        </p>
        <div class="tos-meta-strip">
            <?php if ($terms): ?>
            <div class="tos-meta-pill">
                <i class="fas fa-file-contract"></i>
                Terms v<?= htmlspecialchars($terms['version']) ?>
            </div>
            <div class="tos-meta-pill">
                <i class="fas fa-calendar"></i>
                <?= fmtEffDate($terms['effective_date']) ?>
            </div>
            <?php endif; ?>
            <?php if ($privacy): ?>
            <div class="tos-meta-pill">
                <i class="fas fa-lock"></i>
                Privacy v<?= htmlspecialchars($privacy['version']) ?>
            </div>
            <?php endif; ?>
            <button class="tos-print-btn" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</section>

<!-- ════════════════════════════════════════════════════════
     STICKY TABS
════════════════════════════════════════════════════════ -->
<div class="tos-tabs-outer">
    <div class="tos-tabs-inner">
        <div class="tos-tab-nav">
            <a href="?tab=terms"
               class="tos-tab-btn <?= $active_tab === 'terms' ? 'active' : '' ?>"
               id="tabBtnTerms">
                <i class="fas fa-file-contract"></i> Terms of Service
            </a>
            <a href="?tab=privacy"
               class="tos-tab-btn <?= $active_tab === 'privacy' ? 'active' : '' ?>"
               id="tabBtnPrivacy">
                <i class="fas fa-shield-halved"></i> Privacy Policy
            </a>
        </div>
        <?php
        $currentDoc = $active_tab === 'terms' ? $terms : $privacy;
        if ($currentDoc): ?>
        <div class="tos-updated-label">
            <i class="fas fa-clock-rotate-left"></i>
            Last updated <?= date('d M Y', strtotime($currentDoc['updated_at'])) ?>
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════
     MAIN LAYOUT
════════════════════════════════════════════════════════ -->
<div class="tos-layout">

    <!-- ── SIDEBAR: TABLE OF CONTENTS ── -->
    <aside class="tos-toc-wrap">
        <?php
        $tocDoc      = $active_tab === 'terms' ? $terms      : $privacy;
        $tocSections = $active_tab === 'terms' ? $termsSections : $privacySections;
        ?>
        <div class="tos-toc" id="tosToc">
            <div class="tos-toc-head">
                <i class="fas fa-list-ul"></i>
                <span>Contents</span>
            </div>
            <div class="tos-toc-body" id="tosTocBody">
                <?php if (!empty($tocSections)): ?>
                    <?php foreach ($tocSections as $i => $sec): ?>
                    <a class="tos-toc-item"
                       href="#sec-<?= slugify($sec['section_title']) ?>"
                       data-target="sec-<?= slugify($sec['section_title']) ?>">
                        <span class="tos-toc-num"><?= $i + 1 ?></span>
                        <?= htmlspecialchars($sec['section_title']) ?>
                    </a>
                    <?php endforeach; ?>
                    <div class="tos-toc-divider"></div>
                <?php else: ?>
                    <div style="padding:12px 18px;font-size:12px;color:var(--g400)">
                        No sections available.
                    </div>
                <?php endif; ?>

                <?php if ($tocDoc && !empty($tocDoc['contact_email'])): ?>
                <div class="tos-toc-contact">
                    <p>
                        <i class="fas fa-envelope" style="color:var(--cr);margin-right:4px;font-size:11px"></i>
                        Questions? Contact us at<br>
                        <a href="mailto:<?= htmlspecialchars($tocDoc['contact_email']) ?>">
                            <?= htmlspecialchars($tocDoc['contact_email']) ?>
                        </a>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </aside>


    <!-- ── CONTENT ── -->
    <div id="tosContent">

        <!-- ════ TERMS OF SERVICE PANEL ════ -->
        <div class="tos-panel <?= $active_tab === 'terms' ? 'active' : '' ?>" id="panel-terms">
            <?php if ($terms && !empty($termsSections)): ?>

            <!-- Doc header -->
            <div class="tos-doc-header">
                <div class="tos-doc-icon"><i class="fas fa-file-contract"></i></div>
                <h1 class="tos-doc-title"><?= htmlspecialchars($terms['title']) ?></h1>
                <?php if (!empty($terms['intro'])): ?>
                <p class="tos-doc-intro"><?= htmlspecialchars($terms['intro']) ?></p>
                <?php endif; ?>
                <div class="tos-doc-meta-row">
                    <span class="tos-doc-badge tos-badge-ver">
                        <i class="fas fa-tag"></i> Version <?= htmlspecialchars($terms['version']) ?>
                    </span>
                    <span class="tos-doc-badge tos-badge-date">
                        <i class="fas fa-calendar-check"></i>
                        <?= fmtEffDate($terms['effective_date']) ?>
                    </span>
                    <span class="tos-doc-badge tos-badge-live">
                        <i class="fas fa-circle" style="font-size:7px"></i> Active &amp; Published
                    </span>
                </div>
            </div>

            <!-- Expand / collapse controls -->
            <div class="tos-expand-row">
                <div class="tos-expand-info">
                    <i class="fas fa-layer-group"></i>
                    <?= count($termsSections) ?> section<?= count($termsSections) !== 1 ? 's' : '' ?> — click any to expand
                </div>
                <div class="tos-expand-btns">
                    <button class="tos-expand-btn" onclick="tosExpandAll(false)">
                        <i class="fas fa-minus"></i> Collapse All
                    </button>
                    <button class="tos-expand-btn primary" onclick="tosExpandAll(true)">
                        <i class="fas fa-plus"></i> Expand All
                    </button>
                </div>
            </div>

            <!-- Sections -->
            <?php foreach ($termsSections as $i => $sec):
                $slug = slugify($sec['section_title']);
            ?>
            <div class="tos-section <?= $i === 0 ? 'open' : '' ?>" id="sec-<?= $slug ?>">
                <div class="tos-section-head" onclick="tosToggle(this.closest('.tos-section'))">
                    <div class="tos-sec-num"><?= $i + 1 ?></div>
                    <div class="tos-sec-title-wrap">
                        <div class="tos-sec-title"><?= htmlspecialchars($sec['section_title']) ?></div>
                        <div class="tos-sec-tag">Section <?= $i + 1 ?> of <?= count($termsSections) ?></div>
                    </div>
                    <div class="tos-sec-toggle"><i class="fas fa-chevron-down"></i></div>
                </div>
                <div class="tos-section-body">
                    <?php if (!empty($sec['section_body'])): ?>
                        <?= nl2p($sec['section_body']) ?>
                    <?php else: ?>
                        <p style="color:var(--g400);font-style:italic">Content coming soon.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php else: ?>
            <div class="tos-empty">
                <div class="tos-empty-icon"><i class="fas fa-file-contract"></i></div>
                <h3>Terms of Service Coming Soon</h3>
                <p>Our legal team is finalising the Terms of Service. Please check back shortly.</p>
            </div>
            <?php endif; ?>
        </div>
        <!-- /terms panel -->


        <!-- ════ PRIVACY POLICY PANEL ════ -->
        <div class="tos-panel <?= $active_tab === 'privacy' ? 'active' : '' ?>" id="panel-privacy">
            <?php if ($privacy && !empty($privacySections)): ?>

            <!-- Doc header -->
            <div class="tos-doc-header">
                <div class="tos-doc-icon" style="background:linear-gradient(135deg,var(--cr-d),var(--cr))">
                    <i class="fas fa-shield-halved"></i>
                </div>
                <h1 class="tos-doc-title"><?= htmlspecialchars($privacy['title']) ?></h1>
                <?php if (!empty($privacy['intro'])): ?>
                <p class="tos-doc-intro"><?= htmlspecialchars($privacy['intro']) ?></p>
                <?php endif; ?>
                <div class="tos-doc-meta-row">
                    <span class="tos-doc-badge tos-badge-ver">
                        <i class="fas fa-tag"></i> Version <?= htmlspecialchars($privacy['version']) ?>
                    </span>
                    <span class="tos-doc-badge tos-badge-date">
                        <i class="fas fa-calendar-check"></i>
                        <?= fmtEffDate($privacy['effective_date']) ?>
                    </span>
                    <span class="tos-doc-badge tos-badge-live">
                        <i class="fas fa-circle" style="font-size:7px"></i> Active &amp; Published
                    </span>
                </div>
            </div>

            <!-- Expand / collapse controls -->
            <div class="tos-expand-row">
                <div class="tos-expand-info">
                    <i class="fas fa-layer-group"></i>
                    <?= count($privacySections) ?> section<?= count($privacySections) !== 1 ? 's' : '' ?> — click any to expand
                </div>
                <div class="tos-expand-btns">
                    <button class="tos-expand-btn" onclick="tosExpandAll(false)">
                        <i class="fas fa-minus"></i> Collapse All
                    </button>
                    <button class="tos-expand-btn primary" onclick="tosExpandAll(true)">
                        <i class="fas fa-plus"></i> Expand All
                    </button>
                </div>
            </div>

            <!-- Sections -->
            <?php foreach ($privacySections as $i => $sec):
                $slug = slugify($sec['section_title']);
            ?>
            <div class="tos-section <?= $i === 0 ? 'open' : '' ?>" id="sec-<?= $slug ?>">
                <div class="tos-section-head" onclick="tosToggle(this.closest('.tos-section'))">
                    <div class="tos-sec-num" style="background:var(--cr)"><?= $i + 1 ?></div>
                    <div class="tos-sec-title-wrap">
                        <div class="tos-sec-title"><?= htmlspecialchars($sec['section_title']) ?></div>
                        <div class="tos-sec-tag">Section <?= $i + 1 ?> of <?= count($privacySections) ?></div>
                    </div>
                    <div class="tos-sec-toggle"><i class="fas fa-chevron-down"></i></div>
                </div>
                <div class="tos-section-body">
                    <?php if (!empty($sec['section_body'])): ?>
                        <?= nl2p($sec['section_body']) ?>
                    <?php else: ?>
                        <p style="color:var(--g400);font-style:italic">Content coming soon.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php else: ?>
            <div class="tos-empty">
                <div class="tos-empty-icon"><i class="fas fa-shield-halved"></i></div>
                <h3>Privacy Policy Coming Soon</h3>
                <p>Our Privacy Policy is being prepared. Please check back shortly.</p>
            </div>
            <?php endif; ?>
        </div>
        <!-- /privacy panel -->


        <!-- ── CONTACT / BOTTOM CTA ── -->
        <div class="tos-contact-card">
            <div class="tos-contact-card-icon"><i class="fas fa-envelope-open-text"></i></div>
            <h3>Questions About These Policies?</h3>
            <p>
                If you have any questions, concerns, or need clarification about our
                Terms of Service or Privacy Policy, our support team is ready to help.
            </p>
            <?php
            $emailAddr = null;
            if ($active_tab === 'terms' && $terms && !empty($terms['contact_email'])) {
                $emailAddr = $terms['contact_email'];
            } elseif ($active_tab === 'privacy' && $privacy && !empty($privacy['contact_email'])) {
                $emailAddr = $privacy['contact_email'];
            }
            $emailAddr = $emailAddr ?: 'support@keychele.co.ke';
            ?>
            <a href="mailto:<?= htmlspecialchars($emailAddr) ?>" class="tos-contact-btn">
                <i class="fas fa-envelope"></i> Contact Support
            </a>
        </div>

    </div>
    <!-- /content -->

</div>
<!-- /layout -->

</main>


<!-- ── Mobile TOC Drawer ── -->
<button class="tos-toc-fab" id="tosFab" onclick="tosDrawerOpen()" title="Table of Contents">
    <i class="fas fa-list-ul"></i>
</button>
<div class="tos-toc-drawer" id="tosDrawer" onclick="tosDrawerClose(event)">
    <div class="tos-toc-drawer-inner">
        <div class="tos-toc-drawer-head">
            <span><i class="fas fa-list-ul" style="color:var(--navy);margin-right:6px"></i> Contents</span>
            <button class="tos-drawer-close" onclick="tosDrawerClose()"><i class="fas fa-xmark"></i></button>
        </div>
        <div style="padding:8px 0 16px">
            <?php foreach ($tocSections as $i => $sec): ?>
            <a class="tos-toc-item"
               href="#sec-<?= slugify($sec['section_title']) ?>"
               onclick="tosDrawerClose()"
               data-target="sec-<?= slugify($sec['section_title']) ?>">
                <span class="tos-toc-num"><?= $i + 1 ?></span>
                <?= htmlspecialchars($sec['section_title']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<?php include_once('includes/footer.php'); ?>

<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.sticky.js"></script>
<script src="js/custom.js"></script>

<script>
/* ════════════════════════════════════════════════
   SECTION ACCORDION
════════════════════════════════════════════════ */
function tosToggle(section) {
    var isOpen = section.classList.contains('open');
    section.classList.toggle('open', !isOpen);
}

function tosExpandAll(open) {
    var panel = document.querySelector('.tos-panel.active');
    if (!panel) return;
    panel.querySelectorAll('.tos-section').forEach(function (s) {
        s.classList.toggle('open', open);
    });
}

/* ════════════════════════════════════════════════
   TOC ACTIVE HIGHLIGHT (scroll spy)
════════════════════════════════════════════════ */
var tocItems = document.querySelectorAll('.tos-toc-item[data-target]');

function kcUpdateToc() {
    var scrollY = window.scrollY + 100;
    var active  = null;
    document.querySelectorAll('.tos-panel.active .tos-section').forEach(function (sec) {
        if (sec.offsetTop <= scrollY) active = sec.id;
    });
    tocItems.forEach(function (item) {
        item.classList.toggle('active', item.dataset.target === active);
    });
}
window.addEventListener('scroll', kcUpdateToc, { passive: true });
kcUpdateToc();

/* ────── Smooth scroll TOC click ── */
tocItems.forEach(function (item) {
    item.addEventListener('click', function (e) {
        e.preventDefault();
        var target = document.getElementById(this.dataset.target);
        if (!target) return;
        if (!target.classList.contains('open')) target.classList.add('open');
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        // highlight briefly
        target.classList.add('highlighted');
        setTimeout(function () { target.classList.remove('highlighted'); }, 1800);
    });
});

/* ════════════════════════════════════════════════
   READING PROGRESS BAR
════════════════════════════════════════════════ */
var progressBar = document.getElementById('tosProgress');
window.addEventListener('scroll', function () {
    var docH = document.documentElement.scrollHeight - window.innerHeight;
    var pct  = docH > 0 ? (window.scrollY / docH * 100) : 0;
    if (progressBar) progressBar.style.width = pct.toFixed(2) + '%';
}, { passive: true });

/* ════════════════════════════════════════════════
   MOBILE DRAWER
════════════════════════════════════════════════ */
function tosDrawerOpen()  { document.getElementById('tosDrawer').classList.add('open');  document.body.style.overflow = 'hidden'; }
function tosDrawerClose(e) {
    if (e && e.target !== document.getElementById('tosDrawer')) return;
    document.getElementById('tosDrawer').classList.remove('open');
    document.body.style.overflow = '';
}
document.querySelector('.tos-drawer-close') &&
    document.querySelector('.tos-drawer-close').addEventListener('click', function () {
        document.getElementById('tosDrawer').classList.remove('open');
        document.body.style.overflow = '';
    });

/* ════════════════════════════════════════════════
   OPEN FIRST SECTION ON LOAD
════════════════════════════════════════════════ */
(function () {
    var hash = window.location.hash.slice(1);
    if (hash) {
        var target = document.getElementById(hash);
        if (target && target.classList.contains('tos-section')) {
            setTimeout(function () {
                target.classList.add('open');
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 300);
        }
    }
})();
</script>

</body>
</html>
