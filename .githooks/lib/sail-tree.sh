#!/usr/bin/env bash
#
# Canonical Zekini hook library (from zekini-com/standards): make the git hooks
# check the tree being committed or pushed, not whatever happens to be
# bind-mounted at /var/www/html.
#
# ## The problem this exists for
#
# Every gate in the Zekini hooks runs through Sail, so `sail composer lint`
# becomes `docker compose exec laravel.test composer lint`. `docker compose exec`
# starts in the container's WORKDIR — /var/www/html — which is the checkout the
# stack was started from. Push from a second worktree that shares that stack and
# the gate silently measures a DIFFERENT tree.
#
# That is worse than having no gate. In the incident that produced this file it
# failed an honest branch on two errors belonging to another session's
# uncommitted work, and it would just as happily have passed a broken branch
# whose defects were absent from the main checkout.
#
# Any repo whose hooks run gates through Sail has this hole. It is not specific
# to the repo that found it — which is why it lives here.
#
# ## The fix
#
# Ask docker where /var/www/html actually comes from, work out where the current
# tree sits inside it, and run every gate with the container's working directory
# set there. If the current tree is not under that mount at all, REFUSE — a hook
# that cannot see the code it is judging must not return a verdict on it.
#
# ## Using it
#
# Copy this file to `.githooks/lib/sail-tree.sh` alongside the hooks, then in
# each hook:
#
#   . "$(dirname "${BASH_SOURCE[0]}")/lib/sail-tree.sh"
#   zekini_require_tree || exit 1
#   sail_run composer lint          # instead of "$sail" composer lint
#
# Source it relative to the script rather than via `git rev-parse`: hooks are
# also run directly (and copied into sandboxes by their own tests), where there
# is no repository to ask.

# Where the app container's /var/www/html comes from on the host, or nothing.
zekini_mount_root() {
  local service="${APP_SERVICE:-laravel.test}" container

  command -v docker >/dev/null 2>&1 || return 1

  container="$(docker compose ps -q "$service" 2>/dev/null)" || return 1
  [ -n "$container" ] || return 1

  docker inspect \
    -f '{{range .Mounts}}{{if eq .Destination "/var/www/html"}}{{.Source}}{{end}}{{end}}' \
    "$container" 2>/dev/null
}

# Map a host directory to its path inside the container.
#
# Pure — takes the mount root and the directory, touches nothing else — so the
# mapping is unit-testable without docker. The reference implementation tests it
# in tests/Feature/GitHookTreeTest.php; a repo adopting this should port that
# test with it, since this function is the part that can be subtly wrong.
#
#   zekini_container_dir /repo /repo                → /var/www/html
#   zekini_container_dir /repo /repo/a/b            → /var/www/html/a/b
#   zekini_container_dir /repo /elsewhere           → (exit 1)
#
# A trailing slash on either argument is tolerated; callers get it from
# `docker inspect` and from `pwd` on a root-level checkout.
zekini_container_dir() {
  local root="${1%/}" dir="${2%/}"

  [ -n "$root" ] || return 1

  if [ "$dir" = "$root" ]; then
    echo "/var/www/html"
    return 0
  fi

  case "$dir" in
    "$root"/*)
      echo "/var/www/html/${dir#"$root"/}"
      return 0
      ;;
  esac

  return 1
}

# ZST-776: the host git directories a LINKED worktree's app container must
# mount for git to work inside it, one per line; nothing for a primary checkout.
#
# In a linked worktree `.git` is a FILE naming `<repo>/.git/worktrees/<name>`
# on the host. The container mounts only the tree at /var/www/html, so without
# these `git status` in it exits 128 ("not a git repository") — and every
# check that asks git what is unstaged (Kadro's UnstagedPaths behind `composer
# lint`, KAD-2214) fails on every card. Mount each path at the SAME absolute
# path inside the container, read-write: git writes `index.lock` even for
# `status`. `zekini_git_compose_override` prints the compose override that does.
#
# The paths are read the way git inside the container reads them — the `.git`
# file's `gitdir:` line, then that dir's `commondir` — and folded lexically
# (`cd` + `pwd -L`), never through symlinks: the container is asked for exactly
# the path the `.git` file names. The common dir always; the worktree's own
# git dir too only when it lies outside the common one.
#
#   zekini_git_mounts /repo                         → (nothing: .git is a folder)
#   zekini_git_mounts /trees/KAD-1/repo             → /repo/.git
zekini_git_mounts() {
  local tree="${1:-.}" line="" own common raw=""

  [ -f "$tree/.git" ] || return 0
  IFS= read -r line < "$tree/.git" || [ -n "$line" ] || return 1
  case "$line" in
    gitdir:*) line="${line#gitdir:}"; line="${line#"${line%%[![:space:]]*}"}" ;;
    *) return 1 ;;
  esac
  own="$(cd "$tree" 2>/dev/null && cd -L "$line" 2>/dev/null && pwd -L)" || return 1
  if [ -f "$own/commondir" ]; then
    IFS= read -r raw < "$own/commondir" || true
  fi
  if [ -n "$raw" ]; then
    common="$(cd -L "$own" 2>/dev/null && cd -L "$raw" 2>/dev/null && pwd -L)" || return 1
  else
    common="$own"
  fi

  echo "$common"
  case "$own" in
    "$common" | "$common"/*) ;;
    *) echo "$own" ;;
  esac
}

# The compose override (YAML) that bind-mounts zekini_git_mounts' paths into the
# app service at the same path, read-write; nothing for a primary checkout.
# Write it OUTSIDE the tree (`git status` would list it, and it is host-specific)
# and add it to COMPOSE_FILE in the worktree's .env, e.g.:
#
#   zekini_git_compose_override . > "$(git rev-parse --absolute-git-dir)/compose.override.yml"
#   COMPOSE_FILE=docker-compose.yml:<that path>        # in the worktree's .env
#
# Fleet writes the same override for its own worktrees (Fleet/gitmount.py).
zekini_git_compose_override() {
  local tree="${1:-.}" service="${2:-${APP_SERVICE:-laravel.test}}" mounts path

  mounts="$(zekini_git_mounts "$tree")" || return 1
  [ -n "$mounts" ] || return 0

  printf 'services:\n  "%s":\n    volumes:\n' "$service"
  while IFS= read -r path; do
    printf '      - type: bind\n        source: "%s"\n        target: "%s"\n        read_only: false\n' \
      "$path" "$path"
  done <<< "$mounts"
}

# Resolve SAIL_CWD for this tree, or explain precisely why we will not proceed.
#
# Refusing is the point. The previous behaviour — carry on and check
# /var/www/html regardless — is what let a worktree's push be judged against
# another branch.
zekini_require_tree() {
  local root here

  here="$(pwd -P)"
  root="$(zekini_mount_root)"

  if [ -z "$root" ]; then
    echo "✗ Cannot find the Sail app container for this directory." >&2
    echo "  The hooks run every gate inside it, so without it they cannot check anything." >&2
    echo "  A worktree needs its own .env before docker compose can find the stack:" >&2
    echo "    cp <main checkout>/.env .env   # then give it unused ports" >&2
    echo "  and start it with ./vendor/bin/sail up -d." >&2
    return 1
  fi

  # Resolve symlinks on both sides before comparing; /var/www/html's source can
  # come back through one, and pwd -P has already resolved ours.
  root="$(cd "$root" 2>/dev/null && pwd -P)" || root=""

  if ! SAIL_CWD="$(zekini_container_dir "$root" "$here")"; then
    echo "✗ This tree is not inside the directory the app container serves." >&2
    echo "    this tree:        $here" >&2
    echo "    container serves: ${root:-<unresolvable>}" >&2
    echo "  Every check would run against the container's tree instead of this one," >&2
    echo "  reporting a verdict about code you are not pushing. Refusing instead." >&2
    echo "  Give this worktree its own Sail stack (its own .env, with unused ports)," >&2
    echo "  or run the gates by hand and push with --no-verify." >&2
    return 1
  fi

  export SAIL_CWD
  return 0
}

# Run a command through Sail in THIS tree.
#
# `sail bash -c …` because Sail has no flag for the working directory: it appends
# our arguments to `docker compose exec … bash`, so the cd travels with the
# command. Each argument is requoted so paths with spaces survive the round trip.
#
# ⚠️ ZST-311: `docker compose exec` (what `sail bash -c` boils down to) forwards
# NO host environment variable unless named explicitly — a repo policy knob set
# in .githooks/config (sourced on the host, before this function runs) is a
# real shell variable here but silently never reaches the container. Every
# currently-set `ZEKINI_*` variable (excluding this hook's own internal
# orchestration state — see `ZEKINI_INTERNAL_PREFIXES` below) is re-exported
# inside the container command for exactly that reason — this is the one seam
# every such knob crosses, so fixing it here (not per-caller) covers every
# script that reads one, not just the one that found the gap.
#
# ⚠️ ZST-348: this is a DENYLIST (forward everything except internal state),
# not an allowlist (forward only named knobs) — deliberately, not by
# oversight. An allowlist would need every repo's every `.githooks/config`
# knob enumerated here in advance, which is exactly the coupling ZST-311 was
# written to remove: a NEW knob a repo adds today must cross into the
# container with zero code changes here, or ZST-311's whole fix regresses one
# knob at a time. The real risk this card raised — a future INTERNAL-only
# var landing outside the excluded prefix and leaking silently — is real, but
# an allowlist trades a rare, catchable naming-convention slip for a
# guaranteed, permanent one (every legitimate knob needs a matching entry
# added here, forever, or it silently never reaches the container — the
# ORIGINAL bug ZST-311 fixed, reintroduced by this file itself). The fix that
# addresses the actual risk without paying that cost: every internal-only
# prefix lives in ONE explicit, appendable list rather than being buried in
# an inline `case` pattern easy to forget to extend — see
# `ZEKINI_INTERNAL_PREFIXES` immediately below.
#
# Each export is joined with `&&`, not `;` — a `;`-joined chain runs
# unconditionally regardless of whether `cd "$SAIL_CWD"` succeeded, silently
# defeating zekini_require_tree()'s whole refusal-on-wrong-tree guarantee
# (this file's own reason for existing) the moment any ZEKINI_* var is set,
# which pre-push's own ZEKINI_PRE_PUSH_BRANCH_SHAS/REFS exports mean is always.
#
# ⚠️ Found in review: `${!var}` is SCALAR-only indirect expansion. A knob
# ever expressed as a bash array in .githooks/config forwards only element
# 0 (an indexed array) or throws "unbound variable" under this file's own
# `set -u` and forwards an empty value (an associative array with no [0]
# key) — a latent trap, not a live bug, since no knob across the fleet is
# array-typed today. Every ZEKINI_* knob this mechanism forwards MUST stay
# a plain scalar string.

# ZST-348: the ONE place that names which `ZEKINI_*` prefixes are internal
# orchestration state and must NEVER cross into the container — everything
# else matching `ZEKINI_*` is a repo-policy knob and forwards by design (see
# `sail_run`'s own comment above for why that's a denylist, not an
# allowlist). A future internal-only variable belongs under one of these
# prefixes, not a new one invented ad hoc — add it HERE, not as another
# inline `case` arm somewhere `sail_run` could grow a second, driftable copy
# of this list.
#   ZEKINI_PRE_PUSH_*  pre-push's own re-exec state (branch SHAs/refs, lock
#                      flags, ZEKINI_PRE_PUSH_REEXEC) — implementation
#                      detail, not a knob any script should read.
#
# ⚠️ Found in review: deliberately NOT `readonly`. This file is a shared
# LIBRARY (`. lib/sail-tree.sh`), not a script run once — a `readonly` here
# would abort with "readonly variable" the moment any caller sources it
# twice in the same process (a future defensive re-source in a wrapper lib,
# say), which the pre-refactor inline `case` could never do since
# re-sourcing just harmlessly redefines the `sail_run` function. Nothing in
# this file mutates the array after this point; the convention is enforced
# by review, not the shell.
ZEKINI_INTERNAL_PREFIXES=(
  ZEKINI_PRE_PUSH_
)

sail_run() {
  local quoted="" arg envfwd="" var prefix is_internal

  for arg in "$@"; do
    quoted+=" $(printf '%q' "$arg")"
  done

  for var in "${!ZEKINI_@}"; do
    # ZEKINI_INTERNAL_PREFIXES itself matches `${!ZEKINI_@}` (it starts with
    # ZEKINI_) but isn't covered by any of its OWN prefixes — an exact-name
    # exclusion, not a prefix one, since this is the mechanism's own control
    # variable, not a repo-policy knob. Without it every sail_run call leaked
    # `export ZEKINI_INTERNAL_PREFIXES=ZEKINI_PRE_PUSH_ && ...` into the
    # container, contradicting this file's own "must NEVER cross into the
    # container" rule for internal orchestration state (caught in review,
    # voc VOC-1211/1213: harmless today — nothing inside reads it, and it
    # carries no secret — but a real, live self-inconsistency, not
    # hypothetical).
    [ "$var" = "ZEKINI_INTERNAL_PREFIXES" ] && continue
    is_internal=0
    for prefix in "${ZEKINI_INTERNAL_PREFIXES[@]}"; do
      case "$var" in
        "${prefix}"*) is_internal=1; break ;;
      esac
    done
    [ "$is_internal" -eq 1 ] && continue
    envfwd+="export $var=$(printf '%q' "${!var}") && "
  done

  # A gate is never an agent's run (LOU-1451, 2026-10-29). Sail ≥ 1.58 forwards
  # the AI-agent markers (CLAUDECODE, AI_AGENT, CURSOR_AGENT, …) into the
  # container, and laravel/pao then takes over any test run it sees them on:
  # per-test output gone, one JSON line in its place, and `php artisan test`
  # exits 1 WITH EVERY TEST PASSED — the hook's unit stage read red on main for
  # every push made from a Claude session while the same suite was green from
  # a plain shell. Pao is for a coder's own ad-hoc run in its session; the
  # hook's stages are judged on exit codes and parsed as PHPUnit text, so Pao
  # is off for everything that goes through here, whatever shell pushed.
  envfwd+="export PAO_DISABLE=1 && "

  "${sail:-./vendor/bin/sail}" bash -c "cd $(printf '%q' "$SAIL_CWD") && ${envfwd}$quoted"
}
