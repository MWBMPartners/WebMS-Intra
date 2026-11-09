#!/usr/bin/env python3
"""
The public door's two feeds match their schemas, and every property explains itself (#493 P4a).

Path: tools/audit-checks/check_public_door_schema.py

WHY THIS EXISTS
---------------
This project's rule is that where it produces JSON, a JSON Schema lives beside
it with a `description` on every property -- the schema being the
documentation as well as the validator -- and that the validation is wired into
a check "so it actually runs; a schema nothing executes is a document, not a
check". The public door's two feeds are read by OTHER PEOPLE'S websites, so
their shape is a promise the portal makes to strangers:

    web/_core/data/public-door-events.schema.json   (events.json)
    web/_core/data/public-door-posters.schema.json  (posters.json)

WHAT IT CHECKS
--------------
1. Each schema is valid JSON and uses NO keyword this file's validator does not
   implement (UNDERSTOOD). A keyword it ignored would be decoration -- the one
   failure that turns a schema back into a document -- so it is refused loudly.
2. Every property under any `properties` map, every `$defs` entry and the
   schema itself carries a non-empty `description` (a bare `$ref` is allowed:
   the shape it points at carries the words).
3. The format names and version in `web/_core/PublicDoor.php` are the ones the
   schemas pin, so the code and the promise cannot drift apart.
4. The VALIDATOR ITSELF, both ways: built-in answers that must pass do, and
   built-in answers that must be refused (a database number leaking out, a
   time with no offset, a plain-http link, an unknown field, a wrong format
   name) are. A validator that passes everything would otherwise look exactly
   like a correct feed.
5. With `--answers DIR`: every `events-*.json` and `posters-*.json` file in DIR
   -- the answers tools/public-door-selftest.php captured from the REAL door
   over HTTP -- satisfies its schema. It FAILS when DIR holds none of either,
   because "nothing checked" must never read as "all fine".

WHY IT CARRIES ITS OWN VALIDATOR
--------------------------------
There is no `jsonschema` library on the development machine, and every script
in this folder uses the standard library only (#646: a check that needs
something the runner has not got does not run). It is NOT a conformant JSON
Schema implementation: it implements exactly the keywords in UNDERSTOOD. The
one in check_api_spec_schema.py could not be reused as it stands: it cannot
read a `type` written as a list ("string or null"), which these schemas need.

WHAT IT CANNOT SEE, said plainly
--------------------------------
- Whether a value is TRUE: an event that should not be public but is, with a
  well-formed answer, passes. That is tools/public-door-selftest.php's job.
- Anything about an answer it was not given: without --answers it checks the
  schemas and the validator only.

USAGE
    python3 -I tools/audit-checks/check_public_door_schema.py
    python3 -I tools/audit-checks/check_public_door_schema.py --answers DIR

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
SCHEMAS = {
    "events": REPO / "web/_core/data/public-door-events.schema.json",
    "posters": REPO / "web/_core/data/public-door-posters.schema.json",
}
DOOR = REPO / "web/_core/PublicDoor.php"

# 🧮 Every keyword the validator below implements. Nothing else may appear.
UNDERSTOOD = {
    "$schema", "$id", "$comment", "$defs", "$ref", "title", "description",
    "type", "required", "properties", "additionalProperties", "items",
    "enum", "const", "pattern", "minLength", "maxLength", "minimum",
    "maximum", "maxItems",
}

TYPES = {
    "object": dict, "array": list, "string": str, "boolean": bool,
    "number": (int, float), "integer": int, "null": type(None),
}


def keywords_used(node, found: set[str]) -> None:
    """Every keyword name used anywhere (names under properties/$defs are the author's, not keywords)."""
    if isinstance(node, dict):
        for key, value in node.items():
            found.add(key)
            if key in ("properties", "$defs") and isinstance(value, dict):
                for sub in value.values():
                    keywords_used(sub, found)
            else:
                keywords_used(value, found)
    elif isinstance(node, list):
        for item in node:
            keywords_used(item, found)


def undescribed(node, where: str, out: list[str]) -> None:
    """Every property and every $defs entry must carry a description (a bare $ref excepted)."""
    if isinstance(node, dict):
        for key in ("properties", "$defs"):
            for name, sub in (node.get(key) or {}).items():
                if isinstance(sub, dict) is False:
                    continue
                only_ref = set(sub.keys()) == {"$ref"}
                text = sub.get("description")
                if only_ref is False and (isinstance(text, str) is False or text.strip() == ""):
                    out.append("%s.%s.%s has no description" % (where, key, name))
                undescribed(sub, "%s.%s.%s" % (where, key, name), out)
        if "items" in node:
            undescribed(node["items"], where + ".items", out)


def resolve(ref: str, root: dict):
    node = root
    for part in ref.lstrip("#/").split("/"):
        if isinstance(node, dict) is False or part not in node:
            return None
        node = node[part]
    return node


def type_ok(instance, expected) -> bool:
    names = expected if isinstance(expected, list) else [expected]
    for name in names:
        want = TYPES.get(name)
        if want is None:
            continue
        if name in ("integer", "number") and isinstance(instance, bool):
            continue
        if isinstance(instance, want):
            return True
    return False


def validate(instance, schema, root: dict, where: str, out: list[str]) -> None:
    """Collect every failure rather than stopping at the first."""
    if isinstance(schema, dict) is False:
        return
    if "$ref" in schema:
        target = resolve(schema["$ref"], root)
        if target is None:
            out.append("%s: the schema's $ref %s does not resolve" % (where, schema["$ref"]))
            return
        validate(instance, target, root, where, out)
        schema = {k: v for k, v in schema.items() if k != "$ref"}
        if not schema:
            return
    if "type" in schema and type_ok(instance, schema["type"]) is False:
        out.append("%s: expected %s, found %s" % (where, schema["type"], type(instance).__name__))
        return
    if "const" in schema and (instance != schema["const"] or type(instance) is not type(schema["const"])):
        out.append("%s: must be %r" % (where, schema["const"]))
    if "enum" in schema and instance not in schema["enum"]:
        out.append("%s: %r is not one of %r" % (where, instance, schema["enum"]))
    if isinstance(instance, str):
        if "pattern" in schema and re.search(schema["pattern"], instance) is None:
            out.append("%s: %r does not match %s" % (where, instance[:80], schema["pattern"]))
        if "minLength" in schema and len(instance) < schema["minLength"]:
            out.append("%s: shorter than %d" % (where, schema["minLength"]))
        if "maxLength" in schema and len(instance) > schema["maxLength"]:
            out.append("%s: longer than %d" % (where, schema["maxLength"]))
    if isinstance(instance, (int, float)) and isinstance(instance, bool) is False:
        if "minimum" in schema and instance < schema["minimum"]:
            out.append("%s: below %s" % (where, schema["minimum"]))
        if "maximum" in schema and instance > schema["maximum"]:
            out.append("%s: above %s" % (where, schema["maximum"]))
    if isinstance(instance, list):
        if "maxItems" in schema and len(instance) > schema["maxItems"]:
            out.append("%s: more than %d entries" % (where, schema["maxItems"]))
        if "items" in schema:
            for i, item in enumerate(instance):
                validate(item, schema["items"], root, "%s[%d]" % (where, i), out)
    if isinstance(instance, dict):
        for name in schema.get("required", []):
            if name not in instance:
                out.append("%s: `%s` is required and is missing" % (where, name))
        props = schema.get("properties") or {}
        extra = schema.get("additionalProperties")
        for key, value in instance.items():
            if key in props:
                validate(value, props[key], root, "%s.%s" % (where, key), out)
            elif extra is False:
                out.append("%s: `%s` is not allowed here" % (where, key))
            elif isinstance(extra, dict):
                validate(value, extra, root, "%s.%s" % (where, key), out)


# ── Built-in answers for checking the validator itself, both ways ───────────
ORG = {"name": "Example Church", "timezone": "Europe/London"}
GOOD_EVENT = {
    "id": "harvest-supper", "title": "Harvest supper", "startsAt": "2026-11-20T19:00:00+00:00",
    "endsAt": None, "startsOnClock": "2026-11-20T19:00:00", "endsOnClock": None, "allDay": False, "timezone": "Europe/London", "detailsLimited": False,
    "description": "Bring a dish.", "location": {"name": "Hall", "address": None, "url": "https://example.org/hall"},
}
GOOD_POSTER = {
    "title": "Toddler group", "kicker": None, "category": "Children", "when": {"type": "weekly", "weekday": 2},
    "time": "10:00", "location": "Hall", "link": None,
    "media": {"type": "image", "url": "https://events.example.org/media/" + "a" * 32 + ".png"},
    "thumbUrl": None, "canvaUrl": None,
}


def events_doc(events) -> dict:
    return {"format": "webms-public-events", "version": 1, "organisation": ORG,
            "generatedAt": "2026-11-09T12:00:00Z", "events": events}


def posters_doc(posters) -> dict:
    return {"format": "webms-public-posters", "version": 1, "organisation": ORG,
            "generatedAt": "2026-11-09T12:00:00Z", "posters": posters}


def with_change(base: dict, **changes) -> dict:
    out = dict(base)
    out.update(changes)
    return out


MUST_PASS = [
    ("events", "an empty events feed", events_doc([])),
    ("events", "an ordinary event", events_doc([GOOD_EVENT])),
    ("events", "an event with its details withheld",
     events_doc([with_change(GOOD_EVENT, detailsLimited=True, description=None, location=None)])),
    ("posters", "an empty posters feed", posters_doc([])),
    ("posters", "an ordinary poster", posters_doc([GOOD_POSTER])),
    ("posters", "a Canva poster with no media",
     posters_doc([with_change(GOOD_POSTER, media=None, canvaUrl="https://www.canva.com/design/x/view")])),
]
MUST_FAIL = [
    ("events", "a database number leaking out", events_doc([with_change(GOOD_EVENT, eventID=42)])),
    ("events", "a creator leaking out", events_doc([with_change(GOOD_EVENT, createdByID=7)])),
    ("events", "a start time with no offset", events_doc([with_change(GOOD_EVENT, startsAt="2026-11-20 19:00:00")])),
    # #493 P4b: the stored reading carries NO offset (it is a reading, not a moment).
    ("events", "a stored reading given an offset", events_doc([with_change(GOOD_EVENT, startsOnClock="2026-11-20T19:00:00+00:00")])),
    ("events", "a plain-http place address",
     events_doc([with_change(GOOD_EVENT, location={"name": None, "address": None, "url": "http://x.org"})])),
    ("events", "a telephone number inside the location",
     events_doc([with_change(GOOD_EVENT, location={"name": None, "address": None, "url": None, "phone": "01234"})])),
    ("events", "the posters format name on the events feed", with_change(events_doc([]), format="webms-public-posters")),
    ("events", "a version given as text", with_change(events_doc([]), version="1")),
    ("events", "a missing field", events_doc([{k: v for k, v in GOOD_EVENT.items() if k != "detailsLimited"}])),
    ("events", "fifty-one events", events_doc([GOOD_EVENT] * 51)),
    ("posters", "a sort order leaking out", posters_doc([with_change(GOOD_POSTER, sortOrder=3)])),
    ("posters", "a plain-http link", posters_doc([with_change(GOOD_POSTER, link="http://example.org")])),
    ("posters", "a javascript: picture", posters_doc([with_change(GOOD_POSTER, media={"type": "image", "url": "javascript:alert(1)"})])),
    ("posters", "a Canva address elsewhere", posters_doc([with_change(GOOD_POSTER, canvaUrl="https://evil.example/www.canva.com/")])),
    ("posters", "a weekday of 7", posters_doc([with_change(GOOD_POSTER, when={"type": "weekly", "weekday": 7})])),
]


def door_constants() -> dict:
    text = DOOR.read_text(encoding="utf-8")
    found = {}
    for name in ("EVENTS_FORMAT", "POSTERS_FORMAT"):
        m = re.search(r"public const %s\s*=\s*'([^']*)';" % name, text)
        found[name] = m.group(1) if m else None
    m = re.search(r"public const FORMAT_VERSION\s*=\s*(\d+);", text)
    found["FORMAT_VERSION"] = int(m.group(1)) if m else None
    return found


def main() -> int:
    problems: list[str] = []
    schemas = {}
    for kind, path in SCHEMAS.items():
        try:
            schemas[kind] = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError) as e:
            problems.append("%s: cannot be read as JSON (%s)" % (path.relative_to(REPO), e))
            continue
        used: set[str] = set()
        keywords_used(schemas[kind], used)
        unknown = sorted(used - UNDERSTOOD)
        if unknown:
            problems.append("%s uses keyword(s) this check cannot enforce: %s" % (path.name, ", ".join(unknown)))
        if not str(schemas[kind].get("description", "")).strip():
            problems.append("%s has no top-level description" % path.name)
        missing: list[str] = []
        undescribed(schemas[kind], path.name, missing)
        problems.extend(missing)
    if problems:
        for p in problems:
            print("✗ " + p)
        return 1

    consts = door_constants()
    want = {"EVENTS_FORMAT": schemas["events"]["properties"]["format"].get("const"),
            "POSTERS_FORMAT": schemas["posters"]["properties"]["format"].get("const"),
            "FORMAT_VERSION": schemas["events"]["properties"]["version"].get("const")}
    for name, value in want.items():
        if consts.get(name) != value:
            problems.append("PublicDoor.php's %s is %r but the schema pins %r" % (name, consts.get(name), value))
    if schemas["posters"]["properties"]["version"].get("const") != want["FORMAT_VERSION"]:
        problems.append("the two schemas pin different versions")

    for kind, label, doc in MUST_PASS:
        errs: list[str] = []
        validate(doc, schemas[kind], schemas[kind], kind, errs)
        if errs:
            problems.append("the validator REFUSED a good answer (%s): %s" % (label, errs[0]))
    for kind, label, doc in MUST_FAIL:
        errs = []
        validate(doc, schemas[kind], schemas[kind], kind, errs)
        if not errs:
            problems.append("the validator ACCEPTED a bad answer (%s)" % label)

    checked = 0
    if "--answers" in sys.argv:
        at = sys.argv.index("--answers")
        if at + 1 >= len(sys.argv):
            problems.append("--answers needs a folder")
        else:
            folder = Path(sys.argv[at + 1])
            seen = {"events": 0, "posters": 0}
            for kind in ("events", "posters"):
                for path in sorted(folder.glob(kind + "-*.json")):
                    seen[kind] += 1
                    try:
                        doc = json.loads(path.read_text(encoding="utf-8"))
                    except (OSError, ValueError) as e:
                        problems.append("%s: not JSON (%s)" % (path.name, e))
                        continue
                    errs = []
                    validate(doc, schemas[kind], schemas[kind], path.name, errs)
                    problems.extend(errs)
                    checked += 1
            for kind, n in seen.items():
                if n == 0:
                    problems.append("--answers %s holds no %s-*.json answer, so nothing of that feed was checked" % (folder, kind))

    if problems:
        for p in problems:
            print("✗ " + p)
        return 1
    print("check_public_door_schema: OK — both schemas use only keywords this check enforces, every property is described, "
          "PublicDoor.php's format names match, the validator passed %d good and refused %d bad built-in answers%s."
          % (len(MUST_PASS), len(MUST_FAIL), (", and %d captured answer(s) satisfy their schema" % checked) if checked else ""))
    return 0


if __name__ == "__main__":
    sys.exit(main())
