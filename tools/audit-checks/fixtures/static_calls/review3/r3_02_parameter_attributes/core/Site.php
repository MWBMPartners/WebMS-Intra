<?php
// Path: tools/audit-checks/fixtures/static_calls/review3/r3_02_parameter_attributes/core/Site.php
// Third review, finding 2: an attribute on a parameter can hold code that
// contains `...` or `=` — a first-class callable `Site::ok(...)`, or a closure
// with its own default value. Both are allowed in attribute arguments since
// PHP 8.5. Neither says anything about the parameter the attribute is attached
// to.
//
// The previous version looked for `...` and `=` ANYWHERE inside a parameter,
// attribute included. So it treated need() as taking any number of arguments
// and needClosure() as having a default, and a call to either with no
// arguments passed the check — while real PHP throws ArgumentCountError.
//
// spread() and optional() are the controls: there the `...` and the `=`
// belong to the parameter itself, so a call with no arguments is fine.
//
// Requires PHP 8.5 (callables and closures in attribute arguments). This
// folder is never linted by the pull-request workflow's PHP 8.4 jobs, which
// only read web/.
declare(strict_types=1);

namespace Portal\Core;

class Site
{
    public static function ok(): string
    {
        return "Site::ok\n";
    }

    // REQUIRED: the `...` is inside the attribute, not before $value.
    public static function need(#[A(Site::ok(...))] $value): string
    {
        return "Site::need\n";
    }

    // REQUIRED: the `=` is the closure's own default, not $value's.
    public static function needClosure(#[A(static function (int $a = 1): int {
        return $a;
    })] $value): string {
        return "Site::needClosure\n";
    }

    // OPTIONAL: $values itself is variadic.
    public static function spread(#[A(Site::ok(...))] ...$values): string
    {
        return "Site::spread\n";
    }

    // OPTIONAL: $value itself has a default.
    public static function optional(#[A(Site::ok(...))] $value = null): string
    {
        return "Site::optional\n";
    }
}
