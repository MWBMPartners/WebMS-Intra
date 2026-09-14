<?php
// Path: tools/audit-checks/fixtures/static_calls/apps/carried07_call_after_heredoc.php
// Carried-over case — a call written on the SAME line as a heredoc's
// closing marker, followed immediately by TWO punctuation characters
// ("TXT);"). The old hand-written lexer's closing-marker pattern only ever
// tolerated one trailing punctuation character, so it treated the heredoc
// as unterminated and blanked the rest of the FILE to nothing — silently
// losing every real call after it, for the whole rest of the file, in
// total silence. The call below, immediately after, MUST still be found and
// reported (Site has no name() method) — proving the scan genuinely resumed
// as ordinary code right after the heredoc, not merely that this one
// specific line still parses.
declare(strict_types=1);

use Portal\Core\Site;

function carried07_some_call(string $s): void
{
}

carried07_some_call(<<<TXT
plain text
TXT);

Site::name();
