#!/usr/bin/env python3
"""
The API description matches its own schema, and every property explains itself (#641).

Path: tools/audit-checks/check_api_spec_schema.py

WHY THIS EXISTS
---------------
This project's rule is that where it produces JSON, a JSON Schema lives beside
it with a `description` on every property -- the schema being the documentation
as well as the validator -- and that the validation is wired into a check "so it
actually runs; a schema nothing executes is a document, not a check".

`web/_core/api-spec.json` had no schema at all, which is part of how ten untrue
statements and 286 undescribed properties accumulated in it unnoticed. The
schema is `web/_core/api-spec.schema.json`. This file executes it.

WHY IT CARRIES ITS OWN VALIDATOR, WHICH IS NOT THE OBVIOUS CHOICE
-----------------------------------------------------------------
There is no `jsonschema` library on the development machine, and every other
script in this folder uses the standard library only. Adding a dependency would
mean the check could not be run before pushing -- and #646's lesson was exactly
that: a test that needs something the runner has not got is a test that does not
run. So the subset of JSON Schema the schema file uses is implemented here, in
about 150 lines, and the schema's own `$comment` lists the keywords it may use.

**The honest cost:** this is NOT a conformant JSON Schema implementation. It
covers the keywords listed in the schema's `$comment` and nothing else, and it
silently IGNORES any keyword it does not know. So adding a keyword to the schema
file without teaching it here makes that keyword decorative -- which would turn
the schema back into a document. `UNDERSTOOD` below is the guard: the check
fails when the schema uses a keyword this validator does not implement, so the
failure is loud rather than silent.

WHAT IT CHECKS
--------------
1. The schema file is valid JSON and uses no keyword this validator cannot see.
2. `api-spec.json` satisfies it -- which, through `describedProperty`, means
   **every property under any `properties` map carries a non-empty
   `description`**, unless it is only a `$ref` or an `allOf` (where the shape
   pointed at carries the words).
3. Every tag an operation names is declared in the top-level `tags`.
4. Every `$ref` resolves.
5. It REPORTS, without failing, any schema that nothing points at -- a shape
   the prose may still send a reader to while no operation uses it.

WHAT THIS CANNOT SEE, said plainly because an overstated guarantee is worse
than none
--------------------------------------------------------------------------
- **Whether a description is TRUE.** It checks that words are present, not that
  they are right. The three untrue statements corrected on 30 October 2026 all
  had descriptions.
- **Whether a declared type matches what the handler really sends.** A field
  declared `string` that comes back as an integer passes. Several do: a MySQL
  `DECIMAL` arrives as a string, and `format: date-time` is declared on fields
  that return a plain MySQL `DATETIME`, which is not RFC 3339.
- **Anything about the handlers.** That the described addresses match the code
  is `check_api_spec_matches_code.py`'s job, not this one.

USAGE
    python3 -I tools/audit-checks/check_api_spec_schema.py
    python3 -I tools/audit-checks/check_api_spec_schema.py --list-missing

Package:   WebMS-Intra
Author:    MWBM Partners Ltd (t/a MWservices)
Copyright: All Rights Reserved
Version:   1.0.0
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
SPEC = REPO / "web/_core/api-spec.json"
SCHEMA = REPO / "web/_core/api-spec.schema.json"

# 🧮 Every keyword this validator implements. The schema file may use these and
#    no others: a keyword outside this set would be silently decorative, which
#    is the one failure mode that would turn the schema back into a document.
UNDERSTOOD = {
    "$schema", "$id", "$comment", "$defs", "$ref", "title", "description",
    "type", "required", "properties", "additionalProperties",
    "patternProperties", "propertyNames", "items", "enum", "const", "pattern",
    "minLength", "maxLength", "minimum", "maximum", "minItems", "maxItems",
    "minProperties", "maxProperties", "allOf", "anyOf", "oneOf", "not",
    "nullable", "format", "default", "example", "readOnly", "writeOnly",
    "deprecated",
}

TYPES = {
    "object": dict, "array": list, "string": str, "boolean": bool,
    "number": (int, float), "integer": int, "null": type(None),
}


def keywords_used(node, found: set[str]) -> None:
    """Every keyword name appearing anywhere in the schema document."""
    if isinstance(node, dict):
        for key, value in node.items():
            found.add(key)
            # Under these, the keys are NAMES chosen by the author, not keywords.
            if key in ("properties", "patternProperties", "$defs"):
                if isinstance(value, dict):
                    for sub in value.values():
                        keywords_used(sub, found)
            else:
                keywords_used(value, found)
    elif isinstance(node, list):
        for item in node:
            keywords_used(item, found)


def resolve(ref: str, root: dict):
    """A local `#/a/b` pointer. Anything else is refused by the caller."""
    node = root
    for part in ref.lstrip("#/").split("/"):
        part = part.replace("~1", "/").replace("~0", "~")
        if isinstance(node, dict) is False or part not in node:
            return None
        node = node[part]
    return node


def validate(instance, schema, root: dict, where: str, out: list[str]) -> None:
    """Collect every failure, rather than stopping at the first."""
    if isinstance(schema, dict) is False:
        return

    if "$ref" in schema:
        target = resolve(schema["$ref"], root)
        if target is None:
            out.append("%s: the schema's own $ref %s does not resolve"
                       % (where, schema["$ref"]))
            return
        validate(instance, target, root, where, out)
        # A $ref alongside other keywords: apply those too (2020-12 allows it).
        schema = {k: v for k, v in schema.items() if k != "$ref"}
        if not schema:
            return

    if instance is None and schema.get("nullable") is True:
        return

    expected = schema.get("type")
    if expected is not None:
        want = TYPES.get(expected)
        ok = isinstance(instance, want) if want is not None else True
        # JSON true/false is not a number, and a bool is an int in Python.
        if expected in ("integer", "number") and isinstance(instance, bool):
            ok = False
        if ok is False:
            out.append("%s: expected %s, found %s"
                       % (where, expected, type(instance).__name__))
            return

    if "const" in schema and instance != schema["const"]:
        out.append("%s: must be %r" % (where, schema["const"]))
    if "enum" in schema and instance not in schema["enum"]:
        out.append("%s: %r is not one of %r" % (where, instance, schema["enum"]))

    if isinstance(instance, str):
        if "pattern" in schema and re.search(schema["pattern"], instance) is None:
            out.append("%s: %r does not match %s"
                       % (where, instance[:60], schema["pattern"]))
        if "minLength" in schema and len(instance) < schema["minLength"]:
            out.append("%s: must be at least %d character(s); it is %d"
                       % (where, schema["minLength"], len(instance)))
        if "maxLength" in schema and len(instance) > schema["maxLength"]:
            out.append("%s: must be at most %d character(s)" % (where, schema["maxLength"]))

    if isinstance(instance, (int, float)) and isinstance(instance, bool) is False:
        if "minimum" in schema and instance < schema["minimum"]:
            out.append("%s: must be at least %s" % (where, schema["minimum"]))
        if "maximum" in schema and instance > schema["maximum"]:
            out.append("%s: must be at most %s" % (where, schema["maximum"]))

    if isinstance(instance, list):
        if "minItems" in schema and len(instance) < schema["minItems"]:
            out.append("%s: must hold at least %d entry(ies)" % (where, schema["minItems"]))
        if "maxItems" in schema and len(instance) > schema["maxItems"]:
            out.append("%s: must hold at most %d entry(ies)" % (where, schema["maxItems"]))
        if "items" in schema:
            for i, item in enumerate(instance):
                validate(item, schema["items"], root, "%s[%d]" % (where, i), out)

    if isinstance(instance, dict):
        for name in schema.get("required", []):
            if name not in instance:
                out.append("%s: `%s` is required and is missing" % (where, name))
        if "minProperties" in schema and len(instance) < schema["minProperties"]:
            out.append("%s: must hold at least %d member(s)" % (where, schema["minProperties"]))
        if "maxProperties" in schema and len(instance) > schema["maxProperties"]:
            out.append("%s: must hold at most %d member(s)" % (where, schema["maxProperties"]))

        props = schema.get("properties") or {}
        patterns = schema.get("patternProperties") or {}
        names = schema.get("propertyNames")
        extra = schema.get("additionalProperties")
        for key, value in instance.items():
            child = "%s.%s" % (where, key)
            if names is not None:
                validate(key, names, root, "%s (its NAME)" % child, out)
            if key in props:
                validate(value, props[key], root, child, out)
                continue
            matched = [s for p, s in patterns.items() if re.search(p, key)]
            if matched:
                for s in matched:
                    validate(value, s, root, child, out)
                continue
            if extra is False:
                out.append("%s: `%s` is not allowed here" % (where, key))
            elif isinstance(extra, dict):
                validate(value, extra, root, child, out)

    for sub in schema.get("allOf", []):
        validate(instance, sub, root, where, out)
    if "oneOf" in schema:
        # EXACTLY one branch. This shared the allOf loop until 30 October 2026,
        # which made it mean "every branch", the opposite for more than one
        # branch. Nothing in the schema used it, so nothing was wrong -- but
        # UNDERSTOOD claimed this validator implements what it lists, and that
        # claim was false. Found by the independent check with a two-branch
        # schema. A keyword enforced WRONGLY does not trip the UNDERSTOOD guard,
        # which only catches a keyword that is not implemented at all.
        passed = 0
        for sub in schema["oneOf"]:
            errs: list[str] = []
            validate(instance, sub, root, where, errs)
            if not errs:
                passed += 1
        if passed != 1:
            out.append("%s: must satisfy exactly ONE of the %d permitted shapes, and it satisfies %d"
                       % (where, len(schema["oneOf"]), passed))
    if "anyOf" in schema:
        # Report only when EVERY branch fails, and say why each did -- the
        # describedProperty rule is an anyOf, so this message is the one a
        # reader of this check will actually see.
        attempts = []
        for sub in schema["anyOf"]:
            errs: list[str] = []
            validate(instance, sub, root, where, errs)
            if not errs:
                break
            attempts.append(errs)
        else:
            out.append("%s: satisfies none of the permitted shapes (%s)"
                       % (where, "; ".join(a[0].split(": ", 1)[-1] for a in attempts)))
    if "not" in schema:
        errs: list[str] = []
        validate(instance, schema["not"], root, where, errs)
        if not errs:
            out.append("%s: must NOT match the forbidden shape" % where)


def cross_reference(spec: dict) -> tuple[list[str], list[str]]:
    """Checks JSON Schema cannot express: tags declared, and $refs resolving."""
    problems: list[str] = []
    declared = {t["name"] for t in spec.get("tags", []) if isinstance(t, dict) and "name" in t}
    used: dict[str, list[str]] = {}
    for path, item in (spec.get("paths") or {}).items():
        if isinstance(item, dict) is False:
            continue
        for method, op in item.items():
            if isinstance(op, dict) is False:
                continue
            for tag in op.get("tags", []):
                used.setdefault(tag, []).append("%s %s" % (method.upper(), path))
    for tag in sorted(set(used) - declared):
        problems.append("the tag %r is used by %s but is not declared in the top-level `tags`"
                        % (tag, ", ".join(used[tag][:3])))

    refs: set[str] = set()
    def walk(node):
        if isinstance(node, dict):
            for key, value in node.items():
                if key == "$ref" and isinstance(value, str):
                    refs.add(value)
                else:
                    walk(value)
        elif isinstance(node, list):
            for item in node:
                walk(item)
    walk(spec)
    for ref in sorted(refs):
        if ref.startswith("#/") is False:
            problems.append("the $ref %r points outside this file" % ref)
        elif resolve(ref, spec) is None:
            problems.append("the $ref %r does not resolve" % ref)

    schemas = (spec.get("components") or {}).get("schemas") or {}
    unused = sorted(n for n in schemas if "#/components/schemas/" + n not in refs)
    return problems, unused


def main() -> int:
    for needed in (SPEC, SCHEMA):
        if needed.is_file() is False:
            print("✗ %s is missing." % needed.relative_to(REPO), file=sys.stderr)
            return 1
    try:
        schema = json.loads(SCHEMA.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        print("✗ %s is not valid JSON: %s" % (SCHEMA.relative_to(REPO), exc), file=sys.stderr)
        return 1
    try:
        spec = json.loads(SPEC.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        print("✗ %s is not valid JSON: %s" % (SPEC.relative_to(REPO), exc), file=sys.stderr)
        return 1

    # 🛡️ The guard that keeps the schema from becoming decorative.
    found: set[str] = set()
    keywords_used(schema, found)
    unknown = sorted(found - UNDERSTOOD)
    if unknown:
        print("✗ %s uses %d keyword(s) this check's validator does not implement: %s\n"
              "   A keyword it cannot see is IGNORED, which would make that part of the "
              "schema decorative -- the exact failure the project rule about schemas "
              "warns against. Implement it in validate(), or take it out of the schema."
              % (SCHEMA.relative_to(REPO), len(unknown), ", ".join(unknown)), file=sys.stderr)
        return 1

    errors: list[str] = []
    validate(spec, schema, schema, "api-spec", errors)
    cross, unused = cross_reference(spec)

    missing_description = [e for e in errors if "satisfies none of the permitted shapes" in e]
    other = [e for e in errors if e not in missing_description]

    if "--list-missing" in sys.argv:
        print("Properties with no `description` (%d):" % len(missing_description))
        for e in missing_description:
            print("   " + e.split(":")[0].replace("api-spec.", "", 1))
        return 0

    if errors or cross:
        if missing_description:
            print("✗ %d property(ies) carry no `description`. A schema is this project's "
                  "documentation as well as its validator, so a property with no words "
                  "leaves a developer guessing:" % len(missing_description), file=sys.stderr)
            for e in missing_description[:40]:
                print("     " + e.split(":")[0].replace("api-spec.", "", 1), file=sys.stderr)
            if len(missing_description) > 40:
                print("     ... and %d more. Run with --list-missing for all of them."
                      % (len(missing_description) - 40), file=sys.stderr)
        if other:
            print("\n✗ %d place(s) where the description does not match its own schema:"
                  % len(other), file=sys.stderr)
            for e in other[:40]:
                print("     " + e, file=sys.stderr)
            if len(other) > 40:
                print("     ... and %d more." % (len(other) - 40), file=sys.stderr)
        if cross:
            print("\n✗ %d cross-reference problem(s):" % len(cross), file=sys.stderr)
            for c in cross:
                print("     " + c, file=sys.stderr)
        print("\nThe rule is #641: api-spec.json must satisfy api-spec.schema.json, and "
              "every property must explain itself.", file=sys.stderr)
        return 1

    note = ""
    if unused:
        note = ("\n  Worth knowing (not a failure): %d schema(s) nothing points at — %s. "
                "A shape the prose may still send a reader to while no operation uses it."
                % (len(unused), ", ".join(unused)))
    print("✓ api-spec.json satisfies api-spec.schema.json: every property explains itself, "
          "every tag is declared, and every $ref resolves.%s" % note)
    return 0


if __name__ == "__main__":
    sys.exit(main())
