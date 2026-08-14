<?php
// Path: apps/help/noticeboard.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre — Noticeboard Guide 📌
 * -----------------------------------------------------------------------------
 * Walkthrough of the Community Noticeboard poster wall: poster types (Canva
 * embed / image / video / text), once-vs-weekday scheduling, colour/aspect/
 * serif styling, the tap-to-expand + QR share flow, and the admin-only
 * management panel.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license    All Rights Reserved
 * @version    1.0.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra/issues/362
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Noticeboard';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Noticeboard' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-thumbtack me-2"></i>Noticeboard Guide</h1>
        <p class="text-secondary mb-0">A visual poster wall for what's on — browse, tap for details, and (if you're an admin) pin up new notices.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<!-- 🧭 Table of contents -->
<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h6 class="card-title mb-2"><i class="fa-solid fa-list me-1"></i>On this page</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="#overview" class="badge text-bg-secondary text-decoration-none">What is the Noticeboard?</a>
            <a href="#poster-types" class="badge text-bg-secondary text-decoration-none">Poster Types</a>
            <a href="#scheduling" class="badge text-bg-secondary text-decoration-none">Scheduling</a>
            <a href="#styling" class="badge text-bg-secondary text-decoration-none">Styling a Poster</a>
            <a href="#viewing" class="badge text-bg-secondary text-decoration-none">Viewing &amp; QR Share</a>
            <a href="#admins" class="badge text-bg-secondary text-decoration-none">For Admins</a>
        </div>
    </div>
</div>

<!-- 1️⃣ Overview -->
<section id="overview" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-thumbtack me-2"></i>What is the Noticeboard?</h2>
    <p>
        <a href="/noticeboard">Noticeboard</a> is a visual "pinboard" of what's coming up at your
        site — think of a physical corkboard of posters, but shareable and always up to date.
        Each notice is a poster tile with a title, category, date/time, location, and (optionally)
        an image, video, Canva design, or plain text card.
    </p>
    <p>
        Unlike <a href="/announcements">Announcements</a> (which are short text notices in a list),
        the Noticeboard is designed to look and feel like a wall of real posters — full of colour,
        imagery, and variety.
    </p>
</section>

<!-- 2️⃣ Poster types -->
<section id="poster-types" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-images me-2"></i>Poster Types</h2>
    <p>Every poster uses one of four media types:</p>

    <div class="portal-data-list">
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-brands fa-canva me-1"></i>Canva embed</strong></div>
            <div class="col-12 col-md-9">Paste a Canva design's share link and it renders live on the tile, so any updates you make in Canva show up on the board automatically.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-image me-1"></i>Image</strong></div>
            <div class="col-12 col-md-9">A single photo or graphic as the poster's background.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-video me-1"></i>Video</strong></div>
            <div class="col-12 col-md-9">A short looping video clip in place of a static image.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-font me-1"></i>Text</strong></div>
            <div class="col-12 col-md-9">No media at all — a styled colour card carrying just the title, kicker, and details. The simplest option when you don't have artwork ready.</div>
        </div>
    </div>
    <p class="text-muted small mt-3 mb-0">
        Every poster also carries a short <strong>kicker</strong> (a small eyebrow line above the
        title, e.g. "Live Music" or "Fundraiser") and a <strong>category</strong> used for the
        filter pills at the top of the board.
    </p>
</section>

<!-- 3️⃣ Scheduling -->
<section id="scheduling" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-calendar-check me-2"></i>Scheduling</h2>
    <p>Each poster is scheduled one of two ways:</p>
    <ul>
        <li>
            <span class="badge bg-primary-subtle text-primary-emphasis">
                <i class="fa-solid fa-calendar-day me-1"></i>Once
            </span>
            — a single fixed date (and optional time). Best for one-off events, fairs, and
            performances. The poster naturally drops off the board once its date has passed.
        </li>
        <li>
            <span class="badge bg-info-subtle text-info-emphasis">
                <i class="fa-solid fa-repeat me-1"></i>Weekly
            </span>
            — recurs on a chosen day of the week (e.g. every Saturday) with an optional time,
            for regular fixtures like a market, a class, or a weekly group. There's no end date
            to set — it simply keeps recurring until the poster is edited or removed.
        </li>
    </ul>
    <p class="text-muted small mb-0">
        Every poster can also carry an optional <strong>location</strong> and a <strong>link</strong>
        through to the official event page — see <a href="#viewing">Viewing &amp; QR Share</a> below
        for how the link is opened.
    </p>
</section>

<!-- 4️⃣ Styling -->
<section id="styling" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-palette me-2"></i>Styling a Poster</h2>
    <p>Three independent style choices control how a poster looks on the wall:</p>
    <ul>
        <li><strong>Colour</strong> — pick an accent swatch from the board's built-in palette; it tints the poster's card and text.</li>
        <li><strong>Aspect ratio</strong> — choose the tile's shape (square, portrait, or a taller poster-like ratio) so it best fits your artwork.</li>
        <li><strong>Serif toggle</strong> — switch the title between a bold sans-serif look and a more editorial serif typeface, depending on the tone you want (playful vs. formal).</li>
    </ul>
    <p class="text-muted small mb-0">
        Picking a Canva design's share link automatically carries over its own aspect ratio, so
        you usually won't need to set that manually for Canva posters.
    </p>
</section>

<!-- 5️⃣ Viewing & QR share -->
<section id="viewing" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-qrcode me-2"></i>Viewing &amp; QR Share</h2>
    <p>
        Tap any poster on the board to open it full-size with the complete details. From there:
    </p>
    <ul>
        <li>If the poster has a <strong>link</strong>, tapping the enlarged poster again opens the official event page in a new tab.</li>
        <li>Tapping outside the enlarged poster closes it and returns you to the board.</li>
        <li>A <strong>Share</strong> control on the enlarged view generates a <strong>QR code</strong> that points straight back to that notice — handy for printed flyers, noticeboards in other rooms, or a slide at the front of a meeting.</li>
    </ul>
</section>

<!-- 6️⃣ Admins -->
<section id="admins" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-gauge-high me-2"></i>For Admins</h2>
    <p>
        Site admins see an extra <em>Manage board</em> control on the Noticeboard page. Opening it
        reveals the admin panel where you can:
    </p>
    <ul>
        <li><strong>Pin a new notice</strong> — fill in the title, kicker, category, media, schedule, styling, and optional link, then save it straight to the board.</li>
        <li><strong>Edit an existing notice</strong> — select any poster from the board to update its details or replace its artwork.</li>
        <li><strong>Remove a notice</strong> — take a poster down once it's no longer needed.</li>
    </ul>
    <p class="text-muted small mb-0">
        Changes save immediately for everyone viewing the board on this site — there's no separate
        publish step. Only site admins can make changes; everyone else sees a read-only board.
    </p>
</section>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
