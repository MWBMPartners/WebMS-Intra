<?php
// Path: _core/templates/install-wide-notice.php
/**
 * -----------------------------------------------------------------------------
 * "This organisation uses its own value here" 🏢 (#943, #674)
 * -----------------------------------------------------------------------------
 * The ONE notice an admin page shows when its boxes hold the INSTALLATION's
 * settings while the organisation it is opened in holds a value of its own for
 * some of them.
 *
 * WHY IT EXISTS. A page whose Save writes installation-wide (`siteID IS NULL`)
 * must fill its boxes from the installation's own rows
 * (`InstallationSettings::value()`), never from the merged settings, or a
 * global administrator who opens it inside an organisation with its own value
 * and presses Save untouched makes that value every organisation's (#674,
 * #943). But filling from the installation's rows means the boxes are then NOT
 * what this organisation is using, and saying nothing about that would hide a
 * real difference in behaviour. So the page says so, here.
 *
 * Only the NAMES are printed, never a value: a value here could be an API key.
 *
 * HOW TO USE IT. Set `$installWideDiffering` to the list
 * `InstallationSettings::differsHere(...)` answers, then require this file
 * where the notice should appear. An empty list prints nothing.
 *
 *     $installWideDiffering = InstallationSettings::differsHere('sms');
 *     require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates'
 *           . DIRECTORY_SEPARATOR . 'install-wide-notice.php';
 *
 * WHAT IT CANNOT DO. It cannot say WHY the organisation has its own row: the
 * pages that use it never write one, so somebody put it there some other way.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/943
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$installWideNames = isset($installWideDiffering) === true && is_array($installWideDiffering) === true
    ? array_values(array_filter($installWideDiffering, 'is_string'))
    : [];
?>
<?php if ($installWideNames !== []): ?>
    <div class="alert alert-info" data-install-wide-notice>
        <strong>This organisation has its own value for
        <?php echo count($installWideNames) === 1 ? 'one of these settings' : 'some of these settings'; ?>.</strong>
        This page shows and saves <em>the installation's</em> settings, which every organisation uses
        unless it has its own &mdash; so what you see here is not what this organisation is using for
        <?php echo count($installWideNames) === 1 ? 'this setting' : 'these settings'; ?>:
        <?php foreach ($installWideNames as $i => $name): ?><code><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></code><?php echo $i < count($installWideNames) - 1 ? ', ' : ''; ?><?php endforeach; ?>.
        Saving here does not change this organisation's own value.
    </div>
<?php endif; ?>
