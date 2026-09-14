<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/extra01_class_trait_use.php
// Extra regression fixture — not one of the 17 documented cases; added after
// an independent verifier found a real gap the 17 never exercised (build
// item 1's own words: "telling a trait use inside a class body apart from
// an import at the top of a file" — the core-map builder already did this,
// this scanner did not).
//
// A trait declared AND used entirely within THIS one app-level file, whose
// short name happens to collide with the real core Site class (see
// fixtures/core/Entities.php). `use Site;`, written directly inside a class
// body, is a TRAIT-USE — nothing to do with importing anything from
// web/_core — but a version of this scanner that treats every `use` token
// as a file-level import, regardless of where it sits, would record it as
// an import. The first tokenizer version also read a one-word import as
// "look this up among the mapped Portal\Core classes", so the call below,
// meant for the LOCAL trait, was checked against the core Site (which has
// no hello() method) and reported as broken.
//
// Second review: that one-word rule was itself wrong — a one-word import
// means the TOP-LEVEL class (see review2/r2_02_import_lists/apps/
// 30_single_segment_import.php). Under the corrected rule, mistaking this
// trait use for an import would make `Site` mean \Site, which is not
// mapped, so this fixture on its own would no longer catch that mistake.
// It is kept because the call must still never be accused.
// Must NOT be accused of anything.
declare(strict_types=1);

namespace App\Thing;

trait Site
{
    public static function hello(): void
    {
    }
}

class UsesLocalTraitNamedSite
{
    use Site;
}

Site::hello();
