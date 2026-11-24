#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Standalone secret-redaction tokenizer (ZST-305, epic REDACT/ZST-289).
 *
 * Reads captured check output on stdin, writes the redacted text to
 * stdout, exits non-zero on any internal failure. Replaces
 * templates/hooks/eod-lib.sh's hand-patched sed/ERE `zekini_redact_secrets()`
 * and `zekini_redact_pem_blocks()`, which shipped a NEW total-bypass regex
 * bug on four separate sync rounds in a row (ZST-277/283/286/288) — POSIX
 * ERE has no backreferences inside character classes, no non-capturing
 * groups, no `\b`, and kept producing new silent-bypass classes rather than
 * obviously-wrong ones.
 *
 * Deliberately dependency-free — no Composer, no vendor/autoload, core PHP
 * functions only — and deliberately invoked as a bare `php` subprocess
 * outside Sail (owner decision 2026-09-07, ZST-305): the output this
 * redacts can itself be a failing Sail/Docker check, so redaction cannot
 * depend on the thing it might be reporting as down. This is a narrow,
 * explicit exception to "never run PHP on the host" — it applies to this
 * one small utility script, not to the application.
 *
 * Full spec and test-case inventory: docs/redact-secrets-tokenizer-spec.md.
 * Conformance suite: redact-secrets.test.php (same directory).
 *
 * Design call made here, per the spec's own "decide, don't default": this
 * implementation is KEY-NAME-DRIVEN ONLY, with no value-based entropy/shape
 * heuristic layered on top (the spec's option C). A prior value-shape
 * heuristic in the bash implementation (a "bare line of base64-alphabet
 * characters" rule, meant to catch PEM bodies without BEGIN/END markers)
 * was reverted after shipping in production: base64's alphabet is almost
 * the same as an ordinary identifier or file path, and it destroyed real,
 * non-secret diagnostic output. Every regex-shaped rule this fleet has
 * hand-patched has failed the same way at least once — a second detection
 * pass is a second surface for exactly that failure, for a fleet that
 * cannot yet test a heuristic against a real, large corpus of its own
 * output. Key-name-driven is the same shape the current rules already use,
 * just implemented completely and correctly.
 */

const SECRET_WORDS = ['secret', 'token', 'password', 'key'];

/**
 * The identifier class every quoted/bracketed KEY-shaped rule below builds its
 * pattern from — JSON, array-dump, print_r, var_dump, JSON-pretty. One
 * constant, so a future narrowing or widening cannot reach one shape and miss
 * its siblings (the "narrow rule, sibling not updated" shape blamed for most of
 * this file's history).
 *
 * `.` is allowed on purpose (ZST-388, found by Loupe verifying ZST-385): Laravel
 * prints CONFIG keys dotted — `config:show`, a dumped config array, an
 * exception page's context all render `'services.stripe.secret' => '…'` and
 * `{"mail.mailers.smtp.password": "…"}` — and a class that stopped at `.` shipped
 * exactly those in full in every shape except dotenv (whose own class already
 * allowed it). `splitWords()` already ignores `.` as a non-word character, so
 * `mail.mailers.smtp.password` classifies on `password` like any other key.
 */
const KEY_CLASS = '[A-Za-z0-9_.\-]+';

/**
 * A header NAME as it appears in a quoted or unquoted `Name: value` argument:
 * letter first, then letters, digits, `_` and `-` (no `.`, unlike KEY_CLASS —
 * an HTTP header name never has one, and allowing it would make ordinary prose
 * such as `"See app.name: value"` a candidate).
 */
const HEADER_NAME_CLASS = '[A-Za-z][A-Za-z0-9_\-]*';

/**
 * Segment words that, when they FOLLOW a secret word, name metadata ABOUT
 * a key/token rather than the secret material itself — `KeyType`,
 * `TokenExpiry`, `KeyRing`, `SubjectKeyIdentifier`, `AWS_ACCESS_KEY_ID`,
 * `PasswordPolicyName`. These are the ONLY shapes that stop a secret word
 * from redacting.
 *
 * 🔥 STOP-SHIP from loupe (LOU-831, 2026-09-07), reversing this file's
 * first rule: "the secret word must be the last segment" leaked every
 * secret whose name carries a trailing QUALIFIER — `SECRET_KEY_BASE`
 * (Rails' own), `STRIPE_SECRET_KEY_TEST`, `MAIL_PASSWORD_NEW`,
 * `DATABASE_PASSWORD_FILE`, `APP_KEY_BASE`, `TOKEN_VALUE` — all shipped in
 * full at exit 0, caught only because loupe's own fixture had pinned
 * `SECRET_KEY_BASE` since real key material reached a Kadro card
 * (LOU-800). A metadata suffix and a qualifier look identical positionally;
 * only the WORD tells them apart, so the rule is now "a secret word
 * anywhere redacts, unless the very next segment is a known metadata
 * word." Deliberate asymmetry: an unknown next-segment (`Bucket`,
 * `Management`, `Base`, `Test`) redacts — over-redacting a diagnostic value
 * is cheap, shipping a secret is not. The review-round-1 preference for
 * keeping `KeyManagementService`/`TokenBucketLimiter` intact is knowingly
 * given up for that. Extend this list only with a word that is metadata in
 * every real usage you can name.
 *
 * 🔥 STOP-SHIP from loupe, round 5 (LOU-831, 2026-09-08): the array below
 * still listed `provider`, `store`, `vault`, `manager`, `management`,
 * `service` as safe — directly contradicting the paragraph above, which
 * says an unrecognized trailing segment must redact and names
 * `Management`/`KeyManagementService` as an example that SHOULD redact.
 * `DATABASE_PASSWORD_STORE` and `{"KeyManagementService":"…"}` both shipped
 * unredacted as a result. Removed; none of the six is metadata in every
 * real usage the way `type`/`id`/`version` are.
 */
const METADATA_AFTER_SECRET_WORD = [
    'type', 'kind', 'expiry', 'expires', 'expiration', 'ttl', 'ring',
    'identifier', 'id', 'name', 'label', 'length', 'size', 'bits',
    'algorithm', 'alg', 'version', 'format', 'encoding', 'usage', 'policy',
    'rotation', 'count', 'index', 'arn', 'status', 'enabled', 'required',
    'hint', 'prefix', 'suffix', 'header', 'param', 'field', 'source',
];

/**
 * Splits an identifier into lowercase words on underscores, hyphens, and
 * camelCase/PascalCase case-transitions — one splitter shared by every
 * format's key check (dotenv, JSON, PHP array-dump, print_r, var_dump,
 * bare colon-headers), per the spec's code-hygiene requirement. Digits stay
 * attached to whichever letter run they're adjacent to (`oauth2` is one
 * word) rather than becoming their own segment or a false boundary — the
 * root cause of `API_KEY_2`/`oauth2_client_secret` shipping unredacted
 * under the old regex, whose continuation classes excluded 0-9 entirely.
 *
 * 🔥 STOP-SHIP (ZST-349, found by Accounting's review panel): an identifier
 * with NEITHER a separator NOR a case transition — `APIKEY`, `SECRETKEY`,
 * `DBPASSWORD`, `PRIVATEKEY` (all-caps) or `apikey`, `clientsecret`
 * (all-lowercase) — has no boundary for either the `preg_split` above or
 * the case-transition regex below to find, so it collapses into ONE word
 * that never equals a whole `SECRET_WORDS` entry and `keyLooksSecret()`
 * returns false at exit 0. The original fix here only peeled a secret word
 * off the END of such a word (`apikey` → `api` + `key`), on the theory that
 * a SUFFIX check would leave `Keyword`/`Keyboard`/`Secretary`/`Tokenizer`/
 * `Passwordless` alone (none of them ENDS in a secret word) while still
 * catching every no-separator shape reported at the time.
 *
 * 🔥 STOP-SHIP (ZST-361, 2026-09-11): the suffix-only check missed the same
 * secret words as a PREFIX or MIDDLE segment — `SECRETVALUE`, `TOKENDATA`
 * (secret word first) and `APIKEYVALUE`, `MYPASSWORDHASH` (secret word in
 * the middle) all shipped in full, exit 0, because nothing peeled a secret
 * word off the front or out of the middle of an unsplit word, only off the
 * end. `splitEmbeddedSecretWord()` below is the fix: it finds a KNOWN
 * secret word ANYWHERE in an otherwise-unsplit word — leftmost occurrence
 * wins when more than one could match — and splits into up to three parts
 * (`before`, the secret word itself, `after`), so `keyLooksSecret()`'s
 * existing "last secret word decides" logic sees it exactly as if the
 * input had separators: `secretvalue` → `secret` + `value`, `apikeyvalue`
 * → `api` + `key` + `value`.
 *
 * Owner decision, ZST-361: accept the new false positives this creates
 * rather than leave the prefix/middle gap open. `Keyword`, `Keyboard`,
 * `Secretary`, `Tokenizer`, `Passwordless` now all redact too — each
 * contains a whole `SECRET_WORDS` segment once `key`/`token`/`secret`/
 * `password` is found anywhere in them, and the word that follows
 * (`word`, `board`, `ary`, `izer`, `less`) is not a recognized
 * `METADATA_AFTER_SECRET_WORD` entry, so the existing "unknown next
 * segment redacts" rule fires exactly as it already does for `MONKEY`/
 * `TURKEY`. Consistent with this file's standing rule: over-redacting a
 * diagnostic value is cheap, shipping a secret is not.
 *
 * ⚠️ Only the leftmost secret word in a word is ever extracted — a word
 * containing TWO different secret words (`PASSWORDTOKENVALUE`) only splits
 * out the first one found (`password` + `tokenvalue`), not both. This is
 * not a false-negative risk: the leftover chunk (`tokenvalue`) is never a
 * recognized `METADATA_AFTER_SECRET_WORD` entry unless it equals one
 * EXACTLY, and if it did equal one exactly there would be no room left for
 * a second secret word to hide inside it. The single split is a cosmetic
 * limit (the second secret word isn't independently named in the output),
 * not a security one — redaction still fires either way.
 */
function splitWords(string $ident): array
{
    // pcreSplitOrThrow, not a bare `=== false` return-[] (ZST-385, defense
    // in depth): identifiers are always short in practice, so this specific
    // call isn't known to be exploitable the way the value-line matches
    // above are — but silently treating a PCRE engine failure as "no words
    // found" is the same fail-open shape as every other bare preg_* call
    // this card fixes, and a caller here has no way to tell that apart from
    // a genuinely empty/unsplittable identifier.
    $words = [];
    foreach (pcreSplitOrThrow(preg_split('/[_\-]+/', $ident, -1, PREG_SPLIT_NO_EMPTY), 'identifier split') as $part) {
        // The acronym lookahead is `[A-Z][a-z]` on purpose, NOT `[A-Z][a-z0-9]`
        // — with a digit allowed there, `SECRET1` backtracks to `SECRE` + `T1`
        // (the lookahead sees `T` then `1`) and the secret word is never seen
        // whole (caught in review). Digits belong in the word-body branch only.
        //
        // Flagged by loupe alongside round 5 (LOU-831, 2026-09-08): a bare
        // `preg_match_all(...) > 0` treats a PCRE engine failure (`false`,
        // e.g. backtrack-limit exceeded on pathological input) identically
        // to a genuine zero-match part — silently "not secret" instead of
        // the withhold-on-failure every sibling function in this file
        // guarantees via pcreMatchOrThrow().
        $wordCount = pcreMatchOrThrow(
            preg_match_all('/[A-Z]+(?=[A-Z][a-z])|[A-Z]?[a-z0-9]+|[A-Z]+/', $part, $matches),
            'identifier word-split',
        );
        if ($wordCount > 0) {
            foreach ($matches[0] as $word) {
                array_push($words, ...splitEmbeddedSecretWord(strtolower($word)));
            }
        }
    }

    return $words;
}

/**
 * ZST-349/ZST-361: peels a KNOWN secret word out of an already-lowercased
 * word that has no separator or case boundary of its own to split on,
 * wherever it occurs — prefix, middle, or suffix. `apikey` → `['api',
 * 'key']`, `dbpassword` → `['db', 'password']`, `secretvalue` → `['secret',
 * 'value']`, `apikeyvalue` → `['api', 'key', 'value']`. A no-op (`[$word]`)
 * when nothing matches, or when the word IS a `SECRET_WORDS` entry outright
 * (an empty `$before`/`$after` segment is dropped, so `key` itself is
 * returned whole, not split into `''` + `key` + `''`).
 *
 * Leftmost match wins when more than one secret word could match at
 * different positions. No tie-break is needed for two SECRET_WORDS entries
 * matching at the SAME position — 'secret', 'token', 'password' and 'key'
 * all start with a distinct first character, so that can't happen with the
 * current list (would need revisiting if a future secret word shared a
 * prefix with an existing one). Only the FIRST secret word found is ever
 * extracted — see the docblock above `splitWords()` for why a second one
 * hiding in the same no-separator word is a cosmetic gap, not a security
 * one.
 */
function splitEmbeddedSecretWord(string $word): array
{
    $matchPos = null;
    $matchWord = null;

    foreach (SECRET_WORDS as $secretWord) {
        $pos = strpos($word, $secretWord);
        if ($pos === false) {
            continue;
        }
        if ($matchPos === null || $pos < $matchPos) {
            $matchPos = $pos;
            $matchWord = $secretWord;
        }
    }

    if ($matchPos === null) {
        return [$word];
    }

    $before = substr($word, 0, $matchPos);
    $after = substr($word, $matchPos + strlen($matchWord));

    $parts = [];
    if ($before !== '') {
        $parts[] = $before;
    }
    $parts[] = $matchWord;
    if ($after !== '') {
        $parts[] = $after;
    }

    return $parts;
}

/**
 * The one key-classification predicate every format-specific rule below
 * calls, instead of each re-encoding its own copy of the secret-word
 * pattern (the exact "narrow rule, sibling not updated" shape blamed for
 * several of the bash implementation's hand-patch rounds).
 *
 * A whole-word, case-insensitive match against SECRET_WORDS, on the words
 * `splitWords()` produces — which, since ZST-361, includes a secret word
 * `splitEmbeddedSecretWord()` peeled out of an otherwise-unsplit identifier
 * at ANY position, not only a genuine separator/case boundary. `Keyword`,
 * `Keyboard`, `Tokenizer`, `Secretary`, `Passwordless` therefore DO match
 * now (an accepted false positive, ZST-361) — see `splitWords()`'s own
 * docblock for why the prefix/middle case couldn't be closed without them.
 */
function keyLooksSecret(string $ident): bool
{
    $words = splitWords($ident);
    if ($words === []) {
        return false;
    }

    // The LAST secret word in the identifier decides: what follows it is
    // either metadata (don't redact) or a qualifier / the secret's own
    // representation / nothing (redact). Earlier secret words don't matter
    // — `STRIPE_SECRET_KEY_TEST` is judged on `key`→`test`, not `secret`.
    $last = -1;
    foreach ($words as $i => $word) {
        if (in_array($word, SECRET_WORDS, true)) {
            $last = $i;
        }
    }
    if ($last < 0) {
        return false;
    }

    $next = $words[$last + 1] ?? null;
    if ($next === null || ctype_digit($next)) {
        return true; // `API_KEY`, `API_KEY_2`, `TOKEN9`
    }

    return !in_array($next, METADATA_AFTER_SECRET_WORD, true);
}

/**
 * Redacts PEM blocks in three linear phases — replacing the bash
 * implementation's line-streamed `awk` state machine, kept behaviourally
 * identical (see redact-secrets.test.php, ported from the old
 * `redact-pem-blocks.test.sh`) but without its measured O(n²) hot path.
 *
 * **Phase 1 — every COMPLETE, matching-label pair, in one linear pass.**
 * PHP reads the whole captured output into memory up front (never
 * streamed), so — unlike the old awk version, which had to hand-roll
 * cross-line state to survive a BEGIN/END pair spanning more than one
 * "record" (ZST-283's data-destruction regression, ZST-297's "body still
 * leaks" fix, both about exactly that) — one match covers the whole block,
 * however many lines it spans. The scan finds every non-overlapping pair by
 * advancing a cursor forward through the string ONCE; it never re-scans
 * already-consumed text the way the awk version's manual `while (match) {
 * ...; s = substr(s, ...) }` loop did (the spec's own measured
 * 10k-pairs/~5s, 20k-pairs/~22s — quadratic, not linear), nor re-scans to
 * end of input per unterminated BEGIN the way the DOTALL regex it replaced
 * did (ZST-435). A BEGIN closes only at the first END of the SAME label
 * after it, which is what makes a MISMATCHED-label END never close a
 * block early — a real requirement, not a nice-to-have (see the "mismatched
 * label" test cases): a stray same-shaped END for a different block must
 * not be mistaken for this one's close.
 *
 * **Phase 2 — bare ENDs, and same-line content glued directly to one.**
 * Whatever `-----END <label>-----` markers remain after phase 1 have no
 * reachable matching BEGIN. Ported from the bash version's
 * `redact_bare_ends()`: everything on the SAME LINE before a bare END is
 * dropped too, not just the marker — real secret body content is
 * sometimes glued directly onto a bare END with no newline (a truncated or
 * malformed capture), and there is no reliable way to tell "ordinary
 * preceding text" from "leaked secret glued to the marker," so the
 * conservative read (drop it) carries over unchanged. Applied per line
 * (never across a line boundary) so unrelated earlier/later lines are
 * never affected.
 *
 * **Phase 3 — a BEGIN with no reachable END anywhere.** Suppresses up to
 * 5000 subsequent lines looking for a close that will never come, same cap
 * as the bash version, so a genuinely unterminated capture can't suppress
 * output indefinitely; emits a distinct TRUNCATED marker either at the cap
 * or at end-of-input (whichever comes first), then resumes normal
 * processing for anything after the cap.
 */
function redactPemBlocks(string $text): string
{
    $text = redactCompletePemPairs($text);
    $text = redactBareEndsPerLine($text);

    return redactUnclosedPemBegins($text);
}

/**
 * Never falls back to the unredacted input on a PCRE failure (caught in
 * review): `preg_replace` returns null when the engine gives up — a PEM body
 * of a few hundred KB is enough to blow the default backtrack limit — and a
 * `?? $text` fallback would ship the real private key verbatim, at exit 0,
 * with nothing signalling the pass failed. Throw instead; main()'s catch
 * turns that into a non-zero exit, which eod-lib.sh's file_ticket() already
 * treats as "withhold the whole capture." The backtrack limit is also raised
 * up front (see main()) so an ordinary large block doesn't trip it at all.
 */
function pcreOrThrow(?string $result, string $context): string
{
    if ($result === null || preg_last_error() !== PREG_NO_ERROR) {
        throw new \RuntimeException($context.': PCRE failure ('.preg_last_error_msg().')');
    }

    return $result;
}

/** Same contract for the `preg_match*` family, whose failure value is `false`, not null. */
function pcreMatchOrThrow(int|false $result, string $context): int
{
    if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
        throw new \RuntimeException($context.': PCRE failure ('.preg_last_error_msg().')');
    }

    return $result;
}

/** Same contract for `preg_split`, whose failure value is `false`, not null (ZST-385). */
function pcreSplitOrThrow(array|false $result, string $context): array
{
    if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
        throw new \RuntimeException($context.': PCRE failure ('.preg_last_error_msg().')');
    }

    return $result;
}

/**
 * A complete pair whose span contains ANOTHER BEGIN OF THE SAME LABEL is marked
 * as collapsed, not silently swallowed (ZST-389, found by Loupe): the lazy
 * `.*?` runs from an UNTERMINATED BEGIN to the first same-label END anywhere
 * later, so `BEGIN CERTIFICATE` / three important log lines / `BEGIN
 * CERTIFICATE` / body / `END CERTIFICATE` collapsed everything, the three lines
 * included, into one plain `[REDACTED PEM BLOCK]` with nothing to say that more
 * than a key had been removed. A second BEGIN of the same label inside the span
 * proves the first was never closed, so that span now carries its own TRUNCATED
 * marker. The text is still withheld (those lines sit between two BEGINs and
 * may well be key material, so conservative is right), but the reader can tell
 * something was lost, and lines AFTER the closing END survive exactly as before.
 *
 * Two earlier drafts of this fix changed the regex itself and were reverted:
 * one tempered the body against a second BEGIN, which left the first BEGIN to
 * phase 3 and so dropped everything after the pair to end of input under a
 * marker claiming no closing marker existed; the other consumed dashes one loop
 * iteration at a time and exhausted PCRE's JIT stack on a body with ~10,000
 * dashes, withholding the whole capture. The matching is unchanged from
 * before, and only the replacement decides which marker to write. A BEGIN of a
 * DIFFERENT label inside the span stays part of the body, as tested ("two
 * BEGINs with no END between them collapse to one marker").
 *
 * An offset scan, not a regex (ZST-435). It matches exactly what
 * `/-----BEGIN ([A-Z0-9 ]+)-----.*?-----END \1-----/s` matched, pair for pair,
 * but that pattern was quadratic in unterminated BEGINs: each one sent `.*?`
 * to the end of input looking for an END that was not there (20,000
 * same-label BEGINs, 585 KB, took 13.7s). Here every END is indexed by label
 * in one sweep up front, so a BEGIN finds its first same-label END after it
 * by moving a per-label pointer that only goes forward. The whole pass is
 * linear, whether the missing END is absent, has the wrong label, or the
 * BEGINs all carry different labels. The same-label-BEGIN check reads only
 * the matched span, and spans never overlap. No backtracking over the body
 * either, so the 200k-dash body case cannot touch a PCRE limit.
 */
function redactCompletePemPairs(string $text): string
{
    $ends = pemMarkerOffsetsByLabel($text, '-----END ');
    $next = []; // label => index of its first END in $ends not yet behind the cursor
    $begin = '-----BEGIN ';
    $out = '';
    $copied = 0; // text before this offset is already in $out
    $cursor = 0; // where the next BEGIN search starts

    while (($start = strpos($text, $begin, $cursor)) !== false) {
        $cursor = $start + 1;
        $label = pemLabelAt($text, $start + strlen($begin));
        if ($label === null || !isset($ends[$label])) {
            continue;
        }

        $bodyAt = $start + strlen($begin) + strlen($label) + 5;
        $i = $next[$label] ?? 0;
        while (isset($ends[$label][$i]) && $ends[$label][$i] < $bodyAt) {
            ++$i;
        }
        $next[$label] = $i;
        if (!isset($ends[$label][$i])) {
            continue;
        }

        // The rest of the match, END included, as the regex's callback read it:
        // a same-label BEGIN whose closing dashes open the END still counts.
        $matchEnd = $ends[$label][$i] + strlen('-----END '.$label.'-----');
        $marker = substr($text, $start, $bodyAt - $start);
        $out .= substr($text, $copied, $start - $copied);
        $out .= str_contains(substr($text, $bodyAt, $matchEnd - $bodyAt), $marker)
            ? '[REDACTED PEM BLOCK -- TRUNCATED, an earlier BEGIN of this label had no closing marker]'
            : '[REDACTED PEM BLOCK]';
        $copied = $cursor = $matchEnd;
    }

    return $out.substr($text, $copied);
}

/**
 * Every well-formed `<prefix><label>-----` marker in $text, as label => the
 * prefix's offsets in ascending order, found in one forward strpos sweep.
 *
 * @return array<string, int[]>
 */
function pemMarkerOffsetsByLabel(string $text, string $prefix): array
{
    $offsets = [];
    $at = 0;
    while (($at = strpos($text, $prefix, $at)) !== false) {
        $label = pemLabelAt($text, $at + strlen($prefix));
        if ($label !== null) {
            $offsets[$label][] = $at;
        }
        ++$at;
    }

    return $offsets;
}

/**
 * The `[A-Z0-9 ]+` label starting at $at when `-----` closes it, else null.
 * The label class has no dash, so the longest run is the only one a
 * `-----` can follow, exactly as the old regex's greedy group.
 */
function pemLabelAt(string $text, int $at): ?string
{
    $length = strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 ', $at);
    if ($length === 0 || substr($text, $at + $length, 5) !== '-----') {
        return null;
    }

    return substr($text, $at, $length);
}

/** @return array{0: string[], 1: bool} lines, and whether $text had a trailing newline */
function splitLinesPreservingTrailingNewline(string $text): array
{
    $hadTrailingNewline = str_ends_with($text, "\n");
    $lines = explode("\n", $text);
    if ($hadTrailingNewline) {
        array_pop($lines);
    }

    return [$lines, $hadTrailingNewline];
}

function joinLinesRestoringTrailingNewline(array $lines, bool $hadTrailingNewline): string
{
    $result = implode("\n", $lines);

    return $hadTrailingNewline ? $result."\n" : $result;
}

function redactBareEndsPerLine(string $text): string
{
    [$lines, $hadTrailingNewline] = splitLinesPreservingTrailingNewline($text);

    $out = array_map(static function (string $line): string {
        // pcreMatchOrThrow, not a bare `=== 0` check (LOU-831's third
        // round): preg_match_all returns false on a PCRE engine failure,
        // and `false === 0` is false, so the old check fell through to the
        // "found matches" branch with $matches[0] UNDEFINED — the foreach
        // below would have iterated nothing and the line shipped with no
        // error, exactly the silent-failure shape pcreOrThrow exists to
        // rule out elsewhere in this file.
        $count = pcreMatchOrThrow(
            preg_match_all('/-----END [A-Z0-9 ]+-----/', $line, $matches, PREG_OFFSET_CAPTURE),
            'bare-END scan',
        );
        if ($count === 0) {
            return $line;
        }

        $redacted = '';
        $pos = 0;
        foreach ($matches[0] as [$matchText, $offset]) {
            $redacted .= '[REDACTED PEM BLOCK]';
            $pos = $offset + strlen($matchText);
        }

        return $redacted.substr($line, $pos);
    }, $lines);

    return joinLinesRestoringTrailingNewline($out, $hadTrailingNewline);
}

function redactUnclosedPemBegins(string $text): string
{
    [$lines, $hadTrailingNewline] = splitLinesPreservingTrailingNewline($text);

    $out = [];
    $suppressing = false;
    $suppressedLines = 0;

    foreach ($lines as $line) {
        if ($suppressing) {
            $suppressedLines++;
            if ($suppressedLines >= 5000) {
                $out[] = '[REDACTED PEM BLOCK -- TRUNCATED, no closing marker found within 5000 lines]';
                $suppressing = false;
            }
            continue;
        }

        // pcreMatchOrThrow, not a bare preg_match (LOU-831's third round):
        // a single line at or past pcre.backtrack_limit (the ~10MB ceiling
        // main() itself raises) makes this match FAIL, not merely not
        // match — a bare `if (preg_match(...))` treats both as "false",
        // so the BEGIN line prints raw, `$suppressing` never turns on, and
        // every following line of real key material ships in clear at
        // exit 0. Reproduced live against a line at the limit.
        if (pcreMatchOrThrow(preg_match('/^(.*?)-----BEGIN [A-Z0-9 ]+-----.*$/', $line, $m), 'unclosed-PEM-begin scan') === 1) {
            $out[] = $m[1].'[REDACTED PEM BLOCK]';
            $suppressing = true;
            $suppressedLines = 0;
            continue;
        }

        $out[] = $line;
    }

    if ($suppressing) {
        $out[] = '[REDACTED PEM BLOCK -- TRUNCATED, input ended before a closing marker]';
    }

    return joinLinesRestoringTrailingNewline($out, $hadTrailingNewline);
}

/**
 * Known secret-value prefixes (Stripe-style API keys and webhook secrets)
 * worth catching regardless of the key name they were assigned to — carried
 * over unchanged from the bash implementation's own final rule (not flagged
 * as buggy by any spec item, and narrowly scoped to a handful of real
 * fixed-prefix formats, unlike the general entropy-heuristic question the
 * spec leaves open and this implementation declines — see the file header).
 */
function redactKnownValuePrefixes(string $text): string
{
    $pattern = '/(^|[^A-Za-z0-9_])(sk|pk|re|whsec)_[A-Za-z0-9_]{8,}/';

    // Never `?? $text` (LOU-831's second review, ZST-330): that fallback shipped
    // the UNREDACTED text on a PCRE failure, the mirror image of the blank-line
    // bug the `(string)` casts elsewhere had. Both go through pcreOrThrow now.
    return pcreOrThrow(preg_replace($pattern, '$1[REDACTED_KEY]', $text), 'known-prefix pass');
}

/**
 * dotenv: `KEY=value`, at line start or after any non-identifier character —
 * so `?password=` inside a URL and `,password=` inside a DSN both count.
 * Every `KEY=` on the line is classified (the key is matched with nothing
 * after it consumed, so a later assignment is always seen — the
 * `MODE=production STRIPE_WEBHOOK_TOKEN=…` case from review round 1), and
 * from the FIRST one that looks secret the rest of the line is redacted to
 * END OF LINE, not to the next whitespace.
 *
 * Why to EOL (LOU-831's second STOP-SHIP, ZST-330): the previous `\S*` value
 * bound regressed the bash redactor this replaces in two ways. An unquoted
 * value containing a space was only half-redacted
 * (`API_TOKEN=some secret phrase` → `API_TOKEN=[REDACTED] secret phrase`),
 * and a value that itself carried `key=` pairs was consumed whole by the
 * non-secret outer key and never inspected
 * (`URL=…?password=pw&x=1`, `DSN=host=db,password=hunter2,port=5432` —
 * both shipped untouched). The bash version's to-EOL bound existed for
 * exactly the first reason; that rationale was lost in the rewrite.
 * Anything after a secret value on the same line is lost — an accepted
 * over-redaction, since it is diagnostic text sharing a line with a
 * credential.
 */
function redactDotenvLine(string $line): string
{
    pcreMatchOrThrow(
        preg_match_all('/(?<![A-Za-z0-9_])([A-Za-z_][A-Za-z0-9_]*)=/', $line, $matches, PREG_OFFSET_CAPTURE),
        'dotenv key scan',
    );

    foreach ($matches[1] as [$key, $offset]) {
        // 🔥 STOP-SHIP from loupe, round 5 (LOU-831, 2026-09-08): this called
        // keyLooksSecret() directly, making dotenv a second bare-header-only
        // rule (the exact shape HEADER_ALWAYS_SECRET's own docblock, line
        // ~556, says every key-shaped rule must avoid) — `AUTHORIZATION=Bearer
        // <token>` shipped in full whenever no recognized provider-key prefix
        // happened to also catch it.
        if (keyIsAlwaysSecret($key)) {
            return substr($line, 0, $offset + strlen($key) + 1).'[REDACTED]';
        }
    }

    return $line;
}

/**
 * URL userinfo: `scheme://user:password@host` — the shape a Laravel
 * exception page prints for `DATABASE_URL`, and one no key name ever
 * classifies because the password has no key at all (ZST-330). The user
 * part is kept (it is an identifier, and a restore needs it); only the
 * password segment goes. Runs before the dotenv rule so a `DATABASE_URL=`
 * line that the key scan would leave alone is still covered.
 *
 * 🔥 STOP-SHIP (ZST-350, found by Accounting's review panel): the password
 * class used to be `[^\s/@]+` — EXCLUDING both `@` and `/` — so a password
 * containing either stopped the match early. A literal `@`
 * (`postgres://user:sec@ret@host:5432/db`) truncated the redaction at the
 * FIRST `@`, shipping `ret` (the password's own tail) in clear. Worse,
 * found in review round 2: a password containing `/` — routine for a
 * base64-derived secret — didn't just truncate, it broke the match
 * ENTIRELY (nothing after the excluded `/` could ever reach the mandatory
 * trailing `@`), so the WHOLE LINE shipped completely unredacted, the
 * exact total-bypass shape this file exists to prevent.
 *
 * Fixed with a LAZY password match (`[^\s]+?`, no character excluded) plus
 * a NEGATIVE lookahead, `(?!\S*@)`, asserting that no FURTHER `@` occurs
 * before the next whitespace or end of line. A lazy quantifier always
 * tries the SHORTEST match first, so the engine keeps extending the
 * password by one character and re-testing the assertion until it lands on
 * the `@` after which no other `@` remains in this same token — which, for
 * ambiguous input with more than one `@`, is always the LAST one, matching
 * how a real parser resolves it: `parse_url('postgres://user:pass@ho@st:5432/db')`
 * returns `pass => 'pass@ho'`, `host => 'st'`, the identical split this
 * pattern now makes.
 *
 * ⚠️ Found in review, round 3: an EARLIER version of this fix used a
 * POSITIVE lookahead requiring the host to be followed by `/`, `?`, `#`,
 * or end of string — so a URL with nothing else on its line but trailing
 * prose or a JSON sibling field (`Connecting to postgres://user:pass@host:5432 now`;
 * `{"database_url": "postgres://user:pass@host:5432", "other": "value"}`)
 * matched NONE of those terminators and the whole match failed, shipping
 * the line completely unredacted — the very total-bypass class this card
 * exists to close, reintroduced by trying to enumerate every character
 * that could legitimately follow a host. The negative-lookahead form
 * doesn't enumerate terminators at all: it only asks "is there another `@`
 * before the next whitespace", which is `true`/`false` regardless of what
 * that trailing character actually is — a quote, a comma, a bracket, or
 * nothing. `/` is no longer excluded from the password body either — the
 * lookahead, not the character class, is what finds the boundary, so a
 * `/`-bearing password can never break the match. An ordinary password
 * with no embedded `@` is unaffected: there is only one `@` in its token
 * and the assertion succeeds on the very first try.
 */
function redactUrlUserinfo(string $line): string
{
    // `[^\s/:@]*`, not `+` (ZST-385, LOU-831 review): an empty user before
    // the colon is the NORMAL Redis shape (`redis://:hunter2@host:6379`,
    // no username at all), and requiring at least one user character made
    // the whole pattern fail to match there — the password shipped raw.
    //
    // ZST-389 (found by Loupe): a URL that sits INSIDE a quoted string is bounded
    // by that string. The lazy password above runs to the LAST `@` in the
    // whitespace-free token, which on minified JSON or a compact log line
    // reaches past the URL's own closing quote into a SIBLING field
    // (`{"database_url":"postgres://u:p@h:5432","email":"a@b.com"}` became
    // `{"database_url":"postgres://u:[REDACTED]@b.com"}`: the host, the port and
    // an unrelated field gone, with no marker).
    //
    // So: when the URL is directly preceded by an opening quote, the password
    // and the look-ahead for a further `@` both stop at that SAME quote
    // character (a backslash escape, as in JSON's `\"`, is skipped over). When it
    // is not — a dotenv line, a log line, prose — the original whitespace-bound
    // pattern applies unchanged. The scope is decided by context, never by
    // guessing whether a quote inside a password is "really" part of it: a first
    // attempt applied the quote bound to every URL and leaked the tail of
    // `postgres://u:p@ss'word@host` (the password `p@ss'word` was cut at its own
    // quote and `ss'word` shipped in clear); review caught it. With the bound
    // tied to an actual OPENING quote, that unquoted shape is redacted whole by
    // the second pass, exactly as before.
    //
    // A quoted URL the first pass cannot bound (a password containing the
    // enclosing quote character itself) simply falls through to the second, so
    // the worst case is the old over-reach, never a leak. The
    // `(?!\[REDACTED\]@)` right after the colon keeps the second pass from
    // re-matching a userinfo the first already redacted.
    //
    // ZST-428: a URL inside JSON that is itself a string inside JSON (a Laravel
    // log line's context: `{\"url\":\"postgres://u:p@h:5432\",\"e\":\"a@b.com\"}`)
    // sits between ESCAPED quotes, `\"`, and the quoted pass above treats a
    // backslash escape as skippable, so it could not tell an escaped quote inside
    // the value from an escaped TERMINATOR and reproduced the original over-reach.
    // A first pass for that context bounds the password by the two-character
    // sequence backslash-quote instead. It runs before the plain-quote pass and
    // leaves its own redaction behind the same `(?!\[REDACTED\]@)` guard.
    //
    // Known limit, deliberate: an escaped-context URL that has a `host:port` and no
    // `@` of its own (`{\"u\":\"http://host:80/x\",\"e\":\"a@b.com\"}`) is not
    // bounded, and the later passes run it on to the sibling field's `@`. That is
    // corruption of a harmless line, never a leak. Stopping the later passes for
    // every URL that follows `\"` would fix it but would let a password that itself
    // contains `\"` ship its tail, and this file prefers over-redaction to that.
    $userinfoStart = '[A-Za-z][A-Za-z0-9+.\-]*://[^\s/:@]*:';
    $escaped = '~\\\\(?<q>["\'])('.$userinfoStart.')(?!\[REDACTED\]@)(?:(?!\\\\\k<q>)[^\s])+?(@)(?!(?:(?!\\\\\k<q>)[^\s])*@)~';
    $quoted = '~(?<q>["\'])('.$userinfoStart.')(?!\[REDACTED\]@)(?:\\\\.|(?!\k<q>)[^\s\\\\])+?(@)(?!(?:\\\\.|(?!\k<q>)[^\s\\\\])*@)~';
    $bare = '~('.$userinfoStart.')(?!\[REDACTED\]@)[^\s]+?(@)(?!\S*@)~';

    $line = pcreOrThrow(preg_replace($escaped, '\\\\$1$2[REDACTED]$3', $line), 'URL userinfo escaped-quote pass');
    $line = pcreOrThrow(preg_replace($quoted, '$1$2[REDACTED]$3', $line), 'URL userinfo quoted pass');

    return pcreOrThrow(preg_replace($bare, '$1[REDACTED]$2', $line), 'URL userinfo pass');
}

/**
 * Offset of the `]` that closes the array opened at `$open` (a `[`), or null if the
 * text ends first. Tracks string state (a `]` or `[` inside a string does not
 * count) and escapes, and nests, so `["a],", ["b"]]` closes at the last bracket.
 * Linear in the text, with no regex. `$text` may span lines (a pretty-printed
 * array).
 *
 * `$escaped` is JSON that is itself a string inside JSON, where every quote is
 * written `\"`: a string then opens and closes at `\"`. What a run of backslashes
 * before a quote means depends on its LENGTH, not on the last three characters
 * (found in round 2): the outer layer halves the run, and the inner layer reads
 * what is left, so one backslash (or 5, 9, ...) is the string's own quote, and
 * three (or 7, 11, ...) is a quote INSIDE the string. Reading `\\\"` as "an
 * escaped quote" wherever it appears made a value that ENDS in a backslash
 * (`C:\`, written with five before the closing quote) swallow its own terminator
 * and flip the string state for the rest of the line.
 */
function indexOfMatchingBracket(string $text, int $open, bool $escaped = false): ?int
{
    $length = strlen($text);
    $depth = 0;
    $inString = false;
    for ($i = $open; $i < $length; $i++) {
        $c = $text[$i];

        if ($escaped) {
            if ($c === '\\') {
                $run = strspn($text, '\\', $i);
                $beforeQuote = ($text[$i + $run] ?? '') === '"';
                $i += $run - 1 + ($beforeQuote ? 1 : 0); // land on the quote if there is one, else on the run's last backslash
                if ($beforeQuote && (!$inString || $run % 4 === 1)) {
                    $inString = !$inString;
                }

                continue;
            }
            if ($inString) {
                continue;
            }
        } elseif ($inString) {
            if ($c === '\\') {
                $i++;
            } elseif ($c === '"') {
                $inString = false;
            }

            continue;
        } elseif ($c === '"') {
            $inString = true;

            continue;
        }

        if ($c === '[') {
            $depth++;
        } elseif ($c === ']') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * `"secretKey": [ … ]` on one line — the whole bracketed value, however many
 * elements and however nested, becomes `["[REDACTED]"]` (ZST-428). An empty array
 * has nothing to leak and is left alone. An array whose `]` never arrives (a
 * truncated line) is redacted to the end of the line rather than shipped.
 * `$escaped` is the JSON-in-JSON form, `\"secretKey\": [\"…\"]`.
 */
function redactJsonArrayValues(string $line, bool $escaped = false): string
{
    $keyPattern = $escaped
        ? '/\\\\"('.KEY_CLASS.')\\\\"\s*:\s*\[/'
        : '/"('.KEY_CLASS.')"\s*:\s*\[/';
    $replacement = $escaped ? '[\\"[REDACTED]\\"]' : '["[REDACTED]"]';

    $count = pcreMatchOrThrow(
        preg_match_all($keyPattern, $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE),
        'JSON array key scan',
    );
    if ($count === 0) {
        return $line;
    }

    $out = '';
    $pos = 0;
    foreach ($matches as $m) {
        [$whole, $offset] = $m[0];
        if ($offset < $pos || !keyIsAlwaysSecret($m[1][0])) {
            continue; // inside a value already redacted, or a harmless key
        }

        $open = $offset + strlen($whole) - 1;
        $close = indexOfMatchingBracket($line, $open, $escaped);
        $inner = substr($line, $open + 1, ($close ?? strlen($line)) - $open - 1);
        if (trim($inner) === '') {
            continue;
        }

        $out .= substr($line, $pos, $open - $pos).$replacement;
        $pos = $close === null ? strlen($line) : $close + 1;
    }

    return $out.substr($line, $pos);
}

/**
 * An array under a secret key whose `]` is NOT on the same line (ZST-428, found in
 * review, and widened by round 2). The commonest source is
 * `json_encode($headers, JSON_PRETTY_PRINT)`:
 *
 *     "Authorization": [
 *         "Bearer abc123"
 *     ],
 *
 * where the opening line ends in `[`, so no same-line rule sees a value and the
 * two-line pair rule only knows a key ending in `:`. Round 2 showed the same leak
 * for every variation on it: the first element on the key line and the rest below,
 * an outer object opened on the key line (`"headers": {"Authorization": [`), and
 * text after the `[`. So the trigger is not the shape of the key line but this:
 * a secret key, a `[`, and no matching `]` on that line.
 *
 * The array, from its `[` to the matching `]` (found across lines, within 5000
 * lines, string- and depth-aware), collapses into ONE `["[REDACTED]"]` on the key
 * line, and the result REPLACES the closing line in `$lines`, so the caller runs it
 * through every rule again: whatever followed the `]` (a comma, a sibling key
 * with a secret of its own) is ordinary input, not something to hand back
 * unchecked. That also keeps CRLF captures consistent, since the closing line's
 * own line ending survives. A whitespace-only interior is left alone. An array that
 * never closes within the window is redacted to the end of it under a TRUNCATED
 * marker, like an unclosed PEM; a capture that ends on the key line has nothing
 * below it, so that line falls through to the same-line rule.
 *
 * The window grows 8, 64, 512, 5000 lines and stops at the first that closes, so an
 * ordinary three-line header costs eight lines of work, not five thousand.
 *
 * Known cost, deliberate: a key line that was CUT mid-string (a truncated log line,
 * `{"token":["abc`) cannot be told from one that continues, so with lines after it
 * it takes up to 5000 of them under the TRUNCATED marker. That is over-redaction,
 * visible and bounded, and this file prefers it to shipping the rest of an array.
 *
 * @param  string[]  $lines
 * @return array{0: string, 1: int, 2: bool}|null the replacement line, how many input lines it
 *                                                replaces, and whether to run it through the rules again
 */
function redactMultilineSecretArray(array $lines, int $i): ?array
{
    $first = $lines[$i];
    if (!str_contains($first, '[') || !str_contains($first, '"')) {
        return null;
    }

    pcreMatchOrThrow(
        preg_match_all('/"('.KEY_CLASS.')"\s*:\s*\[/', $first, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE),
        'multi-line array key scan',
    );

    foreach ($matches as $m) {
        if (!keyIsAlwaysSecret($m[1][0])) {
            continue;
        }

        $open = $m[0][1] + strlen($m[0][0]) - 1;
        if (indexOfMatchingBracket($first, $open) !== null) {
            continue; // closes on its own line: the same-line rule owns it
        }

        return redactArraySpanningLines($lines, $i, $open);
    }

    return null;
}

/**
 * @param  string[]  $lines
 * @return array{0: string, 1: int, 2: bool}|null see redactMultilineSecretArray()
 */
function redactArraySpanningLines(array $lines, int $i, int $open): ?array
{
    $first = $lines[$i];
    $prefix = substr($first, 0, $open);
    $rest = substr($first, $open);

    $window = [];
    $buffer = '';
    $close = null;
    foreach ([8, 64, 512, 5000] as $size) {
        $window = array_slice($lines, $i + 1, $size);
        $buffer = $rest."\n".implode("\n", $window);
        $close = indexOfMatchingBracket($buffer, 0);
        if ($close !== null || count($window) < $size) {
            break;
        }
    }

    if ($close === null) {
        // At the end of the capture, the last explode() element is the newline that
        // ended the input, not a line: leave it (and do not count it).
        if (count($window) < 5000 && $window !== [] && end($window) === '') {
            array_pop($window);
        }
        if ($window === []) {
            return null; // the capture ends on the key line: nothing below it to redact
        }

        $eol = str_ends_with($first, "\r") ? "\r" : '';

        return [$prefix.'[REDACTED ARRAY -- TRUNCATED, no closing bracket found within '.count($window).' lines]'.$eol, 1 + count($window), false];
    }

    $before = substr($buffer, 0, $close);
    $closingIndex = substr_count($before, "\n") - 1; // $rest is one line: its newline is the one added above
    if ($closingIndex < 0 || trim(substr($buffer, 1, $close - 1)) === '') {
        return null; // closes on the key line after all, or an empty array spread over lines
    }

    $column = $close - (int) strrpos($before, "\n") - 1;

    return [$prefix.'["[REDACTED]"]'.substr($window[$closingIndex], $column + 1), $closingIndex + 2, true];
}

/**
 * The escaped-string body: runs of non-backslashes; four backslashes (an escaped
 * backslash in the inner text, neutral); three backslashes and a quote (a quote
 * INSIDE this escaped string); or a lone backslash that does not start `\"`.
 * The four-backslash unit is what makes a run's LENGTH decide (round 2): a value
 * that ends in a backslash is written with five before its closing quote, so the
 * body takes four and leaves the fifth for the terminator, instead of reading the
 * last three plus the quote as an inner quote and running on into the next key.
 * Possessive, and by runs rather than a per-character `(?:(?!\\").)*?`: the probe
 * for a 5,000-element escaped array exhausted PCRE's JIT stack with the
 * per-character form.
 */
function escapedJsonStringBody(): string
{
    $bs = '\\\\';

    return '(?:[^'.$bs.']++|'.$bs.$bs.$bs.$bs.'|'.$bs.$bs.$bs.'"|'.$bs.'(?!"))*+';
}

/**
 * JSON same-line: `"key": "value"`, escape-aware, one or more per line
 * (minified JSON). Checks `keyIsAlwaysSecret()`, not just `keyLooksSecret()`
 * (LOU-831's third round) — `{"Authorization":"Bearer …"}` is an ordinary
 * shape for a dumped request/response and carries no other secret word.
 *
 * Four shapes, found by the ZST-390 review rounds and closed by ZST-428, each a
 * real leak until then:
 *
 * 1. `"key": "value"` — the original.
 * 2. `"key": ["v1", "v2"]` — an ARRAY value, the shape Guzzle's `getHeaders()` and
 *    Laravel's `$request->headers->all()` actually print (every header is a list),
 *    so `{"headers":{"Authorization":["Bearer x"]}}` shipped whole: the string-only
 *    rule never looked at a `[`. The whole bracketed value, nested arrays and
 *    numbers included, is replaced by one marker (the brackets stay, so the line
 *    still parses as JSON-shaped output). An array whose `]` is on a LATER line
 *    (pretty-printed, or elements continuing below the key line) is handled by
 *    `redactMultilineSecretArray()`.
 * 3. and 4. the same two shapes with every quote ESCAPED — JSON that is itself a
 *    string inside JSON, which is how Laravel writes a log line's context
 *    (`{\"Authorization\": \"Bearer x\"}`). Every JSON rule needed an unescaped
 *    quote beside the key, so these shipped whole. A quote INSIDE such a value,
 *    written `\\\"`, is part of the value and does not end it.
 *
 * The escaped shapes run first; they cannot collide with the plain ones (a plain
 * key needs `"` immediately after it, an escaped one has `\` there). Both are
 * skipped outright for a line with no quote, and the escaped ones for a line with
 * no `\"`: every line of a large capture pays for this function.
 *
 * Known gaps: an escaped-JSON value that is a nested object or spans lines, and an
 * escaped array that is pretty-printed (JSON-in-JSON is written on one line by
 * Laravel, so the multi-line escaped form is not expected).
 */
function redactJsonSameLine(string $line): string
{
    if (!str_contains($line, '"')) {
        return $line;
    }

    if (str_contains($line, '\\"')) {
        $escapedString = '/\\\\"('.KEY_CLASS.')\\\\"(\s*:\s*)\\\\"('.escapedJsonStringBody().')\\\\"/';
        $line = pcreOrThrow(preg_replace_callback($escapedString, static function (array $m): string {
            return keyIsAlwaysSecret($m[1]) ? '\\"'.$m[1].'\\"'.$m[2].'\\"[REDACTED]\\"' : $m[0];
        }, $line), 'JSON same-line escaped-string pass');

        // Arrays, escaped form, by the same string- and depth-aware scanner as the
        // plain form: numbers, nested arrays and mixed elements all redact.
        $line = redactJsonArrayValues($line, true);
    }

    // Arrays are found by a small string- and depth-aware scanner, not a regex: a
    // header dump can hold thousands of elements (a regex loop per element
    // exhausted PCRE's JIT stack), and a secret array can nest.
    $line = redactJsonArrayValues($line);

    $string = '/"('.KEY_CLASS.')"(\s*:\s*)"((?:\\\\.|[^"\\\\])*)"/';

    return pcreOrThrow(preg_replace_callback($string, static function (array $m): string {
        return keyIsAlwaysSecret($m[1]) ? '"'.$m[1].'"'.$m[2].'"[REDACTED]"' : $m[0];
    }, $line), 'JSON same-line pass');
}

/**
 * JSON pretty two-line: `"key":` alone on one line, its value alone on the
 * next (spec item 7 — the old var_dump-only two-line rule never covered a
 * plain pretty-printer's layout with no `N`-length marker to key off).
 * Returns the possibly-modified pair of lines; caller advances past both
 * when this matches.
 */
function redactJsonPrettyPair(string $keyLine, string $valueLine): ?array
{
    // pcreMatchOrThrow throughout (ZST-385, LOU-831 review): a bare
    // `preg_match` treats "didn't match" and "PCRE engine gave up" as the
    // SAME `false`/`0`-ish result, so a value line long enough to exhaust
    // PCRE's JIT stack (reproduced live at ~10KB, well under the
    // pcre.backtrack_limit ceiling main() raises — a JIT stack overflow is a
    // separate limit) read as "not this shape", nothing else matches a lone
    // giant value line, and the raw secret shipped in full at exit 0. Now it
    // throws, main() withholds the WHOLE capture and exits non-zero — the
    // same fail-closed contract every other PCRE call in this file already
    // has, not a new one invented for this pair.
    if (pcreMatchOrThrow(preg_match('/^(\s*)"('.KEY_CLASS.')"\s*:\s*$/', $keyLine, $km), 'JSON-pretty key line') !== 1) {
        return null;
    }
    if (!keyIsAlwaysSecret($km[2])) {
        return null;
    }
    // Quoted-string value line, or a bare JSON scalar (number / true / false
    // / null — found live by Mail, ZST-335 follow-up: a secret rendered as
    // a bare numeric PIN or feature-flag boolean was never covered, since
    // this rule required the value to be a quoted string).
    if (pcreMatchOrThrow(preg_match('/^(\s*)"(?:\\\\.|[^"\\\\])*"(\s*,?\s*)$/', $valueLine, $vm), 'JSON-pretty quoted value line') === 1) {
        return [$keyLine, $vm[1].'"[REDACTED]"'.$vm[2]];
    }
    if (pcreMatchOrThrow(preg_match('/^(\s*)(?:-?\d+(?:\.\d+)?|true|false|null)(\s*,?\s*)$/', $valueLine, $vm), 'JSON-pretty scalar value line') === 1) {
        return [$keyLine, $vm[1].'"[REDACTED]"'.$vm[2]];
    }

    return null;
}

/**
 * PHP array-dump: `"key" => "value"` or `'key' => 'value'`, quote-mismatch
 * safe (spec item 8). Checks `keyIsAlwaysSecret()` (LOU-831's third round) —
 * `'Authorization' => 'Bearer …',` is an ordinary var_export of a headers
 * array and carries no other secret word.
 *
 * Nested print_r/var_export where the value is itself an array — `[password]
 * => Array` on one line, the real value several lines later — is a KNOWN,
 * ACCEPTED gap, same as var_dump's own scalar-only assumption below: no
 * marker on the `Array` line ties it back to a length or type the way
 * `string(N)` does, so there is nothing reliable to key a two-line (or
 * N-line) rule off. Not fixed here.
 */
function redactArrayDumpLine(string $line): string
{
    $patterns = [
        '/(["\'])('.KEY_CLASS.')\1(\s*=>\s*)"((?:\\\\.|[^"\\\\])*)"/',
        '/(["\'])('.KEY_CLASS.')\1(\s*=>\s*)\'((?:\\\\.|[^\'\\\\])*)\'/',
    ];

    foreach ($patterns as $pattern) {
        $valueQuote = str_contains($pattern, '"((?:') ? '"' : "'";
        $line = pcreOrThrow(preg_replace_callback($pattern, static function (array $m) use ($valueQuote): string {
            return keyIsAlwaysSecret($m[2]) ? $m[1].$m[2].$m[1].$m[3].$valueQuote.'[REDACTED]'.$valueQuote : $m[0];
        }, $line), 'array-dump pass');
    }

    return $line;
}

/**
 * print_r: `[key] => value`, value runs to end of line — or up to the NEXT
 * `[key] =>` pair sharing that line — since print_r never quotes a scalar.
 * Checks `keyIsAlwaysSecret()` (LOU-831's third round) — `[Authorization]
 * => Bearer …` is an ordinary print_r of a headers array.
 *
 * 🔥 STOP-SHIP from loupe, round 5 (LOU-831, 2026-09-08): the value capture
 * was `.*$` — greedy AND unanchored to the next pair — so on a one-line
 * flattened dump like `[id] => 5 [password] => hunter2`, the FIRST pair's
 * value consumed the rest of the line (`5 [password] => hunter2`) before
 * the second pair was ever seen, and since `id` isn't secret the whole
 * remainder — the real secret — shipped untouched. The value now stops
 * (non-greedy, `.*?`) at whichever comes first: the next `[key] =>` on the
 * same line, or end of line, so each pair on the line gets classified on
 * its own.
 *
 * A nested value (`[password] => Array`, real value on a later line) is a
 * known, accepted gap — see `redactArrayDumpLine()`'s docblock for why.
 *
 * 🔥 STOP-SHIP from Mail, round 6 (2026-09-08): round 5's non-greedy value
 * stop introduced a NEW same-line bypass it didn't have before — a nested
 * array flattened onto one line, `[password] => Array ( [0] => hunter2 )`,
 * stops at the nested `[0] =>` (which looks identical to a sibling pair
 * boundary) and the numeric key `0` is never itself secret, so `hunter2`
 * shipped in clear. This is NOT the same "known, accepted gap" as the
 * paragraph above — that one is about the value being on a LATER line;
 * this one is reachable on the SAME line round 5 already handles.
 *
 * 🔥 STOP-SHIP from Mail, round 7 (2026-09-08): round 6's fix made the
 * greedy `Array\s*\(.*` consume-to-EOL fire off the VALUE's shape alone,
 * with no regard to whether the key it followed was secret. A single regex
 * alternation can't condition the value-capture strategy on the key —
 * `keyIsAlwaysSecret()` only ever ran in the callback, after the match (and
 * its extent) was already fixed. `[headers] => Array ( [Content-Type] =>
 * application/json ) [Authorization] => Bearer abc123` — `headers` isn't
 * secret, but its Array-shaped value still triggered the greedy branch,
 * swallowing the whole rest of the line as ONE match; since
 * `preg_replace_callback` never re-scans consumed text, the genuinely
 * secret `Authorization` pair inside that span was never seen. Restructured
 * per that root cause: classify each `[key] =>` key FIRST (a plain key/arrow
 * scan, like `redactDotenvLine()`'s), THEN choose how far its value extends —
 * only a SECRET key with an `Array (`-shaped value consumes to end of line
 * (round 6's safe over-redaction, now correctly scoped); a secret key with a
 * scalar value stops at the next sibling pair (round 5's fix); a non-secret
 * key's value — Array-shaped or not — is left untouched, so any pair nested
 * inside it (`Content-Type`, or a secret one like `Authorization`) still gets
 * its own turn at classification.
 *
 * Known, accepted gap (ZST-342, filed by review on round 7 above, not a
 * security leak): when that nested secret pair is the LAST `[key] =>` on the
 * line, its "scalar value, no next sibling → consume to true EOL" branch has
 * no sibling to stop at, so it also consumes the outer non-secret key's own
 * closing `)` — `[headers] => Array ( [Authorization] => Bearer abc123 )`
 * redacts to `[headers] => Array ( [Authorization] => [REDACTED]`, dropping
 * the trailing ` )`. Fixing it needs paren-nesting depth tracking to know
 * where the outer array actually closes — bigger scope than this function's
 * single-pass sibling-offset scan. Left as-is: this over-redacts a harmless
 * trailing paren, it never under-redacts the secret.
 */
function redactPrintRLine(string $line): string
{
    $count = pcreMatchOrThrow(
        preg_match_all('/\[('.KEY_CLASS.')\]\s*=>\s*/', $line, $matches, PREG_OFFSET_CAPTURE),
        'print_r key scan',
    );
    if ($count === 0) {
        return $line;
    }

    $lineLength = strlen($line);
    $out = '';
    $pos = 0;

    foreach ($matches[0] as $i => [$fullMatch, $offset]) {
        if ($offset < $pos) {
            continue; // nested pair already swallowed by a preceding secret Array-value redaction
        }

        $out .= substr($line, $pos, $offset - $pos);
        $key = $matches[1][$i][0];
        $arrowEnd = $offset + strlen($fullMatch);

        if (!keyIsAlwaysSecret($key)) {
            $out .= $fullMatch;
            $pos = $arrowEnd;
            continue;
        }

        // Secret key, `Array (`-shaped value: a nested structure can't be
        // safely bounded by a single `[key] =>` lookahead (round 6/round 7's
        // lesson) — consume to end of line unconditionally. The probe is
        // bounded to a short prefix (found in review): a plain
        // `substr($line, $arrowEnd)` copies everything to end of line on
        // EVERY secret-shaped key, turning a line with many such keys into
        // O(n²) work — 20k pairs (~330KB) took 0.34s, 200k didn't finish in
        // 60s. "Array (" is 7 bytes; nothing legitimate needs more than a
        // handful of characters of slack for the `\s*`.
        $looksNested = pcreMatchOrThrow(
            preg_match('/^Array\s*\(/', substr($line, $arrowEnd, 16)),
            'print_r nested-value check',
        ) === 1;
        if ($looksNested) {
            $out .= $fullMatch.'[REDACTED]';
            $pos = $lineLength;
            continue;
        }

        // Secret key, scalar value: stop at the next sibling pair on the
        // same line, or end of line (round 5's fix). The next MATCH is
        // always the next sibling here — preg_match_all's matches are
        // non-overlapping and offset-increasing, so matches[i + 1] can
        // never start before matches[i] ends (found in review: an earlier
        // draft searched for one anyway, dead code that always took its
        // first iteration). The whitespace separating the value from that
        // next pair is NOT part of the value — trimmed back off so it
        // survives in the output (matches the old lookahead regex, whose
        // `\s*` sat in the zero-width assertion rather than the consumed
        // `.*?`).
        $nextOffset = $matches[0][$i + 1][1] ?? null;
        $valueEnd = $nextOffset ?? $lineLength;
        if ($nextOffset !== null) {
            while ($valueEnd > $arrowEnd && strpos(" \t\r\n\v\f", $line[$valueEnd - 1]) !== false) {
                $valueEnd--;
            }
        }
        $out .= $fullMatch.'[REDACTED]';
        $pos = $valueEnd;
    }

    $out .= substr($line, $pos);

    return $out;
}

/**
 * var_dump two-line: `["key"]=>` then `string(N) "value"` on the next line —
 * or, for an object property, `["key":protected]=>` / `["key":"Class":private]=>`
 * (LOU-831's third round: the plain-array-only pattern never matched a
 * typed/visibility-annotated property render at all, so the value line
 * survived untouched). Checks `keyIsAlwaysSecret()` — `["Authorization"]=>`
 * followed by `string(N) "Bearer …"` is an ordinary var_dump of a headers
 * array. Returns the possibly-modified pair of lines, or null if this pair
 * isn't the shape at all (caller falls through to single-line rules on
 * keyLine).
 */
function redactVarDumpPair(string $keyLine, string $valueLine): ?array
{
    // pcreMatchOrThrow throughout (ZST-385, LOU-831 review) — see
    // redactJsonPrettyPair()'s identical note: a value line long enough to
    // exhaust PCRE's JIT stack (~10KB, reproduced live) used to read as
    // "not this shape" via a bare `preg_match`, and the raw secret shipped
    // whole at exit 0. Now it throws and main() withholds the capture.
    if (pcreMatchOrThrow(preg_match('/^(\s*)\["('.KEY_CLASS.')"(?::(?:"[^"]*":)?(?:protected|private))?\]=>\s*$/', $keyLine, $km), 'var_dump key line') !== 1) {
        return null;
    }
    if (!keyIsAlwaysSecret($km[2])) {
        return null;
    }

    if (pcreMatchOrThrow(preg_match('/^(\s*string\(\d+\)\s*)"(?:\\\\.|[^"\\\\])*"(\s*)$/', $valueLine, $vm), 'var_dump string value line') === 1) {
        return [$keyLine, $vm[1].'"[REDACTED]"'.$vm[2]];
    }

    // Non-string scalar renderings (found live by Mail, ZST-335 follow-up):
    // int(N), float(N) and bool(true|false) carry secret material just as
    // often as a string does — an API key stored as a numeric PIN, a
    // feature flag toggling a credential path. NULL is excluded on purpose:
    // there is no value there to leak. Arrays/objects are multi-line with
    // no single value line to key off — the same accepted gap already
    // documented on `redactArrayDumpLine()`, not fixed here either.
    if (pcreMatchOrThrow(preg_match('/^(\s*(?:int|float|bool)\()[^()]*(\)\s*)$/', $valueLine, $vm), 'var_dump scalar value line') === 1) {
        return [$keyLine, $vm[1].'[REDACTED]'.$vm[2]];
    }

    return null;
}

/**
 * HTTP header names that carry a credential by convention alone, with no
 * secret word in the name itself — spec item 6 names `Authorization`
 * explicitly (`Basic <base64>` / `Bearer <jwt>`). `keyLooksSecret()` can't
 * catch these; they're not a naming pattern, they're a fixed, small,
 * well-known set.
 *
 * 🔥 STOP-SHIP from loupe (LOU-831's third round, 2026-09-08): this constant
 * used to be consulted ONLY from `redactBareHeaderLine()` — the literal
 * `Name: value` shape. Every OTHER format that can carry the same header
 * name shipped the token in full: `{"Authorization":"Bearer …"}` (JSON),
 * `[Authorization] => Bearer …` (print_r of a headers array), `'Authorization'
 * => 'Bearer …'` (var_export). A failing HTTP-client test dumping its
 * request headers through any of those is ordinary, not an edge case, and
 * Authorization is the one header this file is supposed to treat as
 * unconditionally secret regardless of container shape. `keyIsAlwaysSecret()`
 * below is now the single check every per-format rule uses instead of calling
 * `keyLooksSecret()` directly — this list travels with all of them.
 */
const HEADER_ALWAYS_SECRET = ['authorization'];

/**
 * The one predicate every key-shaped rule (dotenv, JSON, array-dump,
 * print_r, var_dump, bare headers) must use — `keyLooksSecret()` alone
 * would leave `HEADER_ALWAYS_SECRET` a bare-header-only rule again, which is
 * exactly the STOP-SHIP above.
 */
function keyIsAlwaysSecret(string $ident): bool
{
    return keyLooksSecret($ident) || in_array(strtolower($ident), HEADER_ALWAYS_SECRET, true);
}

/**
 * Bare colon-headers (spec item 6): `Header-Name: value` to end of line, no
 * quotes. `splitWords()` already treats `-` as a separator, so
 * `X-Postmark-Server-Token` segments the same way `X_Postmark_Server_Token`
 * would.
 */
function redactBareHeaderLine(string $line): string
{
    // Leading `>`/`<` allowed: curl's verbose mode prefixes every header line
    // with one (`> Authorization: Bearer …`), a common shape in captured
    // diagnostic output (caught in review). Nothing broader — a general
    // "any prefix" match would start hitting unrelated `word: value` text.
    //
    // Identifier class includes `_` (found live by Mail, ZST-335 follow-up):
    // every sibling rule's identifier class is `[A-Za-z0-9_\-]+`; this one
    // alone was missing the underscore, so `Client_Secret: …` didn't match
    // this rule at all and shipped in the clear — no other rule covers bare
    // `Name: value` syntax, so there was nothing left to catch it.
    $pattern = '/^([<>]?\s*)([A-Za-z][A-Za-z0-9_\-]*)(\s*:\s*)(.+)$/';

    return pcreOrThrow(preg_replace_callback($pattern, static function (array $m): string {
        return keyIsAlwaysSecret($m[2]) ? $m[1].$m[2].$m[3].'[REDACTED]' : $m[0];
    }, $line), 'bare-header pass');
}

/**
 * Offset of the first `$quote` at or after `$from` that is NOT backslash-escaped
 * (whatever follows a backslash is part of the value, as in a shell `"ab\"cd"`
 * or JSON's `\"`), or false if the quote is never closed. The sibling
 * URL-userinfo rule skips `\\.` escapes the same way; a bare `strpos` here ended
 * `-H "X-Api-Key: ab\"cd"` at the escaped quote and shipped `cd`.
 */
function indexOfClosingQuote(string $line, string $quote, int $from): int|false
{
    $length = strlen($line);
    for ($i = $from; $i < $length; $i++) {
        if ($line[$i] === '\\') {
            $i++; // skip the escaped character, quote included

            continue;
        }
        if ($line[$i] === $quote) {
            return $i;
        }
    }

    return false;
}

/**
 * A secret HEADER carried MID-LINE rather than at the start of one (ZST-390,
 * found by Loupe): `redactBareHeaderLine()` only sees `Name: value` anchored at
 * the line start, so a captured shell command leaked whole —
 * `$ curl -H "Authorization: Bearer leak_x" https://x` passed through untouched,
 * although `HEADER_ALWAYS_SECRET`'s own docblock says Authorization must redact
 * "regardless of container shape", and a failing HTTP check echoing the command
 * it ran is an ordinary shape in `bin/eod` output.
 *
 * Three steps, all driven by `keyIsAlwaysSecret()` like every sibling (see the
 * comment at each step for why it is shaped the way it is):
 *
 * 1. A QUOTED `name: value` whose name is secret — `-H "Authorization: Bearer x"`,
 *    `-H 'X-Api-Key: y'`, `--header "X-Postmark-Server-Token: z"`. The quote must
 *    be immediately followed by the name and the colon, so JSON's `"key": "v"`
 *    (a quote sits between the name and the colon) is never matched here. Any
 *    secret-named header is covered, not only Authorization, and over-redaction
 *    is accepted as everywhere in this file: `echo "Password: hunter2"` redacts
 *    too, and that is the point, not a false positive.
 * 2. An UNQUOTED `-H Name:value` / `--header=Name:value` argument.
 * 3. `Authorization:` anywhere else on the line, redacted to end of line.
 *
 * Known gaps, none a regression (every one shipped in full before this rule
 * existed): a secret header named in prose with no quote, `-H` or leading
 * position; and a header value that a shell splits across arguments
 * (`-H X-Api-Key: abc`, colon then space, unquoted). JSON-shaped headers, array
 * values and JSON-in-JSON are covered by `redactJsonSameLine()` and
 * `redactMultilineSecretArray()` (ZST-428).
 */
function redactInlineHeaders(string $line): string
{
    // 1. Quoted `Name: value`. Every OPENING `"Name:` / `'Name:` is located by
    // its own offset rather than by one consuming match, so a secret header
    // nested inside a quoted span whose own name is harmless
    // (`echo 'Note: uses "X-Api-Key: y" header'`) is still found: a single
    // regex with a consuming value group swallowed the inner header along with
    // the outer span. The value runs to the next quote of the same kind, or to
    // the end of the line when the quote is never closed (a truncated capture:
    // `curl -H "X-Api-Key: abc https://x`), because an unterminated quote is
    // not a reason to ship the credential.
    $count = pcreMatchOrThrow(
        preg_match_all('/(["\'])('.HEADER_NAME_CLASS.')(\s*:\s*)/', $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE),
        'inline quoted-header scan',
    );
    if ($count > 0) {
        $out = '';
        $pos = 0;
        foreach ($matches as $m) {
            [$opening, $offset] = $m[0];
            if ($offset < $pos || !keyIsAlwaysSecret($m[2][0])) {
                continue; // inside a value already redacted, or a harmless name
            }

            $valueStart = $offset + strlen($opening);
            $close = indexOfClosingQuote($line, $m[1][0], $valueStart);
            $out .= substr($line, $pos, $valueStart - $pos).'[REDACTED]';
            $pos = $close === false ? strlen($line) : $close;
        }
        $line = $out.substr($line, $pos);
    }

    // 2. An UNQUOTED header argument (`curl -H X-Api-Key:abc`, `--header=Name:v`):
    // the shell allows it when the value has no spaces.
    $line = pcreOrThrow(preg_replace_callback(
        '/(?<![\w\-])((?:-H\s*|--header(?:=|\s+)))('.HEADER_NAME_CLASS.')(:)([^\s"\']+)/',
        static fn (array $m): string => keyIsAlwaysSecret($m[2]) ? $m[1].$m[2].$m[3].'[REDACTED]' : $m[0],
        $line,
    ), 'inline unquoted-header-argument pass');

    // 3. `Authorization:` (the always-secret names) anywhere else on the line,
    // redacted to END OF LINE like the line-start rule, so the credential goes
    // whatever its scheme is (`Bearer`, `Basic`, `ApiKey`, `AWS4-HMAC-SHA256`, a
    // bare token): an allow-list of scheme words redacted only the scheme word
    // of an unlisted one and shipped the credential after it. The look-behind
    // skips a name directly after a quote: those were handled, precisely, by
    // step 1, and this must not eat the closing quote and the rest of a curl
    // command. Idempotent on an already-redacted line. The pattern is built
    // once, not per line.
    static $always = null;
    $always ??= '/(?<!["\'])\b('.implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), HEADER_ALWAYS_SECRET)).')(\s*:\s*).+$/i';

    return pcreOrThrow(preg_replace($always, '$1$2[REDACTED]', $line), 'inline always-secret-header pass');
}

function redact(string $text): string
{
    $text = redactPemBlocks($text);
    $lines = explode("\n", $text);
    $out = [];

    $i = 0;
    $count = count($lines);
    while ($i < $count) {
        $line = $lines[$i];
        $next = $i + 1 < $count ? $lines[$i + 1] : null;

        if ($next !== null && ($pair = redactVarDumpPair($line, $next)) !== null) {
            $out[] = $pair[0];
            $out[] = $pair[1];
            $i += 2;
            continue;
        }

        if ($next !== null && ($pair = redactJsonPrettyPair($line, $next)) !== null) {
            $out[] = $pair[0];
            $out[] = $pair[1];
            $i += 2;
            continue;
        }

        if (($array = redactMultilineSecretArray($lines, $i)) !== null) {
            [$replacement, $consumed, $again] = $array;
            if ($again) {
                // The array is folded into its closing line, and that line goes round
                // again: what followed the `]` is ordinary input, not a tail to hand back.
                $i += $consumed - 1;
                $lines[$i] = $replacement;
                continue;
            }

            $out[] = $replacement;
            $i += $consumed;
            continue;
        }

        $line = redactUrlUserinfo($line);
        $line = redactDotenvLine($line);
        $line = redactJsonSameLine($line);
        $line = redactArrayDumpLine($line);
        $line = redactPrintRLine($line);
        $line = redactBareHeaderLine($line);
        $line = redactInlineHeaders($line);

        $out[] = $line;
        $i++;
    }

    return redactKnownValuePrefixes(implode("\n", $out));
}

function main(): int
{
    ini_set('pcre.backtrack_limit', '10000000');

    $input = stream_get_contents(STDIN);
    if ($input === false) {
        fwrite(STDERR, "redact-secrets: could not read stdin\n");

        return 1;
    }

    try {
        $output = redact($input);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'redact-secrets: '.$e->getMessage()."\n");

        return 1;
    }

    fwrite(STDOUT, $output);

    return 0;
}

exit(main());
