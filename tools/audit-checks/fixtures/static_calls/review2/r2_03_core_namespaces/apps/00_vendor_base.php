<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_03_core_namespaces/apps/00_vendor_base.php
// Support file for finding 3: the parent class Portal\Core\Child really
// extends. Outside the core folder on purpose, so the check never maps it.
declare(strict_types=1);

namespace Vendor;

class Base
{
    public static function fromVendorBase(): void
    {
    }
}
