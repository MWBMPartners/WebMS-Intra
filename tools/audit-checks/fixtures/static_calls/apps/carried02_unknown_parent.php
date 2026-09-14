<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/carried02_unknown_parent.php
// Carried-over case — a class whose `extends` target this check could never
// resolve at all: a plain, unqualified name (NOT a leading-backslash case —
// see case10 for that one) that matches nothing anywhere. Portal\Core\
// OrphanChild might be inheriting the very method being called from that
// unseen parent, so it must be left alone entirely, never accused. Must NOT
// be accused of anything.
declare(strict_types=1);

use Portal\Core\OrphanChild;

OrphanChild::somethingItMightInheritFromNoSuchParent();
