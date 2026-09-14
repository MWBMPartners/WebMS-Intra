<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_03_core_namespaces/core/30_portal_base.php
// Second review, finding 3: a core Base class. Child (40_child.php) imports a
// DIFFERENT Base and extends that one, so Child must never be treated as
// inheriting this class's methods.
declare(strict_types=1);

namespace Portal\Core;

class Base
{
    public static function fromCoreBase(): void
    {
    }
}
