<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_03_core_namespaces/apps/10_real_methods.php
// Second review, finding 3 — FALSE ACCUSATION shapes. Both calls are real and
// real PHP runs this file without error:
//   - coreOnly() genuinely exists on Portal\Core\Site; the previous version
//     had replaced that class's methods with Vendor\Site's.
//   - fromVendorBase() is inherited from Vendor\Base; the previous version
//     looked for it on Portal\Core\Base instead.
// Must NOT be accused. The Child call must be counted as "left alone",
// because Child's real parent is outside what the check can see.
declare(strict_types=1);

use Portal\Core\Child;
use Portal\Core\Site;

Site::coreOnly();
Child::fromVendorBase();
