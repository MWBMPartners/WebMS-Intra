<?php
// Path: tools/audit-checks/check_static_calls.php
/**
 * -----------------------------------------------------------------------------
 * Static-method call check: does the class really have that method, and did
 * the call pass what it needs? — rebuilt on PHP's own tokenizer (#494 follow-up)
 * -----------------------------------------------------------------------------
 *
 * WHY THIS EXISTS
 * ----------------
 * Three public pages called `Site::name()`. That method does not exist — Site
 * has never had one. Two of the same three pages also called `Site::branding()`
 * with no argument, when `branding()` takes one required argument. A separate
 * real fault — `web/_apps/admin/activity/index.php` calling `Auth::csrfToken()`
 * with no `use Portal\Core\Auth;` anywhere in the file — crashed the whole
 * Activity Log page the same way: a fatal PHP error, on the SUCCESS path, that
 * nothing else in this project's checks reads a method name closely enough to
 * catch. `php -l` only parses syntax; `Site::name()` is syntactically fine PHP.
 * See the original `check_static_calls.py` history and issue #494 for the full
 * story of how those four calls shipped and stayed invisible.
 *
 * WHY THIS FILE EXISTS — THE HAND-WRITTEN LEXER WAS WRONG, PROVABLY
 * --------------------------------------------------------------------
 * The first version of this check (1,486 lines of Python, `check_static_calls
 * .py`, now a thin shim over this file) parsed PHP with a hand-written,
 * character-by-character lexer: its own idea of where a string starts and
 * ends, where a comment starts and ends, where a heredoc's closing marker is.
 * An independent review by Codex reproduced every one of the ten faults below
 * against it, and each one is confirmed here, against the REAL PHP tokenizer,
 * as the reason this file exists. Nobody should ever "simplify" this back into
 * a hand-rolled lexer — that is exactly the design this file replaces, and
 * every fault below is a concrete, reproduced case of it being wrong.
 *
 * FALSE ACCUSATIONS the old version made — valid PHP it reported as broken:
 *   1. A grouped import — `use Portal\Core\{Site, Auth};` then `Site::ok(1);`
 *      — was reported as "missing import" because the old regex only ever
 *      recognised `use Portal\Core\ClassName;`, one class per line.
 *   2. A completely different class that merely shares a short name — `use
 *      Vendor\Site;` then `Site::ok(1);` — was reported as "missing CORE
 *      import", even though this `Site` is not `Portal\Core\Site` at all.
 *   3. The bracketed namespace form — `namespace Portal\Core { ... }` — was
 *      never recognised as declaring the namespace, so every bare reference
 *      inside it was reported as unimported.
 *   4. Trait conflict resolution — two traits, `use A, B { B::ok insteadof
 *      A; }`, selecting B's zero-argument `ok()` — the old version still
 *      judged the call against A's argument count.
 *   5. A backtick shell-execution string containing the literal text
 *      `Site::missing()` was read as a real call, because the old lexer's
 *      string handling could be fooled by backticks.
 *   6. A nested heredoc whose inner text happened to contain the outer
 *      heredoc's own closing marker could desynchronise the old lexer's
 *      notion of "am I inside a string right now", making later, real PHP
 *      look like string content or vice versa.
 *
 * MISSED FAULTS the old version let through — genuinely broken PHP it called
 * fine:
 *   7. An aliased import — `use Portal\Core\Site as S;` then `S::missing();`
 *      — was silently ignored: the old version never tracked aliases at all,
 *      so a call through the alias was invisible to it.
 *   8. Enums and interfaces were never mapped, only classes and traits — so
 *      a nonexistent method called on an enum (enums genuinely do have
 *      static methods, both built-in ones like `cases()`/`from()`/
 *      `tryFrom()` and hand-written ones) passed clean every time.
 *   9. A call written inside string interpolation — `echo "{$a[Site::
 *      missing()]}";` — was never inspected, even though PHP really parses
 *      and calls it at runtime and really throws if it does not exist.
 *  10. `extends \Base` was mapped onto `Portal\Core\Base` whenever a class of
 *      that name happened to exist, rather than being treated as an unknown
 *      GLOBAL parent (a leading backslash means "start from the very top",
 *      not "look in Portal\Core").
 *
 * HOW THE TOKENIZER FIXES ALL TEN AT ONCE
 * ------------------------------------------
 * `token_get_all()`, called with the `TOKEN_PARSE` flag, is the same code
 * that compiles this file for real. It already knows, with certainty, every
 * rule the old lexer was guessing at:
 *
 *   - A double-quoted string's `{$ ... }` complex-interpolation block is not
 *     "string content" to the tokenizer — PHP really does parse the tokens
 *     inside it as ordinary code (that is precisely fault 9, and precisely
 *     why it must be caught, not skipped). A `T_CONSTANT_ENCAPSED_STRING`
 *     literal typed INSIDE that block (`"{$a["Site::x()"]}"`) is still one
 *     single opaque string token, never decomposed into `Site`/`::`/
 *     `missing`/`(`/`)` — so the nested-string case that must NOT be
 *     accused (see fixture case 9b) is told apart automatically, for free,
 *     with no special-case code anywhere in this file.
 *   - A backtick string's content — fault 5 — is one single
 *     `T_ENCAPSED_AND_WHITESPACE` token, never separate identifier/`::`/
 *     identifier tokens, so it can never match the call pattern below no
 *     matter what text it contains.
 *   - A heredoc's real closing marker — however it is nested, however it is
 *     followed on the same line — is exactly whatever the tokenizer says it
 *     is, because it is the same tokenizer that will actually run the file.
 *     Fault 6 cannot happen here; there is no separate "where does the
 *     heredoc end" guess to get wrong.
 *   - `TOKEN_PARSE` additionally folds a qualified name into ONE token —
 *     `T_NAME_QUALIFIED` (`Vendor\Site`, no leading `\`), `T_NAME_FULLY_
 *     QUALIFIED` (`\Portal\Core\Site`, leading `\`), or `T_NAME_RELATIVE`
 *     (`namespace\Foo`) — instead of a `T_STRING`/`T_NS_SEPARATOR` sequence
 *     this file would otherwise have to reassemble by hand. The full text of
 *     that one token is what sc_resolve_class_name() reads, so a leading-
 *     backslash call to some other path (`\Vendor\Site::method()`) can never
 *     be confused with `\Portal\Core\Site`, and `extends \Base` means the
 *     top-level Base, never Portal\Core\Base (fault 10).
 *
 * SECOND REVIEW — SIX MORE FAULTS THE TOKENIZER VERSION STILL HAD
 * -----------------------------------------------------------------
 * Codex then reviewed the tokenizer version and reproduced eight more faults:
 * six in this file, two in the Python wrapper (see check_static_calls.py).
 * Each was confirmed against real PHP before it was fixed, and each has its
 * own small fixture set under tools/audit-checks/fixtures/static_calls/
 * review2/, run by tools/static-calls-selftest.php:
 *
 *  11. Imports leaked between namespaces. One import list was kept for the
 *      whole file, but PHP forgets every `use` line each time a new
 *      `namespace` statement starts. With `use Vendor\Site;` in one namespace
 *      and `use Portal\Core\Site;` in the next, the later import won
 *      everywhere: a real vendor call was accused, and with the imports the
 *      other way round a real `Site::missing()` passed silently.
 *  12. A comma-separated import (`use Portal\Core\Auth, Portal\Core\Site;`)
 *      only counted its FIRST name, so Site was falsely reported as
 *      unimported. Inside a grouped import, `function Site` and `const Auth`
 *      were treated as CLASS imports, so a genuinely missing class import
 *      went unnoticed. While fixing this a third shape turned up: a one-word
 *      import (`use Auth;`) was read as Portal\Core\Auth and counted as
 *      verified, when PHP reads it as the top-level class \Auth.
 *  13. The class map filed every class under its short name alone, and
 *      ignored `use` lines inside web/_core. A `Vendor\Site` declared in the
 *      core folder replaced `Portal\Core\Site`'s method list, and
 *      `use Vendor\Base; class Child extends Base` was treated as inheriting
 *      Portal\Core\Base.
 *  14. A class declared inside an if/else kept whichever declaration was read
 *      LAST, so a real method in the branch that actually runs was reported
 *      missing.
 *  15. A method that returns by reference (`function &ok()`) vanished from the
 *      map: PHP's tokenizer gives that `&` its own token id, and the old code
 *      only looked for the plain character.
 *  16. Trait method selection gave wrong argument counts in four ways, each of
 *      which runs cleanly in real PHP: an `insteadof` choice overwrote the
 *      class's OWN method; `B::ok as other` copied the wrong trait's ok(); a
 *      trait's own `insteadof` was only honoured if that trait happened to be
 *      processed before the class using it; and an abstract trait method
 *      ("whoever uses me must have an ok($x)") hid a real inherited
 *      ok($x = null).
 *
 * The fixes share one rule, and that rule decides every hard case from here
 * on:
 *
 *   WHEN THE CHECK CANNOT BE SURE WHAT A NAME REFERS TO, IT MUST NEITHER
 *   ACCUSE THE CALL NOR COUNT IT AS VERIFIED.
 *
 * A false accusation gets a check switched off; a false pass hides a real
 * crash. So a call is only judged when the class it names is known for
 * certain, and anything outside what this file models is counted in the
 * "left alone" figures the report prints, rather than guessed at. Where a
 * complete model of PHP's rules would be large — every trait-combination
 * edge case, which branch of an if/else runs — this file deliberately takes
 * the "left alone" route, and says so in a comment at that spot.
 *
 * The code change behind 11-16: the two separate file walkers (one building
 * the class map, one finding calls) became ONE, sc_analyse_file(), because
 * both needed the same namespace and import tracking and two copies had
 * already drifted apart. Every class name — in a call, an `extends`, a trait
 * `use`, an `insteadof` or `as` rule — now goes through ONE function,
 * sc_resolve_class_name(), which applies PHP's own name rules and produces
 * the FULL class name. The map is keyed by that full name.
 *
 * THIRD REVIEW — TWO MORE FAULTS IN THIS FILE
 * ---------------------------------------------
 * Codex's third pass reproduced three more faults: two here, one in the
 * Python wrapper. Each was confirmed by running a fixture in real PHP before
 * it was fixed. The fixtures are under fixtures/static_calls/review3/.
 *
 *  17. A class name held in a constant or a property was read as a literal
 *      class name. In `Holder::Site::ok()` and `$obj->Site::ok()`, `Site` is a
 *      class constant or a property whose VALUE is a class name, and PHP calls
 *      whichever class that value names. The call pattern matched just the
 *      `Site::ok(` part, so the check judged the class literally called Site.
 *      With no import, that meant a false "core class used with no import".
 *      With `use Portal\Core\Site;`, the wrong class was checked: a method
 *      that really exists was reported missing, and a missing one was counted
 *      as verified. Now, whenever `::`, `->` or `?->` comes straight before
 *      the name, the call is counted as "left alone". The report shows how
 *      many were left alone this way. This file does not try to work out the
 *      value, because that would mean following constants and properties
 *      across files, which is well beyond what it models.
 *  18. A parameter's attribute could hide that the parameter is required.
 *      `...` or `=` ANYWHERE inside a parameter counted as "variadic" or "has
 *      a default", including inside an attribute such as
 *      `#[A(Site::ok(...))] $value` or `#[A(static function ($a = 1) {})]
 *      $value` (both legal since PHP 8.5). A call passing no arguments was
 *      then passed, while PHP throws ArgumentCountError. Now only tokens at
 *      the parameter's own level count; see sc_analyse_params().
 *
 * WHAT THIS CHECK DOES
 * ---------------------
 * 1. Reads every class, trait, enum AND interface declared in
 *    `web/_core/*.php` (non-recursive, matching this project's own
 *    documented "framework classes" convention) and records, under its FULL
 *    name, namespace included: every method it declares, how many parameters
 *    each takes, how many are REQUIRED (no default value; a variadic
 *    parameter accepts zero on its own), and whether the method is abstract
 *    (has no body). It also records what it `extends` and which traits it
 *    `use`s, each resolved to a full name at that point in the file. A backed
 *    enum additionally gets `from()` and `tryFrom()` (one required argument
 *    each); every enum gets `cases()`. These are real PHP, not something the
 *    source text spells out, so leaving them out would itself be a false
 *    accusation waiting to happen.
 * 2. Works out each class's real method list the way PHP does: its OWN
 *    methods first; then trait methods, after applying `insteadof` and `as`
 *    rules (including traits that use other traits); then inherited methods,
 *    where an abstract method never hides a real one further up.
 * 3. Scans `web/_core`, `web/_apps`, `web/public_html` and `web/_install`
 *    (recursively) for every `ClassName::methodName(` call.
 * 4. For each call, works out the full class name PHP will look for (see NAME
 *    RESOLUTION below) and, only when that is a mapped class it can be sure
 *    about, checks: does the method exist at all, and — if the call passed
 *    literally zero arguments — does the method need at least one.
 * 5. Separately, flags a call that reaches for a real `Portal\Core` class BY
 *    NAME, with no import, no full path, and no namespace of its own to
 *    explain how PHP could ever find it — the shape of the real
 *    `Auth::csrfToken()` outage. See "CORE CLASS USED WITHOUT BEING
 *    IMPORTED" below.
 *
 * NAME RESOLUTION — PHP's own rules for a class name
 * ----------------------------------------------------
 * sc_resolve_class_name() turns a class name, as written at one position in
 * a file, into the full name PHP will look for:
 *
 *   - `\A\B` (leading backslash): exactly `A\B`, whatever the file imports.
 *   - `namespace\B`: the current namespace, then `\B`.
 *   - `A\B` (no leading backslash): if `A` is an import alias, the imported
 *     name followed by `\B`; otherwise the current namespace, then `\A\B`.
 *   - `B` (one word): if `B` is an import alias, the imported name; otherwise
 *     the current namespace, then `\B`. There is NO fallback to the top level
 *     for a class name — that fallback exists only for functions and
 *     constants.
 *   - `self` and `parent` name "the class this code is in", not a class, so
 *     such calls are not counted at all (`static` is its own keyword token and
 *     never looks like a name in the first place).
 *
 * "Imports" means the class imports made by `use` lines so far in the
 * CURRENT namespace section: PHP clears them at each `namespace` statement
 * (fault 11), and a `use` line only affects code after it. A name inside a
 * `use` line is always read from the top of the namespace tree, so `use
 * Auth;` means `\Auth` (fault 12).
 *
 * A call whose full class name is not a mapped class is left alone for the
 * missing-method and missing-argument checks. There is no autoloader map to
 * consult (no Composer on this project — shared hosting, `.claude/
 * CLAUDE.md`), so guessing what some other class can do would risk exactly
 * the "cries wolf" failure that document warns about.
 *
 * Before the second review, a qualified name with no leading backslash
 * (`Portal\Core\Site::x()`) was never resolved at all, as a deliberate gap,
 * for fear of getting PHP's rule subtly wrong. That gap is gone because the
 * rule above IS PHP's rule, and every other name already goes through it.
 * Written inside `namespace Portal\Core;`, that name means
 * `Portal\Core\Portal\Core\Site`, which is not mapped, so it is still left
 * alone.
 *
 * WHEN THE CHECK CANNOT BE SURE — calls it leaves alone
 * -------------------------------------------------------
 * A call to a mapped class is still NOT judged, and is counted as "left
 * alone" in the report, when any of these is true of that class or of
 * anything it inherits from:
 *   - it defines `__callStatic` (directly, through a parent, or via a trait),
 *     so it can answer to ANY method name at runtime;
 *   - a parent it `extends` is not a mapped class — it may inherit the very
 *     method being called from a class this check never saw;
 *   - a trait it `use`s is not a mapped trait, or the traits are combined in
 *     a way this file does not model: two real methods with the same name and
 *     no `insteadof`, an alias that could mean more than one trait's method,
 *     or an adaptation rule it cannot read;
 *   - it is declared inside anything other than a namespace block — an
 *     if/else (with braces, or written with colons and `endif`), a function,
 *     a loop — so it may not exist at all, or may be a different version
 *     (fault 14);
 *   - the same full name is declared more than once in web/_core, so which
 *     one is loaded depends on something outside the source text.
 *
 * Separately, a call is left alone WHATEVER class it seems to name when `::`,
 * `->` or `?->` comes straight before that name — `Holder::Site::ok()`,
 * `$obj->Site::ok()`. There the "name" is a constant or property holding a
 * class name, and the source text does not say which class (fault 17). These
 * are counted on their own line of the report, because they never resolve to
 * any class at all, so they cannot sit inside the "resolved" figure.
 *
 * Only the ZERO-argument shape of the missing-argument fault is ever
 * reported. `Foo::bar(...)` — PHP 8.1's first-class callable syntax, a
 * reference to the method rather than a call passing nothing — is
 * deliberately NOT treated as zero arguments. This check also does NOT count
 * arguments in a non-empty call, check argument TYPES, check named
 * arguments, judge visibility, or judge whether a call is static-vs-instance
 * correct. See the exact wording printed at the bottom of a clean run.
 *
 * POSITION, NOT FILE — the fix for fault 3, and why it matters
 * ------------------------------------------------------------------
 * The very first version decided "does this file declare `namespace
 * Portal\Core`?" once, for the whole file, with one regex search, so the
 * bracketed form `namespace Portal\Core { ... }` broke it.
 *
 * This file tracks the ACTIVE namespace, and the imports that belong with it,
 * as plain variables updated while walking the token stream once, exactly
 * the way PHP itself would: a semicolon-form `namespace X;` changes the
 * namespace from that point until the next `namespace` statement or end of
 * file; a brace-form `namespace X { ... }` (or bare `namespace { ... }` for
 * the global namespace) changes it only between that `{` and its matching
 * `}`. Both forms start a fresh, empty import list. Every declaration and
 * every call is resolved AT ITS OWN POSITION, never against one average fact
 * about the whole file.
 *
 * CORE CLASS USED WITHOUT BEING IMPORTED
 * -----------------------------------------
 * This is the shape of the real `Auth::csrfToken()` outage: not a method that
 * doesn't exist, but a call to a real one that PHP cannot find, because
 * nothing in the file told it where to look. A one-word class name at a
 * position with no namespace and no matching class import means the
 * top-level class of that name, with no fallback. If none exists and the
 * real class lives in `Portal\Core`, PHP raises `Error: Class "X" not found`
 * the instant the line runs.
 *
 * This check flags exactly that shape: a call `SomeName::method(` where —
 *   - there is no active namespace at the call's own position;
 *   - the name is one word, with no leading backslash;
 *   - no class import under that name is in force at that position (importing
 *     a FUNCTION or CONSTANT of that name does not count — fault 12);
 *   - no top-level `SomeName` is mapped, but `Portal\Core\SomeName` is; and
 *   - nothing in any scanned file declares its OWN top-level `SomeName`
 *     (anywhere, conditionally or not) — because if something does, the name
 *     might genuinely mean that other thing, and guessing would risk the
 *     exact false accusation this file exists to avoid.
 *
 * Exit code:
 *   0 — every static call this check could verify resolves cleanly, and no
 *       core class was reached for without an import
 *   1 — at least one finding, of any of the three kinds above, OR the PHP
 *       source itself could not be tokenized (a genuine parse fault — see
 *       "PARSE FAULTS" below)
 *
 * PARSE FAULTS
 * ------------
 * `token_get_all()` with `TOKEN_PARSE` throws `\ParseError` for source PHP
 * itself cannot make sense of. Every file this check reads is expected to
 * already pass `php -l` (a hard gate in this project's own CI, and checked
 * directly for every fixture this file's self-test uses) — so this should
 * never actually happen on real, checked-in PHP. If it ever does, this check
 * reports it as a finding (with the "•" bullet the pull-request comment
 * filter looks for) and fails the run, rather than silently skipping the
 * file and reporting a false "all clear".
 *
 * Usage:
 *   php tools/audit-checks/check_static_calls.php [--core=DIR] [--root=DIR]...
 *
 * With no arguments, --core defaults to web/_core and --root defaults to
 * web/_core, web/_apps, web/public_html and web/_install, all resolved
 * against this script's own location — exactly the old script's defaults.
 * `tools/static-calls-selftest.php` passes both explicitly, pointed at
 * `tools/audit-checks/fixtures/static_calls/`, so the exact same checking
 * logic that runs on the real codebase also runs against a small, committed
 * set of regression fixtures.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.2.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/494
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// =============================================================================
// 🔤 Tokenizing — hand this straight to PHP's own compiler front-end, then
// throw away only the pieces that can never be part of a real call: literal
// HTML outside a PHP open/close tag pair, comments, and the tag markers
// themselves. Everything else — including string-literal and heredoc CONTENT
// tokens — is kept, because the "is this call genuinely empty?" check later
// needs to know something occupies the parentheses even when it doesn't care
// what that something says (see sc_analyse_params()).
// =============================================================================

/**
 * Tokenize one PHP source file's contents, filtered down to the tokens this
 * check ever needs to look at.
 *
 * @param string $code Raw file contents (`<?php ...`, possibly with HTML
 *                      mixed in — most files under web/_apps do exactly that).
 *
 * @return array<int,array{0:?int,1:string,2:?int}>|null Each entry is
 *     [token id or null for a single-character token, the token's own text,
 *     the line it starts on (only ever null for a single-character token —
 *     nothing in this file ever needs one for those; see the module
 *     docstring)]. Returns null when the source could not be tokenized at
 *     all (see "PARSE FAULTS" in the module docstring).
 */
function sc_tokenize(string $code): ?array
{
    try {
        // TOKEN_PARSE is what makes T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED
        // / T_NAME_RELATIVE exist at all — without it, a qualified name comes
        // back as a T_STRING/T_NS_SEPARATOR sequence this file would have to
        // reassemble by hand, which is exactly the kind of hand-rolled
        // reconstruction the tokenizer rewrite exists to avoid.
        $raw = token_get_all($code, TOKEN_PARSE);
    } catch (\ParseError $e) {
        return null;
    }

    $out = [];
    foreach ($raw as $t) {
        if (is_array($t)) {
            $id = $t[0];
            if ($id === T_WHITESPACE
                || $id === T_COMMENT
                || $id === T_DOC_COMMENT
                || $id === T_OPEN_TAG
                || $id === T_OPEN_TAG_WITH_ECHO
                || $id === T_CLOSE_TAG
                || $id === T_INLINE_HTML
            ) {
                continue;
            }
            $out[] = [$id, $t[1], $t[2]];
        } else {
            $out[] = [null, $t, null];
        }
    }
    return $out;
}

/** True when a token id is one PHP would resolve as a class/trait/enum/
 *  interface NAME — a bare identifier, or any of the three qualified forms
 *  TOKEN_PARSE folds into a single token. */
function sc_is_name_id(?int $id): bool
{
    return $id === T_STRING
        || $id === T_NAME_QUALIFIED
        || $id === T_NAME_FULLY_QUALIFIED
        || $id === T_NAME_RELATIVE;
}

/** True when `$tok` is the single-character token `$char`. */
function sc_is_char(array $tok, string $char): bool
{
    return $tok[0] === null && $tok[1] === $char;
}

/**
 * How much this one token changes bracket-nesting depth: +1 for anything
 * that OPENS a balanced span, -1 for anything that CLOSES one, 0 otherwise.
 *
 * Three of the four "opens" are NOT the plain single character `(`/`[`/`{`
 * — `#[` (an attribute), the `{` that opens a string's `{$ ... }` complex
 * interpolation, and (pre-8.2, still legal to tokenize) `${` are each their
 * OWN token id, distinct from a bare `{`. Their closing character, though,
 * is always the plain single-character `]` or `}` — checked directly against
 * this project's own PHP 8.5 (see the module docstring's proof-of-work run).
 * Missing this would silently desynchronise the depth counter on the very
 * first attribute or interpolation any balanced-span helper below had to
 * step over — an `#[SensitiveParameter]` attribute in a parameter list, for
 * one real example already in this codebase.
 */
function sc_open_delta(array $tok): int
{
    if ($tok[0] === T_ATTRIBUTE || $tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
        return 1;
    }
    if ($tok[0] !== null) {
        return 0;
    }
    return match ($tok[1]) {
        '(', '[', '{' => 1,
        ')', ']', '}' => -1,
        default => 0,
    };
}

/**
 * Like sc_open_delta(), but narrowed to the ONE bracket shape this file's
 * class/function-BODY and namespace-SCOPE tracking below cares about: a
 * curly brace. A plain `{` and PHP's `{$ ... }` string-interpolation opener
 * (`T_CURLY_OPEN`) BOTH close with an ordinary `}` character — checked
 * directly against this project's own PHP 8.5 (see the module docstring's
 * proof-of-work run) — so both have to be treated as the SAME kind of
 * "open" here, or a class body containing so much as one interpolated
 * string such as `"Asset #{$assetId}"` desyncs a `{`/`}`-only depth counter
 * by exactly one: the genuine plain `}` that closes the interpolation gets
 * counted as closing whatever the counter thinks is still open, popping one
 * frame too many. In this file's own class-map builder, that one spurious
 * pop silently ended the entity's own body-tracking wherever the FIRST such
 * interpolated string happened to sit — every method declared after it, for
 * the rest of that file, was then attributed to nothing at all. Caught
 * directly: on the very first real run against `web/_core/AssetRegister
 * .php`, every method declared after that file's first `"...{$assetId}..."`
 * string (line 7280) went missing from the map, and every one of its real
 * callers was wrongly reported as calling a method that doesn't exist —
 * see this file's own commit history / PR description for the exact
 * before/after finding counts. `T_DOLLAR_OPEN_CURLY_BRACES` is included for
 * the same reason, though this codebase does not use that (deprecated
 * since PHP 8.2) interpolation form. `#[Attribute]` is deliberately NOT
 * included here — it closes with `]`, not `}`, so counting it in THIS
 * narrower counter would desync it in the opposite direction; it is only
 * ever relevant to sc_open_delta()'s general-purpose balanced-span helpers
 * above, which extract a parameter list or a trait-adaptation block, never
 * to the body/scope tracking this helper exists for.
 */
function sc_curly_delta(array $tok): int
{
    if ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
        return 1;
    }
    if ($tok[0] !== null) {
        return 0;
    }
    return match ($tok[1]) {
        '{' => 1,
        '}' => -1,
        default => 0,
    };
}

// =============================================================================
// 🧱 Balanced-span helpers — operate on an already-filtered token array, so
// unlike a text-based version these never need to worry about a string
// literal's own content containing a stray bracket: a whole string is ALREADY
// one opaque token here, never decomposed into individual characters.
// =============================================================================

/**
 * `$stream[$openIdx]` must be a token `sc_open_delta()` reports as +1.
 * Returns [inner tokens between the open and its match (exclusive of both),
 * index just past the matching close].
 */
function sc_extract_balanced(array $stream, int $openIdx): array
{
    $depth = 0;
    $n = count($stream);
    $inner = [];
    for ($i = $openIdx; $i < $n; $i++) {
        $delta = sc_open_delta($stream[$i]);
        if ($i > $openIdx) {
            if ($delta < 0) {
                $depth += $delta;
                if ($depth === 0) {
                    return [$inner, $i + 1];
                }
                $inner[] = $stream[$i];
                continue;
            }
            $inner[] = $stream[$i];
        }
        $depth += ($i === $openIdx) ? 1 : ($delta > 0 ? $delta : 0);
    }
    // Unterminated/malformed — best effort, matching every other checker in
    // this directory's "never worse than doing nothing" spirit.
    return [$inner, $n];
}

/** Split a token list on top-level commas (depth 0 within the list itself). */
function sc_split_top_level(array $tokens): array
{
    $parts = [];
    $depth = 0;
    $current = [];
    foreach ($tokens as $tok) {
        $delta = sc_open_delta($tok);
        if ($delta > 0) {
            $depth += $delta;
            $current[] = $tok;
            continue;
        }
        if ($delta < 0) {
            $depth += $delta;
            $current[] = $tok;
            continue;
        }
        if ($depth === 0 && sc_is_char($tok, ',')) {
            $parts[] = $current;
            $current = [];
            continue;
        }
        $current[] = $tok;
    }
    $parts[] = $current;
    return $parts;
}

/**
 * `(total parameter count, required parameter count)` for a method's
 * already-extracted parameter-list tokens. A parameter is OPTIONAL — does
 * not count towards "required" — when it has a default value (a depth-0 `=`
 * token; PHP's tokenizer already folds `==`, `<=`, `>=`, `!=`, `<=>` and
 * `=>` into their own distinct token ids, so a bare single-character `=`
 * token can only ever be the default-value operator, no lookahead/lookbehind
 * needed) or is variadic (a `T_ELLIPSIS` token — `...$rest` is valid on its
 * own with zero arguments supplied for it).
 *
 * Both tokens only count at the parameter's OWN level: outside every bracket
 * inside that parameter. Anything inside brackets belongs to something else —
 * an attribute's arguments, a default value's own contents, or a property
 * hook's body.
 * The previous version looked at every token in the parameter, and that was
 * fault 18 in the module docstring: `#[A(Site::ok(...))] $value` has a `...`
 * (a first-class callable) inside its attribute, and `#[A(static function
 * ($a = 1) {})] $value` has an `=` (the closure's own default). Both
 * attributes are legal since PHP 8.5. In both cases `$value` is still
 * required, but the method was recorded as needing no arguments, so a call
 * passing none went unreported while PHP throws ArgumentCountError. A real
 * `...$rest` or `$x = 1` always sits at the parameter's own level, so the
 * shapes that SHOULD count are still found.
 */
function sc_analyse_params(array $paramTokens): array
{
    $chunks = array_filter(sc_split_top_level($paramTokens), static fn (array $c): bool => $c !== []);
    $total = count($chunks);
    $required = 0;
    foreach ($chunks as $chunk) {
        $isVariadic = false;
        $hasDefault = false;
        // sc_split_top_level() only splits on commas outside brackets, so
        // every chunk starts outside all brackets. An opener itself (such as
        // `#[`) and a closer are never `...` or `=`, so they can simply move
        // the depth and be skipped.
        $depth = 0;
        foreach ($chunk as $tok) {
            $delta = sc_open_delta($tok);
            if ($delta !== 0) {
                $depth += $delta;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($tok[0] === T_ELLIPSIS) {
                $isVariadic = true;
            }
            if (sc_is_char($tok, '=')) {
                $hasDefault = true;
            }
        }
        if (!$isVariadic && !$hasDefault) {
            $required++;
        }
    }
    return ['total' => $total, 'required' => $required];
}

// =============================================================================
// 🗺️  Turning a class name, as written, into the full name PHP will look for
// =============================================================================

/**
 * The full class name, in lower case, that PHP will look for when it meets
 * this name token at this position — or null for `self` and `parent`, which
 * name "the class this code is in" rather than a class. See "NAME
 * RESOLUTION" in the module docstring for the rules, which are PHP's own.
 *
 * Lower case because PHP matches class and namespace names without regard to
 * case: `portal\core\site` and `Portal\Core\Site` are the same class, and
 * `namespace portal\core;` is the same namespace as `Portal\Core`.
 *
 * This is the ONE place a class name becomes a full name — for a call, an
 * `extends`, a trait `use` and a trait `insteadof`/`as` rule alike — so those
 * can never quietly disagree. Before the second review there were three
 * separate partial versions of this (one for calls, one for `extends`/trait
 * names inside web/_core that ignored imports and namespaces, one for import
 * lines), and they did disagree: faults 11-13 in the module docstring.
 *
 * @param int                  $tokenId   T_STRING or one of the T_NAME_* ids.
 * @param string               $text      The token's own text.
 * @param string|null          $namespace The namespace active at this position; null for none.
 * @param array<string,string> $imports   Class imports in force at this position:
 *                                        alias (lower case) => full name (lower case).
 */
function sc_resolve_class_name(int $tokenId, string $text, ?string $namespace, array $imports): ?string
{
    $prefix = $namespace === null ? '' : strtolower($namespace) . '\\';
    if ($tokenId === T_NAME_FULLY_QUALIFIED) {
        return strtolower(substr($text, 1));
    }
    if ($tokenId === T_NAME_RELATIVE) {
        // The token's own text includes the keyword: "namespace\Rest".
        return $prefix . strtolower(substr($text, strlen('namespace\\')));
    }
    $lower = strtolower($text);
    if ($tokenId === T_NAME_QUALIFIED) {
        [$first, $rest] = explode('\\', $lower, 2);
        return isset($imports[$first]) ? $imports[$first] . '\\' . $rest : $prefix . $lower;
    }
    if ($lower === 'self' || $lower === 'parent') {
        return null;
    }
    return $imports[$lower] ?? $prefix . $lower;
}

/**
 * Read one file-level `use` statement, starting just past the `use` keyword.
 * Returns the CLASS imports it makes, and the index just past its `;`.
 *
 * Handles every shape PHP allows, because missing any of them was a real
 * fault (fault 12 in the module docstring):
 *   use A\B;                use A\B as C;
 *   use A\B, D\E as F;      — every name counts. The previous version read
 *                             only the first and skipped to the `;`.
 *   use A\{B, C as D};      — a grouped import
 *   use A\{B, function c, const D};
 *                           — a `function` or `const` item imports a
 *                             function or a constant, NOT a class, so it is
 *                             skipped. The previous version threw those two
 *                             words away and recorded class imports.
 *   use function a\b;  use const A\B;
 *                           — nothing in the statement is a class import.
 *
 * @return array{0: array<string,string>, 1: int} [alias (lower) => full name (lower), next index]
 */
function sc_parse_use_statement(array $stream, int $i): array
{
    $n = count($stream);
    $importsClasses = true;
    if ($i < $n && ($stream[$i][0] === T_FUNCTION || $stream[$i][0] === T_CONST)) {
        $importsClasses = false;
        $i++;
    }

    // Gather the statement up to its own `;`. A group's `{ ... }` can hold
    // commas but never a `;`, so the first `;` outside brackets ends it.
    $tokens = [];
    $depth = 0;
    for (; $i < $n; $i++) {
        if ($depth === 0 && sc_is_char($stream[$i], ';')) {
            break;
        }
        $depth += sc_open_delta($stream[$i]);
        $tokens[] = $stream[$i];
    }

    $imports = [];
    foreach (sc_split_top_level($tokens) as $clause) {
        if ($clause === [] || !sc_is_name_id($clause[0][0])) {
            continue;
        }
        // TOKEN_PARSE gives a group's prefix as its own name token, then a
        // separate T_NS_SEPARATOR, then the `{`.
        $isGroup = isset($clause[2]) && $clause[1][0] === T_NS_SEPARATOR && sc_is_char($clause[2], '{');
        if (!$isGroup) {
            if ($importsClasses) {
                sc_add_class_import($imports, $clause[0][1], $clause);
            }
            continue;
        }
        [$groupTokens] = sc_extract_balanced($clause, 2);
        foreach (sc_split_top_level($groupTokens) as $item) {
            if ($item === []) {
                continue;
            }
            $itemIsClass = $importsClasses && $item[0][0] !== T_FUNCTION && $item[0][0] !== T_CONST;
            if ($itemIsClass && sc_is_name_id($item[0][0])) {
                sc_add_class_import($imports, $clause[0][1] . '\\' . $item[0][1], $item);
            }
        }
    }
    return [$imports, $i + 1];
}

/**
 * Record one class import. The alias is the word after `as` if there is one,
 * otherwise the last segment of the name. A leading backslash changes nothing
 * in a `use` line: names there are always read from the top of the namespace
 * tree. That is also why a one-word import (`use Auth;`) means the top-level
 * `\Auth` — the previous version read it as "a Portal\Core class" instead.
 */
function sc_add_class_import(array &$imports, string $name, array $clauseTokens): void
{
    $full = strtolower(ltrim($name, '\\'));
    $lastSlash = strrpos($full, '\\');
    $alias = $lastSlash === false ? $full : substr($full, $lastSlash + 1);
    foreach ($clauseTokens as $idx => $tok) {
        if ($tok[0] === T_AS && isset($clauseTokens[$idx + 1])) {
            $alias = strtolower($clauseTokens[$idx + 1][1]);
        }
    }
    $imports[$alias] = $full;
}

// =============================================================================
// 🚶 One walk over one file — declarations, their methods, imports and calls
// =============================================================================

/** Map of PHP entity-header keyword token id => the "kind" string this check
 *  uses throughout. */
function sc_entity_kind_for_token(?int $id): ?string
{
    return match ($id) {
        T_CLASS => 'class',
        T_TRAIT => 'trait',
        T_ENUM => 'enum',
        T_INTERFACE => 'interface',
        default => null,
    };
}

/** Token ids that open a block written with a colon instead of braces —
 *  `if (...):` ... `endif;` — and the ids that close one. A class declared
 *  inside such a block is exactly as conditional as one inside
 *  `if (...) { }`, but there is no brace to show it, so these have to be
 *  counted separately (fault 14). `else:` and `elseif (...):` continue a
 *  block that is already open rather than opening a new one, so they are
 *  deliberately absent. */
const SC_ALT_SYNTAX_OPENERS = [T_IF => true, T_WHILE => true, T_FOR => true, T_FOREACH => true, T_SWITCH => true, T_DECLARE => true];
const SC_ALT_SYNTAX_CLOSERS = [T_ENDIF => true, T_ENDWHILE => true, T_ENDFOR => true, T_ENDFOREACH => true, T_ENDSWITCH => true, T_ENDDECLARE => true];

/**
 * Walk one file's filtered token stream ONCE and return:
 *   - entities: every NAMED class/trait/enum/interface it declares, with its
 *     full name, methods, `extends`, trait `use`s and trait rules, and
 *     whether it is declared conditionally;
 *   - declarations: every named declaration's short name and namespace (for
 *     the "core class used without an import" check's collision set);
 *   - calls: every `Name::method(` call, with the full class name resolved at
 *     the call's own position — or, when `::`, `->` or `?->` comes straight
 *     before the name, marked `classHeldInValue` with no class at all
 *     (fault 17).
 *
 * Before the second review this was two separate walkers, one for the class
 * map and one for calls. Both needed namespace and import tracking; only one
 * of them had it, which is how faults 11 and 13 happened. One walker means
 * one copy of that tracking.
 *
 * Brace bookkeeping is an explicit stack with one frame per `{` currently
 * open. Each frame records its kind — 'namespace' (a bracketed namespace
 * block), 'type' (a class/trait/enum/interface body, named or anonymous) or
 * 'other' (everything else: a method body, an if-block, a closure, a string
 * interpolation) — and the namespace active before it opened, restored when
 * it closes. The kind of the frame on top answers the two questions this
 * walker keeps asking: "is this `function` a method?" and "is this `use` a
 * trait use or an import?" — the two cannot be told apart by token shape,
 * only by where they sit, exactly as PHP decides.
 *
 * @return array{entities: list<array<string,mixed>>, declarations: list<array{name:string,namespace:?string}>,
 *               calls: list<array<string,mixed>>}
 */
function sc_analyse_file(array $stream, string $file): array
{
    $n = count($stream);
    $entities = [];
    $declarations = [];
    $calls = [];

    $namespace = null;
    $imports = [];              // class imports in force right now: alias (lower) => full name (lower)
    $stack = [];
    $framesOutsideNamespace = 0; // open frames that are NOT namespace blocks
    $altSyntaxDepth = 0;         // open `if (...):` style blocks
    $pendingNamespace = null;    // ['braceIdx' => int, 'name' => ?string] for `namespace X {`
    $pendingTypes = [];          // body-brace index => header record awaiting that brace

    $i = 0;
    while ($i < $n) {
        $tok = $stream[$i];
        $id = $tok[0];

        // ---- namespace X; / namespace X { / namespace { ------------------
        // Under TOKEN_PARSE, `namespace\Foo` is a single T_NAME_RELATIVE
        // token, so T_NAMESPACE here is always a real namespace statement.
        if ($id === T_NAMESPACE) {
            $j = $i + 1;
            $name = null;
            if ($j < $n && sc_is_name_id($stream[$j][0])) {
                $name = $stream[$j][1];
                $j++;
            }
            // PHP forgets every import at each namespace statement. Keeping
            // them was fault 11: one namespace's `use` lines decided what a
            // name meant in the next.
            $imports = [];
            if ($j < $n && sc_is_char($stream[$j], '{')) {
                $pendingNamespace = ['braceIdx' => $j, 'name' => $name];
            } else {
                $namespace = $name;
            }
            $i = $j;
            continue;
        }

        // ---- `if (...):` style blocks, which have no braces (fault 14) ---
        // Only PEEKS past the condition; the walk still steps through it
        // token by token, because a condition can contain a real call.
        if ($id !== null && isset(SC_ALT_SYNTAX_OPENERS[$id]) && $i + 1 < $n && sc_is_char($stream[$i + 1], '(')) {
            [, $afterCondition] = sc_extract_balanced($stream, $i + 1);
            if ($afterCondition < $n && sc_is_char($stream[$afterCondition], ':')) {
                $altSyntaxDepth++;
            }
            $i++;
            continue;
        }
        if ($id !== null && isset(SC_ALT_SYNTAX_CLOSERS[$id])) {
            $altSyntaxDepth = max(0, $altSyntaxDepth - 1);
            $i++;
            continue;
        }

        // ---- class / trait / enum / interface header ---------------------
        // Does not jump past the header: an anonymous class's constructor
        // arguments (`new class(Foo::bar()) {}`) can contain real calls.
        $kind = sc_entity_kind_for_token($id);
        if ($kind !== null) {
            $header = sc_parse_entity_header($stream, $i, $kind, $namespace, $imports);
            if ($header['name'] !== null) {
                $declarations[] = ['name' => $header['name'], 'namespace' => $namespace];
            }
            if ($header['bodyIdx'] !== null) {
                $header['file'] = $file;
                // Inside anything but namespace blocks, the declaration only
                // happens if and when that code runs — and an if/else may
                // declare a different version in each branch. The previous
                // version kept whichever declaration it read last (fault 14).
                $header['conditional'] = $framesOutsideNamespace > 0 || $altSyntaxDepth > 0;
                $pendingTypes[$header['bodyIdx']] = $header;
            }
            $i++;
            continue;
        }

        // ---- braces ------------------------------------------------------
        // sc_curly_delta(), not a plain `{` character check: a string's
        // `{$ ... }` interpolation opener is its own token id but closes with
        // an ordinary `}` — see that function's doc comment for the real bug
        // that caused in web/_core/AssetRegister.php.
        $curly = sc_curly_delta($tok);
        if ($curly === 1) {
            $frame = ['kind' => 'other', 'namespaceBefore' => $namespace, 'entity' => null];
            if ($pendingNamespace !== null && $pendingNamespace['braceIdx'] === $i) {
                $frame['kind'] = 'namespace';
                $namespace = $pendingNamespace['name'];
                $pendingNamespace = null;
            } elseif (isset($pendingTypes[$i])) {
                $frame['kind'] = 'type';
                $frame['entity'] = $pendingTypes[$i];
                unset($pendingTypes[$i]);
            }
            if ($frame['kind'] !== 'namespace') {
                $framesOutsideNamespace++;
            }
            $stack[] = $frame;
            $i++;
            continue;
        }
        if ($curly === -1) {
            $frame = array_pop($stack);
            if ($frame !== null) {
                $namespace = $frame['namespaceBefore'];
                if ($frame['kind'] === 'namespace') {
                    $imports = []; // the next namespace block starts with none
                } else {
                    $framesOutsideNamespace--;
                }
                if ($frame['kind'] === 'type' && $frame['entity']['name'] !== null) {
                    $entities[] = sc_add_enum_builtins($frame['entity']);
                }
            }
            $i++;
            continue;
        }

        $top = $stack === [] ? null : $stack[array_key_last($stack)];
        $inTypeBody = $top !== null && $top['kind'] === 'type';

        // ---- a method header, directly inside a type body ----------------
        if ($id === T_FUNCTION) {
            if ($inTypeBody) {
                $method = sc_parse_method_header($stream, $i, $file);
                if ($method !== null) {
                    $stack[array_key_last($stack)]['entity']['methods'][strtolower($method['name'])] = $method;
                }
            }
            $i++;
            continue;
        }

        // ---- use: closure capture, trait use, or file-level import -------
        if ($id === T_USE) {
            $j = $i + 1;
            if ($j < $n && sc_is_char($stream[$j], '(')) {
                $i = $j; // `function () use ($x)` — not an import of anything
                continue;
            }
            if ($inTypeBody) {
                // A trait use. Its `{ ... }` rule block is consumed as ONE
                // balanced span: each rule inside ends with its own `;`, and
                // stopping at the first one once left the block's `{` open
                // and closed a surrounding bracketed namespace one brace early
                // (fixture extra02).
                $traitUse = sc_parse_trait_use($stream, $j, $namespace, $imports);
                $k = array_key_last($stack);
                foreach (['uses', 'aliases', 'insteadof'] as $field) {
                    array_push($stack[$k]['entity'][$field], ...$traitUse[$field]);
                }
                $stack[$k]['entity']['traitRulesUnclear'] = $stack[$k]['entity']['traitRulesUnclear'] || $traitUse['unclear'];
                $i = $traitUse['next'];
                continue;
            }
            [$added, $i] = sc_parse_use_statement($stream, $j);
            $imports = $added + $imports; // a repeated alias: the newer import wins
            continue;
        }

        // ---- a static call: Name :: method ( -----------------------------
        if (sc_is_name_id($id)
            && $i + 3 < $n
            && $stream[$i + 1][0] === T_DOUBLE_COLON
            && $stream[$i + 2][0] === T_STRING
            && sc_is_char($stream[$i + 3], '(')
        ) {
            // Is `Name` really a class name? Not when `::`, `->` or `?->`
            // comes straight before it. In `Holder::Site::ok()` it is a class
            // constant, and in `$obj->Site::ok()` a property. Whatever class
            // name that constant or property holds at runtime is the class
            // PHP calls, and the source text does not say which. The previous
            // version read `Site` as a class name anyway (fault 17 in the
            // module docstring). With no import it falsely reported "core
            // class used with no import". With `use Portal\Core\Site;` it
            // checked the wrong class, so a real method was reported missing,
            // and a missing one was counted as verified. Such a call is now
            // recorded with no class name at all and counted as "left alone".
            $prev = $stream[$i - 1] ?? null;
            if ($prev !== null && in_array($prev[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $calls[] = ['classHeldInValue' => true, 'method' => $stream[$i + 2][1], 'line' => $tok[2]];
                $i += 4;
                continue;
            }
            $fullName = sc_resolve_class_name($id, $tok[1], $namespace, $imports);
            if ($fullName !== null) {
                // Comments and whitespace are already filtered out, so the
                // token straight after `(` tells us everything needed: `)`
                // means literally no arguments; `...` then `)` is a
                // first-class callable, which is NOT a call with no arguments.
                $next = $stream[$i + 4] ?? [null, ''];
                $calls[] = [
                    'clsTokenId' => $id,
                    'clsText' => $tok[1],
                    'fullName' => $fullName,
                    'method' => $stream[$i + 2][1],
                    'line' => $tok[2],
                    'isZeroArg' => sc_is_char($next, ')'),
                    'namespace' => $namespace,
                    // Only a CLASS import counts. Importing a function or
                    // constant of the same name does not (fault 12).
                    'hasClassImport' => $id === T_STRING && isset($imports[strtolower($tok[1])]),
                ];
            }
            // Resume right after the OPENING `(`, not past the whole argument
            // list: a call's arguments can contain another static call —
            // `ApiResponse::success(['asset' => ApiResponse::filterSensitive(
            // $asset, [...])]);` is real code here. Jumping past them once
            // silently lost 14 real nested calls from every count and check.
            $i += 4;
            continue;
        }

        $i++;
    }

    return ['entities' => $entities, 'declarations' => $declarations, 'calls' => $calls];
}

/**
 * Read a `class|trait|enum|interface` header starting at its keyword. Works
 * for named declarations and for anonymous classes (`new class(...) extends X
 * { }`), which have no name and are never mapped, but whose body must still
 * be recognised as a class body so a `use` inside it reads as a trait use.
 *
 * `bodyIdx` is the index of the exact `{` that opens the body; the walker
 * marks only THAT brace as the type body. Saying "the next `{`" instead would
 * be fooled by a closure inside an anonymous class's constructor arguments.
 *
 * `implements` is deliberately never captured: implementing an interface
 * supplies no method body (a class implementing `Stringable` must still write
 * `__toString` itself), so nothing here needs it.
 */
function sc_parse_entity_header(array $stream, int $i, string $kind, ?string $namespace, array $imports): array
{
    $n = count($stream);
    $j = $i + 1;
    $name = null;
    $line = $stream[$i][2];
    if ($j < $n && $stream[$j][0] === T_STRING) {
        $name = $stream[$j][1];
        $line = $stream[$j][2];
        $j++;
    }
    $fullName = $name === null ? null : ($namespace === null ? $name : $namespace . '\\' . $name);
    $isBacked = $kind === 'enum' && $j < $n && sc_is_char($stream[$j], ':');

    $extends = [];
    $inExtends = false;
    $bodyIdx = null;
    for (; $j < $n; $j++) {
        $tok = $stream[$j];
        if (sc_is_char($tok, '(')) {
            [, $after] = sc_extract_balanced($stream, $j);
            $j = $after - 1;
            continue;
        }
        if (sc_is_char($tok, '{')) {
            $bodyIdx = $j;
            break;
        }
        if (sc_is_char($tok, ';')) {
            break;
        }
        if ($tok[0] === T_EXTENDS || $tok[0] === T_IMPLEMENTS) {
            $inExtends = $tok[0] === T_EXTENDS;
            continue;
        }
        if ($inExtends && sc_is_name_id($tok[0])) {
            // Resolved with the imports in force HERE — ignoring them was
            // fault 13 (`use Vendor\Base; class Child extends Base`). An
            // unreadable parent becomes '', which never matches a mapped
            // class, so the class is left alone rather than guessed at.
            $extends[] = sc_resolve_class_name($tok[0], $tok[1], $namespace, $imports) ?? '';
        }
    }

    return [
        'kind' => $kind,
        'name' => $name,
        'fullName' => $fullName,
        'key' => $fullName === null ? null : strtolower($fullName),
        'line' => $line,
        'extends' => $extends,
        'isBacked' => $isBacked,
        'bodyIdx' => $bodyIdx,
        'uses' => [],
        'aliases' => [],
        'insteadof' => [],
        'traitRulesUnclear' => false,
        'methods' => [],
    ];
}

/**
 * Read one method header starting at its `function` keyword, or return null
 * when this `function` is not a named method (an anonymous closure).
 *
 * A method that returns by reference is written `function &name(`. PHP's
 * tokenizer does NOT hand that `&` back as the plain character: since PHP
 * 8.1 it is T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG (or, in principle,
 * its FOLLOWED_BY twin). The previous version only skipped the plain
 * character, never saw the name, and left the method out of the map, so a
 * real call to it was reported as calling a method that does not exist
 * (fault 15). The plain character is still accepted too, in case a future
 * PHP version changes the token.
 */
function sc_parse_method_header(array $stream, int $i, string $file): ?array
{
    $n = count($stream);
    $j = $i + 1;
    if ($j < $n && (
        $stream[$j][0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG
        || $stream[$j][0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
        || sc_is_char($stream[$j], '&')
    )) {
        $j++;
    }
    if ($j + 1 >= $n || $stream[$j][0] !== T_STRING || !sc_is_char($stream[$j + 1], '(')) {
        return null;
    }
    [$paramTokens, $afterParams] = sc_extract_balanced($stream, $j + 1);
    // Step over any return type to the body's `{`, or the `;` that ends a
    // method with no body (an interface method, or an abstract one).
    $end = $afterParams;
    while ($end < $n && !sc_is_char($stream[$end], '{') && !sc_is_char($stream[$end], ';')) {
        $end++;
    }
    $counts = sc_analyse_params($paramTokens);
    return [
        'name' => $stream[$j][1],
        'total' => $counts['total'],
        'required' => $counts['required'],
        'line' => $stream[$j][2],
        'file' => $file,
        // No body means abstract. Recorded because an abstract method must
        // never hide a real one inherited from a parent (fault 16(d)).
        'abstract' => !($end < $n && sc_is_char($stream[$end], '{')),
    ];
}

/**
 * Read a trait use written directly inside a class/trait/enum body, starting
 * just past `use`: the trait names, then an optional `{ ... }` block of
 * `insteadof` and `as` rules.
 *
 * Every trait name, including the one before `::` in a rule, is resolved to
 * a full name here, with the imports in force at this point (fault 13).
 * A rule this cannot read sets `unclear`, which makes the class "left alone"
 * rather than letting a half-read rule produce a wrong answer.
 *
 * @return array{uses: list<string>, aliases: list<array{0:?string,1:string,2:string}>,
 *               insteadof: list<array{0:string,1:string}>, unclear: bool, next: int}
 *     aliases are [trait full name, or null when the rule names none; method
 *     (lower case); new name as written]. insteadof entries are [method
 *     (lower case); full name of the trait whose method wins].
 */
function sc_parse_trait_use(array $stream, int $j, ?string $namespace, array $imports): array
{
    $n = count($stream);
    $out = ['uses' => [], 'aliases' => [], 'insteadof' => [], 'unclear' => false, 'next' => $j];
    while ($j < $n && sc_is_name_id($stream[$j][0])) {
        $out['uses'][] = sc_resolve_class_name($stream[$j][0], $stream[$j][1], $namespace, $imports) ?? '';
        $j++;
        if ($j < $n && sc_is_char($stream[$j], ',')) {
            $j++;
            continue;
        }
        break;
    }
    $out['next'] = $j;
    if (!($j < $n && sc_is_char($stream[$j], '{'))) {
        return $out;
    }
    [$block, $out['next']] = sc_extract_balanced($stream, $j);

    $rules = [[]];
    foreach ($block as $tok) {
        if (sc_is_char($tok, ';')) {
            $rules[] = [];
            continue;
        }
        $rules[array_key_last($rules)][] = $tok;
    }
    foreach ($rules as $rule) {
        if ($rule === []) {
            continue;
        }
        $k = 0;
        $trait = null;
        if (isset($rule[1]) && sc_is_name_id($rule[0][0]) && $rule[1][0] === T_DOUBLE_COLON) {
            $trait = sc_resolve_class_name($rule[0][0], $rule[0][1], $namespace, $imports) ?? '';
            $k = 2;
        }
        $methodTok = $rule[$k] ?? null;
        $operator = $rule[$k + 1][0] ?? null;
        if ($methodTok === null || $methodTok[0] !== T_STRING) {
            $out['unclear'] = true;
            continue;
        }
        $method = strtolower($methodTok[1]);
        if ($operator === T_INSTEADOF && $trait !== null) {
            $out['insteadof'][] = [$method, $trait];
            continue;
        }
        if ($operator === T_AS) {
            // After `as` may come a visibility word, `final`, a new name, or
            // several of those; a new name, when there is one, is always the
            // last word. `ok as protected;` changes visibility only and adds
            // no name, so it needs no entry.
            $last = $rule[count($rule) - 1];
            if ($last[0] === T_STRING && count($rule) > $k + 2) {
                $out['aliases'][] = [$trait, $method, $last[1]];
            }
            continue;
        }
        $out['unclear'] = true;
    }
    return $out;
}

/**
 * Add the methods every enum has without the source spelling them out:
 * `cases()` (no arguments) for every enum, and `from()`/`tryFrom()` (one
 * required argument each) for a backed enum (`enum X: string`). `+=` keeps a
 * same-named method the enum declares itself, as PHP does.
 */
function sc_add_enum_builtins(array $entity): array
{
    if ($entity['kind'] !== 'enum') {
        return $entity;
    }
    $builtin = static fn (string $name, int $required): array => [
        'name' => $name, 'total' => $required, 'required' => $required,
        'line' => $entity['line'], 'file' => $entity['file'], 'abstract' => false,
    ];
    $entity['methods'] += ['cases' => $builtin('cases', 0)];
    if ($entity['isBacked']) {
        $entity['methods'] += ['from' => $builtin('from', 1), 'tryfrom' => $builtin('tryFrom', 1)];
    }
    return $entity;
}

// =============================================================================
// 🗺️  The core entity map, and each class's real method list
// =============================================================================

/**
 * Read every top-level `.php` file directly inside `$coreDir` and map every
 * named class/trait/enum/interface under its full name (lower case).
 *
 * Keyed by FULL name, not short name. Keying by short name was fault 13: a
 * `Vendor\Site` in the same folder silently replaced `Portal\Core\Site`.
 *
 * A full name declared more than once is marked `uncertain`, as is any
 * declaration made conditionally (inside an if/else, a function, and so on).
 * The check cannot know which version PHP will actually have loaded, so calls
 * to it are left alone (fault 14).
 *
 * @return array{entities: array<string, array<string, mixed>>, parse_errors: list<string>}
 */
function sc_build_core_map(string $coreDir): array
{
    $entities = [];
    $parseErrors = [];

    $files = glob(rtrim($coreDir, '/') . '/*.php') ?: [];
    sort($files);

    foreach ($files as $file) {
        $code = @file_get_contents($file);
        if ($code === false) {
            continue;
        }
        $stream = sc_tokenize($code);
        if ($stream === null) {
            $parseErrors[] = $file;
            continue;
        }
        foreach (sc_analyse_file($stream, $file)['entities'] as $entity) {
            $key = $entity['key'];
            if (isset($entities[$key])) {
                $entities[$key]['uncertain'] = true;
                continue;
            }
            $entity['uncertain'] = $entity['conditional'];
            $entities[$key] = $entity;
        }
    }

    return ['entities' => $entities, 'parse_errors' => $parseErrors];
}

/**
 * The real method list of one mapped class, trait or enum — its own methods
 * plus everything its traits supply — worked out the way PHP does it, or
 * null when this file cannot be sure.
 *
 * PHP's order, which is the order below:
 *   1. Each used trait's own real method list (worked out by this same
 *      function, so a trait that uses other traits has ITS `insteadof` and
 *      `as` rules applied first).
 *   2. `insteadof`: for that method name, only the named trait's version is
 *      taken.
 *   3. `as`: the new name gets a copy of the method from the trait the rule
 *      NAMES — or, when it names none, from the one trait that has it.
 *   4. The class's OWN methods always win over anything from a trait.
 *   (Inherited methods come after all of that; see sc_lookup_method().)
 *
 * This works only from the raw data of each declaration and never changes
 * the map, so the answer cannot depend on which declaration is processed
 * first. The previous version changed each class in place as it went and read
 * those half-changed classes back, which is why a trait's own `insteadof`
 * counted only if that trait happened to come first (fault 16(c)). It also
 * let an `insteadof` choice overwrite the class's own method (16(a)) and
 * copied aliases from whichever method had already won (16(b)).
 *
 * Returns null — "left alone" — for anything outside that model: a used trait
 * that is not a mapped trait, an uncertain or unreadable declaration, a
 * cycle of traits, two real methods with the same name from different traits
 * and no `insteadof`, an alias that could mean more than one trait's method,
 * or an alias whose new name clashes with another trait method. PHP has
 * further rules for several of those (some are fatal errors, some are
 * allowed when both copies are the very same method); modelling them all
 * would be large, and "cannot be sure" is the safe answer.
 *
 * @param list<string> $resolving Full names already being worked out further up (cycle guard).
 */
function sc_effective_methods(string $key, array $entities, array $resolving = []): ?array
{
    $entity = $entities[$key] ?? null;
    if ($entity === null || $entity['uncertain'] || $entity['traitRulesUnclear'] || in_array($key, $resolving, true)) {
        return null;
    }
    $resolving[] = $key;

    $traitTables = [];
    foreach ($entity['uses'] as $traitKey) {
        if (($entities[$traitKey]['kind'] ?? null) !== 'trait') {
            return null;
        }
        $table = sc_effective_methods($traitKey, $entities, $resolving);
        if ($table === null) {
            return null;
        }
        $traitTables[$traitKey] = $table;
    }

    $winners = [];
    foreach ($entity['insteadof'] as [$method, $winningTrait]) {
        if (!isset($traitTables[$winningTrait][$method])) {
            return null;
        }
        $winners[$method] = $winningTrait;
    }

    $fromTraits = [];
    foreach ($traitTables as $traitKey => $table) {
        foreach ($table as $method => $info) {
            if (isset($winners[$method]) && $winners[$method] !== $traitKey) {
                continue; // excluded by `insteadof`
            }
            $existing = $fromTraits[$method] ?? null;
            if ($existing === null || ($existing['abstract'] && !$info['abstract'])) {
                $fromTraits[$method] = $info; // a real method satisfies an abstract one
                continue;
            }
            if ($info['abstract']) {
                continue;
            }
            return null; // two real methods, same name, no `insteadof`
        }
    }

    foreach ($entity['aliases'] as [$aliasTrait, $method, $newName]) {
        if ($aliasTrait !== null) {
            $source = $traitTables[$aliasTrait][$method] ?? null;
        } else {
            $holders = array_keys(array_filter($traitTables, static fn (array $t): bool => isset($t[$method])));
            $source = count($holders) === 1 ? $traitTables[$holders[0]][$method] : null;
        }
        $newKey = strtolower($newName);
        if ($source === null || isset($fromTraits[$newKey])) {
            return null;
        }
        $fromTraits[$newKey] = ['name' => $newName] + $source;
    }

    return $entity['methods'] + $fromTraits;
}

/**
 * For every mapped entity, walk its `extends` chain (self first, then each
 * parent — an interface may name several) and record `chain` (each link's
 * name and real method list, in search order) and `skip` (true when any link
 * cannot be pinned down, or anything in the chain defines `__callStatic`).
 */
function sc_compute_chains(array $entities): array
{
    $effective = [];
    foreach (array_keys($entities) as $key) {
        $effective[$key] = sc_effective_methods($key, $entities);
    }

    foreach ($entities as $key => $entity) {
        $chain = [];
        $seen = [];
        $sure = true;
        $dynamic = false;
        $queue = [$key];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue; // two parents sharing a grandparent — already counted
            }
            $seen[$current] = true;
            $methods = $effective[$current] ?? null;
            if ($methods === null) {
                $sure = false; // an unmapped parent, or a link this file cannot be sure about
                continue;
            }
            $chain[] = ['name' => $entities[$current]['name'], 'methods' => $methods];
            if (isset($methods['__callstatic'])) {
                $dynamic = true;
            }
            foreach ($entities[$current]['extends'] as $parent) {
                $queue[] = $parent;
            }
        }
        $entities[$key]['chain'] = $chain;
        $entities[$key]['skip'] = !$sure || $dynamic;
    }
    return $entities;
}

/**
 * Find the definition of a method that actually governs a call: search self,
 * then each ancestor in turn, returning the first REAL (non-abstract) one.
 *
 * An abstract method is only a promise that a real one exists. Returning the
 * first match regardless was fault 16(d): a trait's abstract `ok($x)` hid the
 * real `ok($x = null)` inherited from the parent, and a valid `ok()` call was
 * accused. If nothing real exists anywhere in the chain, the first abstract
 * one is returned, so a call to it still counts as naming a method that
 * exists (calling it would fail for a different reason, which this check does
 * not judge).
 */
function sc_lookup_method(array $entity, string $methodLower): ?array
{
    $abstractOnly = null;
    foreach ($entity['chain'] as $link) {
        $info = $link['methods'][$methodLower] ?? null;
        if ($info === null) {
            continue;
        }
        if (!$info['abstract']) {
            return $info;
        }
        $abstractOnly ??= $info;
    }
    return $abstractOnly;
}

// =============================================================================
// 🔎 Call-site scanning — every file under the scan roots, then every call
// judged against the map
// =============================================================================

/** Recursively list every `.php` file under `$root`, sorted, deduplicated by
 *  real path (so a root nested inside another is never scanned twice). */
function sc_list_php_files(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }
    $out = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

/**
 * Run the whole call-site scan: read, tokenize and walk every file across
 * `$scanRoots` exactly once, build the top-level collision set for the
 * "core class used without an import" check, then judge every call.
 *
 * @return array{
 *     missing: list<array>, zeroArg: list<array>, unimported: list<array>,
 *     stats: array<string,int>, parseErrors: list<string>
 * }
 */
function sc_scan_calls(array $entities, array $scanRoots): array
{
    $files = [];
    $seenRealpaths = [];
    foreach ($scanRoots as $root) {
        foreach (sc_list_php_files($root) as $path) {
            $real = realpath($path) ?: $path;
            if (isset($seenRealpaths[$real])) {
                continue;
            }
            $seenRealpaths[$real] = true;
            $files[] = $path;
        }
    }
    sort($files);

    $parseErrors = [];
    $perFile = []; // path => ['declarations' => ..., 'calls' => ...]
    foreach ($files as $path) {
        $code = @file_get_contents($path);
        if ($code === false) {
            continue;
        }
        $stream = sc_tokenize($code);
        if ($stream === null) {
            $parseErrors[] = $path;
            continue;
        }
        $analysis = sc_analyse_file($stream, $path);
        $perFile[$path] = ['declarations' => $analysis['declarations'], 'calls' => $analysis['calls']];
    }

    // ---- top-level collision set for the "no import" check --------------
    $globalNames = [];
    foreach ($perFile as $analysis) {
        foreach ($analysis['declarations'] as $decl) {
            if ($decl['namespace'] === null) {
                $globalNames[strtolower($decl['name'])] = true;
            }
        }
    }

    $findingsMissing = [];
    $findingsZero = [];
    $findingsUnimported = [];
    $totalCandidates = 0;
    $resolved = 0;
    $skipped = 0;
    $classHeldInValue = 0;

    foreach ($perFile as $path => $analysis) {
        foreach ($analysis['calls'] as $call) {
            $totalCandidates++;

            // `Holder::Site::ok()` or `$obj->Site::ok()`: the class is
            // whatever a constant or property holds, so it is neither accused
            // (not even of a missing import) nor verified (fault 17).
            if (isset($call['classHeldInValue'])) {
                $classHeldInValue++;
                continue;
            }

            $target = $entities[$call['fullName']] ?? null;
            if ($target === null) {
                // Not a mapped class, so not in scope for the method checks —
                // but it might be the OTHER fault: a one-word name at a
                // position with no namespace and no class import, for which
                // PHP looks only at the top level, while the real class is in
                // Portal\Core. A file that imports SOME class under this name
                // (`use Vendor\Site;`) has no such fault: PHP finds that
                // class instead (fault 2 in the module docstring).
                $lowerCls = strtolower($call['clsText']);
                if ($call['clsTokenId'] === T_STRING
                    && !$call['hasClassImport']
                    && $call['namespace'] === null
                    && !isset($globalNames[$lowerCls])
                ) {
                    $coreMatch = $entities['portal\\core\\' . $lowerCls] ?? null;
                    if ($coreMatch !== null) {
                        $findingsUnimported[] = [$path, $call['line'], $call['clsText'], $call['method'], $coreMatch];
                    }
                }
                continue;
            }
            $resolved++;

            if ($target['skip']) {
                $skipped++; // could not be sure — neither accused nor verified
                continue;
            }

            $methodInfo = sc_lookup_method($target, strtolower($call['method']));
            if ($methodInfo === null) {
                $findingsMissing[] = [$path, $call['line'], $call['clsText'], $call['method'], $target];
                continue;
            }
            if ($methodInfo['required'] >= 1 && $call['isZeroArg']) {
                $findingsZero[] = [$path, $call['line'], $call['clsText'], $call['method'], $target, $methodInfo];
            }
        }
    }

    return [
        'missing' => $findingsMissing,
        'zeroArg' => $findingsZero,
        'unimported' => $findingsUnimported,
        'stats' => [
            'files_scanned' => count($perFile),
            'total_candidates' => $totalCandidates,
            'resolved' => $resolved,
            'skipped' => $skipped,
            'class_held_in_value' => $classHeldInValue,
        ],
        'parseErrors' => $parseErrors,
    ];
}

// =============================================================================
// 🖨️  Report — same shape as the old script: findings as "  • path:line —
// message", section headings as "### ...", a coverage summary, and a closing
// line that states plainly what was and was not verified. The pull-request
// workflow's own filter (grep for a line containing "•" or starting "###")
// depends on this shape staying exactly this shape — see .github/workflows/
// pr-security.yml.
// =============================================================================

function sc_rel(string $path, string $repoRoot): string
{
    $real = realpath($path) ?: $path;
    $root = rtrim($repoRoot, '/') . '/';
    return str_starts_with($real, $root) ? substr($real, strlen($root)) : $real;
}

function sc_run(string $coreDir, array $scanRoots, string $repoRoot): int
{
    $map = sc_build_core_map($coreDir);
    $entities = sc_compute_chains($map['entities']);

    $byKind = ['class' => 0, 'trait' => 0, 'enum' => 0, 'interface' => 0];
    $totalMethods = 0;
    $skippedNames = [];
    foreach ($entities as $entity) {
        $byKind[$entity['kind']] = ($byKind[$entity['kind']] ?? 0) + 1;
        $totalMethods += count($entity['methods']);
        if ($entity['skip']) {
            // The full name, because two left-alone classes may share a
            // short name (fault 13).
            $skippedNames[] = $entity['fullName'];
        }
    }
    sort($skippedNames);

    $result = sc_scan_calls($entities, $scanRoots);

    usort($result['missing'], static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    usort($result['zeroArg'], static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    usort($result['unimported'], static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

    if ($map['parse_errors'] !== [] || $result['parseErrors'] !== []) {
        echo "### PHP source this check could not tokenize\n\n";
        foreach (array_merge($map['parse_errors'], $result['parseErrors']) as $bad) {
            echo '  • ' . sc_rel($bad, $repoRoot) . " — token_get_all() could not parse this file (ParseError); "
                . "every other file was still checked, but this one's classes/calls are missing from every "
                . "figure below\n";
        }
        echo "\n";
    }

    echo 'Classes mapped (web/_core/*.php): ' . $byKind['class'] . "\n";
    echo 'Traits mapped (web/_core/*.php): ' . $byKind['trait'] . "\n";
    echo 'Enums mapped (web/_core/*.php): ' . $byKind['enum'] . "\n";
    echo 'Interfaces mapped (web/_core/*.php): ' . $byKind['interface'] . "\n";
    echo 'Methods mapped across those (declared in their own bodies, plus enums\' built-in '
        . "cases()/from()/tryFrom()): " . $totalMethods . "\n";
    if ($skippedNames !== []) {
        echo 'Entities left alone (defines __callStatic itself, inherits/uses one, is declared inside an if/else, '
            . 'a function or more than once, or it/an ancestor\'s parent or trait wasn\'t fully resolvable): '
            . count($skippedNames) . ' — ' . implode(', ', $skippedNames) . "\n";
    }
    echo 'PHP files scanned for static calls (web/_core, web/_apps, web/public_html, web/_install): '
        . $result['stats']['files_scanned'] . "\n";
    echo 'Static calls found (ClassName::method(), excluding self::/static::/parent::): '
        . $result['stats']['total_candidates'] . "\n";
    echo '  resolved to one of the mapped entities: ' . $result['stats']['resolved'] . "\n";
    echo '  of those, left alone because this check could not be sure what they call — neither accused '
        . 'nor verified (see above): ' . $result['stats']['skipped'] . "\n";
    // Printed even when it is 0, so a clean run shows this shape was looked
    // for rather than leaving it to be assumed.
    echo '  left alone because the class is held in a constant or property (Holder::Site::ok(), '
        . '$obj->Site::ok()), so the source text does not say which class it is — neither accused nor '
        . 'verified: ' . $result['stats']['class_held_in_value'] . "\n\n";

    echo 'Calls to a method that does not exist: ' . count($result['missing']) . "\n";
    echo 'Calls made with zero arguments to a method needing at least one: ' . count($result['zeroArg']) . "\n";
    echo 'Calls to a core class used with no import and no namespace of its own: ' . count($result['unimported']) . "\n\n";

    if ($result['missing'] !== []) {
        echo "### Calls to a method that does not exist\n\n";
        foreach ($result['missing'] as [$path, $line, $clsName, $method, $target]) {
            $rel = sc_rel($path, $repoRoot);
            $checked = implode(' -> ', array_map(static fn ($c) => $c['name'], $target['chain']));
            echo "  • {$rel}:{$line} — {$clsName}::{$method}() — no such method (checked: {$checked})\n";
        }
        echo "\n";
    }

    if ($result['zeroArg'] !== []) {
        echo "### Calls made with zero arguments to a method that requires at least one\n\n";
        foreach ($result['zeroArg'] as [$path, $line, $clsName, $method, $target, $methodInfo]) {
            $rel = sc_rel($path, $repoRoot);
            // The method's OWN file: a trait method is defined in the trait's
            // file, which the previous version got wrong by printing the
            // class's file with the trait method's line number.
            $defRel = sc_rel($methodInfo['file'], $repoRoot);
            echo "  • {$rel}:{$line} — {$clsName}::{$method}() called with no arguments, but {$method}() needs "
                . "at least {$methodInfo['required']} (defined at {$defRel}:{$methodInfo['line']})\n";
        }
        echo "\n";
    }

    if ($result['unimported'] !== []) {
        echo "### Calls to a core class with no import, no full path, and no namespace of its own\n\n";
        foreach ($result['unimported'] as [$path, $line, $clsName, $method, $target]) {
            $rel = sc_rel($path, $repoRoot);
            $defRel = sc_rel($target['file'], $repoRoot);
            echo "  • {$rel}:{$line} — {$clsName}::{$method}() — {$target['name']} is a real "
                . "{$target['kind']} ({$defRel}), but this file never imports it (`use Portal\\Core\\"
                . "{$target['name']};`), never writes the full path (`\\Portal\\Core\\{$target['name']}::…`), "
                . "and has no namespace of its own at this point in the file — PHP looks for a top-level "
                . "`{$clsName}`, finds nothing, and stops with a fatal error the instant this line runs\n";
        }
        echo "\n";
    }

    $total = count($result['missing']) + count($result['zeroArg']) + count($result['unimported']);
    $tokenizeFailed = ($map['parse_errors'] !== [] || $result['parseErrors'] !== []);

    if ($total === 0 && !$tokenizeFailed) {
        echo 'check_static_calls: OK — every static call this check could verify resolves to a real method on '
            . 'an entity it could actually reach (imported, a fully-qualified Portal\\Core reference, or '
            . 'called from a position inside Portal\\Core itself), and no core class was reached for without '
            . "an import. NOT verified, even for a call that passed: the argument COUNT of a non-empty call "
            . '(only the zero-argument shape above is checked), argument TYPES, named arguments, visibility, '
            . 'or whether a call is static-vs-instance-correct — see this file\'s own header comment for '
            . "exactly what this script can and cannot see.\n";
        return 0;
    }

    if ($tokenizeFailed) {
        echo "One or more PHP files could not be tokenized at all — see the finding(s) above. That is treated "
            . "as a failure in its own right, not silently skipped, because a file this check cannot even "
            . "read is a file it cannot prove anything about.\n";
    }
    if ($total > 0) {
        echo "A method that doesn't exist, a required argument that was never passed, or a real core class "
            . "reached for with no way for PHP to find it, is not a maybe — PHP throws on that exact line "
            . "every single time it runs. Fix the call (or the method/import, if the call is right and "
            . "something else is the one that's wrong).\n";
    }
    return 1;
}

// =============================================================================
// 🚀 Entry point — arguments make the core directory and scan roots
// overridable, so tools/static-calls-selftest.php can point this exact same
// checking logic at tools/audit-checks/fixtures/static_calls/ instead of the
// real codebase.
// =============================================================================

function sc_main(array $argv): int
{
    $repoRoot = realpath(__DIR__ . '/../..');
    if ($repoRoot === false) {
        fwrite(STDERR, "check_static_calls.php: could not resolve the repository root\n");
        return 1;
    }

    $coreDir = $repoRoot . '/web/_core';
    $scanRoots = [
        $repoRoot . '/web/_core',
        $repoRoot . '/web/_apps',
        $repoRoot . '/web/public_html',
        $repoRoot . '/web/_install',
    ];
    $rootsGiven = false;

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--core=')) {
            $coreDir = substr($arg, strlen('--core='));
            continue;
        }
        if (str_starts_with($arg, '--root=')) {
            if (!$rootsGiven) {
                $scanRoots = [];
                $rootsGiven = true;
            }
            $scanRoots[] = substr($arg, strlen('--root='));
            continue;
        }
    }

    return sc_run($coreDir, $scanRoots, $repoRoot);
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    exit(sc_main($argv));
}
