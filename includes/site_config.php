<?php
// ── Site-wide settings for the billing marketing site ──
// Change these once here; header, nav and footer read from them.
$siteName      = $siteName      ?? 'Mecrofex Billing';
$siteTagline   = $siteTagline   ?? 'Smart billing for ISPs & hotspot networks';
$sitePhone     = $sitePhone     ?? '0791 011 130';
$sitePhoneTel  = $sitePhoneTel  ?? '+254791011130';
$siteEmail     = $siteEmail     ?? 'sales@mecrofexbilling.com';
$siteHours     = $siteHours     ?? 'Mon–Fri, 10:00–19:00';
$siteLogo      = $siteLogo      ?? 'https://ik.imagekit.io/mecrofextech/mecrofexbillinglogo.jpeg?tr=w-256';   // ImageKit-resized (original ~99 KB)
$appLoginUrl   = $appLoginUrl   ?? '/login';   // link to the billing system's client portal
// Footer social links: Bootstrap Icons name => URL. Empty URLs are skipped.
$siteSocials   = $siteSocials   ?? [
    'twitter-x' => '',
    'instagram' => '',
    'linkedin'  => '',
    'facebook'  => '',
];

// Link to a section of the homepage: "#id" when already on the homepage
// (scrolls in place), "/index#id" from any other page.
if (!function_exists('mb_section_url')) {
    function mb_section_url(string $id): string {
        $onHome = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__DIR__ . '/../index.php');
        return ($onHome ? '' : '/index') . '#' . $id;
    }
}

$appSignupUrl  = $appSignupUrl  ?? mb_section_url('contact');   // "Get Started" button: point at the billing system's sign-up page when it exists
