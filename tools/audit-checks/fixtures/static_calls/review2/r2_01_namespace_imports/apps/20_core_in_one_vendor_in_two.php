<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_01_namespace_imports/apps/20_core_in_one_vendor_in_two.php
// Second review, finding 1 — MISSED FAULT shape: the same two imports as the
// file beside this one, in the opposite order. In namespace Three, `Site`
// really is Portal\Core\Site, which has no missing(), so real PHP stops with
// "Call to undefined method" on that line. The previous version let the
// later `use Vendor\Site;` overwrite the earlier import, decided the call was
// about some other vendor's class, and stayed silent.
// MUST be reported: Site::missing(), no such method.
declare(strict_types=1);

namespace Three;

use Portal\Core\Site;

Site::missing();

namespace Four;

use Vendor\Site;

Site::vendorOnly();
