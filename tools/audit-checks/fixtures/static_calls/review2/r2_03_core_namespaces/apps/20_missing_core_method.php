<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_03_core_namespaces/apps/20_missing_core_method.php
// Second review, finding 3 — MISSED FAULT shape. vendorOnly() exists on
// Vendor\Site, not on Portal\Core\Site, so real PHP stops here with "Call to
// undefined method". The previous version passed it, because Vendor\Site's
// methods had overwritten the core Site's in its map.
// MUST be reported: Site::vendorOnly(), no such method.
declare(strict_types=1);

use Portal\Core\Site;

Site::vendorOnly();
