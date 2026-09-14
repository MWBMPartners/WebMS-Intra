<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_03_core_namespaces/core/10_portal_site.php
// Second review, finding 3: the real core Site. A class in another namespace
// with the same short name is declared in 20_vendor_site.php, which sorts
// AFTER this file — the order that let the previous version overwrite this
// class's method list with the vendor one's.
declare(strict_types=1);

namespace Portal\Core;

class Site
{
    public static function coreOnly(): void
    {
    }
}
