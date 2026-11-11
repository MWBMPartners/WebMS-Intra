#!/usr/bin/env python3
"""
Nothing under web/ sends a member's details to an outside company except
through Portal\\Core\\OutsideServices (#778; the owner's decisions D-058, D-072).

Path: tools/audit-checks/check_outside_services_gated.py

WHY THIS EXISTS
---------------
Until #778, every page's top bar asked `www.gravatar.com` for the member's
picture at an address built from a scramble of their e-mail address -- which
Gravatar's whole business is matching to a person -- and nothing could switch
it off. A Google sign-in photo was drawn from Google's servers the same way,
and the member directory drew a map of a member's home from OpenStreetMap.

#778 made `Portal\\Core\\OutsideServices` the ONE place that decides whether
each may be used (the strictest of the installation, every organisation the
member belongs to, and the member's own choice; for a map, BOTH the member
shown and the person looking), and the one place an outside address is BUILT,
after deciding. This check is what stops that being undone one fallback at a
time -- the #623 shape, and it is modelled on #623's guard
(`check_no_outside_qr_service.py`).

WHAT IT LOOKS FOR
-----------------
1. Every occurrence of a MEMBER-IDENTIFYING HOST in any file under web/, counted
   per file. Each file that holds one must be listed in ALLOWED for that host,
   with the exact number and a reason. Unlisted fails; more than stated fails
   (a second use added to an already-listed file); fewer fails too (a listed
   note or use has gone -- change the count on purpose).
     gravatar.com, googleusercontent.com  -- ONLY OutsideServices.php.
     tile.openstreetmap.org                -- OutsideServices.php, the shared
        map script, and the three pages whose map is of a BUILDING (a venue,
        an event's venue, an organisation's own address). The member directory
        may NOT name it: its map is of a member's home and must take the host
        from OutsideServices after deciding. Nor may a small group's page
        (#809, D-120): a group often meets in somebody's home, so its map is
        treated as the host's home on the same terms.
2. `web/_core/Avatar.php` holds no outside host and, in CODE (comments
   removed by PHP's own tokeniser), calls both builders:
   `OutsideServices::providerPhotoIfAllowed(` and
   `OutsideServices::gravatarUrlIfAllowed(`.
3. `web/_core/templates/header.php` holds no outside host, and in code appends
   `OutsideServices::cspImgSources()` to `$cspImgExtra`.
4. No `$cspImgExtra` assignment anywhere under web/ names gravatar or
   googleusercontent (a page widening the rule itself is how a refused picture
   would quietly start loading again).
5. `web/_apps/directory/profile.php`, in code, decides the map with
   `OutsideServices::allows(OutsideServices::MEMBER_MAP, $id)` and widens the
   rule with `OutsideServices::mapTileSource()`.
6. `web/_apps/small-groups/group.php`, in code, decides its meeting-place map
   with `OutsideServices::allowsMeetingPlaceMap(` (#809, D-120) — TWICE: once
   for the group's usual place and once inside the loop over the hosts' homes
   the group takes turns in (D-123), each by its own row's host — widens the
   rule with `OutsideServices::mapTileSource()` inside `if ($anyMap === true)`,
   and hands the partial `'showMap' => $showMap` rather than `true`.
7. `web/_apps/small-groups/meeting.php`, in code, decides the shown place's map
   with `OutsideServices::allowsMeetingPlaceMap(` and widens the rule only
   inside `if ($showPlaceMap === true)`.

WHAT THIS CANNOT SEE, said plainly because an overstated guarantee is worse
than none
--------------------------------------------------------------------------
1. A host built up in pieces (`'www.grav' . 'atar.com'`). Matching text cannot
   follow a concatenation. That is an evasion, not an accident.
2. An outside service whose host is not on this list. The list is of the three
   services #778 governs; the follow-ups recorded on #778 (video players,
   address look-up, what3words, content-delivery libraries, the robot check)
   are NOT covered here.
3. Whether the gated code RUNS. Checks 2, 3, 5, 6 and 7 are PRESENCE only (D-065:
   "is this code reached" is behavioural). `tools/outside-services-selftest.php`
   drives the real pages and is what proves the decision is obeyed.

RUN IT
------
    python3 tools/audit-checks/check_outside_services_gated.py

Exit 0 when clean, 1 on any breach. Needs `php` on the PATH for the
tokeniser, and REFUSES (exit 1) without it rather than reading comments as
code.
"""

from __future__ import annotations

import re
import shutil
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
WEB = REPO / "web"

# 🌐 Hosts whose request tells an outside company something about a member.
GRAVATAR = "gravatar.com"
GOOGLE_PHOTO = "googleusercontent.com"
OSM_TILES = "tile.openstreetmap.org"
HOSTS = (GRAVATAR, GOOGLE_PHOTO, OSM_TILES)

OUTSIDE = "_core/OutsideServices.php"

# ✅ host -> {file: (count, reason)}. A count that no longer matches fails.
ALLOWED: dict[str, dict[str, tuple[int, str]]] = {
    GRAVATAR: {
        OUTSIDE: (2, "The one place the Gravatar address is built (GRAVATAR_BASE) and the one "
                     "page-rule source for it (SERVICES imgSources)."),
    },
    GOOGLE_PHOTO: {
        OUTSIDE: (2, "The page-rule source for a sign-in photo (SERVICES imgSources) and the host "
                     "family a stored photo must come from to be drawn (PROVIDER_PHOTO_HOST_SUFFIX)."),
    },
    OSM_TILES: {
        OUTSIDE: (1, "The page-rule source a member-map page is given AFTER deciding (mapTileSource())."),
        "_core/partials/location-map-assets.php": (
            2, "The shared map script: its tile address template, and a note telling callers which "
               "source to allow. It draws nothing until a page puts a map on itself."),
        "_apps/calendar/event.php": (1, "An event's VENUE map: a building, not a member's home."),
        "_apps/admin/settings/organisation/index.php": (1, "The organisation's OWN address map."),
        "_apps/venues/venue.php": (1, "A venue's map: a building, not a member's home."),
        # 🏠 `_apps/small-groups/group.php` is deliberately NOT here any more
        #    (#809, D-120): a group's meeting place is treated as the host's
        #    home, so that page takes the host from OutsideServices after
        #    deciding, exactly as the directory does (guard 6 below).
    },
}

AVATAR = "_core/Avatar.php"
HEADER = "_core/templates/header.php"
PROFILE = "_apps/directory/profile.php"
GROUP = "_apps/small-groups/group.php"
MEETING = "_apps/small-groups/meeting.php"

AVATAR_CALLS = (
    "OutsideServices::providerPhotoIfAllowed(",
    "OutsideServices::gravatarUrlIfAllowed(",
)
HEADER_CALL = re.compile(
    r"\$cspImgExtra\s*\.=\s*\$cspExtSanitise\(\s*\\?Portal\\Core\\OutsideServices::cspImgSources\(\)\s*\)"
)
PROFILE_DECIDE = re.compile(
    r"\$showMap\s*=\s*\$showCoordsBlock\s*===\s*true\s*&&\s*OutsideServices::allows\(\s*"
    r"OutsideServices::MEMBER_MAP\s*,\s*\$id\s*\)\s*===\s*true\s*;"
)
PROFILE_WIDEN = re.compile(
    r"if\s*\(\s*\$showMap\s*===\s*true\s*\)\s*\{\s*\$cspImgExtra\s*=\s*OutsideServices::mapTileSource\(\)\s*;"
)
GROUP_DECIDE = re.compile(
    r"\$showMap\s*=\s*\$hasPin\s*===\s*true\s*&&\s*OutsideServices::allowsMeetingPlaceMap\(", re.S
)
GROUP_WIDEN = re.compile(
    r"if\s*\(\s*\$anyMap\s*===\s*true\s*\)\s*\{\s*\$cspImgExtra\s*=\s*OutsideServices::mapTileSource\(\)\s*;"
)
DECIDE_CALL = "OutsideServices::allowsMeetingPlaceMap("
MEETING_WIDEN = re.compile(
    r"if\s*\(\s*\$showPlaceMap\s*===\s*true\s*\)\s*\{\s*\$cspImgExtra\s*=\s*OutsideServices::mapTileSource\(\)\s*;"
)
GROUP_PARTIAL = re.compile(r"'showMap'\s*=>\s*\$showMap\s*,")
CSP_ASSIGN = re.compile(r"\$cspImgExtra\s*(?:\.?=)[^;]*;", re.S)

# PHP's own tokeniser, comments dropped and everything else kept. Asked of PHP
# because a regular expression cannot tell `// gravatarUrlIfAllowed(` from code.
PHP_CODE_ONLY = r"""
$out = '';
foreach (token_get_all((string) file_get_contents($argv[1])) as $t) {
    if (is_array($t) === true) {
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
        $out .= $t[1];
    } else {
        $out .= $t;
    }
}
echo $out;
"""


class Refused(Exception):
    """The check could not do its job, which is a failure, never a pass."""


def code_only(path: Path) -> str:
    php = shutil.which("php")
    if php is None:
        raise Refused("php is not on the PATH, so comments cannot be told from code")
    done = subprocess.run([php, "-r", PHP_CODE_ONLY, str(path)], capture_output=True, text=True)
    if done.returncode != 0:
        raise Refused("PHP's tokeniser could not read %s: %s" % (path, done.stderr.strip()))
    return done.stdout


def main() -> int:
    problems: list[str] = []
    if WEB.is_dir() is False:
        print("✗ cannot find the web/ folder at %s" % WEB, file=sys.stderr)
        return 1

    # ---- 1. Count every mention, host by host, file by file ----------------
    found: dict[str, dict[str, int]] = {h: {} for h in HOSTS}
    texts: dict[str, str] = {}
    for path in sorted(WEB.rglob("*")):
        if path.is_file() is False:
            continue
        try:
            text = path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        rel = path.relative_to(WEB).as_posix()
        lowered = text.lower()
        for host in HOSTS:
            hits = lowered.count(host)
            if hits > 0:
                found[host][rel] = hits
                texts[rel] = text

    for host in HOSTS:
        for rel, hits in sorted(found[host].items()):
            if rel not in ALLOWED[host]:
                problems.append(
                    "✗ %s names %s %d time(s) and is not on the allowed list.\n"
                    "   A request to that host tells an outside company something about a member, and "
                    "only Portal\\Core\\OutsideServices may decide whether it is sent (#778, D-058). "
                    "Ask that class: gravatarUrlIfAllowed(), providerPhotoIfAllowed(), or "
                    "allows(MEMBER_MAP, $subject) and then mapTileSource()." % (rel, host, hits)
                )
                continue
            want, reason = ALLOWED[host][rel]
            if hits != want:
                problems.append(
                    "✗ %s names %s %d time(s); %d was expected.\n"
                    "   Why it is allowed at all: %s\n"
                    "   MORE means a new use has appeared -- the fault this check exists for. FEWER means "
                    "a listed use or note has gone; if on purpose, change the count here and say why."
                    % (rel, host, hits, want, reason)
                )
        for rel in sorted(ALLOWED[host]):
            if rel not in found[host]:
                problems.append(
                    "✗ %s is allowed to name %s but does not. Either it moved, or the use has gone; "
                    "remove the entry here on purpose." % (rel, host)
                )

    # ---- 2-5. The structural guards, on code with comments removed ----------
    try:
        avatar = code_only(WEB / AVATAR)
        header = code_only(WEB / HEADER)
        profile = code_only(WEB / PROFILE)
        group = code_only(WEB / GROUP)
        meeting = code_only(WEB / MEETING)
        php_files = sorted(WEB.rglob("*.php"))
        all_code = {p.relative_to(WEB).as_posix(): code_only(p) for p in php_files
                    if "cspImgExtra" in p.read_text(encoding="utf-8", errors="ignore")}
    except (Refused, OSError) as err:
        print("REFUSED: %s. Nothing was checked, and this run fails." % err, file=sys.stderr)
        return 1

    for call in AVATAR_CALLS:
        if call not in avatar:
            problems.append(
                "✗ %s no longer calls %s in code.\n"
                "   Without it the avatar is either built some other way -- without the decision -- or "
                "never offered at all. Every outside picture must come through OutsideServices." % (AVATAR, call)
            )
    if HEADER_CALL.search(header) is None:
        problems.append(
            "✗ %s no longer appends OutsideServices::cspImgSources() to $cspImgExtra in code.\n"
            "   That call is the ONE way a member-identifying host reaches the page rule's img-src; "
            "without it an allowed picture is refused by the browser and draws broken." % HEADER
        )
    for rel, code in sorted(all_code.items()):
        for match in CSP_ASSIGN.finditer(code):
            statement = match.group(0).lower()
            for host in (GRAVATAR, GOOGLE_PHOTO):
                if host in statement:
                    problems.append(
                        "✗ %s widens $cspImgExtra with %s itself: %s\n"
                        "   Only OutsideServices::cspImgSources() may do that, after deciding."
                        % (rel, host, match.group(0).strip()[:160])
                    )
    if PROFILE_DECIDE.search(profile) is None:
        problems.append(
            "✗ %s no longer decides its map with "
            "`$showMap = $showCoordsBlock === true && OutsideServices::allows(OutsideServices::MEMBER_MAP, $id) === true;`.\n"
            "   A map of a member's home must be drawn only when BOTH the member shown and the person "
            "looking allow it (D-072)." % PROFILE
        )
    if PROFILE_WIDEN.search(profile) is None:
        problems.append(
            "✗ %s no longer widens the page rule with OutsideServices::mapTileSource() inside "
            "`if ($showMap === true)`." % PROFILE
        )

    if GROUP_DECIDE.search(group) is None:
        problems.append(
            "✗ %s no longer decides its meeting-place map with "
            "`$showMap = $hasPin === true && OutsideServices::allowsMeetingPlaceMap(...)`.\n"
            "   A group often meets in somebody's home, so the map is drawn only where the person who "
            "place's NAMED HOST agreed (D-124), or the place is marked not anybody's home (D-128), on the "
            "terms of a member's own home map (#809, D-120)." % GROUP
        )
    if group.count(DECIDE_CALL) != 2:
        problems.append(
            "✗ %s calls %s %d time(s); 2 were expected -- once for the group's usual place and once for "
            "EACH host's home it takes turns in. Each home is its own host's, so each must be decided on its "
            "own row (#809, D-123)." % (GROUP, DECIDE_CALL, group.count(DECIDE_CALL))
        )
    if GROUP_WIDEN.search(group) is None:
        problems.append(
            "✗ %s no longer widens the page rule with OutsideServices::mapTileSource() inside "
            "`if ($anyMap === true)`." % GROUP
        )
    if DECIDE_CALL not in meeting or MEETING_WIDEN.search(meeting) is None:
        problems.append(
            "✗ %s no longer decides the shown place's map with %s and widens the page rule only inside "
            "`if ($showPlaceMap === true)` (#809, D-120, D-123)." % (MEETING, DECIDE_CALL)
        )
    if GROUP_PARTIAL.search(group) is None:
        problems.append(
            "✗ %s no longer hands the location partial `'showMap' => $showMap`. Handing it `true` draws "
            "the map's container whatever was decided (#809)." % GROUP
        )

    if problems:
        print("\n".join(problems), file=sys.stderr)
        print("\n%d problem(s). The rule is #778 (D-058, D-072): Portal\\Core\\OutsideServices is the one "
              "place an outside service is decided and its address built." % len(problems), file=sys.stderr)
        return 1

    total = sum(len(v) for v in found.values())
    print("✓ Outside services are gated: %d file mention(s) of a member-identifying host, all accounted "
          "for; Avatar, the page rule, the directory map and the small-group meeting-place maps (group page "
          "and meeting page) all go through OutsideServices. "
          "(Presence only -- tools/outside-services-selftest.php proves the pages obey it.)" % total)
    return 0


if __name__ == "__main__":
    sys.exit(main())
