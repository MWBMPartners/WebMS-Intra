<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_03_core_namespaces/core/40_child.php
// Second review, finding 3: `extends Base` here means Vendor\Base, because of
// the `use` line. The previous version ignored imports inside the core
// folder, read the bare word Base as Portal\Core\Base, and so judged Child's
// calls against the wrong parent. Vendor\Base is declared outside the core
// folder (apps/00_vendor_base.php), so the check cannot see it: Child's
// parent is unknown and every call on Child must be left alone.
declare(strict_types=1);

namespace Portal\Core;

use Vendor\Base;

class Child extends Base
{
}
