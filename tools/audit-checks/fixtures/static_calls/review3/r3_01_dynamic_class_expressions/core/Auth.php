<?php
// Path: tools/audit-checks/fixtures/static_calls/review3/r3_01_dynamic_class_expressions/core/Auth.php
// Third review, finding 1: the class the calls REALLY reach at runtime, because
// the constant and the property called `Site` in the app files both hold
// Auth::class. It has authOnly() but not ok().
declare(strict_types=1);

namespace Portal\Core;

class Auth
{
    public static function authOnly(): string
    {
        return "Auth::authOnly\n";
    }
}
