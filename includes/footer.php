<!-- ══════════════════════════════════════════════════
     KEYCHELE ENHANCED FOOTER — footer.php
══════════════════════════════════════════════════ -->

<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<style>
:root {
    --kc-red:    #b81236;
    --kc-red-d:  #96102d;
    --kc-gold:   #fdbd10;
    --kc-navy:   #052e64;
    --kc-navy-d: #021e44;
}

/* ── FOOTER SHELL ── */
.kc-footer {
    background: var(--kc-navy);
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
    background: var(--kc-gold);
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
    background: var(--kc-red);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 900; color: var(--kc-gold);
    flex-shrink: 0;
}
.kc-footer-logo img {
    width: 100%; height: 100%;
    object-fit: contain; border-radius: 10px;
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
    background: var(--kc-gold);
    border-color: var(--kc-gold);
    color: var(--kc-navy);
    transform: translateY(-3px);
    box-shadow: 0 6px 18px rgba(253,189,16,.25);
}

/* ── CONTACT ROWS ── */
.kc-contact-row {
    display: flex; align-items: flex-start;
    gap: 12px; margin-bottom: 16px;
}
.kc-contact-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: rgba(253,189,16,.1);
    border: 1px solid rgba(253,189,16,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: var(--kc-gold);
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
.kc-contact-val a:hover { color: var(--kc-gold); }

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
    color: var(--kc-gold);
    font-size: 17px; line-height: 1;
    transition: transform .2s;
}
.kc-links-list li a:hover {
    color: var(--kc-gold);
    padding-left: 6px;
}

/* ── NEWSLETTER ── */
.kc-news-desc {
    font-size: 13.5px;
    color: rgba(255,255,255,.58);
    line-height: 1.75; margin-bottom: 18px;
}
.kc-news-form {
    display: flex; flex-direction: column; gap: 10px;
}
.kc-news-input {
    width: 100%;
    padding: 11px 16px;
    border-radius: 10px;
    border: 1.5px solid rgba(255,255,255,.13);
    background: rgba(255,255,255,.06);
    color: #fff;
    font-size: 13.5px;
    outline: none;
    transition: border-color .2s, background .2s;
}
.kc-news-input::placeholder { color: rgba(255,255,255,.28); }
.kc-news-input:focus {
    border-color: var(--kc-gold);
    background: rgba(255,255,255,.09);
}
.kc-news-btn {
    width: 100%;
    padding: 12px 16px;
    border-radius: 10px;
    background: var(--kc-red);
    color: #fff;
    border: none;
    font-size: 13.5px; font-weight: 700;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all .2s;
}
.kc-news-btn:hover {
    background: var(--kc-gold);
    color: #000;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(184,18,54,.28);
}
.kc-news-note {
    font-size: 11.5px;
    color: rgba(255,255,255,.28);
    text-align: center; margin: 0;
}

/* ── DIVIDER ── */
.kc-footer-divider {
    border: none;
    border-top: 1px solid rgba(255,255,255,.07);
    margin: 52px 0 0;
}

/* ── BOTTOM BAR ── */
.kc-footer-bottom {
    background: var(--kc-navy-d);
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
    color: var(--kc-gold);
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
.kc-bottom-links a:hover { color: var(--kc-gold); }

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
                <img src="images/logo/KECHELE LOGO.png"
                     alt="Keychele"
                     onerror="this.style.display='none';this.parentElement.textContent='K'">
            </div>
            <span class="kc-footer-brand-name">Keychele</span>
        </div>
        <p class="kc-footer-about">
            We built Keychele to empower creators with the right tools to monetize their
            crafts and create exceptional social experiences across Kenya.
        </p>
        <div class="kc-footer-live">Platform Live</div>
        <div class="kc-socials">
            <a href="https://facebook.com/keychele" class="kc-soc" target="_blank" rel="noopener" aria-label="Facebook">
                <i class="bi bi-facebook"></i>
            </a>
            <a href="https://twitter.com/keychele" class="kc-soc" target="_blank" rel="noopener" aria-label="Twitter / X">
                <i class="bi bi-twitter-x"></i>
            </a>
            <a href="https://instagram.com/keycheleke" class="kc-soc" target="_blank" rel="noopener" aria-label="Instagram">
                <i class="bi bi-instagram"></i>
            </a>
            <a href="https://tiktok.com/@keychele" class="kc-soc" target="_blank" rel="noopener" aria-label="TikTok">
                <i class="bi bi-tiktok"></i>
            </a>
            <a href="https://youtube.com/@keychele" class="kc-soc" target="_blank" rel="noopener" aria-label="YouTube">
                <i class="bi bi-youtube"></i>
            </a>
        </div>
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
                    <a href="tel:+254112680521">+254 112 680 521</a>
                </div>
            </div>
        </div>

        <div class="kc-contact-row">
            <div class="kc-contact-icon"><i class="bi bi-envelope"></i></div>
            <div>
                <div class="kc-contact-label">Email</div>
                <div class="kc-contact-val">
                    <a href="mailto:info@keychele.co.ke">info@keychele.co.ke</a>
                </div>
            </div>
        </div>

        <div class="kc-contact-row">
            <div class="kc-contact-icon"><i class="bi bi-clock"></i></div>
            <div>
                <div class="kc-contact-label">Hours</div>
                <div class="kc-contact-val">Mon–Fri, 10:00–19:00</div>
            </div>
        </div>
    </div>

    <!-- ── Col 3: Quick Links ── -->
    <div>
        <h5 class="kc-footer-heading">Quick Links</h5>
        <ul class="kc-links-list">
            <li><a href="/index">Home</a></li>
            <li><a href="/about">About Us</a></li>
            <li><a href="/creators">Creators</a></li>
            <li><a href="/events">Events</a></li>
            <li><a href="/contact">Contact</a></li>
            <li><a href="/register">Become a Creator</a></li>
            <li><a href="/privacy">Privacy Policy</a></li>
            <li><a href="/terms">Terms of Service</a></li>
        </ul>
    </div>

    <!-- ── Col 4: Newsletter ── -->
    <div>
        <h5 class="kc-footer-heading">Newsletter</h5>
        <p class="kc-news-desc">
            Get the latest events, creator highlights and platform updates delivered to your inbox.
        </p>
        <form class="kc-news-form" action="/newsletter/subscribe" method="POST">
            <?php if(function_exists('csrf_field')) echo csrf_field(); ?>
            <input class="kc-news-input" type="text"   name="name"  placeholder="Your full name"     required>
            <input class="kc-news-input" type="email"  name="email" placeholder="Your email address" required>
            <button type="submit" class="kc-news-btn">
                <i class="bi bi-send"></i> Subscribe Now
            </button>
            <p class="kc-news-note">No spam. Unsubscribe anytime.</p>
        </form>
    </div>

</div><!-- /kc-footer-grid -->

<hr class="kc-footer-divider">
</div><!-- /container -->


<!-- ── BOTTOM BAR ── -->
<div class="kc-footer-bottom">
<div class="container">
<div class="kc-footer-bottom-inner">

    <p>&copy; <?php echo date('Y'); ?> Keychele. All Rights Reserved.</p>

    <div class="kc-bottom-links">
        <a href="/privacy">Privacy</a>
        <a href="/terms">Terms</a>
        <a href="/contact">Support</a>
    </div>

    <p>
        Designed &amp; developed by
        <a href="#" id="mecrofex-link">Mecrofex Technologies</a>
    </p>

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
    background: var(--kc-navy, #052e64);
    border: 1.5px solid var(--kc-gold, #fdbd10);
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
    color: var(--kc-gold, #fdbd10);
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
.mecrofex-website-btn  { background: var(--kc-gold, #fdbd10); color: #032d5d; }
.mecrofex-website-btn:hover  { background: #fff; color: #032d5d; }
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