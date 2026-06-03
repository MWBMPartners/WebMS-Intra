<?php
// Path: apps/help/translations.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre -- Translations & Languages Guide
 * -----------------------------------------------------------------------------
 * ELI5-friendly guide for managing translations: how the language system works,
 * how to switch languages, how translators create new language files, and how
 * translations are reviewed and approved.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license    All Rights Reserved
 * @version    0.8.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Translations & Languages';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Translations' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Translations & Languages Guide -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-globe me-2"></i>Translations &amp; Languages</h1>
        <p class="text-secondary mb-0">How to change your language, contribute translations, and manage the portal's multilingual support.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to Help Centre
    </a>
</div>

<!-- Who is this for? -->
<div class="alert alert-info d-flex gap-2 mb-4" role="alert">
    <i class="fa-solid fa-circle-info mt-1" aria-hidden="true"></i>
    <div>
        <strong>For everyone.</strong> The first two sections (changing your language and how it works) are for all users. The later sections (creating translations and the review process) are for translators and administrators.
    </div>
</div>

<!-- Table of contents -->
<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h6 class="card-title mb-2"><i class="fa-solid fa-list me-1" aria-hidden="true"></i>On this page</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="#change-language" class="badge text-bg-secondary text-decoration-none">Changing Your Language</a>
            <a href="#how-it-works" class="badge text-bg-secondary text-decoration-none">How It Works</a>
            <a href="#supported-languages" class="badge text-bg-secondary text-decoration-none">Supported Languages</a>
            <a href="#add-language" class="badge text-bg-secondary text-decoration-none">Adding a New Language</a>
            <a href="#translate-string" class="badge text-bg-secondary text-decoration-none">Translating a String</a>
            <a href="#parameters" class="badge text-bg-secondary text-decoration-none">Parameters &amp; Plurals</a>
            <a href="#rtl" class="badge text-bg-secondary text-decoration-none">Right-to-Left Languages</a>
            <a href="#review" class="badge text-bg-secondary text-decoration-none">Review &amp; Approval</a>
            <a href="#admin-settings" class="badge text-bg-secondary text-decoration-none">Admin Settings</a>
        </div>
    </div>
</div>


<!-- ================================================================== -->
<!-- Section 1: Changing Your Language -->
<!-- ================================================================== -->
<div class="portal-card p-4 mb-4" id="change-language">
    <h2 class="h4 mb-3"><i class="fa-solid fa-language me-2 text-primary" aria-hidden="true"></i>Changing Your Language</h2>

    <p>You can change the portal's language at any time. Your choice is remembered, so you only need to do this once.</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Look for the globe icon in the navigation bar</strong>
                <p class="mb-0 small text-secondary">At the top of every page, you'll see a <i class="fa-solid fa-globe" aria-hidden="true"></i> button showing your current language (e.g. "English"). Click it to open the language menu.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Pick your language from the dropdown</strong>
                <p class="mb-0 small text-secondary">Each language is shown in its own script (e.g. "Cymraeg" for Welsh, "العربية" for Arabic). Click the one you want.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>The page reloads in your chosen language</strong>
                <p class="mb-0 small text-secondary">All menus, buttons, and messages will switch to your selected language. If you're logged in, your preference is saved permanently.</p>
            </div>
        </div>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-lightbulb mt-1" aria-hidden="true"></i>
        <div>
            <strong>Tip:</strong> If a translation hasn't been provided for a particular phrase, the English version will be shown instead. This is normal — it means the translator hasn't got to that phrase yet.
        </div>
    </div>
</div>


<!-- ================================================================== -->
<!-- Section 2: How It Works -->
<!-- ================================================================== -->
<div class="portal-card p-4 mb-4" id="how-it-works">
    <h2 class="h4 mb-3"><i class="fa-solid fa-gears me-2 text-primary" aria-hidden="true"></i>How It Works</h2>

    <p>Think of the translation system like a dictionary. When the portal needs to show you some text (like "Sign In" or "Dashboard"), it looks up the translation in your language's dictionary. If it can't find one, it uses the English version instead.</p>

    <h5 class="mt-3 mb-2">The lookup order</h5>
    <p>The portal decides which language to show you using this priority:</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">1st</span>
            <div>
                <strong>Your saved preference</strong>
                <p class="mb-0 small text-secondary">If you've previously chosen a language (and you're logged in), that choice is stored in your account and used automatically.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-info rounded-pill mt-1">2nd</span>
            <div>
                <strong>Your current session</strong>
                <p class="mb-0 small text-secondary">If you changed language during this visit (even without logging in), that choice is remembered until you close your browser.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-warning rounded-pill mt-1">3rd</span>
            <div>
                <strong>Your browser's language setting</strong>
                <p class="mb-0 small text-secondary">If you haven't chosen a language, the portal checks what language your browser is set to and tries to match it.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-secondary rounded-pill mt-1">4th</span>
            <div>
                <strong>The default (English)</strong>
                <p class="mb-0 small text-secondary">If none of the above match, English is used as the fallback.</p>
            </div>
        </div>
    </div>
</div>


<!-- ================================================================== -->
<!-- Section 3: Supported Languages -->
<!-- ================================================================== -->
<div class="portal-card p-4 mb-4" id="supported-languages">
    <h2 class="h4 mb-3"><i class="fa-solid fa-earth-americas me-2 text-primary" aria-hidden="true"></i>Supported Languages</h2>

    <p>The portal framework supports 13 languages. However, only languages with a translation file are available in the language switcher.</p>

    <div class="row g-3">
        <div class="col-12 col-md-6">
            <h6>Left-to-Right (LTR)</h6>
            <ul class="list-unstyled ms-2">
                <li><strong>en</strong> — English (baseline)</li>
                <li><strong>cy</strong> — Cymraeg (Welsh)</li>
                <li><strong>fr</strong> — Français (French)</li>
                <li><strong>de</strong> — Deutsch (German)</li>
                <li><strong>es</strong> — Español (Spanish)</li>
                <li><strong>pt</strong> — Português (Portuguese)</li>
                <li><strong>zh</strong> — 中文 (Chinese)</li>
                <li><strong>ja</strong> — 日本語 (Japanese)</li>
                <li><strong>ko</strong> — 한국어 (Korean)</li>
            </ul>
        </div>
        <div class="col-12 col-md-6">
            <h6>Right-to-Left (RTL)</h6>
            <ul class="list-unstyled ms-2">
                <li><strong>ar</strong> — العربية (Arabic)</li>
                <li><strong>he</strong> — עברית (Hebrew)</li>
                <li><strong>fa</strong> — فارسی (Farsi)</li>
                <li><strong>ur</strong> — اردو (Urdu)</li>
            </ul>
        </div>
    </div>

    <div class="alert alert-info d-flex gap-2 mt-3" role="alert">
        <i class="fa-solid fa-circle-info mt-1" aria-hidden="true"></i>
        <div>
            Currently, <strong>English</strong> and <strong>Welsh</strong> have translation files. Other languages will appear in the switcher as their translation files are created.
        </div>
    </div>
</div>


<!-- ================================================================== -->
<!-- Section 4: Adding a New Language (For Translators) -->
<!-- ================================================================== -->
<div class="portal-card p-4 mb-4" id="add-language">
    <h2 class="h4 mb-3"><i class="fa-solid fa-plus-circle me-2 text-primary" aria-hidden="true"></i>Adding a New Language</h2>

    <div class="alert alert-danger d-flex gap-2 mb-3" role="alert">
        <i class="fa-solid fa-shield-halved mt-1" aria-hidden="true"></i>
        <div>
            <strong>Requires code access.</strong> Adding a new language means creating a PHP file in the codebase. You'll need access to the Git repository or help from a developer.
        </div>
    </div>

    <p>Here's how to add a brand new language to the portal, step by step:</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Copy the English file as your starting point</strong>
                <p class="mb-0 small text-secondary">
                    The English file lives at <code>web/_lang/en.php</code>. Make a copy and rename it to your language code. For example, for French: copy <code>en.php</code> to <code>fr.php</code> in the same folder.
                </p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Update the file header comment</strong>
                <p class="mb-0 small text-secondary">
                    Change "English (en) Translation File" to your language name and code, e.g. "French (fr) Translation File". Update the flag emoji too.
                </p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>Translate the values (right side of the arrow)</strong>
                <p class="mb-0 small text-secondary">
                    Each line looks like: <code>'nav.dashboard' =&gt; 'Dashboard',</code><br>
                    Change <strong>only the text after the arrow</strong> (<code>=&gt;</code>). Never change the key on the left side.
                </p>
                <div class="mt-2 bg-body-tertiary rounded p-2">
                    <code class="text-success">&#10004; 'nav.dashboard' =&gt; 'Tableau de bord',</code><br>
                    <code class="text-danger">&#10008; 'nav.tableau_de_bord' =&gt; 'Tableau de bord',</code>
                </div>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">4</span>
            <div>
                <strong>Remove untranslated lines</strong>
                <p class="mb-0 small text-secondary">
                    If you haven't translated a phrase yet, <strong>delete that line entirely</strong>. The portal will automatically show the English version for missing translations. This is better than leaving English text in your language file.
                </p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">5</span>
            <div>
                <strong>Save and test</strong>
                <p class="mb-0 small text-secondary">
                    Once your file is saved and deployed, your language will appear in the <i class="fa-solid fa-globe" aria-hidden="true"></i> language switcher automatically. Visit any page and select your language to see it in action.
                </p>
            </div>
        </div>
    </div>
</div>


<!-- ================================================================== -->
<!-- Section 5: Translating a Specific String -->
<!-- ================================================================== -->
<div class="portal-card p-4 mb-4" id="translate-string">
    <h2 class="h4 mb-3"><i class="fa-solid fa-pen me-2 text-primary" aria-hidden="true"></i>Translating a Specific String</h2>

    <p>If you notice a phrase that's still in English (or you want to improve an existing translation), here's what to do:</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Find the English text you want to translate</strong>
                <p class="mb-0 small text-secondary">Open <code>web/_lang/en.php</code> and search for the English text. For example, searching for "Sign In" would find:<br><code>'auth.sign_in' =&gt; 'Sign In',</code></p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Note the key (left side)</strong>
                <p class="mb-0 small text-secondary">The key is <code>auth.sign_in</code> — this is what you'll add to your language file.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>Add it to your language file with the translation</strong>
                <p class="mb-0 small text-secondary">Open your language file (e.g. <code>web/_lang/fr.php</code>) and add:<br><code>'auth.sign_in' =&gt; 'Se connecter',</code></p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">4</span>
            <div>
                <strong>Save and check</strong>
                <p class="mb-0 small text-secondary">The change takes effect immediately — no build or restart needed. Just reload the page in your chosen language.</p>
            </div>
        </div>
    </div>

    <h5 class="mt-3 mb-2">Key naming pattern</h5>
    <p>Keys follow a consistent pattern: <code>{section}.{description}</code>. Here are the main sections:</p>

    <div class="row g-2">
        <div class="col-6 col-md-4"><code>nav.</code> — Navigation bar</div>
        <div class="col-6 col-md-4"><code>auth.</code> — Login &amp; account</div>
        <div class="col-6 col-md-4"><code>dashboard.</code> — Dashboard</div>
        <div class="col-6 col-md-4"><code>expenses.</code> — Expense claims</div>
        <div class="col-6 col-md-4"><code>calendar.</code> — Calendar / Events</div>
        <div class="col-6 col-md-4"><code>attendance.</code> — Attendance</div>
        <div class="col-6 col-md-4"><code>admin.</code> — Admin panel</div>
        <div class="col-6 col-md-4"><code>error.</code> — Error pages</div>
        <div class="col-6 col-md-4"><code>common.</code> — Shared buttons/labels</div>
    </div>
</div>


<!-- ================================================================== -->
<!-- Section 6: Parameters & Plurals -->
<!-- ================================================================== -->
<div class="portal-card p-4 mb-4" id="parameters">
    <h2 class="h4 mb-3"><i class="fa-solid fa-code me-2 text-primary" aria-hidden="true"></i>Parameters &amp; Plurals</h2>

    <p>Some translations include dynamic values or change based on a number. Here's how they work:</p>

    <h5 class="mt-3 mb-2">Parameters (dynamic values)</h5>
    <p>Words starting with a colon (<code>:</code>) are placeholders that get replaced with real values at runtime. <strong>Keep them exactly as they are</strong> — only translate the text around them.</p>

    <div class="bg-body-tertiary rounded p-3 mb-3">
        <p class="mb-1"><strong>English:</strong></p>
        <code>'auth.too_many_attempts' =&gt; 'Too many attempts. Try again in :minutes minute(s).'</code>
        <p class="mb-1 mt-2"><strong>French:</strong></p>
        <code>'auth.too_many_attempts' =&gt; 'Trop de tentatives. Réessayez dans :minutes minute(s).'</code>
        <p class="mt-2 mb-0 small text-secondary"><i class="fa-solid fa-arrow-right me-1" aria-hidden="true"></i><code>:minutes</code> stays the same — the portal replaces it with the actual number.</p>
    </div>

    <h5 class="mt-4 mb-2">Plurals (singular vs plural forms)</h5>
    <p>Strings that change based on a count use the pipe character (<code>|</code>) to separate forms:</p>

    <div class="bg-body-tertiary rounded p-3 mb-3">
        <p class="mb-1"><strong>Two forms</strong> (singular | plural):</p>
        <code>'expenses.claim_count' =&gt; 'One claim|:count claims'</code>
        <ul class="small text-secondary mt-1 mb-2">
            <li>If count = 1 → "One claim"</li>
            <li>If count = 5 → "5 claims"</li>
        </ul>

        <p class="mb-1"><strong>Three forms</strong> (zero | one | many):</p>
        <code>'items.count' =&gt; 'No items|One item|:count items'</code>
        <ul class="small text-secondary mt-1 mb-0">
            <li>If count = 0 → "No items"</li>
            <li>If count = 1 → "One item"</li>
            <li>If count = 7 → "7 items"</li>
        </ul>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1" aria-hidden="true"></i>
        <div>
            <strong>Important:</strong> Keep the same number of pipe-separated forms as the English version. If English has three forms (zero|one|many), your translation should also have three forms.
        </div>
    </div>
</div>


<!-- ================================================================== -->
<!-- Section 7: Right-to-Left Languages -->
<!-- ================================================================== -->
<div class="portal-card p-4 mb-4" id="rtl">
    <h2 class="h4 mb-3"><i class="fa-solid fa-arrows-left-right me-2 text-primary" aria-hidden="true"></i>Right-to-Left Languages</h2>

    <p>Languages like Arabic, Hebrew, Farsi, and Urdu are read from right to left. The portal handles this automatically:</p>

    <ul>
        <li>The entire page layout flips — menus appear on the right, text aligns right</li>
        <li>Bootstrap loads a special RTL stylesheet</li>
        <li>Margins, paddings, and icons are mirrored</li>
    </ul>

    <p><strong>As a translator, you don't need to do anything special.</strong> Just provide the translated text in your language file. The portal detects which direction your language uses and adjusts the layout accordingly.</p>
</div>


<!-- ================================================================== -->
<!-- Section 8: Review & Approval Process -->
<!-- ================================================================== -->
<div class="portal-card p-4 mb-4" id="review">
    <h2 class="h4 mb-3"><i class="fa-solid fa-clipboard-check me-2 text-primary" aria-hidden="true"></i>Review &amp; Approval Process</h2>

    <p>Translations are managed through the same process as code changes. This ensures quality and accountability:</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Translator creates or edits the language file</strong>
                <p class="mb-0 small text-secondary">The translator opens the relevant file (e.g. <code>web/_lang/fr.php</code>) and adds or updates translations.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Submit the changes for review</strong>
                <p class="mb-0 small text-secondary">The translator commits the changes and submits them via a Git pull request (or sends the file to a developer for review).</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>A reviewer checks the translations</strong>
                <p class="mb-0 small text-secondary">A developer or language reviewer checks that:
                    <br>— Keys haven't been changed (only values)
                    <br>— Parameters (like <code>:name</code>) are preserved
                    <br>— Plural forms match the expected count
                    <br>— The PHP syntax is correct (commas, quotes, semicolons)
                </p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">4</span>
            <div>
                <strong>Merge and deploy to dev</strong>
                <p class="mb-0 small text-secondary">Once approved, the changes are merged into the main branch and automatically deployed to the dev site for testing.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">5</span>
            <div>
                <strong>Test on the dev site</strong>
                <p class="mb-0 small text-secondary">Visit the dev site, switch to the translated language, and check that all the new strings appear correctly in context.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">6</span>
            <div>
                <strong>Release to production</strong>
                <p class="mb-0 small text-secondary">When a new version is tagged, the translations go live on the production site along with all other changes.</p>
            </div>
        </div>
    </div>
</div>


<!-- ================================================================== -->
<!-- Section 9: Admin Settings -->
<!-- ================================================================== -->
<div class="portal-card p-4 mb-4" id="admin-settings">
    <h2 class="h4 mb-3"><i class="fa-solid fa-sliders me-2 text-primary" aria-hidden="true"></i>Admin Settings</h2>

    <div class="alert alert-danger d-flex gap-2 mb-3" role="alert">
        <i class="fa-solid fa-shield-halved mt-1" aria-hidden="true"></i>
        <div>
            <strong>Admin access required.</strong> These settings are only accessible to portal administrators via the Settings page.
        </div>
    </div>

    <p>Two settings control the translation system:</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong><code>i18n.defaultLocale</code></strong>
                    <p class="mb-0 small text-secondary">The default language for users who haven't chosen one. Usually set to <code>en</code> (English).</p>
                </div>
                <span class="badge text-bg-secondary">Default: en</span>
            </div>
        </div>
        <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong><code>i18n.enabled</code></strong>
                    <p class="mb-0 small text-secondary">Whether the translation system is active. Set to <code>true</code> to enable, <code>false</code> to disable (all text will show in English).</p>
                </div>
                <span class="badge text-bg-secondary">Default: true</span>
            </div>
        </div>
    </div>
</div>


<!-- Need more help? -->
<div class="card mt-4 border-0 bg-body-tertiary">
    <div class="card-body text-center py-4">
        <i class="fa-solid fa-headset fa-2x text-secondary mb-3" aria-hidden="true"></i>
        <h5>Still need help?</h5>
        <p class="text-secondary mb-0">Contact your system administrator or raise a support request through your organisation's IT helpdesk.</p>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
