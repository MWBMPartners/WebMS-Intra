<?php
// Path: tools/audit-checks/fixtures/static_calls/review3/r3_02_parameter_attributes/core/A.php
// Third review, finding 2: a plain attribute class, so the attributes on the
// parameters in Site.php are real, complete PHP. Reading one back through
// reflection and calling newInstance() works, which proves the attribute
// arguments are valid and not just tolerated by the syntax check.
declare(strict_types=1);

namespace Portal\Core;

#[\Attribute]
final class A
{
    public function __construct(public mixed $value)
    {
    }
}
