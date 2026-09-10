<?php
// Path: _core/version.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Authoritative Version Number 📌
 * -----------------------------------------------------------------------------
 * SINGLE SOURCE OF TRUTH for the portal version string. Both the runtime
 * (via Portal\Core\App and bootstrap.php → PORTAL_VERSION) AND the
 * bootstrap-free installer (web/_install/index.php → INSTALL_VERSION)
 * read this file to derive their displayed version.
 *
 * 🛠️ Release process:
 *   1. Bump the string returned below
 *   2. Update CHANGELOG.md entry header
 *   3. Tag the release commit
 * Nothing else needs to change to keep the installer + runtime in sync.
 *
 * 📐 Design notes:
 *   - Returns a plain string so it can be `require`d from both classes
 *     (which have a namespace) and bootstrap-free scripts (which don't).
 *   - Bootstrap-free: this file MUST NOT use the Portal\Core\ namespace or
 *     reference any class, constant, or function defined elsewhere. It is
 *     loaded by the installer BEFORE any autoloader is available.
 *   - This file is the ONLY source of the version. There used to be a
 *     `portal.version` setting in the database that App::init() would prefer
 *     whenever it had a value. That quietly defeated the point of this file:
 *     a stale seed meant the portal reported itself as version 0.1.0. The
 *     setting is no longer read (see App::init()) and is removed by
 *     migration 187.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return '1.4.0';
