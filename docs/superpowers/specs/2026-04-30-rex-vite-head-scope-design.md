# `REX_VITE` head-scope bugfix — design

**Status:** approved
**Date:** 2026-04-30
**Target version:** v3.2.1
**Scope:** `lib/OutputFilter.php`, plus first-time PHPUnit infra, docs, version bump, release.

## Problem

`OutputFilter::rewriteHtml` currently runs `preg_replace_callback` for the
pattern `(?<!\w)REX_VITE(?:\[([^\]]*)\])?` against the entire response body.
Every occurrence anywhere in the rendered HTML — including literal `REX_VITE`
text inside `<code>` / `<pre>` blocks on documentation pages, slice content,
or anywhere else in `<body>` — gets replaced with the asset block.

The placeholder is documented as a `<head>` directive ("Add `REX_VITE` in
`<head>`", README §"Der `REX_VITE`-Platzhalter"). Replacement outside `<head>`
was never the intended behavior; it is a regression for any project whose
content includes literal mentions of the placeholder syntax.

## Goal

Restrict `REX_VITE` replacement to the **first occurrence inside the first
`<head>...</head>` block**. Body content (and any subsequent placeholders in
head) is left as literal text.

## Non-goals

- Multiple `REX_VITE` placeholders in `<head>` are **not** supported. The
  documented escape hatch for multiple entries is the pipe-separated form
  `REX_VITE[src="a.css|b.js"]`.
- DOM-level parsing of the response. We stay in regex/substring territory; the
  fix is small enough that introducing `DOMDocument` would be disproportionate
  and would change character-level fidelity of every frontend response.
- Test infrastructure beyond PHPUnit and a single test file. No CI integration,
  no coverage reporting, no static-analysis tooling.

## Approach

Slice-and-splice. The pure transformation is:

1. Locate the first `<head\b[^>]*>...</head>` substring with `preg_match`
   (case-insensitive, dotall, lazy `.*?`) using `PREG_OFFSET_CAPTURE`.
2. **No `<head>` found** → return content unchanged. Same outcome as today
   for the no-`</head>` edge case (auto-insert can't run either).
3. **`<head>` found**:
   - Run the existing `REX_VITE` replacement regex on the head slice with
     `limit = 1`.
   - If at least one match: splice the modified head back into the content.
   - If zero matches: run the existing auto-insert (`preg_replace` of `</head>`
     with `block . "\n</head>"`) against the head slice, then splice back.
4. Body content is never touched by either path.

### Public API

`OutputFilter::rewriteHtml(string $content): string` keeps its signature.
Callers (`OutputFilter::register` for the frontend `OUTPUT_FILTER` extension
point, and the `BLOCK_PEEK_OUTPUT` handler in `boot.php`) are unchanged.

### Testability seam

Pure transformation is split out:

```php
public static function rewriteHtml(string $content): string
{
    return self::rewriteHtmlWithBlock(
        $content,
        [Assets::class, 'renderBlock'],
    );
}

/** @internal Exposed for testing. */
public static function rewriteHtmlWithBlock(string $content, callable $renderBlock): string
{
    // …slice-and-splice logic; calls $renderBlock(?array $entries): string…
}
```

`Assets::renderBlock` reaches into `Server::factory()`, manifest reads, etc.,
so unit tests can't realistically call it. The injected callable is the
minimum surface increase needed to test the regex/string behavior in isolation.
The `@internal` annotation signals that this is not part of the public API.

## Test plan

`tests/OutputFilterTest.php` targets `OutputFilter::rewriteHtmlWithBlock()`
with a fixed stub returning `'<!--BLOCK-->'`, so assertions can be exact-string
matches rather than fuzzy patterns. Cases:

1. Single `REX_VITE` in `<head>` → replaced with the block.
2. `REX_VITE[src="x.js"]` in `<head>` → replaced; the parsed `src` is forwarded
   to the callable as `['x.js']`.
3. `REX_VITE` in `<head>` AND a literal `REX_VITE` in `<body>` (the documentation-
   page regression) → only the head occurrence is replaced; the body occurrence
   stays as literal text byte-for-byte.
4. Two `REX_VITE` in `<head>` → first replaced, second left as literal text
   (the explicit Option-B choice).
5. No `REX_VITE` anywhere, but `<head>` exists → auto-insert before `</head>`
   inside the head slice.
6. No `<head>` at all → content returned unchanged.
7. Empty input → empty output (existing fast path).

## Test infrastructure

New files / config (all excluded from the release zip):

- `composer.json` — `require-dev: { "phpunit/phpunit": "^10.5" }` + `scripts: { "test": "phpunit" }`.
  PHPUnit 10 is the latest line that supports PHP 8.1, the addon's floor.
  PHPUnit 11 needs 8.2.
- `phpunit.xml.dist` at repo root — bootstrap `vendor/autoload.php`, single
  `<testsuite>` rooted at `tests/`, `cacheDirectory=".phpunit.cache"`.
- `tests/OutputFilterTest.php` — the seven cases above.

Release-artifact hygiene:

- Add `tests`, `phpunit.xml.dist`, `.phpunit.cache` to `package.yml`'s
  `installer_ignore` list.
- Add matching `-x "tests/*" -x "phpunit.xml.dist" -x ".phpunit.cache/*"`
  entries to the zip command in `.github/workflows/publish-to-redaxo.yml`.
- `vendor/` is already gitignored except `vendor/autoload.php`, and CI runs
  `composer install --no-dev`, so PHPUnit's own files never reach the zip.

## Documentation changes

### `README.md`

The README is German-only (the English README was replaced in v3.1.0 — see
the v3.1.0 changelog entry "Documentation"). Tighten the existing German
`REX_VITE` paragraph (around the line "Wird `REX_VITE` in der gerenderten Seite
_nicht_ gefunden…") so the head-only constraint is explicit. Equivalent of:

> Der `REX_VITE`-Platzhalter wird **nur innerhalb des `<head>`-Tags ersetzt und
> nur beim ersten Vorkommen**. Jedes `REX_VITE` an anderer Stelle — Body-Inhalt,
> Code-Samples auf Dokumentationsseiten, Slice-Text — bleibt unverändert als
> Literal-Text stehen. Ist im `<head>` gar kein `REX_VITE` enthalten, wird der
> Asset-Block automatisch vor dem ersten `</head>` eingefügt.

Final wording is finalized at implementation time so the existing surrounding
paragraph reads naturally.

### `CHANGELOG.md`

New entry at the top:

```markdown
## **Version 3.2.1**

### Fixed

- **`REX_VITE` replacement scoped to `<head>`** (`lib/OutputFilter.php`). Previously
  `OutputFilter::rewriteHtml` replaced every `REX_VITE` occurrence anywhere in the
  rendered HTML, including literal mentions inside `<code>` / `<pre>` blocks on
  documentation pages that themselves describe how to use `viterex_addon`. The filter
  now finds the first `<head>...</head>` block and replaces only the first `REX_VITE`
  (or `REX_VITE[src="…"]`) inside it; subsequent placeholders and any `REX_VITE` text
  in `<body>` are left as literal text. Auto-insert before `</head>` is unchanged.

### Notes for upgraders

- If you (unusually) had multiple `REX_VITE` placeholders inside `<head>` to load
  different entries, only the first is now replaced. Combine them via the
  pipe-separated form: `REX_VITE[src="a.css|b.js"]`.

### Internal

- **PHPUnit added** as `require-dev`. `tests/OutputFilterTest.php` covers the
  head-only scoping, body-untouched behavior, multiple-in-head, auto-insert, and
  missing-`<head>` edge cases. Run via `composer test`. Test infrastructure
  (`tests/`, `phpunit.xml.dist`, `.phpunit.cache`) is excluded from the release zip
  via `package.yml` `installer_ignore` and the publish workflow.
- For testability, the pure transformation in `OutputFilter` is split into a thin
  public `rewriteHtml()` shim that delegates to a new `@internal
  rewriteHtmlWithBlock(string, callable)` method. Public API and behavior at all
  callers (frontend `OUTPUT_FILTER`, `BLOCK_PEEK_OUTPUT`) are unchanged.
```

## Versioning

Patch bump `3.2.0` → `3.2.1`. Rationale: the change restores the placeholder
to the behavior the README already documents. Anyone affected was relying on
undocumented behavior; the "Notes for upgraders" line surfaces the one real
edge case (multiple-in-head). Project precedent agrees — `v3.1.1` (HTTPS
checkbox) and `v3.1.2` (path renames requiring a re-save) both shipped as
patches with comparable observable changes.

`package.yml` is the source of truth. `package.json` is synced automatically
by the `prebuild` hook (`scripts/sync-version.js`) on `npm run build`.

## Release flow

Sequence (push, tag, and release-creation pause for confirmation per project
guidance):

1. Implement `OutputFilter` change + `rewriteHtmlWithBlock` seam.
2. Add PHPUnit dev dep, `phpunit.xml.dist`, `tests/OutputFilterTest.php`.
   `composer install` locally so `vendor/` gets PHPUnit.
3. Run `composer test` — must be green.
4. `package.yml` version `3.2.0` → `3.2.1`.
5. `package.yml` `installer_ignore` — add `tests`, `phpunit.xml.dist`,
   `.phpunit.cache`.
6. `.github/workflows/publish-to-redaxo.yml` — matching `-x` zip excludes.
7. `npm run build` — `prebuild` syncs `package.json`; Vite rebuilds badge into
   `assets/badge/`. Commit any resulting diff alongside the rest.
8. `README.md` — head-only paragraph (German, single block).
9. `CHANGELOG.md` — `## **Version 3.2.1**` block as above.
10. Single commit on `main`, e.g. `fix(v3.2.1): scope REX_VITE replacement to <head>`.
    Pause for confirmation before pushing.
11. `git push`, then `git tag v3.2.1 && git push --tags`.
12. `gh release create v3.2.1` with the v3.2.1 changelog block as the body.
    The release body is forwarded by the workflow as the MyREDAXO description.
13. Watch Actions: confirm the zip is attached to the GitHub release and the
    MyREDAXO publish step is green.

## Risks and mitigations

- **Regex against `<head>` is fragile in pathological HTML.** A `<head>` token
  inside a JS string or comment in the document body is unusual but possible.
  Mitigation: `<head\b[^>]*>` only matches actual head opening tags (with or
  without attributes); the lazy `.*?</head>` will pair it with the first
  closing `</head>`. Real-world Redaxo templates structure their HTML normally;
  the trade-off vs. DOM parsing is acceptable.
- **Auto-insert no longer runs when there is no `<head>` element at all.**
  Today's code already silently no-ops in that case (the `preg_replace` of
  `</head>` finds nothing to replace), so this is not a behavior change for
  practical responses; only the code path is now explicit instead of implicit.
- **Multiple-in-head pattern goes silent for the second placeholder.** Mitigated
  by the "Notes for upgraders" entry in the changelog and the EN/DE README
  update spelling out the constraint.
