<?php
// Path: tools/audit-checks/fixtures/static_calls/review3/r3_01_dynamic_class_expressions/core/Site.php
// Third review, finding 1: the class the app files NAME in their calls. It has
// ok() but not authOnly(). Auth (next to this file) has authOnly() but not
// ok(). Those two differences are what turn a wrong guess about which class a
// call reaches into a visible wrong answer.
declare(strict_types=1);

namespace Portal\Core;

class Site
{
    public static function ok(): string
    {
        return "Site::ok\n";
    }
}
