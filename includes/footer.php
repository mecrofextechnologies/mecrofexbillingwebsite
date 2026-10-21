<?php include_once __DIR__ . '/site_config.php'; ?>
<!-- ══════════════════════════════════════════════════
     MAIN FOOTER — footer.php
══════════════════════════════════════════════════ -->

<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<style>
:root {
    --bl-primary:   #0284c7;
    --bl-primary-d: #0369a1;
    --bl-accent:    #38bdf8;
    --bl-tint:      #e0f2fe;
    --bl-tint-2:    #f0f9ff;
    --bl-deep:      #0c4a6e;   /* footer background */
    --bl-deep-d:    #082f49;   /* bottom bar */
}

/* ── FOOTER SHELL ── */
.kc-footer {
    background: var(--bl-deep);
    color: #fff;
    padding: 64px 0 0;
    font-family: 'Montserrat', system-ui, sans-serif;
}

/* ── GRID ── */
.kc-footer-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr 1fr 1.3fr;
    gap: 48px;
}

/* ── SECTION HEADINGS ── */
.kc-footer-heading {
    font-size: 14.5px;
    font-weight: 800;
    color: #fff;
    margin-bottom: 22px;
    padding-bottom: 10px;
    position: relative;
    letter-spacing: .3px;
}
.kc-footer-heading::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0;
    width: 36px; height: 3px;
    background: var(--bl-accent);
    border-radius: 2px;
}

/* ── BRAND BLOCK ── */
.kc-footer-brand-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.kc-footer-logo {
    width: 46px; height: 46px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--bl-accent), var(--bl-primary));
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 900; color: #fff;
    flex-shrink: 0;
}
.kc-footer-logo img {
    width: 100%; height: 100%;
    object-fit: contain; border-radius: 10px;
    background: #fff;   /* logo is a white-background JPEG */
}
.kc-footer-brand-name {
    font-size: 22px; font-weight: 900;
    color: #fff; letter-spacing: -.5px;
}
.kc-footer-about {
    font-size: 13.5px;
    color: rgba(255,255,255,.62);
    line-height: 1.78;
    margin-bottom: 18px;
}
.kc-footer-live {
    display: inline-flex; align-items: center; gap: 7px;
    background: rgba(22,163,74,.13);
    border: 1px solid rgba(22,163,74,.28);
    color: #4ade80;
    font-size: 10.5px; font-weight: 700;
    padding: 4px 12px; border-radius: 20px;
    letter-spacing: .5px; text-transform: uppercase;
    margin-bottom: 20px;
}
.kc-footer-live::before {
    content: '';
    width: 7px; height: 7px;
    border-radius: 50%; background: #4ade80;
    animation: kcLivePulse 1.6s ease-in-out infinite;
}
@keyframes kcLivePulse {
    0%,100% { opacity: 1; }
    50%      { opacity: .25; }
}

/* ── SOCIAL ICONS ── */
.kc-socials {
    display: flex; gap: 10px;
}
.kc-soc {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 17px;
    text-decoration: none;
    transition: all .25s;
}
.kc-soc:hover {
    background: var(--bl-accent);
    border-color: var(--bl-accent);
    color: var(--bl-deep-d);
    transform: translateY(-3px);
    box-shadow: 0 6px 18px rgba(56,189,248,.25);
}

/* ── CONTACT ROWS ── */
.kc-contact-row {
    display: flex; align-items: flex-start;
    gap: 12px; margin-bottom: 16px;
}
.kc-contact-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: rgba(56,189,248,.1);
    border: 1px solid rgba(56,189,248,.22);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: var(--bl-accent);
    flex-shrink: 0;
}
.kc-contact-label {
    font-size: 10px; color: rgba(255,255,255,.38);
    font-weight: 700; letter-spacing: 1px;
    text-transform: uppercase; margin-bottom: 3px;
}
.kc-contact-val {
    font-size: 13.5px; color: rgba(255,255,255,.82);
}
.kc-contact-val a {
    color: rgba(255,255,255,.82);
    text-decoration: none; transition: color .2s;
}
.kc-contact-val a:hover { color: var(--bl-accent); }

/* ── QUICK LINKS ── */
.kc-links-list {
    list-style: none; padding: 0; margin: 0;
    display: flex; flex-direction: column; gap: 2px;
}
.kc-links-list li a {
    display: flex; align-items: center; gap: 9px;
    font-size: 13.5px; color: rgba(255,255,255,.62);
    text-decoration: none;
    padding: 7px 0;
    border-bottom: 1px solid rgba(255,255,255,.05);
    transition: all .2s;
}
.kc-links-list li a::before {
    content: '›';
    color: var(--bl-accent);
    font-size: 17px; line-height: 1;
    transition: transform .2s;
}
.kc-links-list li a:hover {
    color: var(--bl-accent);
    padding-left: 6px;
}

/* ── GET STARTED (col 4) ── */
.kc-start-desc {
    font-size: 13.5px;
    color: rgba(255,255,255,.58);
    line-height: 1.75; margin-bottom: 18px;
}
.kc-start-btns { display: flex; flex-direction: column; gap: 10px; }
.kc-start-btn {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13.5px; font-weight: 700;
    text-decoration: none;
    transition: all .2s;
}
.kc-start-btn.is-main { background: var(--bl-primary); color: #fff; }
.kc-start-btn.is-main:hover {
    background: var(--bl-accent);
    color: var(--bl-deep-d);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(2,132,199,.35);
}
.kc-start-btn.is-wa { border: 1.5px solid rgba(74,222,128,.45); color: #86efac; }
.kc-start-btn.is-wa:hover { background: rgba(74,222,128,.12); color: #bbf7d0; }

/* ── DIVIDER ── */
.kc-footer-divider {
    border: none;
    border-top: 1px solid rgba(255,255,255,.07);
    margin: 52px 0 0;
}

/* ── BOTTOM BAR ── */
.kc-footer-bottom {
    background: var(--bl-deep-d);
    padding: 20px 0;
    margin-top: 0;
}
.kc-footer-bottom-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.kc-footer-bottom p {
    font-size: 13px;
    color: rgba(255,255,255,.42);
    margin: 0;
}
.kc-footer-bottom a {
    color: var(--bl-accent);
    text-decoration: none;
    font-weight: 700;
    transition: opacity .2s;
}
.kc-footer-bottom a:hover { opacity: .75; }
.kc-bottom-links {
    display: flex; gap: 22px;
}
.kc-bottom-links a {
    font-size: 12.5px;
    color: rgba(255,255,255,.38);
    text-decoration: none;
    font-weight: 600;
    transition: color .2s;
}
.kc-bottom-links a:hover { color: var(--bl-accent); }

/* ── RESPONSIVE ── */
@media (max-width: 1024px) {
    .kc-footer-grid {
        grid-template-columns: 1fr 1fr;
        gap: 36px;
    }
}
@media (max-width: 600px) {
    .kc-footer { padding: 48px 0 0; }
    .kc-footer-grid {
        grid-template-columns: 1fr;
        gap: 32px;
    }
    .kc-footer-bottom-inner {
        flex-direction: column;
        text-align: center;
    }
    .kc-bottom-links { justify-content: center; }
}
</style>


<!-- ── FOOTER MAIN ── -->
<footer class="kc-footer">
<div class="container">
<div class="kc-footer-grid">

    <!-- ── Col 1: About ── -->
    <div>
        <div class="kc-footer-brand-wrap">
            <div class="kc-footer-logo">
                <img src="<?= htmlspecialchars($siteLogo) ?>"
                     alt="<?= htmlspecialchars($siteName) ?>"
                     onerror="this.style.display='none';this.parentElement.textContent='<?= htmlspecialchars(strtoupper(substr($siteName, 0, 1))) ?>'">
            </div>
            <span class="kc-footer-brand-name"><?= htmlspecialchars($siteName) ?></span>
        </div>
        <p class="kc-footer-about">
            <?= htmlspecialchars($siteTagline) ?>. Automate customer billing, M-Pesa payments,
            hotspot vouchers and PPPoE accounts from one dashboard.
        </p>
        <div class="kc-footer-live">All Systems Operational</div>
        <?php $footerSocials = array_filter($siteSocials ?? []); if ($footerSocials): ?>
        <div class="kc-socials">
            <?php foreach ($footerSocials as $icon => $url): ?>
            <a href="<?= htmlspecialchars($url) ?>" class="kc-soc" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars($icon) ?>">
                <i class="bi bi-<?= htmlspecialchars($icon) ?>"></i>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Col 2: Contact ── -->
    <div>
        <h5 class="kc-footer-heading">Contact Us</h5>

        <div class="kc-contact-row">
            <div class="kc-contact-icon"><i class="bi bi-geo-alt"></i></div>
            <div>
                <div class="kc-contact-label">Location</div>
                <div class="kc-contact-val">Nairobi, Kenya</div>
            </div>
        </div>

        <div class="kc-contact-row">
            <div class="kc-contact-icon"><i class="bi bi-telephone"></i></div>
            <div>
                <div class="kc-contact-label">Phone</div>
                <div class="kc-contact-val">
                    <a href="tel:<?= htmlspecialchars($sitePhoneTel) ?>"><?= htmlspecialchars($sitePhone) ?></a>
                </div>
            </div>
        </div>

        <div class="kc-contact-row">
            <div class="kc-contact-icon"><i class="bi bi-envelope"></i></div>
            <div>
                <div class="kc-contact-label">Email</div>
                <div class="kc-contact-val">
                    <a href="mailto:<?= htmlspecialchars($siteEmail) ?>"><?= htmlspecialchars($siteEmail) ?></a>
                </div>
            </div>
        </div>

        <div class="kc-contact-row">
            <div class="kc-contact-icon"><i class="bi bi-clock"></i></div>
            <div>
                <div class="kc-contact-label">Hours</div>
                <div class="kc-contact-val"><?= htmlspecialchars($siteHours) ?></div>
            </div>
        </div>
    </div>

    <!-- ── Col 3: Quick Links ── -->
    <div>
        <h5 class="kc-footer-heading">Quick Links</h5>
        <ul class="kc-links-list">
            <li><a href="<?= mb_section_url('home') ?>">Home</a></li>
            <li><a href="<?= mb_section_url('features') ?>">Features</a></li>
            <li><a href="<?= mb_section_url('pricing') ?>">Pricing</a></li>
            <li><a href="<?= mb_section_url('about') ?>">About Us</a></li>
            <li><a href="<?= mb_section_url('contact') ?>">Contact</a></li>
            <li><a href="<?= mb_section_url('demo') ?>">Book a Demo</a></li>
            <li><a href="<?= htmlspecialchars($appSignupUrl) ?>">Register</a></li>
            <li><a href="<?= htmlspecialchars($appLoginUrl) ?>" class="js-client-login">ISP Login</a></li>
        </ul>
    </div>

    <!-- ── Col 4: Get started ── -->
    <div>
        <h5 class="kc-footer-heading">Get Started</h5>
        <p class="kc-start-desc">
            See how <?= htmlspecialchars($siteName) ?> can automate billing for your hotspot and PPPoE customers.
        </p>
        <div class="kc-start-btns">
            <a class="kc-start-btn is-main" href="<?= mb_section_url('demo') ?>"><i class="bi bi-calendar-check"></i> Book a Demo</a>
            <a class="kc-start-btn is-wa" href="https://wa.me/<?= preg_replace('/\D/', '', $sitePhoneTel) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Chat on WhatsApp</a>
        </div>
    </div>
</div><!-- /kc-footer-grid -->

<hr class="kc-footer-divider">
</div><!-- /container -->


<!-- ── BOTTOM BAR ── -->
<div class="kc-footer-bottom">
<div class="container">
<div class="kc-footer-bottom-inner">

    <p>&copy; <?php echo date('Y'); ?> <?= htmlspecialchars($siteName) ?>. All Rights Reserved.</p>

    <div class="kc-bottom-links">
        <a href="<?= mb_section_url('contact') ?>">Support</a>
    </div>

    <!--
<p>
    Designed &amp; developed by
    <a href="#" id="mecrofex-link">Mecrofex Technologies</a>
</p>
-->
</div>
</div>
</div>

</footer>


<!-- ══════════════════════════════════════════════════
     MECROFEX MODAL (preserved from original)
══════════════════════════════════════════════════ -->
<div class="mecrofex-modal" id="mecrofex-modal">
    <div class="mecrofex-modal-content">
        <span class="mecrofex-close">&times;</span>
        <h4 class="text-white mb-2">Contact Mecrofex Technologies</h4>
        <p style="color:rgba(255,255,255,.6);margin-bottom:24px;">How would you like to connect with us?</p>
        <div class="mecrofex-modal-buttons">
            <a href="https://mecrofex.co.ke" target="_blank" rel="noopener" class="mecrofex-btn mecrofex-website-btn">
                <i class="bi bi-globe2 me-2"></i> Visit Our Website
            </a>
            <a href="https://wa.me/254791011130" target="_blank" rel="noopener" class="mecrofex-btn mecrofex-whatsapp-btn">
                <i class="bi bi-whatsapp me-2"></i> Chat on WhatsApp
            </a>
        </div>
    </div>
</div>

<style>
.mecrofex-modal {
    display: none; position: fixed; z-index: 1050;
    inset: 0; background: rgba(0,0,0,.72);
}
.mecrofex-modal-content {
    background: var(--bl-deep, #0c4a6e);
    border: 1.5px solid var(--bl-accent, #38bdf8);
    border-radius: 16px;
    margin: 14% auto;
    padding: 32px 28px;
    width: 90%; max-width: 420px;
    text-align: center;
    position: relative;
    box-shadow: 0 20px 60px rgba(0,0,0,.45);
}
.mecrofex-close {
    position: absolute; top: 12px; right: 18px;
    color: var(--bl-accent, #38bdf8);
    font-size: 28px; font-weight: 700; cursor: pointer;
    line-height: 1; transition: color .2s;
}
.mecrofex-close:hover { color: #fff; }
.mecrofex-modal-buttons { display: flex; flex-direction: column; gap: 12px; }
.mecrofex-btn {
    padding: 13px 20px; border-radius: 10px;
    font-weight: 700; font-size: 14px;
    text-decoration: none; transition: all .25s;
    display: flex; align-items: center; justify-content: center;
}
.mecrofex-website-btn  { background: var(--bl-accent, #38bdf8); color: #082f49; }
.mecrofex-website-btn:hover  { background: #fff; color: #082f49; }
.mecrofex-whatsapp-btn { background: #25D366; color: #fff; }
.mecrofex-whatsapp-btn:hover { background: #128C7E; }
</style>

<script>
(function(){
    const link  = document.getElementById('mecrofex-link');
    const modal = document.getElementById('mecrofex-modal');
    const close = document.querySelector('.mecrofex-close');
    if(link)  link.addEventListener('click', function(e){ e.preventDefault(); modal.style.display='block'; });
    if(close) close.addEventListener('click', function(){ modal.style.display='none'; });
    window.addEventListener('click', function(e){ if(e.target===modal) modal.style.display='none'; });
})();
</script>

<!-- ══════════════════════════════════════════════════
     CLIENT LOGIN MODAL — each ISP has its own subdomain
     (e.g. medolistnet.mecrofexbilling.com), so "Client Login"
     asks which one before sending them there.
══════════════════════════════════════════════════ -->
<div class="mecrofex-modal" id="client-login-modal">
    <div class="mecrofex-modal-content">
        <span class="mecrofex-close" id="client-login-close">&times;</span>
        <h4 class="text-white mb-2">ISP Login</h4>
        <p style="color:rgba(255,255,255,.6);margin-bottom:20px;">Enter your ISP's name to go to its login page.</p>
        <form id="client-login-form">
            <input type="text" id="client-login-ispname" placeholder="e.g. Medolistnet" autocomplete="off"
                   style="width:100%;padding:12px 14px;border-radius:10px;border:1.5px solid var(--bl-accent,#38bdf8);background:rgba(255,255,255,.08);color:#fff;font-size:14px;margin-bottom:8px;">
            <p id="client-login-error" style="color:#fca5a5;font-size:12.5px;margin-bottom:10px;display:none;">Please enter your ISP's name.</p>
            <button type="submit" class="mecrofex-btn mecrofex-website-btn" style="width:100%;border:none;cursor:pointer;">
                <i class="bi bi-arrow-right-circle me-2"></i> Continue
            </button>
        </form>
    </div>
</div>

<script>
(function(){
    const BILLING_DOMAIN = 'mecrofexbilling.com';
    const triggers = document.querySelectorAll('.js-client-login');
    const modal    = document.getElementById('client-login-modal');
    const closeBtn = document.getElementById('client-login-close');
    const form     = document.getElementById('client-login-form');
    const input    = document.getElementById('client-login-ispname');
    const error    = document.getElementById('client-login-error');
    if (!triggers.length || !modal || !form || !input) return;

    triggers.forEach(function(t){
        t.addEventListener('click', function(e){
            e.preventDefault();
            error.style.display = 'none';
            modal.style.display = 'block';
            input.value = '';
            setTimeout(function(){ input.focus(); }, 50);
        });
    });
    closeBtn.addEventListener('click', function(){ modal.style.display = 'none'; });
    window.addEventListener('click', function(e){ if (e.target === modal) modal.style.display = 'none'; });

    form.addEventListener('submit', function(e){
        e.preventDefault();
        const slug = input.value.toLowerCase().trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        if (!slug) {
            error.style.display = 'block';
            input.focus();
            return;
        }
        window.location.href = 'https://' + slug + '.' + BILLING_DOMAIN + '/login';
    });
})();
</script>
