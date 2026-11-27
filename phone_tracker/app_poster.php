<?php
/*
 * The A4 poster for the gate and the offices (GROUP_VISITS.md): a QR code to the site's main page,
 * where visitors get the app. Opened from Admin › Menu › Visitor app › Print the poster.
 */
require_once __DIR__ . "/app_release_service.php";

$siteUrl = app_release_site_url();
$shown = (string) preg_replace('#^https?://#', "", rtrim($siteUrl, "/"));
$h = fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-cache");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gate poster · ISATU Visits</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
<style>
:root { --navy: #003b82; --gold: #ffcb2f; --ink: #172033; --body: #3d4b5f; }
* { box-sizing: border-box; }
body { margin: 0; padding: 24px 16px 40px; background: #d9dee6; color: var(--ink); font-family: Poppins, Arial, sans-serif; }
.bar { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; justify-content: center; max-width: 210mm; margin: 0 auto 16px; font-size: 13px; color: #374151; }
.bar button { min-height: 42px; padding: 0 18px; border: 0; border-radius: 10px; background: #0755b5; color: #fff; font: 600 14px Poppins, Arial, sans-serif; cursor: pointer; }
.sheet-wrap { overflow-x: auto; }
.poster { position: relative; display: grid; grid-template-rows: 96mm minmax(0, 1fr) 40mm; width: 210mm; height: 297mm; margin: 0 auto; overflow: hidden; background: #fff; box-shadow: 0 20px 50px rgba(15, 23, 42, .25); }
.top { position: relative; display: grid; place-items: center; align-content: center; gap: 2mm; padding: 8mm 14mm 7mm; background: var(--navy); color: #fff; text-align: center; border-bottom: 3mm solid var(--gold); overflow: hidden; }
.top::after { content: ""; position: absolute; right: -26mm; top: -14mm; width: 92mm; height: 92mm; background: url("assets/images/get-app/seal-mark.png") center / contain no-repeat; opacity: .08; }
.top img { width: 28mm; height: 28mm; padding: 1mm; border-radius: 50%; background: #fff; }
.uni { margin: 1mm 0 0; color: var(--gold); font-size: 10.5pt; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; }
.top h1 { margin: 0; font-size: 40pt; font-weight: 800; line-height: 1.05; letter-spacing: -.01em; }
.top p.lead { margin: 1mm 0 0; color: rgba(255, 255, 255, .92); font-size: 16pt; font-weight: 500; }
.top p.lead b { color: var(--gold); font-weight: 700; }
.mid { display: grid; align-content: center; justify-items: center; gap: 5mm; padding: 6mm 14mm; }
.qr-frame { display: grid; place-items: center; width: 80mm; height: 80mm; padding: 4mm; border: 2.2mm solid var(--gold); border-radius: 7mm; background: #fff; }
.qr-frame img, .qr-frame canvas { width: 68mm !important; height: 68mm !important; }
.scan { margin: -2mm 0 0; text-align: center; }
.scan strong { display: block; font-size: 20pt; line-height: 1.2; }
.scan span { display: block; margin-top: 1mm; color: #0757b9; font-size: 13pt; font-weight: 600; }
.steps { display: grid; width: 100%; grid-template-columns: repeat(3, 1fr); gap: 6mm; margin: 2mm 0 0; padding: 0; list-style: none; }
.steps li { display: grid; justify-items: center; gap: 1.5mm; text-align: center; }
.steps i { display: grid; width: 12mm; height: 12mm; place-items: center; border-radius: 50%; background: var(--navy); color: var(--gold); font-size: 15pt; font-style: normal; font-weight: 700; }
.steps b { font-size: 12.5pt; line-height: 1.25; }
.steps span { color: var(--body); font-size: 10pt; line-height: 1.4; }
.foot { display: grid; grid-template-columns: 22mm 1fr; align-items: center; gap: 6mm; padding: 6mm 16mm; background: var(--gold); color: var(--ink); }
.foot svg { width: 20mm; height: 20mm; fill: none; stroke: var(--navy); stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.foot strong { display: block; font-size: 17pt; line-height: 1.2; }
.foot span { display: block; font-size: 12pt; font-weight: 500; }
@page { size: A4; margin: 0; }
@media print {
    body { padding: 0; background: #fff; }
    .bar { display: none; }
    .poster { box-shadow: none; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<div class="bar">
    <button type="button" id="printBtn">Print the poster</button>
    <span>A4, portrait. Or save it as a PDF from the print window.</span>
</div>
<div class="sheet-wrap">
    <article class="poster" aria-label="Poster: get the ISATU Visits app">
        <header class="top">
            <img src="assets/images/isatu-seal-512.png" alt="ISATU seal">
            <p class="uni">Iloilo Science and Technology University</p>
            <h1>Visiting the campus?</h1>
            <p class="lead">Book your visit with the <b>ISATU Visits</b> app.</p>
        </header>
        <section class="mid">
            <div class="qr-frame"><div id="qr" data-url="<?= $h($siteUrl) ?>"></div></div>
            <p class="scan"><strong>Scan to get the app</strong><span><?= $h($shown) ?></span></p>
            <ol class="steps">
                <li><i>1</i><b>Get the app</b><span>Free, for Android phones.</span></li>
                <li><i>2</i><b>Book your visit</b><span>Pick the office and the time. Bringing people? Add their names.</span></li>
                <li><i>3</i><b>Show your pass here</b><span>With a valid ID. The guard checks your name.</span></li>
            </ol>
        </section>
        <footer class="foot">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 20v-1.5a4 4 0 0 0-4-4H6.5a4 4 0 0 0-4 4V20"/><circle cx="8.8" cy="7.5" r="3.6"/><path d="M19 8v6M16 11h6"/></svg>
            <div><strong>iPhone or no smartphone?</strong><span>Tell the guard. You'll be registered here, with a valid ID.</span></div>
        </footer>
    </article>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    "use strict";
    var box = document.getElementById("qr");
    if (window.QRCode) {
        new window.QRCode(box, { text: box.dataset.url, width: 600, height: 600, colorDark: "#0b1730", colorLight: "#ffffff", correctLevel: window.QRCode.CorrectLevel.M });
    } else {
        box.textContent = "Connect to the internet to show the QR code.";
    }
    document.getElementById("printBtn").addEventListener("click", function () {
        window.print();
    });
})();
</script>
</body>
</html>
