<?php
/*
 * The site's main page, for visitors (GROUP_VISITS.md, "Getting the app"): how to get the ISATU
 * Visits app and install it, what to do without an Android phone, and "Staff sign in" for staff.
 * The root index.php shows it, so every link here is relative to the site's main address.
 * The download is the version the Admin published in Admin › Menu › Visitor app.
 */
if (realpath((string) ($_SERVER["SCRIPT_FILENAME"] ?? "")) === __FILE__) {
    header("Location: ../");
    exit;
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/app_release_service.php";

$release = app_release_current($conn);
$siteUrl = app_release_site_url();
$conn->close();

header("Content-Type: text/html; charset=utf-8");
header("X-Frame-Options: DENY");
header("Content-Security-Policy: frame-ancestors 'none'");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: no-cache");

$h = fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
$version = $release ? (string) $release["version_name"] : "";
$size = $release ? app_release_size_label((int) $release["file_size"]) : "";
$whatsNew = $release ? trim((string) $release["whats_new"]) : "";
$asset = "phone_tracker/assets/images/";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#003B82">
<meta name="description" content="Visiting Iloilo Science and Technology University? Get the ISATU Visits app to book your visit and show your pass at the gate.">
<title>ISATU Visits · Book your campus visit</title>
<link rel="icon" href="<?= $asset ?>isatu-seal-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
<style>
:root {
    --navy: #003b82;
    --blue: #0757b9;
    --blue-dark: #06357a;
    --gold: #ffcb2f;
    --ink: #172033;
    --body: #3d4b5f;
    --muted: #64748b;
    --line: #d9e1ec;
    --wash: #f4f7fc;
    --soft: #e8f0fd;
    --green: #127a43;
}
* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body { margin: 0; background: var(--wash); color: var(--ink); font-family: Poppins, Arial, sans-serif; line-height: 1.5; }
a { color: var(--blue); }
img { max-width: 100%; }
.wrap { width: min(1120px, 100%); margin: 0 auto; padding: 0 16px; }

/* Top */
.ga-top { position: absolute; top: 0; left: 0; right: 0; z-index: 3; }
.ga-top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-top: 14px; padding-bottom: 14px; }
.ga-brand { display: flex; align-items: center; gap: 10px; color: #fff; font-size: 17px; font-weight: 700; text-decoration: none; }
.ga-brand img { width: 40px; height: 40px; padding: 2px; border-radius: 50%; background: #fff; box-shadow: 0 4px 12px rgba(0, 0, 0, .25); }
.ga-staff { display: inline-flex; min-height: 40px; align-items: center; padding: 0 14px; border: 1px solid rgba(255, 255, 255, .45); border-radius: 999px; color: #fff; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap; }
.ga-staff:hover { background: rgba(255, 255, 255, .12); }

/* Hero */
.ga-hero { position: relative; overflow: hidden; padding: 86px 0 34px; background: var(--navy); color: #fff; border-bottom: 4px solid var(--gold); }
.ga-hero::before { content: ""; position: absolute; inset: -12px; background: url("<?= $asset ?>get-app/campus-front.jpg") center 30% / cover no-repeat; filter: blur(3px) saturate(.9); opacity: .2; }
.ga-hero::after { content: ""; position: absolute; right: -90px; top: 40px; width: 300px; height: 300px; background: url("<?= $asset ?>get-app/seal-mark.png") center / contain no-repeat; opacity: .07; }
.ga-hero .wrap { position: relative; z-index: 1; display: grid; gap: 28px; }
.ga-kicker { margin: 0 0 8px; color: var(--gold); font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; }
.ga-hero h1 { margin: 0; font-size: clamp(31px, 8.4vw, 50px); font-weight: 700; line-height: 1.1; letter-spacing: -.01em; }
.ga-lead { max-width: 36ch; margin: 12px 0 22px; color: rgba(255, 255, 255, .88); font-size: 16px; }
.ga-download { display: flex; width: 100%; min-height: 58px; align-items: center; justify-content: center; gap: 10px; padding: 0 24px; border-radius: 16px; background: var(--gold); color: #172033; font-size: 17px; font-weight: 700; text-decoration: none; box-shadow: 0 12px 26px rgba(0, 0, 0, .28); }
.ga-download:hover { background: #ffd84f; }
.ga-download svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
.ga-meta { margin: 10px 0 0; color: rgba(255, 255, 255, .78); font-size: 12.5px; text-align: center; }
.ga-soon { margin: 0; padding: 14px 16px; border-radius: 14px; background: rgba(255, 255, 255, .12); color: #fff; font-size: 14.5px; }
.ga-soon b { color: var(--gold); }
.ga-whatsnew { display: flex; margin: 14px 0 0; padding: 10px 12px; gap: 8px; align-items: flex-start; border-radius: 12px; background: rgba(255, 255, 255, .1); color: rgba(255, 255, 255, .92); font-size: 13px; }
.ga-whatsnew b { color: var(--gold); font-weight: 600; white-space: nowrap; }

/* Sections */
main { padding: 26px 0 8px; }
.ga-section { margin: 0 0 28px; }
.ga-section > h2 { margin: 0 0 4px; color: var(--blue-dark); font-size: 22px; line-height: 1.25; }
.ga-section > p.sub { margin: 0 0 16px; color: var(--muted); font-size: 14px; }
.steps { display: grid; gap: 12px; margin: 0; padding: 0; list-style: none; }
.step { display: grid; grid-template-columns: 40px minmax(0, 1fr); gap: 4px 14px; padding: 16px; border: 1px solid var(--line); border-radius: 18px; background: #fff; }
.step .n { display: grid; width: 40px; height: 40px; grid-row: span 2; place-items: center; border-radius: 50%; background: var(--navy); color: var(--gold); font-size: 17px; font-weight: 700; }
.step h3 { grid-column: 2; margin: 0; font-size: 16px; line-height: 1.35; align-self: center; }
.step p { grid-column: 2; margin: 2px 0 0; color: var(--body); font-size: 14px; }
.step b { color: var(--ink); font-weight: 600; }
.step .shot { grid-column: 1 / -1; margin-top: 10px; }

/* Little drawings of what the phone shows */
.mini { display: grid; gap: 8px; max-width: 300px; padding: 12px; border-radius: 14px; background: #eef2f8; font-size: 12.5px; }
.mini .dlg { padding: 12px 14px; border-radius: 12px; background: #fff; box-shadow: 0 2px 8px rgba(16, 24, 40, .08); }
.mini .dlg strong { display: block; font-size: 13px; }
.mini .dlg span { display: block; color: var(--muted); font-size: 12px; }
.mini .acts { display: flex; justify-content: flex-end; gap: 16px; margin-top: 8px; font-weight: 600; color: var(--blue); }
.mini .acts .dim { color: var(--muted); font-weight: 500; }
.mini .hl { padding: 2px 8px; border-radius: 8px; outline: 2px solid var(--gold); color: var(--blue); font-weight: 600; }
.mini .row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border-radius: 12px; background: #fff; box-shadow: 0 2px 8px rgba(16, 24, 40, .08); }
.mini .row strong { display: block; font-size: 13px; }
.mini .row small { display: block; color: var(--muted); font-size: 12px; }
.mini .toggle { position: relative; width: 40px; height: 22px; flex: none; border-radius: 999px; background: var(--blue); outline: 2px solid var(--gold); outline-offset: 2px; }
.mini .toggle::after { content: ""; position: absolute; top: 3px; right: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; }
.mini .app { display: flex; align-items: center; gap: 10px; }
.mini .app img { width: 30px; height: 30px; border-radius: 8px; background: #fff; }

.why { margin-top: 12px; padding: 0 16px; border: 1px solid var(--line); border-radius: 16px; background: #fff; }
.why summary { display: flex; min-height: 52px; align-items: center; justify-content: space-between; gap: 10px; font-size: 14.5px; font-weight: 600; cursor: pointer; list-style: none; }
.why summary::-webkit-details-marker { display: none; }
.why summary::after { content: ""; width: 10px; height: 10px; border-right: 2px solid var(--muted); border-bottom: 2px solid var(--muted); transform: rotate(45deg) translate(-2px, -2px); transition: transform .15s; }
.why[open] summary::after { transform: rotate(-135deg); }
.why p { margin: 0 0 14px; color: var(--body); font-size: 14px; }
.why + .why { margin-top: 8px; }

.cards { display: grid; gap: 12px; }
.card { display: grid; grid-template-columns: 46px minmax(0, 1fr); gap: 4px 14px; padding: 16px; border: 1px solid var(--line); border-radius: 18px; background: #fff; }
.card .ico { display: grid; width: 46px; height: 46px; grid-row: span 2; place-items: center; border-radius: 14px; background: var(--soft); color: var(--blue); }
.card h3, .card p { grid-column: 2; }
.card .ico.is-gold { background: #fff4cc; color: #8a5a00; }
.card .ico.is-green { background: #e7f6ee; color: var(--green); }
.card .ico svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.card h3 { margin: 0; font-size: 16px; line-height: 1.35; }
.card p { margin: 2px 0 0; color: var(--body); font-size: 14px; }

.privacy { display: grid; grid-template-columns: 22px minmax(0, 1fr); gap: 10px; padding: 14px 16px; border-radius: 16px; background: var(--soft); color: var(--blue-dark); font-size: 13.5px; }
.privacy svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.privacy p { margin: 0; }

/* Footer */
.ga-foot { position: relative; margin-top: 30px; padding: 46px 16px 26px; background: var(--navy); color: #fff; text-align: center; font-size: 12.5px; }
.ga-foot::before { content: ""; position: absolute; left: 0; right: 0; top: -1px; height: 34px; background: var(--wash); border-bottom: 3px solid var(--gold); border-radius: 0 0 50% 50% / 0 0 100% 100%; }
.ga-foot p { margin: 0; }
.ga-foot em { display: block; color: var(--gold); font-style: normal; font-weight: 600; }
.ga-foot a { display: inline-block; margin-top: 12px; color: #fff; font-weight: 600; }

/* Laptop: the app beside the text, and a QR code to open this page on the phone */
.ga-side { display: none; }
@media (min-width: 900px) {
    .ga-hero { padding: 112px 0 54px; }
    .ga-hero .wrap { grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); align-items: center; gap: 48px; }
    .ga-download { width: auto; display: inline-flex; }
    .ga-meta { text-align: left; }
    .ga-whatsnew { display: inline-flex; }
    .ga-side { position: relative; display: block; justify-self: center; width: 400px; height: 470px; }
    .ga-phone { position: absolute; top: 0; left: 0; width: 230px; border: 8px solid #0f172a; border-radius: 34px; overflow: hidden; background: #fff; box-shadow: 0 30px 60px rgba(0, 0, 0, .35); }
    .ga-phone img { display: block; width: 100%; }
    .ga-qr { position: absolute; right: 0; bottom: 6px; width: 210px; padding: 16px; border-radius: 20px; background: #fff; color: var(--ink); text-align: center; box-shadow: 0 24px 50px rgba(0, 0, 0, .3); }
    .ga-qr #qr { display: grid; min-height: 150px; place-items: center; padding: 6px; }
    .ga-qr #qr img, .ga-qr #qr canvas { width: 150px !important; height: 150px !important; }
    .ga-qr strong { display: block; margin-top: 8px; font-size: 14px; line-height: 1.3; }
    .ga-qr span { display: block; color: var(--muted); font-size: 12px; }
    main .wrap { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, .85fr); gap: 32px; align-items: start; }
    .steps .step { grid-template-columns: 40px minmax(0, 1fr) 250px; }
    .steps .step .n { grid-row: 1 / span 2; }
    .steps .step .shot { grid-column: 3; grid-row: 1 / span 2; margin-top: 0; align-self: center; }
    .ga-section > h2 { font-size: 24px; }
}
</style>
</head>
<body>
<header class="ga-top">
    <div class="wrap">
        <a class="ga-brand" href="./"><img src="<?= $asset ?>isatu-seal-192.png" alt=""><span>ISATU Visits</span></a>
        <a class="ga-staff" href="phone_tracker/login.html">Staff sign in</a>
    </div>
</header>

<section class="ga-hero">
    <div class="wrap">
        <div>
            <p class="ga-kicker">Iloilo Science and Technology University</p>
            <h1>Visiting ISATU? Book it in the app.</h1>
            <p class="ga-lead">Book your visit, show your pass at the gate, and find the office on the campus map.</p>
<?php if ($release): ?>
            <a class="ga-download" href="phone_tracker/download_app.php" download>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
                Download for Android
            </a>
            <p class="ga-meta">Free · version <?= $h($version) ?> · <?= $h($size) ?> · Android 8 or newer</p>
<?php if ($whatsNew !== ""): ?>
            <p class="ga-whatsnew"><b>What's new</b><span><?= $h($whatsNew) ?></span></p>
<?php endif; ?>
<?php else: ?>
            <p class="ga-soon"><b>The app will be here soon.</b> Until then, bring a valid ID: the guard at the gate registers your visit.</p>
<?php endif; ?>
        </div>
        <div class="ga-side" aria-hidden="true">
            <div class="ga-phone"><img src="<?= $asset ?>get-app/app-home.png" alt=""></div>
            <div class="ga-qr"><div id="qr" data-url="<?= $h($siteUrl) ?>"></div><strong>On a computer?</strong><span>Scan with your phone's camera to get the app there.</span></div>
        </div>
    </div>
</section>

<main>
    <div class="wrap">
        <section class="ga-section" aria-labelledby="stepsTitle">
            <h2 id="stepsTitle">Install it in 4 steps</h2>
            <p class="sub">The app isn't on the Play Store yet, so your phone asks a few extra questions. That's normal.</p>
            <ol class="steps">
                <li class="step">
                    <span class="n">1</span>
                    <h3>Download the app</h3>
                    <p>Tap <b>Download for Android</b>. If your browser warns about the file, tap <b>Download anyway</b>.</p>
                    <div class="shot mini" aria-hidden="true">
                        <div class="dlg"><strong>File might be harmful</strong><span>Do you want to download ISATU-Visits.apk anyway?</span>
                            <div class="acts"><span class="dim">Cancel</span><span class="hl">Download anyway</span></div></div>
                    </div>
                </li>
                <li class="step">
                    <span class="n">2</span>
                    <h3>Open the file</h3>
                    <p>Tap <b>Open</b> when it finishes, or find <b>ISATU-Visits.apk</b> in your Downloads.</p>
                    <div class="shot mini" aria-hidden="true">
                        <div class="row"><span class="app"><img src="<?= $asset ?>isatu-seal-192.png" alt=""><span><strong>ISATU-Visits.apk</strong><small>Download complete</small></span></span><span class="hl">Open</span></div>
                    </div>
                </li>
                <li class="step">
                    <span class="n">3</span>
                    <h3>Allow the install</h3>
                    <p>Your phone asks before installing an app from outside the Play Store. Tap <b>Settings</b>, turn on <b>Allow from this source</b>, then go back.</p>
                    <div class="shot mini" aria-hidden="true">
                        <div class="row"><span><strong>Allow from this source</strong><small>Chrome</small></span><span class="toggle"></span></div>
                    </div>
                </li>
                <li class="step">
                    <span class="n">4</span>
                    <h3>Install, then sign up</h3>
                    <p>Tap <b>Install</b>. If Google Play Protect asks, tap <b>More details</b>, then <b>Install anyway</b>. Open ISATU Visits and create your account with your email.</p>
                    <div class="shot mini" aria-hidden="true">
                        <div class="dlg"><span class="app"><img src="<?= $asset ?>isatu-seal-192.png" alt=""><strong>ISATU Visits</strong></span><span style="margin-top:6px">Do you want to install this app?</span>
                            <div class="acts"><span class="dim">Cancel</span><span class="hl">Install</span></div></div>
                    </div>
                </li>
            </ol>
            <details class="why">
                <summary>Why does my phone warn me?</summary>
                <p>Android asks about every app that doesn't come from the Play Store. This file comes straight from ISATU's own visitor system, the same place as this page.</p>
            </details>
            <details class="why">
                <summary>How do I update the app?</summary>
                <p>When a new version is ready, the app tells you on its Home screen. Download it again from this page and install it over the old one. Your account and visits stay.</p>
            </details>
        </section>

        <div>
            <section class="ga-section" aria-labelledby="visitTitle">
                <h2 id="visitTitle">Good to know</h2>
                <p class="sub">Before you come to the campus.</p>
                <div class="cards">
                    <article class="card">
                        <span class="ico is-gold"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M10.5 18.5h3"/></svg></span>
                        <h3>iPhone, or no smartphone?</h3>
                        <p>You can still visit. Bring a valid ID to the guard at the gate. Security registers you there, and your location isn't tracked.</p>
                    </article>
                    <article class="card">
                        <span class="ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M17 11a3 3 0 1 0 0-6M21 20v-1.5a4 4 0 0 0-3-3.9"/></svg></span>
                        <h3>Coming with family or a group?</h3>
                        <p>Only one of you needs the app. When you book, add each person's name. Adults with you show a valid ID at the gate too.</p>
                    </article>
                    <article class="card">
                        <span class="ico is-green"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6 16c.6-1.4 1.7-2 3-2s2.4.6 3 2M15 10h3M15 13h3"/></svg></span>
                        <h3>At the gate</h3>
                        <p>Show your pass and a valid ID. The guard checks that the name on it matches yours.</p>
                    </article>
                </div>
            </section>
            <div class="privacy">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s7-4.5 7-12a7 7 0 1 0-14 0c0 7.5 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                <p>The app shares your location only during a visit, from when the guard checks you in until your visit ends, and you can stop sharing at any time. You'll read the full Terms and Privacy Notice in the app before you sign up.</p>
            </div>
        </div>
    </div>
</main>

<footer class="ga-foot">
    <p>Iloilo Science and Technology University<em>Since 1905</em></p>
    <a href="phone_tracker/login.html">Staff sign in</a>
</footer>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    "use strict";
    // On a laptop: a QR code that opens this page on the visitor's phone. Without the library
    // (offline), the box just says to open the address on the phone.
    var box = document.getElementById("qr");
    if (box && window.QRCode && window.matchMedia("(min-width: 900px)").matches) {
        new window.QRCode(box, { text: box.dataset.url || location.href, width: 300, height: 300, colorDark: "#0b1730", colorLight: "#ffffff", correctLevel: window.QRCode.CorrectLevel.M });
    }
})();
</script>
</body>
</html>
