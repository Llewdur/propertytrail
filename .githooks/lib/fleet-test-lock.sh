#!/usr/bin/env bash
#
# Fair FIFO queue + max-hold-time cap for heavy local test runs across every
# repo on this shared dev machine (ZST-258). Loupe's composer-900s-timeout
# kills and Accounting's raw OOM kills turned out to be the same root cause —
# a dozen+ repos' Sail stacks running heavy suites at once — just
# manifesting as whichever resource ran out first for a given repo's run.
#
# Usage:
#   ./snippets/fleet-test-lock.sh -- <command> [args...]
#   e.g. ./snippets/fleet-test-lock.sh -- composer test
#        ./snippets/fleet-test-lock.sh -- ./vendor/bin/sail artisan test --coverage
#
# Design (owner-specified, ZST-258):
#   - A shared QUEUE DIRECTORY holds one ticket file per waiting invocation,
#     named by a monotonic counter (a shared sequence file, flock-protected
#     for the increment) so ticket order is exact FIFO — NOT "whatever order
#     the OS wakes flock waiters in", which is unspecified and not something
#     to build fairness on.
#   - Each invocation waits until its own ticket is the LOWEST-numbered
#     ticket still present, polling on a short interval — no separate
#     "who's actually running" lock is needed, since ticket numbers are
#     unique (protected by the sequence file's own flock), so only one
#     ticket can ever be the minimum at a time.
#   - Once at the front, it runs the command wrapped in `timeout
#     MAX_HOLD_SECONDS` so the QUEUE TICKET can never be held forever, even
#     by a genuinely wedged run. ⚠️ If the wrapped command routes through
#     Docker/Sail, this only kills the HOST-side client — the container-side
#     process can keep running and holding resources (kadro's KAD-1597 own
#     hard-won lesson). This queue is fairness on top of a repo's own
#     internal timeout, not a replacement for one — see the comment at the
#     actual `timeout` call below for the full explanation.
#   - The ticket is removed the moment this invocation is done — success,
#     failure, OR killed for holding too long — via a trap, so a killed run
#     never strands a ticket blocking the queue behind it. A retry starts a
#     FRESH, later-numbered ticket: it goes to the BACK of the queue rather
#     than immediately re-running, which is the fairness property this was
#     built for, explicitly.
#
# ⚠️ A DIFFERENT, Postgres-specific lock gap this script cannot see or fix
# (ZST-269, found by Kadro 2026-09-06, hit twice, ~an hour each time):
# a repo's own `bin/parallel-suite` wrapper (KAD-608) takes a Postgres
# ADVISORY lock before starting, to stop two parallel runs sharing
# `testing_test_1…N` schemas and destroying each other. That lock lives on
# a Postgres BACKEND, not the PHP process — killing the wrapper (pkill,
# Ctrl-C, OOM) kills PHP, but the Postgres backend holding the lock goes
# `idle` and keeps holding it. The next run then blocks waiting on a lock
# nobody owns, and it looks exactly like a hanging test suite: no test
# named, no output, symptom points at the suite, cause is the lock. This is
# NOT the same gap as the Docker/Sail one two paragraphs up (that one: a
# killed HOST process leaves a CONTAINER-side suite alive; this one: a
# killed process leaves a POSTGRES advisory lock alive) — a fix for one
# does not cover the other, and this queue's own ticket-reclaim logic above
# only ever sees its own host-level ticket files, never Postgres locks.
# This is a repo's OWN Postgres-aware wrapper's job to detect and recover
# from, not something this generic host-level queue can do on its own —
# kadro's `bin/parallel-suite` is the fleet's reference implementation,
# and its wait-banner already prints the recovery command:
#   ./vendor/bin/sail exec -T pgsql psql -U sail -d postgres -c \
#     "select pg_terminate_backend(a.pid) from pg_stat_activity a \
#      join pg_locks l on l.pid = a.pid \
#      where l.locktype = 'advisory' and a.state = 'idle'"
# A repo building or adopting its own `bin/parallel-suite` should: (1)
# print that recovery command in its own wait banner — a lock message with
# no escape hatch just gets waited out; (2) say "idle for Ns", never
# "still running" — idle-for-N is the abandoned-holder signal, and "still
# running" is technically true of the Postgres backend while being
# actively misleading about the actual test run; (3) treat auto-reaping an
# advisory holder idle past a threshold as a deliberate yes/no decision,
# not a silent default — a live run's backend is never idle for minutes,
# so the safety case is decent, but it's still a real trade.
#
# Env overrides:
#   ZEKINI_TEST_LOCK_DIR          shared queue directory (default
#                                 /tmp/zekini-fleet-test-queue). Deliberately
#                                 NOT per-repo — every repo/session on this
#                                 host must queue in the SAME place for this
#                                 to serialize anything at all.
#   ZEKINI_TEST_LOCK_MAX_HOLD     max seconds any one run may hold its turn
#                                 before being killed (default 900 — matches
#                                 composer's own historical default
#                                 process-timeout, a familiar ceiling).
#   ZEKINI_TEST_LOCK_WAIT_TIMEOUT max seconds to wait in queue before giving
#                                 up loudly rather than waiting forever
#                                 (default 1800 = 30 min).
#   ZEKINI_TEST_LOCK_POLL         poll interval while waiting, in seconds
#                                 (default 2).

set -uo pipefail

readonly QUEUE_DIR="${ZEKINI_TEST_LOCK_DIR:-/tmp/zekini-fleet-test-queue}"

# ZST-346: `kill -0 $pid` alone answers "does SOME process have this PID
# right now", not "is the holder that took this ticket still alive" — on a
# busy shared box, a holder can get OOM-killed and its PID reassigned to
# something completely unrelated before the next waiter polls. `kill -0`
# on the reused PID still succeeds, so the stale ticket never reclaims and
# every queued repo blocks for the full WAIT_TIMEOUT (1800s default) behind
# a ticket nobody actually holds — exactly the scenario this queue exists
# to protect against.
#
# The fix is a process fingerprint that PID reuse can't fake, recorded in
# the ticket file at creation time and compared against the CURRENT holder
# of that PID before trusting `kill -0`'s "still alive".
#
# ⚠️ Found in review, round 2: the first version of this fix used `ps -o
# lstart=` (wall-clock start time) as the fingerprint everywhere. That is
# WRONG on its own — the kernel stores a process's start time relative to
# boot (monotonic), and `ps` converts it to a wall-clock string using the
# CURRENT wall-clock-to-boot-time offset at query time. If the system
# clock steps between two queries of the SAME still-running process (an
# NTP correction, a DST transition, a VM/WSL resuming from suspend), `ps`
# can report a DIFFERENT lstart string for it — a false "PID reuse"
# verdict that would `rm -f` a perfectly valid ticket and let a second
# holder start while the first is still genuinely running, which is
# strictly worse than the bug this fix exists to close (unbounded WAITING
# vs. two holders actively colliding on the same shared resources).
#
# `/proc/<pid>/stat` field 22 (starttime) is the fix for THIS repo's actual
# fleet (Linux/WSL boxes, this file's own "shared dev machine" — every
# entry point above already assumes `/proc`-free portability only matters
# as a fallback, not the common case): it is ticks since BOOT, immune to
# wall-clock steps, and read with pure bash builtins (no `ps`/`tr`/`sed`
# fork per poll — found in the same review round: a fork-per-cycle
# fingerprint check for every waiter, every ~2s poll, on the very box this
# file says runs "a dozen+ repos' Sail stacks" at once was its own real
# cost). The process name field (`comm`, in parens) can itself contain
# spaces or `)`, so this splits from the LAST `) ` rather than assuming a
# fixed field count from the front.
#
# `ps -o lstart=` is kept ONLY as the no-`/proc` fallback (macOS) — a real,
# documented, accepted limitation there (the same clock-step risk above),
# not something a shell one-liner can avoid without a monotonic counter
# `ps` itself doesn't expose portably.
zekini_pid_fingerprint() {
  local pid="$1" stat_line rest
  if [ -r "/proc/${pid}/stat" ]; then
    IFS= read -r stat_line <"/proc/${pid}/stat" 2>/dev/null || return 0
    rest="${stat_line##*) }"
    # shellcheck disable=SC2086
    set -- $rest
    printf '%s\n' "${20:-}"
    return 0
  fi
  ps -o lstart= -p "$pid" 2>/dev/null | tr -s ' \t' ' ' | sed 's/^ *//; s/ *$//'
}

# Single source of truth for "is this ticket stale" (ZST-346, review round
# 2: the check used to be duplicated near-verbatim between --status and the
# reclaim loop below — a fix to the comparison had two places to apply and
# one to forget). Echoes `dead` (holder pid is gone), `reused` (pid is
# alive but is NOT the ticket's original holder) or nothing (still the
# original holder, not stale). Never fails — a staleness check must not
# itself block the queue.
zekini_ticket_stale_reason() {
  local pid="$1" ticket_file="$2" recorded_fp="" current_fp
  if ! kill -0 "$pid" 2>/dev/null; then
    printf 'dead\n'
    return 0
  fi
  IFS= read -r recorded_fp <"$ticket_file" 2>/dev/null
  current_fp="$(zekini_pid_fingerprint "$pid")"
  if [ -n "$recorded_fp" ] && [ -n "$current_fp" ] && [ "$recorded_fp" != "$current_fp" ]; then
    printf 'reused\n'
    return 0
  fi
  printf '\n'
}

# Single source of truth for "which ticket is at the front of the queue"
# (ZST-347: this scan used to be duplicated near-verbatim between --status
# and the reclaim/wait loop below — not a bug today, but a maintenance
# trap, since a future change to ticket shape or tie-break logic could
# land in only one copy and silently diverge --status's reporting from
# the lock's actual wait behavior). Front = lowest ticket NUMBER among
# files still present; ticket numbers are unique (guarded by the sequence
# flock at creation time), so exactly one file can ever be the minimum —
# no second lock is needed around this check. Sets the bare (not `local`)
# variables `front`, `front_file` and `zekini_ticket_count` for the caller
# — both callers already used `front`/`front_file` under this exact name
# before this extraction, and the count is free from the same pass rather
# than a second scan of the directory.
#
# ⚠️ A ticket number is VALIDATED as numeric before it is compared, and a file
# whose name is not `<digits>.<pid>` is skipped with a warning. The glob is
# `*.*`, so anything dropped in this directory matches it, and without the
# check one stray non-numeric file wedges the queue for EVERY repo on the
# machine: it is the first file scanned, `front` is still empty so it becomes
# the front, and every later `[ "$t" -lt "$front" ]` fails as a non-integer
# comparison — so no real ticket can ever take the front, and every waiter
# everywhere spins to ZEKINI_TEST_LOCK_WAIT_TIMEOUT and hard-fails. Reported
# by the Help session, 2026-10-20, found while scaffolding fleet-help.
#
# Each stray file is warned about ONCE per run, not on every scan: the waiter
# rescans every POLL seconds, so one stray file used to print ~900 identical
# warnings over a 30-minute wait, burying the gate output that bin/eod
# captures (ZST-969). It is still never removed — this scanner does not own it.
zekini_warned_strays=""
zekini_scan_queue() {
  local f b t
  front=""
  front_file=""
  zekini_ticket_count=0
  for f in "$QUEUE_DIR"/*.*; do
    [ -e "$f" ] || continue
    b="$(basename "$f")"
    t="${b%%.*}"
    case "$t" in
      ''|*[!0-9]*)
        case "$zekini_warned_strays" in
          *"/${b}/"*) ;;
          *)
            echo "▶ fleet-test-lock: ignoring ${f} — not a ticket (expected <number>.<pid>)." >&2
            zekini_warned_strays="${zekini_warned_strays}/${b}/"
            ;;
        esac
        continue
        ;;
    esac
    zekini_ticket_count=$((zekini_ticket_count + 1))
    if [ -z "$front" ] || [ "$t" -lt "$front" ]; then
      front="$t"
      front_file="$f"
    fi
  done
}

# --status: who holds the front ticket, since when, and who's waiting behind
# them. Read-only — takes no ticket of its own, so checking status never
# joins the queue. Same ticket-file shape the main loop below reads
# (<number>.<pid> in $QUEUE_DIR), so this never drifts from what the queue
# actually enforces.
if [ "${1:-}" = "--status" ]; then
  if [ ! -d "$QUEUE_DIR" ]; then
    echo "fleet-test-lock: queue empty (no queue directory at ${QUEUE_DIR})."
    exit 0
  fi
  zekini_scan_queue
  count="$zekini_ticket_count"
  if [ "$count" -eq 0 ]; then
    echo "fleet-test-lock: queue empty."
    exit 0
  fi
  front_base="$(basename "$front_file")"
  front_pid="${front_base#*.}"
  case "$front_pid" in '' | *[!0-9]*) front_pid="?" ;; esac
  held_state="running"
  if [ "$front_pid" != "?" ]; then
    case "$(zekini_ticket_stale_reason "$front_pid" "$front_file")" in
      dead) held_state="STALE — holder pid ${front_pid} is gone, will be reclaimed by the next waiter" ;;
      reused) held_state="STALE — pid ${front_pid} is alive but is NOT its original holder (PID reuse), will be reclaimed by the next waiter" ;;
    esac
  fi
  held_since="?"
  if command -v stat >/dev/null 2>&1; then
    held_since="$(stat -c '%y' "$front_file" 2>/dev/null || stat -f '%Sm' "$front_file" 2>/dev/null || echo '?')"
  fi
  echo "fleet-test-lock: ${count} ticket(s) in ${QUEUE_DIR}"
  echo "  front: #${front} (pid ${front_pid}), ${held_state}, ticket held since ${held_since}"
  if [ "$count" -gt 1 ]; then
    echo "  waiting behind it: $((count - 1))"
  fi
  exit 0
fi

if [ "${1:-}" != "--" ]; then
  echo "Usage: $0 -- <command> [args...]" >&2
  echo "       $0 --status" >&2
  exit 2
fi
shift

if [ "$#" -eq 0 ]; then
  echo "✗ fleet-test-lock: no command given after --" >&2
  exit 2
fi

if ! command -v flock >/dev/null 2>&1; then
  # A host without flock (rare on Linux; util-linux ships it everywhere this
  # fleet runs) has nothing to build a race-free ticket counter on. Run
  # unlocked rather than refuse to run tests at all — "cannot enforce
  # fairness" must not block a developer from running tests, only fail to
  # protect them from contention.
  echo "▶ fleet-test-lock: flock not available — running unlocked (nothing to queue with)." >&2
  exec "$@"
fi

readonly MAX_HOLD="${ZEKINI_TEST_LOCK_MAX_HOLD:-900}"
readonly WAIT_TIMEOUT="${ZEKINI_TEST_LOCK_WAIT_TIMEOUT:-1800}"
readonly POLL="${ZEKINI_TEST_LOCK_POLL:-2}"
# The longest hold this queue will issue a ticket for at all. The queue
# serialises ordinary suites competing for one machine; it is not a way to
# reserve the machine for hours.
#
# 2026-10-23: a WhatsApp load test raised ZEKINI_TEST_LOCK_MAX_HOLD to match
# its own --ceiling-seconds=18000 and sat at the front of the queue for over
# an hour. Pulse's unit stage timed out behind it as ticket 83 with a one-line
# change to push, and every other repo on the machine was equally stuck: the
# cap existed and was simply raised past the point where the queue means
# anything. A run that legitimately needs hours is not a suite taking its
# turn, so it does not take a turn — it runs outside the lock and competes
# for the machine like any other long job.
#
# The hour is not arbitrary, and the numbers are here so nobody "fixes" it
# later by reasoning about it. WhatsApp measured its own load run the same day
# (WHA-175): submission alone took 4h32m (05:37–10:10 UTC, two drivers, one of
# which declared an 18,000s ceiling and used all of it), then about five hours
# more of report delivery — roughly nine hours wall clock. A real load run is
# 4 to 9 HOURS. There is no ceiling that admits one of those to a shared queue
# and still leaves the queue meaning anything, so the right number is one no
# load run can reach and no ordinary suite needs. WhatsApp moved
# bin/volume-proof outside the lock (its PR 110, 0.27.0) rather than raise it.
#
# So the advice is one rule, said the same way here, in the refusal and in the
# kill message: a suite that needs more time raises ZEKINI_TEST_LOCK_MAX_HOLD,
# up to this ceiling; a run that needs more than the ceiling runs outside the
# lock. The ceiling itself is never raised, which is why it is a constant and
# not a knob: ZST-975 shipped a ZEKINI_TEST_LOCK_MAX_HOLD_CEILING override that
# the refusal recommended, the exact "raise the cap past the point where the
# queue means anything" this ceiling exists to stop (ZST-971).
readonly MAX_HOLD_CEILING=3600
case "$MAX_HOLD" in
  '' | *[!0-9]*) ;;   # not a number: the `timeout` call below reports it
  *)
    if [ "$MAX_HOLD" -gt "$MAX_HOLD_CEILING" ]; then
      echo "✗ fleet-test-lock: refusing a ticket for a ${MAX_HOLD}s hold (ceiling ${MAX_HOLD_CEILING}s)." >&2
      echo "  This queue serialises test suites on a shared machine. A run that needs longer than" >&2
      echo "  the ceiling would starve every other repo for its whole duration — which is what a" >&2
      echo "  5-hour load test did on 2026-10-23, blocking a one-line Pulse push behind it." >&2
      echo "  Run it OUTSIDE the lock (call the command directly, without this wrapper) and accept" >&2
      echo "  that it competes for the machine; or, if it really is a suite, lower" >&2
      echo "  ZEKINI_TEST_LOCK_MAX_HOLD to the ${MAX_HOLD_CEILING}s ceiling or below. The ceiling itself is" >&2
      echo "  never raised: no ceiling that admits a load run leaves the queue meaning anything." >&2
      exit 1
    fi
    ;;
esac
readonly SEQ_FILE="${QUEUE_DIR}/.sequence"
readonly SEQ_LOCK="${QUEUE_DIR}/.sequence.lock"

mkdir -p "$QUEUE_DIR" || { echo "✗ fleet-test-lock: could not create queue dir ${QUEUE_DIR}." >&2; exit 1; }

# Atomically grab the next ticket number: flock the sequence file for the
# read-increment-write, so two simultaneous invocations can never end up
# with the same number — the exact race this design exists to avoid.
exec 8>"$SEQ_LOCK" || { echo "✗ fleet-test-lock: could not open ${SEQ_LOCK}." >&2; exit 1; }
flock 8
current="$(cat "$SEQ_FILE" 2>/dev/null || echo 0)"
case "$current" in '' | *[!0-9]*) current=0 ;; esac
ticket=$((current + 1))
echo "$ticket" >"$SEQ_FILE"
flock -u 8

ticket_file="${QUEUE_DIR}/${ticket}.$$"
# ZST-346: the ticket file's CONTENT is this holder's process fingerprint
# (zekini_pid_fingerprint, above) — a stale reclaim needs it to tell "this
# PID is gone" from "this PID now belongs to something else" once PID reuse
# is in play. Best-effort: empty content (ps -o lstart= unsupported here)
# just means reclaim falls back to plain liveness, same as before this fix.
zekini_pid_fingerprint "$$" >"$ticket_file"
# Removed on ANY exit path (normal, failed, or killed by the max-hold
# timeout below) — a ticket left behind after a kill would wedge the queue
# for every ticket behind it, forever, which is worse than the contention
# this script exists to fix. The filename encodes the holder's PID ($$)
# after the ticket number, so a DIFFERENT invocation can tell whose ticket
# it is — needed below for the case this trap can't cover.
trap 'rm -f "$ticket_file"' EXIT

echo "▶ fleet-test-lock: ticket #${ticket} — waiting for your turn (queue: ${QUEUE_DIR})…"

waited=0
while :; do
  # ⚠️ Found by kadro's first adoption (ZST-333), reproduced by accident, not
  # constructed: something outside this script (an external /tmp sweep, a
  # session resetting the queue by hand — never THIS script, nothing in it
  # removes the directory) can delete QUEUE_DIR out from under a waiter,
  # ticket file and all. The glob two lines down then matches nothing,
  # `front` never gets set, and `[ "$front" = "$ticket" ]` can never be
  # true — a waiter whose OWN ticket is gone was reading its position off a
  # glob that answers identically for "nobody ahead of me" and "the queue
  # does not exist any more", and it spun silently to the full
  # WAIT_TIMEOUT, reporting ordinary contention for a queue that had
  # stopped existing. The stale-holder reclaim below doesn't cover it
  # either: that path needs a FRONT ticket to inspect, and there is none.
  # Checking the waiter's own ticket file first closes exactly that gap —
  # if it is gone, this waiter cannot possibly still be in the queue.
  if [ ! -e "$ticket_file" ]; then
    echo "✗ fleet-test-lock: ticket #${ticket}'s own ticket file vanished from ${QUEUE_DIR} — the queue directory was removed or reset out from under this wait (not by this script)." >&2
    echo "  This is not contention; re-run to enqueue fresh." >&2
    exit 1
  fi

  # Front of the queue = lowest ticket NUMBER among files still present —
  # zekini_scan_queue, above (ZST-347: was its own copy of this scan here).
  zekini_scan_queue
  [ "$front" = "$ticket" ] && break

  # ⚠️ Caught in review: the EXIT trap above cannot run on SIGKILL — exactly
  # what an OOM killer sends, and OOM kills are this script's own motivating
  # case. A killed holder would otherwise leave its ticket in place forever,
  # wedging the queue for every invocation behind it. Since the ticket
  # filename encodes the holder's PID, a waiting invocation can tell the
  # difference between "still running" and "process is gone" and reclaim
  # the stale ticket itself rather than waiting out the full WAIT_TIMEOUT
  # for a holder that will never finish.
  if [ -n "$front_file" ]; then
    front_base="$(basename "$front_file")"
    front_pid="${front_base#*.}"
    case "$front_pid" in '' | *[!0-9]*) front_pid="" ;; esac
    if [ -n "$front_pid" ]; then
      case "$(zekini_ticket_stale_reason "$front_pid" "$front_file")" in
        dead)
          rm -f "$front_file"
          echo "⚠ fleet-test-lock: reclaimed ticket #${front} — its holder (pid ${front_pid}) is gone (killed, not exited cleanly)." >&2
          continue
          ;;
        reused)
          rm -f "$front_file"
          echo "⚠ fleet-test-lock: reclaimed ticket #${front} — pid ${front_pid} is alive but is NOT its original holder (PID reuse)." >&2
          continue
          ;;
      esac
    fi
  fi

  if [ "$waited" -ge "$WAIT_TIMEOUT" ]; then
    echo "✗ fleet-test-lock: ticket #${ticket} timed out after ${WAIT_TIMEOUT}s waiting in queue" >&2
    echo "  (currently at front: #${front:-?}). Run \`$0 --status\` to see who holds it and since when." >&2
    echo "  Waiting longer (ZEKINI_TEST_LOCK_WAIT_TIMEOUT=<seconds>) only helps if the holder is a" >&2
    echo "  suite that is about to finish. If it is a long job, that is the bug — run YOUR suite" >&2
    echo "  outside the lock on the exact commit, confirm it green, and push with --no-verify." >&2
    exit 1
  fi
  sleep "$POLL"
  waited=$((waited + POLL))
done

echo "▶ fleet-test-lock: ticket #${ticket} — your turn, running (max ${MAX_HOLD}s): $*"

# ⚠️ KNOWN, NARROWED GAP (caught in review — kadro, KAD-1597's own hard-won
# lesson): if "$@" routes through Docker/Sail (`./vendor/bin/sail composer
# test`, say), `timeout` here only kills the HOST-side client process —
# the actual suite keeps running inside the container, still holding
# whatever lock/resources it had, exactly the failure mode KAD-1597 already
# documented for this same class of command. This script's max-hold
# guarantees the QUEUE TICKET is released so every repo waiting behind it
# isn't blocked forever — it does NOT guarantee the underlying container
# process is reclaimed. A repo whose wrapped command is Docker-based needs
# its OWN internal wall-clock ceiling that terminates the process tree from
# INSIDE the container (KAD-1597's `Tests\Support\SuiteRunner` is the
# fleet's reference implementation; pre-push's own browser-group timeout
# wraps `timeout` INSIDE `sail_run` for the identical reason — see that
# comment for the full explanation) — this queue is fairness on top of
# that, not a replacement for it.
if command -v timeout >/dev/null 2>&1; then
  timeout --signal=TERM --kill-after=10 "$MAX_HOLD" "$@"
  status=$?
  if [ "$status" -eq 124 ]; then
    echo "✗ fleet-test-lock: killed after ${MAX_HOLD}s (ZEKINI_TEST_LOCK_MAX_HOLD) — this run held its" >&2
    echo "  turn too long and was stopped so it doesn't starve every other repo waiting behind it." >&2
    echo "  A retry starts a NEW ticket at the back of the queue, on purpose. If this repo's" >&2
    echo "  suite genuinely needs more time, raise ZEKINI_TEST_LOCK_MAX_HOLD — but no higher than" >&2
    echo "  the ${MAX_HOLD_CEILING}s ceiling, which is never raised: a longer hold is refused before it" >&2
    echo "  gets a ticket. A run that needs longer than that is not a suite; run it outside the lock." >&2
    echo "  If this command runs inside Docker/Sail, this kill only stopped the HOST-side" >&2
    echo "  client — check for an orphaned container process still holding resources" >&2
    echo "  (docker stats), and consider an internal timeout for the wrapped command" >&2
    echo "  itself, not just this queue's." >&2
  fi
else
  # No `timeout` binary: still runs, just without the max-hold cap — same
  # "don't block a developer from testing at all" principle as the missing-
  # flock case above.
  "$@"
  status=$?
fi

exit "$status"
