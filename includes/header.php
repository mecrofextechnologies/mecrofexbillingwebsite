<header class="site-header">

    <style>
        :root {
            --kc-red:  #b81236;
            --kc-gold: #fdbd10;
        }
        .site-header {
            background: var(--kc-red);
            border-bottom: 2px solid var(--kc-gold);
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            overflow: hidden;
            height: 44px;
            display: flex;
            align-items: center;
        }
        .kc-ticker-label {
            flex-shrink: 0;
            background: var(--kc-gold);
            color: var(--kc-red);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 0 16px;
            height: 100%;
            display: flex;
            align-items: center;
            border-right: 2px solid rgba(0,0,0,0.1);
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
            animation: kcScroll 32s linear infinite;
        }
        .kc-ticker-track:hover {
            animation-play-state: paused;
        }
        @keyframes kcScroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
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
        .kc-ticker-item a {
            color: var(--kc-gold);
            text-decoration: none;
            font-weight: 800;
        }
        .kc-ticker-item a:hover {
            text-decoration: underline;
            opacity: 0.85;
        }
        .kc-ticker-sep {
            color: rgba(253,189,16,0.45);
            font-size: 18px;
            padding: 0 4px;
            font-weight: 300;
        }
        .kc-ticker-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--kc-gold);
            display: inline-block;
            flex-shrink: 0;
        }
        .kc-live-pill {
            background: var(--kc-gold);
            color: var(--kc-red);
            font-size: 9px;
            font-weight: 900;
            padding: 2px 8px;
            border-radius: 20px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        @media (max-width: 768px) {
            .site-header { height: 36px; }
            .kc-ticker-label { display: none; }
            .kc-ticker-item { font-size: 11.5px; padding: 0 20px; }
        }
    </style>

    <div class="kc-ticker-label">KEYCHELE</div>

    <div class="kc-ticker-outer">
        <div class="kc-ticker-track">

            <!-- === SET 1 === -->
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Welcome to <strong style="color:#fdbd10;">Keychele</strong> &mdash; Kenya's creator monetization platform!</span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Become a Creator today <a href="https://keychele.co.ke/register">Register Free &rarr;</a></span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Post your events &amp; sell tickets easily <a href="https://keychele.co.ke/register">Get Started &rarr;</a></span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Fundraise, run competitions &amp; receive fan donations &mdash; all in one place</span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> M-Pesa integrated &amp; ready <span class="kc-live-pill">Live</span></span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Call us: <a href="tel:+254112680521">+254 112 680 521</a></span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Email: <a href="mailto:info@keychele.co.ke">info@keychele.co.ke</a></span>
            <span class="kc-ticker-sep">|</span>

            <!-- === SET 2 (duplicate for seamless loop) === -->
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Welcome to <strong style="color:#fdbd10;">Keychele</strong> &mdash; Kenya's creator monetization platform!</span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Become a Creator today <a href="https://keychele.co.ke/register">Register Free &rarr;</a></span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Post your events &amp; sell tickets easily <a href="https://keychele.co.ke/register">Get Started &rarr;</a></span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Fundraise, run competitions &amp; receive fan donations &mdash; all in one place</span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> M-Pesa integrated &amp; ready <span class="kc-live-pill">Live</span></span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Call us: <a href="tel:+254112680521">+254 112 680 521</a></span>
            <span class="kc-ticker-sep">|</span>
            <span class="kc-ticker-item"><span class="kc-ticker-dot"></span> Email: <a href="mailto:info@keychele.co.ke">info@keychele.co.ke</a></span>
            <span class="kc-ticker-sep">|</span>

        </div>
    </div>

</header>