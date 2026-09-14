<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/extra02_ns_trait_adapt.php
// Extra regression fixture — not one of the 17 documented cases; added after
// an independent verifier found a real gap the 17 never exercised: the
// COMBINATION of case 3 (bracketed `namespace Portal\Core { ... }`) and
// case 4 (trait conflict resolution via `insteadof`), neither of which
// proves anything about the other on its own.
//
// A version of this scanner that steps past a trait-use's adaptation block
// by scanning ahead only as far as the block's own first `;` — the one
// after `insteadof` — stops INSIDE that block's braces, because an
// `insteadof`/`as` clause always ends with its OWN `;`. That leaves the
// block's opening `{` never counted, so the bracketed namespace's real
// closing `}` a few lines later gets matched against the WRONG opening
// brace, and the namespace's own scope is treated as closed one brace too
// early. `Site::ok(1)` below — reached while STILL genuinely inside the
// bracket — would then be wrongly reported as "core class used with no
// import": there is nothing broken about it; the surrounding scope was
// simply lost first. Must NOT be accused of anything.
declare(strict_types=1);

namespace Portal\Core {
    trait Extra02TraitA
    {
        public static function f(): void
        {
        }
    }

    trait Extra02TraitB
    {
        public static function f(): void
        {
        }
    }

    class Extra02Uses
    {
        use Extra02TraitA, Extra02TraitB {
            Extra02TraitA::f insteadof Extra02TraitB;
        }
    }

    // Still genuinely inside `namespace Portal\Core { ... }` here — a bare
    // Site::ok() needs no import at this position. If the adaptation block
    // above ate the bracket's real closing brace early (the fault this
    // fixture exists to catch), $activeNamespace would already be back to
    // null by the time this line is scanned, and this call would be wrongly
    // reported as unimported.
    Site::ok(1);
}
