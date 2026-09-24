#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# tools/watchdog.sh — wake the assistant when something it is waiting on
# finishes, stalls or dies, whether or not that thing's own "finished" notice
# ever arrives.
# -----------------------------------------------------------------------------
# @package   WebMS-Intra (development tooling — never deployed; only web/ is)
# @author    Salem874
# @copyright MWBM Partners Ltd (t/a MWservices). All Rights Reserved.
# @version   1.0.0 (24 September 2026)
#
# WHY THIS EXISTS
# An assistant session (Claude Code, Codex, or whatever comes next) only acts
# when something wakes it. On 23 September 2026 a background agent finished and
# its completion notice never arrived, so the work sat idle until the maintainer
# asked "are we stuck?". The maintainer's standing rule since 24 September:
# whenever you wait for something to complete, start a watchdog beside it.
# Run this as a BACKGROUND command; when it exits, the session is woken and looks.
#
# WHY THERE ARE TWO COPIES
# This copy is committed so the rule works on ANY machine that checks out this
# repository. The maintainer's Mac also has ~/.claude/bin/watchdog.sh for every
# other project. Keep the two copies' LOGIC identical; only the headers differ.
#
# EVERY MODE HAS A HARD DEADLINE as well as its condition. A waiting loop with
# no deadline once spun for seven hours after the file it waited for had been
# deleted by its own agent's clean-up.
#
# USAGE (times in seconds; CAP defaults to 14400 = 4 hours)
#   tools/watchdog.sh quiet    <file> [idle=900] [cap]   file unchanged for <idle>s
#                                                        (or never appears in 20 min)
#   tools/watchdog.sh exists   <file> [cap]              file appears
#   tools/watchdog.sh contains <file> <text> [cap]       file contains <text>
#   tools/watchdog.sh pid      <pid> [cap]               process <pid> has ended
#
# WHAT IT CANNOT DO
# It cannot tell "finished" from "stalled": a quiet file means one or the other.
# When it fires, LOOK — then act, or start it again if the work is still going.
#
# PORTABILITY
# Plain bash, no zsh-only syntax. A file's age is read with GNU `stat -c %Y`
# (Linux) first and BSD `stat -f %m` (macOS) second, and the answer must be a
# number or it is treated as unknown. Measured on 24 September 2026 (Debian,
# GNU coreutils): on Linux `stat -f` means "describe the FILE SYSTEM", so
# `stat -f %m file` reads `%m` as a second file name, prints file-system
# details for the real file and fails. Trying the Linux form first avoids that
# entirely; the "must be a number" test is the belt for any other `stat`.
# Proved on macOS and on Linux: every mode, the deadline, and a bad mode (64).
# -----------------------------------------------------------------------------

set -u

MODE="${1:-}"
[ "$#" -gt 0 ] && shift
START=$(date +%s)

# True once CAP seconds have passed since this watchdog started.
cap_hit() { [ $(( $(date +%s) - START )) -ge "$1" ]; }

# Seconds-since-epoch of a file's last change, on Linux or macOS; empty if unknown.
mtime() {
    local t
    t=$(stat -c %Y "$1" 2>/dev/null) || t=$(stat -f %m "$1" 2>/dev/null) || t=""
    case "$t" in ''|*[!0-9]*) echo "" ;; *) echo "$t" ;; esac
}

case "$MODE" in
    quiet)
        F="${1:?file}"; IDLE="${2:-900}"; CAP="${3:-14400}"
        while true; do
            if cap_hit "$CAP"; then echo "WATCHDOG: hard deadline (${CAP}s) — look at $F"; exit 0; fi
            if [ -f "$F" ]; then
                M=$(mtime "$F")
                if [ -z "$M" ]; then echo "WATCHDOG: cannot read the age of $F on this system — look at it"; exit 0; fi
                AGE=$(( $(date +%s) - M ))
                if [ "$AGE" -ge "$IDLE" ]; then echo "WATCHDOG: $F unchanged for ${AGE}s — finished or stalled, look now"; exit 0; fi
            elif cap_hit 1200; then
                echo "WATCHDOG: $F never appeared after 20 minutes — whatever writes it may have died"; exit 0
            fi
            sleep 60
        done ;;
    exists)
        F="${1:?file}"; CAP="${2:-14400}"
        until [ -e "$F" ]; do
            if cap_hit "$CAP"; then echo "WATCHDOG: hard deadline — $F never appeared"; exit 0; fi
            sleep 30
        done
        echo "WATCHDOG: $F now exists"; exit 0 ;;
    contains)
        F="${1:?file}"; T="${2:?text}"; CAP="${3:-14400}"
        until [ -f "$F" ] && grep -a -q -F -- "$T" "$F"; do
            if cap_hit "$CAP"; then echo "WATCHDOG: hard deadline — '$T' never appeared in $F"; exit 0; fi
            sleep 30
        done
        echo "WATCHDOG: $F now contains '$T'"; exit 0 ;;
    pid)
        P="${1:?pid}"; CAP="${2:-14400}"
        while kill -0 "$P" 2>/dev/null; do
            if cap_hit "$CAP"; then echo "WATCHDOG: hard deadline — process $P still running"; exit 0; fi
            sleep 30
        done
        echo "WATCHDOG: process $P has ended"; exit 0 ;;
    *)
        echo "usage: tools/watchdog.sh quiet|exists|contains|pid …  (see the header of this file)" >&2
        exit 64 ;;
esac
