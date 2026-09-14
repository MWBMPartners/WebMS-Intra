<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_03_core_namespaces/core/20_vendor_site.php
// Second review, finding 3: a class in the core folder that is NOT in
// Portal\Core but shares the short name Site. The previous version filed
// every class under its short name alone, so this one replaced
// Portal\Core\Site in the map.
declare(strict_types=1);

namespace Vendor;

class Site
{
    public static function vendorOnly(): void
    {
    }
}
