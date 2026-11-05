<?php
// Path: web/_apps/admin/check/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Check this portal 🔎
 * -----------------------------------------------------------------------------
 * Shows what `Portal\Core\PortalCheck` found, in plain English. Read-only: this
 * page reports and never fixes (#477). Fixing from here would invite a
 * different class of accident, and the owner's own note on the issue says so.
 *
 * The decision about WHO may open it is a capability, not a role written into
 * this file (D-017): `Capabilities::PORTAL_CHECK`, shipping with `staff` and
 * re-pointable by the organisation. The `App::isAdmin()` ground beside it is
 * the same one every other capability gate carries — it is what makes an
 * emptied capability recoverable rather than a lock-out.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/477
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\PortalCheck;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false && App::can(Capabilities::PORTAL_CHECK) === false) {
    http_response_code(403);
    exit('Forbidden');
}

$findings = PortalCheck::run($mysqli, Site::id());
$tally    = PortalCheck::tally($findings);

$pageTitle = 'Check this portal';
$pageDescription = 'A read-only list of anything inconsistent in how this portal is set up.';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

/** The words and colour for each severity, in one place so the page cannot disagree with itself. */
$look = [
    PortalCheck::PROBLEM => ['Needs attention', 'danger'],
    PortalCheck::WARNING => ['Worth knowing',   'warning'],
    PortalCheck::NOTE    => ['For information', 'secondary'],
];
?>
<div class="container-fluid px-4 py-4">

  <h1 class="h3 mb-1">Check this portal</h1>
  <p class="text-body-secondary">
    What this looks for is the kind of thing that breaks quietly: a menu link
    with no page behind it, a setting stored twice, a help guide nobody can
    reach. None of those writes an error anywhere, so without a page like this
    they are only found by somebody reading the code.
  </p>

  <div class="alert alert-light border d-flex flex-wrap gap-3 align-items-center" role="status">
    <strong>
      <?php echo (int) $tally[PortalCheck::PROBLEM]; ?> needing attention,
      <?php echo (int) $tally[PortalCheck::WARNING]; ?> worth knowing,
      <?php echo (int) $tally[PortalCheck::NOTE]; ?> for information.
    </strong>
    <span class="text-body-secondary small">
      <!-- ⚠️ THE HONEST SENTENCE, AND IT IS NOT A DISCLAIMER. Every fault the
           September audit found was invisible here, and a page that implied
           "nothing found, therefore nothing wrong" would re-create exactly the
           false reassurance it exists to end. -->
      Nothing needing attention does not mean the portal is correct — only that
      none of the things this page knows how to look for is wrong today.
    </span>
  </div>

  <p class="small text-body-secondary">
    This page changes nothing. For whether the <em>server</em> is well — disk
    space, backups, mail, errors — see
    <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/health'), ENT_QUOTES, 'UTF-8'); ?>">System health</a>.
  </p>

  <?php foreach ($findings as $finding): ?>
    <?php [$word, $colour] = $look[$finding['severity']] ?? ['For information', 'secondary']; ?>
    <div class="card mb-3 border-<?php echo htmlspecialchars($colour, ENT_QUOTES, 'UTF-8'); ?>">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-baseline mb-2">
          <span class="badge text-bg-<?php echo htmlspecialchars($colour, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($word, ENT_QUOTES, 'UTF-8'); ?>
          </span>
          <span class="text-body-secondary small">
            <?php echo htmlspecialchars((string) $finding['area'], ENT_QUOTES, 'UTF-8'); ?>
          </span>
        </div>

        <p class="fw-semibold mb-1">
          <?php echo htmlspecialchars((string) $finding['what'], ENT_QUOTES, 'UTF-8'); ?>
          <?php if ((int) $finding['count'] > 0): ?>
            <span class="text-body-secondary fw-normal">
              (<?php echo (int) $finding['count']; ?>)
            </span>
          <?php endif; ?>
        </p>

        <p class="mb-1"><?php echo htmlspecialchars((string) $finding['why'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="mb-2 small"><strong>What to do:</strong>
          <?php echo htmlspecialchars((string) $finding['todo'], ENT_QUOTES, 'UTF-8'); ?>
        </p>

        <?php if (count($finding['examples']) > 0): ?>
          <ul class="small mb-0">
            <?php foreach ($finding['examples'] as $example): ?>
              <li><code><?php echo htmlspecialchars((string) $example, ENT_QUOTES, 'UTF-8'); ?></code></li>
            <?php endforeach; ?>
          </ul>
          <?php if ((int) $finding['count'] > count($finding['examples'])): ?>
            <p class="small text-body-secondary mt-2 mb-0">
              <!-- The COUNT above is exact; this list is a sample. A finding
                   naming four hundred rows is a finding nobody reads. -->
              Showing <?php echo count($finding['examples']); ?>
              of <?php echo (int) $finding['count']; ?>.
            </p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

</div>
<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
