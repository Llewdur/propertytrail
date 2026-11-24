#!/usr/bin/env bash
#
# templates/hooks/eod-lib.sh — shared bin/eod machinery (Zekini local-CI
# standard). Sourced by each repo's own bin/eod, which stays per-repo and
# defines its own check list; this file provides the run_check/ticketing/auth
# machinery that check list drives, plus the heartbeat and stack-down guard.
# Drift-checked by hook-drift.sh like .githooks/pre-commit and .githooks/
# pre-push — THIS file is meant to be byte-identical across repos, unlike
# bin/eod itself, whose check list legitimately varies by what the repo has.
#
# Reconciled 2026-09-01 (ZST-183/ZST-182) from kadro's and whatsapp's
# independently-evolved copies, which had diverged in both directions: kadro
# had the machine-credential OAuth exchange (card 1013) that whatsapp lacked;
# whatsapp had the dotenv-wins-over-shell token-shadow guard (loupe card 500),
# the ticketing_probe preflight, the missing-standards-checkout skip-not-fail
# guard, and the stack-down guard that kadro lacked. This file carries all of
# it, so no repo regresses on adopting it.
#
# The sourcing bin/eod must, BEFORE sourcing this file:
#   - `set -uo pipefail`
#   - `cd "$(dirname "$0")/.." || exit 0`   (repo root, regardless of caller cwd)
# Everything here assumes both.
#
# Config (environment or .env):
#   EOD_REPO                  label for this repo    (default: current dir name)
#   TASKBOARD_API_URL         e.g. https://kadro.co.za/api/v1
#   TASKBOARD_BOARD           this repo's board id/ulid
#   TASKBOARD_LIST            the list the preamble probes, and the fallback
#                             target when the board's lists cannot be read.
#                             A failing check is filed into the board's To Do
#                             (Backlog when it has no To Do), priority high, at
#                             the top of the column (ZST-487).
#
# Auth — machine credential first (card 1013), pasted PAT as the fallback:
#   KADRO_MACHINE_CLIENT_ID   the client_credentials client id (not secret)
#   KADRO_MACHINE_SECRET_FILE mode-600 file holding the client secret.
#                             ⚠️ A file, never an exported variable and never a
#                             command-line argument — fleet-infra.md requires
#                             it, because argv is visible in `ps` to every
#                             user on the box. acquire_auth() reads it via
#                             `cat`, then pipes the value into curl via
#                             --data-urlencode client_secret@- (stdin) — NOT
#                             @file directly (that was this mechanism's own
#                             earlier shape, ZST-248, before @- replaced it;
#                             this comment claimed @file long after the code
#                             moved on, caught in review alongside ZST-321).
#                             Either way the secret never appears in argv.
#                             ZST-321: accepts EITHER a secret-only file, OR
#                             the two-line env fragment kadro's own
#                             `machine:issue-credential` command writes to
#                             stdout (`KADRO_MACHINE_CLIENT_ID=…` /
#                             `KADRO_MACHINE_CLIENT_SECRET=…`), documented
#                             there as `... > /etc/kadro/machine.env` — that
#                             output is directly usable as this file with no
#                             hand-editing. acquire_auth() extracts just the
#                             KADRO_MACHINE_CLIENT_SECRET= value when present.
#   TASKBOARD_TOKEN           legacy Sanctum bearer token — an ORDINARY user
#                             who is a member of this repo's board, never a
#                             super-user token (that authenticates fine and is
#                             then refused every board call with 403). Used
#                             only when no machine credential is configured or
#                             the exchange fails.
# If none of these are set, checks still run and report; ticketing is skipped.

REPO="${EOD_REPO:-$(basename "$(pwd)")}"
DATE="$(date +%Y-%m-%d)"
STANDARDS_DIR="${ZEKINI_STANDARDS_DIR:-$HOME/projects/zekini/standards}"

# ZST-287: found by Kadro adopting this template. Used to return 0 (success)
# unconditionally — `.env` missing, key absent from it, and key present-but-
# BLANK all produced the same empty stdout, so every caller collapsed
# "explicitly set to nothing" into "not set at all". Now distinguishes them
# via exit status: 1 when the key genuinely isn't in `.env` (no file, or no
# matching line — the only case a caller should fall back to the shell for),
# 0 whenever a matching line exists, even if its value is empty — `grep`
# matching `^$1=` on a bare `KEY=` line still finds a real line, so `line`
# below is non-empty (the key name and `=` are still there) even though the
# VALUE after `cut` is empty.
#
# ZST-321: strips a leading UTF-8 BOM (EF BB BF — PowerShell's default
# `Out-File` encoding, a realistic artifact of a Windows operator's tooling
# rather than a hypothetical) from content read on stdin. Shared by
# zekini_env_find_line() and zekini_extract_machine_secret(), so this exact
# fix can't end up applied to one and not the other the way it briefly
# was: BOM handling shipped for the machine secret first, and only reached
# env_get() (hence TASKBOARD_TOKEN/BOARD/LIST) a full review round later,
# once a reviewer noticed the shared function it should have lived in from
# the start didn't have it.
zekini_strip_bom() {
  local content
  content="$(cat)"
  printf '%s' "${content#$'\xef\xbb\xbf'}"
}

# ZST-321: finds the first KEY=VALUE line for a given key in content read on
# stdin — shared by env_get() (against .env) and acquire_auth()'s
# KADRO_MACHINE_CLIENT_SECRET= extraction, so "which line matches" can't
# drift between call sites the way it did before this existed.
# prod_url_from_hook_config() used to share this too, but no longer does —
# it sources .githooks/config directly now (see its own comment) rather
# than hand-parsing it, for reasons that don't apply to .env/the machine
# secret (this file is never sourced elsewhere for those).
#
# ⚠️ Caught in review: the anchor originally required KEY= at the very
# start of the line — a plausible copy/paste from an indented terminal or
# markdown code block (leading spaces/tabs), or a manually-wrapped `export
# KEY=value` line, both failed to match. Verified live: an indented
# two-line kadro fragment fell through to the legacy secret-only path and
# shipped the raw two-line text as a "successful" secret — the exact
# malformed-fragment shape this file exists to catch, missed entirely.
# Tolerates optional leading whitespace and an optional `export ` prefix
# now; doesn't change what gets matched for the common, unindented case.
# Investigated: this widening applies to env_get()'s .env parsing too, not
# just the machine-secret path it was found against — deliberate, same
# reasoning as the CRLF/quote widenings below: an indented or
# `export`-prefixed real KEY=VALUE line is exactly as valid in .env as in
# a mint-command fragment, and this fleet's .env convention has no
# multi-line values for a widened anchor to wrongly swallow.
#
# ⚠️ Caught in review: a leading UTF-8 BOM (PowerShell's default Out-File
# encoding — cited as a real target elsewhere in this file, the machine-
# secret extraction below) breaks the `^` anchor for whichever line is
# physically first, exactly like the malformed-fragment bug above — but
# the BOM strip that fixed it there was local to
# zekini_extract_machine_secret() and never reached this shared function,
# so env_get() (hence TASKBOARD_TOKEN/BOARD/LIST from a BOM-prefixed
# .env) stayed exposed. Now uses the shared zekini_strip_bom() below —
# see its own comment for why this moved out of being a second
# copy-pasted one-liner here and in zekini_extract_machine_secret().
#
# ⚠️ Caught in review: this used to end in `| head -n1` after the grep —
# the exact two-process race three OTHER functions in this file were
# rewritten to eliminate (head exits after the first match while grep is
# still writing later ones; under pipefail with a caller that tests exit
# status directly, grep's resulting SIGPIPE can make the whole pipeline
# report failure even though the right line was printed). No current
# caller tests this function's exit status directly — they all test
# captured OUTPUT — so this was dormant, not live, but this function is
# explicitly the shared primitive future call sites are meant to use
# without re-deriving these rules. `grep -m1` stops after the first match
# ITSELF, with no downstream reader to race against — verified live: the
# old `| head -n1` form failed 35/100 runs under pipefail against 2000
# duplicate matches; `grep -m1` alone failed 0/100 against the same input.
#
# ⚠️ Caught in review (again): `zekini_strip_bom | grep -m1 ...` still has
# the identical race, just one hop upstream — `grep -m1` can exit right
# after its first match while `zekini_strip_bom`'s own `printf` is still
# mid-write on a LARGE payload, SIGPIPE-ing that write. Verified live:
# 100/100 failures under pipefail against a ~300KB payload with the match
# on line 1 — worse than the case this same round's fix just closed,
# because it needs no duplicate lines, just enough bytes. Removing `head
# -n1` didn't remove the underlying hazard (a writer process piped into an
# early-exiting reader), it just moved which two processes race. The
# actual fix: no piped writer process at all. Capture the BOM-stripped
# content into a variable first (command substitution reads to EOF, no
# early exit involved, so nothing to race), then feed grep via a
# HERE-STRING rather than a pipe — verified live: the identical 300KB/
# line-1-match reproduction failed 0/100 this way, because a here-string
# isn't backed by a separate producer process whose write() can receive
# SIGPIPE from grep closing early.
zekini_env_find_line() {
  local content
  content="$(zekini_strip_bom)"
  grep -m1 -E "^[[:space:]]*(export[[:space:]]+)?$1=" <<< "$content"
}

# ZST-321: cuts a KEY=VALUE line (read on stdin) down to VALUE, stripping a
# wrapping pair of double OR single quotes and every CR byte (`tr -d`, not
# anchored to end-of-line — a CRLF-terminated file, e.g. one produced or
# edited on Windows, is the realistic source, but this also removes a CR
# ANYWHERE in the value, not just a trailing one; no legitimate config
# value contains a literal CR at all, trailing or otherwise, so the wider
# strip is intentional, not merely how it happens to behave). `\r` is a
# GNU-sed-only escape — BSD/macOS sed (a real target here: acquire_auth()
# already special-cases it for `stat`) does not recognize it as carriage
# return, so `tr -d '\r'` strips the byte first, portably, before sed ever
# sees the line. Shared by env_get() and acquire_auth()'s secret
# extraction, so the same parsing rule can't drift between those two call
# sites (prod_url_from_hook_config() no longer uses this — see its own
# comment). Applying the CRLF strip to EVERY .env-driven value
# (not just the machine secret) is deliberate, not scope creep: a
# CRLF-terminated .env would silently corrupt TASKBOARD_BOARD/LIST/TOKEN
# the identical way, and no legitimate config value ends in a literal CR.
#
# ⚠️ Caught in review: originally stripped only double quotes —
# `KEY='value'` (single-quoted, a common shell convention) shipped with
# both literal quote characters still attached, sent as part of the value.
# Widening single-quote stripping to EVERY .env-driven value here (not just
# the machine secret), same as the CRLF strip above, is deliberate for the
# same reason: a wrapping quote character — either kind — is standard
# .env-file delimiter syntax, not literal value content, in every common
# dotenv convention this fleet's own values follow; no legitimate
# TASKBOARD_TOKEN/BOARD/LIST value is meant to keep a wrapping quote
# character as part of itself.
#
# ⚠️ Caught in review (round 2 of that fix): the first version added single-
# quote stripping as a SECOND, independent sed rule run right after the
# double-quote one — correct for a value quoted with exactly one kind of
# quote, but a value legitimately double-quoted whose OWN content starts
# and ends with a literal single quote (`KEY="'realsecret'"`, the value
# being `'realsecret'` WITH those quotes as real content) had its outer
# double quotes stripped by the first rule, exposing the inner single
# quotes as the new first/last characters — which the second rule then
# ALSO stripped, silently truncating the real value to `realsecret`.
# Verified live. Rewritten as ONE rule with a backreference — `-E` for
# grouping/alternation, same technique zekini_redact_secrets() already uses
# for its own either-quote key matching a few hundred lines down
# (`(["'])...\1`) — so the wrapping quote character (whichever it is) must
# match on BOTH ends before either is stripped, and a value with no
# legitimate matching wrapper (nested, mismatched, or genuinely absent) is
# left untouched rather than partially stripped.
#
# ⚠️ Caught in review: this is a deliberate behavior change from the
# original two-independent-rules version, worth calling out since it's not
# obviously a strict improvement. The old rules each fired independently,
# so a value with only ONE stray quote and no matching pair (`"https://
# example.com`, e.g. a typo dropping the closing quote, or a truncated
# line from a bad merge) had that lone quote stripped too, producing a
# clean-looking value. The backreference form requires a genuine matching
# pair, so an unpaired quote is now left attached instead. Not fixed
# further: leaving a visibly malformed value intact (so whatever consumes
# it fails loudly, e.g. a URL string starting with a literal `"`) is
# consistent with this whole file's established preference for surfacing
# malformed input rather than silently guess-correcting it — see the
# malformed-fragment and whitespace-only-value detection a few hundred
# lines down, which exist for exactly that reason.
#
# Not handled, deliberately: bare-CR-only line endings (pre-OS X classic
# Mac) would collapse multiple real lines into one before any KEY= pattern
# in this file gets to match — unlike CRLF and a leading BOM, both
# realistic today (PowerShell/Windows tooling), bare-CR-only files are not
# a realistic shape any current editor or terminal produces; not defended
# against for the same reason a bare high-entropy secret with no key name
# isn't caught by zekini_redact_secrets() either (see its own "not
# exhaustive by design" note) — a vanishingly unlikely shape isn't worth
# the same defensive weight as one that's actually been seen.
zekini_env_value() {
  tr -d '\r' | sed -E -e 's/^[^=]*=//' -e "s/^([\"'])(.*)\\1\$/\\2/"
}

env_get() {
  [ -f .env ] || return 1
  local line
  line="$(zekini_env_find_line "$1" < .env)"
  [ -n "$line" ] || return 1
  printf '%s' "$line" | zekini_env_value
}

# `.env` WINS; an exported shell variable is only the fallback (loupe card
# 500: an exported super-user token silently shadowed a working `.env` token
# for days — a working credential must never lose to a broken one just
# because the broken one is more visible to the current shell).
#
# ZST-287: this used to test `[ -n "$from_file" ]` — the OUTPUT's emptiness —
# which is exactly the bug env_get's rewrite above fixes at the source: a
# `.env` line explicitly blanking a value (`TASKBOARD_BOARD=`) was
# indistinguishable from the key not being in `.env` at all, so blanking a
# value to force "use the shell's" silently did the OPPOSITE of what
# loupe-500's rule says should happen — `.env` should win even when it wins
# with nothing. Tests env_get's EXIT STATUS instead: found-but-empty now
# correctly stays "found", so it's returned as-is rather than falling
# through to the shell.
resolve() {
  local from_file
  if from_file="$(env_get "$1")"; then
    printf '%s' "$from_file"
  else
    printf '%s' "${!1:-}"
  fi
}

API_URL="$(resolve TASKBOARD_API_URL)"
TOKEN="$(resolve TASKBOARD_TOKEN)"
BOARD="$(resolve TASKBOARD_BOARD)"
LIST="$(resolve TASKBOARD_LIST)"
MACHINE_CLIENT_ID="$(resolve KADRO_MACHINE_CLIENT_ID)"
# ZST-324 (decided 2026-09-07, option A): the secret is read from `.env`
# FIRST, the separate KADRO_MACHINE_SECRET_FILE is the fallback. Before this
# the secret was structurally invisible unless it sat in that separate file
# — but fleet-infra.md's own cross-session delivery (ZST-209, incident-tested
# 2026-09-06) lands the mint-command fragment in the consuming app's `.env`
# via `cat file >> .env`, so an operator following the fleet's documented
# handoff ended up with a credential this file could not see, silently
# falling through to TASKBOARD_TOKEN. A mode-600 `.env` satisfies the same
# "file, not argv, not a shell profile" guarantee the file rule protects, and
# on a host with no persistent disk a platform-injected env var is the
# written-down exception (ZST-322) — so `.env`-wins here matches loupe
# card 500's precedent and resolve()'s own precedence rather than opening a
# new hole.
MACHINE_CLIENT_SECRET="$(resolve KADRO_MACHINE_CLIENT_SECRET)"
MACHINE_SECRET_FILE="$(resolve KADRO_MACHINE_SECRET_FILE)"

# ZST-277: was TASKBOARD_TOKEN-only — an exported TASKBOARD_BOARD/
# TASKBOARD_LIST left over in a shell profile shadows .env exactly the same
# way a shadowed token does (loupe card 500's failure mode), and until now
# only the token had a guard against it: a stray `export TASKBOARD_BOARD=…`
# from another repo's setup files a failing check onto THAT repo's board
# with nothing in the output saying where the id came from. One function,
# called once per config var, so a fourth var doesn't invite a fourth
# near-identical copy of this logic to drift from the other three.
zekini_shadow_of() {
  local name="$1" resolved="$2" source="none" shadowed="no"
  # ZST-287: was `[ -n "$(env_get "$name")" ]` — the same output-emptiness
  # test resolve() used to make, and the same bug: a `.env` line explicitly
  # blanking a value read as "not in dotenv" here too, misattributing
  # source as "shell" for a var that WAS set (deliberately blank) in `.env`.
  # Tests env_get's exit status instead, same fix as resolve() above.
  if env_get "$name" >/dev/null; then
    source="dotenv"
  elif [ -n "${!name:-}" ]; then
    source="shell"
  fi
  if [ "$source" = "dotenv" ] && [ -n "${!name:-}" ] && [ "${!name:-}" != "$resolved" ]; then
    shadowed="yes"
  fi
  printf '%s %s' "$source" "$shadowed"
}

read -r TOKEN_SOURCE TOKEN_SHADOWED <<< "$(zekini_shadow_of TASKBOARD_TOKEN "$TOKEN")"
read -r BOARD_SOURCE BOARD_SHADOWED <<< "$(zekini_shadow_of TASKBOARD_BOARD "$BOARD")"
read -r LIST_SOURCE LIST_SHADOWED <<< "$(zekini_shadow_of TASKBOARD_LIST "$LIST")"
# ZST-356 (found by Accounting's review panel): zekini_shadow_of() was
# already generic — it takes a var NAME and its resolved value, nothing
# TASKBOARD-specific — but was only ever CALLED for the three TASKBOARD_*
# vars, so a correctly-exported Kadro machine credential silently shadowed
# by a stale .env line got none of the diagnostic output the other three
# get in the identical situation. All three machine vars go through the
# exact same resolve() precedence (lines above) as TASKBOARD_TOKEN/BOARD/
# LIST, so they need the exact same guard, not a narrower one — including
# KADRO_MACHINE_SECRET_FILE, a path rather than a secret value, but "which
# copy wins when shell and .env disagree" is the identical confusion either
# way. Neither of zekini_shadow_of's own warning messages ever prints the
# resolved VALUE, only the var NAME, so this is safe to reuse verbatim for
# the secret var too.
read -r MACHINE_CLIENT_ID_SOURCE MACHINE_CLIENT_ID_SHADOWED <<< "$(zekini_shadow_of KADRO_MACHINE_CLIENT_ID "$MACHINE_CLIENT_ID")"
read -r MACHINE_CLIENT_SECRET_SOURCE MACHINE_CLIENT_SECRET_SHADOWED <<< "$(zekini_shadow_of KADRO_MACHINE_CLIENT_SECRET "$MACHINE_CLIENT_SECRET")"
read -r MACHINE_SECRET_FILE_SOURCE MACHINE_SECRET_FILE_SHADOWED <<< "$(zekini_shadow_of KADRO_MACHINE_SECRET_FILE "$MACHINE_SECRET_FILE")"

fail_count=0

# Filled in by acquire_auth(): the bearer actually sent, and the API base the
# ticket calls use — `/api/v1/machine/…` for a machine token (the human
# routes refuse one), plain `/api/v1/…` for the legacy PAT.
AUTH_TOKEN=""
TICKET_API_URL=""

# Filled in by zekini_resolve_ticket_list(): the list a failing check is filed
# into, and its name for the log. TASKBOARD_LIST until the board's own lists
# say otherwise.
TICKET_LIST="$LIST"
TICKET_LIST_NAME="TASKBOARD_LIST"

# Set by eod_preamble() once ticketing_probe() comes back rejecting or
# unreachable — every field ticketing_ready() checks can be individually
# non-empty while the board still refuses this runner (a stale id, a
# super-user token), and until this flag existed nothing after the
# preamble's own printed warning actually stopped trying: every later
# failing check still called file_ticket() -> card_exists() -> a POST, each
# paying a real round-trip against a board already known to be dead (ZST-212).
TICKETING_BLOCKED="no"

# ZST-324: ready when the client id is set AND a secret is reachable from
# EITHER source — `.env` (or the shell / a platform env var) directly, or the
# separate secret file. The "id set but no secret anywhere" case is the one
# that used to fall through silently; eod_preamble() now names it loudly via
# zekini_machine_secret_missing() rather than leaving it indistinguishable
# from "machine credential not configured at all."
machine_ready() {
  [ -n "$MACHINE_CLIENT_ID" ] && { [ -n "$MACHINE_CLIENT_SECRET" ] || { [ -n "$MACHINE_SECRET_FILE" ] && [ -f "$MACHINE_SECRET_FILE" ]; }; }
}

zekini_machine_secret_missing() {
  [ -n "$MACHINE_CLIENT_ID" ] && [ -z "$MACHINE_CLIENT_SECRET" ] && { [ -z "$MACHINE_SECRET_FILE" ] || [ ! -f "$MACHINE_SECRET_FILE" ]; }
}

# ZST-321 round 5: extracted out of acquire_auth() so this parsing logic can
# be unit-tested directly (snippets/eod-lib-machine-secret.test.sh — run it
# after ANY change here, same discipline as redact-secrets.php's own
# conformance suite), matching this file's established practice of pulling
# fiddly parsing logic into its own named, independently-testable function
# (env_get, zekini_shadow_of, zekini_redact_secrets all live this way).
#
# Reads the raw KADRO_MACHINE_SECRET_FILE content on stdin. Accepts EITHER a
# secret-only file OR the two-line env fragment kadro's own
# `machine:issue-credential` command writes to stdout (KADRO_MACHINE_CLIENT_
# ID=… / KADRO_MACHINE_CLIENT_SECRET=…) — see the KADRO_MACHINE_SECRET_FILE
# doc near the top of this file for the full account (found by Loupe
# adopting this credential, LOU-819).
#
# Strips a trailing CR (CRLF line endings) and a leading UTF-8 BOM before
# any KEY= matching — both are realistic artifacts of a Windows operator
# redirecting kadro's mint-command output via PowerShell, whose default
# `Out-File` encoding adds a BOM; without stripping it first, a BOM on
# whichever line happens to be physically first breaks that line's own `^`
# anchor match (caught in review, round 5).
#
# Exit status distinguishes three outcomes the caller (acquire_auth()) warns
# about differently:
#   0  a usable, non-empty secret — printed on stdout
#   1  content has a KADRO_MACHINE_CLIENT_ID= line but NO
#      KADRO_MACHINE_CLIENT_SECRET= line at all — a truncated fragment, or
#      only the first line was copied
#   2  nothing usable — a genuinely empty/unusable secret-only file, OR a
#      KADRO_MACHINE_CLIENT_SECRET= line whose extracted value is blank (a
#      redacted or failed mint). ⚠️ Caught in review: an earlier version of
#      this comment claimed status 1 also covered a blank SECRET= value —
#      it doesn't; that shape falls through to the generic status-2 path
#      below, same as any other empty result. Corrected so the doc matches
#      the code (and snippets/eod-lib-machine-secret.test.sh's own
#      "empty SECRET value" case, which asserts status 2).
#
# If the same KEY= appears more than once, only the FIRST match is used
# (zekini_env_find_line's `grep -m1`) — deliberately consistent with
# env_get()'s own first-match convention for every other .env-style var in
# this file, not special-cased here just because the source is a different
# file. Not fixed: a machine.env accumulating a stale blank
# KADRO_MACHINE_CLIENT_SECRET= line ahead of a real one (e.g. from `>>`
# instead of `>` redirecting a re-run of kadro's mint command) reads as
# empty rather than finding the real value further down — same trade-off
# env_get() already makes for every other variable, not unique to this one.
#
# ⚠️ Caught in review: this function hand-parses its input (BOM/CRLF/
# quote-stripping, malformed-fragment detection) while
# prod_url_from_hook_config() just above went the OTHER way and switched
# to sourcing .githooks/config directly as real shell code. That is NOT
# an inconsistency to "fix" by making this symmetric — sourcing THIS
# file would mean executing untrusted SECRET BYTES as bash, since
# KADRO_MACHINE_SECRET_FILE's content is exactly the credential material
# this whole mechanism exists to protect, not reviewed, committed repo
# policy the way .githooks/config is. Hand-parsing here is deliberate and
# stays that way; the fix depth genuinely differs because what's being
# read differs.
zekini_extract_machine_secret() {
  local content secret_line secret
  # ⚠️ Caught in review: an earlier version read via `content="$(cat)"` then
  # a SEPARATE `printf | tr -d '\r'` pipe to strip CRLF — two forks where
  # one does the same job, since this function's own stdin (what `cat` was
  # reading) is exactly what `tr` can read directly.
  content="$(tr -d '\r' | zekini_strip_bom)"
  # (BOM-strip after CRLF-strip, not before: order doesn't matter — CR
  # stripping never touches the BOM's 3 bytes — but reads simpler as
  # "normalize line endings, then normalize the byte-order marker."
  # zekini_strip_bom() is the shared helper above — see its own comment
  # for why this is no longer its own inline copy.
  #
  # Investigated: `content` is already BOM-free by this point, so the two
  # zekini_env_find_line() calls below each re-run zekini_strip_bom() on
  # already-clean input — a harmless no-op strip, plus a `cat` fork,
  # spent again for nothing each time. Not eliminated: doing so would mean
  # this function stops using the shared line-finder for one of its own
  # two lookups, reopening the exact "logic drifts because one caller is
  # special-cased" risk that function was extracted to prevent — an
  # acceptable trade for a call that runs at most once per bin/eod
  # invocation, same reasoning as the CRLF double-strip accepted
  # elsewhere in this function's history.)
  #
  # ⚠️ Caught in review: an earlier version split CRLF-stripping across two
  # branches below instead of normalizing it once, up front, next to the
  # BOM strip — this file's own multi-round PEM-redaction history (a few
  # hundred lines down) is a long account of exactly this "per-shape
  # special case instead of one general pass" pattern reintroducing bugs
  # every time a new shape gets added. One normalization pass here, before
  # any branching, matching the BOM strip's own placement, even though it
  # means zekini_env_value()'s own internal CR-strip (below) becomes a
  # harmless no-op on the already-clean fragment path.

  secret_line="$(printf '%s' "$content" | zekini_env_find_line KADRO_MACHINE_CLIENT_SECRET)"
  if [ -n "$secret_line" ]; then
    secret="$(printf '%s' "$secret_line" | zekini_env_value)"
  elif [ -n "$(printf '%s' "$content" | zekini_env_find_line KADRO_MACHINE_CLIENT_ID)" ]; then
    # ⚠️ Caught in review: an earlier version tested this pipeline's EXIT
    # STATUS directly (`if ... | zekini_env_find_line ID >/dev/null; then`)
    # rather than its output, relying on the caller's documented `set -uo
    # pipefail`. That is fragile in a way this rewrite avoids entirely: if
    # the ID= pattern matches MORE than one line, `head -n1` (inside
    # zekini_env_find_line) reads the first and exits before grep finishes
    # writing the rest — grep's next write can hit SIGPIPE (141), and under
    # pipefail the pipeline then reports FAILURE even though a genuine
    # match exists, silently falling through to the legacy-secret-file
    # branch instead of detecting the malformed fragment. Testing captured
    # OUTPUT via command substitution, same as the `secret_line` check
    # three lines up, sidesteps this: the content already written before
    # any later SIGPIPE is unaffected by it, so `[ -n "$(...)" ]` is
    # correct regardless of pipefail or how many lines matched.
    return 1
  else
    # Legacy secret-only shape: no KEY=VALUE line at all, so the whole
    # (already CRLF/BOM-normalized) content IS the secret, unchanged.
    secret="$content"
  fi

  # ⚠️ Caught in review: `[ -z "$secret" ]` alone treats a WHITESPACE-ONLY
  # value (e.g. `KADRO_MACHINE_CLIENT_SECRET=   `, a copy/paste artifact or
  # unfilled template placeholder) as usable — non-empty by string length,
  # but no real secret content. That sent a garbage secret to the OAuth
  # exchange instead of being caught here, producing a generic 401 rather
  # than the more actionable "no usable secret" warning this status exists
  # to give. Checks for any NON-whitespace character instead of mere
  # non-emptiness.
  #
  # ⚠️ Caught in review: the first version of this check tested a piped
  # `grep -qE`'s EXIT STATUS directly (`if ! printf ... | grep -qE ...;
  # then`) — the exact same SIGPIPE/pipefail fragility as the malformed-
  # fragment check above, just via grep's OWN early-exit-on-first-match
  # (grep -q) racing printf's write instead of a downstream head. Verified
  # empirically: with a match early in a many-line string, ~8% of runs
  # under pipefail reported "no match" when one genuinely existed. A second
  # version fixed that the same way as the malformed-fragment check — `grep
  # -m1 -oE` capturing the actual match via command substitution instead of
  # relying on exit status.
  #
  # ⚠️ Caught in review (again): forking printf+grep at all was unnecessary
  # — bash tests a shell variable against a regex NATIVELY via `[[ =~ ]]`,
  # with no subprocess and so no pipe for a SIGPIPE/pipefail race to exist
  # in, in a function whose review history spent two rounds specifically
  # eliminating that exact class of fragility elsewhere. Verified live,
  # including against the same multi-thousand-line race-inducing input the
  # earlier `grep -qE` bug was caught with (no exposure, by construction —
  # there's no pipe left to race).
  if ! [[ "$secret" =~ [^[:space:]] ]]; then
    return 2
  fi

  # Deliberately NOT trimmed further than the whitespace-only check above:
  # a value with real content PLUS stray leading/trailing whitespace (a
  # copy/paste artifact) still passes through as-is, corrupted whitespace
  # and all. Left as an accepted gap rather than fixed here — trimming
  # every value this file parses is a pre-existing characteristic of
  # zekini_env_value()/env_get(), shared by every other .env-style var in
  # this file (TASKBOARD_TOKEN/BOARD/LIST included), not a regression from
  # ZST-321 or something worth special-casing for just this one caller.

  # ⚠️ Caught in review: relied on this printf's own (near-guaranteed)
  # success as the function's implicit return status, rather than an
  # explicit `return 0` — a future edit inserting any fallible command
  # between building `secret` and here would silently change what this
  # function reports, and acquire_auth()'s status dispatch folds anything
  # other than 0/1 into the generic "no usable secret" branch, misdiagnosing
  # a real secret as unusable with no test catching it (the regression
  # suite exercises input shapes, not internal command failure). Explicit
  # now, so this can't drift out from under a later edit.
  printf '%s' "$secret"
  return 0
}

# Exchange the client secret for an access token — ONCE per run. Tokens last an
# hour and a run is far shorter, so there is no refresh to manage (card 1013).
# Loud on failure but never fatal: bin/eod is unattended and always exits 0, so
# an auth failure prints and the run carries on, exactly like a failed filing.
acquire_auth() {
  if machine_ready; then
    local perms
    # ZST-324: the mode check is for the SEPARATE secret file only. A
    # `.env`-sourced secret's file is the app's own `.env`, whose mode is
    # repo/host policy (ZST-260 set 640 group www-data on the Xneelo box),
    # not something this library second-guesses per run.
    if [ -z "$MACHINE_CLIENT_SECRET" ]; then
      # ZST-248: `stat -c` is GNU-only and fails on macOS/BSD stat, where the
      # `|| echo ''` fallback silently swallowed the error — the mode-600 check
      # below then never fires on that platform, a world-readable secret file
      # going unwarned-about. Try GNU's flag first, fall back to BSD's.
      perms="$(stat -c '%a' "$MACHINE_SECRET_FILE" 2>/dev/null || stat -f '%Lp' "$MACHINE_SECRET_FILE" 2>/dev/null || echo '')"
      if [ -n "$perms" ] && [ "$perms" != "600" ] && [ "$perms" != "400" ]; then
        echo "  ⚠ $MACHINE_SECRET_FILE is mode $perms — it should be 600 (fleet-infra.md)."
      fi
    fi

    local oauth_base access_token secret_content attempted=0
    # TASKBOARD_API_URL ends in /api/v1; the token endpoint lives at the origin.
    oauth_base="${API_URL%/api/v1*}"

    # ZST-248: --data-urlencode name@file sends the file's contents VERBATIM,
    # including a trailing newline — the shape `echo "$SECRET" > file` always
    # produces. That newline becomes part of the sent secret, the exchange
    # 401s, and nothing here says a trailing newline is the actual problem —
    # it just falls back to TASKBOARD_TOKEN like any other credential
    # failure.
    #
    # ⚠️ Caught in review (round 3): earlier versions of this fix trimmed
    # into a FRESH TEMP FILE (mktemp + chmod 600 + an EXIT-trap
    # save/restore/eval dance to clean it up even on a signal), on the
    # theory that a shell variable would have to be passed as a literal
    # `-d`/`--data-urlencode name=value` argument, putting it in argv
    # (visible via `ps`) — exactly what the original `@file` form was
    # chosen to avoid. That reasoning missed `--data-urlencode name@-`,
    # which reads the value from STDIN — still never touches argv, and
    # needs no temp file at all: pipe the trimmed variable in directly.
    # Verified `@-` does NOT itself trim (curl reads it as literally as
    # `@file`); the trim still has to happen in the shell variable via
    # command substitution (which already strips trailing newlines) before
    # piping it in.
    #
    # This also removes the entire class of findings the temp-file version
    # kept accumulating (a `cat` failure writing an empty secret unnoticed;
    # the EXIT trap clobbering a caller's own trap; a silent chmod
    # failure) — there is no temp file left to leak, chmod, or clean up.
    # ⚠️ Caught in review (round 4): the previous version warned on a cat
    # failure but still fell through to the curl call with an EMPTY
    # secret_content — a real behavior change from the original `@file`
    # form, which failed LOCALLY the instant curl itself couldn't open the
    # file (no network call at all). Sending an empty client_secret over
    # the wire instead is worse than the bug ZST-248 fixed: a live request
    # for a credential that was never actually read. Skip the network call
    # entirely on a read failure — access_token stays empty, which falls
    # through to the same "machine token exchange FAILED... falling back to
    # TASKBOARD_TOKEN" path every other failure mode already uses below.
    # ⚠️ Caught in review: `access_token=""` was set independently in three
    # different branches below (the extract_status elif, its else, and the
    # outer "could not read" else) instead of once, defaulted — the same
    # "two/three copies drift apart" pattern this function's own extract_
    # status dispatch comment above says it was rewritten to avoid. A
    # future branch added to any of these dispatches has to remember to
    # also blank it or it silently carries a stale value; only the success
    # path below needs to actively SET it.
    access_token=""
    # ⚠️ Caught in review: this suffix was copy-pasted verbatim into all
    # three "couldn't get a usable secret" warnings below — the exact
    # class of drift-risk this SAME function's neighboring comments (the
    # access_token="" consolidation, the extract_status if/elif/else merge)
    # were already written to eliminate, just not extended to this string.
    local not_attempting_suffix="not attempting the network call, falling back to TASKBOARD_TOKEN if set."
    # ZST-324: `.env` wins. Its value is already a bare secret (the fragment
    # was appended line-by-line, so KADRO_MACHINE_CLIENT_SECRET= carries only
    # the value) — it still goes through zekini_extract_machine_secret() so
    # CRLF/BOM/whitespace stripping and the empty-value check apply
    # identically to both sources, one code path, one set of diagnostics.
    # ZST-392: an explicit flag, set at the point of success or failure, not
    # `if [ $? -eq 0 ]` reading whichever statement happened to end the block
    # above. That `$?` was the status of the whole if/fi, which was only ever 0
    # for the .env branch because a bare `true` happened to be its last
    # statement: any line inserted after it would have silently changed which
    # branch the check below saw, and the .env branch could never report a read
    # failure at all.
    local secret_source secret_read_ok=0
    if [ -n "$MACHINE_CLIENT_SECRET" ]; then
      secret_source=".env (KADRO_MACHINE_CLIENT_SECRET)"
      secret_content="$MACHINE_CLIENT_SECRET"
      secret_read_ok=1
    else
      secret_source="$MACHINE_SECRET_FILE"
      if secret_content="$(cat "$MACHINE_SECRET_FILE" 2>/dev/null)"; then
        secret_read_ok=1
      fi
    fi
    if [ "$secret_read_ok" -eq 1 ]; then
      # ZST-321: parsing (secret-only vs kadro's two-line env-fragment shape,
      # CRLF/BOM stripping, malformed-fragment/empty-value detection) lives
      # in zekini_extract_machine_secret() — see its own comment above for
      # the full account and the exit-status contract this reads.
      local extract_status
      secret_content="$(printf '%s' "$secret_content" | zekini_extract_machine_secret)"
      extract_status=$?

      # ⚠️ Caught in review: extract_status used to be branched on TWICE —
      # a `case` here for the warning message, then a separate `if
      # [ "$extract_status" -eq 0 ]` below for the actual behavior — the
      # exact "two copies drift apart" pattern this file's own
      # ticketing_ready()/zekini_ticketing_configured split was written to
      # avoid elsewhere. One if/elif/else now decides both together.
      if [ "$extract_status" -eq 0 ]; then
        # The secret travels via --data-urlencode client_secret@- (stdin) so
        # it never appears in argv; the client id is not a secret and may.
        # ZST-253: bounded — this runs inside eod_preamble(), near the top of
        # every bin/eod invocation, so a wedged auth endpoint used to hang
        # the entire unattended run forever, the exact shape ZST-212 bounded
        # every other curl in this file against.
        attempted=1
        access_token="$(printf '%s' "$secret_content" | curl -fsS --max-time 10 "$oauth_base/oauth/token" \
          -d grant_type=client_credentials \
          -d client_id="$MACHINE_CLIENT_ID" \
          --data-urlencode "client_secret@-" \
          -d 'scope=machine:cards.create machine:cards.read' 2>/dev/null \
          | jq -r '.access_token // empty')"
      elif [ "$extract_status" -eq 1 ]; then
        echo "  ⚠ $secret_source looks like kadro's mint-command output (has a KADRO_MACHINE_CLIENT_ID= line) but no KADRO_MACHINE_CLIENT_SECRET= line — cannot extract a secret; $not_attempting_suffix" >&2
      else
        echo "  ⚠ no usable secret in $secret_source — cannot exchange the machine credential; $not_attempting_suffix" >&2
      fi
    else
      echo "  ⚠ could not read $secret_source (permission or filesystem problem) —" >&2
      echo "  cannot exchange the machine credential; $not_attempting_suffix" >&2
    fi

    if [ -n "$access_token" ]; then
      AUTH_TOKEN="$access_token"
      TICKET_API_URL="${API_URL%/}/machine"
      echo "  ↳ ticketing as machine credential ($MACHINE_CLIENT_ID)"
      return
    fi

    # ⚠️ Caught in review: this used to print unconditionally whenever
    # access_token was empty for ANY reason — including the "not attempting
    # the network call" cases just above, which already printed their own
    # specific reason. An operator with a truncated secret file saw "...not
    # attempting the network call" immediately followed by "machine token
    # exchange FAILED... check the credential", which reads as a contradiction
    # (implies an exchange was tried and rejected server-side) and points at
    # the wrong thing to check. Only print this when an exchange was actually
    # attempted; the "not attempting" branches already said why.
    if [ "$attempted" -eq 1 ]; then
      echo "  ✗ machine token exchange FAILED (client $MACHINE_CLIENT_ID) — check the credential; falling back to TASKBOARD_TOKEN if set."
    fi
  fi

  if [ -n "$TOKEN" ]; then
    AUTH_TOKEN="$TOKEN"
    TICKET_API_URL="${API_URL%/}"
  fi
}

# ZST-248 (caught in review): just the STATIC config fields — no AUTH_TOKEN
# (a live token exchange result) and no TICKETING_BLOCKED (a live probe
# result). The shell-exported-token warning below needs to fire whenever
# ticketing is genuinely SET UP, regardless of whether the board has
# separately accepted or rejected this runner — gating it on the full
# ticketing_ready() instead suppressed the warning exactly when
# TICKETING_BLOCKED=yes, hiding the one actionable clue (this token only
# lives in your shell) at the moment the credential is confirmed broken and
# every clue matters most.
zekini_ticketing_configured() {
  [ -n "$API_URL" ] && [ -n "$BOARD" ] && [ -n "$LIST" ] \
    && command -v curl >/dev/null 2>&1 && command -v jq >/dev/null 2>&1
}

# ⚠️ Caught in review (round 2): composed FROM zekini_ticketing_configured
# rather than restating its four conditions — an earlier version duplicated
# them verbatim, which is exactly the "two copies drift apart the moment one
# changes" class of bug this file's OWN comments already warn about
# elsewhere (see ZST-246's shared-reason-helper extraction, a few lines
# down).
ticketing_ready() {
  zekini_ticketing_configured && [ -n "$AUTH_TOKEN" ] && [ "$TICKETING_BLOCKED" != "yes" ]
}

# Ask the BOARD, not four non-empty strings — all four can be set and the
# board can still reject every call (a super-user token, a stale id).
ticketing_probe() {
  curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
    -H "Authorization: Bearer $AUTH_TOKEN" -H "Accept: application/json" \
    "$TICKET_API_URL/boards/$BOARD/lists/$LIST/cards" 2>/dev/null || echo "000"
}

# ZST-487 (owner decision 2026-09-23): a red nightly run used to land in
# TASKBOARD_LIST — Backlog on most boards — with no priority, and LOU-975/976
# sat there six days while every Fleet run on the repo tripped over the same
# failure. The nightly run is the only place the browser suite runs on most
# repos, so its failures go where work is pulled from: To Do, priority high,
# top of the column. Backlog only when the board has no To Do; TASKBOARD_LIST
# only when the board's lists cannot be read at all (the old behaviour, so a
# route this runner cannot reach degrades to what it did before, not to
# nothing).
#
# Reads a board's lists JSON on stdin and prints the id of the first list
# whose name, lowercased with everything but letters and digits dropped,
# equals $1 ("todo" matches "To Do", "TODO", "To-do"). The recursive walk
# survives whatever envelope the endpoint wraps the lists in, same as
# card_exists() below.
zekini_pick_list() {
  jq -r --arg want "$1" '
    [.. | objects | select(has("name") and (has("ulid") or has("id")))
      | select((.name | tostring | ascii_downcase | gsub("[^a-z0-9]"; "")) == $want)
      | (.ulid // .id | tostring)] | first // empty' 2>/dev/null
}

zekini_resolve_ticket_list() {
  TICKET_LIST="$LIST"
  TICKET_LIST_NAME="TASKBOARD_LIST"
  local resp id key
  if ! resp="$(curl -fsS --max-time 10 -H "Authorization: Bearer $AUTH_TOKEN" -H "Accept: application/json" \
    "$TICKET_API_URL/boards/$BOARD/lists" 2>/dev/null)"; then
    echo "  ⚠ could not read this board's lists — failing checks go to TASKBOARD_LIST, not To Do (ZST-487)."
    return
  fi
  for key in todo backlog; do
    id="$(printf '%s' "$resp" | zekini_pick_list "$key")"
    if [ -n "$id" ]; then
      TICKET_LIST="$id"
      case "$key" in
        todo) TICKET_LIST_NAME="To Do" ;;
        backlog) TICKET_LIST_NAME="Backlog" ;;
      esac
      echo "  ↳ failing checks are filed into $TICKET_LIST_NAME, priority high, top of the column"
      return
    fi
  done
  echo "  ⚠ this board has neither a To Do nor a Backlog list — failing checks go to TASKBOARD_LIST (ZST-487)."
}

# The failing test classes a check's output names, one per line, deduped:
# `Tests\Browser\AdminSectionsTest` (Pest's FAILED line, PHPUnit's numbered
# failure) and `tests/Browser/AdminSectionsTest.php` (a file path) both give
# AdminSectionsTest. Nothing printed for a check that is not a test suite.
# A here-string, not a pipe, feeds grep — the SIGPIPE/pipefail race
# zekini_env_find_line()'s comment describes.
zekini_failing_test_classes() {
  local match class
  local -A seen=()
  while IFS= read -r match; do
    [ -n "$match" ] || continue
    class="${match##*\\}"
    class="${class##*/}"
    class="${class%.php}"
    [ -n "${seen[$class]:-}" ] && continue
    seen[$class]=1
    printf '%s\n' "$class"
  done <<< "$(grep -oE '(Tests\\([A-Za-z0-9_]+\\)*[A-Za-z0-9_]+Test|tests/([A-Za-z0-9_]+/)*[A-Za-z0-9_]+Test\.php)' <<< "$1")"
}

# Every open card title in the list a failure is filed into, and in
# TASKBOARD_LIST when that is a different list (a card filed before ZST-487
# still sits there), one per line. Status 2 when the board did not answer or
# a body did not parse.
#
# Unpaginated by design, not by omission: this endpoint only paginates when
# the caller sends `page`/`per_page` (CardController::index), and the machine
# route never paginates at all (MachineCardListController — a plain `.get()`
# over every non-archived card). Neither call below sends those params, so
# both routes return the full open-card list in one response (verified
# against kadro's controllers, ZST-185).
zekini_open_card_titles() {
  local list resp
  local -A seen=()
  for list in "$TICKET_LIST" "$LIST"; do
    [ -n "$list" ] || continue
    [ -n "${seen[$list]:-}" ] && continue
    seen[$list]=1
    # LOU-1133: one retry — a single dropped read used to mean a duplicate card.
    resp="$(curl -fsS --retry 1 --max-time 10 -H "Authorization: Bearer $AUTH_TOKEN" -H "Accept: application/json" \
      "$TICKET_API_URL/boards/$BOARD/lists/$list/cards" 2>/dev/null)" || return 2
    printf '%s' "$resp" | jq -r '.. | objects | select(has("title")) | .title | tostring' 2>/dev/null || return 2
  done
}

# Is this failure already on the board?
#   0  yes — do not file again
#   1  no  — genuinely new
#   2  could not tell (the board did not answer, or the body did not parse)
# Two ways to be "already on the board": an open card with this exact title
# (the stable [EOD] title, as always), or — ZST-487 — every failing test class
# the output names already appears, as a whole word, in some open card's title
# (a card someone titled after the test, like LOU-975's AdminSectionsTest).
# Every, not any: a second class failing alongside one already carded is a
# new failure, and filing it is the point. Remaining args are the classes.
card_exists() {
  local title="$1" titles open class covered
  shift
  titles="$(zekini_open_card_titles)" || return 2

  while IFS= read -r open; do
    [ "$open" = "$title" ] && return 0
  done <<< "$titles"

  [ "$#" -gt 0 ] || return 1
  for class in "$@"; do
    covered=0
    while IFS= read -r open; do
      if [[ "$open" =~ (^|[^A-Za-z0-9_])${class}([^A-Za-z0-9_]|$) ]]; then
        covered=1
        break
      fi
    done <<< "$titles"
    [ "$covered" -eq 1 ] || return 1
  done
  return 0
}

# Moves a just-filed card to the top of its column. Best effort: the create
# call already asks for position 0, and a board route that refuses this one (a
# machine credential without card-update scope) leaves the card filed, high,
# in the right list — a warning, never a failed filing.
zekini_card_to_top() {
  local card_id="$1" payload status
  payload="$(jq -n --arg l "$TICKET_LIST" '{list: $l, position: 0}')"
  status="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
    -X PATCH "$TICKET_API_URL/boards/$BOARD/cards/$card_id/move" \
    -H "Authorization: Bearer $AUTH_TOKEN" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "$payload" 2>/dev/null || echo "000")"
  case "$status" in
    2*) return 0 ;;
    *)
      echo "    ↳ could not move it to the top of $TICKET_LIST_NAME (HTTP $status) — it is filed, priority high"
      return 1
      ;;
  esac
}

# ZST-662: the Kadro release whose create routes take a caller's
# `dedupe_key` (kept in cards.issue_hash, matched board-wide against OPEN
# cards: a hit answers the existing card with `deduplicated: true` and Kadro
# records the recurrence on it). The same-title read above sees one list only,
# so an [EOD] card somebody moved out of LIST was filed again every night
# (Loupe, 2026-10-01: "[EOD][loupe] githooks drift failing" x6 across Triage
# and To Do). Kadro 0.987.0 ships the key (ZST-1088) — the same number as
# DEDUPE_VERSION in Fleet/adapters/kadro.py and the rule in
# docs/board-workflow.md; ZEKINI_DEDUPE_KADRO_VERSION overrides it.
EOD_DEDUPE_KADRO_VERSION="${ZEKINI_DEDUPE_KADRO_VERSION-0.987.0}"
EOD_KADRO_DEDUPES=""   # yes / no once asked, so one run reads /version once

# Does the board's Kadro dedupe a create by key? 0 yes; 1 no, or cannot tell
# (an unreadable /version is an older Kadro, so the title read stays).
kadro_dedupes() {
  if [ -z "$EOD_KADRO_DEDUPES" ]; then
    EOD_KADRO_DEDUPES="no"
    if [ -n "$EOD_DEDUPE_KADRO_VERSION" ]; then
      local have
      have="$(curl -fsS --max-time 10 -H "Accept: application/json" "${API_URL%/api/v1*}/version" 2>/dev/null \
        | jq -r '.version // empty' 2>/dev/null)"
      if [[ "$have" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]] \
        && [ "$(printf '%s\n%s\n' "$EOD_DEDUPE_KADRO_VERSION" "${BASH_REMATCH[0]}" | sort -V | head -n1)" = "$EOD_DEDUPE_KADRO_VERSION" ]; then
        EOD_KADRO_DEDUPES="yes"
      fi
    fi
  fi
  [ "$EOD_KADRO_DEDUPES" = "yes" ]
}

# ZST-246: ticketing_ready() can be false for two different reasons — the
# TASKBOARD_* env vars are simply unset, or (since ZST-212) the board
# REJECTED this runner (TICKETING_BLOCKED=yes: a stale board/list id or a
# refused credential — already surfaced correctly by the preamble's own
# warning). Shared between file_ticket() and eod_finish(), which both need
# to tell the two apart — caught in review: an earlier version of this fix
# duplicated the same `if [ "$TICKETING_BLOCKED" = "yes" ]` branch in both
# places, which could drift the moment a third TICKETING_BLOCKED-adjacent
# state gets added and only one call site is updated for it.
zekini_ticketing_not_ready_reason() {
  if [ "$TICKETING_BLOCKED" = "yes" ]; then
    printf 'the board rejected this runner'
  else
    printf 'ticketing is not configured'
  fi
}

# ZST-277/ZST-283/ZST-286/ZST-288/ZST-297: `file_ticket` used to POST a
# failing check's raw captured output (stdout+stderr, `run_check`'s `2>&1`)
# with no redaction at all, then went through four sync rounds in a row
# each shipping a NEW total-bypass regex bug in the hand-patched sed/ERE
# rules that used to live here (a Laravel exception page or a dumped config
# array is exactly the shape that ends up verbatim on a DIFFERENT system —
# the board — with a different access list than the repo it came from).
#
# ZST-305 (epic REDACT/ZST-289) replaced the sed/ERE + awk implementation
# entirely with a standalone PHP tokenizer — same call site, different
# implementation language, not a new moving part. Full history, spec and
# test-case inventory: docs/redact-secrets-tokenizer-spec.md. The old
# hand-patched rules and their "caught in review" history lived inline here
# across ten review rounds; that history is now consolidated in the spec
# doc rather than duplicated in both places.
#
# Invoked as a bare `php` subprocess even outside Sail — owner decision
# 2026-09-07, ZST-305: the check output this redacts can itself be a
# failing Sail/Docker check, so redaction cannot depend on the thing it
# might be reporting as down. A narrow, explicit exception to "never run
# PHP on the host" (coding-standards.md), scoped to this one small,
# dependency-free utility script, never the application.
#
# Resolved relative to THIS file, not the caller's CWD — `bin/eod` sources
# this library from a fixed relative path, but nothing guarantees it's
# invoked from the repo root (a cron entry, an IDE run configuration, a
# subshell). `${BASH_SOURCE[0]}` is this file's own path regardless of CWD.
zekini_redact_secrets() {
  # One clear diagnostic up front, not a cryptic "exit 127" inside every
  # filed ticket (caught in review): a strictly Sail-only host may have no
  # host php at all. Non-zero here is what file_ticket() already treats as
  # "withhold the capture" — fail loud, never ship unredacted.
  if ! command -v php >/dev/null 2>&1; then
    echo "✗ zekini_redact_secrets: no host 'php' on PATH — bin/lib/redact-secrets.php needs one (ZST-305); output withheld." >&2
    return 127
  fi
  # A php that exists is not a php that is NEW ENOUGH (ZST-391, found by
  # Loupe): redact-secrets.php uses union types, str_ends_with and
  # preg_last_error_msg (all PHP 8.0), so a PHP 7 host parse-errors, exits 255,
  # and file_ticket() then replaces the whole capture with "redaction pass
  # failed (exit 255)" — nothing naming the version gap. On a Sail-only machine
  # (loupe's documented posture) every filed EOD card would lose its entire
  # capture that way. Say the actual problem, with a distinct status.
  # One probe serves both the check and the message ("80425:8.4.25"), so this
  # costs a single extra php start per ticket, not one to test and one to name.
  local php_probe php_id php_version
  php_probe="$(php -r 'echo PHP_VERSION_ID, ":", PHP_VERSION;' 2>/dev/null)"
  php_id="${php_probe%%:*}"
  php_version="${php_probe#*:}"
  if ! [[ "$php_id" =~ ^[0-9]+$ ]] || [ "$php_id" -lt 80000 ]; then
    echo "✗ zekini_redact_secrets: host php is ${php_version:-unknown}, older than the 8.0 bin/lib/redact-secrets.php needs (ZST-391); output withheld." >&2
    return 126
  fi
  local script_dir
  script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  php "${script_dir}/redact-secrets.php"
}

# ZST-1088: why a check failed, from its name and its output — the same rules
# as cause_of() in Fleet/eod.py, which reads them from this run's log. The
# 2026-10-28 run filed about ten cards for three causes, one per repo each:
#   hooks-drift, browser-env, missing-tool  the laptop's own state (machine)
#   deploy-visibility                       the EOD lane or template (lane)
#   suite-red, check-red                    the repo's own (repo)
# A here-string, not a pipe, feeds grep (zekini_env_find_line()'s race).
zekini_eod_cause() {
  local check="$1" output="${2:-}"
  if grep -qiE 'githooks|\bhooks?\b.*\bdrift|\bdrift\b.*\bhooks?\b' <<< "$check"; then
    # ZST-1094: hook-drift.sh says BEHIND (an earlier template revision) or ABSENT when the
    # template moved and the repo must re-sync — the repo's own commit, filed on its board;
    # "— DRIFTED" or a side it could not hash is this machine's state, central's to file once.
    if grep -qE 'BEHIND the template|— ABSENT' <<< "$output" && ! grep -qE '— DRIFTED|could not hash' <<< "$output"; then
      echo "hooks-behind"
    else
      echo "hooks-drift"
    fi
  elif grep -qiE 'release tag|\bdeployed\b' <<< "$check"; then
    echo "deploy-visibility"
  elif grep -qiE "executable doesn't exist|browserType\.launch|failed to launch|playwright install|host system is missing dependencies|chrome not reachable|session not created|DevToolsActivePort|cannot open display|no usable sandbox" <<< "$output" \
    && grep -qiE 'browser|e2e|playwright|dusk' <<< "$check
$output"; then
    echo "browser-env"
  elif grep -qiE "command not found|: not found\$|executable file not found|is not installed|no such file or directory: '?[[:alnum:]_./-]*/(bin|sbin)/" <<< "$output"; then
    echo "missing-tool"
  elif grep -qiE 'test|pest|phpunit|vitest|jest|e2e|browser|dusk|playwright|suite' <<< "$check"; then
    echo "suite-red"
  else
    echo "check-red"
  fi
}

# The laptop's own state, or the lane's, is not the repo's problem: central
# files it once — per machine, or once for every repo it shows on — on the
# standards board (ZST-1088, Fleet/eod.py plan_night).
zekini_eod_central_cause() {
  case "$1" in
    hooks-drift | browser-env | missing-tool | deploy-visibility) return 0 ;;
    *) return 1 ;;
  esac
}

# ZST-1111: the deployed-version check prints "Deployed X, expected Y" and fails
# on ANY difference. Production AHEAD of the expected version (0.128.3 vs 0.128.2)
# is a stale expectation, not "a release behind" — the site is fine — so it is
# never a card. Compared numerically, major.minor.patch (0.128.10 > 0.128.9);
# anything else (behind, equal, unparseable) answers 1 and files as before.
zekini_deploy_is_ahead() {
  local output="${1:-}"
  local re
  # Only the deploy check's own wording: a test log that happens to quote two versions is not it.
  # Two spellings exist: "release behind" + "Deployed X ... expected Y", and voc's
  # `ops:check-deployed-version`: "is serving A, but the build that should be live is B".
  if [[ "$output" == *"should be live"* ]]; then
    re='serving v?([0-9]+)\.([0-9]+)\.([0-9]+),? but the build that should be live is v?([0-9]+)\.([0-9]+)\.([0-9]+)'
  elif [[ "$output" == *"release behind"* ]]; then
    re='[Dd]eployed v?([0-9]+)\.([0-9]+)\.([0-9]+)[^0-9]+expected v?([0-9]+)\.([0-9]+)\.([0-9]+)'
  else
    return 1
  fi
  [[ "$output" =~ $re ]] || return 1
  local deployed expected i
  for i in 1 2 3; do
    deployed="${BASH_REMATCH[$i]}"
    expected="${BASH_REMATCH[$((i + 3))]}"
    if [ "$((10#$deployed))" -gt "$((10#$expected))" ]; then return 0; fi
    if [ "$((10#$deployed))" -lt "$((10#$expected))" ]; then return 1; fi
  done
  return 1
}

file_ticket() {
  local check="$1" output="$2"
  local title="[EOD][$REPO] $check failing" # stable — no date — so re-runs de-dupe
  if zekini_deploy_is_ahead "$output"; then
    echo "    (production is AHEAD of the expected version — a stale expectation, not a deploy behind; nothing wrong on the site, NOT filing: $title)"
    return
  fi
  local cause
  cause="$(zekini_eod_cause "$check" "$output")"
  # ZST-662: the key names the failing check, never the run (no date, sha or
  # version in it): a re-run is the same card wherever somebody moved it.
  # ZST-1088: and its cause, so a recurrence is recorded whatever the title.
  local dedupe_key="eod:$REPO:$check:$cause"

  if zekini_eod_central_cause "$cause"; then
    # Never straight onto this repo's board: one machine's drift used to be
    # one card per repo (KAD-2552, VOC-1493, PIG-88), the deploy check that
    # cannot see the deploy three (KAD-2554, VOC-1496, PUL-251). The "would
    # file" line is what central reads (Fleet/eod.py) to file it once.
    echo "    ($cause is the machine's or the lane's, not $REPO's alone — central files it once — would file: $title)"
    return
  fi

  if ! ticketing_ready; then
    # A blocked run used to tell whoever read the log "not configured" once
    # per failing check, when the real problem was a rejected credential.
    echo "    ($(zekini_ticketing_not_ready_reason) — would file: $title)"
    return
  fi

  if ! kadro_dedupes; then
    dedupe_key=""   # an older Kadro: today's read of the open cards' titles instead
    local classes=()
    mapfile -t classes < <(zekini_failing_test_classes "$output")
    card_exists "$title" "${classes[@]}"
    case "$?" in
      0)
        echo "    ↳ open card already exists, not duplicating: $title${classes[0]:+ (${classes[*]})}"
        return
        ;;
      1) ;;
      *)
        # LOU-1133: filing anyway on a failed read put 121 duplicate urgent
        # cards on Loupe's board. The check still fails, so the next run files
        # it once the board answers.
        echo "    ↳ could not check for an existing card — NOT filing, the next run checks again: $title"
        return
        ;;
    esac
  fi

  local body payload status response redacted redact_status
  # Redact BEFORE truncating — the secret can sit anywhere in the capture,
  # not just the tail, so redacting only the already-truncated 4000 bytes
  # would miss anything cut off earlier in the same output.
  redacted="$(printf '%s' "$output" | zekini_redact_secrets)"
  redact_status=$?

  # ZST-283: a failed redaction pass used to silently ship whatever
  # `$redacted` happened to hold — typically empty, an unlabeled blank code
  # block giving no hint the capture was ever lost. Fail loud and withhold
  # the content instead, never post output that skipped redaction for an
  # unknown reason.
  #
  # ZST-305: `zekini_redact_secrets` is now a single `php` subprocess call,
  # not an internal pipe of its own — `redact_status` is directly that
  # process's exit code, no `pipefail` dependency to reason about for THIS
  # capture (a two-stage `printf | zekini_redact_secrets` pipe already
  # exposes its last command's status via bare `$?` regardless of
  # `pipefail`). Kept as its own paragraph rather than deleted outright:
  # this exact comment has already gone stale once before, when the
  # function's own internals changed shape — worth re-reading against the
  # function's CURRENT body before trusting either version of this claim.
  if [ "$redact_status" -ne 0 ]; then
    redacted="[redaction pass failed (exit ${redact_status}) — output withheld rather than risk an unredacted or silently empty capture]"
  fi

  body="$(printf 'Auto-filed by bin/eod on %s.\n\n```\n%s\n```' "$DATE" "$(printf '%s' "$redacted" | tail -c 4000)")"
  # ZST-487: priority high, position 0 (top of the column), into TICKET_LIST —
  # To Do, see zekini_resolve_ticket_list().
  payload="$(jq -n --arg t "$title" --arg d "$body" --arg k "$dedupe_key" \
    '{title: $t, description: $d, priority: "high", position: 0}
      + (if $k == "" then {} else {dedupe_key: $k} end)')"

  # The body comes back too: a key Kadro already holds an open card for
  # answers that card with `deduplicated: true`, not a new one.
  local card_id
  response="$(curl -s -w '\n%{http_code}' --max-time 20 \
    -X POST "$TICKET_API_URL/boards/$BOARD/lists/$TICKET_LIST/cards" \
    -H "Authorization: Bearer $AUTH_TOKEN" -H "Accept: application/json" \
    -H "Content-Type: application/json" -d "$payload" 2>/dev/null)"
  status="${response##*$'\n'}"
  [[ "$status" =~ ^[0-9]{3}$ ]] || status="000"

  case "$status" in
    2*)
      if printf '%s' "${response%$'\n'*}" | jq -e '[.. | objects | select(.deduplicated == true)] | length > 0' \
        >/dev/null 2>&1; then
        # The existing card stays where somebody put it: not moved to the top.
        echo "    ↳ open card already exists, Kadro counted the recurrence: $title"
      else
        echo "    ↳ filed ticket in $TICKET_LIST_NAME, priority high: $title"
        card_id="$(printf '%s' "${response%$'\n'*}" | jq -r '(.data // .) | (.ulid // .id // empty) | tostring' 2>/dev/null)"
        if [ -n "$card_id" ]; then
          zekini_card_to_top "$card_id"
        else
          echo "    ↳ the board did not return the new card's id — not moved to the top of $TICKET_LIST_NAME"
        fi
      fi
      ;;
    *) echo "    ↳ FAILED to file ticket — the board answered HTTP $status: $title" ;;
  esac
}

# A passing check's own stdout+stderr is discarded, not printed — a green
# nightly run must not bury the "read the summary at the end" banner under
# the full output of composer test / npm test / a Lighthouse budget run.
# ZEKINI_EOD_SKIP_PREFIX is the one carve-out: a check that passed by
# SKIPPING (standards_snippet() when the checkout is missing) rather than by
# actually running must still surface that reason, or a skip reads as
# verified-clean indefinitely. Found by Loupe's adversarial review on
# LOU-809 (2026-09-07): the previous condition was "did the check print
# anything at all", which is true for nearly every real passing check,
# so a fleet-wide adoption silently changed every green run into a wall of
# dumped output — a real behaviour change the per-repo inline copies never
# had.
ZEKINI_EOD_SKIP_PREFIX="ZEKINI_EOD_SKIP: "

run_check() {
  local name="$1"
  shift
  echo "▶ $name…"
  local out
  if out="$("$@" 2>&1)"; then
    echo "  ✓ $name"
    case "$out" in
      "$ZEKINI_EOD_SKIP_PREFIX"*) echo "    ${out#"$ZEKINI_EOD_SKIP_PREFIX"}" ;;
    esac
  else
    echo "  ✗ $name"
    fail_count=$((fail_count + 1))
    log_failure "$name" "$out"
    file_ticket "$name" "$out"
  fi
}

# VOC-1494 (2026-10-28): a failing check's capture reached only file_ticket, so a
# run with ticketing off — every eod Fleet runs: central files the cards itself
# from the ✗ lines — logged "✗ php artisan test" and threw the output away. The
# card central filed sent the coder to eod-cron.log, which held nothing, and the
# coder stopped for a person. The redacted tail goes in the log on EVERY failure,
# ticket or not. Each line is prefixed so Fleet's parser (eod.py: a line that IS
# "✗ <check>") never reads a ✗ inside a suite's own output as another check.
log_failure() {
  local name="$1" output="$2" redacted lines="${ZEKINI_EOD_LOG_TAIL_LINES:-80}"
  if redacted="$(printf '%s\n' "$output" | zekini_redact_secrets 2>/dev/null)"; then
    echo "    ── last $lines line(s) of $name ──"
    printf '%s\n' "$redacted" | tail -n "$lines" | sed 's/^/    │ /'
  else
    echo "    (output withheld from the log: the redaction pass failed, exit $?)"
  fi
}

# ZST-444 (found by Loupe, LOU-940): run_check took no machine-wide test lock,
# so the 16:00 cron's `run_check "tests" ./vendor/bin/sail composer test`
# could start while an interactive push's suite was already running in the
# SAME container — two full suites at once, on the same test databases, the
# deadlock/OOM shape the fleet test queue (snippets/fleet-test-lock.sh,
# ZST-258/332) exists to stop. pre-push already routes every backend stage
# and the browser step through that queue; bin/eod had no way to join it.
#
# run_test_check is run_check for a HEAVY check (a test suite, a browser or
# Lighthouse run): same arguments, same ticketing, but the command waits its
# turn in the SAME shared queue directory pre-push uses, so an eod suite and
# a push suite serialize instead of overlapping, in whichever order they
# arrived. Light checks (drift, audit, triage) stay on plain run_check — they
# don't compete for the stack and must not wait behind someone's suite.
#
# The queue script is resolved in order: the repo's own synced copy
# (.githooks/lib/fleet-test-lock.sh, what pre-push uses), then the standards
# checkout's snippets/fleet-test-lock.sh — the queue is a shared directory,
# not the script, so either copy queues in the same place. With neither, the
# check runs unqueued with a warning printed OUTSIDE run_check's capture: a
# passing check's output is discarded, so a warning inside it would vanish.
#
# fleet-test-lock.sh execve()s its command via `timeout`, which cannot find a
# shell function — the same STOP-SHIP pre-push's zekini_test_lock_run hit
# with sail_run. A function passed here is exported for the child `bash -c`
# for the duration of the call; anything THAT function calls in turn must be
# an external command or exported by the caller, and so must every shell
# variable it reads — the child is a new bash process.
#
# ⚠️ Never pass a command that itself goes through the queue (pre-push, or a
# composer script that wraps fleet-test-lock.sh): it would take a second,
# later ticket behind the one this call is holding and wait on itself until
# ZEKINI_TEST_LOCK_WAIT_TIMEOUT.
zekini_eod_test_lock_lib() {
  local candidate
  for candidate in ".githooks/lib/fleet-test-lock.sh" "${STANDARDS_DIR}/snippets/fleet-test-lock.sh"; do
    if [ -f "$candidate" ]; then
      printf '%s' "$candidate"
      return 0
    fi
  done
  return 1
}

run_test_check() {
  local name="$1"
  shift
  local lock_lib
  if ! lock_lib="$(zekini_eod_test_lock_lib)"; then
    echo "⚠ $name: no fleet-test-lock.sh (.githooks/lib/ or ${STANDARDS_DIR}/snippets/) — running WITHOUT the machine-wide test queue; it may overlap a push's suite (re-sync from standards, ZST-444)."
    run_check "$name" "$@"
    return
  fi

  local exported_fn=""
  if [ "$(type -t "$1")" = "function" ]; then
    exported_fn="$1"
    export -f "$exported_fn"
  fi
  run_check "$name" bash "$lock_lib" -- bash -c '"$@"' bash "$@"
  if [ -n "$exported_fn" ]; then
    export -fn "$exported_fn"
  fi
}

# A missing standards checkout must SKIP, not fail: `bash` on a path that
# doesn't exist exits 127 either way, and run_check can't tell that apart
# from the check itself finding something wrong — it would file a card about
# a shell error and call it "hook drift"/"orphan check" failing.
standards_snippet() {
  local name="$1"
  local path="${STANDARDS_DIR}/snippets/${name}"
  if [ ! -f "$path" ]; then
    echo "${ZEKINI_EOD_SKIP_PREFIX}standards checkout not found at ${STANDARDS_DIR} (set ZEKINI_STANDARDS_DIR) — skipping"
    return 0
  fi
  # ZST-194: repo policy from .githooks/config reaches the snippet. Before
  # this, a snippet run from bin/eod saw NONE of that file — only pre-push
  # sources it wholesale — so check-domain-health.sh's ZEKINI_XNEELO_DOMAIN
  # (its own documented "set on any Xneelo-hosted app" knob) had no path in
  # and every Xneelo repo's "domain health" check fell into the Laravel
  # Cloud branch and failed misleadingly on every run. Exported as
  # env-prefix assignments on the child only — nothing leaks into this
  # shell — and a value already in the environment wins, same precedence
  # pre-push gives an exported override.
  #
  # ZST-330 (Loupe's LOU-831 review): ZEKINI_EOD_SKIP_PREFIX goes too. It is
  # set above without `export`, so a snippet that skipped (error-log-triage.sh
  # on a box with no readable log) printed its OWN default prefix, run_check's
  # case never matched, and the skip surfaced as a bare green tick with the
  # reason swallowed — the exact "a skip reads as verified-clean" this
  # carve-out exists to prevent. And every knob a snippet documents as a
  # .githooks/config setting must actually be forwarded, or setting it per
  # the snippet's own header does nothing, silently: the ZEKINI_ERRLOG_*
  # trio for error-log-triage.sh. Empty is fine — each snippet's `${X:-…}`
  # default covers empty and unset alike.
  local zekini_cfg_names=(ZEKINI_PROD_URL ZEKINI_XNEELO_DOMAIN ZEKINI_ERRLOG_HOURS ZEKINI_ERRLOG_MAX ZEKINI_ERRLOG_PATH)
  local zekini_cfg_values=()
  mapfile -t zekini_cfg_values < <(zekini_hook_config_values "${zekini_cfg_names[@]}")
  ZEKINI_EOD_SKIP_PREFIX="$ZEKINI_EOD_SKIP_PREFIX" \
  ZEKINI_PROD_URL="${ZEKINI_PROD_URL:-${zekini_cfg_values[0]:-}}" \
  ZEKINI_XNEELO_DOMAIN="${ZEKINI_XNEELO_DOMAIN:-${zekini_cfg_values[1]:-}}" \
  ZEKINI_ERRLOG_HOURS="${ZEKINI_ERRLOG_HOURS:-${zekini_cfg_values[2]:-}}" \
  ZEKINI_ERRLOG_MAX="${ZEKINI_ERRLOG_MAX:-${zekini_cfg_values[3]:-}}" \
  ZEKINI_ERRLOG_PATH="${ZEKINI_ERRLOG_PATH:-${zekini_cfg_values[4]:-}}" \
    bash "$path" .
}

# Reads ONE variable's value out of .githooks/config by sourcing it the way
# prod_url_from_hook_config() does (same subshell, same stdout discard, same
# unset-first so an exported value is never mistaken for the file's). Empty/
# unset → status 1, nothing printed. Kept as a standalone single-value
# reader (own tested contract, snippets/eod-lib*.test.sh may call it
# directly) — standards_snippet() no longer does, see
# zekini_hook_config_values() below. Not a replacement for
# prod_url_from_hook_config(), which keeps its own exit-status contract and
# history.
zekini_hook_config_value() {
  local name="$1" value
  [ -f .githooks/config ] || return 1
  value="$(unset "$name"; . .githooks/config >/dev/null; printf '%s' "${!name:-}")"
  [ -n "$value" ] || return 1
  printf '%s' "$value"
}

# Reads MULTIPLE .githooks/config knobs in a single source pass. Before
# ZST-336 (found by Mail), standards_snippet() called out to
# zekini_hook_config_value()/prod_url_from_hook_config() once PER forwarded
# knob — five separate `. .githooks/config` sources per standards_snippet()
# call: five subprocess forks re-parsing the identical file, and (ZST-326)
# five separate chances for a stray stdout-producing line in that file to
# corrupt a forwarded value instead of one. Same unset-first-per-name and
# stdout-discard discipline as the two single-value functions above; prints
# one line per requested name, in the order given, empty for a name the
# file doesn't set — the caller pairs values back up by position, via
# `mapfile`. Missing .githooks/config → status 1, nothing printed, same as
# zekini_hook_config_value(). Not a replacement for either single-value
# function — see their own docblocks — this exists only for a caller that
# wants several knobs from ONE source pass, which today is only
# standards_snippet().
zekini_hook_config_values() {
  [ -f .githooks/config ] || return 1
  local names=("$@") name
  (
    for name in "${names[@]}"; do
      unset "$name"
    done
    . .githooks/config >/dev/null
    for name in "${names[@]}"; do
      printf '%s\n' "${!name:-}"
    done
  )
}

# The production origin the deployed-tag check asks, read from the SAME
# committed policy `pre-push` reads (card 764) rather than duplicated here —
# two places naming a production URL is two places to get it wrong. Only
# meaningful to a repo that has app/Console/Commands/ReleaseTag.php; the
# caller's own check list decides whether to use this.
prod_url_from_hook_config() {
  [ -f .githooks/config ] || return 0
  # ⚠️ Caught in review, twice: this used to grep/sed-parse the file,
  # trying to approximate bash's own quote- and comment-handling rules by
  # hand — first missing comment-stripping entirely (pre-push, which reads
  # this file by SOURCING it, drops a trailing `# comment` there; this
  # function's grep/sed didn't), then, once a comment-strip was added,
  # getting it wrong for a QUOTED value containing an internal space+`#`
  # (the strip ate part of the quoted string, then the quote-strip's
  # backreference failed to match the now-unpaired quote — verified live,
  # a real regression against even the PRE-ZST-321 code, which handled
  # that shape correctly by accident). Every one of these was a NEW
  # bash-tokenizing edge case this file's own regex had to catch up to,
  # with no end in sight — the actual fix is to stop reimplementing bash's
  # parser and just USE it, exactly like pre-push does. Sourced in a
  # subshell (command substitution already provides one) so nothing set by
  # .githooks/config — this var or any other repo-policy knob it carries —
  # leaks into this function's own caller; `unset` first so an ALREADY-
  # exported ZEKINI_PROD_URL from outside doesn't get mistaken for a value
  # this file actually set (the same shadowing concern zekini_shadow_of()
  # exists to catch elsewhere, just guarded inline here since this is the
  # only var this function cares about). Errors are NOT suppressed
  # (no `2>/dev/null`) — pre-push doesn't suppress them either when it
  # sources this same file, and silencing a genuine syntax error here
  # would be exactly the "same file, different behavior in different
  # readers" problem this whole rewrite exists to close.
  #
  # A further, deliberate side effect: if ZEKINI_PROD_URL= appears more
  # than once in the file, the LAST assignment now wins — that's what
  # sourcing genuinely does, and it's what pre-push has always actually
  # done too. The old grep/head -n1 approach picked the FIRST match
  # instead, which was itself a latent inconsistency with pre-push this
  # rewrite happens to close, not something being newly introduced.
  #
  # ⚠️ Investigated in review, not a new problem: an UNQUOTED value
  # containing a space (`ZEKINI_PROD_URL=https://x.example.com staging`,
  # a plausible authoring mistake) parses as a prefix-assignment applied to
  # a one-off command (`staging`), which fails with "command not found" on
  # stderr and leaves ZEKINI_PROD_URL unset — a bigger visible failure than
  # the old grep/cut/tr version's silent "https://x.example.com staging"
  # (wrong, but a recoverable prefix). Verified live: pre-push's OWN
  # sourcing of the identical malformed line produces the EXACT same
  # "command not found" + unset result — this is true parity with the
  # thing this rewrite exists to match, not a regression it introduces;
  # fixing it here would mean diverging from pre-push again, the opposite
  # of the goal.
  #
  # ⚠️ Also investigated: sourcing .githooks/config here does mean bin/eod
  # (unattended, and already holding a live OAuth token in memory from
  # acquire_auth() earlier in eod_preamble()) now executes it as real shell
  # code, not just pre-push (interactive, at push time). Not a NEW trust
  # boundary — .githooks/config is already committed, reviewed repo policy
  # that pre-push has always sourced the same way — but it is a wider BLAST
  # RADIUS for the same trust, worth knowing rather than assuming away.
  # DECIDED 2026-09-07 (ZST-325, option A): accepted as-is, no sandboxing.
  # .githooks/config is governed by the repo's review/PR gate like every
  # other committed file, and pre-push already sources it in an
  # unattended-adjacent context (a developer pushing isn't reviewing the
  # sourcing itself). A restricted subshell (`env -i`, stripped PATH)
  # would add real complexity to a read-one-value function and still not
  # close pre-push's identical exposure; reverting to hand-parsing reopens
  # the exact three-regression bug class this rewrite closed.
  #
  # ⚠️ Also investigated: a CRLF-terminated `ZEKINI_PROD_URL=...\r\n` line
  # leaves a trailing CR attached to the resolved value — every other
  # .env-driven value in this file gets that stripped (zekini_env_value()).
  # Verified live: pre-push's OWN sourcing of the identical CRLF line
  # produces the EXACT same trailing-CR result. Same reasoning as the
  # unquoted-space case above — this is parity with what this rewrite
  # exists to match, not a gap it introduces; stripping it here would mean
  # this function and pre-push disagreeing about the SAME file again, the
  # opposite of the goal. A real robustness gap, but pre-push's, not this
  # function's alone to fix — out of scope for ZST-321.
  #
  # 🔥 ZST-326 (found by loupe, LOU-823's review panel, tracked as LOU-824):
  # the command substitution captures EVERYTHING the subshell writes to
  # stdout, not just the trailing printf — any output-producing line in
  # .githooks/config (a stray `echo`, a command run for a side effect that
  # also prints) silently corrupted the resolved URL at exit 0, and no
  # caller validates the shape, so a multi-line value flowed into every
  # PROD_URL-driven post-release check with nothing naming the cause. A
  # genuine regression of the sourcing rewrite, not a pre-existing gap: the
  # old grep/sed read the file as TEXT, immune to what other lines DID.
  # The source's stdout is discarded; only the printf reaches the capture.
  # stderr is still NOT suppressed — see above. pre-push's own sourcing of
  # this file (its "Repo policy, committed" section) is a bare `.`, never
  # inside a substitution, so stray output there just prints; no
  # equivalent fix needed on that side.
  local url
  url="$(unset ZEKINI_PROD_URL; . .githooks/config >/dev/null; printf '%s' "${ZEKINI_PROD_URL:-}")"
  # ⚠️ ROLLOUT NOTE, caught in review: this function's exit-status contract
  # changed under this ticket (ZST-321) — the pre-321 version (and every
  # version through round 7) always returned 0 once .githooks/config
  # existed, regardless of whether ZEKINI_PROD_URL was actually set,
  # because its last pipe stage basically always succeeds. Round 8 added
  # `return 1` when the value is absent/empty, matching env_get()'s own
  # established convention — the more consistent, more useful contract,
  # but a genuine behavior change. This file is kept byte-identical and
  # synced across every adopting repo (kadro, mail, whatsapp, loupe, voc):
  # if any of them call this with `if url=$(prod_url_from_hook_config);
  # then …` and lean on the old always-0 behavior, that caller's behavior
  # changes silently the next time this template gets synced in. Worth an
  # explicit check across adopting repos' own check lists at that sync
  # point, not just a byte-diff.
  [ -n "$url" ] || return 1
  printf '%s' "$url"
}

# Prints the repo/date header and every ticketing diagnostic that does not
# depend on which checks the caller is about to run, and resolves auth. Call
# once, right after sourcing this file, before the caller's own run_check
# calls.
eod_preamble() {
  echo "== bin/eod — $REPO ($DATE) =="

  # ZST-324: the one case that used to be silent — a client id configured
  # (so the operator clearly intended the machine credential) but no secret
  # reachable from `.env` OR the separate file. Before, machine_ready()
  # simply returned false and the run fell through to TASKBOARD_TOKEN with
  # nothing pointing at the mismatch; an operator who had followed
  # fleet-infra.md's `cat file >> .env` handoff to the letter saw the same
  # output as one who had configured nothing. Named here, before
  # acquire_auth, so it prints even when a TASKBOARD_TOKEN fallback then
  # succeeds and masks it.
  if zekini_machine_secret_missing; then
    echo ""
    echo "⚠️  KADRO_MACHINE_CLIENT_ID is set but no secret is reachable — ticketing will NOT use the machine credential."
    echo "    Put KADRO_MACHINE_CLIENT_SECRET in .env (fleet-infra.md's cross-session handoff lands it there:"
    echo "    cat file >> .env), or point KADRO_MACHINE_SECRET_FILE at a mode-600 file. Falling back to"
    echo "    TASKBOARD_TOKEN if set."
    echo ""
  fi

  acquire_auth

  if ! ticketing_ready; then
    local missing
    missing=""
    [ -n "$API_URL" ] || missing="$missing TASKBOARD_API_URL"
    [ -n "$AUTH_TOKEN" ] || missing="$missing TASKBOARD_TOKEN(or KADRO_MACHINE_CLIENT_ID+KADRO_MACHINE_CLIENT_SECRET-in-.env-or-KADRO_MACHINE_SECRET_FILE)"
    [ -n "$BOARD" ] || missing="$missing TASKBOARD_BOARD"
    [ -n "$LIST" ] || missing="$missing TASKBOARD_LIST"
    command -v curl >/dev/null 2>&1 || missing="$missing curl(not-installed)"
    command -v jq >/dev/null 2>&1 || missing="$missing jq(not-installed)"

    echo ""
    echo "⚠️  TICKETING IS OFF — nothing this run finds will be filed."
    echo "    Missing:$missing"
    echo "    Set it in .env (see .env.example — everything but the token is already"
    echo "    spelled out there). Checks still run; failures are printed only, so read"
    echo "    the summary at the end before closing this terminal."
    echo ""
  fi

  if ticketing_ready; then
    local probe_status
    probe_status="$(ticketing_probe)"

    case "$probe_status" in
      2*) ;;
      *)
        TICKETING_BLOCKED="yes"
        echo ""
        echo "⚠️  TICKETING IS CONFIGURED, but the board rejected this runner (HTTP $probe_status)."
        echo "    Nothing this run finds will be filed."
        echo "    401     — the credential is dead or wrong."
        echo "    403     — the credential authenticates and is refused board work. A"
        echo "              super-user token is forbidden this by design — mint an"
        echo "              ordinary user token that is a member of this repo's board,"
        echo "              or use the machine credential (card 1013)."
        echo "    404     — the board or list id does not exist on this host."
        echo "    000     — the board did not answer at all."
        echo "    Checks still run; read the summary at the end before closing this terminal."
        echo ""
        ;;
    esac
  fi

  if ticketing_ready; then
    zekini_resolve_ticket_list
  fi

  # ZST-248: gated on zekini_ticketing_configured — TOKEN_SOURCE is set
  # purely on whether TASKBOARD_TOKEN happens to be an exported shell var
  # (above), independent of whether API_URL/BOARD/LIST are configured at
  # all. Without this guard, a dev with a stray `export TASKBOARD_TOKEN=...`
  # left over from something unrelated, but ticketing otherwise fully
  # unconfigured, got told to "put it in .env" — advice about a token that
  # isn't the actual blocker.
  #
  # ⚠️ Caught in review: an earlier version of this fix gated on the FULL
  # ticketing_ready() instead, which also folds in TICKETING_BLOCKED — so
  # the warning went silent exactly when the board had REJECTED this runner
  # (a stale credential, say), hiding the one actionable clue (this token
  # only lives in your shell) at the moment it matters most.
  # zekini_ticketing_configured checks only the static config fields, so
  # this fires whenever ticketing is genuinely set up, regardless of
  # whether the board has separately accepted or rejected the runner.
  # ZST-277: was TASKBOARD_TOKEN-only, looped now so BOARD/LIST get the same
  # two warnings from zekini_shadow_of's three computed pairs above instead
  # of two more near-identical `if` blocks per var.
  # ZST-356: extended to the three Kadro machine-credential vars, same
  # zekini_shadow_of pairs computed above — same resolve() precedence, same
  # "which copy actually wins" confusion, so the same two warnings apply.
  local cfgvar src shadowed
  for cfgvar in TASKBOARD_TOKEN TASKBOARD_BOARD TASKBOARD_LIST \
    KADRO_MACHINE_CLIENT_ID KADRO_MACHINE_CLIENT_SECRET KADRO_MACHINE_SECRET_FILE; do
    case "$cfgvar" in
      TASKBOARD_TOKEN) src="$TOKEN_SOURCE"; shadowed="$TOKEN_SHADOWED" ;;
      TASKBOARD_BOARD) src="$BOARD_SOURCE"; shadowed="$BOARD_SHADOWED" ;;
      TASKBOARD_LIST) src="$LIST_SOURCE"; shadowed="$LIST_SHADOWED" ;;
      KADRO_MACHINE_CLIENT_ID) src="$MACHINE_CLIENT_ID_SOURCE"; shadowed="$MACHINE_CLIENT_ID_SHADOWED" ;;
      KADRO_MACHINE_CLIENT_SECRET) src="$MACHINE_CLIENT_SECRET_SOURCE"; shadowed="$MACHINE_CLIENT_SECRET_SHADOWED" ;;
      KADRO_MACHINE_SECRET_FILE) src="$MACHINE_SECRET_FILE_SOURCE"; shadowed="$MACHINE_SECRET_FILE_SHADOWED" ;;
    esac

    if [ "$src" = "shell" ] && zekini_ticketing_configured; then
      echo ""
      echo "⚠️  $cfgvar is coming from an EXPORTED SHELL VARIABLE, not .env."
      echo "    Only this terminal carries it. A cron entry, a fresh shell, another user"
      echo "    or a CI runner has none — and that run looks identical to this one."
      echo "    Put it in .env (chmod 600) and drop the export from your shell profile."
      echo ""
    fi

    if [ "$shadowed" = "yes" ]; then
      echo ""
      echo "ℹ️  An exported $cfgvar is being IGNORED — .env carries its own and wins."
      echo "    Drop the export so there is one copy, in .env."
      echo ""
    fi
  done
}

# An unattended run against a stopped stack must skip, not report: every check
# below would fail for the same dull reason, filing one bogus card each. A
# skip deliberately does NOT stamp the heartbeat — a machine whose stack has
# been down for a week must read as "eod has not run", which the pre-push
# staleness warning then surfaces. Exits the whole script (not a normal
# return) — nothing past this point can run against a stopped stack.
#
# ⚠️ It checks the app service (**laravel.test**) specifically, not "Up" anywhere in
# the output. Matching the bare word passed whenever ANY service was up — so a
# crashed laravel.test beside a healthy pgsql read as a running stack, and every
# check after it then failed with a raw "docker compose exec: no such container"
# instead of this clear skip. Those raw errors were redacted and filed as cards,
# as if they were real lint or test failures. Reported by the Help session,
# 2026-10-20, found while scaffolding fleet-help.
#
# The service is APP_SERVICE, defaulting to laravel.test: a repo that renames
# its app service would otherwise read as "stack not running" on every run and
# skip every check, silently (ZST-969). It is read through resolve(), `.env`
# first and the exported variable as fallback, because Sail sources `.env` and
# that is where a repo sets it; reading only the environment missed it there
# and brought the silent skip back (ZST-971).
eod_require_stack_up() {
  local sail="$1" service pattern
  service="$(resolve APP_SERVICE)"
  service="${service:-laravel.test}"
  # A compose service name is [a-zA-Z0-9._-], so "." is the only regex
  # metacharacter it can carry; escaping it makes the name match literally.
  pattern="${service//./\\.}"
  # `Up` as a whole word too, as docker prints it in STATUS — a bare substring
  # match would also hit a project or image name that happens to contain "up".
  if ! "$sail" ps 2>/dev/null | grep -E "(^|[[:space:]_-])${pattern}([[:space:]]|\$)" \
       | grep -qE '(^|[[:space:]])Up([[:space:]]|$)'; then
    echo ""
    echo "⚠️  The Sail stack is not running ($service is not Up) — skipping every check."
    echo "    Start it (./vendor/bin/sail up -d) and re-run, or let the next scheduled"
    echo "    run catch it. The heartbeat is NOT stamped for a skip."
    echo ""
    echo "== bin/eod done — skipped, stack down =="
    exit 0
  fi
}

# Prints the closing summary and stamps the heartbeat. Call once, after every
# run_check in the caller's own check list has run.
eod_finish() {
  echo "== bin/eod done — $fail_count check(s) failed =="

  if [ "$fail_count" -gt 0 ] && ! ticketing_ready; then
    if [ "$TICKETING_BLOCKED" = "yes" ]; then
      echo "⚠️  $fail_count failure(s) above and nothing was filed — $(zekini_ticketing_not_ready_reason) (see the warning near the top of this run)."
      echo "    Nobody will see these tomorrow. Act on them now, or fix the credential/board id."
    else
      echo "⚠️  $fail_count failure(s) above and nothing was filed — $(zekini_ticketing_not_ready_reason)."
      echo "    Nobody will see these tomorrow. Act on them now, or configure TASKBOARD_*."
    fi
  fi

  # Stamped only here: a completed pass over every check, whatever it found.
  # On entry it would read a run that died halfway as a run that happened; on
  # the stack-down skip above it would read a week of down-stack as a week of
  # green. The pre-push hook warns when this file goes stale.
  mkdir -p storage/logs
  printf '%s fail_count=%s\n' "$(date -Iseconds)" "$fail_count" >storage/logs/eod-heartbeat
}
