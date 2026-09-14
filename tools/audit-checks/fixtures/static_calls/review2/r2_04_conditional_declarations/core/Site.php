<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_04_conditional_declarations/core/Site.php
// Second review, finding 4: a class declared inside an if/else. Which of the
// two declarations exists depends on a condition only known when the file
// runs. The previous version kept whichever it read LAST — here the empty
// one — and so reported the real Site::free() as missing. The same question
// arises for the if/else written with colons and `endif` (AltSite), which has
// no braces at all, so it is covered too.
declare(strict_types=1);

namespace Portal\Core;

if (true) {
    class Site
    {
        public static function free(): void
        {
        }
    }
} else {
    class Site
    {
    }
}

if (true):
    class AltSite
    {
        public static function free(): void
        {
        }
    }
else:
    class AltSite
    {
    }
endif;
