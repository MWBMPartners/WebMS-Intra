<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_05_by_reference_return/core/Site.php
// Second review, finding 5: methods that return a reference (`function &name`).
// PHP's tokenizer does not give that `&` back as the plain character; it has
// its own token for it. The previous version only recognised the plain
// character, so it never saw the method name and left both methods out of
// the map entirely.
declare(strict_types=1);

namespace Portal\Core;

class Site
{
    /** @var array<int, int> */
    private static array $data = [];

    public static function &ok(): array
    {
        return self::$data;
    }

    public static function &need(int $x): array
    {
        return self::$data;
    }
}
