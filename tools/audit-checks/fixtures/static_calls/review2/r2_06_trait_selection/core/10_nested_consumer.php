<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_06_trait_selection/core/10_nested_consumer.php
// Second review, finding 6(c): a class that uses a trait which itself settles
// a conflict between two further traits with `insteadof`. This file sorts
// BEFORE the one declaring those traits, which is the order that made the
// previous version ignore the inner `insteadof`: it only applied `insteadof`
// while walking its list of classes, so the answer depended on which
// declaration it happened to reach first. (Real PHP needs the traits loaded
// before this class; in the portal an autoloader or require order does that.)
declare(strict_types=1);

namespace Portal\Core;

class NestedConsumer
{
    use NestedPicker;
}
