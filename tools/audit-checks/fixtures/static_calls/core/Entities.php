<?php
// Path: tools/audit-checks/fixtures/static_calls/core/Entities.php
/**
 * -----------------------------------------------------------------------------
 * Fixture core classes for tools/static-calls-selftest.php
 * -----------------------------------------------------------------------------
 * A small, fake stand-in for web/_core/*.php — every class, trait, enum and
 * interface tools/audit-checks/check_static_calls.php's own self-test needs
 * to prove the 17 documented cases (10 new, 7 carried over from the old
 * script) against. This file is never scanned in a normal run: the self-test
 * points --core and --root explicitly at tools/audit-checks/fixtures/
 * static_calls/, and neither that directory nor tools/ itself is one of the
 * four real scan roots (web/_core, web/_apps, web/public_html, web/_install).
 *
 * Every method body here is deliberately empty or trivial — this file exists
 * to be TOKENIZED and MAPPED, not to run.
 *
 * @package   Portal\Tools\Fixtures
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/494
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

// -----------------------------------------------------------------------------
// Site — the workhorse used by most of the import-resolution cases (1, 2, 3,
// 7, 10-adjacent) and by the carried-over "Site::name() IS reported" case.
// Deliberately has NO name() method — the real historical bug this whole
// check exists for.
// -----------------------------------------------------------------------------
class Site
{
    public static function ok(int $x): void
    {
    }

    // A method whose body contains a `{$ ... }` string-interpolation opener
    // — extra regression coverage, not one of the 17 documented cases,
    // covering a bug caught and fixed DURING the tokenizer rewrite itself
    // (see check_static_calls.php's own sc_curly_delta() comment for the
    // story): that opener is its own distinct token
    // (T_CURLY_OPEN/T_DOLLAR_OPEN_CURLY_BRACES) but still closes with a
    // plain '}', so a class-body depth counter that only recognised the
    // literal '{' CHARACTER for opening (rather than every token
    // sc_curly_delta() treats as an opener) would count one more '}' than
    // '{' by the time it reached the end of THIS method, and every method
    // declared after it in the file would silently stop being attributed to
    // the class at all. See afterInterpolation() below, and
    // extra04_core_interpolation_method.php, which calls it.
    public static function withInterpolation(): string
    {
        $x = 1;
        return "Value: {$x}";
    }

    // Declared AFTER a method containing string interpolation — the
    // position that exposes the bug described above. If this method were
    // ever silently dropped from Site's method table again, a real call to
    // it would be wrongly reported as calling a method that does not exist.
    public static function afterInterpolation(): void
    {
    }

    public static function zero(): void
    {
    }
}

// -----------------------------------------------------------------------------
// Auth — used for the "core class used with no import IS reported" case
// (carried over from the old script; mirrors the real Auth::csrfToken()
// outage this check exists for).
// -----------------------------------------------------------------------------
class Auth
{
    public static function csrfToken(): string
    {
        return '';
    }
}

// -----------------------------------------------------------------------------
// Greetable / Greeter — "a trait method is not accused" (carried over).
// -----------------------------------------------------------------------------
trait Greetable
{
    public static function greet(): string
    {
        return 'hi';
    }
}

class Greeter
{
    use Greetable;
}

// -----------------------------------------------------------------------------
// OrphanChild — "a class with an unknown parent is skipped" (carried over).
// The parent is a bare, unqualified name that simply isn't mapped anywhere
// (not a leading-backslash case — see Weird/Base below for that one).
// -----------------------------------------------------------------------------
class OrphanChild extends NoSuchParent
{
}

// -----------------------------------------------------------------------------
// Base / Weird — fault 10: `extends \Base` (a LEADING BACKSLASH, single
// segment) must be treated as an unknown GLOBAL parent, never silently
// matched to this Portal\Core\Base just because the short name happens to
// coincide. Weird::anything() must be SKIPPED, not checked against Base's
// own methods.
// -----------------------------------------------------------------------------
class Base
{
    public static function realMethod(): void
    {
    }
}

class Weird extends \Base
{
    public static function ok(): void
    {
    }
}

// -----------------------------------------------------------------------------
// TraitA / TraitB / Conflicted — fault 4: trait conflict resolution. Both
// traits declare `ok()`; TraitA's takes one required argument, TraitB's
// takes none. `Conflicted` resolves the clash in TraitB's favour, so
// `Conflicted::ok()` called with ZERO arguments must NOT be accused (TraitB's
// zero-argument version is the one that actually runs) — the old script
// always judged this kind of call against whichever trait's method its own
// (undefined) merge order happened to keep, which for the real house
// precedent this mirrors was consistently the wrong one.
// -----------------------------------------------------------------------------
trait TraitA
{
    public static function ok(int $mustHaveThis): void
    {
    }
}

trait TraitB
{
    public static function ok(): void
    {
    }
}

class Conflicted
{
    use TraitA, TraitB {
        TraitB::ok insteadof TraitA;
    }
}

// -----------------------------------------------------------------------------
// Status — fault 8: enums are mapped, and DO have static methods (both a
// hand-written one and the built-ins). Status::fromLabel() and
// Status::cases() must resolve cleanly; Status::missing() must be reported.
// -----------------------------------------------------------------------------
enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public static function fromLabel(string $label): self
    {
        return self::Active;
    }
}

// -----------------------------------------------------------------------------
// Thing — fault 8's interface half: interfaces are mapped too. Thing::make()
// is declared (no body — interface methods never have one); calling
// Thing::missing() would be reported if anything ever called it directly
// (nothing in these fixtures does — interfaces are rarely called on
// directly, since their methods have no bodies to run — but mapping them at
// all, rather than silently ignoring them, is the point being proven; see
// the self-test for the positive existence check).
// -----------------------------------------------------------------------------
interface Thing
{
    public static function make(): self;
}
