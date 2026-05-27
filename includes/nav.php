<!-- ══════════════════════════════════════════════════
     KEYCHELE ENHANCED NAV — nav.php
     Two-tier: Search bar (top) + Main nav (bottom)
══════════════════════════════════════════════════ -->

<!-- ── SEARCH TIER ── -->
<div class="kc-search-bar">
    <div class="container">
        <div class="kc-search-inner">

            <!-- Search input + category -->
            <form class="kc-search-wrap" action="/events" method="GET" role="search">
                <svg class="kc-si" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" width="18" height="18" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                </svg>
                <input
                    class="kc-search-input"
                    type="search"
                    name="q"
                    id="navSearchInput"
                    placeholder="Search events, concerts, meetups in Kenya…"
                    autocomplete="off"
                    aria-label="Search events"
                    value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                <div class="kc-sdiv"></div>
                <select class="kc-search-cat" name="category" aria-label="Event category">
                    <option value="">All Categories</option>
                    <option value="music">Music &amp; Concerts</option>
                    <option value="sports">Sports</option>
                    <option value="comedy">Comedy</option>
                    <option value="tech">Tech &amp; Business</option>
                    <option value="arts">Arts &amp; Culture</option>
                    <option value="food">Food &amp; Drink</option>
                    <option value="fashion">Fashion</option>
                    <option value="gospel">Gospel</option>
                </select>
                <button type="submit" class="kc-search-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.5" width="14" height="14" aria-hidden="true">
                        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                    </svg>
                    <span class="d-none d-md-inline">Search Events</span>
                </button>
            </form>

            <!-- Trending quick tags -->
            <div class="kc-quick-tags d-none d-xl-flex">
                <span class="kc-quick-label">Trending:</span>
                <a href="/events?category=music"   class="kc-qtag">🎵 Music</a>
                <a href="/events?category=comedy"  class="kc-qtag">🎭 Comedy</a>
                <a href="/events?category=sports"  class="kc-qtag">🏆 Sports</a>
                <a href="/events?category=gospel"  class="kc-qtag">✝ Gospel</a>
            </div>

        </div>
    </div>
</div>


<!-- ── MAIN NAV ── -->
<nav class="kc-nav navbar navbar-expand-lg shadow-sm">
    <div class="container">

        <!-- Logo -->
        <a href="/index" class="navbar-brand kc-brand d-flex align-items-center">
            <img src="images/logo/KECHELE LOGO.png"
                 alt="Keychele Logo"
                 class="kc-logo-img me-2"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="kc-logo-fb me-2" style="display:none;">K</div>
            <span class="kc-brand-name">Keychele</span>
        </a>

        <!-- Mobile toggler -->
        <button class="navbar-toggler kc-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#kcNavLinks"
                aria-controls="kcNavLinks" aria-expanded="false" aria-label="Toggle navigation">
            <span class="kc-tog-bar"></span>
            <span class="kc-tog-bar"></span>
            <span class="kc-tog-bar"></span>
        </button>

        <!-- Nav links -->
        <div class="collapse navbar-collapse" id="kcNavLinks">
            <ul class="navbar-nav mx-auto align-items-lg-center gap-lg-1">
                <li class="nav-item">
                    <a class="kc-nav-link <?= (basename($_SERVER['PHP_SELF']) === 'index.php' || $_SERVER['REQUEST_URI'] === '/') ? 'active' : '' ?>"
                       href="/index">Home</a>
                </li>
                <li class="nav-item">
                    <a class="kc-nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/about') ? 'active' : '' ?>"
                       href="/about">About</a>
                </li>
                <li class="nav-item">
                    <a class="kc-nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/creators') ? 'active' : '' ?>"
                       href="/creators">Creators</a>
                </li>
                <li class="nav-item">
                    <a class="kc-nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/events') ? 'active' : '' ?>"
                       href="/events">Events</a>
                </li>
                <li class="nav-item">
                    <a class="kc-nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/contact') ? 'active' : '' ?>"
                       href="/contact">Contact</a>
                </li>
                
                <li class="nav-item">
                    <a class="kc-nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/contact') ? 'active' : '' ?>"
                       href="/terms-of-service">Terms of Service</a>
                </li>
            </ul>

            <!-- Action buttons -->
            <div class="kc-nav-actions d-flex align-items-center gap-2 mt-3 mt-lg-0">
                <a href="/login"    class="kc-btn-outline">Log In</a>
                <a href="/register" class="kc-btn-fill">
                    <span class="kc-gold-dot"></span> Get Started
                </a>
            </div>
        </div>

    </div>
</nav>


<!-- ══════════════════════════════════════════════════
     STYLES
══════════════════════════════════════════════════ -->
<style>
:root {
    --kc-red:    #b81236;
    --kc-red-d:  #96102d;
    --kc-gold:   #fdbd10;
    --kc-gold-d: #d9a70a;
}

/* ── SEARCH BAR TIER ── */
.kc-search-bar {
    background: #f8f8fb;
    border-bottom: 1px solid #ececf0;
    padding: 9px 0;
}
.kc-search-inner {
    display: flex;
    align-items: center;
    gap: 14px;
}
.kc-search-wrap {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fff;
    border: 1.5px solid #e0e0e8;
    border-radius: 50px;
    padding: 0 6px 0 16px;
    height: 44px;
    transition: border-color .2s, box-shadow .2s;
}
.kc-search-wrap:focus-within {
    border-color: var(--kc-red);
    box-shadow: 0 0 0 3px rgba(184,18,54,.09);
}
.kc-si { color: var(--kc-red); flex-shrink: 0; }
.kc-search-input {
    flex: 1;
    border: none;
    outline: none;
    font-size: 13.5px;
    color: #222;
    background: transparent;
    min-width: 0;
}
.kc-search-input::placeholder { color: #bbb; }
.kc-sdiv { width: 1px; height: 22px; background: #e5e5e5; flex-shrink: 0; }
.kc-search-cat {
    border: none;
    outline: none;
    background: transparent;
    font-size: 13px;
    color: #555;
    cursor: pointer;
    padding: 0 6px;
    min-width: 130px;
    max-width: 150px;
}
.kc-search-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    flex-shrink: 0;
    background: var(--kc-red);
    color: #fff;
    border: none;
    border-radius: 50px;
    padding: 0 20px;
    height: 36px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: background .2s, transform .15s;
    white-space: nowrap;
}
.kc-search-btn:hover {
    background: var(--kc-red-d);
    transform: translateY(-1px);
}
.kc-quick-tags {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.kc-quick-label {
    font-size: 12px;
    color: #aaa;
    white-space: nowrap;
    font-weight: 600;
}
.kc-qtag {
    font-size: 12px;
    color: #555;
    background: #fff;
    border: 1px solid #e5e5e8;
    border-radius: 20px;
    padding: 4px 12px;
    text-decoration: none;
    white-space: nowrap;
    transition: all .2s;
}
.kc-qtag:hover {
    border-color: var(--kc-red);
    color: var(--kc-red);
    background: #fff5f7;
}

/* ── MAIN NAV ── */
.kc-nav {
    background: #fff !important;
    padding-top: 0;
    padding-bottom: 0;
    border-bottom: 2px solid var(--kc-gold);
}
.kc-brand { text-decoration: none; }
.kc-logo-img {
    height: 52px;
    object-fit: contain;
    transition: transform .3s;
}
.kc-brand:hover .kc-logo-img { transform: scale(1.07); }
.kc-logo-fb {
    width: 46px; height: 46px;
    border-radius: 10px;
    background: var(--kc-red);
    color: var(--kc-gold);
    font-size: 22px;
    font-weight: 900;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.kc-brand-name {
    font-size: 22px;
    font-weight: 800;
    color: var(--kc-red);
    letter-spacing: -.5px;
}

/* Nav links */
.kc-nav-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 22px 14px;
    font-size: 14.5px;
    font-weight: 600;
    color: #222;
    text-decoration: none;
    position: relative;
    transition: color .2s;
}
.kc-nav-link::after {
    content: '';
    position: absolute;
    bottom: 0; left: 50%;
    transform: translateX(-50%);
    width: 0; height: 2.5px;
    background: var(--kc-red);
    border-radius: 2px;
    transition: width .25s;
}
.kc-nav-link:hover,
.kc-nav-link.active { color: var(--kc-red); }
.kc-nav-link:hover::after,
.kc-nav-link.active::after { width: 55%; }

/* Action buttons */
.kc-btn-outline {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 20px;
    border: 1.5px solid var(--kc-red);
    border-radius: 50px;
    color: var(--kc-red);
    font-size: 13.5px;
    font-weight: 700;
    background: transparent;
    text-decoration: none;
    transition: all .2s;
}
.kc-btn-outline:hover { background: #fff5f7; color: var(--kc-red); }

.kc-btn-fill {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 22px;
    border-radius: 50px;
    background: var(--kc-red);
    color: #fff;
    font-size: 13.5px;
    font-weight: 700;
    text-decoration: none;
    border: none;
    transition: all .2s;
}
.kc-btn-fill:hover {
    background: var(--kc-gold);
    color: #000;
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(184,18,54,.25);
}

.kc-gold-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--kc-gold);
    display: inline-block;
    flex-shrink: 0;
}

/* Custom hamburger */
.kc-toggler {
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    padding: 8px 10px;
    background: transparent;
    display: flex; flex-direction: column;
    gap: 5px; cursor: pointer;
}
.kc-tog-bar {
    width: 22px; height: 2px;
    background: var(--kc-red);
    border-radius: 2px;
    display: block;
    transition: all .3s;
}

/* ── RESPONSIVE ── */
@media (max-width: 991px) {
    .kc-nav-link { padding: 12px 16px; }
    .kc-nav-link::after { display: none; }
    .kc-nav-actions { justify-content: center; padding-bottom: 16px; }
    .navbar-collapse { border-top: 1px solid #f0f0f0; margin-top: 8px; }
}
@media (max-width: 767px) {
    .kc-search-input { font-size: 13px; }
    .kc-search-cat   { display: none; }
    .kc-sdiv         { display: none; }
    .kc-logo-img     { height: 40px; }
    .kc-brand-name   { font-size: 18px; }
}
</style>