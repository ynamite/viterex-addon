# `REX_VITE` head-scope bugfix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restrict `REX_VITE` placeholder replacement to the first occurrence inside the first `<head>...</head>` block; ship as v3.2.1.

**Architecture:** Slice-and-splice in `OutputFilter::rewriteHtml`. Find `<head>...</head>` via regex, run the existing `REX_VITE` replacement on that slice with `limit=1`, splice back. Public API and all caller wiring stay unchanged. Pure transformation is split into a thin `rewriteHtml()` shim that delegates to a new `@internal rewriteHtmlWithBlock(string, callable)` method so unit tests can stub the asset-block renderer without bootstrapping Redaxo.

**Tech Stack:** PHP 8.1+, PHPUnit 10.5 (new `require-dev`), Redaxo 5.13+, Vite 8.

**Reference spec:** `docs/superpowers/specs/2026-04-30-rex-vite-head-scope-design.md`

**Commit policy:** Per user direction, all changes (including the spec doc) land in a **single commit** at Task 10 — no intermediate commits. Tag and GitHub release in Task 11 pause for explicit confirmation before pushing or creating the release.

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `lib/OutputFilter.php` | Modify | Add `rewriteHtmlWithBlock(string, callable)` with slice-and-splice; convert `rewriteHtml()` to a thin shim. |
| `composer.json` | Modify | Add `phpunit/phpunit ^10.5` to `require-dev`; add `autoload-dev` PSR-4 mapping for tests; add `scripts.test`. |
| `phpunit.xml.dist` | Create | PHPUnit config (bootstrap `vendor/autoload.php`, single suite rooted at `tests/`). |
| `tests/OutputFilterTest.php` | Create | Seven test cases targeting `rewriteHtmlWithBlock`. |
| `package.yml` | Modify | Bump version `3.2.0` → `3.2.1`; add `tests`, `phpunit.xml.dist`, `.phpunit.cache` to `installer_ignore`. |
| `package.json` | Modify (auto) | Synced from `package.yml` by `scripts/sync-version.js` via `npm run build` `prebuild` hook. Don't edit by hand. |
| `assets/badge/viterex-badge.{js,css}` | Modify (auto) | Rebuilt by `npm run build`. Diff likely empty since assets-src didn't change; commit whatever results. |
| `.github/workflows/publish-to-redaxo.yml` | Modify | Add `-x "tests/*"`, `-x "phpunit.xml.dist"`, `-x ".phpunit.cache/*"` to the zip command. |
| `CHANGELOG.md` | Modify | Add `## **Version 3.2.1**` block at top (Fixed + Notes-for-upgraders + Internal). |
| `README.md` | Modify | Tighten the "Wichtig:" paragraph in the German `## Der REX_VITE-Platzhalter` section to spell out head-only + first-occurrence behavior. |
| `CLAUDE.md` | Modify | Update the "Architecture: REX_VITE placeholder" section so the auto-insert and scope notes match the new behavior. |
| `docs/superpowers/specs/2026-04-30-rex-vite-head-scope-design.md` | (already exists) | Lands in the same commit as the rest. |

---

## Task 1: Set up PHPUnit infrastructure

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml.dist`

- [ ] **Step 1.1: Update `composer.json`**

Replace the file contents with:

```json
{
  "name": "ynamite/viterex",
  "require": {
    "php": ">=8.1"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5"
  },
  "autoload": {
    "psr-4": {
      "Ynamite\\ViteRex\\": "lib/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Ynamite\\ViteRex\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "test": "phpunit"
  },
  "config": {
    "optimize-autoloader": true,
    "sort-packages": true,
    "platform": {
      "php": "8.3.24"
    }
  }
}
```

- [ ] **Step 1.2: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
    bootstrap="vendor/autoload.php"
    colors="true"
    cacheDirectory=".phpunit.cache"
    failOnWarning="true"
    failOnRisky="true"
>
    <testsuites>
        <testsuite name="default">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 1.3: Install dev dependencies**

Run: `composer install`
Expected: composer reports installing `phpunit/phpunit` and its transitive deps into `vendor/`. Exit code 0.

- [ ] **Step 1.4: Smoke-check the runner**

Run: `vendor/bin/phpunit --version`
Expected: prints `PHPUnit 10.5.x by Sebastian Bergmann and contributors.`

Run: `vendor/bin/phpunit`
Expected: prints something like `No tests executed!` and exits 0 (the suite is empty, but the runner is wired up).

- [ ] **Step 1.5: Verify `vendor/` is still gitignored**

Run: `git status --short -- vendor/`
Expected: empty output (only `vendor/autoload.php` is tracked, and composer install rewrote it but git treats that as modification of an already-tracked file — that diff lands in the final commit, which is fine).

> **No commit at this task.** Per the user's "single commit later" directive, all task work accumulates in the working tree until Task 10.

---

## Task 2: Write the failing tests (RED)

**Files:**
- Create: `tests/OutputFilterTest.php`

- [ ] **Step 2.1: Create `tests/OutputFilterTest.php`**

```php
<?php

declare(strict_types=1);

namespace Ynamite\ViteRex\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\OutputFilter;

final class OutputFilterTest extends TestCase
{
    /** @var Closure(?array<int,string>): string */
    private Closure $stubBlock;

    /** @var list<?array<int,string>> */
    private array $capturedEntries;

    protected function setUp(): void
    {
        $this->capturedEntries = [];
        $this->stubBlock = function (?array $entries): string {
            $this->capturedEntries[] = $entries;
            return '<!--BLOCK-->';
        };
    }

    public function testReplacesSingleRexViteInHead(): void
    {
        $html = '<html><head>REX_VITE</head><body>hi</body></html>';

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame('<html><head><!--BLOCK--></head><body>hi</body></html>', $out);
        $this->assertSame([null], $this->capturedEntries);
    }

    public function testForwardsParsedSrcAttribute(): void
    {
        $html = '<html><head>REX_VITE[src="x.js"]</head></html>';

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame('<html><head><!--BLOCK--></head></html>', $out);
        $this->assertSame([['x.js']], $this->capturedEntries);
    }

    public function testLeavesBodyOccurrencesUntouched(): void
    {
        $html = '<html><head>REX_VITE</head>'
              . '<body><pre>REX_VITE</pre><code>REX_VITE[src="a.js"]</code></body></html>';

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame(
            '<html><head><!--BLOCK--></head>'
            . '<body><pre>REX_VITE</pre><code>REX_VITE[src="a.js"]</code></body></html>',
            $out,
        );
        $this->assertSame([null], $this->capturedEntries);
    }

    public function testReplacesOnlyFirstWhenMultipleInHead(): void
    {
        $html = "<html><head>REX_VITE\nREX_VITE[src=\"x.js\"]</head><body></body></html>";

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame(
            "<html><head><!--BLOCK-->\nREX_VITE[src=\"x.js\"]</head><body></body></html>",
            $out,
        );
        $this->assertSame([null], $this->capturedEntries);
    }

    public function testAutoInsertsBeforeClosingHeadWhenNoPlaceholder(): void
    {
        $html = '<html><head><title>x</title></head><body></body></html>';

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame(
            "<html><head><title>x</title><!--BLOCK-->\n</head><body></body></html>",
            $out,
        );
        $this->assertSame([null], $this->capturedEntries);
    }

    public function testReturnsContentUnchangedWhenNoHeadElement(): void
    {
        $html = '<html><body>REX_VITE</body></html>';

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame($html, $out);
        $this->assertSame([], $this->capturedEntries);
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        $out = OutputFilter::rewriteHtmlWithBlock('', $this->stubBlock);

        $this->assertSame('', $out);
    }
}
```

- [ ] **Step 2.2: Run the suite to confirm RED**

Run: `vendor/bin/phpunit`
Expected: 7 errors, 7 tests. All errors say something like "Call to undefined method `Ynamite\ViteRex\OutputFilter::rewriteHtmlWithBlock()`" — the method we're about to implement.

> If any test passes at this point, something is wrong: stop and inspect.

---

## Task 3: Implement `rewriteHtmlWithBlock` (GREEN)

**Files:**
- Modify: `lib/OutputFilter.php`

- [ ] **Step 3.1: Replace the contents of `lib/OutputFilter.php`**

```php
<?php

namespace Ynamite\ViteRex;

use rex;
use rex_extension_point;

final class OutputFilter
{
    /**
     * `OUTPUT_FILTER` callback. Frontend-only — backend bails to avoid rewriting
     * literal "REX_VITE" strings that appear in admin UIs (e.g., the slice editor).
     * For the block_peek preview iframe, see the `BLOCK_PEEK_OUTPUT` handler in boot.php
     * which calls `rewriteHtml()` directly (preview is rendered inside a backend request).
     */
    public static function register(rex_extension_point $ep): void
    {
        if (rex::isBackend()) {
            return;
        }
        $content = $ep->getSubject();
        if (!is_string($content) || $content === '') {
            return;
        }
        $ep->setSubject(self::rewriteHtml($content));
    }

    /**
     * Pure HTML transformer. Replaces the **first** `REX_VITE` (or `REX_VITE[src="…"]`)
     * placeholder inside the **first `<head>...</head>` block** with the rendered asset
     * block. If the head contains no placeholder, the block is auto-inserted before the
     * closing `</head>`. Body content is never rewritten — literal `REX_VITE` inside
     * `<code>`, `<pre>`, slice text, etc. is preserved.
     *
     * Reusable across contexts (frontend OUTPUT_FILTER, block_peek preview, etc.).
     */
    public static function rewriteHtml(string $content): string
    {
        return self::rewriteHtmlWithBlock($content, [Assets::class, 'renderBlock']);
    }

    /**
     * @internal Exposed for unit testing — the public entrypoint is {@see rewriteHtml()}.
     *
     * @param callable(?array<int,string>): string $renderBlock Called with the parsed
     *     entries from a `REX_VITE[src="…"]` attribute, or `null` for a bare `REX_VITE`
     *     placeholder (use default entries) or for the auto-insert path.
     */
    public static function rewriteHtmlWithBlock(string $content, callable $renderBlock): string
    {
        if ($content === '') {
            return $content;
        }

        if (!preg_match('/<head\b[^>]*>.*?<\/head>/is', $content, $headMatch, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $headStart = $headMatch[0][1];
        $head      = $headMatch[0][0];
        $headLen   = strlen($head);

        $matchCount = 0;
        $newHead = preg_replace_callback(
            '/(?<!\w)REX_VITE(?:\[([^\]]*)\])?/',
            static function (array $matches) use ($renderBlock): string {
                $attrs = $matches[1] ?? '';
                return $renderBlock(self::parseEntries($attrs));
            },
            $head,
            1,
            $matchCount,
        );

        if ($newHead === null) {
            return $content;
        }

        if ($matchCount === 0) {
            $block = $renderBlock(null);
            if ($block !== '') {
                $autoInserted = preg_replace(
                    '/<\/head>/i',
                    $block . "\n</head>",
                    $newHead,
                    1,
                    $autoCount,
                );
                if ($autoInserted !== null && $autoCount === 1) {
                    $newHead = $autoInserted;
                }
            }
        }

        if ($newHead === $head) {
            return $content;
        }

        return substr_replace($content, $newHead, $headStart, $headLen);
    }

    private static function parseEntries(string $attrs): ?array
    {
        $attrs = trim($attrs);
        if ($attrs === '') {
            return null;
        }

        if (preg_match_all(
            '/([a-zA-Z_][\w-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))/',
            $attrs,
            $pairs,
            PREG_SET_ORDER,
        )) {
            foreach ($pairs as $pair) {
                $key = strtolower($pair[1]);
                $value = ($pair[2] ?? '') !== '' ? $pair[2] : (($pair[3] ?? '') !== '' ? $pair[3] : ($pair[4] ?? ''));
                if ($key === 'src' && $value !== '') {
                    return self::splitSrc($value);
                }
            }
        }

        return null;
    }

    private static function splitSrc(string $src): array
    {
        $parts = explode('|', $src);
        return array_values(array_filter(
            array_map(static fn($p) => trim($p), $parts),
            static fn($p) => $p !== '',
        ));
    }
}
```

- [ ] **Step 3.2: Run the suite to confirm GREEN**

Run: `vendor/bin/phpunit`
Expected: `OK (7 tests, NN assertions)`. All seven tests pass.

> If anything fails, stop and inspect — do not proceed. The implementation is wrong, not the test.

---

## Task 4: Bump version and rebuild badge assets

**Files:**
- Modify: `package.yml` (line 2)
- Modify: `package.json` (auto, via prebuild)
- Modify: `assets/badge/viterex-badge.{js,css}` (auto, likely no diff)

- [ ] **Step 4.1: Bump `package.yml` version**

In `package.yml` line 2, change:

```yaml
version: '3.2.0'
```

to:

```yaml
version: '3.2.1'
```

- [ ] **Step 4.2: Rebuild badge + sync `package.json`**

Run: `npm run build`
Expected:
- prebuild hook prints something like `✓ Synced version to 3.2.1 in package.json`.
- vite build prints output about emitting `assets/badge/viterex-badge.js` and `viterex-badge.css`.
- Exit code 0.

- [ ] **Step 4.3: Verify version sync landed**

Run: `grep -E '"version"' package.json`
Expected: `  "version": "3.2.1",`

Run: `grep -E "^version" package.yml`
Expected: `version: '3.2.1'`

- [ ] **Step 4.4: Inspect any badge asset diff**

Run: `git status --short -- assets/badge/`
Expected: either empty (no diff) or the two files (`assets/badge/viterex-badge.js`, `assets/badge/viterex-badge.css`) listed as modified. Either is fine — `assets-src/` didn't change in this fix, so the rebuild is essentially a no-op or a deterministic re-emit.

If files show as modified, glance at the diff: `git diff -- assets/badge/`. The diff should be empty or minor (whitespace, hash changes) — if it shows substantive code differences, stop and investigate.

---

## Task 5: Exclude test infra from the release zip

**Files:**
- Modify: `package.yml` (`installer_ignore` list)
- Modify: `.github/workflows/publish-to-redaxo.yml` (zip command)

- [ ] **Step 5.1: Extend `package.yml` `installer_ignore`**

Locate the `installer_ignore:` block in `package.yml` (near the bottom). Add three entries to the existing list. The relevant block becomes:

```yaml
installer_ignore:
  - node_modules
  - assets-src
  - scripts
  - .github
  - .gitignore
  - .prettierrc
  - .stylelintrc.json
  - jsconfig.json
  - package.json
  - package-lock.json
  - postcss.config.js
  - vite.config.js
  - .DS_Store
  - .editorconfig
  - tests
  - phpunit.xml.dist
  - .phpunit.cache
```

- [ ] **Step 5.2: Extend the workflow zip command**

Open `.github/workflows/publish-to-redaxo.yml`. Find the `Create release archive` step's `zip -r ... \` block. Add three `-x` lines anywhere inside the existing list of exclusions (immediately after `-x ".editorconfig" \` is a sensible spot):

```yaml
            -x "tests/*" \
            -x "phpunit.xml.dist" \
            -x ".phpunit.cache/*"
```

Make sure the line preceding the new block ends with `\ ` (continuation) and the last line of the entire `zip` command does **not** end with `\`.

- [ ] **Step 5.3: Sanity-check workflow YAML**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/publish-to-redaxo.yml'))"`
Expected: no output, exit 0. (If python3 isn't available, run `npx --yes js-yaml .github/workflows/publish-to-redaxo.yml > /dev/null` instead — also expected silent + exit 0.)

---

## Task 6: Update `CHANGELOG.md`

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 6.1: Insert v3.2.1 block at the top**

Open `CHANGELOG.md`. Immediately after the `# Changelog` heading and the blank line that follows, insert this block (so it sits above the existing `## **Version 3.2.0**`):

```markdown
## **Version 3.2.1**

### Fixed

- **`REX_VITE` replacement scoped to `<head>`** (`lib/OutputFilter.php`). Previously `OutputFilter::rewriteHtml` replaced every `REX_VITE` occurrence anywhere in the rendered HTML, including literal mentions inside `<code>` / `<pre>` blocks on documentation pages that themselves describe how to use `viterex_addon`. The filter now finds the first `<head>...</head>` block and replaces only the first `REX_VITE` (or `REX_VITE[src="…"]`) inside it; subsequent placeholders and any `REX_VITE` text in `<body>` are left as literal text. Auto-insert before `</head>` is unchanged.

### Notes for upgraders

- If you (unusually) had multiple `REX_VITE` placeholders inside `<head>` to load different entries, only the first is now replaced. Combine them via the pipe-separated form: `REX_VITE[src="src/main.css|src/main.js"]`.

### Internal

- **PHPUnit added** as `require-dev` (`phpunit/phpunit ^10.5`). `tests/OutputFilterTest.php` covers the head-only scoping, body-untouched behavior, multiple-in-head, auto-insert, and missing-`<head>` edge cases. Run via `composer test`. Test infrastructure (`tests/`, `phpunit.xml.dist`, `.phpunit.cache`) is excluded from the release zip via `package.yml` `installer_ignore` and the publish workflow.
- For testability, the pure transformation in `OutputFilter` is split into a thin public `rewriteHtml()` shim that delegates to a new `@internal rewriteHtmlWithBlock(string, callable)` method. Public API and behavior at all callers (frontend `OUTPUT_FILTER`, `BLOCK_PEEK_OUTPUT`) are unchanged.
```

- [ ] **Step 6.2: Verify the file**

Run: `head -25 CHANGELOG.md`
Expected: the file starts with `# Changelog`, then the v3.2.1 block, then `## **Version 3.2.0**`. No stray duplicates.

---

## Task 7: Update `README.md`

**Files:**
- Modify: `README.md` (line 139, "Wichtig:" paragraph)

- [ ] **Step 7.1: Replace the "Wichtig:" paragraph**

In `README.md`, find this exact line (currently ~line 139 in the `## Der REX_VITE-Platzhalter` section):

```
**Wichtig:** Wird `REX_VITE` in der gerenderten Seite _nicht_ gefunden, fügt der Output-Filter den Block automatisch vor dem ersten `</head>` ein. Du kannst den Platzhalter also auch ganz weglassen — bequem, aber explizit ist besser, wenn du Kontrolle über die Position willst.
```

Replace it with:

```
**Wichtig:** Der `REX_VITE`-Platzhalter wird **nur innerhalb von `<head>` und nur beim ersten Vorkommen** ersetzt. Jedes weitere `REX_VITE` — im Body, in Code-Beispielen auf Doku-Seiten, in Slice-Inhalten — bleibt unverändert als Literal-Text stehen. Wird im `<head>` gar kein `REX_VITE` gefunden, fügt der Output-Filter den Asset-Block automatisch vor dem ersten `</head>` ein. Du kannst den Platzhalter also auch ganz weglassen — bequem, aber explizit ist besser, wenn du Kontrolle über die Position willst.
```

- [ ] **Step 7.2: Verify**

Run: `grep -n 'nur innerhalb von' README.md`
Expected: one line of output around line 139, containing the new paragraph fragment.

Run: `grep -c 'nicht_ gefunden, fügt der Output-Filter' README.md`
Expected: `0` (the old phrasing is gone).

---

## Task 8: Update `CLAUDE.md` architecture notes

**Files:**
- Modify: `CLAUDE.md` (the "Architecture: REX_VITE placeholder" section, around lines 37-45)

- [ ] **Step 8.1: Replace the third bullet and the placeholder-forms paragraph**

Find this block in `CLAUDE.md` (currently lines 43-45 of the "Architecture: REX_VITE placeholder" section):

```
- **Auto-insert**: if no `REX_VITE` placeholder is found anywhere, `rewriteHtml` injects the asset block before the first `</head>`. Skip this if the rendered block is empty (e.g., dev with hot-file gone but no manifest yet).

Placeholder forms: `REX_VITE` (default entries), `REX_VITE[src="x.js"]` (one), `REX_VITE[src="a.css|b.js"]` (pipe-separated). Regex uses `(?<!\w)` so `REX_VITES` etc. don't match.
```

Replace with:

```
- **Head-scoped, first-occurrence-only**: `rewriteHtml` finds the first `<head>...</head>` block and replaces only the first `REX_VITE` (or `REX_VITE[src="…"]`) inside it. Subsequent placeholders in `<head>` and any `REX_VITE` text in `<body>` (docs pages, slice content, `<code>` / `<pre>` blocks) are left as literal text. The transformation is split into a thin public `rewriteHtml()` shim that delegates to `@internal rewriteHtmlWithBlock(string, callable)` — tests target the latter with a stub block renderer (see `tests/OutputFilterTest.php`).
- **Auto-insert**: if `<head>` exists but contains no `REX_VITE` placeholder, the asset block is injected before the closing `</head>`. Skipped if the rendered block is empty (e.g., dev with hot-file gone but no manifest yet) or if the response has no `<head>` at all.

Placeholder forms: `REX_VITE` (default entries), `REX_VITE[src="x.js"]` (one), `REX_VITE[src="a.css|b.js"]` (pipe-separated). Regex uses `(?<!\w)` so `REX_VITES` etc. don't match. Multiple placeholders in `<head>` are not supported — use the pipe-separated form for multiple entries.
```

- [ ] **Step 8.2: Verify**

Run: `grep -n 'Head-scoped, first-occurrence-only' CLAUDE.md`
Expected: one line of output.

Run: `grep -c 'if no \`REX_VITE\` placeholder is found anywhere' CLAUDE.md`
Expected: `0` (old phrasing gone).

---

## Task 9: Run the full test + build chain one more time

**Files:** none

- [ ] **Step 9.1: Tests still green**

Run: `vendor/bin/phpunit`
Expected: `OK (7 tests, NN assertions)`.

- [ ] **Step 9.2: Build still clean**

Run: `npm run build`
Expected: prebuild reports version already synced (`✓ Version 3.2.1 already synced`), Vite emits the badge files, exit 0.

- [ ] **Step 9.3: Eyeball the full diff**

Run: `git status`
Expected modified/new files (no others):

- `M CLAUDE.md`
- `M CHANGELOG.md`
- `M README.md`
- `M composer.json`
- `M lib/OutputFilter.php`
- `M package.yml`
- `M package.json`
- `M .github/workflows/publish-to-redaxo.yml`
- `M assets/badge/viterex-badge.js` (or unchanged)
- `M assets/badge/viterex-badge.css` (or unchanged)
- `M vendor/autoload.php` (composer install rewrote it; no functional change)
- `?? phpunit.xml.dist`
- `?? tests/OutputFilterTest.php`
- `?? docs/superpowers/specs/2026-04-30-rex-vite-head-scope-design.md`
- `?? docs/superpowers/plans/2026-04-30-rex-vite-head-scope.md`

If anything else shows up (e.g., stray `composer.lock` modifications, files outside the addon), inspect before committing.

Run: `git diff --stat`
Expected: a manageable diff. `lib/OutputFilter.php` should be the largest code-change diff; everything else is small to moderate.

---

## Task 10: Single commit on `main`

**Files:** none modified — git operations only.

> **PAUSE POINT.** Before this task runs the commit, the implementer must show `git status` to the user and confirm the file list looks right.

- [ ] **Step 10.1: Stage the intended files explicitly**

Don't use `git add -A` or `git add .`. Stage by name to keep `node_modules/` and any local-only files out:

```bash
git add \
  CLAUDE.md \
  CHANGELOG.md \
  README.md \
  composer.json \
  lib/OutputFilter.php \
  package.yml \
  package.json \
  .github/workflows/publish-to-redaxo.yml \
  assets/badge/viterex-badge.js \
  assets/badge/viterex-badge.css \
  vendor/autoload.php \
  phpunit.xml.dist \
  tests/OutputFilterTest.php \
  docs/superpowers/specs/2026-04-30-rex-vite-head-scope-design.md \
  docs/superpowers/plans/2026-04-30-rex-vite-head-scope.md
```

> Some of those files may not actually be modified (e.g., the badge assets or `vendor/autoload.php`). `git add` on an unchanged file is a no-op — that's fine. Don't omit any line in case the file *is* modified.

- [ ] **Step 10.2: Verify the staged set**

Run: `git status --short`
Expected: every line starts with a green `M `, `A `, or similar (i.e., everything is staged). Nothing in the unstaged column.

If any unstaged changes remain, inspect — usually it means a file got modified after staging.

- [ ] **Step 10.3: Commit**

```bash
git commit -m "$(cat <<'EOF'
fix(v3.2.1): scope REX_VITE replacement to <head>, first occurrence only

OutputFilter::rewriteHtml previously replaced every REX_VITE occurrence
anywhere in the rendered HTML. The filter now finds the first
<head>...</head> block and replaces only the first REX_VITE (or
REX_VITE[src="…"]) inside it. Subsequent placeholders in <head> and any
REX_VITE text in <body> (docs pages, slice content, <code> / <pre>) are
left as literal text. Auto-insert before </head> is unchanged.

Pure transformation extracted to @internal rewriteHtmlWithBlock(string,
callable) for testability; public rewriteHtml() delegates to it.

Adds PHPUnit 10.5 as require-dev and tests/OutputFilterTest.php covering
head-only scoping, body-untouched behavior, multiple-in-head,
auto-insert, and missing-<head> edge cases. Test infra excluded from the
release zip via package.yml installer_ignore and the publish workflow.
EOF
)"
```

Expected: `[main <hash>] fix(v3.2.1): scope REX_VITE replacement to <head>, first occurrence only` and a file/insertion summary.

- [ ] **Step 10.4: Verify the commit**

Run: `git log -1 --stat`
Expected: the new commit on top, with all the staged files listed.

Run: `git status`
Expected: `nothing to commit, working tree clean` (or, at most, residual untracked files like `node_modules/` that we don't ship).

---

## Task 11: Push, tag, and GitHub release

> **PAUSE POINT.** Each step in this task pushes work to a shared system. Confirm with the user before running each one. The user explicitly opted in to single-commit + tag + release at the end of this plan, but per project guidance ("authorization stands for the scope specified, not beyond") each remote-mutating step is its own confirmation.

- [ ] **Step 11.1: Push the commit to `main`**

After confirming with the user:

```bash
git push origin main
```

Expected: refs updated, no errors. (If a pre-push hook runs and fails, fix the underlying issue — do not pass `--no-verify`.)

- [ ] **Step 11.2: Create and push the tag**

After confirming with the user:

```bash
git tag v3.2.1
git push origin v3.2.1
```

Expected: tag created locally, then pushed. `gh api repos/:owner/:repo/git/refs/tags/v3.2.1` (optional sanity check) returns the tagged ref.

- [ ] **Step 11.3: Create the GitHub release**

After confirming with the user. The release body forwards verbatim to MyREDAXO via the publish workflow's `installer-action` step, so use the v3.2.1 changelog block as the body. Extract it to a temp file:

```bash
awk '/^## \*\*Version 3\.2\.1\*\*/{flag=1; next} /^## \*\*Version /{flag=0} flag' CHANGELOG.md > /tmp/v3.2.1-release-notes.md
```

Then verify it looks right:

```bash
cat /tmp/v3.2.1-release-notes.md
```

Expected: the three subsections (`### Fixed`, `### Notes for upgraders`, `### Internal`) without the `## **Version 3.2.1**` heading itself (GitHub adds the title from the tag).

Then create the release:

```bash
gh release create v3.2.1 \
  --title "v3.2.1 — REX_VITE head-scope fix" \
  --notes-file /tmp/v3.2.1-release-notes.md
```

Expected: returns the release URL (e.g., `https://github.com/ynamite/viterex_addon/releases/tag/v3.2.1`).

- [ ] **Step 11.4: Watch the publish workflow**

```bash
gh run watch
```

Expected:
- Composer install (no-dev), npm ci, npm run build all green.
- `Create release archive` zips the addon (without `tests/`, `phpunit.xml.dist`, `.phpunit.cache`).
- `Upload release asset` attaches `viterex_addon-v3.2.1.zip` to the release.
- `installer-action` (final step) publishes to MyREDAXO. Workflow concludes successfully.

If the workflow fails, do not retry blindly — open the failed step's logs (`gh run view --log-failed`) and diagnose.

- [ ] **Step 11.5: Final confirmation**

Run: `gh release view v3.2.1`
Expected: shows the release with `viterex_addon-v3.2.1.zip` attached and the v3.2.1 changelog block in the body.

Optional manual check: visit MyREDAXO and confirm v3.2.1 is listed as the latest version of `viterex_addon`.

---

## Self-review notes

**Spec coverage:**
- OutputFilter slice-and-splice + seam → Task 3 ✓
- Seven test cases → Task 2 ✓
- PHPUnit infra (composer, phpunit.xml.dist) → Task 1 ✓
- Version bump 3.2.0 → 3.2.1 → Task 4 ✓
- npm run build → Task 4 ✓
- installer_ignore + workflow zip excludes → Task 5 ✓
- CHANGELOG with Notes-for-upgraders → Task 6 ✓
- README German paragraph → Task 7 ✓
- CLAUDE.md update → Task 8 (added beyond spec; spec called this out as "comprehensive documentation" which CLAUDE.md is part of)
- Single commit → Task 10 ✓
- Tag + GitHub release with pause-for-confirmation → Task 11 ✓

**Type/signature consistency:**
- `rewriteHtmlWithBlock(string $content, callable $renderBlock): string` — used identically in Tasks 2, 3, 8.
- `$renderBlock` callable signature `(?array<int,string>): string` — same in spec, Task 2 docblock, Task 3 docblock.
- Test method names line up with the seven cases listed in the spec.

**Placeholder scan:** none of the disallowed phrases (TBD, TODO, "implement later", "appropriate error handling", etc.) appear in the plan. All steps include literal commands or literal code.

**Out-of-scope guardrails:**
- No new test cases added beyond the seven in the spec.
- No `OutputFilter` cleanup beyond the seam refactor.
- No `installer_ignore` cleanup of pre-existing entries.
- CLAUDE.md update is the one in-scope addition; rationalized in the file-structure note.
