#!/usr/bin/env bash
#
# Canonical Zekini hook library (from zekini-com/standards): pure
# classification of a changed path, or a single commit's full diff, as
# "frontend" or "confirmed safe to skip" — the decision
# templates/hooks/pre-push's diff-aware frontend lanes (ZST-217) are built
# on.
#
# ## Why this is a separate file (ZST-315)
#
# Three real bugs have been found by hand in this exact classification
# shape (SIGPIPE under `pipefail`, an empty-diff false-positive, per-path
# vs per-diff classification — see zekini_classify_diff's own history
# below) — subtle, and none caught by a test, because the logic lived
# inline in pre-push's own ~300-line main body, not in a sourceable,
# fixture-testable function the way lib/sail-tree.sh's `sail_run` already
# is. Pulled out here for the same reason sail-tree.sh is its own file:
# this can be unit-tested (snippets/zekini-skip-classify.test.php)
# without needing git, a real diff, or a real push.
#
# ## Using it
#
# Copy this file to `.githooks/lib/frontend-skip.sh` alongside the hooks
# (after `.githooks/config` is sourced, since both patterns below are
# overridable from it), then in pre-push:
#
#   . "$(dirname "${BASH_SOURCE[0]}")/lib/frontend-skip.sh"
#   read -r frontend unknown <<< "$(zekini_classify_diff "$diff_output")"

# ZST-284: INVERTED, from an allow-list ("does this path look frontend?")
# to a deny-list ("is this path confirmed to have NOTHING to do with any
# build, test, or app code?"). The allow-list shape kept finding gaps the
# same way, three weeks apart, in the same regex — ZST-249 (app/, routes/,
# config/, tests/ never matched at all) and ZST-284 (kadro's own
# browser-extension/ build target, a real top-level directory the list had
# never heard of). Every one of those gaps was the SAME failure mode: a
# path nobody had explicitly classified defaulted to "not frontend, skip" —
# the unsafe direction — when this very section already applies the OPPOSITE
# default ("cannot see must not guess its way to skipping") one level up,
# at the commit-range level, via `any_unknown`. Applying that same default
# PER PATH closes the whole class at once instead of chasing the next
# uncovered directory as its own card. A repo's next new top-level build
# target now runs the lanes by default, the safe direction, until someone
# adds it to the short deny-list below — not the other way round.
#
# ZST-249: found by mail — the original regex only matched web/**,
# resources/**, and a handful of JS config/lockfiles. It never matched
# app/, routes/, or config/, nor any test file — so a repo whose browser
# specs are PHP (testing PHP-served surfaces: a landing page, admin auth,
# an SPA shell route) got its backend-only push scored as "no frontend
# change" and skipped the dedicated browser step entirely, contradicting
# pre-push's own rule: "backend only" never means "frontend safe" — the
# asymmetry only runs the other way.
#
# `^tests/` closes the second gap broadly rather than trying to guess
# which test files are "the browser ones" by path shape — that guess is
# exactly what zekini_has_browser_tests's own history (KAD-1376, the
# tests/Browser-is-actually-a-screenshot-dir bug) shows is fragile across
# repos. Any test file change running the frontend lanes is the safe
# direction to be wrong in.
#
# Also added the config/lockfile shapes this regex missed entirely:
# tsconfig*.json, pnpm-lock.yaml, yarn.lock, postcss/vitest/eslint config.
#
# ⚠️ ZST-313: `bin/reap-test-orphans` and `bin/test` are test/CI
# infrastructure, not deploy tooling — the deny-list's blanket `bin/` match
# (below) treats them as safe-to-skip, but a match here doesn't override a
# match there (documented on the deny-list itself); listing them here
# forces the frontend/browser lanes to run whenever they change, same as
# any other test file, rather than a fix to the reaper shipping without
# ever being exercised against a live browser process tree in the same
# push that introduces it.
#
# ⚠️ ZST-313: `VAR=${A:-'...'}`, no outer double quotes around the whole
# expansion, is a plain assignment — bash never word-splits or globs it
# regardless of the default's quoting, so the single-quoted default's
# quote-removal still applies correctly, letting `.githooks/config`
# override the pattern the usual way.
ZEKINI_FRONTEND_PATTERN=${ZEKINI_FRONTEND_PATTERN:-'^(web/|resources/|app/|routes/|config/|tests/)|(^|/)(package(-lock)?\.json|pnpm-lock\.yaml|yarn\.lock|tsconfig[^/]*\.json)$|(^|/)(vite|tailwind|postcss|vitest|eslint)\.config\.|(^|/)bin/(reap-test-orphans|test)$'}

# ZST-314: ADDITIVE override. A repo that needs one more path here used to
# have to fork the ENTIRE fleet default into .githooks/config to add it —
# and from then on silently stopped receiving every fleet-wide fix to that
# regex (this pattern has already needed ZST-249, ZST-284 and ZST-313's
# fixes; a full-replace override would have missed all of them). Set
# ZEKINI_FRONTEND_PATTERN_EXTRA to an ERE alternation fragment and it is
# OR'd onto the default, so the repo keeps tracking the fleet. The full
# ZEKINI_FRONTEND_PATTERN override stays as the escape hatch for genuine
# total divergence. Same validation as the base pattern — pre-push
# validates the COMBINED value, so a malformed fragment fails loud there.
if [ -n "${ZEKINI_FRONTEND_PATTERN_EXTRA:-}" ]; then
  ZEKINI_FRONTEND_PATTERN="${ZEKINI_FRONTEND_PATTERN}|${ZEKINI_FRONTEND_PATTERN_EXTRA}"
fi

zekini_changed_paths_touch_frontend() {
  grep -qE "$ZEKINI_FRONTEND_PATTERN"
}

# ZST-284: the deny-list that actually governs skip-vs-run now. Deliberately
# SHORT and conservative — documentation, repo-meta, and ops/deploy files
# only, nothing that could plausibly be read by a build, a test, or the app
# itself. A path that matches NEITHER this NOR the frontend pattern above is
# UNKNOWN, and unknown means run, same as `any_unknown` above it — this
# pattern's whole job is to name what's safe to skip, not what's unsafe to
# skip, because the second list is unbounded and every attempt at it so far
# has undercounted.
#
# An earlier version of this list omitted `VERSION` and `bin/` — but the
# motivating example for the whole skip mechanism is "bin/deploy-xneelo.sh
# + a VERSION bump, zero application code", and every non-hotfix push
# bumps VERSION (pre-push's own version-check enforces it). Without them,
# that exact routine push — the paradigm case — flipped any_unknown=1 and
# ran the frontend lanes on every single push, reintroducing the
# test-database contention this mechanism exists to avoid. `bin/` is ops/
# deploy tooling by the same convention every repo in the fleet already
# follows; a repo whose bin/ genuinely contains frontend-relevant code is
# the edge case, not the norm.
#
# `^docs/` assumes docs/ is markdown documentation, never a built app,
# which holds for every repo in this fleet today — a repo that ever builds
# a real docs SITE (VitePress/Docusaurus) out of a top-level docs/ would
# need this narrowed; not attempted here since it is hypothetical, not
# observed. The same caveat applies to the blanket `\.md$` alternative
# below it, which matches ANY markdown file anywhere in the tree (not just
# under docs/) — a future content/**.md feeding a docs-site build would
# need the same narrowing, for the same not-yet-observed reason.
#
# A match here is deliberate, not "is treated as safe-to-skip" — the two
# patterns aren't mutually exclusive, and a match here doesn't override a
# match on ZEKINI_FRONTEND_PATTERN. A markdown file that also sits under a
# frontend-prefixed path (`web/README.md`, `app/README.md`,
# `tests/README.md`) still sets any_frontend=1 and the lanes still run —
# this pattern only ever WIDENS what counts as safe, it can't narrow what
# counts as frontend.
#
# ⚠️ ZST-313: also added `.gitattributes` and `.github/workflows/*.yml` —
# both exist in real repos and have zero effect on build/test/app behavior,
# but weren't here, so a push touching only one of them got scored
# "unknown" and ran the full frontend/browser lanes unconditionally,
# reintroducing exactly the unnecessary-full-run cost ZST-217 existed to
# fix.
ZEKINI_SAFE_TO_SKIP_PATTERN=${ZEKINI_SAFE_TO_SKIP_PATTERN:-'^(docs/|bin/)|(^|/)(VERSION|\.gitignore|\.gitattributes|\.editorconfig|LICENSE(\.[A-Za-z]+)?)$|(^|/)[A-Za-z0-9_.-]+\.md$|^\.github/workflows/.*\.ya?ml$'}

# ZST-314: additive override, same shape and same reasoning as
# ZEKINI_FRONTEND_PATTERN_EXTRA above. Note the asymmetry that carries over
# from the base patterns: extending THIS list only ever widens what is
# safe to skip; it cannot narrow what counts as frontend.
if [ -n "${ZEKINI_SAFE_TO_SKIP_PATTERN_EXTRA:-}" ]; then
  ZEKINI_SAFE_TO_SKIP_PATTERN="${ZEKINI_SAFE_TO_SKIP_PATTERN}|${ZEKINI_SAFE_TO_SKIP_PATTERN_EXTRA}"
fi

# ⚠️ ZST-313: grep -qE on a malformed pattern exits 2, which every `if` that
# reads its result treats identically to "no match" (exit 1) — a typo'd
# override in .githooks/config (an unbalanced paren, say) makes every push
# on that repo silently default to skip from then on, directly
# contradicting this file's own "a gate that cannot see must not guess its
# way to skipping" rule. The caller decides what "refuse to trust" means
# (pre-push forces the full run); this only says whether the pattern is
# safe to grep with at all.
zekini_validate_skip_pattern() {
  local name="$1" pattern="$2"
  grep -qE "$pattern" /dev/null 2>/dev/null
  case $? in
    0 | 1) return 0 ;;
    *)
      echo "⚠ pre-push: $name is not a valid extended regex (grep -E rejected it) — running every lane instead of trusting a broken skip pattern." >&2
      return 1
      ;;
  esac
}

# ZST-284 / ZST-313 / ZST-315: pure per-diff classification. No git, no
# side effects — a function of its one argument and the two patterns above
# — so it is unit-testable directly (snippets/zekini-skip-classify.test.php)
# the same way zekini_changed_paths_touch_frontend already was. Echoes two
# space-separated 0/1 flags: "<frontend> <unknown>".
#
# ⚠️ Caught in review: the first version of this did classification with a
# bash `while read` loop forking `grep` TWICE per changed path — 2×
# (files×commits) subprocesses on a push touching dozens of files, for a
# hook whose whole point is keeping pushes fast. Same per-path correctness,
# two `grep` calls per DIFF instead: one to ask "does any line match
# frontend", and one `grep -qvE` pass asking "does any line match NEITHER
# pattern" — a match there is, by construction, a path that matched
# neither list.
#
# ⚠️ Caught in review (a second pass): the version between this one and the
# one above fed both greps via `printf '%s' "$diff_output" | grep ...`,
# which SIGPIPEs under `set -o pipefail` whenever grep's `-q` exits early
# on a match before printf finishes writing — pipefail then reports
# printf's 141, not grep's 0, as the pipeline's status. Verified
# concretely: a >64KB piped diff with an early frontend match reproduced
# exit 141 every time, which `if pipeline; then` reads as "no match" —
# silently skipping the frontend lanes on exactly the large-push shape
# this mechanism exists to get right. A here-string (`<<<`) has no pipe to
# SIGPIPE, since grep reads from an fd bash already finished writing
# rather than from a live writer it can kill mid-stream.
#
# ⚠️ Caught in review (a third pass): a here-string on an EMPTY
# `$diff_output` still feeds grep one empty line, not zero — `<<<` always
# terminates with a newline, unlike a piped empty `printf`, which writes
# zero bytes. That empty line matches neither pattern, so the `-qvE` check
# would spuriously flip unknown=1 on a diff that changed nothing at all
# (a commit already an ancestor of what it's being compared against).
# Skip classification entirely — return "0 0" without touching grep — when
# there is nothing to classify.
zekini_classify_diff() {
  local diff_output="$1" frontend=0 unknown=0
  if [ -n "$diff_output" ]; then
    if zekini_changed_paths_touch_frontend <<< "$diff_output"; then
      frontend=1
    fi
    if grep -qvE "$ZEKINI_FRONTEND_PATTERN|$ZEKINI_SAFE_TO_SKIP_PATTERN" <<< "$diff_output"; then
      unknown=1
    fi
  fi
  echo "$frontend $unknown"
}
