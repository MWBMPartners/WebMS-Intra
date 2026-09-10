<?php
// Path: _apps/admin/upgrade.php
/**
 * -----------------------------------------------------------------------------
 * Admin → Upgrade — front-controller proxy 🪞
 * -----------------------------------------------------------------------------
 * The actual upgrade handler lives at web/_install/upgrade.php (outside
 * public_html/ so it isn't web-accessible directly). The Router serves
 * routeKey `admin/upgrade` from this file; we require the real handler.
 *
 * Why the proxy: a route's target file is looked for under PORTAL_APPS
 * (web/_apps/), so it cannot name a path outside that folder. The real
 * handler deliberately lives in web/_install/, outside the part of the
 * site the web server hands out, so it cannot be opened directly.
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
