<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_01_namespace_imports/apps/10_vendor_in_one_core_in_two.php
// Second review, finding 1 — FALSE ACCUSATION shape. PHP forgets every `use`
// line each time a new `namespace` statement starts, so the two `Site` names
// below are two different classes: Vendor\Site in One, Portal\Core\Site in
// Two. The previous version kept ONE import list for the whole file, so the
// later import won and the call in One was checked against the core Site,
// which has no vendorOnly(). Real PHP runs this file without error.
// Must NOT be accused of anything.
declare(strict_types=1);

namespace One;

use Vendor\Site;

Site::vendorOnly();

namespace Two;

use Portal\Core\Site;

Site::ok(1);
