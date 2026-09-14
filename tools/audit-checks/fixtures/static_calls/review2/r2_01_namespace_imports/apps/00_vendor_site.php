<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_01_namespace_imports/apps/00_vendor_site.php
// Support file for finding 1: a class that is NOT in Portal\Core but has the
// same short name as the core Site. It lives in apps/, not core/, so the
// check never maps it and can never verify a call against it.
declare(strict_types=1);

namespace Vendor;

class Site
{
    public static function vendorOnly(): string
    {
        return 'vendor';
    }
}
