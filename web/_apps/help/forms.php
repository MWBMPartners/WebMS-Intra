<?php
// Path: _apps/help/forms.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre — Forms Builder Guide 🧾
 * -----------------------------------------------------------------------------
 * Walkthrough of the Forms Builder app (#153): field types, building a form,
 * publishing & the public share link, collecting/exporting responses, admin
 * settings, and what's stored for privacy purposes.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license    All Rights Reserved
 * @version    1.0.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra/issues/153
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\FormEngine;

$pageTitle   = 'Help - Forms Builder';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Forms Builder' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-clipboard-list me-2"></i>Forms Builder Guide</h1>
        <p class="text-secondary mb-0">Design a form, publish it internally or publicly, and collect and export the responses.</p>
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
            <a href="#overview" class="badge text-bg-secondary text-decoration-none">What is Forms Builder?</a>
            <a href="#building" class="badge text-bg-secondary text-decoration-none">Building a Form</a>
            <a href="#publishing" class="badge text-bg-secondary text-decoration-none">Publishing &amp; the Public Link</a>
            <a href="#responses" class="badge text-bg-secondary text-decoration-none">Collecting Responses &amp; CSV Export</a>
            <a href="#admins" class="badge text-bg-secondary text-decoration-none">For Admins</a>
            <a href="#privacy" class="badge text-bg-secondary text-decoration-none">Privacy Note</a>
        </div>
    </div>
</div>

<!-- 1️⃣ Overview -->
<section id="overview" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-circle-info me-2"></i>What is Forms Builder?</h2>
    <p>
        <a href="/forms">Forms</a> lets an admin design a custom form — registration, survey, sign-up,
        feedback, whatever a "we need a quick form" request calls for — without a code change. Each
        form has its own set of fields, and can be filled in by signed-in site members, or shared
        publicly with anyone via a short link, or both.
    </p>
</section>

<!-- 2️⃣ Building a form -->
<section id="building" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-hammer me-2"></i>Building a Form</h2>
    <p>
        From <a href="/forms/manage">Manage forms</a>, click <strong>New form</strong>, give it a title
        and (optionally) a description, then save it. Once saved, you can add fields — each field has a
        label, a type, whether it's required, and a small amount of type-specific configuration
        (for example, the choices for a dropdown).
    </p>

    <div class="table-responsive">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-4">Field type</div>
                <div class="col-md-8">What it's for</div>
            </div>
            <?php foreach (FormEngine::FIELD_TYPES as $key => $meta): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-4"><strong><?php echo htmlspecialchars((string) $meta['label'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-12 col-md-8">
                        <?php
                        echo match ($key) {
                            'text'       => 'A single line of free text (e.g. a name).',
                            'textarea'   => 'A multi-line block of free text (e.g. comments).',
                            'email'      => 'An email address, validated on submit.',
                            'phone'      => 'A phone number.',
                            'number'     => 'A number, optionally bounded by a minimum/maximum.',
                            'date'       => 'A calendar date.',
                            'time'       => 'A time of day.',
                            'select'     => 'A dropdown — pick exactly one option from a list you define.',
                            'radio'      => 'A group of radio buttons — pick exactly one option.',
                            'checkboxes' => 'A group of checkboxes — pick any number of options.',
                            'checkbox'   => 'A single tick box — good for a yes/no or a consent statement.',
                            'heading'    => 'Not an input — a section heading to break up a long form.',
                            default      => '',
                        };
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <p class="text-muted small mt-3 mb-0">
        Fields can be reordered with the up/down arrows, edited in place, or removed. Removing a field
        never touches responses already collected against it — see <a href="#privacy">Privacy note</a>.
    </p>
</section>

<!-- 3️⃣ Publishing & the public link -->
<section id="publishing" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-share-nodes me-2"></i>Publishing &amp; the Public Link</h2>
    <p>A form has a status and an audience:</p>
    <ul>
        <li><strong>Draft</strong> — being built, not visible to anyone else yet.</li>
        <li><strong>Published</strong> — live and accepting responses (within its optional open/close window).</li>
        <li><strong>Closed</strong> — stopped accepting responses, but the form and its past responses are kept.</li>
    </ul>
    <p>Audience controls WHO can fill it in:</p>
    <ul>
        <li><strong>Internal</strong> — signed-in members of this site only, via <a href="/forms">Forms</a>.</li>
        <li><strong>Public only</strong> / <strong>Internal + public</strong> — also shareable with anyone via a
            short link (<code>/f/&lt;token&gt;</code>) and a printable QR code, generated the first time the
            form is published. "Rotate link" on the manage page invalidates the old link/QR and mints a new one.</li>
    </ul>
    <p class="text-muted small mb-0">
        Public forms are OFF for a site by default — an admin must switch on public sharing first (see
        <a href="#admins">For Admins</a>) before the public/internal+public options become available when
        building a form.
    </p>
</section>

<!-- 4️⃣ Responses -->
<section id="responses" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-inbox me-2"></i>Collecting Responses &amp; CSV Export</h2>
    <p>
        Every submission appears under a form's <strong>Responses</strong> page (admin-only), tabbed
        New / Reviewed. Click a row to expand it and see every answer. Mark a response reviewed once
        you've actioned it, or delete it outright.
    </p>
    <p class="mb-0">
        <strong>Export CSV</strong> downloads every current response as a spreadsheet — one column per
        current field (by its current label), plus Response ID / Submitted / Channel / Submitter /
        Status. A field that's since been removed from the form still shows up as a trailing column for
        any response that answered it, so historical data is never silently dropped from the export.
    </p>
</section>

<!-- 5️⃣ Admins -->
<section id="admins" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-gauge-high me-2"></i>For Admins</h2>
    <ul>
        <li>Forms Builder is enabled/disabled per site like any other app at <a href="/admin/apps">/admin/apps</a>.</li>
        <li><strong>Public sharing</strong> (<code>forms.allowPublic</code>) is a separate, default-OFF switch
            in <a href="/settings">Settings</a> — flip it on before a form's audience can be set to public/both.</li>
        <li>Only admins can build forms, publish/close them, and view or export responses (v1 — no separate
            "forms manager" role yet).</li>
    </ul>
</section>

<!-- 6️⃣ Privacy -->
<section id="privacy" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Privacy Note</h2>
    <p>
        A response is stored as a snapshot of exactly what was submitted — the field label, type, and
        answer at the time — so it stays readable and correct even if the form is edited or a field is
        later removed.
    </p>
    <p>
        An internal (signed-in) response is linked to the submitter's account and is included in that
        member's GDPR data export and is erased with the rest of their data on account erasure. A
        <strong>public</strong> (anonymous) response carries no account link — only the submitting IP
        address, kept for abuse-tracing purposes — so it cannot be matched to a specific person for
        erasure-by-request the way an internal response can; an admin can still delete any individual
        response by hand from the Responses page. Public responses are otherwise kept indefinitely in
        this version — see DEV_NOTES for a note on an optional future auto-purge.
    </p>
</section>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
