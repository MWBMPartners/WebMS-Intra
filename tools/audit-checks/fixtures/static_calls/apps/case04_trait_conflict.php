<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/case04_trait_conflict.php
// Fixture case 4 — trait conflict resolution. Portal\Core\Conflicted
// resolves the clash between TraitA::ok() (one required argument) and
// TraitB::ok() (none) in TraitB's favour, via `insteadof`. Calling
// Conflicted::ok() with ZERO arguments must NOT be flagged — TraitB's
// zero-argument version is the one that actually runs. The old lexer did
// not interpret `insteadof` at all, so it judged this call against
// whichever trait's method its own declaration-order merge happened to
// keep — which, for the real house precedent this mirrors, was consistently
// the wrong one.
declare(strict_types=1);

use Portal\Core\Conflicted;

Conflicted::ok();
