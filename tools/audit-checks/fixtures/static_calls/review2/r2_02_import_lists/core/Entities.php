<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_02_import_lists/core/Entities.php
// Fake core classes for the second-review fixture set, finding 2 (every name
// in a comma-separated import counts; a grouped `function`/`const` item is
// not a class import).
declare(strict_types=1);

namespace Portal\Core;

class Site
{
    public static function free(): void
    {
    }
}

class Auth
{
    public static function ok(): void
    {
    }
}
