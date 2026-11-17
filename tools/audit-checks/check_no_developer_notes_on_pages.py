#!/usr/bin/env python3
"""
No page may tell a reader to "see DEV_NOTES" (#1138 item 11).

WHY. DEV_NOTES.md is a developer's file and is NOT in the installation package,
so a page or an e-mail that says "see DEV_NOTES.md" sends an administrator
looking for a file that does not exist on their server. Four pages did exactly
that until the rehearsal found them.

WHAT IT CHECKS (presence only). Under web/_apps and web/_core/templates, the
text "DEV_NOTES" must not appear in what a reader can be shown:
  - inline HTML (outside <?php ... ?>), HTML comments included, because an
    HTML comment is sent to every visitor's browser;
  - a string literal, a double-quoted string, or a heredoc.
It IS allowed in PHP comments (// # /* */ and docblocks), which are never sent.

HOW. PHP's own tokeniser (token_get_all) reads each file, never a text search:
a text search cannot tell a comment from a string, and every file here quotes
DEV_NOTES in its comments. The reader is a small PHP script run by `php`; this
check REFUSES (exit 2) when `php` is missing or a file does not parse, because
a check that quietly sees nothing is worse than none (the #646 shape).

WHAT IT CANNOT SEE. A name built by joining pieces ('DEV_' . 'NOTES'), and a
string handed in from a database. It does not judge whether any other sentence
is true.

Usage:
  python3 tools/audit-checks/check_no_developer_notes_on_pages.py [--self-test]
Exit: 0 clean, 1 a page names DEV_NOTES, 2 could not run.
"""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
FOLDERS = [REPO_ROOT / "web" / "_apps", REPO_ROOT / "web" / "_core" / "templates"]

# Only tokens whose text a reader can be shown. Comments (T_COMMENT,
# T_DOC_COMMENT) are deliberately absent.
PHP_READER = r"""
$bad = [];
foreach (array_slice($argv, 1) as $file) {
    $src = file_get_contents($file);
    if ($src === false) { fwrite(STDERR, "cannot read $file\n"); exit(2); }
    try {
        $tokens = token_get_all($src, TOKEN_PARSE);
    } catch (\Throwable $e) {
        fwrite(STDERR, "does not parse: $file: " . $e->getMessage() . "\n");
        exit(2);
    }
    $shown = [T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
    foreach ($tokens as $t) {
        if (is_array($t) === true && in_array($t[0], $shown, true) === true && strpos($t[1], 'DEV_NOTES') !== false) {
            $bad[] = [$file, $t[2], token_name($t[0])];
        }
    }
}
echo json_encode($bad);
"""


def read(files: list[Path]) -> list[list]:
    if not files:
        return []
    try:
        done = subprocess.run(
            ["php", "-r", PHP_READER, "--", *[str(f) for f in files]],
            capture_output=True, text=True, check=False,
        )
    except FileNotFoundError:
        print("REFUSED: php is not installed, so nothing was checked.")
        sys.exit(2)
    if done.returncode != 0:
        print("REFUSED: the PHP reader failed:\n" + done.stderr.strip())
        sys.exit(2)
    return json.loads(done.stdout or "[]")


def page_files() -> list[Path]:
    out: list[Path] = []
    for folder in FOLDERS:
        out.extend(sorted(folder.rglob("*.php")))
    return out


def self_test() -> int:
    cases = {
        "html text":    ("<p>See DEV_NOTES.md for details.</p>\n", True),
        "html comment": ("<!-- see DEV_NOTES.md -->\n", True),
        "a string":     ("<?php\n$m = 'see DEV_NOTES.md';\n", True),
        "heredoc":      ("<?php\n$m = <<<T\nsee DEV_NOTES\nT;\n", True),
        "php comment":  ("<?php\n// see DEV_NOTES.md\n/* DEV_NOTES */\n# DEV_NOTES\n", False),
        "docblock":     ("<?php\n/** DEV_NOTES */\necho 1;\n", False),
    }
    failed = 0
    with tempfile.TemporaryDirectory() as tmp:
        for name, (code, should_flag) in cases.items():
            f = Path(tmp) / (name.replace(" ", "_") + ".php")
            f.write_text(code)
            flagged = len(read([f])) > 0
            ok = flagged == should_flag
            print(("ok   " if ok else "FAIL ") + name + (" (flagged)" if flagged else " (clean)"))
            failed += 0 if ok else 1
    print("self-test: " + ("all passed" if failed == 0 else f"{failed} failed"))
    return 1 if failed else 0


def main() -> int:
    if "--self-test" in sys.argv:
        return self_test()
    hits = read(page_files())
    if hits:
        print("A page names DEV_NOTES where a reader can see it (DEV_NOTES.md is not in the package):")
        for file, line, kind in hits:
            print(f"  {Path(file).relative_to(REPO_ROOT)}:{line} ({kind})")
        return 1
    print("OK: no page or template shows DEV_NOTES to a reader.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
