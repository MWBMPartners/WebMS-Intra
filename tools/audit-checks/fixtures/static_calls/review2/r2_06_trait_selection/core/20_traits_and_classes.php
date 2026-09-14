<?php
// Path: tools/audit-checks/fixtures/static_calls/review2/r2_06_trait_selection/core/20_traits_and_classes.php
// Second review, finding 6: four ways the previous version picked the wrong
// method out of a set of traits, each one checked against real PHP.
declare(strict_types=1);

namespace Portal\Core;

// ---- 6(a): a class's OWN method always beats a trait method --------------
// `insteadof` only chooses between the TRAITS. OwnWins declares ok() itself,
// so its own zero-argument ok() is what runs. The previous version let the
// `insteadof` choice (OwnA's ok(), which needs an argument) overwrite it.
trait OwnA
{
    public static function ok(int $x): void
    {
    }
}

trait OwnB
{
    public static function ok(): void
    {
    }
}

class OwnWins
{
    use OwnA, OwnB {
        OwnA::ok insteadof OwnB;
    }

    public static function ok(): void
    {
    }
}

// ---- 6(b): an alias copies the trait it NAMES ----------------------------
// `AliasB::ok as other` gives the name other() to AliasB's ok(), which needs
// no argument. The previous version copied whichever ok() had already won
// the conflict (AliasA's, which needs one).
trait AliasA
{
    public static function ok(int $x): void
    {
    }
}

trait AliasB
{
    public static function ok(): void
    {
    }
}

class AliasUser
{
    use AliasA, AliasB {
        AliasA::ok insteadof AliasB;
        AliasB::ok as other;
    }
}

// ---- 6(c): a trait's own `insteadof`, used by NestedConsumer -------------
trait NestedA
{
    public static function ok(int $x): void
    {
    }
}

trait NestedB
{
    public static function ok(): void
    {
    }
}

trait NestedPicker
{
    use NestedA, NestedB {
        NestedB::ok insteadof NestedA;
    }
}

// ---- 6(d): an abstract trait method does not hide an inherited one -------
// NeedsOk only says "whoever uses me must have an ok($x)". AbstractChild
// inherits a real ok($x = null) from AbstractParent, which satisfies it, so
// ok() with no argument runs. The previous version let the abstract
// placeholder, which needs one argument, hide the real inherited method.
class AbstractParent
{
    public static function ok($x = null): void
    {
    }
}

trait NeedsOk
{
    abstract public static function ok($x): void;
}

class AbstractChild extends AbstractParent
{
    use NeedsOk;
}
