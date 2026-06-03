<?php
// Path: public_html/admin/upgrade.php
/**
 * -----------------------------------------------------------------------------
 * Admin → Upgrade — front-controller proxy 🪞
 * -----------------------------------------------------------------------------
 * The actual upgrade handler lives at web/_install/upgrade.php (outside
 * public_html/ so it isn't web-accessible directly). The Router serves
 * routeKey `admin/upgrade` from this file; we require the real handler.
 *
 * Why the proxy: route targetFiles are resolved relative to PORTAL_APPS
 * (web/public_html/), so they cannot reference paths outside that root.
 * A 1-line proxy keeps the upgrade handler's "bootstrap + admin gate"
 * logic in one place while still satisfying the routing model. The route
 * was previously misconfigured with `targetFile = '../install/upgrade.php'`,
 * which 404s on click — see issue #202.
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/202
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🪞 Delegate to the bootstrap-aware upgrade handler in _install/.
//    PORTAL_ROOT points at web/, so this resolves to web/_install/upgrade.php.
require PORTAL_ROOT . DIRECTORY_SEPARATOR . '_install' . DIRECTORY_SEPARATOR . 'upgrade.php';
