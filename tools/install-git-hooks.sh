#!/usr/bin/env bash
# Path: tools/install-git-hooks.sh
# -----------------------------------------------------------------------------
# Turn this repository's git hooks on, once per clone 🪝 (#569)
# -----------------------------------------------------------------------------
# Run it once after cloning:
#
#     tools/install-git-hooks.sh
#
# It points this clone's `core.hooksPath` at the versioned `.githooks` folder,
# so `.githooks/pre-push` runs before every push from this clone.
#
# WHY A SCRIPT RATHER THAN SOMETHING AUTOMATIC
# Git does not copy hooks when a repository is cloned, and there is no way to
# make it — by design, because a hook is code that would then run on somebody's
# machine without them ever agreeing to it. So every clone has to be told once,
# and this script is that one step.
#
# WHY NOT A GLOBAL HOOKS FOLDER (the owner's decision, 5 October 2026)
# A global `core.hooksPath` applies to EVERY repository on the machine and
# OVERRIDES each one's own hooks. Projects here already have their own, and
# they would stop running. One check per repository, turned on per clone, keeps
# every project's hooks working.
#
# SAFE TO RUN TWICE. It only writes the setting when it is not already right,
# and says which it did.
#
# WHAT IT DOES NOT DO: it does not change anything outside this clone, and it
# does not touch your global git configuration.
#
# @package   WebMS Intra
# @author    MWBM Partners Ltd (t/a MWservices)
# @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
# @license   All Rights Reserved
# -----------------------------------------------------------------------------

set -euo pipefail

if ! git rev-parse --show-toplevel > /dev/null 2>&1; then
    printf '✗ Not inside a git repository — nothing to do.\n' >&2
    exit 1
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "${REPO_ROOT}"

HOOKS_DIR=".githooks"
PRE_PUSH="${HOOKS_DIR}/pre-push"

if [ ! -d "${HOOKS_DIR}" ]; then
    printf '✗ %s/%s does not exist. Are you in the right repository?\n' "${REPO_ROOT}" "${HOOKS_DIR}" >&2
    exit 1
fi

if [ ! -f "${PRE_PUSH}" ]; then
    printf '✗ %s is missing, so turning hooks on would do nothing.\n' "${PRE_PUSH}" >&2
    exit 1
fi

# 🔑 A hook that is not executable is silently ignored by git — no warning, no
#    error, the push simply goes through unchecked. That is the one failure
#    this script exists to prevent, so the bit is set here rather than trusted
#    to whatever the file arrived with. (Git stores the executable bit, so this
#    is normally already right; a zip download is where it gets lost.)
chmod +x "${PRE_PUSH}"

CURRENT="$(git config --local --get core.hooksPath || true)"

if [ "${CURRENT}" = "${HOOKS_DIR}" ]; then
    printf '✅ Already on: core.hooksPath = %s\n' "${HOOKS_DIR}"
else
    if [ -n "${CURRENT}" ]; then
        printf '⚠️  core.hooksPath was %s — changing it to %s\n' "${CURRENT}" "${HOOKS_DIR}"
    fi
    git config --local core.hooksPath "${HOOKS_DIR}"
    printf '✅ Hooks turned on: core.hooksPath = %s\n' "${HOOKS_DIR}"
fi

printf '\nFrom now on, a push from this clone first runs:\n'
printf '  • php -l on the PHP files being pushed\n'
printf '  • every script in tools/audit-checks/ (about eight seconds)\n'
printf '\nIt does NOT run the self-tests or the migration harness — those need a\n'
printf 'database and run in the pull-request checks. `git push --no-verify`\n'
printf 'skips the hook; git provides no way to prevent that.\n'
