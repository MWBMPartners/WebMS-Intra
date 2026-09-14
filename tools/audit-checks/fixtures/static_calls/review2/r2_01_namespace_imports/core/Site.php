<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_01_namespace_imports/core/Site.php
// Fake core class for the second-review fixture set, finding 1 (imports
// belong to their own namespace section). Tokenized and mapped by the check,
// and loaded by real PHP only to prove the apps/ files behave as described.
declare(strict_types=1);

namespace Portal\Core;

class Site
{
    public static function ok(int $x): void
    {
    }
}
