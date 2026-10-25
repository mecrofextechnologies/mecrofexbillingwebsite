<?php
/* ════════════════════════════════════════════════════════════
   MECROFEX BILLING — home page (index.php)
   Static page: no database. Shared settings live in includes/site_config.php.
════════════════════════════════════════════════════════════════ */
date_default_timezone_set('Africa/Nairobi');

// ── Hero slider content ──────────────────────────────────────
// 'title' is trusted HTML (<em> gets the sky-blue gradient); every other
// field is escaped on output.
include_once __DIR__ . '/includes/site_config.php';
$heroChips = [
    ['fa-circle-check', true,  'Payment received', 'KES 1,500 · M-Pesa'],
    ['fa-wifi',         false, 'Auto-reconnected', 'Customer back online'],
];
$heroSlides = [
    [
        'image'   => 'images/slide/jason-goodman-0K7GgiA8lVE-unsplash.jpg',
        'eyebrow' => 'ISP & Hotspot Billing',
        'title'   => 'Billing that <em>runs itself</em>',
        'sub'     => 'Automate PPPoE and hotspot billing, collect payments by M-Pesa, and disconnect or reconnect customers automatically.',
        'btn1'    => ['Book a Demo', '#demo'],
        'btn2'    => ['See Pricing', '#pricing'],
        'chips'   => $heroChips,
    ],
    [
        'image'   => 'images/slide/christina-wocintechchat-com-NDoVgcS_lZM-unsplash.jpg',
        'eyebrow' => 'Hotspot Vouchers',
        'title'   => 'Sell Wi-Fi access <em>while you sleep</em>',
        'sub'     => 'Customers pick a package, pay with M-Pesa and get online instantly. No vouchers to print, no cash to chase.',
        'btn1'    => ['Explore Features', '#features'],
        'btn2'    => ['Book a Demo', '#demo'],
        'chips'   => [
            ['fa-ticket', true,  'Voucher issued', '1 Day · Unlimited'],
            ['fa-users',  false, 'Users online',   'Live from your routers'],
        ],
    ],
    [
        'image'   => 'images/slide/jason-goodman-0K7GgiA8lVE-unsplash.jpg',
        'eyebrow' => 'MikroTik Integration',
        'title'   => 'Every router, <em>one dashboard</em>',
        'sub'     => 'Connect your MikroTik routers, set packages and speeds, and track usage and revenue in real time.',
        'btn1'    => ['Book a Demo', '#demo'],
        'btn2'    => ['Client Login', $appLoginUrl],
        'chips'   => [
            ['fa-server',     true,  'Router synced', 'MikroTik · online'],
            ['fa-chart-line', false, 'Revenue today', 'Updated in real time'],
        ],
    ],
];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= htmlspecialchars($siteName) ?> — ISP &amp; Hotspot Billing with M-Pesa in Kenya</title>
    <meta name="description" content="Billing for internet providers: hotspot vouchers, PPPoE, automatic M-Pesa payments, auto reconnection and MikroTik integration. From KES 500 a month.">
    <meta name="keywords" content="ISP billing Kenya, hotspot billing software, PPPoE billing, MikroTik billing system, M-Pesa billing system, hotspot vouchers, internet service provider billing software, WiFi hotspot billing Kenya, captive portal billing, prepaid internet billing, router billing software, estate WiFi billing, campus WiFi billing">
    <meta name="author" content="Mecrofex Technologies">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://mecrofexbilling.com/">

    <meta property="og:title" content="<?= htmlspecialchars($siteName) ?> — ISP &amp; Hotspot Billing with M-Pesa">
    <meta property="og:description" content="Hotspot vouchers, PPPoE, automatic M-Pesa payments and MikroTik integration in one dashboard.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://mecrofexbilling.com/">
    <meta property="og:image" content="<?= htmlspecialchars($siteLogo) ?>">
    <meta property="og:image:width" content="256">
    <meta property="og:image:height" content="256">
    <meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
    <meta property="og:locale" content="en_KE">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= htmlspecialchars($siteName) ?> — ISP &amp; Hotspot Billing">
    <meta name="twitter:description" content="Hotspot vouchers, PPPoE, automatic M-Pesa payments and MikroTik integration in one dashboard.">
    <meta name="twitter:image" content="<?= htmlspecialchars($siteLogo) ?>">
    <meta name="geo.region" content="KE">
    <meta name="geo.placename" content="Nairobi">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    <!-- Core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/tooplate-waso-strategy.css" rel="stylesheet">

    <link rel="icon" href="<?= htmlspecialchars($siteLogo) ?>">

    <script type="application/ld+json">
    <?= json_encode([
        '@context'    => 'https://schema.org',
        '@type'       => 'Organization',
        'name'        => $siteName,
        'logo'        => $siteLogo,
        'description' => 'Billing software for internet providers in Kenya: hotspot vouchers, PPPoE, M-Pesa payments and MikroTik integration.',
        'address'     => ['@type' => 'PostalAddress', 'addressLocality' => 'Nairobi', 'addressCountry' => 'KE'],
        'email'       => $siteEmail,
        'telephone'   => $sitePhoneTel,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>

    </script>
    <script>document.documentElement.classList.add('mb-js');</script>
</head>

<style>
/* ═══════════════════════════════════════════════════════════════
   MECROFEX BILLING — home page styles
   Colours: --bl-* tokens (defined in includes/nav.php) + per-section tokens
═══════════════════════════════════════════════════════════════ */
:root {
    --fh: 'Montserrat', system-ui, sans-serif;   /* heading font */
}

/* ═══════════════════════════════════════════════════
   1. HERO SLIDER — split layout, progress-bar autoplay
═══════════════════════════════════════════════════ */
.mb-hero {
    --h-navy:   #071d3a;                        /* logo navy */
    --h-navy-2: #0c3b6e;
    --h-blue:   var(--bl-primary, #0284c7);
    --h-sky:    var(--bl-accent, #38bdf8);
    --h-ease:   cubic-bezier(.16,1,.3,1);
    position: relative;
    overflow: hidden;
    color: #fff;
    font-family: var(--fh);
    padding: 64px 0 44px;
    background:
        radial-gradient(900px 520px at 88% 18%, rgba(56,189,248,.26), transparent 62%),
        radial-gradient(700px 520px at 0% 100%, rgba(2,132,199,.34), transparent 60%),
        linear-gradient(135deg, var(--h-navy) 0%, var(--h-navy-2) 100%);
}
/* faint grid, fading out from the image side */
.mb-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px);
    background-size: 44px 44px;
    -webkit-mask-image: radial-gradient(ellipse at 70% 40%, #000 15%, transparent 72%);
            mask-image: radial-gradient(ellipse at 70% 40%, #000 15%, transparent 72%);
    pointer-events: none;
}
.mb-hero .container { position: relative; z-index: 1; }

/* Slides stack in one grid cell, so the track is as tall as the tallest slide */
.mb-hero-track { display: grid; }
.mb-slide {
    grid-area: 1 / 1;
    display: grid;
    grid-template-columns: 1.05fr 1fr;
    gap: 56px;
    align-items: center;
    opacity: 0;
    visibility: hidden;
    transition: opacity .6s ease, visibility 0s linear .6s;
}
.mb-slide.is-active {
    opacity: 1;
    visibility: visible;
    transition: opacity .6s ease;
}

/* Text: staggered rise-in */
.mb-slide-text > * {
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .6s ease, transform .7s var(--h-ease);
}
.mb-slide.is-active .mb-slide-text > * { opacity: 1; transform: none; }
.mb-slide.is-active .mb-slide-text > :nth-child(2) { transition-delay: .08s; }
.mb-slide.is-active .mb-slide-text > :nth-child(3) { transition-delay: .16s; }
.mb-slide.is-active .mb-slide-text > :nth-child(4) { transition-delay: .24s; }

.mb-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(56,189,248,.14);
    border: 1px solid rgba(56,189,248,.38);
    color: #7dd3fc;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    padding: 6px 16px;
    border-radius: 30px;
    margin-bottom: 18px;
}
.mb-title {
    font-family: var(--fh);
    font-size: clamp(2rem, 4.2vw, 3.4rem);
    font-weight: 900;
    line-height: 1.1;
    letter-spacing: -.02em;
    color: #fff;
    margin: 0 0 18px;
}
.mb-title em {
    font-style: normal;
    background: linear-gradient(90deg, #7dd3fc, var(--h-sky));
    -webkit-background-clip: text;
            background-clip: text;
    color: transparent;
}
.mb-sub {
    font-size: clamp(14px, 1.4vw, 17px);
    color: rgba(255,255,255,.78);
    line-height: 1.7;
    max-width: 520px;
    margin: 0 0 30px;
}
.mb-btns { display: flex; gap: 12px; flex-wrap: wrap; }
.mb-btn-main,
.mb-btn-ghost {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 13px 28px;
    border-radius: 50px;
    font-family: var(--fh);
    font-size: 14px;
    font-weight: 800;
    text-decoration: none;
    transition: background .2s, color .2s, border-color .2s, transform .2s, box-shadow .2s;
}
.mb-btn-main {
    background: var(--h-sky);
    color: var(--h-navy);
    box-shadow: 0 8px 24px rgba(56,189,248,.35);
}
.mb-btn-main:hover {
    background: #7dd3fc;
    color: var(--h-navy);
    transform: translateY(-2px);
}
.mb-btn-ghost {
    border: 1.5px solid rgba(255,255,255,.35);
    color: #fff;
    background: rgba(255,255,255,.04);
}
.mb-btn-ghost:hover {
    border-color: #fff;
    background: rgba(255,255,255,.12);
    color: #fff;
}

/* Image: wipe-in reveal + slow zoom-out */
.mb-visual { position: relative; }
.mb-frame {
    position: relative;
    aspect-ratio: 4 / 3;
    border-radius: 24px;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,.14);
    box-shadow: 0 30px 80px rgba(0,0,0,.45);
    clip-path: inset(0 0 0 100% round 24px);
    transition: clip-path .9s cubic-bezier(.77,0,.18,1);
}
.mb-slide.is-active .mb-frame { clip-path: inset(0 0 0 0 round 24px); }
.mb-frame img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transform: scale(1.12);
    transition: transform 6s ease-out;
}
.mb-slide.is-active .mb-frame img { transform: scale(1); }
.mb-frame::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(7,29,58,0) 45%, rgba(7,29,58,.55) 100%);
}

/* Floating info cards over the image */
.mb-chip {
    position: absolute;
    z-index: 2;
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,.97);
    color: #0f172a;
    padding: 10px 16px 10px 10px;
    border-radius: 14px;
    box-shadow: 0 14px 34px rgba(0,0,0,.28);
    opacity: 0;
    transform: translateY(12px) scale(.96);
    transition: opacity .5s ease, transform .6s var(--h-ease);
}
.mb-slide.is-active .mb-chip { opacity: 1; transform: none; transition-delay: .6s; }
.mb-slide.is-active .mb-chip-2 { transition-delay: .75s; }
.mb-chip-1 { left: -28px; bottom: 34px; }
.mb-chip-2 { right: -18px; top: 28px; }
.mb-chip-ico {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    background: var(--bl-tint, #e0f2fe);
    color: var(--h-blue);
    font-size: 15px;
    flex-shrink: 0;
}
.mb-chip-ico.is-green { background: #dcfce7; color: #16a34a; }
.mb-chip strong { display: block; font-size: 13px; font-weight: 800; line-height: 1.2; }
.mb-chip span   { display: block; font-size: 11.5px; color: #64748b; margin-top: 2px; }

/* Controls: counter · progress bars · arrows */
.mb-controls {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-top: 44px;
}
.mb-count {
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 1px;
    color: rgba(255,255,255,.5);
    font-variant-numeric: tabular-nums;
}
.mb-count b { color: #fff; }
.mb-bars { display: flex; gap: 8px; flex: 1; max-width: 320px; }
.mb-bar {
    flex: 1;
    padding: 10px 0;            /* taller hit area than the 4px line */
    border: 0;
    background: none;
    cursor: pointer;
}
.mb-bar span {
    display: block;
    position: relative;
    height: 4px;
    border-radius: 4px;
    background: rgba(255,255,255,.18);
    overflow: hidden;
}
.mb-bar span::after {
    content: '';
    position: absolute;
    inset: 0;
    background: var(--h-sky);
    transform: scaleX(0);
    transform-origin: left;
}
.mb-bar.is-done span::after { transform: scaleX(1); }
.mb-bar.is-active span::after { animation: mbFill var(--mb-interval, 6s) linear forwards; }
.mb-hero.is-paused .mb-bar.is-active span::after { animation-play-state: paused; }
.mb-hero.is-static .mb-bar.is-active span::after { animation: none; transform: scaleX(1); }
.mb-bar:focus-visible span { outline: 2px solid #7dd3fc; outline-offset: 4px; }
@keyframes mbFill { to { transform: scaleX(1); } }

.mb-arrows { display: flex; gap: 8px; margin-left: auto; }
.mb-arrow {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: 1.5px solid rgba(255,255,255,.28);
    background: transparent;
    color: #fff;
    display: grid;
    place-items: center;
    cursor: pointer;
    transition: background .2s, color .2s, border-color .2s;
}
.mb-arrow:hover,
.mb-arrow:focus-visible {
    background: var(--h-sky);
    border-color: var(--h-sky);
    color: var(--h-navy);
}


@media (max-width: 991px) {
    .mb-hero { padding: 36px 0 32px; }
    .mb-slide { grid-template-columns: 1fr; gap: 28px; }
    .mb-visual { order: -1; max-width: 600px; }
    .mb-frame { aspect-ratio: 16 / 9; }
    .mb-chip-1 { left: 12px; bottom: 12px; }
    .mb-chip-2 { right: 12px; top: 12px; }
    .mb-controls { margin-top: 30px; }
}
@media (max-width: 576px) {
    .mb-chip-2 { display: none; }
    .mb-btn-main, .mb-btn-ghost { padding: 12px 22px; font-size: 13.5px; }
    .mb-controls { gap: 14px; }
    .mb-arrow { width: 40px; height: 40px; }
}
@media (prefers-reduced-motion: reduce) {
    .mb-slide, .mb-slide *, .mb-frame, .mb-frame img { transition: none !important; }
    .mb-frame { clip-path: none !important; }
    .mb-frame img { transform: none !important; }
}

/* ═══════════════════════════════════════════════════
   2. LIVE DASHBOARD PREVIEW — animated product mock
═══════════════════════════════════════════════════ */
.mb-dash {
    --d-navy:  #071d3a;
    --d-blue:  var(--bl-primary, #0284c7);
    --d-sky:   var(--bl-accent, #38bdf8);
    --d-tint:  var(--bl-tint, #e0f2fe);
    --d-ink:   #0f172a;
    --d-muted: #64748b;
    --d-line:  #e2e8f0;
    --d-soft:  #eef2f7;
    --d-green: #16a34a;
    --d-ease:  cubic-bezier(.16,1,.3,1);
    position: relative;
    overflow: hidden;
    padding: 88px 0 96px;
    background: linear-gradient(180deg, #f0f9ff 0%, #fff 72%);
}
.mb-dash::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(640px 320px at 15% 0%, rgba(56,189,248,.2), transparent 70%);
    pointer-events: none;
}
.mb-dash .container { position: relative; }

/* Heading */
.mb-dash-head { text-align: left; max-width: 620px; margin: 0 0 36px; }
.mb-dash-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--d-tint);
    color: var(--d-blue);
    font-family: var(--fh);
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    padding: 6px 16px;
    border-radius: 30px;
}
.mb-dash-title {
    font-family: var(--fh);
    font-size: clamp(1.5rem, 2.4vw, 2rem);
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -.02em;
    color: var(--d-navy);
    margin: 16px 0 14px;
    text-wrap: balance;
}
.mb-dash-title em { font-style: normal; color: var(--d-blue); }
.mb-dash-sub { color: var(--d-muted); font-size: 15px; line-height: 1.7; margin: 0; }

/* App frame */
.mb-app {
    background: #fff;
    border: 1px solid var(--d-line);
    border-radius: 20px;
    overflow: hidden;
    color: var(--d-ink);
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    box-shadow: 0 40px 90px -30px rgba(7,29,58,.35), 0 0 0 6px rgba(56,189,248,.08);
    opacity: 0;
    transform: translateY(40px) scale(.98);
    transition: opacity .8s ease, transform 1s var(--d-ease);
}
.mb-dash.is-in .mb-app { opacity: 1; transform: none; }

.mb-app-bar {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    background: #f8fafc;
    border-bottom: 1px solid var(--d-line);
}
.mb-app-dots { display: flex; gap: 6px; }
.mb-app-dots i { width: 11px; height: 11px; border-radius: 50%; background: #f87171; }
.mb-app-dots i:nth-child(2) { background: #fbbf24; }
.mb-app-dots i:nth-child(3) { background: #4ade80; }
.mb-app-url {
    flex: 1;
    max-width: 420px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid var(--d-line);
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 12px;
    color: var(--d-muted);
    text-align: center;
}
.mb-app-url i { color: var(--d-green); margin-right: 4px; font-size: 10px; }
.mb-app-tag {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
    color: var(--d-blue);
    background: var(--d-tint);
    padding: 4px 10px;
    border-radius: 20px;
    white-space: nowrap;
}

.mb-app-body { display: grid; grid-template-columns: 236px 1fr; }

/* Sidebar */
.mb-app-side { border-right: 1px solid var(--d-line); padding: 18px 14px; }
.mb-app-user {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    border: 1px solid var(--d-line);
    border-radius: 12px;
    margin-bottom: 18px;
}
.mb-app-av {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--d-tint);
    color: var(--d-blue);
    display: grid;
    place-items: center;
    font-weight: 800;
    font-size: 13px;
}
.mb-app-user strong { display: block; font-size: 13px; }
.mb-app-user em {
    font-style: normal;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .8px;
    text-transform: uppercase;
    color: #b45309;
    background: #fef3c7;
    padding: 2px 6px;
    border-radius: 4px;
}
.mb-app-nav span {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border-radius: 10px;
    font-size: 13px;
    color: #334155;
    margin-bottom: 2px;
    white-space: nowrap;
}
.mb-app-nav span i { width: 18px; text-align: center; color: var(--d-blue); font-size: 13px; }
.mb-app-nav span.is-active {
    background: var(--d-tint);
    color: var(--d-blue);
    font-weight: 700;
    box-shadow: inset 3px 0 0 var(--d-blue);
}
.mb-app-nav b {
    margin-left: auto;
    font-size: 10.5px;
    background: var(--d-blue);
    color: #fff;
    padding: 1px 7px;
    border-radius: 6px;
    font-variant-numeric: tabular-nums;
}
.mb-app-group {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: #94a3b8;
    margin: 16px 10px 6px;
}

/* Main panel */
.mb-app-main { padding: 20px 24px 24px; background: #f8fafc; min-width: 0; }
.mb-app-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.mb-app-search {
    flex: 1;
    min-width: 200px;
    max-width: 360px;
    background: #fff;
    border: 1px solid var(--d-line);
    border-radius: 10px;
    padding: 9px 14px;
    font-size: 12.5px;
    color: #94a3b8;
}
.mb-app-search i { margin-right: 8px; }
.mb-app-online {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #15803d;
    font-size: 12.5px;
    font-weight: 600;
    padding: 7px 14px;
    border-radius: 30px;
}
.mb-app-online b { font-variant-numeric: tabular-nums; }
.mb-pulse {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22c55e;
    animation: mbPulse 1.8s infinite;
}
@keyframes mbPulse {
    0%   { box-shadow: 0 0 0 0 rgba(34,197,94,.6); }
    70%  { box-shadow: 0 0 0 8px rgba(34,197,94,0); }
    100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
}
.mb-app-title small {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--d-muted);
}
.mb-app-title h3 { font-family: var(--fh); font-size: 24px; font-weight: 800; margin: 2px 0; color: var(--d-ink); }
.mb-app-title h3 span { color: var(--d-blue); }
.mb-app-title p { font-size: 12.5px; color: var(--d-muted); margin: 0 0 18px; }

/* Stat cards */
.mb-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 14px; }
.mb-stat,
.mb-card {
    background: #fff;
    border: 1px solid var(--d-line);
    border-radius: 14px;
    min-width: 0;
    opacity: 0;
    transform: translateY(16px);
    transition: opacity .6s ease, transform .7s var(--d-ease);
}
.mb-dash.is-in .mb-stat,
.mb-dash.is-in .mb-card { opacity: 1; transform: none; }
.mb-dash.is-in .mb-stat:nth-child(1) { transition-delay: .3s; }
.mb-dash.is-in .mb-stat:nth-child(2) { transition-delay: .4s; }
.mb-dash.is-in .mb-stat:nth-child(3) { transition-delay: .5s; }
.mb-dash.is-in .mb-stat:nth-child(4) { transition-delay: .6s; }
.mb-dash.is-in .mb-card { transition-delay: .7s; }

.mb-stat { border-top: 3px solid var(--c); padding: 14px 16px; }
.mb-stat-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--d-muted);
}
.mb-stat-top i {
    width: 32px;
    height: 32px;
    border-radius: 9px;
    display: grid;
    place-items: center;
    background: var(--c);
    color: #fff;
    font-size: 13px;
}
.mb-stat-val {
    font-size: 22px;
    font-weight: 800;
    margin: 8px 0 4px;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.mb-stat-val b { font-weight: 800; transition: color .5s; }
.mb-stat-val b.is-bump { color: var(--d-green); }
.mb-stat-foot { font-size: 11.5px; color: var(--d-muted); }
.mb-stat-foot.is-up { color: var(--d-green); font-weight: 600; }

/* Content cards */
.mb-row { display: grid; grid-template-columns: 1.6fr 1fr; gap: 14px; margin-bottom: 14px; }
.mb-row-3 { grid-template-columns: repeat(3, 1fr); margin-bottom: 0; }
.mb-card { padding: 16px 18px; }
.mb-card-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 12px; }
.mb-card-head h4 { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; margin: 0; color: var(--d-ink); }
.mb-card-head h4 i { color: var(--d-blue); }
.mb-card-head > span { font-size: 11.5px; color: var(--d-muted); }
.mb-live {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #dc2626 !important;
    font-size: 10px !important;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
}
.mb-live::before {
    content: '';
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #ef4444;
    animation: mbBlink 1.2s infinite;
}
@keyframes mbBlink { 50% { opacity: .25; } }

/* Revenue chart */
.mb-chart { width: 100%; height: auto; display: block; overflow: visible; }
.mb-chart-grid line { stroke: var(--d-soft); stroke-width: 1; }
.mb-chart-grid text { fill: #94a3b8; font-size: 10px; }
.mb-chart-area { opacity: 0; transition: opacity 1s ease 1.2s; }
.mb-chart-line {
    fill: none;
    stroke: var(--d-blue);
    stroke-width: 3;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: var(--len, 2000);
    stroke-dashoffset: var(--len, 2000);
    transition: stroke-dashoffset 1.8s cubic-bezier(.65,0,.35,1) .5s;
}
.mb-chart-dot { fill: #fff; stroke: var(--d-blue); stroke-width: 3; opacity: 0; transition: opacity .4s 2.2s; }
.mb-chart-halo { fill: var(--d-sky); opacity: 0; transform-box: fill-box; transform-origin: center; }
.mb-dash.is-in .mb-chart-line { stroke-dashoffset: 0; }
.mb-dash.is-in .mb-chart-area,
.mb-dash.is-in .mb-chart-dot { opacity: 1; }
.mb-dash.is-in .mb-chart-halo { animation: mbHalo 2s ease-out 2.4s infinite; }
@keyframes mbHalo {
    0%   { opacity: .5; transform: scale(1); }
    100% { opacity: 0;  transform: scale(3.2); }
}
.mb-chart-x { display: flex; justify-content: space-between; font-size: 10.5px; color: #94a3b8; margin-top: 6px; }

/* Payments feed */
.mb-feed { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.mb-feed li {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border-radius: 10px;
    background: #f8fafc;
    border: 1px solid var(--d-soft);
    transition: background .8s, border-color .8s;
}
.mb-feed li.is-new {
    background: #f0fdf4;
    border-color: #bbf7d0;
    animation: mbFeedIn .6s var(--d-ease);
}
@keyframes mbFeedIn {
    from { opacity: 0; transform: translateY(-12px) scale(.98); }
    to   { opacity: 1; transform: none; }
}
.mb-feed-ico {
    width: 32px;
    height: 32px;
    border-radius: 9px;
    background: #dcfce7;
    color: var(--d-green);
    display: grid;
    place-items: center;
    font-size: 13px;
    flex-shrink: 0;
}
.mb-feed-txt { flex: 1; min-width: 0; }
.mb-feed-txt strong { display: block; font-size: 12.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mb-feed-txt span { display: block; font-size: 11px; color: var(--d-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mb-feed-amt { font-size: 13px; font-weight: 800; color: var(--d-green); white-space: nowrap; font-variant-numeric: tabular-nums; }

/* Data usage ring */
.mb-ring-wrap { position: relative; width: 150px; margin: 4px auto 10px; }
.mb-ring { width: 100%; display: block; transform: rotate(-90deg); }
.mb-ring circle { fill: none; stroke-width: 12; }
.mb-ring-bg { stroke: var(--d-soft); }
.mb-ring-fg {
    stroke: var(--d-blue);
    stroke-linecap: round;
    stroke-dasharray: 314.16;
    stroke-dashoffset: 314.16;
    transition: stroke-dashoffset 1.8s cubic-bezier(.65,0,.35,1) .6s;
}
.mb-dash.is-in .mb-ring-fg { stroke-dashoffset: calc(314.16 * (1 - var(--p))); }
.mb-ring-label { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; }
.mb-ring-label b { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; }
.mb-ring-label small { font-size: 10.5px; color: var(--d-muted); }
.mb-ring-legend { display: flex; justify-content: center; gap: 16px; font-size: 11.5px; color: var(--d-muted); }
.mb-ring-legend i { color: var(--d-blue); margin-right: 3px; }
.mb-ring-legend span + span i { color: var(--d-sky); }

/* Customers by plan */
.mb-plans { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
.mb-plans li { display: grid; grid-template-columns: 1fr auto; gap: 5px 10px; font-size: 12px; color: #334155; }
.mb-plans b { font-variant-numeric: tabular-nums; }
.mb-hbar { grid-column: 1 / -1; height: 7px; border-radius: 6px; background: var(--d-soft); overflow: hidden; }
.mb-hbar i {
    display: block;
    height: 100%;
    width: var(--w);
    border-radius: 6px;
    background: linear-gradient(90deg, var(--d-sky), var(--d-blue));
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 1.2s var(--d-ease);
}
.mb-dash.is-in .mb-hbar i { transform: scaleX(1); }
.mb-plans li:nth-child(1) .mb-hbar i { transition-delay: .9s; }
.mb-plans li:nth-child(2) .mb-hbar i { transition-delay: 1s; }
.mb-plans li:nth-child(3) .mb-hbar i { transition-delay: 1.1s; }
.mb-plans li:nth-child(4) .mb-hbar i { transition-delay: 1.2s; }
.mb-plans li:nth-child(5) .mb-hbar i { transition-delay: 1.3s; }

/* At a glance */
.mb-glance { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.mb-glance li { display: flex; align-items: center; gap: 12px; padding: 10px; border-radius: 12px; border: 1px solid var(--d-soft); }
.mb-glance-ico {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    background: var(--bg);
    color: var(--fg);
    font-size: 14px;
    flex-shrink: 0;
}
.mb-glance small {
    display: block;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .8px;
    text-transform: uppercase;
    color: var(--d-muted);
}
.mb-glance strong { font-size: 15px; font-weight: 800; font-variant-numeric: tabular-nums; }

/* Highlights under the frame */
.mb-dash-points { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 44px; }
.mb-point { display: flex; gap: 14px; align-items: flex-start; }
.mb-point > i {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--d-tint);
    color: var(--d-blue);
    display: grid;
    place-items: center;
    font-size: 17px;
    flex-shrink: 0;
}
.mb-point h3 { font-family: var(--fh); font-size: 15px; font-weight: 800; color: var(--d-navy); margin: 2px 0 4px; }
.mb-point p { font-size: 13.5px; color: var(--d-muted); line-height: 1.6; margin: 0; }

@media (max-width: 1199px) {
    .mb-app-body { grid-template-columns: 1fr; }
    .mb-app-side { display: none; }
}
@media (max-width: 991px) {
    .mb-dash { padding: 64px 0 72px; }
    .mb-stats { grid-template-columns: repeat(2, 1fr); }
    .mb-row, .mb-row-3 { grid-template-columns: 1fr; }
    .mb-dash-points { grid-template-columns: 1fr; gap: 18px; }
}
@media (max-width: 576px) {
    .mb-app-main { padding: 14px; }
    .mb-app-url { display: none; }
    .mb-app-bar { justify-content: space-between; }
    .mb-stats { gap: 10px; }
    .mb-stat { padding: 12px; }
    .mb-stat-val { font-size: 17px; }
    .mb-stat-top { font-size: 9.5px; }
    .mb-stat-top i { width: 28px; height: 28px; font-size: 12px; }
}
@media (prefers-reduced-motion: reduce) {
    .mb-dash *, .mb-dash *::before { transition: none !important; animation: none !important; }
}

/* ═══════════════════════════════════════════════════
   3. PRICING — flip cards (hover on desktop, tap on touch)
═══════════════════════════════════════════════════ */
.mb-price {
    --p-navy:  #071d3a;
    --p-navy2: #0c3b6e;
    --p-blue:  var(--bl-primary, #0284c7);
    --p-sky:   var(--bl-accent, #38bdf8);
    --p-tint:  var(--bl-tint, #e0f2fe);
    --p-muted: #64748b;
    --p-line:  #e2e8f0;
    --p-ease:  cubic-bezier(.16,1,.3,1);
    padding: 88px 0 96px;
    background: #fff;
    scroll-margin-top: 70px;   /* clear the sticky nav when jumped to via #pricing */
}
.mb-price-head { max-width: 620px; margin: 0 0 40px; }
.mb-price-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--p-tint);
    color: var(--p-blue);
    font-family: var(--fh);
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    padding: 6px 16px;
    border-radius: 30px;
}
.mb-price-title {
    font-family: var(--fh);
    font-size: clamp(1.5rem, 2.4vw, 2rem);
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -.02em;
    color: var(--p-navy);
    margin: 16px 0 12px;
}
.mb-price-title em { font-style: normal; color: var(--p-blue); }
.mb-price-sub { color: var(--p-muted); font-size: 15px; line-height: 1.7; margin: 0; }

/* Grid + reveal */
.mb-price-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
.mb-flip {
    perspective: 1200px;
    height: 360px;
    cursor: pointer;
    outline: none;
    opacity: 0;
    transform: translateY(28px);
    transition: opacity .6s ease, transform .8s var(--p-ease);
}
.mb-price.is-in .mb-flip { opacity: 1; transform: none; }
.mb-price.is-in .mb-flip:nth-child(2) { transition-delay: .1s; }
.mb-price.is-in .mb-flip:nth-child(3) { transition-delay: .2s; }
.mb-price.is-in .mb-flip:nth-child(4) { transition-delay: .3s; }

.mb-flip-inner {
    position: relative;
    width: 100%;
    height: 100%;
    transform-style: preserve-3d;
    transition: transform .8s var(--p-ease);
}
.mb-flip.is-flipped .mb-flip-inner { transform: rotateY(180deg); }
@media (hover: hover) {
    .mb-flip:hover .mb-flip-inner { transform: rotateY(180deg); }
}
/* one-time hint that the cards turn over */
.mb-flip.is-peek .mb-flip-inner { animation: mbPeek 1.1s var(--p-ease); }
@keyframes mbPeek {
    40% { transform: rotateY(-24deg); }
    100% { transform: rotateY(0); }
}
.mb-flip:focus-visible .mb-flip-face { box-shadow: 0 0 0 3px var(--p-sky), 0 18px 40px -18px rgba(7,29,58,.35); }

.mb-flip-face {
    position: absolute;
    inset: 0;
    -webkit-backface-visibility: hidden;
            backface-visibility: hidden;
    border-radius: 20px;
    padding: 26px 24px 22px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* Front */
.mb-flip-front {
    background: #fff;
    border: 1px solid var(--p-line);
    box-shadow: 0 18px 40px -22px rgba(7,29,58,.3);
}
.mb-flip-front::after {                    /* soft corner glow */
    content: '';
    position: absolute;
    right: -60px;
    top: -60px;
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(56,189,248,.18), transparent 70%);
}
.mb-flip-ico {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    background: var(--p-tint);
    color: var(--p-blue);
    font-size: 19px;
    margin-bottom: 22px;
}
.mb-flip-label {
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.6px;
    text-transform: uppercase;
    color: var(--p-muted);
    margin-bottom: 6px;
}
.mb-flip-big {
    font-family: var(--fh);
    font-size: 38px;
    font-weight: 900;
    line-height: 1.05;
    letter-spacing: -.02em;
    color: var(--p-navy);
}
.mb-flip-big small { font-size: 18px; font-weight: 800; color: var(--p-blue); margin-right: 4px; vertical-align: 12px; }
.mb-flip-unit { font-size: 13.5px; font-weight: 700; color: var(--p-blue); margin-top: 6px; }
.mb-flip-cap { font-size: 13.5px; line-height: 1.6; color: var(--p-muted); margin: 16px 0 0; }
.mb-flip-hint {
    margin-top: auto;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--p-blue);
}
.mb-flip-hint i { transition: transform .5s var(--p-ease); }
.mb-flip:hover .mb-flip-hint i { transform: rotate(180deg); }

/* Featured (minimum) card front */
.mb-flip.is-featured .mb-flip-front {
    background: linear-gradient(150deg, var(--p-navy) 0%, var(--p-navy2) 100%);
    border-color: transparent;
    color: #fff;
}
.mb-flip.is-featured .mb-flip-ico { background: rgba(56,189,248,.16); color: var(--p-sky); }
.mb-flip.is-featured .mb-flip-label { color: rgba(255,255,255,.6); }
.mb-flip.is-featured .mb-flip-big { color: #fff; }
.mb-flip.is-featured .mb-flip-big small,
.mb-flip.is-featured .mb-flip-unit,
.mb-flip.is-featured .mb-flip-hint { color: var(--p-sky); }
.mb-flip.is-featured .mb-flip-cap { color: rgba(255,255,255,.72); }
.mb-flip-badge {
    position: absolute;
    top: 22px;
    right: 20px;
    z-index: 1;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--p-navy);
    background: var(--p-sky);
    padding: 4px 10px;
    border-radius: 20px;
}

/* Back */
.mb-flip-back {
    transform: rotateY(180deg);
    background:
        radial-gradient(260px 200px at 100% 0%, rgba(56,189,248,.3), transparent 70%),
        linear-gradient(150deg, var(--p-blue) 0%, var(--p-navy2) 100%);
    color: #fff;
}
.mb-flip-back h4 { font-family: var(--fh); font-size: 17px; font-weight: 800; color: #fff; margin: 0 0 14px; }
.mb-flip-back ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
.mb-flip-back li { display: flex; gap: 10px; font-size: 13px; line-height: 1.5; color: rgba(255,255,255,.88); }
.mb-flip-back li i { color: var(--p-sky); font-size: 12px; margin-top: 4px; flex-shrink: 0; }
.mb-flip-eg {
    margin-top: auto;
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.16);
    border-radius: 12px;
    padding: 11px 13px;
    font-size: 12.5px;
    line-height: 1.55;
    color: rgba(255,255,255,.9);
}
.mb-flip-eg b { color: #fff; }
.mb-flip-eg small {
    display: block;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--p-sky);
    margin-bottom: 3px;
}

/* Formula + CTA */
.mb-price-foot {
    margin-top: 36px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    background: #f8fafc;
    border: 1px solid var(--p-line);
    border-radius: 18px;
    padding: 20px 24px;
}
.mb-formula { font-size: 14px; color: #334155; line-height: 1.7; }
.mb-formula small {
    display: block;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 1.4px;
    text-transform: uppercase;
    color: var(--p-muted);
}
.mb-formula b { color: var(--p-navy); }
.mb-formula .mb-op { color: var(--p-blue); font-weight: 800; margin: 0 4px; }
.mb-price-cta { display: flex; gap: 10px; flex-wrap: wrap; }
.mb-price-cta a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 50px;
    font-family: var(--fh);
    font-size: 13.5px;
    font-weight: 800;
    text-decoration: none;
    transition: background .2s, color .2s, transform .2s;
}
.mb-price-cta .is-main { background: var(--p-blue); color: #fff; }
.mb-price-cta .is-main:hover { background: #0369a1; color: #fff; transform: translateY(-2px); }
.mb-price-cta .is-ghost { border: 1.5px solid var(--p-blue); color: var(--p-blue); }
.mb-price-cta .is-ghost:hover { background: var(--p-tint); color: var(--p-blue); }

@media (max-width: 1199px) {
    .mb-price-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 991px) {
    .mb-price { padding: 64px 0 72px; }
}
@media (max-width: 576px) {
    .mb-price-grid { grid-template-columns: 1fr; }
    .mb-flip { height: 290px; }
    .mb-price-foot { padding: 18px; }
    .mb-price-cta, .mb-price-cta a { width: 100%; justify-content: center; }
}
@media (prefers-reduced-motion: reduce) {
    .mb-flip, .mb-flip-inner { transition: none !important; animation: none !important; }
}

/* ═══════════════════════════════════════════════════
   2B. FEATURES — 3 illustrated cards + icon grid (dark)
═══════════════════════════════════════════════════ */
.mb-feat {
    --f-navy:  #071d3a;
    --f-navy2: #0c3b6e;
    --f-blue:  var(--bl-primary, #0284c7);
    --f-sky:   var(--bl-accent, #38bdf8);
    --f-amber: #f5b71a;
    --f-green: #22c55e;
    --f-card:  rgba(255,255,255,.045);
    --f-line:  rgba(255,255,255,.1);
    --f-text:  rgba(255,255,255,.68);
    --f-ease:  cubic-bezier(.16,1,.3,1);
    position: relative;
    overflow: hidden;
    padding: 88px 0 96px;
    color: #fff;
    scroll-margin-top: 70px;
    background:
        radial-gradient(700px 420px at 100% 0%, rgba(56,189,248,.18), transparent 65%),
        radial-gradient(600px 420px at 0% 100%, rgba(2,132,199,.25), transparent 65%),
        linear-gradient(160deg, var(--f-navy) 0%, var(--f-navy2) 100%);
}
.mb-feat::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
    background-size: 44px 44px;
    -webkit-mask-image: radial-gradient(ellipse at 20% 0%, #000 10%, transparent 60%);
            mask-image: radial-gradient(ellipse at 20% 0%, #000 10%, transparent 60%);
    pointer-events: none;
}
.mb-feat .container { position: relative; }

.mb-feat-head { max-width: 620px; margin: 0 0 40px; }
.mb-feat-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(56,189,248,.14);
    border: 1px solid rgba(56,189,248,.35);
    color: #7dd3fc;
    font-family: var(--fh);
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    padding: 6px 16px;
    border-radius: 30px;
}
.mb-feat-title {
    font-family: var(--fh);
    font-size: clamp(1.5rem, 2.4vw, 2rem);
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -.02em;
    color: #fff;
    margin: 16px 0 12px;
}
.mb-feat-title em {
    font-style: normal;
    background: linear-gradient(90deg, #7dd3fc, var(--f-sky));
    -webkit-background-clip: text;
            background-clip: text;
    color: transparent;
}
.mb-feat-sub { color: var(--f-text); font-size: 15px; line-height: 1.7; margin: 0; }

/* Shared card + reveal */
.mb-fcard {
    background: var(--f-card);
    border: 1px solid var(--f-line);
    border-radius: 20px;
    position: relative;
    overflow: hidden;
    opacity: 0;
    transform: translateY(24px);
    transition: opacity .6s ease, transform .8s var(--f-ease), border-color .3s, background .3s;
}
.mb-feat.is-in .mb-fcard { opacity: 1; transform: none; transition-delay: calc(var(--i, 0) * 70ms); }
.mb-fcard:hover { border-color: rgba(56,189,248,.45); background: rgba(255,255,255,.07); }
.mb-fcard h3 { font-family: var(--fh); font-size: 16px; font-weight: 800; color: #fff; margin: 0 0 6px; }
.mb-fcard p { font-size: 13.5px; line-height: 1.65; color: var(--f-text); margin: 0; }

/* Big illustrated cards */
.mb-feat-big { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
.mb-fvis {
    position: relative;
    height: 200px;
    display: grid;
    place-items: center;
    border-bottom: 1px solid var(--f-line);
    background:
        radial-gradient(240px 140px at 50% 100%, rgba(56,189,248,.16), transparent 70%),
        rgba(255,255,255,.02);
}
.mb-fbody { padding: 22px 24px 24px; }
.mb-ftags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 14px; }
.mb-ftags span {
    font-size: 11px;
    font-weight: 700;
    color: #7dd3fc;
    background: rgba(56,189,248,.1);
    border: 1px solid rgba(56,189,248,.22);
    padding: 3px 10px;
    border-radius: 20px;
}

/* Visual 1: voucher stack */
.mb-vch { position: relative; width: 250px; height: 132px; }
.mb-vch-card {
    position: absolute;
    inset: 0;
    border-radius: 14px;
    background: linear-gradient(135deg, #0ea5e9, var(--f-blue));
    box-shadow: 0 18px 40px rgba(0,0,0,.35);
    transition: transform 1s var(--f-ease) .3s;
}
.mb-vch-card.is-back { background: #0c4a6e; opacity: .8; }
.mb-vch-card.is-mid  { background: #0369a1; opacity: .9; }
.mb-feat.is-in .mb-vch-card.is-back { transform: rotate(-11deg) translate(-22px, 6px); }
.mb-feat.is-in .mb-vch-card.is-mid  { transform: rotate(-5deg) translate(-10px, 2px); }
.mb-vch-card.is-front { padding: 14px 16px; display: flex; flex-direction: column; color: #fff; }
.mb-vch-card.is-front::after {                /* shine sweep */
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: linear-gradient(110deg, transparent 35%, rgba(255,255,255,.28) 50%, transparent 65%);
    background-size: 250% 100%;
    background-position: 120% 0;
}
.mb-feat.is-in .mb-vch-card.is-front::after { animation: mbShine 3.5s ease-in-out 1.2s infinite; }
@keyframes mbShine { 0% { background-position: 120% 0; } 45%, 100% { background-position: -40% 0; } }
.mb-vch-top { display: flex; justify-content: space-between; align-items: center; font-size: 10px; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; color: rgba(255,255,255,.75); }
.mb-vch-top b { font-size: 13px; letter-spacing: 0; color: #fff; background: rgba(7,29,58,.35); padding: 2px 8px; border-radius: 6px; }
.mb-vch-code {
    font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace;
    font-size: 21px;
    font-weight: 800;
    letter-spacing: 2px;
    margin: auto 0;
    padding: 6px 0;
    border-top: 1.5px dashed rgba(255,255,255,.35);
    border-bottom: 1.5px dashed rgba(255,255,255,.35);
    text-align: center;
}
.mb-vch-meta { display: flex; justify-content: space-between; font-size: 10.5px; font-weight: 600; color: rgba(255,255,255,.85); }
.mb-vch-meta i { margin-right: 4px; opacity: .8; }

/* Visual 2: FUP — fill to cap, then throttle */
.mb-fup { width: 260px; }
.mb-fup-speed {
    position: relative;
    height: 54px;
    margin-bottom: 16px;
    text-align: center;
}
.mb-fup-speed span {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: baseline;
    justify-content: center;
    gap: 6px;
    font-family: var(--fh);
    font-size: 40px;
    font-weight: 900;
    line-height: 54px;
}
.mb-fup-speed small { font-size: 14px; font-weight: 700; color: var(--f-text); }
.mb-fup-fast { color: #fff; }
.mb-fup-slow { color: var(--f-amber); opacity: 0; }
.mb-fup-bar { position: relative; height: 10px; border-radius: 6px; background: rgba(255,255,255,.1); overflow: visible; }
.mb-fup-fill { height: 100%; width: 0; border-radius: 6px; background: var(--f-sky); }
.mb-fup-cap {
    position: absolute;
    right: 0;
    top: -5px;
    bottom: -5px;
    width: 2px;
    background: var(--f-amber);
    border-radius: 2px;
}
.mb-fup-legend { display: flex; justify-content: space-between; font-size: 11px; color: var(--f-text); margin-top: 10px; }
.mb-fup-legend b { color: var(--f-amber); font-weight: 700; }
.mb-feat.is-in .mb-fup-fill { animation: mbFupFill 6s ease-in-out .6s infinite; }
.mb-feat.is-in .mb-fup-fast { animation: mbFupFast 6s ease-in-out .6s infinite; }
.mb-feat.is-in .mb-fup-slow { animation: mbFupSlow 6s ease-in-out .6s infinite; }
@keyframes mbFupFill {
    0%       { width: 0;    background: var(--f-sky); }
    52%      { width: 100%; background: var(--f-sky); }
    58%, 90% { width: 100%; background: var(--f-amber); }
    100%     { width: 0;    background: var(--f-sky); }
}
@keyframes mbFupFast { 0%, 52% { opacity: 1; transform: none; } 58%, 92% { opacity: 0; transform: translateY(-8px); } 100% { opacity: 1; transform: none; } }
@keyframes mbFupSlow { 0%, 52% { opacity: 0; transform: translateY(8px); } 58%, 92% { opacity: 1; transform: none; } 100% { opacity: 0; transform: translateY(8px); } }

/* Visual 3: wallet */
.mb-wal { width: 260px; }
.mb-wal-bal {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, rgba(56,189,248,.22), rgba(2,132,199,.12));
    border: 1px solid rgba(56,189,248,.3);
    border-radius: 14px;
    padding: 12px 14px;
    margin-bottom: 10px;
}
.mb-wal-bal small { display: block; font-size: 10px; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; color: var(--f-text); }
.mb-wal-bal b { font-family: var(--fh); font-size: 22px; font-weight: 900; }
.mb-wal-auto { display: inline-flex; align-items: center; gap: 6px; font-size: 10.5px; font-weight: 700; color: #86efac; }
.mb-wal-auto i {                              /* toggle switch */
    width: 26px;
    height: 15px;
    border-radius: 10px;
    background: var(--f-green);
    position: relative;
}
.mb-wal-auto i::after { content: ''; position: absolute; top: 2px; right: 2px; width: 11px; height: 11px; border-radius: 50%; background: #fff; }
.mb-wal ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.mb-wal li {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 11.5px;
    color: rgba(255,255,255,.85);
    background: rgba(255,255,255,.05);
    border: 1px solid var(--f-line);
    border-radius: 10px;
    padding: 7px 10px;
    opacity: 0;
    transform: translateX(-12px);
    transition: opacity .5s ease, transform .6s var(--f-ease);
}
.mb-feat.is-in .mb-wal li { opacity: 1; transform: none; }
.mb-feat.is-in .mb-wal li:nth-child(1) { transition-delay: .7s; }
.mb-feat.is-in .mb-wal li:nth-child(2) { transition-delay: 1s; }
.mb-wal li i { width: 22px; height: 22px; border-radius: 6px; display: grid; place-items: center; font-size: 10px; flex-shrink: 0; }
.mb-wal li .is-plus  { background: rgba(34,197,94,.18); color: #86efac; }
.mb-wal li .is-minus { background: rgba(56,189,248,.18); color: #7dd3fc; }
.mb-wal li b { margin-left: auto; font-variant-numeric: tabular-nums; }
.mb-wal li b.is-plus { color: #86efac; background: none; }

/* Icon grid */
.mb-feat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
.mb-feat-grid .mb-fcard { padding: 22px 22px 24px; }
.mb-fico {
    width: 46px;
    height: 46px;
    border-radius: 13px;
    display: grid;
    place-items: center;
    font-size: 18px;
    color: #fff;
    background: linear-gradient(135deg, var(--f-sky), var(--f-blue));
    box-shadow: 0 10px 24px -8px rgba(56,189,248,.55);
    margin-bottom: 16px;
    transition: transform .4s var(--f-ease);
}
.mb-fcard:hover .mb-fico { transform: translateY(-3px) rotate(-6deg); }
.mb-fico.is-green { background: linear-gradient(135deg, #4ade80, #16a34a); box-shadow: 0 10px 24px -8px rgba(34,197,94,.5); }
.mb-fico.is-amber { background: linear-gradient(135deg, #fcd34d, #f59e0b); box-shadow: 0 10px 24px -8px rgba(245,158,11,.5); }

@media (max-width: 1199px) {
    .mb-feat-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 991px) {
    .mb-feat { padding: 64px 0 72px; }
    .mb-feat-big { grid-template-columns: 1fr; }
    .mb-fvis { height: 190px; }
}
@media (max-width: 576px) {
    .mb-feat-grid { grid-template-columns: 1fr; gap: 12px; }
    .mb-feat-grid .mb-fcard {             /* compact: icon beside text */
        display: grid;
        grid-template-columns: 46px 1fr;
        column-gap: 14px;
        padding: 16px 18px;
    }
    .mb-feat-grid .mb-fico { grid-row: span 2; margin-bottom: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .mb-feat *, .mb-feat *::after { transition: none !important; animation: none !important; }
    .mb-fup-fill { width: 70%; }
}

/* ═══════════════════════════════════════════════════
   2C. EARN · CONNECT · GROW — animated 2D drawings
═══════════════════════════════════════════════════ */
.mb-earn {
    --e-navy:  #071d3a;
    --e-navy2: #0c3b6e;
    --e-blue:  var(--bl-primary, #0284c7);
    --e-sky:   var(--bl-accent, #38bdf8);
    --e-tint:  var(--bl-tint, #e0f2fe);
    --e-muted: #64748b;
    --e-line:  #e2e8f0;
    --e-ease:  cubic-bezier(.16,1,.3,1);
    padding: 88px 0 96px;
    background: linear-gradient(180deg, #f0f9ff 0%, #fff 100%);
}
.mb-earn-head { max-width: 620px; margin: 0 0 40px; }
.mb-earn-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--e-tint);
    color: var(--e-blue);
    font-family: var(--fh);
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    padding: 6px 16px;
    border-radius: 30px;
}
.mb-earn-title {
    font-family: var(--fh);
    font-size: clamp(1.5rem, 2.4vw, 2rem);
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -.02em;
    color: var(--e-navy);
    margin: 16px 0 12px;
}
.mb-earn-title em { font-style: normal; color: var(--e-blue); }
.mb-earn-sub { color: var(--e-muted); font-size: 15px; line-height: 1.7; margin: 0; }

.mb-earn-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
.mb-ecard {
    background: #fff;
    border: 1px solid var(--e-line);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 18px 40px -26px rgba(7,29,58,.35);
    opacity: 0;
    transform: translateY(24px);
    transition: opacity .6s ease, transform .8s var(--e-ease), box-shadow .3s;
}
.mb-earn.is-in .mb-ecard { opacity: 1; transform: none; }
.mb-earn.is-in .mb-ecard:nth-child(2) { transition-delay: .12s; }
.mb-earn.is-in .mb-ecard:nth-child(3) { transition-delay: .24s; }
.mb-ecard:hover { box-shadow: 0 26px 50px -24px rgba(2,132,199,.4); }

.mb-evis {
    position: relative;
    background:
        radial-gradient(260px 160px at 50% 100%, rgba(56,189,248,.18), transparent 70%),
        linear-gradient(180deg, #f8fbff, #eef7fe);
    border-bottom: 1px solid var(--e-line);
}
.mb-evis svg { display: block; width: 100%; height: auto; }
.mb-estep {
    position: absolute;
    top: 14px;
    left: 16px;
    font-family: var(--fh);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1px;
    color: var(--e-blue);
    background: #fff;
    border: 1px solid var(--e-line);
    padding: 3px 10px;
    border-radius: 20px;
}
.mb-ebody { padding: 22px 24px 24px; }
.mb-ebody h3 { font-family: var(--fh); font-size: 18px; font-weight: 800; color: var(--e-navy); margin: 0 0 8px; }
.mb-ebody p { font-size: 13.5px; line-height: 1.65; color: var(--e-muted); margin: 0; }
.mb-ebody ul { list-style: none; margin: 14px 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.mb-ebody li { font-size: 13px; color: #334155; display: flex; gap: 8px; }
.mb-ebody li i { color: var(--e-blue); font-size: 12px; margin-top: 3px; }

/* ── 1. Earn: coins (driven by JS) ── */
.mb-coin { opacity: 0; }
.mb-coin-plus { font: 800 14px var(--fh); fill: #16a34a; opacity: 0; }
.mb-phone-amt { font: 800 15px var(--fh); fill: #fff; }
.mb-phone-sm  { font: 700 7.5px system-ui, sans-serif; letter-spacing: 1px; fill: #7dd3fc; }
.mb-phone-lbl { font: 600 8px system-ui, sans-serif; fill: rgba(255,255,255,.65); }

/* ── 2. Connect: cable → plug → link (6s loop) ── */
.mb-cable,
.mb-flow { fill: none; stroke-linecap: round; }
.mb-cable { stroke: #0c3b6e; stroke-width: 5; stroke-dasharray: 100; stroke-dashoffset: 100; }
.mb-flow  { stroke: var(--e-sky); stroke-width: 2.5; stroke-dasharray: 3 9; opacity: 0; }
.mb-plug  { transform: translateX(-30px); opacity: 0; }
.mb-led-main { fill: #475569; }
.mb-win { fill: #94a3b8; }
.mb-conn-badge { opacity: 0; transform-box: fill-box; transform-origin: center; }
.mb-led-sm { fill: #22c55e; }
.mb-led-sm.is-b { animation: mbBlinkSm 1.1s steps(2) infinite; }
.mb-led-sm.is-c { animation: mbBlinkSm .8s steps(2) .3s infinite; }
@keyframes mbBlinkSm { 50% { opacity: .2; } }
.mb-earn.is-in .mb-cable      { animation: mbCable 6s infinite; }
.mb-earn.is-in .mb-plug       { animation: mbPlug 6s var(--e-ease) infinite; }
.mb-earn.is-in .mb-led-main   { animation: mbLed 6s infinite; }
.mb-earn.is-in .mb-win        { animation: mbWin 6s infinite; }
.mb-earn.is-in .mb-conn-badge { animation: mbBadge 6s var(--e-ease) infinite; }
.mb-earn.is-in .mb-flow       { animation: mbFlow .9s linear infinite, mbFlowShow 6s infinite; }
@keyframes mbCable { 0% { stroke-dashoffset: 100; opacity: 1; } 25%, 92% { stroke-dashoffset: 0; opacity: 1; } 97% { stroke-dashoffset: 0; opacity: 0; } 100% { stroke-dashoffset: 100; opacity: 0; } }
@keyframes mbPlug  { 0%, 24% { transform: translateX(-30px); opacity: 0; } 28% { transform: translateX(-30px); opacity: 1; } 36%, 92% { transform: none; opacity: 1; } 97%, 100% { transform: none; opacity: 0; } }
@keyframes mbLed   { 0%, 37% { fill: #475569; } 39%, 92% { fill: #22c55e; } 100% { fill: #475569; } }
@keyframes mbWin   { 0%, 40% { fill: #94a3b8; } 44%, 92% { fill: #fbbf24; } 100% { fill: #94a3b8; } }
@keyframes mbBadge { 0%, 40% { opacity: 0; transform: translateY(6px) scale(.85); } 46%, 90% { opacity: 1; transform: none; } 96%, 100% { opacity: 0; transform: none; } }
@keyframes mbFlow  { to { stroke-dashoffset: -12; } }
@keyframes mbFlowShow { 0%, 40% { opacity: 0; } 46%, 90% { opacity: 1; } 95%, 100% { opacity: 0; } }

/* ── 3. Grow: bars + trend arrow (6s loop) ── */
.mb-gbar { transform-box: fill-box; transform-origin: 50% 100%; transform: scaleY(0); }
.mb-gline { fill: none; stroke: #16a34a; stroke-width: 3.5; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 100; stroke-dashoffset: 100; }
.mb-ghead, .mb-gbadge { opacity: 0; transform-box: fill-box; transform-origin: center; }
.mb-gdot { fill: #fff; stroke: #16a34a; stroke-width: 2.5; opacity: 0; }
.mb-earn.is-in .mb-gbar { animation: mbBar 6s var(--e-ease) infinite; animation-delay: calc(var(--i) * .15s); }
.mb-earn.is-in .mb-gline { animation: mbGLine 6s infinite; }
.mb-earn.is-in .mb-gdot { animation: mbGDot 6s infinite; }
.mb-earn.is-in .mb-ghead { animation: mbGHead 6s infinite; }
.mb-earn.is-in .mb-gbadge { animation: mbBadge 6s var(--e-ease) infinite; }
@keyframes mbBar   { 0% { transform: scaleY(0); } 18%, 90% { transform: scaleY(1); } 98%, 100% { transform: scaleY(0); } }
@keyframes mbGLine { 0%, 22% { stroke-dashoffset: 100; opacity: 1; } 42%, 90% { stroke-dashoffset: 0; opacity: 1; } 96%, 100% { stroke-dashoffset: 0; opacity: 0; } }
@keyframes mbGDot  { 0%, 40% { opacity: 0; } 44%, 90% { opacity: 1; } 96%, 100% { opacity: 0; } }
@keyframes mbGHead { 0%, 40% { opacity: 0; transform: scale(.4); } 45%, 90% { opacity: 1; transform: none; } 96%, 100% { opacity: 0; } }

@media (max-width: 991px) {
    .mb-earn { padding: 64px 0 72px; }
    .mb-earn-grid { grid-template-columns: 1fr; max-width: 520px; }
}
/* Reduced motion: show every drawing in its finished state */
@media (prefers-reduced-motion: reduce) {
    .mb-earn *, .mb-ecard { animation: none !important; transition: none !important; }
    .mb-ecard { opacity: 1; transform: none; }
    .mb-coin { opacity: 1; }
    .mb-cable, .mb-gline { stroke-dashoffset: 0; }
    .mb-plug, .mb-gbar { transform: none; opacity: 1; }
    .mb-led-main { fill: #22c55e; }
    .mb-win { fill: #fbbf24; }
    .mb-conn-badge, .mb-ghead, .mb-gbadge, .mb-gdot { opacity: 1; }
}

/* ═══════════════════════════════════════════════════
   3B. JOIN CTA — "Join and start selling" panel
═══════════════════════════════════════════════════ */
.mb-cta {
    --c-navy:  #071d3a;
    --c-navy2: #0c3b6e;
    --c-blue:  var(--bl-primary, #0284c7);
    --c-sky:   var(--bl-accent, #38bdf8);
    --c-text:  rgba(255,255,255,.72);
    --c-ease:  cubic-bezier(.16,1,.3,1);
    padding: 24px 0 96px;
    background: #fff;
    scroll-margin-top: 70px;
}
.mb-cta-panel {
    position: relative;
    overflow: hidden;
    isolation: isolate;
    display: grid;
    grid-template-columns: 1.15fr 1fr;
    gap: 48px;
    align-items: center;
    padding: 56px;
    border-radius: 28px;
    color: #fff;
    background: linear-gradient(135deg, var(--c-navy) 0%, var(--c-navy2) 100%);
    box-shadow: 0 40px 80px -40px rgba(7,29,58,.6);
}
.mb-cta-panel::before {                       /* faint grid */
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    background-image:
        linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px);
    background-size: 40px 40px;
    -webkit-mask-image: radial-gradient(ellipse at 80% 50%, #000 10%, transparent 65%);
            mask-image: radial-gradient(ellipse at 80% 50%, #000 10%, transparent 65%);
}
.mb-cta-glow {                                 /* two drifting light blobs */
    position: absolute;
    inset: 0;
    z-index: -1;
    pointer-events: none;
}
.mb-cta-glow::before,
.mb-cta-glow::after {
    content: '';
    position: absolute;
    width: 420px;
    height: 420px;
    border-radius: 50%;
    filter: blur(60px);
    opacity: .55;
}
.mb-cta-glow::before { background: #0284c7; top: -180px; right: -80px; animation: mbDrift 14s ease-in-out infinite alternate; }
.mb-cta-glow::after  { background: #38bdf8; bottom: -220px; left: 25%; opacity: .3; animation: mbDrift 18s ease-in-out infinite alternate-reverse; }
@keyframes mbDrift {
    0%   { transform: translate(0, 0) scale(1); }
    100% { transform: translate(-60px, 40px) scale(1.15); }
}

.mb-cta-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(56,189,248,.14);
    border: 1px solid rgba(56,189,248,.35);
    color: #7dd3fc;
    font-family: var(--fh);
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    padding: 6px 16px;
    border-radius: 30px;
}
.mb-cta-title {
    font-family: var(--fh);
    font-size: clamp(1.6rem, 2.8vw, 2.4rem);
    font-weight: 800;
    line-height: 1.15;
    letter-spacing: -.02em;
    color: #fff;
    margin: 16px 0 14px;
    text-wrap: balance;
}
.mb-cta-title em {
    font-style: normal;
    background: linear-gradient(90deg, #7dd3fc, var(--c-sky));
    -webkit-background-clip: text;
            background-clip: text;
    color: transparent;
}
.mb-cta-sub { color: var(--c-text); font-size: 15px; line-height: 1.7; margin: 0 0 26px; max-width: 480px; }
.mb-cta-btns { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 26px; }
.mb-cta-main,
.mb-cta-ghost {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 14px 28px;
    border-radius: 50px;
    font-family: var(--fh);
    font-size: 14px;
    font-weight: 800;
    text-decoration: none;
    transition: background .2s, color .2s, border-color .2s, transform .2s, box-shadow .2s;
}
.mb-cta-main {
    position: relative;
    overflow: hidden;
    background: var(--c-sky);
    color: var(--c-navy);
    box-shadow: 0 10px 30px rgba(56,189,248,.4);
}
.mb-cta-main::after {                          /* periodic shine */
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,.55) 50%, transparent 70%);
    transform: translateX(-120%);
    animation: mbBtnShine 4s ease-in-out 1.5s infinite;
}
@keyframes mbBtnShine { 0% { transform: translateX(-120%); } 35%, 100% { transform: translateX(120%); } }
.mb-cta-main:hover { background: #7dd3fc; color: var(--c-navy); transform: translateY(-2px); }
.mb-cta-main i { transition: transform .25s; }
.mb-cta-main:hover i { transform: translateX(4px); }
.mb-cta-ghost { border: 1.5px solid rgba(255,255,255,.3); color: #fff; }
.mb-cta-ghost:hover { border-color: #fff; background: rgba(255,255,255,.08); color: #fff; }
.mb-cta-trust { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 8px 20px; }
.mb-cta-trust li { display: flex; align-items: center; gap: 7px; font-size: 13px; color: var(--c-text); }
.mb-cta-trust i { color: #4ade80; }

/* Steps timeline */
.mb-cta-steps {
    position: relative;
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.mb-cta-steps::before,
.mb-cta-steps::after {                         /* track + fill linking the numbers */
    content: '';
    position: absolute;
    left: 38px;
    top: 40px;
    bottom: 40px;
    width: 2px;
    border-radius: 2px;
    background: rgba(255,255,255,.12);
    z-index: 0;                               /* behind the step cards and number circles */
}
.mb-cta-steps::after {
    background: linear-gradient(180deg, var(--c-sky), #4ade80);
    transform: scaleY(0);
    transform-origin: top;
    transition: transform 1.4s var(--c-ease) .5s;
}
.mb-cta-steps.is-revealed::after { transform: scaleY(1); }
.mb-cta-step {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 18px;
    border-radius: 16px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    backdrop-filter: blur(6px);
    transition: background .3s, border-color .3s;
}
.mb-cta-step:hover { background: rgba(255,255,255,.1); border-color: rgba(56,189,248,.4); }
.mb-cta-num {
    position: relative;
    z-index: 1;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    font-family: var(--fh);
    font-size: 16px;
    font-weight: 900;
    color: var(--c-navy);
    background: var(--c-sky);
    box-shadow: 0 0 0 5px rgba(56,189,248,.18);
}
.mb-cta-step:last-child .mb-cta-num { background: #4ade80; box-shadow: 0 0 0 5px rgba(74,222,128,.2); }
.mb-cta-step strong { display: block; font-family: var(--fh); font-size: 15px; font-weight: 800; color: #fff; }
.mb-cta-step small { display: block; font-size: 13px; color: var(--c-text); margin-top: 2px; }
.mb-cta-live {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #86efac;
    background: rgba(74,222,128,.12);
    border: 1px solid rgba(74,222,128,.3);
    padding: 4px 10px;
    border-radius: 20px;
}
.mb-cta-live::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #4ade80; animation: mbBlink 1.2s infinite; }

@media (max-width: 991px) {
    .mb-cta { padding: 16px 0 72px; }
    .mb-cta-panel { grid-template-columns: 1fr; gap: 36px; padding: 40px 32px; }
}
@media (max-width: 576px) {
    .mb-cta-panel { padding: 32px 20px; border-radius: 22px; }
    .mb-cta-main, .mb-cta-ghost { width: 100%; justify-content: center; }
    .mb-cta-steps::before, .mb-cta-steps::after { left: 37px; }
}
@media (prefers-reduced-motion: reduce) {
    .mb-cta *, .mb-cta *::before, .mb-cta *::after, .mb-cta-steps::after { animation: none !important; transition: none !important; }
    .mb-cta-steps::after { transform: none; }
}

/* ═══════════════════════════════════════════════════
   3C. ABOUT + 3D. CONTACT
═══════════════════════════════════════════════════ */
main > section[id] { scroll-margin-top: 70px; }   /* clear the sticky nav on #jumps */

.mb-about,
.mb-contact {
    --a-navy:  #071d3a;
    --a-navy2: #0c3b6e;
    --a-blue:  var(--bl-primary, #0284c7);
    --a-sky:   var(--bl-accent, #38bdf8);
    --a-tint:  var(--bl-tint, #e0f2fe);
    --a-muted: #64748b;
    --a-line:  #e2e8f0;
    padding: 88px 0 96px;
}
.mb-about { background: linear-gradient(180deg, #f0f9ff 0%, #fff 100%); }
.mb-contact { background: #fff; }

.mb-sec-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--a-tint);
    color: var(--a-blue);
    font-family: var(--fh);
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    padding: 6px 16px;
    border-radius: 30px;
}
.mb-sec-title {
    font-family: var(--fh);
    font-size: clamp(1.5rem, 2.4vw, 2rem);
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -.02em;
    color: var(--a-navy);
    margin: 16px 0 12px;
}
.mb-sec-title em { font-style: normal; color: var(--a-blue); }
.mb-sec-sub { color: var(--a-muted); font-size: 15px; line-height: 1.75; margin: 0; }

/* About */
.mb-about-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 56px; align-items: center; }
.mb-about-copy .mb-sec-sub + .mb-sec-sub { margin-top: 12px; }
.mb-values { list-style: none; margin: 28px 0 0; padding: 0; display: flex; flex-direction: column; gap: 16px; }
.mb-values li { display: flex; gap: 14px; align-items: flex-start; }
.mb-values i {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    background: var(--a-tint);
    color: var(--a-blue);
    font-size: 16px;
}
.mb-values strong { display: block; font-family: var(--fh); font-size: 15px; font-weight: 800; color: var(--a-navy); }
.mb-values span { display: block; font-size: 13.5px; color: var(--a-muted); line-height: 1.6; margin-top: 2px; }

.mb-about-card {
    position: relative;
    overflow: hidden;
    border-radius: 24px;
    padding: 32px;
    color: #fff;
    background:
        radial-gradient(380px 260px at 100% 0%, rgba(56,189,248,.3), transparent 70%),
        linear-gradient(150deg, var(--a-navy) 0%, var(--a-navy2) 100%);
    box-shadow: 0 30px 70px -34px rgba(7,29,58,.6);
}
.mb-about-brand { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
.mb-about-brand img { width: 58px; height: 58px; border-radius: 14px; background: #fff; object-fit: contain; }
.mb-about-brand strong { display: block; font-family: var(--fh); font-size: 19px; font-weight: 800; }
.mb-about-brand span { font-size: 12.5px; color: rgba(255,255,255,.65); }
.mb-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.mb-fact {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 14px;
    padding: 14px;
    transition: background .3s, border-color .3s, transform .3s;
}
.mb-fact:hover { background: rgba(255,255,255,.1); border-color: rgba(56,189,248,.45); transform: translateY(-2px); }
.mb-fact i { color: var(--a-sky); font-size: 16px; margin-bottom: 8px; display: block; }
.mb-fact strong { display: block; font-size: 13.5px; font-weight: 700; }
.mb-fact span { display: block; font-size: 12px; color: rgba(255,255,255,.6); margin-top: 2px; }
.mb-about-link {
    margin-top: 22px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #7dd3fc;
    font-size: 13.5px;
    font-weight: 700;
    text-decoration: none;
}
.mb-about-link:hover { color: #fff; }
.mb-about-link i { transition: transform .25s; }
.mb-about-link:hover i { transform: translateX(4px); }

/* Contact */
.mb-contact-head { max-width: 620px; margin: 0 0 40px; }
.mb-contact-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
.mb-ccard {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 24px 22px;
    border-radius: 18px;
    border: 1px solid var(--a-line);
    background: #fff;
    color: inherit;
    text-decoration: none;
    box-shadow: 0 16px 36px -28px rgba(7,29,58,.4);
    transition: transform .3s, box-shadow .3s, border-color .3s;
}
a.mb-ccard:hover { transform: translateY(-4px); border-color: rgba(2,132,199,.4); box-shadow: 0 24px 44px -26px rgba(2,132,199,.45); }
.mb-ccard i {
    width: 46px;
    height: 46px;
    border-radius: 13px;
    display: grid;
    place-items: center;
    font-size: 18px;
    color: #fff;
    background: linear-gradient(135deg, var(--a-sky), var(--a-blue));
    margin-bottom: 14px;
}
.mb-ccard.is-wa i { background: linear-gradient(135deg, #4ade80, #16a34a); }
.mb-ccard small { font-size: 11px; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; color: var(--a-muted); }
.mb-ccard strong { font-family: var(--fh); font-size: 16px; font-weight: 800; color: var(--a-navy); overflow-wrap: anywhere; }
.mb-ccard span { font-size: 13px; color: var(--a-muted); }

.mb-demo {
    margin-top: 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
    padding: 28px 32px;
    border-radius: 20px;
    background: linear-gradient(135deg, var(--a-tint) 0%, #f0f9ff 100%);
    border: 1px solid #bae6fd;
}
.mb-demo h3 { font-family: var(--fh); font-size: 20px; font-weight: 800; color: var(--a-navy); margin: 0 0 4px; }
.mb-demo p { font-size: 14px; color: var(--a-muted); margin: 0; }
.mb-demo-btns { display: flex; gap: 10px; flex-wrap: wrap; }
.mb-demo-btns a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 22px;
    border-radius: 50px;
    font-family: var(--fh);
    font-size: 13.5px;
    font-weight: 800;
    text-decoration: none;
    transition: background .2s, color .2s, transform .2s;
}
.mb-demo-btns .is-wa { background: #16a34a; color: #fff; }
.mb-demo-btns .is-wa:hover { background: #15803d; color: #fff; transform: translateY(-2px); }
.mb-demo-btns .is-mail { border: 1.5px solid var(--a-blue); color: var(--a-blue); background: #fff; }
.mb-demo-btns .is-mail:hover { background: var(--a-blue); color: #fff; }

@media (max-width: 1199px) {
    .mb-contact-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 991px) {
    .mb-about, .mb-contact { padding: 64px 0 72px; }
    .mb-about-grid { grid-template-columns: 1fr; gap: 36px; }
}
@media (max-width: 576px) {
    .mb-contact-grid { grid-template-columns: 1fr; }
    .mb-about-card { padding: 24px 20px; }
    .mb-demo { padding: 24px 20px; }
    .mb-demo-btns, .mb-demo-btns a { width: 100%; justify-content: center; }
}

/* ═══════════════════════════════════════════════════
   PAGE MOTION — load-in + scroll reveal + progress bar
   (only when JS is on: <html class="mb-js"> is set in <head>)
═══════════════════════════════════════════════════ */
/* Default: the element rises while a mask opens from the bottom up.
   The revealed inset is negative so shadows and glows are never clipped. */
.mb-js [data-reveal] {
    opacity: 0;
    transform: translate3d(0, 40px, 0);
    clip-path: inset(100% -60px -60px -60px);
    transition:
        opacity .6s ease,
        transform .9s cubic-bezier(.16,1,.3,1),
        clip-path .9s cubic-bezier(.16,1,.3,1);
    transition-delay: calc(var(--rd, 0) * 110ms);
}
.mb-js [data-reveal].is-revealed {
    opacity: 1;
    transform: none;
    clip-path: inset(-60px -60px -60px -60px);
}
/* Panels and side items: no mask, just a clean scale / slide */
.mb-js [data-reveal="zoom"],
.mb-js [data-reveal="right"] { clip-path: none; transition-property: opacity, transform; }
.mb-js [data-reveal="zoom"]  { transform: translate3d(0, 30px, 0) scale(.96); }
.mb-js [data-reveal="right"] { transform: translate3d(48px, 0, 0); }
.mb-js [data-reveal="zoom"].is-revealed,
.mb-js [data-reveal="right"].is-revealed { clip-path: none; transform: none; }
.mb-js [data-reveal="line"] { opacity: 1; transform: none; clip-path: none; }  /* only toggles is-revealed */

/* Nav drops in on load */
.mb-js .kc-nav { animation: mbNavIn .8s cubic-bezier(.16,1,.3,1) both; }
@keyframes mbNavIn { from { opacity: 0; transform: translateY(-18px); } }

/* Hero: hold the first slide's entrance until the page has painted */
.mb-js .mb-hero:not(.is-ready) .mb-slide-text > * { opacity: 0; transform: translateY(26px); }
.mb-js .mb-hero:not(.is-ready) .mb-frame { clip-path: inset(0 0 0 100% round 24px); }
.mb-js .mb-hero:not(.is-ready) .mb-frame img { transform: scale(1.12); }
.mb-js .mb-hero:not(.is-ready) .mb-chip { opacity: 0; transform: translateY(12px) scale(.96); }
.mb-js .mb-hero:not(.is-ready) .mb-controls { opacity: 0; }
.mb-hero .mb-controls { transition: opacity .8s ease .5s; }

/* Scroll progress bar */
.mb-progress {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    z-index: 2000;
    background: linear-gradient(90deg, var(--bl-accent, #38bdf8), var(--bl-primary, #0284c7));
    transform: scaleX(0);
    transform-origin: left;
    pointer-events: none;
}

@media (prefers-reduced-motion: reduce) {
    .mb-js [data-reveal] { opacity: 1; transform: none; clip-path: none; transition: none; }
    .mb-js .kc-nav { animation: none; }
}

</style>

<body id="section_1">
    <div class="mb-progress" id="mbProgress" aria-hidden="true"></div>


    <?php include_once('includes/header.php'); ?>
    <?php include_once('includes/nav.php'); ?>

    <main>

    <!-- ════════════════════════════════════════════════════════
         HERO SLIDER
    ════════════════════════════════════════════════════════ -->
    <section class="mb-hero" id="home" aria-roledescription="carousel" aria-label="Highlights" style="--mb-interval:6s">
        <div class="container">
            <div class="mb-hero-track">
                <?php foreach ($heroSlides as $i => $s): $tag = $i === 0 ? 'h1' : 'h2'; ?>
                <div class="mb-slide<?= $i === 0 ? ' is-active' : '' ?>"
                     role="group" aria-roledescription="slide"
                     aria-label="<?= $i + 1 ?> of <?= count($heroSlides) ?>"<?= $i === 0 ? '' : ' aria-hidden="true" inert' ?>>

                    <div class="mb-slide-text">
                        <?php if ($s['eyebrow'] !== ''): ?>
                        <div class="mb-eyebrow"><i class="fas fa-bolt"></i> <?= htmlspecialchars($s['eyebrow']) ?></div>
                        <?php endif; ?>
                        <<?= $tag ?> class="mb-title"><?= $s['title'] ?></<?= $tag ?>>
                        <?php if ($s['sub'] !== ''): ?>
                        <p class="mb-sub"><?= htmlspecialchars($s['sub']) ?></p>
                        <?php endif; ?>
                        <div class="mb-btns">
                            <?php if ($s['btn1'][0] !== ''): ?>
                            <a href="<?= htmlspecialchars($s['btn1'][1]) ?>" class="mb-btn-main"><?= htmlspecialchars($s['btn1'][0]) ?> <i class="fas fa-arrow-right"></i></a>
                            <?php endif; ?>
                            <?php if ($s['btn2'][0] !== '' && $s['btn2'][1] !== ''): ?>
                            <a href="<?= htmlspecialchars($s['btn2'][1]) ?>" class="mb-btn-ghost"><?= htmlspecialchars($s['btn2'][0]) ?></a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-visual">
                        <div class="mb-frame">
                            <img src="<?= htmlspecialchars($s['image']) ?>" alt=""
                                 <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
                        </div>
                        <?php foreach ($s['chips'] as $n => [$icon, $green, $label, $detail]): ?>
                        <div class="mb-chip mb-chip-<?= $n + 1 ?>" aria-hidden="true">
                            <div class="mb-chip-ico<?= $green ? ' is-green' : '' ?>"><i class="fas <?= $icon ?>"></i></div>
                            <div><strong><?= htmlspecialchars($label) ?></strong><span><?= htmlspecialchars($detail) ?></span></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                </div>
                <?php endforeach; ?>
            </div><!-- /mb-hero-track -->

            <?php if (count($heroSlides) > 1): ?>
            <div class="mb-controls">
                <div class="mb-count" aria-hidden="true"><b id="mbHeroNum">01</b> / <?= sprintf('%02d', count($heroSlides)) ?></div>
                <div class="mb-bars">
                    <?php foreach ($heroSlides as $i => $s): ?>
                    <button type="button" class="mb-bar<?= $i === 0 ? ' is-active' : '' ?>" data-go="<?= $i ?>"
                            aria-label="Go to slide <?= $i + 1 ?>"><span></span></button>
                    <?php endforeach; ?>
                </div>
                <div class="mb-arrows">
                    <button type="button" class="mb-arrow" data-dir="-1" aria-label="Previous slide"><i class="fas fa-arrow-left"></i></button>
                    <button type="button" class="mb-arrow" data-dir="1"  aria-label="Next slide"><i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <!-- /HERO -->


    <!-- ════════════════════════════════════════════════════════
         LIVE DASHBOARD PREVIEW (illustrative sample data)
    ════════════════════════════════════════════════════════ -->
    <section class="mb-dash" id="mbDash" aria-labelledby="mbDashTitle">
        <div class="container">

            <div class="mb-dash-head">
                <div class="mb-dash-eyebrow" data-reveal><i class="fas fa-chart-line"></i> Live Dashboard</div>
                <h2 class="mb-dash-title" data-reveal style="--rd:1" id="mbDashTitle">Your whole network, <em>at a glance</em></h2>
                <p class="mb-dash-sub" data-reveal style="--rd:2">Earnings, customers online, data usage and M-Pesa payments update the moment they happen, so you always know how your business is doing.</p>
            </div>

            <div class="mb-app" role="img" aria-label="Preview of the <?= htmlspecialchars($siteName) ?> dashboard with earnings, customer counts, a revenue chart and live M-Pesa payments">
                <div class="mb-app-bar">
                    <span class="mb-app-dots"><i></i><i></i><i></i></span>
                    <span class="mb-app-url"><i class="fas fa-lock"></i> <?= htmlspecialchars($siteName) ?> · Dashboard</span>
                    <span class="mb-app-tag">Sample data</span>
                </div>

                <div class="mb-app-body">
                    <aside class="mb-app-side">
                        <div class="mb-app-user">
                            <span class="mb-app-av">D</span>
                            <div><strong>demo</strong><em>Administrator</em></div>
                        </div>
                        <div class="mb-app-nav">
                            <span class="is-active"><i class="fas fa-gauge-high"></i> Dashboard</span>
                            <div class="mb-app-group">Customers</div>
                            <span><i class="fas fa-user-check"></i> Online Customers <b data-live-online>0</b></span>
                            <span><i class="fas fa-users"></i> Active Customers <b>1,126</b></span>
                            <span><i class="fas fa-user-plus"></i> Add Customer</span>
                            <span><i class="fas fa-ticket"></i> Vouchers</span>
                            <div class="mb-app-group">Network</div>
                            <span><i class="fas fa-server"></i> Routers</span>
                            <span><i class="fas fa-tower-broadcast"></i> Usage Analytics</span>
                            <span><i class="fas fa-comment-dots"></i> Messages</span>
                        </div>
                    </aside>

                    <div class="mb-app-main">
                        <div class="mb-app-top">
                            <div class="mb-app-search"><i class="fas fa-magnifying-glass"></i> Search customers by name or phone…</div>
                            <div class="mb-app-online"><span class="mb-pulse"></span> <b data-live-online>0</b> online right now</div>
                        </div>

                        <div class="mb-app-title">
                            <small>Overview</small>
                            <h3>Dashboard<span>.</span></h3>
                            <p>Live business snapshot — <span id="mbDashNow"></span></p>
                        </div>

                        <div class="mb-stats">
                            <div class="mb-stat" style="--c:#0284c7">
                                <div class="mb-stat-top"><span>Earnings today</span><i class="fas fa-sack-dollar"></i></div>
                                <div class="mb-stat-val">KES <b id="mbEarnToday" data-count="48750">0</b></div>
                                <div class="mb-stat-foot is-up"><i class="fas fa-arrow-trend-up"></i> 12.4% vs yesterday</div>
                            </div>
                            <div class="mb-stat" style="--c:#38bdf8">
                                <div class="mb-stat-top"><span>This week</span><i class="fas fa-calendar-week"></i></div>
                                <div class="mb-stat-val">KES <b data-count="286400">0</b></div>
                                <div class="mb-stat-foot">Monday to today</div>
                            </div>
                            <div class="mb-stat" style="--c:#16a34a">
                                <div class="mb-stat-top"><span>This month</span><i class="fas fa-calendar-days"></i></div>
                                <div class="mb-stat-val">KES <b data-count="1124900">0</b></div>
                                <div class="mb-stat-foot is-up"><i class="fas fa-arrow-trend-up"></i> 8.1% vs last month</div>
                            </div>
                            <div class="mb-stat" style="--c:#071d3a">
                                <div class="mb-stat-top"><span>Total clients</span><i class="fas fa-users"></i></div>
                                <div class="mb-stat-val"><b data-count="1284">0</b></div>
                                <div class="mb-stat-foot is-up">+38 new this month</div>
                            </div>
                        </div>

                        <div class="mb-row">
                            <div class="mb-card">
                                <div class="mb-card-head"><h4><i class="fas fa-chart-area"></i> Revenue</h4><span>Last 14 days</span></div>
                                <svg class="mb-chart" id="mbChart" viewBox="0 0 600 220" aria-hidden="true"></svg>
                                <div class="mb-chart-x" id="mbChartX"></div>
                            </div>
                            <div class="mb-card">
                                <div class="mb-card-head"><h4><i class="fas fa-mobile-screen"></i> M-Pesa payments</h4><span class="mb-live">Live</span></div>
                                <ul class="mb-feed" id="mbFeed"></ul>
                            </div>
                        </div>

                        <div class="mb-row mb-row-3">
                            <div class="mb-card">
                                <div class="mb-card-head"><h4><i class="fas fa-database"></i> Data usage this month</h4></div>
                                <div class="mb-ring-wrap">
                                    <svg class="mb-ring" viewBox="0 0 120 120" aria-hidden="true">
                                        <circle class="mb-ring-bg" cx="60" cy="60" r="50"/>
                                        <circle class="mb-ring-fg" cx="60" cy="60" r="50" style="--p:.72"/>
                                    </svg>
                                    <div class="mb-ring-label"><b><span data-count="3.4" data-dec="1">0</span> TB</b><small>72% of peak</small></div>
                                </div>
                                <div class="mb-ring-legend"><span><i class="fas fa-arrow-down"></i>2.9 TB</span><span><i class="fas fa-arrow-up"></i>0.5 TB</span></div>
                            </div>
                            <div class="mb-card">
                                <div class="mb-card-head"><h4><i class="fas fa-layer-group"></i> Customers by plan</h4></div>
                                <ul class="mb-plans">
                                    <li><span>PPPoE 10 Mbps</span><b>412</b><div class="mb-hbar"><i style="--w:82%"></i></div></li>
                                    <li><span>Hotspot 1 Day</span><b>356</b><div class="mb-hbar"><i style="--w:70%"></i></div></li>
                                    <li><span>PPPoE 20 Mbps</span><b>238</b><div class="mb-hbar"><i style="--w:48%"></i></div></li>
                                    <li><span>Hotspot 1 Week</span><b>168</b><div class="mb-hbar"><i style="--w:34%"></i></div></li>
                                    <li><span>Hotspot 1 Hour</span><b>110</b><div class="mb-hbar"><i style="--w:22%"></i></div></li>
                                </ul>
                            </div>
                            <div class="mb-card">
                                <div class="mb-card-head"><h4><i class="fas fa-bolt"></i> At a glance</h4></div>
                                <ul class="mb-glance">
                                    <li><span class="mb-glance-ico" style="--bg:#dcfce7;--fg:#16a34a"><i class="fas fa-server"></i></span>
                                        <div><small>Routers online</small><strong>6 / 6</strong></div></li>
                                    <li><span class="mb-glance-ico" style="--bg:#fef3c7;--fg:#d97706"><i class="fas fa-hourglass-half"></i></span>
                                        <div><small>Expiring in 24h</small><strong data-count="14">0</strong></div></li>
                                    <li><span class="mb-glance-ico" style="--bg:#e0f2fe;--fg:#0284c7"><i class="fas fa-comment-sms"></i></span>
                                        <div><small>SMS balance</small><strong>KES <span data-count="2450">0</span></strong></div></li>
                                </ul>
                            </div>
                        </div>
                    </div><!-- /mb-app-main -->
                </div>
            </div><!-- /mb-app -->

            <div class="mb-dash-points">
                <div class="mb-point" data-reveal style="--rd:0">
                    <i class="fas fa-bolt"></i>
                    <div><h3>Real-time earnings</h3><p>Every M-Pesa payment lands on your dashboard the second it clears.</p></div>
                </div>
                <div class="mb-point" data-reveal style="--rd:1">
                    <i class="fas fa-plug-circle-check"></i>
                    <div><h3>Automatic access control</h3><p>Expired customers are cut off and reconnected the moment they pay.</p></div>
                </div>
                <div class="mb-point" data-reveal style="--rd:2">
                    <i class="fas fa-chart-pie"></i>
                    <div><h3>Usage analytics</h3><p>See data usage per customer, plan and router to plan capacity.</p></div>
                </div>
            </div>

        </div>
    </section>
    <!-- /LIVE DASHBOARD -->


    <!-- ════════════════════════════════════════════════════════
         FEATURES
    ════════════════════════════════════════════════════════ -->
    <section class="mb-feat" id="features" aria-labelledby="mbFeatTitle">
        <div class="container">

            <div class="mb-feat-head">
                <div class="mb-feat-eyebrow" data-reveal><i class="fas fa-layer-group"></i> Features</div>
                <h2 class="mb-feat-title" data-reveal style="--rd:1" id="mbFeatTitle">Everything you need to <em>run your ISP</em></h2>
                <p class="mb-feat-sub" data-reveal style="--rd:2">From hotspot vouchers to PPPoE renewals, the tools that collect your money, keep customers online and keep them happy.</p>
            </div>

            <div class="mb-feat-big">

                <article class="mb-fcard" style="--i:0">
                    <div class="mb-fvis" aria-hidden="true">
                        <div class="mb-vch">
                            <div class="mb-vch-card is-back"></div>
                            <div class="mb-vch-card is-mid"></div>
                            <div class="mb-vch-card is-front">
                                <div class="mb-vch-top"><span>Hotspot voucher</span><b>KES 50</b></div>
                                <div class="mb-vch-code">MBX-4K7Q-92</div>
                                <div class="mb-vch-meta">
                                    <span><i class="fas fa-clock"></i>1 Day</span>
                                    <span><i class="fas fa-infinity"></i>Unlimited</span>
                                    <span><i class="fas fa-mobile-screen"></i>1 device</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-fbody">
                        <h3>Voucher management</h3>
                        <p>Generate, print and sell hotspot vouchers in bulk, or let customers buy one instantly with M-Pesa.</p>
                        <div class="mb-ftags"><span>Bulk generate</span><span>Print-ready</span><span>Time &amp; data limits</span></div>
                    </div>
                </article>

                <article class="mb-fcard" style="--i:1">
                    <div class="mb-fvis" aria-hidden="true">
                        <div class="mb-fup">
                            <div class="mb-fup-speed">
                                <span class="mb-fup-fast">20 <small>Mbps</small></span>
                                <span class="mb-fup-slow">5 <small>Mbps</small></span>
                            </div>
                            <div class="mb-fup-bar"><div class="mb-fup-fill"></div><span class="mb-fup-cap"></span></div>
                            <div class="mb-fup-legend"><span>Data used this cycle</span><b>FUP cap 100 GB</b></div>
                        </div>
                    </div>
                    <div class="mb-fbody">
                        <h3>Fair Usage Policy (FUP)</h3>
                        <p>Set data caps per package. Speeds drop automatically when a customer hits the cap and reset on renewal.</p>
                        <div class="mb-ftags"><span>Per-package caps</span><span>Auto throttle</span><span>Auto reset</span></div>
                    </div>
                </article>

                <article class="mb-fcard" style="--i:2">
                    <div class="mb-fvis" aria-hidden="true">
                        <div class="mb-wal">
                            <div class="mb-wal-bal">
                                <div><small>Wallet balance</small><b>KES 1,500</b></div>
                                <span class="mb-wal-auto"><i></i>Auto-renew</span>
                            </div>
                            <ul>
                                <li><i class="fas fa-arrow-down is-plus"></i> Top-up via M-Pesa <b class="is-plus">+3,000</b></li>
                                <li><i class="fas fa-rotate is-minus"></i> Renewed · PPPoE 10 Mbps <b>−1,500</b></li>
                            </ul>
                        </div>
                    </div>
                    <div class="mb-fbody">
                        <h3>Wallet system</h3>
                        <p>Customers top up ahead of time and their package renews automatically from the wallet balance.</p>
                        <div class="mb-ftags"><span>Prepaid balance</span><span>Auto-renew</span><span>Statements</span></div>
                    </div>
                </article>

            </div>

            <div class="mb-feat-grid">
                <article class="mb-fcard" style="--i:3">
                    <div class="mb-fico is-green"><i class="fas fa-plug-circle-check"></i></div>
                    <h3>Auto reconnection</h3>
                    <p>Expired customers are cut off on time and reconnected the moment they pay.</p>
                </article>
                <article class="mb-fcard" style="--i:4">
                    <div class="mb-fico"><i class="fas fa-door-open"></i></div>
                    <h3>PPPoE captive portal</h3>
                    <p>Expired PPPoE users are redirected to a renewal page where they can pay and get back online.</p>
                </article>
                <article class="mb-fcard" style="--i:5">
                    <div class="mb-fico"><i class="fas fa-comments"></i></div>
                    <h3>Customer chat</h3>
                    <p>Customers message their ISP directly from the portal, so issues reach you fast.</p>
                </article>
                <article class="mb-fcard" style="--i:6">
                    <div class="mb-fico is-amber"><i class="fas fa-headset"></i></div>
                    <h3>24/7 support</h3>
                    <p>Our team is available around the clock whenever you need help with your system.</p>
                </article>
                <article class="mb-fcard" style="--i:7">
                    <div class="mb-fico"><i class="fas fa-tv"></i></div>
                    <h3>TV billing</h3>
                    <p>Bill TV subscriptions alongside internet packages, all on one customer account.</p>
                </article>
                <article class="mb-fcard" style="--i:8">
                    <div class="mb-fico is-green"><i class="fas fa-mobile-screen-button"></i></div>
                    <h3>M-Pesa payments</h3>
                    <p>STK push and Paybill payments are matched to customers and activated instantly.</p>
                </article>
                <article class="mb-fcard" style="--i:9">
                    <div class="mb-fico"><i class="fas fa-comment-sms"></i></div>
                    <h3>SMS alerts</h3>
                    <p>Automatic expiry reminders, payment receipts and bulk messages to your customers.</p>
                </article>
                <article class="mb-fcard" style="--i:10">
                    <div class="mb-fico"><i class="fas fa-chart-column"></i></div>
                    <h3>Usage analytics</h3>
                    <p>Track data usage per customer, package and router to plan capacity.</p>
                </article>
            </div>

        </div>
    </section>
    <!-- /FEATURES -->


    <!-- ════════════════════════════════════════════════════════
         EARN · CONNECT · GROW
    ════════════════════════════════════════════════════════ -->
    <section class="mb-earn" id="earn" aria-labelledby="mbEarnTitle">
        <div class="container">

            <div class="mb-earn-head">
                <div class="mb-earn-eyebrow" data-reveal><i class="fas fa-coins"></i> How you earn</div>
                <h2 class="mb-earn-title" data-reveal style="--rd:1" id="mbEarnTitle">Earn, connect and <em>grow</em></h2>
                <p class="mb-earn-sub" data-reveal style="--rd:2">Payments come in on their own, customers get online the moment they pay, and you get the numbers to grow your network.</p>
            </div>

            <div class="mb-earn-grid">

                <!-- 1. EARN -->
                <article class="mb-ecard">
                    <div class="mb-evis">
                        <span class="mb-estep">01</span>
                        <svg viewBox="0 0 320 210" aria-hidden="true">
                            <ellipse cx="120" cy="188" rx="62" ry="8" fill="rgba(7,29,58,.08)"/>
                            <g id="mbCoins">
                                <?php for ($c = 0; $c < 6; $c++): $y = 176 - $c * 12; ?>
                                <g transform="translate(120 <?= $y ?>)">
                                    <g class="mb-coin">
                                        <rect x="-34" y="-6" width="68" height="10" fill="#d4920a"/>
                                        <ellipse cx="0" cy="4" rx="34" ry="9" fill="#c2830a"/>
                                        <ellipse cx="0" cy="-6" rx="34" ry="9" fill="#fbbf24" stroke="#f59e0b" stroke-width="1.5"/>
                                        <ellipse cx="0" cy="-6" rx="21" ry="5" fill="none" stroke="#d4920a" stroke-width="1.5" opacity=".7"/>
                                    </g>
                                </g>
                                <?php endfor; ?>
                            </g>
                            <text class="mb-coin-plus" id="mbCoinPlus" x="164" y="160">+50</text>
                            <!-- phone with running M-Pesa total -->
                            <rect x="222" y="40" width="78" height="134" rx="13" fill="#071d3a"/>
                            <rect x="228" y="52" width="66" height="110" rx="7" fill="#0c3b6e"/>
                            <rect x="250" y="45" width="22" height="3" rx="1.5" fill="#1e3a5f"/>
                            <text class="mb-phone-sm" x="261" y="72" text-anchor="middle">M-PESA</text>
                            <text class="mb-phone-lbl" x="261" y="96" text-anchor="middle">Received today</text>
                            <text class="mb-phone-amt" id="mbCoinTotal" x="261" y="116" text-anchor="middle">KES 0</text>
                            <circle cx="261" cy="140" r="10" fill="#16a34a"/>
                            <path d="M256 140 l3.5 3.5 l6.5 -7" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="mb-ebody">
                        <h3>Earn automatically</h3>
                        <p>Every package sale and renewal is paid by M-Pesa straight into your account, day and night.</p>
                        <ul>
                            <li><i class="fas fa-check"></i> No cash to collect or chase</li>
                            <li><i class="fas fa-check"></i> Every payment matched to a customer</li>
                        </ul>
                    </div>
                </article>

                <!-- 2. CONNECT -->
                <article class="mb-ecard">
                    <div class="mb-evis">
                        <span class="mb-estep">02</span>
                        <svg viewBox="0 0 320 210" aria-hidden="true">
                            <ellipse cx="160" cy="186" rx="130" ry="7" fill="rgba(7,29,58,.06)"/>
                            <!-- customer's house -->
                            <polygon points="12,114 45,84 78,114" fill="#0284c7"/>
                            <rect x="20" y="112" width="50" height="40" fill="#e0f2fe" stroke="#0284c7" stroke-width="2"/>
                            <rect class="mb-win" x="28" y="122" width="15" height="12" rx="1.5"/>
                            <rect x="50" y="128" width="12" height="24" fill="#0284c7"/>
                            <!-- cable: drawn in, then data flows along it -->
                            <path class="mb-cable" pathLength="100" d="M70 142 C 96 142, 96 118, 120 118 L 160 118"/>
                            <path class="mb-flow" d="M70 142 C 96 142, 96 118, 120 118 L 176 118"/>
                            <!-- plug slides into the port -->
                            <g class="mb-plug">
                                <line x1="130" y1="118" x2="172" y2="118" stroke="#0c3b6e" stroke-width="5" stroke-linecap="round"/>
                                <rect x="168" y="111" width="9" height="14" rx="2" fill="#38bdf8"/>
                                <rect x="176" y="110" width="20" height="16" rx="2" fill="#e2e8f0" stroke="#94a3b8" stroke-width="1.5"/>
                                <rect x="195" y="113" width="7" height="10" fill="#cbd5e1"/>
                            </g>
                            <!-- switch -->
                            <rect x="200" y="74" width="106" height="88" rx="10" fill="#0c3b6e"/>
                            <rect x="196" y="108" width="10" height="20" rx="2" fill="#071d3a"/>
                            <circle class="mb-led-main" cx="218" cy="92" r="4.5"/>
                            <text x="228" y="95" font-size="8" font-weight="700" fill="#7dd3fc" font-family="system-ui, sans-serif" letter-spacing="1">LINK</text>
                            <?php for ($p = 0; $p < 4; $p++): ?>
                            <rect x="<?= 214 + $p * 22 ?>" y="134" width="15" height="12" rx="2" fill="#071d3a"/>
                            <circle class="mb-led-sm<?= $p === 1 ? ' is-b' : ($p === 3 ? ' is-c' : '') ?>" cx="<?= 221.5 + $p * 22 ?>" cy="126" r="2.2"/>
                            <?php endfor; ?>
                            <!-- "Connected" badge -->
                            <g class="mb-conn-badge">
                                <rect x="206" y="40" width="94" height="24" rx="12" fill="#dcfce7" stroke="#86efac"/>
                                <circle cx="222" cy="52" r="4" fill="#16a34a"/>
                                <text x="231" y="56" font-size="11" font-weight="800" fill="#15803d" font-family="system-ui, sans-serif">Connected</text>
                            </g>
                        </svg>
                    </div>
                    <div class="mb-ebody">
                        <h3>Connect instantly</h3>
                        <p>Link your MikroTik routers once. Customers are switched on the moment they pay and switched off when they expire.</p>
                        <ul>
                            <li><i class="fas fa-check"></i> PPPoE and hotspot on one system</li>
                            <li><i class="fas fa-check"></i> Automatic reconnection after payment</li>
                        </ul>
                    </div>
                </article>

                <!-- 3. GROW -->
                <article class="mb-ecard">
                    <div class="mb-evis">
                        <span class="mb-estep">03</span>
                        <svg viewBox="0 0 320 210" aria-hidden="true">
                            <defs>
                                <linearGradient id="mbGrowGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="#38bdf8"/><stop offset="1" stop-color="#0284c7"/>
                                </linearGradient>
                            </defs>
                            <line x1="30" y1="178" x2="292" y2="178" stroke="#cbd5e1" stroke-width="2" stroke-linecap="round"/>
                            <?php foreach ([40, 62, 82, 106, 134] as $b => $h): ?>
                            <rect class="mb-gbar" style="--i:<?= $b ?>" x="<?= 46 + $b * 50 ?>" y="<?= 178 - $h ?>" width="30" height="<?= $h ?>" rx="5" fill="url(#mbGrowGrad)"/>
                            <?php endforeach; ?>
                            <path class="mb-gline" pathLength="100" d="M61 126 L111 104 L161 84 L211 60 L258 34"/>
                            <?php foreach ([[61,126],[111,104],[161,84],[211,60]] as [$dx, $dy]): ?>
                            <circle class="mb-gdot" cx="<?= $dx ?>" cy="<?= $dy ?>" r="4"/>
                            <?php endforeach; ?>
                            <polygon class="mb-ghead" points="268,26 252,30 262,42" fill="#16a34a"/>
                            <g class="mb-gbadge">
                                <rect x="30" y="22" width="104" height="26" rx="13" fill="#dcfce7" stroke="#86efac"/>
                                <text x="82" y="39" text-anchor="middle" font-size="12" font-weight="800" fill="#15803d" font-family="system-ui, sans-serif">+38% growth</text>
                            </g>
                        </svg>
                    </div>
                    <div class="mb-ebody">
                        <h3>Grow with confidence</h3>
                        <p>Add routers, sites and customers without extra admin, and use real numbers to decide where to expand next.</p>
                        <ul>
                            <li><i class="fas fa-check"></i> Unlimited MikroTik routers</li>
                            <li><i class="fas fa-check"></i> Revenue and usage analytics</li>
                        </ul>
                    </div>
                </article>

            </div>
        </div>
    </section>
    <!-- /EARN · CONNECT · GROW -->


    <!-- ════════════════════════════════════════════════════════
         PRICING — flip cards
    ════════════════════════════════════════════════════════ -->
    <section class="mb-price" id="pricing" aria-labelledby="mbPriceTitle">
        <div class="container">

            <div class="mb-price-head">
                <div class="mb-price-eyebrow" data-reveal><i class="fas fa-tags"></i> Pricing</div>
                <h2 class="mb-price-title" data-reveal style="--rd:1" id="mbPriceTitle">Simple pricing that <em>grows with you</em></h2>
                <p class="mb-price-sub" data-reveal style="--rd:2">No setup fees and no per-router charges. You pay for active PPPoE customers and a small share of hotspot sales, with a low monthly minimum.</p>
            </div>

            <div class="mb-price-grid">

                <div class="mb-flip is-featured" tabindex="0" role="button" aria-pressed="false">
                    <div class="mb-flip-inner">
                        <div class="mb-flip-face mb-flip-front">
                            <span class="mb-flip-badge">Start here</span>
                            <div class="mb-flip-ico"><i class="fas fa-wallet"></i></div>
                            <div class="mb-flip-label">Monthly minimum</div>
                            <div class="mb-flip-big"><small>KES</small>500</div>
                            <div class="mb-flip-unit">per month</div>
                            <p class="mb-flip-cap">The lowest your bill will ever be, whatever the size of your network.</p>
                            <span class="mb-flip-hint"><i class="fas fa-rotate"></i> Flip for details</span>
                        </div>
                        <div class="mb-flip-face mb-flip-back">
                            <h4>How the minimum works</h4>
                            <ul>
                                <li><i class="fas fa-check"></i> If your PPPoE and hotspot charges come to less than KES 500, you pay KES 500.</li>
                                <li><i class="fas fa-check"></i> Once they pass KES 500, you just pay the usage.</li>
                                <li><i class="fas fa-check"></i> No setup fee, no contract.</li>
                            </ul>
                            <div class="mb-flip-eg"><small>Example</small>10 PPPoE users = KES 200, so you pay the <b>KES 500</b> minimum.</div>
                        </div>
                    </div>
                </div>

                <div class="mb-flip" tabindex="0" role="button" aria-pressed="false">
                    <div class="mb-flip-inner">
                        <div class="mb-flip-face mb-flip-front">
                            <div class="mb-flip-ico"><i class="fas fa-server"></i></div>
                            <div class="mb-flip-label">MikroTik routers</div>
                            <div class="mb-flip-big">Unlimited</div>
                            <div class="mb-flip-unit">routers included</div>
                            <p class="mb-flip-cap">Connect every MikroTik you run. Adding routers never adds to your bill.</p>
                            <span class="mb-flip-hint"><i class="fas fa-rotate"></i> Flip for details</span>
                        </div>
                        <div class="mb-flip-face mb-flip-back">
                            <h4>Every router, no extra cost</h4>
                            <ul>
                                <li><i class="fas fa-check"></i> Add as many MikroTik routers as you need.</li>
                                <li><i class="fas fa-check"></i> Manage them all from one dashboard.</li>
                                <li><i class="fas fa-check"></i> Expand to new sites without changing plans.</li>
                            </ul>
                            <div class="mb-flip-eg"><small>Example</small>1 router or 20 routers: <b>KES 0</b> extra.</div>
                        </div>
                    </div>
                </div>

                <div class="mb-flip" tabindex="0" role="button" aria-pressed="false">
                    <div class="mb-flip-inner">
                        <div class="mb-flip-face mb-flip-front">
                            <div class="mb-flip-ico"><i class="fas fa-network-wired"></i></div>
                            <div class="mb-flip-label">PPPoE customers</div>
                            <div class="mb-flip-big"><small>KES</small>20</div>
                            <div class="mb-flip-unit">per active user / month</div>
                            <p class="mb-flip-cap">You only pay for PPPoE customers who are active that month.</p>
                            <span class="mb-flip-hint"><i class="fas fa-rotate"></i> Flip for details</span>
                        </div>
                        <div class="mb-flip-face mb-flip-back">
                            <h4>Pay per active user</h4>
                            <ul>
                                <li><i class="fas fa-check"></i> Charged only for users active in the month.</li>
                                <li><i class="fas fa-check"></i> Inactive accounts cost you nothing.</li>
                                <li><i class="fas fa-check"></i> Your bill scales up and down with your network.</li>
                            </ul>
                            <div class="mb-flip-eg"><small>Example</small>120 active users × KES 20 = <b>KES 2,400</b> / month.</div>
                        </div>
                    </div>
                </div>

                <div class="mb-flip" tabindex="0" role="button" aria-pressed="false">
                    <div class="mb-flip-inner">
                        <div class="mb-flip-face mb-flip-front">
                            <div class="mb-flip-ico"><i class="fas fa-wifi"></i></div>
                            <div class="mb-flip-label">Hotspot sales</div>
                            <div class="mb-flip-big">3%</div>
                            <div class="mb-flip-unit">of hotspot revenue</div>
                            <p class="mb-flip-cap">We only earn when you do. No hotspot sales means no hotspot fee.</p>
                            <span class="mb-flip-hint"><i class="fas fa-rotate"></i> Flip for details</span>
                        </div>
                        <div class="mb-flip-face mb-flip-back">
                            <h4>A small share of sales</h4>
                            <ul>
                                <li><i class="fas fa-check"></i> 3% of the hotspot payments you collect.</li>
                                <li><i class="fas fa-check"></i> Customers pay by M-Pesa and get online instantly.</li>
                                <li><i class="fas fa-check"></i> No flat hotspot fee in quiet months.</li>
                            </ul>
                            <div class="mb-flip-eg"><small>Example</small>KES 40,000 in hotspot sales × 3% = <b>KES 1,200</b>.</div>
                        </div>
                    </div>
                </div>

            </div><!-- /mb-price-grid -->

            <div class="mb-price-foot" data-reveal="zoom">
                <div class="mb-formula">
                    <small>Your monthly bill</small>
                    <b>KES 20</b> × active PPPoE users <span class="mb-op">+</span> <b>3%</b> of hotspot revenue
                    <span class="mb-op">·</span> minimum <b>KES 500</b> <span class="mb-op">·</span> unlimited MikroTik routers
                </div>
                <div class="mb-price-cta">
                    <a href="#demo" class="is-main">Book a Demo <i class="fas fa-arrow-right"></i></a>
                    <a href="#contact" class="is-ghost">Talk to Sales</a>
                </div>
            </div>

        </div>
    </section>
    <!-- /PRICING -->




    <!-- ════════════════════════════════════════════════════════
         JOIN — "start selling" call to action
    ════════════════════════════════════════════════════════ -->
    <section class="mb-cta" id="join" aria-labelledby="mbCtaTitle">
        <div class="container">
            <div class="mb-cta-panel" data-reveal="zoom">
                <div class="mb-cta-glow" aria-hidden="true"></div>

                <div class="mb-cta-copy">
                    <div class="mb-cta-eyebrow" data-reveal style="--rd:1"><i class="fas fa-rocket"></i> Get started</div>
                    <h2 class="mb-cta-title" id="mbCtaTitle" data-reveal style="--rd:2">Join <?= htmlspecialchars($siteName) ?> and <em>start selling</em> today</h2>
                    <p class="mb-cta-sub" data-reveal style="--rd:3">Connect your MikroTik, set your packages and let customers pay by M-Pesa. Talk to us and we'll get your network set up.</p>
                    <div class="mb-cta-btns" data-reveal style="--rd:4">
                        <a href="<?= htmlspecialchars($appSignupUrl) ?>" class="mb-cta-main">Get Started <i class="fas fa-arrow-right"></i></a>
                        <a href="tel:<?= htmlspecialchars($sitePhoneTel) ?>" class="mb-cta-ghost"><i class="fas fa-phone"></i> <?= htmlspecialchars($sitePhone) ?></a>
                    </div>
                    <ul class="mb-cta-trust" data-reveal style="--rd:5">
                        <li><i class="fas fa-circle-check"></i> KES 500 monthly minimum</li>
                        <li><i class="fas fa-circle-check"></i> Unlimited MikroTik routers</li>
                        <li><i class="fas fa-circle-check"></i> 24/7 support</li>
                    </ul>
                </div>

                <ol class="mb-cta-steps" data-reveal="line">
                    <li class="mb-cta-step" data-reveal="right" style="--rd:3">
                        <span class="mb-cta-num">1</span>
                        <div><strong>Create your account</strong><small>Tell us about your network and packages.</small></div>
                    </li>
                    <li class="mb-cta-step" data-reveal="right" style="--rd:4">
                        <span class="mb-cta-num">2</span>
                        <div><strong>Connect your MikroTik</strong><small>Link your routers to the dashboard.</small></div>
                    </li>
                    <li class="mb-cta-step" data-reveal="right" style="--rd:5">
                        <span class="mb-cta-num">3</span>
                        <div><strong>Start selling</strong><small>Customers pay by M-Pesa and get online.</small></div>
                        <span class="mb-cta-live">Live</span>
                    </li>
                </ol>
            </div>
        </div>
    </section>
    <!-- /JOIN -->


    <!-- ════════════════════════════════════════════════════════
         ABOUT
    ════════════════════════════════════════════════════════ -->
    <?php
    $waNumber = preg_replace('/\D/', '', $sitePhoneTel);
    $waDemo   = 'https://wa.me/' . $waNumber . '?text=' . rawurlencode("Hi, I'd like a demo of $siteName.");
    $mailDemo = 'mailto:' . $siteEmail . '?subject=' . rawurlencode("Demo request: $siteName");
    ?>
    <section class="mb-about" id="about" aria-labelledby="mbAboutTitle">
        <div class="container">
            <div class="mb-about-grid">
                <div class="mb-about-copy">
                    <div class="mb-sec-eyebrow" data-reveal><i class="fas fa-circle-info"></i> About us</div>
                    <h2 class="mb-sec-title" id="mbAboutTitle" data-reveal style="--rd:1">Billing software built for <em>Kenyan ISPs</em></h2>
                    <p class="mb-sec-sub" data-reveal style="--rd:2"><?= htmlspecialchars($siteName) ?> is built by Mecrofex Technologies in Nairobi for internet providers running hotspot and PPPoE networks.</p>
                    <p class="mb-sec-sub" data-reveal style="--rd:3">We know how much time goes into collecting payments, chasing renewals and managing routers by hand, so we built one system that does it for you, with M-Pesa and MikroTik at its core.</p>
                    <ul class="mb-values">
                        <li data-reveal style="--rd:4"><i class="fas fa-location-dot"></i><div><strong>Made for the local market</strong><span>M-Pesa payments, SMS and MikroTik routers work out of the box.</span></div></li>
                        <li data-reveal style="--rd:5"><i class="fas fa-scale-balanced"></i><div><strong>Fair, usage-based pricing</strong><span>You pay for active customers, so the cost grows only as you grow.</span></div></li>
                        <li data-reveal style="--rd:6"><i class="fas fa-headset"></i><div><strong>Support when you need it</strong><span>Our team is available 24/7 to keep your network billing smoothly.</span></div></li>
                    </ul>
                </div>

                <div class="mb-about-card" data-reveal="zoom" style="--rd:2">
                    <div class="mb-about-brand">
                        <img src="<?= htmlspecialchars($siteLogo) ?>" alt="">
                        <div><strong><?= htmlspecialchars($siteName) ?></strong><span>by Mecrofex Technologies</span></div>
                    </div>
                    <div class="mb-facts">
                        <div class="mb-fact"><i class="fas fa-map-pin"></i><strong>Nairobi, Kenya</strong><span>Local team</span></div>
                        <div class="mb-fact"><i class="fas fa-mobile-screen-button"></i><strong>M-Pesa native</strong><span>STK push &amp; Paybill</span></div>
                        <div class="mb-fact"><i class="fas fa-server"></i><strong>MikroTik ready</strong><span>Unlimited routers</span></div>
                        <div class="mb-fact"><i class="fas fa-clock"></i><strong>24/7 support</strong><span>Always reachable</span></div>
                    </div>
                    <a class="mb-about-link" href="https://mecrofex.co.ke" target="_blank" rel="noopener">Visit Mecrofex Technologies <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </section>
    <!-- /ABOUT -->


    <!-- ════════════════════════════════════════════════════════
         CONTACT
    ════════════════════════════════════════════════════════ -->
    <section class="mb-contact" id="contact" aria-labelledby="mbContactTitle">
        <div class="container">
            <div class="mb-contact-head">
                <div class="mb-sec-eyebrow" data-reveal><i class="fas fa-paper-plane"></i> Contact</div>
                <h2 class="mb-sec-title" id="mbContactTitle" data-reveal style="--rd:1">Talk to <em>our team</em></h2>
                <p class="mb-sec-sub" data-reveal style="--rd:2">Questions about pricing, setup or moving from another system? Reach us any way that suits you.</p>
            </div>

            <div class="mb-contact-grid">
                <a class="mb-ccard" href="tel:<?= htmlspecialchars($sitePhoneTel) ?>" data-reveal="zoom" style="--rd:0">
                    <i class="fas fa-phone"></i><small>Call us</small>
                    <strong><?= htmlspecialchars($sitePhone) ?></strong><span>Talk to sales directly</span>
                </a>
                <a class="mb-ccard is-wa" href="<?= htmlspecialchars('https://wa.me/' . $waNumber) ?>" target="_blank" rel="noopener" data-reveal="zoom" style="--rd:1">
                    <i class="fab fa-whatsapp"></i><small>WhatsApp</small>
                    <strong>Chat with us</strong><span>Message us anytime</span>
                </a>
                <a class="mb-ccard" href="mailto:<?= htmlspecialchars($siteEmail) ?>" data-reveal="zoom" style="--rd:2">
                    <i class="fas fa-envelope"></i><small>Email</small>
                    <strong><?= str_replace('@', '@<wbr>', htmlspecialchars($siteEmail)) ?></strong><span>Send us your questions</span>
                </a>
                <div class="mb-ccard" data-reveal="zoom" style="--rd:3">
                    <i class="fas fa-location-dot"></i><small>Office</small>
                    <strong>Nairobi, Kenya</strong><span><?= htmlspecialchars($siteHours) ?></span>
                </div>
            </div>

            <div class="mb-demo" id="demo" data-reveal="zoom">
                <div>
                    <h3>Book a demo</h3>
                    <p>See the dashboard, vouchers and M-Pesa payments working on a quick call.</p>
                </div>
                <div class="mb-demo-btns">
                    <a class="is-wa" href="<?= htmlspecialchars($waDemo) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Request on WhatsApp</a>
                    <a class="is-mail" href="<?= htmlspecialchars($mailDemo) ?>"><i class="fas fa-envelope"></i> Request by email</a>
                </div>
            </div>
        </div>
    </section>
    <!-- /CONTACT -->




    </main>

    <?php include_once('includes/footer.php'); ?>

    <script src="js/bootstrap.min.js"></script>

    <script>
    /* ════════════════════════════════════════════════
       0. PAGE MOTION — hero entrance after first paint,
       scroll reveals for [data-reveal], top progress bar.
    ════════════════════════════════════════════════ */
    (function () {
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        var hero = document.getElementById('home');
        if (hero) {
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { hero.classList.add('is-ready'); });
            });
        }

        var items = document.querySelectorAll('[data-reveal]');
        if (reduce || !('IntersectionObserver' in window)) {
            items.forEach(function (el) { el.classList.add('is-revealed'); });
        } else {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    (en.target.mbRevealItems || [en.target]).forEach(function (el) { el.classList.add('is-revealed'); });
                    io.unobserve(en.target);
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
            // A fully masked element never counts as intersecting, so masked items
            // are watched through their (unclipped) parent and reveal as a group.
            var groups = new Map();
            items.forEach(function (el) {
                var v = el.getAttribute('data-reveal');
                if (v === 'zoom' || v === 'right' || v === 'line') { io.observe(el); return; }
                if (!groups.has(el.parentElement)) groups.set(el.parentElement, []);
                groups.get(el.parentElement).push(el);
            });
            groups.forEach(function (list, parent) { parent.mbRevealItems = list; io.observe(parent); });
        }

        // Highlight the nav link for the section currently on screen
        var navLinks = document.querySelectorAll('.kc-nav-link[data-section]');
        if (navLinks.length && 'IntersectionObserver' in window) {
            var byId = {};
            navLinks.forEach(function (a) { byId[a.dataset.section] = a; });
            var spy = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting || !byId[en.target.id]) return;
                    navLinks.forEach(function (a) { a.classList.remove('active'); a.removeAttribute('aria-current'); });
                    byId[en.target.id].classList.add('active');
                    byId[en.target.id].setAttribute('aria-current', 'location');
                });
            }, { rootMargin: '-45% 0px -50% 0px' });
            Object.keys(byId).forEach(function (id) {
                var sec = document.getElementById(id);
                if (sec) spy.observe(sec);
            });
        }
        var bar = document.getElementById('mbProgress'), ticking = false;
        if (!bar) return;
        function paint() {
            var h = document.documentElement, max = h.scrollHeight - h.clientHeight;
            bar.style.transform = 'scaleX(' + (max > 0 ? h.scrollTop / max : 0) + ')';
            ticking = false;
        }
        window.addEventListener('scroll', function () {
            if (!ticking) { ticking = true; requestAnimationFrame(paint); }
        }, { passive: true });
        paint();
    })();

    /* ════════════════════════════════════════════════
       1. HERO SLIDER — the active progress bar's CSS fill
       animation drives autoplay; pausing it pauses the slider.
    ════════════════════════════════════════════════ */
    (function () {
        var hero = document.getElementById('home');
        if (!hero) return;
        var slides = hero.querySelectorAll('.mb-slide');
        var bars   = hero.querySelectorAll('.mb-bar');
        var num    = document.getElementById('mbHeroNum');
        if (slides.length < 2) return;

        var cur = 0;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            hero.classList.add('is-static');   // no autoplay
        }

        function go(n) {
            n = (n + slides.length) % slides.length;
            if (n === cur) return;
            slides[cur].classList.remove('is-active');
            slides[cur].setAttribute('aria-hidden', 'true');
            slides[cur].inert = true;
            slides[n].classList.add('is-active');
            slides[n].removeAttribute('aria-hidden');
            slides[n].inert = false;
            bars.forEach(function (b, i) {
                b.classList.toggle('is-done', i < n);
                b.classList.remove('is-active');
            });
            void bars[n].offsetWidth;          // restart the fill animation
            bars[n].classList.add('is-active');
            cur = n;
            if (num) num.textContent = ('0' + (n + 1)).slice(-2);
        }

        bars.forEach(function (b) {
            b.addEventListener('animationend', function () {
                if (b.classList.contains('is-active')) go(cur + 1);
            });
            b.addEventListener('click', function () { go(+b.dataset.go); });
        });
        hero.querySelectorAll('[data-dir]').forEach(function (btn) {
            btn.addEventListener('click', function () { go(cur + (+btn.dataset.dir)); });
        });

        // Pause while hovered, focused, or the tab is in the background
        var holds = {};
        function hold(reason, on) {
            holds[reason] = on;
            hero.classList.toggle('is-paused', !!(holds.hover || holds.focus || holds.hidden));
        }
        hero.addEventListener('mouseenter', function () { hold('hover', true); });
        hero.addEventListener('mouseleave', function () { hold('hover', false); });
        hero.addEventListener('focusin',    function () { hold('focus', true); });
        hero.addEventListener('focusout',   function (e) { if (!hero.contains(e.relatedTarget)) hold('focus', false); });
        document.addEventListener('visibilitychange', function () { hold('hidden', document.hidden); });

        hero.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft')  go(cur - 1);
            if (e.key === 'ArrowRight') go(cur + 1);
        });

        var x0 = null;
        hero.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
        hero.addEventListener('touchend', function (e) {
            if (x0 === null) return;
            var dx = e.changedTouches[0].clientX - x0;
            if (Math.abs(dx) > 50) go(cur + (dx < 0 ? 1 : -1));
            x0 = null;
        });
    })();

    /* ════════════════════════════════════════════════
       2. LIVE DASHBOARD PREVIEW — sample data, animates
       when scrolled into view, then "ticks" while visible.
    ════════════════════════════════════════════════ */
    (function () {
        var root = document.getElementById('mbDash');
        if (!root) return;
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var fmt = function (n, dec) {
            return Number(n).toLocaleString('en-KE', { minimumFractionDigits: dec || 0, maximumFractionDigits: dec || 0 });
        };
        var eat = function (d, opts) { return d.toLocaleString('en-KE', Object.assign({ timeZone: 'Africa/Nairobi' }, opts)); };

        // ── Header clock ──
        var nowEl = document.getElementById('mbDashNow');
        function clock() {
            nowEl.textContent = eat(new Date(), { month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) + ' EAT';
        }
        clock();

        // ── Revenue chart (last 14 days, smooth curve) ──
        var revenue = [31200, 28400, 35800, 33100, 39500, 42300, 38800, 36200, 41900, 45600, 43100, 47800, 46200, 48750];
        (function buildChart() {
            var svg = document.getElementById('mbChart'), ns = 'http://www.w3.org/2000/svg';
            var W = 600, H = 220, top = 14, bot = 10, left = 34;
            var max = 60000, min = 0;
            var pts = revenue.map(function (v, i) {
                return [left + i * (W - left) / (revenue.length - 1), top + (1 - (v - min) / (max - min)) * (H - top - bot)];
            });
            var d = 'M' + pts[0].join(',');
            for (var i = 1; i < pts.length; i++) {        // Catmull-Rom → cubic Bézier
                var p0 = pts[i - 2] || pts[i - 1], p1 = pts[i - 1], p2 = pts[i], p3 = pts[i + 1] || p2;
                d += ' C' + (p1[0] + (p2[0] - p0[0]) / 6) + ',' + (p1[1] + (p2[1] - p0[1]) / 6) + ' ' +
                            (p2[0] - (p3[0] - p1[0]) / 6) + ',' + (p2[1] - (p3[1] - p1[1]) / 6) + ' ' + p2.join(',');
            }
            var last = pts[pts.length - 1];
            var html = '<defs><linearGradient id="mbChartFill" x1="0" y1="0" x2="0" y2="1">' +
                       '<stop offset="0" stop-color="#38bdf8" stop-opacity=".35"/><stop offset="1" stop-color="#38bdf8" stop-opacity="0"/></linearGradient></defs><g class="mb-chart-grid">';
            [0, 20000, 40000, 60000].forEach(function (v) {
                var y = top + (1 - v / max) * (H - top - bot);
                html += '<line x1="' + left + '" x2="' + W + '" y1="' + y + '" y2="' + y + '"/>' +
                        '<text x="0" y="' + (y + 3) + '">' + (v / 1000) + 'K</text>';
            });
            html += '</g><path class="mb-chart-area" fill="url(#mbChartFill)" d="' + d + ' L' + last[0] + ',' + (H - bot) + ' L' + left + ',' + (H - bot) + ' Z"/>' +
                    '<path class="mb-chart-line" d="' + d + '"/>' +
                    '<circle class="mb-chart-halo" cx="' + last[0] + '" cy="' + last[1] + '" r="6"/>' +
                    '<circle class="mb-chart-dot" cx="' + last[0] + '" cy="' + last[1] + '" r="6"/>';
            svg.innerHTML = html;
            var line = svg.querySelector('.mb-chart-line');
            line.style.setProperty('--len', Math.ceil(line.getTotalLength()));

            var x = document.getElementById('mbChartX'), labels = '';
            [13, 10, 7, 3, 0].forEach(function (k) {   // oldest → today, left to right
                labels += '<span>' + (k === 0 ? 'Today' : eat(new Date(Date.now() - k * 864e5), { month: 'short', day: 'numeric' })) + '</span>';
            });
            x.innerHTML = labels;
        })();

        // ── Payments feed ──
        var names = ['Brian K.', 'Faith W.', 'Kevin O.', 'Mercy A.', 'Dennis M.', 'Grace N.', 'Collins K.', 'Purity J.', 'Victor O.', 'Esther C.', 'Samuel K.', 'Joy M.'];
        var plans = [['Hotspot 1 Hour', 20], ['Hotspot 1 Day', 50], ['Hotspot 1 Day', 50], ['Hotspot 1 Week', 300], ['PPPoE 10 Mbps', 1500], ['PPPoE 20 Mbps', 2500]];
        var feed = document.getElementById('mbFeed');
        var pick = function (a) { return a[Math.floor(Math.random() * a.length)]; };
        function paymentRow(minsAgo, isNew) {
            var plan = pick(plans), phone = '07' + Math.floor(10 + Math.random() * 89) + ' ••• ' + Math.floor(100 + Math.random() * 899);
            var li = document.createElement('li');
            if (isNew) li.className = 'is-new';
            li.innerHTML = '<span class="mb-feed-ico"><i class="fas fa-check"></i></span>' +
                '<span class="mb-feed-txt"><strong>' + pick(names) + ' · ' + plan[0] + '</strong>' +
                '<span>' + phone + ' · ' + eat(new Date(Date.now() - minsAgo * 6e4), { hour: '2-digit', minute: '2-digit' }) + '</span></span>' +
                '<span class="mb-feed-amt">+' + fmt(plan[1]) + '</span>';
            return { li: li, amount: plan[1] };
        }
        [1, 3, 6, 9].forEach(function (m) { feed.appendChild(paymentRow(m).li); });

        // ── Count-up numbers ──
        var earnEl = document.getElementById('mbEarnToday'), earnToday = +earnEl.dataset.count;
        function countUp() {
            var els = root.querySelectorAll('[data-count]');
            if (reduce) {
                els.forEach(function (el) { el.textContent = fmt(el.dataset.count, +el.dataset.dec || 0); });
                return;
            }
            var t0 = performance.now(), dur = 1600;
            (function frame(t) {
                var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
                els.forEach(function (el) { el.textContent = fmt(el.dataset.count * e, +el.dataset.dec || 0); });
                if (p < 1) requestAnimationFrame(frame);
            })(t0);
        }

        // ── Live "online right now" ──
        var online = 247;
        function setOnline(n) { root.querySelectorAll('[data-live-online]').forEach(function (el) { el.textContent = fmt(n); }); }

        // ── Live loop: only while the section is on screen and the tab is visible ──
        var onScreen = false, started = false, ticks = 0;
        setInterval(function () {
            if (!started || !onScreen || document.hidden) return;
            ticks++;
            clock();
            online = Math.max(200, online + Math.round(Math.random() * 6 - 3));
            setOnline(online);
            if (ticks % 2 === 0) {                       // new payment every ~3.6s
                var row = paymentRow(0, true);
                feed.insertBefore(row.li, feed.firstChild);
                if (feed.children.length > 4) feed.removeChild(feed.lastChild);
                setTimeout(function () { row.li.classList.remove('is-new'); }, 2400);
                earnToday += row.amount;
                earnEl.textContent = fmt(earnToday);
                earnEl.classList.add('is-bump');
                setTimeout(function () { earnEl.classList.remove('is-bump'); }, 900);
            }
        }, 1800);

        new IntersectionObserver(function (entries) {
            onScreen = entries[0].isIntersecting;
            if (onScreen && !started) {
                started = true;
                root.classList.add('is-in');
                countUp();
                setOnline(online);
            }
        }, { threshold: 0.2 }).observe(root);
    })();

    /* ════════════════════════════════════════════════
       2C. PRICING FLIP CARDS — tap/Enter/Space flips (hover
       flips on desktop via CSS); first card "peeks" once on reveal.
    ════════════════════════════════════════════════ */
    (function () {
        var sec = document.getElementById('pricing');
        if (!sec) return;
        var cards = sec.querySelectorAll('.mb-flip');

        function toggle(card) {
            var on = card.classList.toggle('is-flipped');
            card.setAttribute('aria-pressed', on ? 'true' : 'false');
        }
        cards.forEach(function (card) {
            card.addEventListener('click', function () { toggle(card); });
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(card); }
            });
            // on hover devices the CSS hover flip shows the back; un-pin on leave
            card.addEventListener('mouseleave', function () {
                if (window.matchMedia('(hover: hover)').matches && card.classList.contains('is-flipped')) toggle(card);
            });
        });

        new IntersectionObserver(function (entries, obs) {
            if (!entries[0].isIntersecting) return;
            obs.disconnect();
            sec.classList.add('is-in');
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            setTimeout(function () {
                cards[0].classList.add('is-peek');
                setTimeout(function () { cards[0].classList.remove('is-peek'); }, 1200);
            }, 1100);
        }, { threshold: 0.25 }).observe(sec);
    })();

    /* ════════════════════════════════════════════════
       2A. FEATURES — reveal + start illustrations on scroll
    ════════════════════════════════════════════════ */
    (function () {
        var sec = document.getElementById('features');
        if (!sec) return;
        new IntersectionObserver(function (entries, obs) {
            if (!entries[0].isIntersecting) return;
            obs.disconnect();
            sec.classList.add('is-in');
        }, { threshold: 0.15 }).observe(sec);
    })();

    /* ════════════════════════════════════════════════
       2A2. EARN · CONNECT · GROW — reveal; coins drop one by
       one onto the stack and the phone total counts up.
       (Connect + Grow drawings are pure CSS loops.)
    ════════════════════════════════════════════════ */
    (function () {
        var sec = document.getElementById('earn');
        if (!sec) return;
        var coins = Array.prototype.slice.call(sec.querySelectorAll('.mb-coin'));
        var plus  = document.getElementById('mbCoinPlus');
        var total = document.getElementById('mbCoinTotal');
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var onScreen = false, running = false, VALUE = 50;
        var show = function (n) { total.textContent = 'KES ' + n.toLocaleString('en-KE'); };

        function drop(i, done) {
            var c = coins[i];
            c.style.opacity = 1;
            c.animate([
                { transform: 'translateY(-230px)', easing: 'cubic-bezier(.55,0,1,.45)' },
                { transform: 'translateY(0)',      offset: .55, easing: 'ease-out' },
                { transform: 'translateY(-14px)',  offset: .72, easing: 'ease-in' },
                { transform: 'translateY(0)',      offset: .86, easing: 'ease-out' },
                { transform: 'translateY(-3px)',   offset: .93, easing: 'ease-in' },
                { transform: 'translateY(0)' }
            ], { duration: 800, fill: 'both' });
            setTimeout(function () {                    // coin lands
                show((i + 1) * VALUE);
                plus.setAttribute('y', 162 - i * 12);
                plus.animate([
                    { opacity: 0, transform: 'translateY(6px)' },
                    { opacity: 1, transform: 'translateY(0)', offset: .25 },
                    { opacity: 0, transform: 'translateY(-14px)' }
                ], { duration: 900, easing: 'ease-out' });
                done && done();
            }, 440);
        }

        function cycle() {
            if (!onScreen || document.hidden) { running = false; return; }
            running = true;
            coins.forEach(function (c) {           // clear last cycle's held animations
                c.getAnimations().forEach(function (a) { a.cancel(); });
                c.style.opacity = 0;
            });
            show(0);
            var i = 0;
            (function next() {
                if (i >= coins.length) {                // hold, fade the stack, repeat
                    setTimeout(function () {
                        coins.forEach(function (c) { c.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 400, fill: 'forwards' }); });
                        setTimeout(cycle, 600);
                    }, 1800);
                    return;
                }
                drop(i++, function () { setTimeout(next, 260); });
            })();
        }

        if (reduce) {
            coins.forEach(function (c) { c.style.opacity = 1; });
            show(coins.length * VALUE);
        }

        new IntersectionObserver(function (entries) {
            onScreen = entries[0].isIntersecting;
            if (onScreen) sec.classList.add('is-in');
            if (onScreen && !running && !reduce) cycle();
        }, { threshold: 0.2 }).observe(sec);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && onScreen && !running && !reduce) cycle();
        });
    })();

    </script>

    <script>
    // Fire-and-forget visit beacon -- after window.onload, not inline at
    // parse time, so this never competes with anything the page itself
    // needs to render or become interactive. See api/track_visit.php.
    window.addEventListener('load', function () {
        try {
            fetch('/api/track_visit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path: window.location.pathname }),
                keepalive: true,
            }).catch(function () {});
        } catch (e) {}
    });
    </script>

</body>
</html>
