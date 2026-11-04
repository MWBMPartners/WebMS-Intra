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
 * 🛠️ Release process — THE NUMBER IS SET AT RELEASE TIME, NOT ON EVERY PUSH
 *    (the owner, 4 November 2026 — docs/decisions/024-...md, issues #476 and
 *    #474):
 *   1. Run the "Version Bump" workflow BY HAND against the branch being
 *      prepared, or edit the string below. It used to be raised automatically
 *      on every push to `alpha` and `beta`; that stopped, because a job
 *      writing straight to a branch is what prevented those branches from
 *      being guarded at all — and because it had not actually run since
 *      7 July 2026, which is why `alpha` reported a LOWER number than `beta`
 *      and `main` for four months.
 *   2. Update the CHANGELOG.md entry header.
 *   3. The number travels to `main` through the merge, like everything else.
 *      `package.yml` REFUSES to build a release whose number was not raised
 *      above the last tag, which is what makes step 1 impossible to forget.
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
 * @version   1.4.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

return '1.4.1';
