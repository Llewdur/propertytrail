#!/usr/bin/env bash
#
# Canonical Zekini hook library (from zekini-com/standards): the staged
# backend-suite plan templates/hooks/pre-push runs (ZST-295, STAGE epic /
# ZST-270). Pure — reads nothing but its arguments, so it is fixture-testable
# without git, Sail, or a real push (snippets/test-stages.test.sh), the same
# reason lib/sail-tree.sh and lib/frontend-skip.sh are their own files.
#
# ## What "staged" means here (docs/pre-push-performance.md, "staged gate")
#
# The backend suite used to be ONE call: every testsuite, flat pass/fail, the
# whole thing running to the end even when the first unit test had already
# failed. The owner's amended decision (2026-09-06, via Kadro) is "both":
# stage the gate by test TYPE — unit → feature → browser — each stage gating
# the next, AND stop on the first failure WITHIN each stage. This file
# decides the backend part of that: which `php artisan test` invocations run,
# in what order, with which `--testsuite=` / `--exclude-group=` arguments.
# The browser stage is the hook's existing dedicated, timed step (it needs
# the built SPA, so it has to stay after the frontend build) — nothing here
# emits it; this only makes sure the backend stages never double-run or
# silently drop it.
#
# ## Rules (every one of them is a fixture case)
#
# 1. `ZEKINI_TEST_STAGES` (.githooks/config, committed repo policy) is a
#    space-separated ordered list. Unset → `unit feature browser`. Unset must
#    never quietly remove a gate, so the default is the full shape.
# 2. A repo whose phpunit.xml has BOTH a `Unit` and a `Feature` testsuite,
#    and lists both stages, gets two backend stages. Anything else — no
#    split at all (sms: one `Tests` suite), a list naming only one of them,
#    a phpunit.xml this file cannot parse with confidence (see
#    zekini_testsuite_names), or a suite name containing whitespace (which
#    cannot survive the hook's word-splitting into `--testsuite=`) —
#    collapses to ONE stage that runs everything the two would have, exactly
#    today's single call. Degrades to "run it all", never to "run less".
# 3. Testsuites that are neither `Unit` nor `Browser` belong to the feature
#    stage — a custom `Arch`/`Contract` suite is never dropped for having a
#    name this file has not heard of. Suite names are compared
#    case-insensitively (a `browser` suite is the Browser suite) and emitted
#    with their original spelling.
# 4. The `Browser` testsuite, and any spec in the `browser` group wherever it
#    lives (kadro keeps them under tests/Feature/Browser), is EXCLUDED from
#    BOTH backend stages only when the dedicated browser step is actually
#    going to run — the same two-detector gate the hook already uses
#    (zekini_has_browser_tests && zekini_browser_in_prepush). The unit stage
#    carries the exclusion too (caught in review): a group-tagged spec under
#    tests/Unit would otherwise run twice, once against an unbuilt SPA. When
#    that step is off (ZEKINI_BROWSER_IN_PREPUSH=false), the feature stage
#    carries the browser group unfiltered, which is what the flat call did
#    (ZST-242's documented current behaviour) — ZST-294's open question,
#    settled here on the side of never losing coverage. "double-run" is the
#    cheap failure; "never runs" is the one that reaches production blank.
# 5. `playwright` in the list is accepted and folded into `browser`: every
#    browser spec in this fleet is Pest-driven Playwright, there is no
#    separate Playwright-only suite to stage. Said once on stderr, not
#    silently swallowed. An unknown stage name is also said on stderr and
#    ignored — a typo must not remove a gate (rule 1), but it must not pass
#    unnoticed either. And a list that LEAVES OUT `browser` is told, on
#    stderr, that this list does not control the browser step — that is
#    ZEKINI_BROWSER_IN_PREPUSH's job — so omitting it is not a silent no-op.
# 6. PARALLEL-UNSAFE GROUPS (ZST-414, found in review of ACC-338: a test that
#    passes alone and races beside itself under `--parallel`). The optional
#    fifth argument is the repo's `ZEKINI_PARALLEL_TEST_EXCLUDE_GROUPS`, and the
#    CALLER passes it only when the run really is parallel — this file stays
#    pure and knows nothing else about parallelism. Every backend stage then
#    carries the groups in its ONE `--exclude-group=` (the browser group first,
#    comma-joined: repeating the flag is not portable, PHPUnit's CLI keeps only
#    the last), and a final `serial` stage runs exactly those groups with
#    `--group=`. Exclusion without that stage would be the "never runs" failure
#    rule 4 warns about; the serial stage is what makes it "runs elsewhere".
#    Names are validated (letters, digits, `_ . : -`; a comma or whitespace
#    separates). A bad name is said on stderr and IGNORED, so the group stays
#    in the parallel run — the loud, never-runs-less side of a typo. Empty →
#    no change to any line above, byte for byte.
# 7. A REPO'S OWN TEST SELECTOR (ZST-891, for KAD-2320). `ZEKINI_TEST_SELECTOR`
#    (.githooks/config, e.g. `bin/test-plan`) names an executable that is handed
#    the pushed range's changed paths on stdin, one per line, and answers on
#    stdout with the test GROUPS to run (whitespace-, comma- or
#    newline-separated). zekini_test_selection runs it; the optional sixth
#    argument of zekini_stage_plan takes its answer. A selected run is ONE
#    `selected|--group=…` stage (a Unit stage filtered to groups that live only
#    under tests/Feature would select nothing), keeping rule 4's and rule 6's
#    exclusions and rule 6's serial stage — minus any chosen group those same
#    exclusions name, which would make the stage select nothing (ZST-969); a
#    chosen parallel-unsafe group runs in the serial stage. Everything that is not a clean answer
#    runs the FULL suite, loudly: no selector named → silently, byte for byte
#    today's plan; a reason the hook already knows (the protected branch, a tag
#    in the push, ZEKINI_FULL_GATE, no fetch or no merge-base); no changed path;
#    a selector that is missing, unreadable or not executable; a non-zero exit
#    (or a hang, cut at ZEKINI_TEST_SELECTOR_TIMEOUT seconds, default 60); an
#    empty answer; ANY invalid group name in the answer (the whole answer is
#    refused — a selector that emits garbage is not trusted on its other lines);
#    a named group that no test under tests/ carries (zekini_groups_without_tests,
#    rule 6's own check). A gate that cannot see must not guess its way to
#    skipping (ZST-217). This function runs the repo's script, so it is the one
#    thing here that is not pure; it still needs no git and no Sail.
#
# ## Using it
#
#   . "$(dirname "${BASH_SOURCE[0]}")/lib/test-stages.sh"
#   plan="$(zekini_stage_plan "${ZEKINI_TEST_STAGES:-}" "$(zekini_testsuite_names)" "$has_browser" "$browser_dedicated" "$serial_groups")"
#   mapfile -t lines <<< "$plan"   # never `while read` around sail_run — it eats stdin
#   mapfile -t cmd < <(zekini_stage_command "$label" "$args" "$test_args" "$has_parallel_suite" "$stop_flag")
#   zekini_groups_without_tests "$serial_groups" tests   # names to warn about (rule 6)
#   selected="$(zekini_test_selection "$ZEKINI_TEST_SELECTOR" "$changed_paths" "$full_reason" tests)"   # rule 7
#
# Output: one line per BACKEND stage, `label|args`, in run order. `args` is
# the extra argument string for `php artisan test` (already word-splittable;
# may be empty). Only ever `backend` alone, `unit` then `feature`, or
# `selected` alone (rule 7), plus a last `serial` line when rule 6 applies.
# That one must run without `--parallel` and without bin/parallel-suite
# (which always adds it): zekini_stage_command
# is what enforces it, so the hook has no branch of its own to get wrong. Never
# empty — the hook treats an empty plan as a broken lib, not as "no tests".

# Capability marker (ZST-414): the hook checks it before handing over rule 6's
# fifth argument, so a copy of this file synced out of order — one that would
# silently ignore that argument — fails the push instead of racing the groups it
# was told to keep out of the parallel run.
ZEKINI_STAGE_PLAN_SERIAL_GROUPS=1

# Capability marker (ZST-891): rule 7. A hook whose repo names a selector checks
# it, so a lib synced out of order runs the full suite and says why, rather than
# dying on a missing function.
ZEKINI_STAGE_PLAN_SELECTOR=1

# The testsuite names phpunit.xml declares, one per line, in declaration
# order. Asks the file, not the directory tree (KAD-1376's lesson, applied to
# suites instead of specs). `name=` may sit anywhere in the tag and use
# either quote style (caught in review: an earlier version matched only
# `<testsuite name="…"` as the first attribute, so `<testsuite id="a"
# name="Contract">` would have silently vanished from `--testsuite=`).
# If the number of names parsed does not equal the number of `<testsuite`
# tags, prints NOTHING — the caller then collapses to the flat call rather
# than run a subset it cannot vouch for.
#
# 🔥 STOP-SHIP (ZST-351, found by Accounting's review panel): `grep -o`
# matches within ONE LINE at a time, so a tag whose `name=` attribute wraps
# to a second line —
#   <testsuite
#       name="Browser">
# — with no whitespace on the `<testsuite` line itself, matches NOTHING at
# all: `grep` needs `[[:space:]]` immediately after `<testsuite` on that
# SAME line to even start a match, and there is none before the newline.
# The tag contributes ZERO to `$tags`, so it also contributes zero to
# `$tag_count` — the exact same undercount `$name_count` gets from the
# missing name — so the two counts stay EQUAL and the safety net two lines
# down never trips. The wrapped suite doesn't error, doesn't degrade to the
# flat call, it just silently vanishes from `--testsuite=` forever.
#
# Fixed by flattening every newline to a space BEFORE the `grep -o` pass —
# XML doesn't care whether the whitespace between `<testsuite` and `name=`
# is a literal space or a line wrap, and `[[:space:]]`/`[^>]*` already
# tolerate either. `grep -o` still emits one match per line-of-OUTPUT even
# when the whole file is now one line of INPUT, so every downstream line
# (`tag_count`, `names`, `name_count`) is unaffected for the common,
# single-line case — this only changes behavior for the wrapped case it
# fixes.
#
# ⚠️ Found in review: this scanner has NEVER been comment-aware — it is a
# text match, not an XML parser, so a `<testsuite name="Old">` sitting
# inside an `<!-- ... -->` block was already read as live before this fix,
# same as any other text tool that doesn't parse markup. Flattening widens
# that pre-existing gap to also catch a WRAPPED tag inside a comment (one
# whose own `name=` wraps a line, the exact shape this fix targets) — a
# doubly-contrived shape no real phpunit.xml in this fleet has ever used.
# A correct XML-comment stripper was deliberately not added here: XML
# comments can't nest and can span an arbitrary number of separate blocks,
# and a naive greedy `<!--.*-->` removal on the flattened text would eat
# everything BETWEEN two unrelated comments, including real `<testsuite>`
# tags between them — a worse, silent under-count than the one this
# function already guards against. If a repo's phpunit.xml ever needs to
# retire a suite, deleting the block (or commenting out only the
# `<directory>` line, not the whole `<testsuite name="…">` tag) avoids
# this entirely; a genuinely broken phpunit.xml is also always going to
# degrade to the flat call the moment `tag_count` and `name_count`
# actually disagree, which covers every OTHER shape this parser can't
# vouch for.
zekini_testsuite_names() {
  local file="${1:-phpunit.xml}" flat tags names tag_count name_count
  [ -f "$file" ] || return 0
  # An unreadable-but-existing file (permissions, ACL) must degrade exactly
  # like a missing one — `<"$file"` failing to open is a shell-level
  # redirection error printed BEFORE `tr` itself runs, so no `2>/dev/null`
  # placed on that command line can catch it (found in review, verified
  # live: `chmod 000` still leaked "Permission denied" to stderr with one).
  # Checking `-r` up front avoids the redirection entirely.
  [ -r "$file" ] || return 0

  flat="$(tr '\n' ' ' <"$file" 2>/dev/null)"
  tags="$(printf '%s' "$flat" | grep -o '<testsuite[[:space:]][^>]*' 2>/dev/null || true)"
  [ -n "$tags" ] || return 0

  names="$(printf '%s\n' "$tags" | sed -n "s/.*[[:space:]]name=[\"']\([^\"']*\)[\"'].*/\1/p")"
  tag_count="$(printf '%s\n' "$tags" | grep -c .)"
  name_count="$(printf '%s\n' "$names" | grep -c .)"

  [ "$tag_count" -eq "$name_count" ] || return 0
  printf '%s\n' "$names"
}

# The comma-joined groups of the first list that are not in the second, in the
# first list's order. Prints nothing when none are left.
#   zekini_groups_minus GROUPS EXCLUDED
zekini_groups_minus() {
  local groups="${1:-}" excluded=",${2:-}," group out=""
  local -a names=()
  IFS=',' read -r -a names <<< "$groups"
  for group in "${names[@]}"; do
    [ -n "$group" ] || continue
    case "$excluded" in
      *",${group},"*) ;;
      *) out="${out:+$out,}${group}" ;;
    esac
  done
  [ -z "$out" ] || printf '%s\n' "$out"
}

# The trailing `serial` stage of rule 6: the parallel-unsafe groups, run on
# their own. Prints nothing when there are none. The browser group stays out of
# it while the dedicated step owns that group — a parallel-unsafe group whose
# specs are ALSO tagged `browser` must not run in the backend gate against an
# unbuilt SPA.
zekini_serial_stage_line() {
  local groups="${1:-}" browser_excluded="${2:-0}" extra=""
  [ -n "$groups" ] || return 0
  if [ "$browser_excluded" = 1 ]; then
    extra=" --exclude-group=browser"
  fi
  printf 'serial|--group=%s%s\n' "$groups" "$extra"
}

# The words of ONE backend-suite command (ZST-414, found in review): which runner
# and which arguments a stage gets. A pure function so the fixture pins what the
# hook would otherwise decide inline, where nothing can run it. The `serial` stage
# never carries the parallel arguments, and with none it never takes the
# bin/parallel-suite route either (that wrapper always adds --parallel itself, and
# is chosen only when TEST_ARGS is non-empty). Every other stage is exactly the
# command the hook built before rule 6 existed.
#   zekini_stage_command LABEL STAGE_ARGS TEST_ARGS HAS_PARALLEL_SUITE(1|0) STOP_FLAG
# One word per line, so the caller can `mapfile` it. No word holds whitespace.
zekini_stage_command() {
  local label="${1:-}" stage_args="${2:-}" test_args="${3:-}" has_parallel_suite="${4:-0}" stop_flag="${5:-}"
  local -a words=()

  if [ "$label" = serial ]; then
    test_args=""
  fi
  if [ -n "$test_args" ] && [ "$has_parallel_suite" = 1 ]; then
    words=(bin/parallel-suite)
  else
    words=(php artisan test)
  fi
  # Unquoted on purpose: each holds several flags that must arrive as separate words.
  # shellcheck disable=SC2206
  words+=($test_args $stage_args $stop_flag)
  printf '%s\n' "${words[@]}"
}

# The accepted names in ZEKINI_PARALLEL_TEST_EXCLUDE_GROUPS, one per line, in
# order, deduplicated (rule 6). ONE place decides what a valid name is, so the plan
# and the "does any test carry it" check below cannot drift apart (found in
# review: each had its own copy of the tokenising and the validation). Newlines,
# carriage returns and commas all separate. A name that is refused is said on
# stderr unless QUIET is `quiet`; `read -a`, not an unquoted `for`, so a glob
# character cannot expand against the working directory.
#   zekini_serial_group_names RAW [quiet]
zekini_serial_group_names() {
  local raw="${1:-}" quiet="${2:-}" token seen=","
  local -a tokens=()
  raw="${raw//[$'\n\r,']/ }"
  read -r -a tokens <<< "$raw"
  for token in "${tokens[@]}"; do
    case "$token" in
      browser)
        if [ "$quiet" != quiet ]; then
          echo "⚠ ZEKINI_PARALLEL_TEST_EXCLUDE_GROUPS names 'browser' — ignored: the browser group has its own step (and ZEKINI_BROWSER_IN_PREPUSH), running it serially here would double-run it." >&2
        fi
        ;;
      *[!A-Za-z0-9_.:-]*)
        if [ "$quiet" != quiet ]; then
          echo "⚠ ZEKINI_PARALLEL_TEST_EXCLUDE_GROUPS names '${token}', which is not a valid group name (letters, digits and _ . : - only) — ignored, so that group stays in the parallel run." >&2
        fi
        ;;
      *)
        case "$seen" in
          *",${token},"*) ;;
          *)
            seen="${seen}${token},"
            printf '%s\n' "$token"
            ;;
        esac
        ;;
    esac
  done
}

# Group names in ZEKINI_PARALLEL_TEST_EXCLUDE_GROUPS that no test under DIR is
# tagged with (ZST-414, found in review). A misspelling ("maintenance_mode" for
# "maintenance-mode") is a VALID name, so rule 6 accepts it, excludes nothing from
# the parallel run, and then runs a serial stage that selects no tests: a config
# that looks right and does nothing, the exact shape this fleet keeps having to
# guard against. Prints each such name, one per line, for the caller to warn
# about.
#
# What counts as "tagged" is deliberately narrow, because a loose match hides the
# very misspelling it exists to catch (found in review: any quoted string
# elsewhere in tests/, or `@group maintenance-mode-extra`, made a wrong name look
# carried). The name must sit inside the argument list of a group declaration on
# one line — Pest `->group('a', 'b')`, `#[Group('x')]`, `Attributes\Group("x")` —
# or follow a docblock `@group` as a whole word. A declaration whose arguments wrap
# over several lines is missed, which only ever costs a false warning, never a
# hidden typo. Where DIR does not exist it says nothing rather than guess.
zekini_groups_without_tests() {
  local raw="${1:-}" dir="${2:-tests}" name escaped
  [ -d "$dir" ] || return 0
  while IFS= read -r name; do
    [ -n "$name" ] || continue
    escaped="${name//./\\.}" # the only regex metacharacter a valid name can hold
    if ! grep -rIqE -e "(->group|Group)\([^)]*['\"]${escaped}['\"]" -e "@group[[:space:]]+${escaped}([^A-Za-z0-9_.:-]|\$)" -- "$dir" 2>/dev/null; then
      printf '%s\n' "$name"
    fi
  done < <(zekini_serial_group_names "$raw" quiet)
}

# The groups a selector answered with (rule 7), comma-joined, deduplicated, in
# the order given. Prints NOTHING, and says why on stderr, when the answer is
# empty or holds any name that is not a valid group name: one bad line and the
# whole answer is refused. `browser` is dropped with a note — that group is the
# dedicated browser step's (or, with the step off, rides along with every
# selected run: zekini_stage_plan adds it) — so an answer of only `browser` is
# an empty one.
#   zekini_selector_groups ANSWER LABEL
zekini_selector_groups() {
  local raw="${1:-}" label="${2:-the test selector}" token seen="," out=""
  local -a tokens=()
  raw="${raw//[$'\n\r,']/ }"
  read -r -a tokens <<< "$raw"
  for token in "${tokens[@]}"; do
    case "$token" in
      browser)
        echo "ℹ pre-push: ${label} named 'browser' — dropped: the browser group has its own step (ZEKINI_BROWSER_IN_PREPUSH), the selection covers the backend suite." >&2
        ;;
      *[!A-Za-z0-9_.:-]*)
        echo "⚠ pre-push: ${label} answered '${token}', which is not a valid group name (letters, digits and _ . : - only) — its whole answer is refused; running the full backend suite." >&2
        return 0
        ;;
      *)
        case "$seen" in
          *",${token},"*) ;;
          *)
            seen="${seen}${token},"
            out="${out:+$out,}${token}"
            ;;
        esac
        ;;
    esac
  done
  if [ -z "$out" ]; then
    echo "⚠ pre-push: ${label} answered with no groups — running the full backend suite." >&2
    return 0
  fi
  printf '%s\n' "$out"
}

# Rule 7: ask the repo's selector which test groups the changed paths need.
# Prints the comma-joined groups for zekini_stage_plan's sixth argument, or
# NOTHING, which means the full suite. Every outcome but "no selector named"
# says one line on stderr: the selector, the groups chosen and the paths that
# chose them — or why it ran everything instead.
#   zekini_test_selection SELECTOR CHANGED_PATHS FULL_REASON [TESTS_DIR]
#   SELECTOR       ZEKINI_TEST_SELECTOR, a path relative to the repo root
#   CHANGED_PATHS  the union across the pushed range, one per line
#   FULL_REASON    non-empty when the hook already knows it must run everything
#   TESTS_DIR      where the chosen groups must be carried (default tests)
zekini_test_selection() {
  local selector="${1:-}" paths="${2:-}" full_reason="${3:-}" tests_dir="${4:-tests}"
  local full="running the full backend suite" run answer status groups missing
  local timeout_s="${ZEKINI_TEST_SELECTOR_TIMEOUT:-60}" count shown
  local -a runner=()

  [ -n "$selector" ] || return 0

  if [ -n "$full_reason" ]; then
    echo "ℹ pre-push: test selector ${selector} not consulted — ${full_reason}; ${full}." >&2
    return 0
  fi
  if [ -z "$paths" ]; then
    echo "⚠ pre-push: test selector ${selector} not consulted — no changed path to hand it; ${full}." >&2
    return 0
  fi

  case "$selector" in
    /* | ./* | ../*) run="$selector" ;;
    *) run="./$selector" ;;
  esac
  if [ ! -f "$run" ] || [ ! -r "$run" ] || [ ! -x "$run" ]; then
    echo "⚠ pre-push: test selector ${selector} is missing, unreadable or not executable — ${full}." >&2
    return 0
  fi

  case "$timeout_s" in
    '' | 0 | *[!0-9]*) timeout_s=60 ;;
  esac
  if command -v timeout >/dev/null 2>&1; then
    runner=(timeout --kill-after=5 "$timeout_s")
  fi
  # A here-string, never `printf | selector`: a selector that exits without
  # reading stdin would SIGPIPE printf, and pipefail would report that as the
  # selector failing (lib/frontend-skip.sh has the history).
  answer="$("${runner[@]}" "$run" <<< "$paths")"
  status=$?
  if [ "$status" -eq 124 ] && [ "${#runner[@]}" -gt 0 ]; then
    echo "⚠ pre-push: test selector ${selector} did not answer within ${timeout_s}s (ZEKINI_TEST_SELECTOR_TIMEOUT) — ${full}." >&2
    return 0
  fi
  if [ "$status" -ne 0 ]; then
    echo "⚠ pre-push: test selector ${selector} exited ${status} — ${full}." >&2
    return 0
  fi

  groups="$(zekini_selector_groups "$answer" "test selector ${selector}")"
  [ -n "$groups" ] || return 0

  if [ ! -d "$tests_dir" ]; then
    echo "⚠ pre-push: test selector ${selector} chose ${groups}, but there is no ${tests_dir}/ to check them against — ${full}." >&2
    return 0
  fi
  missing="$(zekini_groups_without_tests "$groups" "$tests_dir" | paste -sd, -)"
  if [ -n "$missing" ]; then
    echo "⚠ pre-push: test selector ${selector} chose ${missing}, but nothing under ${tests_dir}/ tags a test with it — a group that selects nothing is not a plan; ${full}." >&2
    return 0
  fi

  count="$(printf '%s\n' "$paths" | grep -c .)"
  shown="$(printf '%s\n' "$paths" | grep . | head -n 10 | paste -sd, - | sed 's/,/, /g')"
  if [ "$count" -gt 10 ]; then
    shown="${shown}, … (+$((count - 10)) more)"
  fi
  echo "▶ pre-push: test selector ${selector} chose group(s) ${groups} from ${count} changed path(s): ${shown}" >&2
  printf '%s\n' "$groups"
}

# zekini_stage_plan STAGES SUITES HAS_BROWSER BROWSER_DEDICATED [SERIAL_GROUPS] [SELECTED_GROUPS]
#   STAGES            the configured list ("" → default)
#   SUITES            testsuite names, one per line (zekini_testsuite_names)
#   HAS_BROWSER       1 when the repo has specs in the `browser` group
#   BROWSER_DEDICATED 1 when the hook's dedicated browser step WILL run
#   SERIAL_GROUPS     parallel-unsafe groups (rule 6); "" when the run is serial
#   SELECTED_GROUPS   zekini_test_selection's answer (rule 7); "" → the full suite
zekini_stage_plan() {
  local configured="${1:-}" suites="${2:-}" has_browser="${3:-0}" browser_dedicated="${4:-0}" serial_raw="${5:-}" selected="${6:-}"
  local stage lower want_unit=0 want_feature=0 want_browser=0 has_unit=0 has_feature=0
  local suite feature_suites="" browser_excluded=0 exclude_groups="" exclude_flag="" flat=0
  local serial_groups="" serial_name serial_only=0 selected_serial=""
  local -a suite_lines=() serial_names=()

  [ -n "$configured" ] || configured="unit feature browser"

  for stage in $configured; do
    lower="$(printf '%s' "$stage" | tr '[:upper:]' '[:lower:]')"
    case "$lower" in
      unit) want_unit=1 ;;
      feature) want_feature=1 ;;
      browser) want_browser=1 ;;
      playwright)
        want_browser=1
        echo "ℹ ZEKINI_TEST_STAGES names 'playwright' — folded into 'browser': this fleet's browser specs are Pest-driven Playwright, there is no separate suite to stage." >&2
        ;;
      *)
        echo "⚠ ZEKINI_TEST_STAGES names an unknown stage '${stage}' — ignored. Known: unit feature browser (playwright folds into browser)." >&2
        ;;
    esac
  done

  if [ "$want_browser" != 1 ]; then
    echo "ℹ ZEKINI_TEST_STAGES leaves out 'browser' — that does not skip the browser step. It is controlled by ZEKINI_BROWSER_IN_PREPUSH (and by whether the repo has browser specs), not by this list." >&2
  fi

  if [ -n "$suites" ]; then
    mapfile -t suite_lines <<< "$suites"
  fi

  for suite in "${suite_lines[@]}"; do
    [ -n "$suite" ] || continue
    case "$suite" in
      *[[:space:]]*) flat=1 ;;
    esac
    lower="$(printf '%s' "$suite" | tr '[:upper:]' '[:lower:]')"
    case "$lower" in
      unit) has_unit=1 ;;
      feature) has_feature=1 ;;
    esac
  done

  # Rule 6: the parallel-unsafe groups, as zekini_serial_group_names accepts them
  # (it also says, on stderr, what it refuses and why).
  mapfile -t serial_names < <(zekini_serial_group_names "$serial_raw")
  for serial_name in "${serial_names[@]}"; do
    serial_groups="${serial_groups:+$serial_groups,}${serial_name}"
  done

  # Rule 4: exclude the browser group from the backend stages only when the
  # dedicated step will actually run it. Group exclusion covers specs that
  # live outside a `Browser` testsuite (kadro); the suite list below covers
  # the ones inside it. Rule 6's groups join the SAME single flag.
  if [ "$has_browser" = 1 ] && [ "$browser_dedicated" = 1 ]; then
    browser_excluded=1
    exclude_groups="browser"
  fi
  if [ -n "$serial_groups" ]; then
    exclude_groups="${exclude_groups:+$exclude_groups,}${serial_groups}"
  fi
  if [ -n "$exclude_groups" ]; then
    exclude_flag="--exclude-group=${exclude_groups}"
  fi

  # Rule 7: a selected run is one stage, whatever the split — a stage per suite
  # filtered to the chosen groups could select nothing at all. Where the backend
  # carries the browser group (the dedicated step is off), it keeps carrying it:
  # a selection must not be what stops the browser specs running anywhere.
  #
  # A chosen group that this same stage also excludes is dropped from its
  # `--group=` (ZST-969): `--group=X --exclude-group=X` runs nothing — it fails
  # on "No tests found" or passes vacuously. A rule 6 group still runs, in the
  # serial stage below, which is where it belongs; a selection made up only of
  # those groups is the serial stage alone (plus the browser group's own
  # selected stage where it rides along), and that serial stage runs only the
  # chosen ones, not every configured group (ZST-971). One that chose no rule 6
  # group at all, such as the browser group alone (excluded because the
  # dedicated step owns it), runs the full plan, whether or not rule 6 groups
  # are configured (ZST-971).
  if [ -n "$selected" ] && [ -n "$exclude_groups" ]; then
    # The chosen rule 6 groups: the selection minus everything in it that is
    # not one.
    if [ -n "$serial_groups" ]; then
      selected_serial="$(zekini_groups_minus "$selected" "$(zekini_groups_minus "$selected" "$serial_groups")")"
    fi
    selected="$(zekini_groups_minus "$selected" "$exclude_groups")"
    if [ -z "$selected" ] && [ -n "$selected_serial" ]; then
      echo "ℹ pre-push: every selected group is parallel-unsafe (ZEKINI_PARALLEL_TEST_EXCLUDE_GROUPS) — it runs in the serial stage." >&2
      serial_only=1
    elif [ -z "$selected" ]; then
      echo "⚠ pre-push: every selected group is excluded from the backend stages — running the full backend suite." >&2
    fi
  fi
  if [ -n "$selected" ] || [ "$serial_only" = 1 ]; then
    if [ "$has_browser" = 1 ] && [ "$browser_dedicated" != 1 ]; then
      selected="${selected:+$selected,}browser"
    fi
    if [ -n "$selected" ]; then
      printf 'selected|--group=%s%s\n' "$selected" "${exclude_flag:+ $exclude_flag}"
    fi
    if [ "$serial_only" = 1 ]; then
      zekini_serial_stage_line "$selected_serial" "$browser_excluded"
    else
      zekini_serial_stage_line "$serial_groups" "$browser_excluded"
    fi
    return 0
  fi

  # Rule 2: anything short of "both suites exist AND both stages wanted AND
  # every suite name is word-safe" is today's single flat call.
  if [ "$flat" = 1 ] || [ "$want_unit" != 1 ] || [ "$want_feature" != 1 ] || [ "$has_unit" != 1 ] || [ "$has_feature" != 1 ]; then
    printf 'backend|%s\n' "$exclude_flag"
    zekini_serial_stage_line "$serial_groups" "$browser_excluded"
    return 0
  fi

  # Rule 3 + 4: the feature stage is every suite except Unit, and except
  # Browser when the dedicated step has it.
  for suite in "${suite_lines[@]}"; do
    [ -n "$suite" ] || continue
    lower="$(printf '%s' "$suite" | tr '[:upper:]' '[:lower:]')"
    case "$lower" in
      unit) ;;
      browser)
        if [ "$browser_excluded" != 1 ]; then
          feature_suites="${feature_suites:+$feature_suites,}$suite"
        fi
        ;;
      *) feature_suites="${feature_suites:+$feature_suites,}$suite" ;;
    esac
  done

  printf 'unit|--testsuite=Unit%s\n' "${exclude_flag:+ $exclude_flag}"
  printf 'feature|--testsuite=%s%s\n' "$feature_suites" "${exclude_flag:+ $exclude_flag}"
  zekini_serial_stage_line "$serial_groups" "$browser_excluded"
}
