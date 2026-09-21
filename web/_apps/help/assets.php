<?php
// Path: apps/help/assets.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre — Asset Tracker Guide 📦
 * -----------------------------------------------------------------------------
 * Plain-English walkthrough of the Asset Tracker: what it is, adding and
 * editing physical/digital items, categories and locations, ownership and
 * co-ownership (including the confidential ownership documents area),
 * lending and borrowing, maintenance logs, identifiers, software licences
 * and seats, printed QR labels, the public "found this item?" page, and
 * the accountability trail behind every change.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Asset Tracker';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Asset Tracker' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-boxes-stacked me-2"></i>Asset Tracker Guide</h1>
        <p class="text-secondary mb-0">Everything owned, borrowed, or looked after — in one register, with a full history of who did what.</p>
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
            <a href="#overview" class="badge text-bg-secondary text-decoration-none">What is the Asset Tracker?</a>
            <a href="#adding-assets" class="badge text-bg-secondary text-decoration-none">Adding &amp; Editing Assets</a>
            <a href="#categories-locations" class="badge text-bg-secondary text-decoration-none">Categories &amp; Locations</a>
            <a href="#ownership" class="badge text-bg-secondary text-decoration-none">Ownership &amp; Co-ownership</a>
            <a href="#lending-borrowing" class="badge text-bg-secondary text-decoration-none">Lending &amp; Borrowing</a>
            <a href="#maintenance" class="badge text-bg-secondary text-decoration-none">Maintenance &amp; Servicing</a>
            <a href="#identifiers" class="badge text-bg-secondary text-decoration-none">Barcodes, GS1 &amp; RFID</a>
            <a href="#licences" class="badge text-bg-secondary text-decoration-none">Software Licences &amp; Seats</a>
            <a href="#labels" class="badge text-bg-secondary text-decoration-none">Printing Labels</a>
            <a href="#lost-and-found" class="badge text-bg-secondary text-decoration-none">Lost &amp; Found</a>
            <a href="#accountability" class="badge text-bg-secondary text-decoration-none">Accountability</a>
        </div>
    </div>
</div>

<!-- 1️⃣ Overview -->
<section id="overview" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-boxes-stacked me-2"></i>What is the Asset Tracker?</h2>
    <p>
        <a href="/assets">Asset Tracker</a> is a register of everything your organisation owns, borrows, or is
        responsible for — from laptops, chairs and projectors to vehicles and musical instruments, right through
        to software licences, subscriptions, and other digital items that don't have a physical form.
    </p>
    <p>
        For every item you can see where it is, who's responsible for it, whether it's currently on loan,
        its service history, and — where it makes sense — print a sticky label with a scannable code that
        links back to a simple public page, so a member of the public who finds a lost item can let you know.
    </p>
    <p class="text-muted small mb-0">
        Everyone who's logged in can browse the register and view an item's details. Adding, editing, and
        managing loans, maintenance, and licences is limited to designated managers — if you can't see an
        "Edit" or "New asset" button, ask an administrator to give you that access.
    </p>
</section>

<!-- 2️⃣ Adding & editing -->
<section id="adding-assets" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-plus me-2"></i>Adding &amp; Editing Assets</h2>
    <p>From the register, click <strong>New asset</strong> to add something new. Every asset is one of two kinds:</p>

    <div class="portal-data-list mb-3">
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-box me-1"></i>Physical</strong></div>
            <div class="col-12 col-md-9">Something you can touch and put in a room or a vehicle — furniture, equipment, tools, instruments, and so on.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-cloud me-1"></i>Digital</strong></div>
            <div class="col-12 col-md-9">A software licence, subscription, domain name, or online account — anything you pay for or hold rights to that doesn't sit on a shelf.</div>
        </div>
    </div>

    <p>Along with a name and description, you can record as much or as little detail as is useful:</p>
    <ul>
        <li><strong>Manufacturer, model, and serial number</strong> — handy for insurance claims and warranty support.</li>
        <li><strong>Condition</strong> — a quick at-a-glance rating from New through to Broken.</li>
        <li><strong>Status</strong> — In service, In repair, On loan, Borrowed, In storage, Retired, Disposed, Lost, or Stolen.</li>
        <li><strong>Purchase details</strong> — where and when it was bought and what it cost, plus warranty expiry.</li>
        <li><strong>Confidential</strong> — tick this to hide the item entirely from the public lost-and-found page (see <a href="#lost-and-found">Lost &amp; Found</a> below); useful for higher-value or sensitive equipment.</li>
    </ul>
    <p class="text-muted small mb-0">
        To change any of these later, open the asset from the register and click <strong>Edit</strong>. Every
        change you make is kept in that item's history — see <a href="#accountability">Accountability</a>.
    </p>
</section>

<!-- 3️⃣ Categories & locations -->
<section id="categories-locations" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-tags me-2"></i>Categories &amp; Locations</h2>
    <p>Two simple ways to keep a growing register organised and searchable:</p>
    <ul>
        <li>
            <strong>Categories</strong> group similar items together — for example "AV Equipment", "Furniture",
            or "Vehicles" — and appear as filters when browsing the register.
        </li>
        <li>
            <strong>Locations</strong> describe where an item lives, is stored, or is deployed. Locations can be
            nested to match how your building is laid out — for example a "Store Room" location inside a
            "Main Hall" location — so you can be as broad or as precise as you like.
        </li>
    </ul>
    <p class="text-muted small mb-0">
        Managers set up the list of categories and locations available to everyone; anyone adding or editing
        an asset simply picks from the list.
    </p>
</section>

<!-- 4️⃣ Ownership -->
<section id="ownership" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-people-group me-2"></i>Ownership &amp; Co-ownership</h2>
    <p>
        Every asset can have one or more <strong>owners or custodians</strong> attached to it — the people or
        groups responsible for it. An owner can be:
    </p>
    <ul>
        <li>a specific <strong>person</strong>,</li>
        <li>a <strong>department</strong> or <strong>team/group</strong> within your organisation (set up at
            Admin &rarr; Groups / Departments), or</li>
        <li>an <strong>outside organisation</strong> — a hire company, a partner charity, or anyone else you
            share equipment with.</li>
    </ul>
    <p>
        Where an item is jointly owned — shared with another department, or co-owned across organisations —
        more than one owner can be attached, each with an optional percentage share, so it's clear who holds
        what stake. Individual owners can also be flagged as able to <strong>approve loans</strong> or
        <strong>log maintenance</strong> for that specific item, without needing full manager access.
    </p>
    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-lock mt-1"></i>
        <div>
            <strong>Ownership &amp; legal documents:</strong> for higher-value or shared items, a separate,
            restricted area holds paperwork such as ownership agreements, insurance documents, and other legal
            correspondence. This area is only visible to managers and the people responsible for that
            particular asset — it's kept out of the general documents list so sensitive paperwork never
            appears where it shouldn't.
        </div>
    </div>
</section>

<!-- 5️⃣ Lending & borrowing -->
<section id="lending-borrowing" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-right-left me-2"></i>Lending &amp; Borrowing</h2>
    <p>The Asset Tracker covers loans in both directions:</p>
    <ul>
        <li><strong>Lending out</strong> — you own the item and someone else (a person or another organisation) wants to use it.</li>
        <li><strong>Borrowing in</strong> — you're temporarily using something that belongs to somebody else.</li>
    </ul>
    <p>Either way, a loan follows the same simple lifecycle:</p>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-3">
            <div class="card text-center h-100 border-warning">
                <div class="card-body">
                    <i class="fa-solid fa-hand mt-1 fa-2x text-warning mb-2"></i>
                    <h6>1. Request</h6>
                    <p class="small text-secondary mb-0">Someone asks to use the item and says when they need it back.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card text-center h-100 border-info">
                <div class="card-body">
                    <i class="fa-solid fa-check fa-2x text-info mb-2"></i>
                    <h6>2. Approve</h6>
                    <p class="small text-secondary mb-0">Someone with lending authority for that item approves or declines the request.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card text-center h-100 border-primary">
                <div class="card-body">
                    <i class="fa-solid fa-arrow-up-from-bracket fa-2x text-primary mb-2"></i>
                    <h6>3. Check out</h6>
                    <p class="small text-secondary mb-0">The item leaves — its condition is noted at the point it goes out.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card text-center h-100 border-success">
                <div class="card-body">
                    <i class="fa-solid fa-arrow-down-to-bracket fa-2x text-success mb-2"></i>
                    <h6>4. Check in</h6>
                    <p class="small text-secondary mb-0">The item comes back — its condition is noted again, so any change is caught straight away.</p>
                </div>
            </div>
        </div>
    </div>

    <p class="text-muted small mb-0">
        Because condition is recorded on the way out AND on the way back in, any damage or missing parts can
        be spotted and attributed to the right loan immediately, rather than being discovered — and argued
        about — weeks later.
    </p>
</section>

<!-- 6️⃣ Maintenance -->
<section id="maintenance" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Maintenance &amp; Servicing</h2>
    <p>
        Keep a running history of everything done to keep an item working — services, repairs, inspections,
        calibrations, and upgrades. Each entry records:
    </p>
    <ul>
        <li>what kind of work it was, and a short title/description,</li>
        <li>who did the work — a portal user, or an outside contractor's name,</li>
        <li>the cost and the date it was carried out, and</li>
        <li>an optional "next due" date, so recurring servicing doesn't get forgotten.</li>
    </ul>
    <p class="text-muted small mb-0">
        For items with purchase cost and expected lifespan recorded, the asset's page also shows an estimated
        current value that reduces automatically over time — a helpful indicator only, not a formal valuation.
    </p>
</section>

<!-- 7️⃣ Identifiers -->
<section id="identifiers" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-barcode me-2"></i>Barcodes, GS1 &amp; RFID</h2>
    <p>
        If your items already carry printed barcodes, industry-standard tracking numbers, or RFID tags, you
        can record those against the asset too — useful if you're bringing an existing labelling scheme into
        the portal rather than starting from scratch.
    </p>
    <p>
        There's a range of code types to choose from, covering common retail barcodes, tracking numbers built
        specifically for identifying individual or reusable assets, and RFID tag numbers. If you're not sure
        which to pick, the two options described as being for tracking individual assets are a sensible
        default for most physical items.
    </p>
    <p class="text-muted small mb-0">
        The system checks that a code looks the right shape for its type and will gently flag anything that
        seems unusual — but this is only a helpful hint. It never stops you from saving a code exactly as you
        entered it.
    </p>
</section>

<!-- 8️⃣ Licences & seats -->
<section id="licences" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-key me-2"></i>Software Licences &amp; Seats</h2>
    <p>For a digital asset, you can additionally record:</p>
    <ul>
        <li>a <strong>licence key</strong> — stored securely and only ever shown in full to managers,</li>
        <li>how many <strong>seats</strong> the licence covers (how many devices or people it can be used on at once), and</li>
        <li>a <strong>renewal date</strong>, so a subscription doesn't lapse unnoticed.</li>
    </ul>
    <p>
        Each seat can be handed out — "linked" — to a specific tracked device, to a particular person, or
        simply typed in by name if it's not something tracked separately. When a seat is no longer needed it
        can be released, freeing it up again. At a glance you can always see how many seats are in use and
        how many remain free.
    </p>
</section>

<!-- 9️⃣ Labels -->
<section id="labels" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-tag me-2"></i>Printing QR Asset-Tag Labels</h2>
    <p>
        Managers can select one or more assets and print sticky labels for them. Each label carries a
        scannable QR code (and, optionally, a barcode) linking back to that item's page — either the internal
        page for a confidential item, or the public lost-and-found page described below for everything else.
    </p>
    <p class="text-muted small mb-0">
        Choose a label size, which details to print alongside the code, and how many copies you need — a live
        preview shows exactly what will print before you download the sheet, so there are no surprises when
        it comes out of the printer.
    </p>
</section>

<!-- 🔟 Lost & found -->
<section id="lost-and-found" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-magnifying-glass me-2"></i>The Public "Found This Item?" Page</h2>
    <p>
        Any non-confidential physical asset can carry a public page reached by scanning its printed label.
        Anyone — a member of the public, a delivery driver, another visitor — who scans the code lands on a
        simple page showing just enough to identify the item, with an <strong>"I found this"</strong> form
        they can fill in to let you know. Sensitive details such as cost, serial number, or who owns it are
        never shown on this public page.
    </p>
    <p>
        Every submission lands in a review queue that managers check. From there, they can follow it up,
        reunite the item with whoever's responsible for it, and mark the report as actioned once it's
        resolved.
    </p>
</section>

<!-- 1️⃣1️⃣ Accountability -->
<section id="accountability" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-clipboard-check me-2"></i>Accountability</h2>
    <p>
        Every change made anywhere in the Asset Tracker — adding an item, editing its details, approving or
        completing a loan, logging maintenance, linking a licence seat, or printing a label — is automatically
        recorded. There's always a clear trail of who did what, and when, giving everyone confidence that the
        register can be trusted.
    </p>
</section>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
