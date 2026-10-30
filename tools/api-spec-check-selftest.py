#!/usr/bin/env python3
"""
The test of the tests: do the two api-spec checks actually catch things?

Path: tools/api-spec-check-selftest.py

WHY THIS EXISTS
---------------
Two checks stand between the API description and the code drifting apart
again: `check_api_spec_matches_code.py` (the addresses agree) and
`check_api_spec_schema.py` (the file satisfies its own schema, and every
property and parameter explains itself). A check that cannot fail is worse than
no check, because it is believed -- and this project has been bitten by that
shape repeatedly, most recently when a guard searched for the TEXT of a
condition and stayed green after `&& false` was added to it.

The independent check of 30 October 2026 made the point concretely: adding
`and False` to check A, or to any of the three named lists, leaves the whole
thing green and nothing notices. The planting that proved the check worked
lived in a one-off evidence folder, so nothing re-ran it. This file is that
planting, committed and wired into a workflow, so the proof runs on every pull
request instead of once.

**It is NOT a lint.** It plants a real fault into the real files, runs the real
check, and requires the check to fail AND to name the expected letter -- going
red for some other reason is not proof. Then it restores every file and
asserts the restoration by SHA-256.

HOW A PLANT IS WRITTEN SAFELY, learned the hard way
---------------------------------------------------
1. **Assert the text being replaced was FOUND.** A plant whose pattern does not
   match applies nothing, and a check that then passes reads exactly like a
   check that caught nothing. Every text plant here asserts an exact count.
2. **Back up anything the plant creates OR DELETES, not just the files you
   expect to edit.** The first version of this harness watched three files and
   had a plant that deleted a fourth (a PHP file, to make a named list go
   stale). It left that file deleted. Caught only by the closing "is it green
   again?" step. Every file this harness touches is now restored from a
   recorded snapshot of the whole set, and files it creates are removed.
3. **One changed thing per plant**, or two guards can mask each other and
   neither half can be shown to be load-bearing.
4. **Check the plant produces the state you meant**, not merely a failure.

USAGE
    python3 -I tools/api-spec-check-selftest.py
    python3 -I tools/api-spec-check-selftest.py --verbose

Package:   WebMS-Intra
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: All Rights Reserved
Version:   1.0.0
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
import re
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
MATCHES = "tools/audit-checks/check_api_spec_matches_code.py"
SCHEMA_CHECK = "tools/audit-checks/check_api_spec_schema.py"
SCHEMA_FILE = REPO / "web/_core/api-spec.schema.json"
SPEC = REPO / "web/_core/api-spec.json"
ROUTER = REPO / "web/_core/ApiRouter.php"
SQL = REPO / "web/_sql/full_schema.sql"
TRANSLATE = REPO / "web/_apps/api/translate.php"

# Every file a plant may edit or delete. Backed up and restored as one set, so a
# plant cannot leave one of them changed -- see point 2 in the header.
WATCHED = [SPEC, ROUTER, SQL, TRANSLATE, SCHEMA_FILE]

VERBOSE = "--verbose" in sys.argv


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.exists() else "(absent)"


def run_check(script: str = MATCHES) -> tuple[int, str]:
    done = subprocess.run(["python3", "-I", script], capture_output=True, text=True, cwd=REPO)
    return done.returncode, done.stdout + done.stderr


def replace_once(path: Path, old: str, new: str, count: int = 1) -> None:
    """Edit a file, refusing to be a no-op. See point 1 in the header."""
    text = path.read_text(encoding="utf-8")
    found = text.count(old)
    if found != count:
        raise AssertionError(
            "plant text appears %d time(s), expected %d, in %s: %r"
            % (found, count, path.name, old[:70]))
    path.write_text(text.replace(old, new, count), encoding="utf-8")


def edit_spec(change) -> None:
    spec = json.loads(SPEC.read_text(encoding="utf-8"))
    change(spec)
    SPEC.write_text(json.dumps(spec, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def add_path(path_key: str):
    return lambda spec: spec["paths"].update(
        {path_key: {"get": {"responses": {"200": {"description": "OK"}}}}})


class _Done(Exception):
    """Internal: this plant has been judged, skip the letter-set comparison."""


class Plant:
    """
    One planted fault: what it does, and the EXACT set of checks that must
    report it.

    `letters` is the whole set, not just the interesting one. Comparing the
    whole set is the difference between "the check I meant fired" and "the check
    I meant fired AND NOTHING ELSE DID" -- and the second is what makes a plant
    evidence about one check. An earlier version asserted only that the expected
    letter appeared somewhere in the output, so a plant that also tripped a
    different check looked perfectly clean; the independent check of 30 October
    2026 found exactly that in plant A#1, by reading it rather than running it.
    """

    def __init__(self, name: str, letters: str, apply, creates=(),
                 script: str = MATCHES, expect: str | None = None) -> None:
        self.name = name
        self.letters = set(letters)
        self.apply, self.creates = apply, creates
        self.script = script
        # For the schema check, which reports in sentences rather than letters,
        # `expect` is a phrase its output must contain.
        self.expect = expect


def new_file(rel: str):
    """A plant that creates a file (and any folder it needs)."""
    target = REPO / rel
    def apply() -> None:
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text("<?php\ndeclare(strict_types=1);\n", encoding="utf-8")
    return apply, (target,)


def plants() -> list[Plant]:
    out: list[Plant] = []

    # ---- A: a reachable endpoint nobody documented -----------------------
    def a_new_handler() -> None:
        # Creating a reachable endpoint needs BOTH a file and a seeded switch,
        # so this plant writes two things -- but it must make only ONE
        # difference to the answer. An earlier version RENAMED an existing
        # switch, which also took that switch away from `venues/check` and so
        # tripped G as well as A. Adding a row leaves every existing switch
        # alone. Found by the independent check of 30 October 2026.
        apply, _ = new_file("web/_apps/venues/api/teapot.php")
        apply()
        anchor = "    (NULL, 'api.venues.check.enabled',"
        text = SQL.read_text(encoding="utf-8")
        line = next(ln for ln in text.splitlines() if ln.startswith(anchor))
        replace_once(SQL, line, line + "\n" + line.replace("venues.check", "venues.teapot"))
    out.append(Plant("a new handler, switch seeded, undocumented", "A",
                     a_new_handler, (REPO / "web/_apps/venues/api/teapot.php",)))
    out.append(Plant("an endpoint's documentation deleted", "A",
                     lambda: edit_spec(lambda s: s["paths"].pop("/api/worship/state"))))

    # ---- B: documented, but nothing answers it ---------------------------
    out.append(Plant("a documented path with no handler", "B",
                     lambda: edit_spec(add_path("/api/venues/teapot"))))
    # A v1 override name changed: the facade now points at a file that is not there.
    out.append(Plant("a v1 override action renamed", "B",
                     lambda: replace_once(ROUTER, "'noticeboard' => 'save',",
                                          "'noticeboard' => 'saveX',")))

    # ---- C: the untrue "Legacy alias: none" sentence put back -------------
    def c_reinstate() -> None:
        def change(spec):
            op = spec["paths"]["/api/v1/users"]["post"]
            op["description"] = re.sub(r"\*\*Legacy alias:\*\*[^.]*\.",
                                       "**Legacy alias:** none - new in v1.",
                                       op["description"], count=1)
        edit_spec(change)
    out.append(Plant("'Legacy alias: none' reinstated", "C", c_reinstate))

    # ---- D: the router knows a resource the description does not ----------
    out.append(Plant("a v1 resource added to the router only", "D",
                     lambda: replace_once(ROUTER, "'assets'          => 'assets',",
                                          "'assets'          => 'assets',\n"
                                          "        'teapots'         => 'venues',")))

    # ---- E: a documented shape the router cannot answer -------------------
    out.append(Plant("a documented path with four parts", "E",
                     lambda: edit_spec(add_path("/api/venues/check/extra"))))
    out.append(Plant("a v1 resource documented only in the spec", "E",
                     lambda: edit_spec(add_path("/api/v1/teapots"))))
    # The router 404s any segment after the id. This shape used to pass.
    out.append(Plant("an over-long api/v1 path", "E",
                     lambda: edit_spec(add_path("/api/v1/events/{id}/attendees"))))
    # A commented-out resource must read as ABSENT, not as live.
    out.append(Plant("a v1 resource commented out", "E",
                     lambda: replace_once(ROUTER, "'assets'          => 'assets',",
                                          "// 'assets'          => 'assets',")))

    # ---- F: a handler parked where the router can never reach it ----------
    #         All four shapes. The middle two are where push/* and tours/*
    #         really sat before #322 and #363 moved them, and the first version
    #         of the check could not see either.
    for rel in ("web/_apps/api/planted.php",
                "web/_apps/api/planted/deep.php",
                "web/_apps/venues/planted/api/deep.php",
                "web/_apps/venues/api/planted/deep.php"):
        apply, creates = new_file(rel)
        out.append(Plant("a file at %s" % rel.replace("web/_apps/", "_apps/"), "F",
                         apply, creates))
    out.append(Plant("the unreachable list gone stale (file deleted)", "F",
                     lambda: TRANSLATE.unlink()))

    # ---- G / H: the two switch lists, both directions ---------------------
    apply, creates = new_file("web/_apps/venues/api/teapot.php")
    out.append(Plant("a handler with no seeded switch", "G", apply, creates))
    def g_stale() -> None:
        # ADDS a whole statement. An earlier version RENAMED
        # `api.expenses.list.enabled`, which un-seeded expenses/list as well and
        # so tripped G's other branch too -- two changes, against point 3 in
        # this file's header. Note the install script seeds these switches in
        # two different styles; this copies the one-row style the expenses
        # switches use rather than assuming the multi-row style.
        statement = ("INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, "
                     "`defaultValue`)\nVALUES ('api.expenses.list.enabled', 'true', 0, 'true')\n"
                     "ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;")
        replace_once(SQL, statement,
                     statement + "\n\n" + statement.replace("expenses.list", "expenses.export"))
    # Declared "AG" on purpose. Seeding expenses/export makes it reachable, and
    # it is undocumented (deliberately -- see #675), so A fires as well as G's
    # stale branch. Both are real, so both are declared; sabotaging either one
    # changes the set and the plant goes red.
    out.append(Plant("the unseeded list gone stale (now seeded)", "AG", g_stale))
    def h_orphan_seed() -> None:
        # ADDS a row. An earlier version renamed venues/check's switch, which
        # took the switch away from a real handler and tripped G too.
        anchor = "    (NULL, 'api.venues.check.enabled',"
        text = SQL.read_text(encoding="utf-8")
        line = next(ln for ln in text.splitlines() if ln.startswith(anchor))
        replace_once(SQL, line, line + "\n" + line.replace("venues.check", "venues.teapot"))
    out.append(Plant("a seeded switch with no handler", "H", h_orphan_seed))

    # ---- and now the schema check -----------------------------------------
    def strip(pointer, keyword):
        """Take one keyword off one node of api-spec.json."""
        def apply() -> None:
            spec = json.loads(SPEC.read_text(encoding="utf-8"))
            node = spec
            for part in pointer:
                node = node[part]
            assert keyword in node, "nothing to remove at %r" % (pointer,)
            del node[keyword]
            SPEC.write_text(json.dumps(spec, indent=2, ensure_ascii=False) + "\n",
                            encoding="utf-8")
        return apply

    def schema_plant(name, expect, apply):
        out.append(Plant(name, "", apply, script=SCHEMA_CHECK, expect=expect))

    schema_plant("a property's description removed", "carry no `description`",
                 strip(["components", "schemas", "Asset", "properties", "name"], "description"))
    schema_plant("a parameter's description removed", "`description` is required",
                 strip(["paths", "/api/events/list", "get", "parameters", 0], "description"))
    schema_plant("an operation's tags removed", "`tags` is required",
                 strip(["paths", "/api/events/list", "get"], "tags"))
    schema_plant("an operation's summary removed", "`summary` is required",
                 strip(["paths", "/api/events/list", "get"], "summary"))

    def dangling_ref() -> None:
        spec = json.loads(SPEC.read_text(encoding="utf-8"))
        spec["paths"]["/health"]["get"]["responses"]["200"].setdefault("content", {})\
            .setdefault("application/json", {})["schema"] = {"$ref": "#/components/schemas/NoSuch"}
        SPEC.write_text(json.dumps(spec, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    schema_plant("a $ref that resolves to nothing", "does not resolve", dangling_ref)

    def undeclared_tag() -> None:
        spec = json.loads(SPEC.read_text(encoding="utf-8"))
        spec["paths"]["/health"]["get"]["tags"] = ["NotATagAnybodyDeclared"]
        SPEC.write_text(json.dumps(spec, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    schema_plant("a tag used but never declared", "is not declared", undeclared_tag)

    def unknown_keyword() -> None:
        # The guard that stops the schema becoming decorative: a keyword the
        # validator cannot see would be silently ignored.
        schema = json.loads(SCHEMA_FILE.read_text(encoding="utf-8"))
        schema["$defs"]["schemaObject"]["unevaluatedProperties"] = False
        SCHEMA_FILE.write_text(json.dumps(schema, indent=2, ensure_ascii=False) + "\n",
                               encoding="utf-8")
    schema_plant("a schema keyword the validator cannot see",
                 "does not implement", unknown_keyword)

    def broken_json() -> None:
        SPEC.write_text(SPEC.read_text(encoding="utf-8").replace("{", "{{", 1), encoding="utf-8")
    schema_plant("api-spec.json made unreadable", "not valid JSON", broken_json)
    return out


# ---------------------------------------------------------------------------
# The validator, keyword by keyword
# ---------------------------------------------------------------------------

# 🧪 Each case is (name, schema, instance, should_fail). Both directions are
#    required on purpose: a validator that reports a failure for everything
#    passes every "does it catch X" test and is useless, and a planted fault in
#    api-spec.json cannot reach a keyword the file does not happen to use.
#
#    This exists because the independent check of 30 October 2026 measured that
#    `and False` on the validator's `enum` condition left BOTH the check and
#    this selftest green: the end-to-end plants could not reach it, because
#    nothing in the schema used `enum` in a way a spec fault would trip.
VALIDATOR_CASES = [
    ("required present", {"type": "object", "required": ["a"]}, {"a": 1}, False),
    ("required missing", {"type": "object", "required": ["a"]}, {"b": 1}, True),
    ("enum member", {"enum": ["x", "y"]}, "x", False),
    ("enum outsider", {"enum": ["x", "y"]}, "z", True),
    ("pattern matches", {"type": "string", "pattern": "^a+$"}, "aaa", False),
    ("pattern fails", {"type": "string", "pattern": "^a+$"}, "bbb", True),
    ("minLength ok", {"type": "string", "minLength": 2}, "ab", False),
    ("minLength short", {"type": "string", "minLength": 2}, "a", True),
    ("non-whitespace ok", {"type": "string", "pattern": "\\S"}, " x ", False),
    ("non-whitespace blank", {"type": "string", "pattern": "\\S"}, "   ", True),
    ("propertyNames ok", {"type": "object", "propertyNames": {"pattern": "^[a-z]+$"}}, {"ab": 1}, False),
    ("propertyNames bad", {"type": "object", "propertyNames": {"pattern": "^[a-z]+$"}}, {"AB": 1}, True),
    ("additionalProperties false, clean",
     {"type": "object", "properties": {"a": {}}, "additionalProperties": False}, {"a": 1}, False),
    ("additionalProperties false, extra",
     {"type": "object", "properties": {"a": {}}, "additionalProperties": False}, {"a": 1, "b": 2}, True),
    ("minItems ok", {"type": "array", "minItems": 2}, [1, 2], False),
    ("minItems short", {"type": "array", "minItems": 2}, [1], True),
    ("minProperties ok", {"type": "object", "minProperties": 1}, {"a": 1}, False),
    ("minProperties empty", {"type": "object", "minProperties": 1}, {}, True),
    ("items ok", {"type": "array", "items": {"type": "integer"}}, [1, 2], False),
    ("items wrong", {"type": "array", "items": {"type": "integer"}}, [1, "x"], True),
    ("type integer, bool refused", {"type": "integer"}, True, True),
    ("type integer, int ok", {"type": "integer"}, 3, False),
    ("nullable null ok", {"type": "string", "nullable": True}, None, False),
    ("not nullable null refused", {"type": "string"}, None, True),
    ("allOf both met",
     {"allOf": [{"type": "object", "required": ["a"]}, {"type": "object", "required": ["b"]}]},
     {"a": 1, "b": 2}, False),
    ("allOf one unmet",
     {"allOf": [{"type": "object", "required": ["a"]}, {"type": "object", "required": ["b"]}]},
     {"a": 1}, True),
    ("anyOf one met",
     {"anyOf": [{"type": "object", "required": ["a"]}, {"type": "object", "required": ["b"]}]},
     {"b": 1}, False),
    ("anyOf none met",
     {"anyOf": [{"type": "object", "required": ["a"]}, {"type": "object", "required": ["b"]}]},
     {"c": 1}, True),
    # oneOf shared allOf's loop until 30 October 2026, so it meant "every
    # branch". Nothing in the schema uses it, which is exactly why only a direct
    # case like this can hold it honest.
    ("oneOf exactly one",
     {"oneOf": [{"type": "object", "required": ["a"]}, {"type": "object", "required": ["b"]}]},
     {"a": 1}, False),
    ("oneOf both", 
     {"oneOf": [{"type": "object", "required": ["a"]}, {"type": "object", "required": ["b"]}]},
     {"a": 1, "b": 2}, True),
    ("oneOf neither",
     {"oneOf": [{"type": "object", "required": ["a"]}, {"type": "object", "required": ["b"]}]},
     {"c": 1}, True),
    ("const met", {"const": 5}, 5, False),
    ("const unmet", {"const": 5}, 6, True),
    ("$ref resolves", {"$ref": "#/$defs/num"}, 1, False),
    ("$ref dangling", {"$ref": "#/$defs/nope"}, 1, True),
]


def validator_cases() -> list[tuple[str, str, bool, str]]:
    """Run the validator directly, in this process, on the cases above."""
    where = REPO / "tools/audit-checks/check_api_spec_schema.py"
    spec = importlib.util.spec_from_file_location("_api_spec_check", where)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    root = {"$defs": {"num": {"type": "integer"}}}
    rows = []
    for name, schema, instance, should_fail in VALIDATOR_CASES:
        errs: list[str] = []
        merged = dict(root, **schema) if "$ref" in schema else schema
        module.validate(instance, merged, dict(root, **merged), "case", errs)
        failed = bool(errs)
        ok = failed == should_fail
        why = "" if ok else (
            "expected a failure and got none" if should_fail
            else "reported a failure it should not have: " + errs[0])
        rows.append(("validator: " + name, "fail" if should_fail else "pass", ok, why))
    return rows


def main() -> int:
    for path in WATCHED:
        if path.exists() is False:
            print("✗ %s is missing, so nothing can be planted into it."
                  % path.relative_to(REPO), file=sys.stderr)
            return 1

    before = {p: sha(p) for p in WATCHED}
    for script in (MATCHES, SCHEMA_CHECK):
        code, out = run_check(script)
        if code != 0:
            print("✗ %s is ALREADY failing, so no plant below would prove anything. "
                  "Fix that first.\n\n%s" % (script, out), file=sys.stderr)
            return 1

    rows: list[tuple[str, str, bool, str]] = []
    for plant in plants():
        snapshot = {p: (p.read_bytes() if p.exists() else None) for p in WATCHED}
        try:
            plant.apply()
            code, out = run_check(plant.script)
            if plant.expect is not None:
                # Exactly ONE complaint block, and it must be the expected one.
                # Substring-matching alone let a plant that also tripped a
                # second complaint pass, while this file claims each plant is
                # caught by the expected check and by nothing else. Found by the
                # independent check of 30 October 2026.
                #
                # What this still cannot see, said plainly: one block may report
                # several instances ("3 property(ies) carry no description"), so
                # this counts complaint KINDS, not instances.
                blocks = [line for line in out.splitlines() if line.startswith("✗ ")]
                caught = code == 1 and len(blocks) == 1 and plant.expect in out
                if caught:
                    why = ""
                elif code != 1:
                    why = "the check did not fail at all (exit %d)" % code
                elif len(blocks) != 1:
                    why = ("tripped %d complaints, not 1, so it is no evidence about one of them: %s"
                           % (len(blocks), " / ".join(b[:60] for b in blocks)))
                else:
                    why = "%r is not in what it said" % plant.expect
                rows.append((plant.name, plant.expect[:18], caught, why))
                raise _Done
            got = {line[2] for line in out.splitlines()
                   if line.startswith("✗ ") and len(line) > 3 and line[3] == ":"}
            caught = code == 1 and got == plant.letters
            if caught:
                why = ""
            elif code != 1:
                why = "the check did not fail at all (exit %d)" % code
            elif plant.letters - got:
                why = "expected %s, got %s" % (sorted(plant.letters), sorted(got) or "nothing")
            else:
                why = "also tripped %s, which makes this no evidence about %s" % (
                    sorted(got - plant.letters), sorted(plant.letters))
            rows.append((plant.name, "".join(sorted(plant.letters)), caught, why))
        except _Done:
            pass
        finally:
            for path, data in snapshot.items():
                if data is None:
                    if path.exists():
                        path.unlink()
                else:
                    path.write_bytes(data)
            for made in plant.creates:
                made = Path(made)
                if made.exists():
                    made.unlink()
                parent = made.parent
                while parent != REPO and parent.exists() and not any(parent.iterdir()):
                    parent.rmdir()
                    parent = parent.parent
        for path in WATCHED:
            if sha(path) != before[path]:
                print("✗ %s was NOT restored after the plant %r. Stopping, because "
                      "anything after this would be measured against a changed tree."
                      % (path.relative_to(REPO), plant.name), file=sys.stderr)
                return 1

    rows += validator_cases()

    width = max(len(r[0]) for r in rows)

    missed = [r for r in rows if r[2] is False]
    if VERBOSE or missed:
        print("%-*s  %-6s  %s" % (width, "PLANTED FAULT", "EXPECT", "CAUGHT?"))
        print("-" * (width + 20))
        for name, letter, caught, why in rows:
            print("%-*s  %-6s  %s" % (width, name, letter, "yes" if caught else "NO — " + why))
        print("-" * (width + 20))

    for script in (MATCHES, SCHEMA_CHECK):
        code, out = run_check(script)
        if code != 0:
            print("✗ %s does not pass again after every plant was undone:\n\n%s"
                  % (script, out), file=sys.stderr)
            return 1

    if missed:
        print("\n✗ %d of %d planted fault(s) were NOT caught. The check is not "
              "guarding what it claims to guard." % (len(missed), len(rows)), file=sys.stderr)
        return 1

    planted = len(rows) - len(VALIDATOR_CASES)
    print("✓ All %d planted faults were caught, each by the expected check, and every file was "
          "restored byte-for-byte. The validator also got all %d keyword cases right, in both "
          "directions." % (planted, len(VALIDATOR_CASES)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
