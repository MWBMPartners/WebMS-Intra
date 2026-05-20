#!/usr/bin/env python3
"""
WebMS-Intra — Required-check audit

Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
Proprietary. Unauthorised copying, modification or distribution prohibited.

PURPOSE
-------
Cross-checks every "required status check" in this repo's rulesets and
branch-protection rules against the check names that workflow files in
this repo would actually emit. Reports orphans (a required check that
no workflow would ever produce) — the class of misconfiguration that
silently soft-locks PRs.

Run locally:
    python3 tools/audit-required-checks.py
    python3 tools/audit-required-checks.py --repo MWBMPartners/WebMS-Intra

Run in CI:
    invoked by .github/workflows/repo-config-audit.yml

Exit codes:
    0  no orphans, repo config is consistent
    1  one or more orphans found (printed to stdout)
    2  failed to query GitHub (auth / permission issue)

Requires:
    - `gh` CLI authenticated with admin:read scope on the target repo
      (rulesets API requires admin; branch-protection requires repo)
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from typing import Iterable


def gh(args: list[str]) -> dict | list:
    """Invoke `gh api` with the given arg list and parse JSON output."""
    res = subprocess.run(
        ["gh", "api"] + args,
        capture_output=True,
        text=True,
        check=False,
    )
    if res.returncode != 0:
        sys.stderr.write(f"gh api {args} failed: {res.stderr}\n")
        sys.exit(2)
    if not res.stdout.strip():
        return {}
    return json.loads(res.stdout)


def collect_required_checks(repo: str) -> dict[str, list[str]]:
    """Return {check_name: [source, ...]} aggregated across rulesets + branch protection."""
    required: dict[str, list[str]] = {}

    rulesets = gh([f"repos/{repo}/rulesets"])
    if isinstance(rulesets, list):
        for rs in rulesets:
            detail = gh([f"repos/{repo}/rulesets/{rs['id']}"])
            for rule in detail.get("rules", []) or []:
                if rule.get("type") != "required_status_checks":
                    continue
                checks = rule.get("parameters", {}).get("required_status_checks", []) or []
                for c in checks:
                    ctx = c.get("context")
                    if ctx:
                        required.setdefault(ctx, []).append(f"ruleset:{detail['name']}")

    for branch in ("main", "beta", "alpha"):
        try:
            prot = gh([f"repos/{repo}/branches/{branch}/protection"])
        except SystemExit:
            continue
        rsc = prot.get("required_status_checks")
        if not rsc:
            continue
        for ctx in rsc.get("contexts", []) or []:
            required.setdefault(ctx, []).append(f"branch-protection:{branch}")
        for c in rsc.get("checks", []) or []:
            ctx = c.get("context")
            if ctx:
                required.setdefault(ctx, []).append(f"branch-protection:{branch}")

    return required


def collect_emitted_checks(workflows_dir: str = ".github/workflows") -> dict[str, str]:
    """Scan workflow YAML files and return {check_name: workflow_file (workflow_name)}."""
    emitted: dict[str, str] = {}
    if not os.path.isdir(workflows_dir):
        return emitted

    for fn in sorted(os.listdir(workflows_dir)):
        if not fn.endswith((".yml", ".yaml")):
            continue
        path = os.path.join(workflows_dir, fn)
        with open(path, "r", encoding="utf-8") as f:
            content = f.read()

        m = re.search(r"^name:\s*(.+?)\s*$", content, re.M)
        workflow_name = m.group(1).strip() if m else fn

        # Capture the jobs: block. Crude but adequate for audit.
        jobs_match = re.search(r"^jobs:\s*\n([\s\S]+?)(?=^[a-z]|\Z)", content, re.M)
        if not jobs_match:
            continue

        # Each top-level job: 2-space indent, jobname:, then its body indented 4+ spaces.
        for jm in re.finditer(
            r"^\s{2}([a-zA-Z0-9_-]+):\s*\n((?:^\s{4,}.*\n)+)",
            jobs_match.group(1),
            re.M,
        ):
            job_id = jm.group(1)
            job_body = jm.group(2)
            nm = re.search(r"^\s{4}name:\s*(.+?)\s*$", job_body, re.M)
            job_name = nm.group(1).strip() if nm else job_id
            # The "check name" GitHub reports is the job's `name:` (or its job_id if no name).
            emitted[job_name] = f"{fn} ({workflow_name})"

    return emitted


def main(argv: Iterable[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--repo",
        default=os.environ.get("GITHUB_REPOSITORY", "MWBMPartners/WebMS-Intra"),
        help="owner/repo (default from $GITHUB_REPOSITORY or MWBMPartners/WebMS-Intra)",
    )
    parser.add_argument(
        "--workflows-dir",
        default=".github/workflows",
        help="path to the workflow files (default: .github/workflows)",
    )
    parser.add_argument(
        "--strict",
        action="store_true",
        help="exit 1 on any unused workflow check too (default: only on orphans)",
    )
    args = parser.parse_args(argv)

    print(f"Audit target: {args.repo}")
    print(f"Workflows dir: {args.workflows_dir}")
    print()

    required = collect_required_checks(args.repo)
    emitted = collect_emitted_checks(args.workflows_dir)

    print(f"Required check names ({len(required)}):")
    for name, sources in sorted(required.items()):
        print(f"  - {name!r}")
        for s in sources:
            print(f"      from {s}")
    print()
    print(f"Workflow-emitted check names ({len(emitted)}):")
    for name, src in sorted(emitted.items()):
        print(f"  - {name!r} from {src}")
    print()

    orphans = [(n, s) for n, s in required.items() if n not in emitted]
    unused = [n for n in emitted if n not in required]

    print("=" * 60)
    if orphans:
        print(f"FAIL — {len(orphans)} orphaned required check(s):")
        for name, sources in orphans:
            print(f"  ❌ {name!r}")
            for s in sources:
                print(f"      required by {s}, but no workflow emits this name")
        print()
        print("To fix: either rename the requirement to match an existing")
        print("workflow's job `name:` (with NO 'workflow / job' prefix), or")
        print("remove the requirement, or build a workflow that emits the name.")
        return 1

    print("OK — every required check matches a workflow job name.")
    if unused:
        print(f"\nInformational: {len(unused)} workflow job(s) NOT required by any rule:")
        for n in unused:
            print(f"  - {n!r} from {emitted[n]}")
        if args.strict:
            return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
