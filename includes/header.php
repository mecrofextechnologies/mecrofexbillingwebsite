<?php include_once __DIR__ . '/site_config.php'; ?>
<header class="site-header">

    <style>
        :root {
            --bl-primary:   #0284c7;   /* main light-blue (text-safe on white) */
            --bl-primary-d: #0369a1;   /* hover / pressed */
            --bl-accent:    #38bdf8;   /* bright sky accent */
            --bl-tint:      #e0f2fe;   /* soft blue backgrounds */
            --bl-tint-2:    #f0f9ff;   /* lightest wash */
        }
        .site-header {
            background: linear-gradient(90deg, var(--bl-primary-d), var(--bl-primary) 55%, #0ea5e9);
            border-bottom: 1px solid rgba(255,255,255,.25);
            overflow: hidden;
            height: 40px;
            display: flex;
            align-items: center;
        }
        .kc-ticker-label {
            flex-shrink: 0;
            background: #fff;
            color: var(--bl-primary);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 0 16px;
            height: 100%;
            display: flex;
            align-items: center;
            white-space: nowrap;
        }
        .kc-ticker-outer {
            flex: 1;
            overflow: hidden;
            position: relative;
        }
        .kc-ticker-track {
            display: flex;
            align-items: center;
            white-space: nowrap;
            animation: kcScroll 36s linear infinite;
        }
        .kc-ticker-track:hover {
            animation-play-state: paused;
        }
        @keyframes kcScroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        @media (prefers-reduced-motion: reduce) {
            .kc-ticker-track { animation: none; }
        }
        .kc-ticker-item {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 0 28px;
            font-size: 12.5px;
            font-weight: 600;
            color: #fff;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        .kc-ticker-item strong { color: #fff; font-weight: 800; }
        .kc-ticker-item a {
            color: #fff;
            text-decoration: underline;
            text-underline-offset: 3px;
            font-weight: 800;
        }
        .kc-ticker-item a:hover { opacity: 0.85; }
        .kc-ticker-sep {
            color: rgba(255,255,255,.4);
            font-size: 18px;
            padding: 0 4px;
            font-weight: 300;
        }
        .kc-ticker-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #fff;
            display: inline-block;
            flex-shrink: 0;
        }
        .kc-live-pill {
            background: #fff;
            color: var(--bl-primary);
            font-size: 9px;
            font-weight: 900;
            padding: 2px 8px;
            border-radius: 20px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        @media (max-width: 768px) {
            .site-header { height: 34px; }
            .kc-ticker-label { display: none; }
            .kc-ticker-item { font-size: 11.5px; padding: 0 20px; }
        }
    </style>

    <div class="kc-ticker-label"><?= htmlspecialchars($siteName) ?></div>

    <div class="kc-ticker-outer">
        <div class="kc-ticker-track">
            <?php
            $tickerItems = [
                'Welcome to <strong>' . htmlspecialchars($siteName) . '</strong> &mdash; ' . htmlspecialchars($siteTagline),
                'Automatic M-Pesa payments &amp; instant internet activation <span class="kc-live-pill">Live</span>',
                'Hotspot &amp; PPPoE billing, vouchers and customer portal in one system',
                'Routers, access points &amp; networking gear &mdash; <strong>Market coming soon</strong>',
                'Plans that grow with your network <a href="' . mb_section_url('pricing') . '">See Pricing &rarr;</a>',
                'Call us: <a href="tel:' . htmlspecialchars($sitePhoneTel) . '">' . htmlspecialchars($sitePhone) . '</a>',
                'Email: <a href="mailto:' . htmlspecialchars($siteEmail) . '">' . htmlspecialchars($siteEmail) . '</a>',
            ];
            // Rendered twice so the -50% scroll loops seamlessly
            for ($set = 0; $set < 2; $set++):
                foreach ($tickerItems as $item): ?>
            <span class="kc-ticker-item"<?= $set ? ' aria-hidden="true"' : '' ?>><span class="kc-ticker-dot"></span> <?= $item ?></span>
            <span class="kc-ticker-sep" aria-hidden="true">|</span>
            <?php endforeach;
            endfor; ?>
        </div>
    </div>

</header>
