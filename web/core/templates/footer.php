<?php
// Path: core/templates/footer.php
/**
 * -----------------------------------------------------------------------------
 * Shared Page Footer Template 📄
 * -----------------------------------------------------------------------------
 * Closes the main content container, renders the site footer with copyright,
 * includes JavaScript assets (Bootstrap JS + portal.js), renders the debug
 * panel (if active), and closes <body> and <html> tags.
 *
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   MIT
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Asset;
use Portal\Core\Debug;

// 📌 Copyright information from settings
$copyrightOrg  = App::settings('site.copyrightOrg') ?? 'MWBM Partners Ltd';
$copyrightYear = App::settings('site.copyrightStartYear') ?? '2025';
$currentYear   = date('Y');

// 📅 Format the copyright year range
$yearDisplay = $copyrightYear;
if ($copyrightYear !== $currentYear) {
    $yearDisplay = $copyrightYear . '-' . $currentYear;
}
?>

</div><!-- /.container -->
</main>

<!-- 📌 Site Footer -->
<footer class="portal-footer">
    <div class="container text-center">
        <span>&copy; <?php echo htmlspecialchars($yearDisplay, ENT_QUOTES, 'UTF-8'); ?>
              <?php echo htmlspecialchars($copyrightOrg, ENT_QUOTES, 'UTF-8'); ?>.
              All rights reserved.</span>
        <span class="d-none d-md-inline ms-2 text-muted">
            v<?php echo htmlspecialchars(App::version(), ENT_QUOTES, 'UTF-8'); ?>
        </span>
    </div>
</footer>

<!-- 📦 JavaScript (CDN with local fallback) -->
<?php echo Asset::bootstrapJs(); ?>

<?php echo Asset::portalJs(); ?>

<?php
// 🐛 Debug panel (only renders for admins with ?debug=true)
echo Debug::renderPanel();
?>

</body>
</html>
