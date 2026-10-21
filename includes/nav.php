<?php
include_once __DIR__ . '/site_config.php';

// ── Nav links (homepage section id => label). Order here = order on screen. ──
$navLinks = [
    'home'     => 'Home',
    'features' => 'Features',
    'pricing'  => 'Pricing',
    'market'   => 'Market',
    'about'    => 'About Us',
    'contact'  => 'Contact',
];
$navSoon = ['market'];   // shown with a "Soon" tag and not clickable yet
?>

<!-- ══════════════════════════════════════════════════
     MAIN NAV — nav.php
══════════════════════════════════════════════════ -->
<nav class="kc-nav navbar navbar-expand-lg">
    <div class="container">

        <!-- Logo -->
        <a href="<?= mb_section_url('home') ?>" class="navbar-brand kc-brand d-flex align-items-center">
            <img src="<?= htmlspecialchars($siteLogo) ?>"
                 alt="<?= htmlspecialchars($siteName) ?> logo"
                 class="kc-logo-img me-2"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="kc-logo-fb me-2" style="display:none;"><?= htmlspecialchars(strtoupper(substr($siteName, 0, 1))) ?></div>
            <span class="kc-brand-name"><?= htmlspecialchars($siteName) ?></span>
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
                <?php foreach ($navLinks as $id => $label): ?>
                <li class="nav-item">
                    <?php if (in_array($id, $navSoon, true)): ?>
                    <span class="kc-nav-link is-soon" aria-disabled="true"><?= $label ?> <em class="kc-soon">Soon</em></span>
                    <?php else: ?>
                    <a class="kc-nav-link<?= $id === 'home' ? ' active' : '' ?>" data-section="<?= $id ?>"
                       href="<?= mb_section_url($id) ?>"><?= $label ?></a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <!-- Action buttons -->
            <div class="kc-nav-actions d-flex align-items-center gap-2 mt-3 mt-lg-0">
                <a href="<?= htmlspecialchars($appLoginUrl) ?>" class="kc-btn-outline js-client-login">
                    <i class="bi bi-person"></i> ISP Login
                </a>
                <a href="<?= htmlspecialchars($appSignupUrl) ?>" class="kc-btn-outline">
                    <i class="bi bi-person-plus"></i> Register
                </a>
                <a href="<?= mb_section_url('contact') ?>" class="kc-btn-fill">
                    <span class="kc-dot"></span> Book a Demo
                </a>
            </div>
        </div>

    </div>
</nav>
<script>
// Close the mobile menu after a section link is tapped.
document.getElementById('kcNavLinks').addEventListener('click', function (e) {
    if (!e.target.closest('a') || !this.classList.contains('show') || !window.bootstrap) return;
    bootstrap.Collapse.getOrCreateInstance(this).hide();
});
</script>


<!-- ══════════════════════════════════════════════════
     STYLES
══════════════════════════════════════════════════ -->
<style>
:root {
    --bl-primary:   #0284c7;
    --bl-primary-d: #0369a1;
    --bl-accent:    #38bdf8;
    --bl-tint:      #e0f2fe;
    --bl-tint-2:    #f0f9ff;
}

/* ── MAIN NAV ── */
.kc-nav {
    background: #fff !important;
    padding-top: 0;
    padding-bottom: 0;
    border-bottom: 1px solid var(--bl-tint);
    box-shadow: 0 2px 14px rgba(2,132,199,.08);
    position: sticky;
    top: 0;
    z-index: 1030;
}
.kc-brand { text-decoration: none; }
.kc-logo-img {
    height: 48px;
    object-fit: contain;
    transition: transform .3s;
}
.kc-brand:hover .kc-logo-img { transform: scale(1.06); }
.kc-logo-fb {
    width: 44px; height: 44px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--bl-accent), var(--bl-primary));
    color: #fff;
    font-size: 22px;
    font-weight: 900;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.kc-brand-name {
    font-size: 21px;
    font-weight: 800;
    color: var(--bl-primary);
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
    color: #1e293b;
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
    background: var(--bl-primary);
    border-radius: 2px;
    transition: width .25s;
}
.kc-nav-link:hover,
.kc-nav-link.active { color: var(--bl-primary); }
.kc-nav-link:hover::after,
.kc-nav-link.active::after { width: 55%; }

/* "Coming soon" item */
.kc-nav-link.is-soon { color: #94a3b8; cursor: default; }
.kc-nav-link.is-soon:hover { color: #94a3b8; }
.kc-nav-link.is-soon::after { display: none; }
.kc-soon {
    font-style: normal;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .8px;
    text-transform: uppercase;
    color: var(--bl-primary);
    background: var(--bl-tint);
    padding: 2px 7px;
    border-radius: 20px;
}

/* Action buttons */
.kc-btn-outline {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 20px;
    border: 1.5px solid var(--bl-primary);
    border-radius: 50px;
    color: var(--bl-primary);
    font-size: 13.5px;
    font-weight: 700;
    background: transparent;
    text-decoration: none;
    transition: all .2s;
}
.kc-btn-outline:hover { background: var(--bl-tint-2); color: var(--bl-primary-d); }

.kc-btn-fill {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 22px;
    border-radius: 50px;
    background: var(--bl-primary);
    color: #fff;
    font-size: 13.5px;
    font-weight: 700;
    text-decoration: none;
    border: none;
    transition: all .2s;
}
.kc-btn-fill:hover {
    background: var(--bl-primary-d);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(2,132,199,.28);
}

.kc-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--bl-tint);
    display: inline-block;
    flex-shrink: 0;
}

/* Custom hamburger */
.kc-toggler {
    border: 1.5px solid var(--bl-tint);
    border-radius: 8px;
    padding: 8px 10px;
    background: transparent;
    display: flex; flex-direction: column;
    gap: 5px; cursor: pointer;
}
.kc-toggler:focus { box-shadow: 0 0 0 3px rgba(56,189,248,.3); }
.kc-tog-bar {
    width: 22px; height: 2px;
    background: var(--bl-primary);
    border-radius: 2px;
    display: block;
    transition: all .3s;
}

/* ── RESPONSIVE ── */
@media (max-width: 991px) {
    .kc-nav { padding: 8px 0; }
    .kc-nav-link { padding: 12px 16px; display: flex; border-radius: 10px; }
    .kc-nav-link::after { display: none; }
    .kc-nav-link.active { background: var(--bl-tint-2); }
    .kc-nav-actions { justify-content: center; padding-bottom: 16px; }
    .navbar-collapse { border-top: 1px solid var(--bl-tint); margin-top: 8px; }
}
@media (max-width: 767px) {
    .kc-logo-img   { height: 38px; }
    .kc-brand-name { font-size: 18px; }
}
</style>
