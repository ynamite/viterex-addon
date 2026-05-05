# SVG Optimizer Simplification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the dev-stage `MEDIA_*` SVGO shell-out, move automatic media-pool optimization to `npm run build`, add a `viterex:optimize-svgs` console command for manual runs, and delete the now-unnecessary `OptimizerFactory` abstraction.

**Architecture:** Each surface picks its engine inline now: prod/staging `MEDIA_*` hook always uses `PhpOptimizer`; the Vite plugin always uses SVGO (extended to walk media-pool during `buildStart` only); the new console command picks SVGO if available, else `PhpOptimizer`. A shared sidecar JSON cache (`<addon-cache-dir>/svg-optimized.json`, sha1 of post-optimization content keyed by project-relative path) lets both runtimes skip files that are already in their optimal form.

**Tech Stack:** PHP 8.3+, PHPUnit 10.5, Symfony Console (via `rex_console_command`), Node 20+, Vite 8, SVGO 4 (npm), `mathiasreker/php-svg-optimizer ^8.5`.

**Spec:** `docs/superpowers/specs/2026-05-05-svg-optimizer-simplification-design.md`.

---

## File Structure

**New files:**

- `lib/Svg/OptimizationCache.php` — pure I/O helper. Reads/writes the sidecar JSON. Hash-keyed against post-optimization content. Constructor takes the absolute JSON path; no Redaxo runtime calls in public methods (mirrors `lib/Deploy/Sidecar.php` testability convention).
- `lib/Console/OptimizeSvgsCommand.php` — `viterex:optimize-svgs` Symfony console command. Walks `<assets_source_dir>` and `<media_dir>`. Inline engine selection (`SvgoCli` if available, else `PhpOptimizer`). Honors `svg_optimize_enabled`. Flags: `--dry-run`, `--force`.
- `tests/Svg/OptimizationCacheTest.php` — round-trip, missing-key, corrupted-JSON fail-open, `--force`-equivalent clear behavior.

**Modified files:**

- `lib/Media/SvgHook.php` — adds dev short-circuit at top of closure; replaces `OptimizerFactory::for(...)` call with direct `new PhpOptimizer()`. Drops `OptimizerFactory` import; adds `PhpOptimizer` import.
- `lib/Config.php` — extends `syncStructureJson()` to emit `media_dir` and `cache_dir` (both derived from `rex_path::*`, NOT added to `Config::DEFAULTS`).
- `assets/viterex-vite-plugin.js` — extends `svgOptimizePlugin` signature with `mediaDir` + `cachePath`; adds media-pool walk during `buildStart` only; integrates cache load/persist; reads `media_dir` and `cache_dir` from `structure.json`.
- `CHANGELOG.md` — new "Changed" subsection under 3.3.0 documenting the simplification (since 3.3.0 hasn't shipped externally, this is amendments to the in-progress release).
- `README.md` — replace SVG-optimization stage table with the post-simplification version; document the console command.
- `CLAUDE.md` — drop the `OptimizerFactory` mention from "Things to be careful about" and update the 3.3.0 roadmap entry.

**Deleted files:**

- `lib/Svg/OptimizerFactory.php`
- `tests/Svg/OptimizerFactoryTest.php`

---

## Task 1: `OptimizationCache` PHP helper (foundation)

**Files:**
- Create: `lib/Svg/OptimizationCache.php`
- Test: `tests/Svg/OptimizationCacheTest.php`

This is the shared cache abstraction the console command and (logically) the Vite plugin both target. Pure I/O, no Redaxo deps in public methods. We TDD it first because it's the foundation everything else builds on.

- [ ] **Step 1: Write the failing tests**

Create `tests/Svg/OptimizationCacheTest.php`:

```php
<?php

namespace Ynamite\ViteRex\Tests\Svg;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Svg\OptimizationCache;

final class OptimizationCacheTest extends TestCase
{
    private string $tmpFile = '';

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'viterex-cache-') . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    public function testEmptyWhenFileMissing(): void
    {
        $cache = new OptimizationCache('/nonexistent/path/cache.json');
        $this->assertFalse($cache->isFresh('any/path.svg', 'content'));
    }

    public function testRecordAndPersistRoundTrip(): void
    {
        $cache = new OptimizationCache($this->tmpFile);
        $cache->record('img/icon.svg', '<svg>final</svg>');
        $cache->persist();

        $reloaded = new OptimizationCache($this->tmpFile);
        $this->assertTrue($reloaded->isFresh('img/icon.svg', '<svg>final</svg>'));
        $this->assertFalse($reloaded->isFresh('img/icon.svg', '<svg>different</svg>'));
        $this->assertFalse($reloaded->isFresh('img/other.svg', '<svg>final</svg>'));
    }

    public function testClearEmptiesInMemoryEntries(): void
    {
        $cache = new OptimizationCache($this->tmpFile);
        $cache->record('img/icon.svg', '<svg/>');
        $cache->clear();
        $this->assertFalse($cache->isFresh('img/icon.svg', '<svg/>'));
    }

    public function testCorruptedJsonFailsOpen(): void
    {
        file_put_contents($this->tmpFile, '{not valid json');
        $cache = new OptimizationCache($this->tmpFile);
        $this->assertFalse($cache->isFresh('img/icon.svg', '<svg/>'));
        // Persist should overwrite the corrupted file with a valid (empty) object.
        $cache->persist();
        $this->assertSame([], json_decode((string) file_get_contents($this->tmpFile), true));
    }

    public function testNonStringEntriesAreSkippedOnLoad(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'img/good.svg' => sha1('<svg>x</svg>'),
            'img/bad.svg'  => ['not', 'a', 'string'],
        ]));
        $cache = new OptimizationCache($this->tmpFile);
        $this->assertTrue($cache->isFresh('img/good.svg', '<svg>x</svg>'));
        // Bad entry skipped on load — never present in the cache map.
        $this->assertFalse($cache->isFresh('img/bad.svg', 'anything'));
    }

    public function testPersistCreatesParentDirectory(): void
    {
        $nested = sys_get_temp_dir() . '/viterex-test-' . uniqid() . '/sub/dir/cache.json';
        $cache = new OptimizationCache($nested);
        $cache->record('a.svg', '<svg/>');
        $cache->persist();
        $this->assertFileExists($nested);
        @unlink($nested);
        @rmdir(dirname($nested));
        @rmdir(dirname($nested, 2));
        @rmdir(dirname($nested, 3));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit tests/Svg/OptimizationCacheTest.php
```

Expected: FAIL with `Class "Ynamite\ViteRex\Svg\OptimizationCache" not found`.

- [ ] **Step 3: Implement `OptimizationCache`**

Create `lib/Svg/OptimizationCache.php`:

```php
<?php

namespace Ynamite\ViteRex\Svg;

/**
 * Sidecar JSON cache shared by `OptimizeSvgsCommand` (PHP) and the Vite
 * plugin's media-pool walk (Node). Maps project-relative file paths to the
 * sha1 of their POST-optimization content. On the next scan, a file whose
 * current on-disk sha1 matches the recorded value is skipped — meaning it's
 * still in its optimal form and re-optimizing would be a no-op.
 *
 * Why post-optimization (not pre)? Files are mutated 1:1 in place, so the
 * "current" disk content IS the post-optimization content from the last run.
 * Hashing post-optimization keeps the freshness check trivial:
 *
 *   isFresh(path, currentContent) === (recorded[path] === sha1(currentContent))
 *
 * Pure I/O + serialization. No Redaxo runtime calls in public methods, so
 * the class is unit-testable without a bootstrap (mirrors the convention
 * established by `lib/Deploy/Sidecar.php`).
 *
 * Concurrency: not a concern in practice. You don't run `npm run build` and
 * `bin/console viterex:optimize-svgs` simultaneously. Last-writer-wins on
 * the rare race; worst case is one run's records get clobbered and those
 * files re-optimize on the next pass — idempotent, no harm.
 */
final class OptimizationCache
{
    /** @var array<string, string> path => sha1 of post-optimization content */
    private array $entries;

    public function __construct(private readonly string $jsonPath)
    {
        $this->entries = self::load($jsonPath);
    }

    public function isFresh(string $relativePath, string $currentContent): bool
    {
        return ($this->entries[$relativePath] ?? null) === sha1($currentContent);
    }

    public function record(string $relativePath, string $finalContent): void
    {
        $this->entries[$relativePath] = sha1($finalContent);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function persist(): void
    {
        $dir = \dirname($this->jsonPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        @file_put_contents(
            $this->jsonPath,
            json_encode($this->entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (\is_string($k) && \is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/Svg/OptimizationCacheTest.php
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add lib/Svg/OptimizationCache.php tests/Svg/OptimizationCacheTest.php
git commit -m "$(cat <<'EOF'
feat(svg): OptimizationCache sidecar for shared optimization tracking

PHP-side helper for the upcoming OptimizeSvgsCommand and (logically) the
Vite plugin's media-pool walk. Maps project-relative paths to sha1 of
post-optimization content; on next scan, files whose current sha1
matches the recorded value are skipped.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Extend `Config::syncStructureJson()` with `media_dir` + `cache_dir`

**Files:**
- Modify: `lib/Config.php` (around `syncStructureJson()`, lines 126–148)

These keys are derived (computed from `rex_path::*`), not user-config. They go in `structure.json` only — not `Config::DEFAULTS`, not seeded via `seedDefaults()`, not surfaced in `pages/settings.php`. Settings UI stays unchanged.

- [ ] **Step 1: Read the current `syncStructureJson()` to confirm shape**

```bash
sed -n '126,148p' lib/Config.php
```

Expected: the existing `$payload = [...]` array literal you'll be extending.

- [ ] **Step 2: Add the helper + extend the payload**

Edit `lib/Config.php`. Replace the `syncStructureJson()` method body so the payload includes the two new derived keys, and add a small `makeRelative()` private helper.

Find this block (lines ~126–148):

```php
    public static function syncStructureJson(): void
    {
        $cfg = self::all();
        $payload = [
            'js_entry'          => $cfg['js_entry'],
            'css_entry'         => $cfg['css_entry'],
            'public_dir'        => $cfg['public_dir'],
            'out_dir'           => $cfg['out_dir'],
            'assets_source_dir' => $cfg['assets_source_dir'],
            'assets_sub_dir'    => $cfg['assets_sub_dir'],
            'build_url_path'    => $cfg['build_url_path'],
            'copy_dirs'         => $cfg['copy_dirs'],
            'https_enabled'        => self::isEnabled('https_enabled'),
            'svg_optimize_enabled' => self::isEnabled('svg_optimize_enabled'),
            'refresh_globs'        => $cfg['refresh_globs'],
            'hot_file'             => self::HOT_FILE_REL,
            'host_url'             => self::getHostUrl(),
        ];
        rex_file::put(
            rex_path::addonData('viterex_addon', 'structure.json'),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
```

Replace with:

```php
    public static function syncStructureJson(): void
    {
        $cfg = self::all();
        $base = rex_path::base();
        $payload = [
            'js_entry'          => $cfg['js_entry'],
            'css_entry'         => $cfg['css_entry'],
            'public_dir'        => $cfg['public_dir'],
            'out_dir'           => $cfg['out_dir'],
            'assets_source_dir' => $cfg['assets_source_dir'],
            'assets_sub_dir'    => $cfg['assets_sub_dir'],
            'build_url_path'    => $cfg['build_url_path'],
            'copy_dirs'         => $cfg['copy_dirs'],
            'media_dir'         => self::makeRelative(rex_path::media(), $base),
            'cache_dir'         => self::makeRelative(rex_path::addonCache('viterex_addon'), $base),
            'https_enabled'        => self::isEnabled('https_enabled'),
            'svg_optimize_enabled' => self::isEnabled('svg_optimize_enabled'),
            'refresh_globs'        => $cfg['refresh_globs'],
            'hot_file'             => self::HOT_FILE_REL,
            'host_url'             => self::getHostUrl(),
        ];
        rex_file::put(
            rex_path::addonData('viterex_addon', 'structure.json'),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Strip `$base` from `$absolute` so the result is project-root-relative,
     * matching how every other path in `structure.json` is expressed. Falls
     * back to the original absolute path if `$absolute` doesn't live under
     * `$base` (extremely unusual; defensive).
     */
    private static function makeRelative(string $absolute, string $base): string
    {
        $base = rtrim($base, '/');
        $absolute = rtrim($absolute, '/');
        if ($base !== '' && str_starts_with($absolute, $base)) {
            return ltrim(substr($absolute, \strlen($base)), '/');
        }
        return $absolute;
    }
```

- [ ] **Step 3: Verify all PHP tests still pass**

```bash
vendor/bin/phpunit
```

Expected: `OK (95+ tests)`. The `Config` class isn't unit-tested directly (it depends on `rex_*` runtime); the existing test suite shouldn't regress.

- [ ] **Step 4: Verify `structure.json` regenerates correctly in the test install**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && php -r '
unset($REX);
$REX["REDAXO"] = true;
$REX["HTDOCS_PATH"] = "./";
$REX["BACKEND_FOLDER"] = "redaxo";
require __DIR__ . "/src/path_provider.php";
$REX["PATH_PROVIDER"] = new app_path_provider();
chdir(__DIR__);
require __DIR__ . "/src/core/boot.php";
rex_addon::initialize(!rex::isSetup());
foreach (rex::getPackageOrder() as $p) rex_package::require($p)->enlist();
\Ynamite\ViteRex\Config::syncStructureJson();
echo file_get_contents(rex_path::addonData("viterex_addon", "structure.json"));
'
```

Expected: JSON output containing both `"media_dir":"public/media"` (or similar; actual value depends on Redaxo structure) and `"cache_dir":"var/cache/addons/viterex_addon"` (modern) / `"cache_dir":"redaxo/cache/addons/viterex_addon"` (classic+theme).

- [ ] **Step 5: Commit**

```bash
git add lib/Config.php
git commit -m "$(cat <<'EOF'
feat(config): emit media_dir + cache_dir in structure.json

Both derived (rex_path::media(), rex_path::addonCache(...)) and exposed
to the Vite plugin via the existing structure.json bridge. Not user
config — no Config::DEFAULTS entry, no settings form field.

Sets up the upcoming media-pool walk in the Vite plugin (which needs
both paths) and the cache-path resolution in OptimizeSvgsCommand.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Simplify `SvgHook` (dev short-circuit, drop `OptimizerFactory`)

**Files:**
- Modify: `lib/Media/SvgHook.php` (entire file)

After this change: dev MEDIA_* uploads are NOT optimized at upload time. Production/staging behavior is identical to v3.3.0 (PHP optimizer runs on every SVG upload).

- [ ] **Step 1: Replace the file contents**

Replace `lib/Media/SvgHook.php` with:

```php
<?php

namespace Ynamite\ViteRex\Media;

use rex_extension;
use rex_extension_point;
use rex_file;
use rex_path;
use Ynamite\ViteRex\Config;
use Ynamite\ViteRex\Server;
use Ynamite\ViteRex\Svg\PhpOptimizer;

/**
 * Optimizes SVGs uploaded or replaced via the Redaxo media pool — but only
 * in staging/prod. In dev, the hook is a no-op: dev devs don't want a
 * shell-out fired on every test upload, and the Vite build (or the
 * `viterex:optimize-svgs` console command) will sweep the media pool
 * anyway when they're ready to clean up.
 *
 * The engine is always `PhpOptimizer` here because the production runtime
 * isn't expected to have Node available — and even if it did, shelling out
 * per-upload is the wrong tradeoff. The `OptimizerFactory` indirection that
 * v3.3.0 had was deleted along with the dev branch (the only caller that
 * needed engine selection at runtime).
 *
 * IMPORTANT: the EP closure returns void. `rex_extension::registerPoint`
 * treats any non-null return as the new EP subject — for MEDIA_ADDED/UPDATED
 * that would clobber the success message and any subsequent listeners. Same
 * gotcha is documented around the reload-signal handler in `boot.php`.
 */
final class SvgHook
{
    public static function register(): void
    {
        $handler = static function (rex_extension_point $ep): void {
            if (Server::getDeploymentStage() === 'dev') {
                return;
            }
            if ($ep->getParam('type') !== 'image/svg+xml') {
                return;
            }
            if (!Config::isEnabled('svg_optimize_enabled')) {
                return;
            }
            $filename = (string) $ep->getParam('filename');
            if ($filename === '') {
                return;
            }
            $path = rex_path::media($filename);
            $svg = rex_file::get($path);
            if (!is_string($svg) || $svg === '') {
                return;
            }
            $optimized = (new PhpOptimizer())->optimize($svg);
            if ($optimized !== $svg) {
                rex_file::put($path, $optimized);
            }
        };
        foreach (['MEDIA_ADDED', 'MEDIA_UPDATED'] as $ep) {
            rex_extension::register($ep, $handler);
        }
    }
}
```

- [ ] **Step 2: Verify all PHP tests still pass**

```bash
vendor/bin/phpunit
```

Expected: `OK (95+ tests)`. SvgHook isn't directly unit-tested (depends on `rex_*` runtime); the suite shouldn't regress.

- [ ] **Step 3: Commit**

```bash
git add lib/Media/SvgHook.php
git commit -m "$(cat <<'EOF'
refactor(svg): SvgHook becomes no-op in dev; drops OptimizerFactory

Dev-stage MEDIA_*-on-upload optimization removed — devs don't want a
shell-out per test upload. Use 'npm run build' or
'bin/console viterex:optimize-svgs' to clean the media pool when ready.

Production/staging path unchanged: every uploaded SVG runs through
PhpOptimizer, which strips <script>/on*-handlers as a security side
effect. No regression in the prod XSS-mitigation behavior.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Delete `OptimizerFactory` and its test

**Files:**
- Delete: `lib/Svg/OptimizerFactory.php`
- Delete: `tests/Svg/OptimizerFactoryTest.php`

After Task 3, nothing imports `OptimizerFactory` anymore. Remove it.

- [ ] **Step 1: Confirm no remaining usages**

```bash
grep -rn "OptimizerFactory" lib/ tests/ pages/ boot.php install.php 2>/dev/null
```

Expected: empty output (or matches only inside the two files about to be deleted).

If the grep finds anything else (other than the two files themselves), STOP and fix the consumer first — don't delete a class with live callers.

- [ ] **Step 2: Delete the files**

```bash
git rm lib/Svg/OptimizerFactory.php tests/Svg/OptimizerFactoryTest.php
```

- [ ] **Step 3: Verify all PHP tests still pass**

```bash
vendor/bin/phpunit
```

Expected: `OK (90+ tests)` — total drops by 5 from the deleted `OptimizerFactoryTest` cases, but everything else still passes.

- [ ] **Step 4: Commit**

```bash
git commit -m "$(cat <<'EOF'
refactor(svg): delete OptimizerFactory now that no callers remain

SvgHook (the only runtime caller) instantiates PhpOptimizer directly
in prod/staging and bails in dev. The new console command does its
own SvgoCli::isAvailable() check inline. The factory's $stage parameter
no longer carries information once those two paths exist; the
abstraction is overhead.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: New console command `viterex:optimize-svgs`

**Files:**
- Create: `lib/Console/OptimizeSvgsCommand.php`
- Modify: (none — Redaxo auto-discovers commands under `lib/Console/`)

The command walks `<assets_source_dir>` AND `<media_dir>`, optimizes via the best available engine, uses `OptimizationCache` to skip already-optimized files. No unit test file — matches the precedent set by `InstallStubsCommand` (which has no test either; console commands here are verified end-to-end via the test install).

- [ ] **Step 1: Create the command file**

Create `lib/Console/OptimizeSvgsCommand.php`:

```php
<?php

namespace Ynamite\ViteRex\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ynamite\ViteRex\Config;
use Ynamite\ViteRex\Svg\OptimizationCache;
use Ynamite\ViteRex\Svg\OptimizerInterface;
use Ynamite\ViteRex\Svg\PhpOptimizer;
use Ynamite\ViteRex\Svg\SvgoCli;
use rex_console_command;
use rex_path;

/**
 * `viterex:optimize-svgs` — batch-optimize SVGs in <assets_source_dir>
 * and the media pool. Mirrors what `npm run build` would do for SVGs,
 * but invokable from the terminal (CI scripts, scheduled tasks, ad-hoc
 * maintenance).
 *
 * Engine selection is inline: SVGO via shell-out if `npx svgo` is on
 * PATH, else PhpOptimizer. Always works because PHP 8.3+ is the addon's
 * hard dependency.
 *
 * Cache: shares the `<addon-cache-dir>/svg-optimized.json` sidecar with
 * the Vite plugin's media-pool walk. Files whose current sha1 matches
 * the recorded value are skipped (already in optimal form).
 *
 * Honors `svg_optimize_enabled` — bails with a clear notice if the
 * global toggle is off.
 *
 * The constructor accepts an optional `OptimizerInterface` so tests can
 * inject a stub. Production code passes null and the engine is resolved
 * inline.
 */
final class OptimizeSvgsCommand extends rex_console_command
{
    public function __construct(private readonly ?OptimizerInterface $injectedOptimizer = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('viterex:optimize-svgs')
            ->setDescription('Optimize SVG files under <assets_source_dir> and the media pool.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List what would change; write nothing.',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Ignore the cache and re-optimize every file.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);

        if (!Config::isEnabled('svg_optimize_enabled')) {
            $io->note('svg_optimize_enabled is off; nothing to do. Toggle it on under ViteRex → Settings.');
            return self::SUCCESS;
        }

        $optimizer = $this->injectedOptimizer
            ?? (SvgoCli::isAvailable() ? new SvgoCli() : new PhpOptimizer());
        $io->writeln('Engine: ' . ($optimizer instanceof SvgoCli ? 'SVGO (Node)' : 'PhpOptimizer (PHP)'));

        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');
        if ($dryRun) {
            $io->writeln('<comment>Dry run: no files will be written.</comment>');
        }

        $cache = new OptimizationCache(rex_path::addonCache('viterex_addon', 'svg-optimized.json'));
        if ($force) {
            $cache->clear();
        }

        $base = rtrim(rex_path::base(), '/') . '/';
        $candidateDirs = [
            rex_path::base(trim(Config::get('assets_source_dir'), '/')),
            rex_path::media(),
        ];
        $files = [];
        foreach ($candidateDirs as $dir) {
            if (is_dir($dir)) {
                foreach (self::findSvgs($dir) as $f) {
                    $files[] = $f;
                }
            }
        }
        $files = array_values(array_unique($files));

        $stats = ['scanned' => 0, 'optimized' => 0, 'skipped' => 0, 'errors' => 0];

        if ($files === []) {
            $io->writeln('No SVGs found under configured paths.');
            return self::SUCCESS;
        }

        $progress = $io->createProgressBar(\count($files));
        $progress->start();

        foreach ($files as $abs) {
            $stats['scanned']++;
            $rel = str_starts_with($abs, $base) ? substr($abs, \strlen($base)) : $abs;

            $original = @file_get_contents($abs);
            if (!\is_string($original) || $original === '') {
                $stats['errors']++;
                $progress->advance();
                continue;
            }
            if ($cache->isFresh($rel, $original)) {
                $stats['skipped']++;
                $progress->advance();
                continue;
            }

            $optimized = $optimizer->optimize($original);
            $finalBytes = ($optimized !== '' ? $optimized : $original);

            if ($finalBytes !== $original) {
                if (!$dryRun) {
                    @file_put_contents($abs, $finalBytes);
                    $cache->record($rel, $finalBytes);
                }
                $stats['optimized']++;
            } else {
                if (!$dryRun) {
                    $cache->record($rel, $original);
                }
                $stats['skipped']++;
            }
            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);

        if (!$dryRun) {
            $cache->persist();
        }

        $io->table(
            ['scanned', 'optimized' . ($dryRun ? ' (would write)' : ''), 'skipped (cached/no-op)', 'errors'],
            [[$stats['scanned'], $stats['optimized'], $stats['skipped'], $stats['errors']]],
        );

        return self::SUCCESS;
    }

    /**
     * @return iterable<string> Absolute paths to .svg files under $dir, recursively.
     */
    private static function findSvgs(string $dir): iterable
    {
        // SKIP_DOTS only (no FOLLOW_SYMLINKS) — circular symlinks would
        // hang the iterator. The default behavior is to leave symlinks
        // alone, which is what we want for media-pool / asset trees.
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'svg') {
                yield $f->getPathname();
            }
        }
    }
}
```

- [ ] **Step 2: Verify the file is syntactically valid PHP**

```bash
php -l lib/Console/OptimizeSvgsCommand.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify all PHP tests still pass (no regressions from new file's autoload)**

```bash
vendor/bin/phpunit
```

Expected: `OK (90+ tests)`. The command isn't unit-tested; we just confirm autoloading the new class doesn't break anything.

- [ ] **Step 4: End-to-end smoke test in test install — dry run**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && bin/console viterex:optimize-svgs --dry-run 2>&1 | tail -20
```

Expected: prints engine choice, "Dry run" note, progress bar, summary table. No files modified on disk; the cache JSON is NOT created.

- [ ] **Step 5: End-to-end smoke test — real run**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && bin/console viterex:optimize-svgs 2>&1 | tail -20 && ls var/cache/addons/viterex_addon/svg-optimized.json 2>&1
```

Expected: prints summary, `svg-optimized.json` exists (or for classic+theme: `redaxo/cache/...`).

- [ ] **Step 6: End-to-end smoke test — second run, cache hit**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && bin/console viterex:optimize-svgs 2>&1 | tail -10
```

Expected: scanned > 0, optimized = 0, skipped > 0 (cache hits).

- [ ] **Step 7: End-to-end smoke test — `--force` re-optimization**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && bin/console viterex:optimize-svgs --force 2>&1 | tail -10
```

Expected: every file re-processed. Optimized count may be 0 if nothing changes (already optimal), but skipped (cached) drops to 0 — `--force` cleared the cache so nothing was a hit.

- [ ] **Step 8: Commit**

```bash
git add lib/Console/OptimizeSvgsCommand.php
git commit -m "$(cat <<'EOF'
feat(svg): viterex:optimize-svgs console command

Walks <assets_source_dir> AND <media_dir>, optimizes via SVGO if
available else PhpOptimizer, uses OptimizationCache to skip files
that are already in optimal form. Flags: --dry-run, --force.

Mirrors what the Vite build will do (next task) so CI / scheduled-task
/ ad-hoc invocations are first-class. Honors svg_optimize_enabled;
constructor accepts an OptimizerInterface for test injection.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Extend Vite plugin — media-pool walk + cache integration

**Files:**
- Modify: `assets/viterex-vite-plugin.js` (around lines 142–230 + 312–315)

Add a media-pool walk that fires only on `buildStart` (not `configureServer`), share the cache file with `OptimizeSvgsCommand`, and import `node:crypto` for sha1.

- [ ] **Step 1: Add the `crypto` import at the top of the file**

Find the existing imports block (around lines 29–35):

```js
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import browserslist from "browserslist";
import { browserslistToTargets } from "lightningcss";
import liveReload from "vite-plugin-live-reload";
import { viteStaticCopy } from "vite-plugin-static-copy";
import VITEREX_SVGO_CONFIG from "./svgo-config.mjs";
```

Add immediately after the existing `node:url` import:

```js
import { createHash } from "node:crypto";
```

(Final block: 9 imports total.)

- [ ] **Step 2: Add cache helpers above `svgOptimizePlugin`**

Find the comment block for `svgOptimizePlugin` (`/** SVG optimization plugin. In dev: ...`, around line 196).

Insert this block IMMEDIATELY ABOVE that comment:

```js
/**
 * Read the optimization cache JSON. Returns an empty object if the file
 * doesn't exist, is unreadable, or contains invalid JSON. Mirrors the
 * fail-open semantics of `lib/Svg/OptimizationCache.php`'s `load()`.
 */
function loadOptimizationCache(cachePath) {
	if (!cachePath) return {};
	try {
		const raw = fs.readFileSync(cachePath, "utf8");
		const parsed = JSON.parse(raw);
		if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) return {};
		const out = {};
		for (const [k, v] of Object.entries(parsed)) {
			if (typeof k === "string" && typeof v === "string") out[k] = v;
		}
		return out;
	} catch {
		return {};
	}
}

/**
 * Persist the optimization cache JSON. Errors are warned-but-not-thrown:
 * cache persistence is best-effort, never load-bearing. On the next run we
 * just re-derive from disk.
 */
function persistOptimizationCache(cachePath, cache) {
	if (!cachePath) return;
	try {
		fs.mkdirSync(path.dirname(cachePath), { recursive: true });
		fs.writeFileSync(cachePath, JSON.stringify(cache, null, 2));
	} catch (e) {
		console.warn(`[viterex] could not persist svg-optimized.json: ${e.message}`);
	}
}

function sha1(content) {
	return createHash("sha1").update(content).digest("hex");
}
```

- [ ] **Step 3: Replace `svgOptimizePlugin` and `optimizeSvgFile` with cache-aware versions**

Find the existing `optimizeSvgFile` function (around line 178) and replace it + `svgOptimizePlugin` (around line 206) with:

```js
/**
 * Optimize one SVG file in place. Uses the shared OptimizationCache JSON
 * to skip files already in optimal form (sha1 of current on-disk content
 * matches the recorded value).
 *
 * Returns one of: "optimized" (file changed + cache updated),
 * "skipped" (cache hit OR optimizer no-op'd), "error" (read/write/svgo
 * failure — warning printed).
 */
async function optimizeSvgFile(absPath, optimize, cache, cwd) {
	let raw;
	try {
		raw = await fs.promises.readFile(absPath, "utf8");
	} catch {
		return "error";
	}
	const rel = path.relative(cwd, absPath).replace(/\\/g, "/");
	const currentHash = sha1(raw);
	if (cache[rel] === currentHash) return "skipped";

	let result;
	try {
		result = optimize(raw, VITEREX_SVGO_CONFIG);
	} catch (e) {
		console.warn(`[viterex] svgo failed on ${absPath}: ${e.message}`);
		return "error";
	}
	const finalBytes = result?.data || raw;
	if (finalBytes !== raw) {
		try {
			await fs.promises.writeFile(absPath, finalBytes, "utf8");
		} catch (e) {
			console.warn(`[viterex] could not write optimized ${absPath}: ${e.message}`);
			return "error";
		}
		cache[rel] = sha1(finalBytes);
		return "optimized";
	}
	// optimizer was a no-op (file already optimal); record sha1 anyway so
	// next run skips the SVGO call
	cache[rel] = currentHash;
	return "skipped";
}

/**
 * SVG optimization plugin. In dev (`configureServer`): walks
 * `<assets_source_dir>` and optimizes 1:1 in place. In build
 * (`buildStart`): walks `<assets_source_dir>` AND `<media_dir>` so
 * `npm run build` produces a clean media-pool too. The shared
 * OptimizationCache (sidecar JSON at `<cache_dir>/svg-optimized.json`)
 * means subsequent runs skip files that haven't changed since their
 * last optimize.
 *
 * Skipped (returns null) when toggle is disabled — caller filters nulls
 * out of the plugin array.
 */
function svgOptimizePlugin({ enabled, srcDir, mediaDir, cachePath }) {
	if (!enabled) return null;
	const scanned = new Set();
	const cache = loadOptimizationCache(cachePath);
	const cwd = process.cwd();

	async function scanDir(dir, label) {
		if (!dir || !fs.existsSync(dir)) return;
		const optimize = await loadSvgo();
		if (!optimize) return;
		const files = await walkSvgs(dir);
		const counts = { optimized: 0, skipped: 0, errors: 0 };
		for (const file of files) {
			if (scanned.has(file)) continue;
			scanned.add(file);
			const r = await optimizeSvgFile(file, optimize, cache, cwd);
			counts[r === "error" ? "errors" : r]++;
		}
		if (counts.optimized > 0 || counts.errors > 0) {
			console.log(
				`[viterex] svg ${label}: optimized=${counts.optimized} skipped=${counts.skipped} errors=${counts.errors}`,
			);
		}
	}

	return {
		name: "viterex:svg-optimize",
		async buildStart() {
			await scanDir(srcDir, "assets");
			await scanDir(mediaDir, "media");
			persistOptimizationCache(cachePath, cache);
		},
		configureServer(server) {
			server.httpServer?.once("listening", async () => {
				await scanDir(srcDir, "assets");
				persistOptimizationCache(cachePath, cache);
			});
		},
	};
}
```

- [ ] **Step 4: Wire `mediaDir` + `cachePath` into the plugin call site**

Find the `svgOptimize = svgOptimizePlugin({...})` call (around line 312):

```js
	const svgOptimize = svgOptimizePlugin({
		enabled: structure.svg_optimize_enabled !== false,
		srcDir: assetsSourceFs,
	});
```

Replace with:

```js
	const mediaDirFs = structure.media_dir ? path.resolve(cwd, structure.media_dir) : null;
	const cacheFs = structure.cache_dir
		? path.resolve(cwd, structure.cache_dir, "svg-optimized.json")
		: null;
	const svgOptimize = svgOptimizePlugin({
		enabled: structure.svg_optimize_enabled !== false,
		srcDir: assetsSourceFs,
		mediaDir: mediaDirFs,
		cachePath: cacheFs,
	});
```

- [ ] **Step 5: Verify JS syntax**

```bash
node --check assets/viterex-vite-plugin.js
```

Expected: no output (success).

- [ ] **Step 6: Sync the plugin into the test install + run a fresh build**

```bash
cp assets/viterex-vite-plugin.js /Users/yvestorres/Herd/viterex-installer-default/public/assets/addons/viterex_addon/viterex-vite-plugin.js && cd /Users/yvestorres/Herd/viterex-installer-default && rm -f var/cache/addons/viterex_addon/svg-optimized.json && (yarn build 2>&1 || npm run build 2>&1) | tail -25
```

Expected: build succeeds. If any SVGs exist under `src/assets/img/` or `public/media/`, you'll see a `[viterex] svg assets: optimized=N skipped=M errors=0` and/or `[viterex] svg media: ...` log line. The cache JSON is now written.

- [ ] **Step 7: Verify the cache is honored on second build**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && (yarn build 2>&1 || npm run build 2>&1) | grep "viterex" | head -5
```

Expected: NO `[viterex] svg assets: ...` line (counts are all 0/all skipped → log line suppressed). If a line appears, optimized should be 0.

- [ ] **Step 8: Confirm cache file matches what console command produces**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && cat var/cache/addons/viterex_addon/svg-optimized.json | head -10
```

Expected: JSON object with `"<relative-path>": "<40-char-sha1-hex>"` entries. Same format as the PHP-side `OptimizationCache::persist()` output.

- [ ] **Step 9: Cross-runtime cache compatibility check**

Delete the cache, run the console command, then run a Vite build:

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && rm -f var/cache/addons/viterex_addon/svg-optimized.json && bin/console viterex:optimize-svgs 2>&1 | tail -5 && (yarn build 2>&1 || npm run build 2>&1) | grep "viterex" | head -5
```

Expected: console command writes the cache; the subsequent Vite build sees the same cache and skips everything (no `[viterex] svg ...` log line).

- [ ] **Step 10: Commit**

```bash
git add assets/viterex-vite-plugin.js
git commit -m "$(cat <<'EOF'
feat(vite): media-pool walk on buildStart + shared cache

The svgOptimizePlugin now walks <media_dir> in addition to
<assets_source_dir> during buildStart (build only, NOT dev — devs
don't want a media-pool scan on every server start). The shared
sidecar JSON at <cache_dir>/svg-optimized.json is the same one the
PHP console command reads/writes, so cross-runtime cache hits work
both directions.

mediaDir + cachePath are derived from the new structure.json fields
added in the previous Config commit.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: End-to-end verification matrix

**Files:** none modified; test install only.

This task is the manual verification matrix from the spec. No commit at the end — if anything fails, go back to the relevant task and fix.

- [ ] **Step 1: Verify dev MEDIA_* hook is now a no-op**

In the test install (which is dev stage by default — no ydeploy stage set), upload an SVG containing a `<script>` tag via the Redaxo backend Media Pool, then check the file on disk:

```bash
# (do the upload via the browser at /redaxo/index.php?page=mediapool)
# afterward:
grep -l "<script" /Users/yvestorres/Herd/viterex-installer-default/public/media/with-script.svg 2>&1
```

Expected: the file STILL contains `<script>` (no optimization on dev upload). This confirms the SvgHook dev short-circuit works.

If `grep` says the script is absent, the dev short-circuit isn't firing — check `Server::getDeploymentStage()` returns `'dev'` in your env.

- [ ] **Step 2: Verify `npm run build` cleans up media-pool SVGs (the deferred cleanup path)**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && rm -f var/cache/addons/viterex_addon/svg-optimized.json && (yarn build 2>&1 || npm run build 2>&1) | tail -10 && grep -l "<script" public/media/with-script.svg 2>&1 || echo "script tag stripped ✓"
```

Expected: `script tag stripped ✓`. The Vite build's media-pool walk caught what the dev hook deliberately skipped.

- [ ] **Step 3: Verify the console command does the same**

Re-upload (or restore) a script-bearing SVG, then:

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && rm -f var/cache/addons/viterex_addon/svg-optimized.json && bin/console viterex:optimize-svgs 2>&1 | tail -10 && grep -l "<script" public/media/with-script.svg 2>&1 || echo "script tag stripped ✓"
```

Expected: `script tag stripped ✓`.

- [ ] **Step 4: Verify `--dry-run` does NOT write**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && rm -f var/cache/addons/viterex_addon/svg-optimized.json && bin/console viterex:optimize-svgs --dry-run 2>&1 | tail -5 && ls var/cache/addons/viterex_addon/svg-optimized.json 2>&1
```

Expected: command succeeds, prints "Dry run" notice. The cache JSON does NOT exist (because dry-run skips persist).

- [ ] **Step 5: Verify svg_optimize_enabled toggle is honored**

In the test install, toggle the setting OFF (Settings page or directly via DB), then:

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && bin/console viterex:optimize-svgs 2>&1 | head -3
```

Expected: prints the "svg_optimize_enabled is off; nothing to do." note and exits cleanly.

Re-enable the toggle before continuing.

- [ ] **Step 6: Verify the cache JSON survives a second build with no work done**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && (yarn build 2>&1 || npm run build 2>&1) > /dev/null && (yarn build 2>&1 || npm run build 2>&1) 2>&1 | grep "viterex svg" | head -5
```

Expected: empty output (no log lines from svgOptimizePlugin, because every file was a cache hit).

- [ ] **Step 7: Verify the prod-stage MEDIA_* hook still optimizes uploads**

Simulate prod via ydeploy stage config (or a temporary `Server::getDeploymentStage()` mock), upload an SVG with `<script>`, check it was stripped on disk. If you don't have ydeploy configured for prod, this can be deferred to a real prod deploy as a manual smoke test.

Document the result; if it fails, the SvgHook prod path is broken and needs revisiting.

---

## Task 8: Update docs

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `README.md`
- Modify: `CLAUDE.md`

3.3.0 hasn't shipped externally yet, so we amend the in-progress entry rather than starting a new version section.

- [ ] **Step 1: Update CHANGELOG.md**

In `CHANGELOG.md`, find the `## **Version 3.3.0**` section. Add a new subsection under "### Internal" (after the existing bullets but before "### Fixed") titled "**Simplification follow-up (2026-05-05):**" with:

```markdown
- **Simplification follow-up (2026-05-05):**
  - **Dev-stage `MEDIA_*` hook is now a no-op.** Devs don't want SVGO
    firing on every test upload. The Vite build (`npm run build`) and
    the new `viterex:optimize-svgs` console command sweep the media
    pool when devs are ready. Production / staging behavior is
    unchanged: every uploaded SVG runs through `PhpOptimizer`, which
    strips `<script>` and `on*` handlers as a security side-effect.
  - **`OptimizerFactory` deleted** (`lib/Svg/OptimizerFactory.php`,
    plus its 5 tests). With the dev `MEDIA_*` branch removed, `SvgHook`
    always wants `PhpOptimizer` and the new console command picks its
    engine inline (`SvgoCli::isAvailable() ? new SvgoCli() : new PhpOptimizer()`).
    The factory's `$stage` parameter no longer carried information.
  - **`viterex:optimize-svgs` console command** (`lib/Console/OptimizeSvgsCommand.php`).
    Walks `<assets_source_dir>` and `<media_dir>`, optimizes via SVGO
    if available else `PhpOptimizer`. Flags: `--dry-run` (list, don't
    write), `--force` (ignore cache). Honors `svg_optimize_enabled`.
  - **Vite build now walks `<media_dir>`** during `buildStart` (NOT
    `configureServer`, so dev-server start stays fast). Same SVGO
    invocation as the existing assets walk.
  - **Shared optimization cache** (`<cache_dir>/svg-optimized.json`).
    Both the Vite plugin and the console command read/write the same
    JSON sidecar — sha1 of post-optimization content keyed by
    project-relative path. Files matching the recorded sha1 are
    skipped (already in optimal form). Helper: `lib/Svg/OptimizationCache.php`,
    fail-open on corrupted JSON, 6 unit tests.
  - **`structure.json` gains `media_dir` + `cache_dir`** (both derived
    from `rex_path::*`, not user config — no settings form fields,
    no `Config::DEFAULTS` entry, just emitted at sync time).
```

- [ ] **Step 2: Update README.md SVG-optimization section**

Find the `## SVG-Optimierung` section's stage table (around the `| dev | SVGO ... |` row). Replace the entire table + the "Sicherheits-Effekt" / "Idempotent" / "Fail-open" / "npm-Dep" paragraphs with:

```markdown
| Surface          | Wann                                                | Engine                                            |
| ---------------- | --------------------------------------------------- | ------------------------------------------------- |
| Source-Assets    | `npm run dev` (server start) + `npm run build`      | SVGO (Node, via Vite-Plugin), 1:1 in-place        |
| Vite copy-pipe   | `npm run build` (`viteStaticCopy` transform)        | SVGO                                              |
| Mediapool        | `npm run build`                                     | SVGO (Vite-Plugin walked `<media_dir>`)           |
| Mediapool        | Manuell: `bin/console viterex:optimize-svgs`        | SVGO wenn verfügbar, sonst `PhpOptimizer` (PHP)   |
| Mediapool-Upload | `MEDIA_ADDED` / `MEDIA_UPDATED` (nur prod/staging)  | `PhpOptimizer` (Node-frei für die Live-Umgebung)  |

In **dev** ist der Mediapool-Upload-Hook ein No-op — kein SVGO-Shell-out bei jedem Test-Upload. Räume mit `npm run build` oder dem Console-Command auf, wenn du willst.

**Sicherheits-Effekt fürs Mediapool**: `<script>`-Tags und `on*`-Event-Handler werden bei jedem Prod/Staging-Upload entfernt — schliesst eine standardmässig vorhandene XSS-Lücke beim Hochladen von SVGs in Redaxo.

**Cache**: Beide Pfade (Vite-Build + Console-Command) teilen sich `<cache_dir>/svg-optimized.json`. SHA1 der optimierten Bytes pro Datei; bereits optimale Files werden in Folge-Runs übersprungen. `bin/console viterex:optimize-svgs --force` ignoriert den Cache.

**Idempotent / Fail-open**: SVGO-Output round-trippt unverändert; Cache verhindert dass das überhaupt bemerkt wird. Bei jedem Fehler bleibt die Datei unverändert.

**npm-Dep**: `svgo` wird per `install.php` in die `package.json` des Projekts gemerged. Beim Upgrade von v3.2.x erscheint es automatisch in `devDependencies`; ein einmaliges `npm install` aktiviert die Optimierung. In der Lücke davor warnt das Vite-Plugin und macht nichts (kein Crash).

### Console-Command

```bash
bin/console viterex:optimize-svgs              # walk + optimize
bin/console viterex:optimize-svgs --dry-run    # list what would change
bin/console viterex:optimize-svgs --force      # ignore cache, re-do everything
```

Engine-Wahl: SVGO wenn `npx svgo` auf PATH, sonst `PhpOptimizer`. Honors `svg_optimize_enabled` (bricht früh ab wenn off).
```

(Keep the existing "Inline-SVGs: Scope-Isolation gegen Class-Kollisionen" subsection as-is — it's unaffected by this change.)

- [ ] **Step 3: Update CLAUDE.md**

In `CLAUDE.md`'s "Things to be careful about" section, REMOVE any bullet that references `OptimizerFactory` (the v3.3.0 entries currently mention it). The factory is gone.

In the "## Roadmap" section, find the `**3.3.0 (shipped)**:` paragraph and APPEND this sentence at the end:

```
v3.3.0 simplification follow-up (2026-05-05): dev-stage MEDIA_*-on-upload optimization removed (devs don't want shell-out per test upload), media-pool optimization moved to `npm run build`'s `buildStart` walk + the new `bin/console viterex:optimize-svgs` command, `OptimizerFactory` deleted in favor of inline engine selection. Shared sha1 cache at `<cache_dir>/svg-optimized.json` lets Vite-build and console-command runs skip already-optimal files. Production `MEDIA_*` path unchanged — `PhpOptimizer` still strips `<script>`/on* handlers on every upload.
```

- [ ] **Step 4: Verify the docs render cleanly**

```bash
head -50 CHANGELOG.md && echo "---" && grep -A 30 "## SVG-Optimierung" README.md | head -40
```

Expected: the new entries appear in CHANGELOG; the new table appears in README.

- [ ] **Step 5: Commit**

```bash
git add CHANGELOG.md README.md CLAUDE.md
git commit -m "$(cat <<'EOF'
docs: simplification follow-up — CHANGELOG, README, CLAUDE.md

Document the dev-MEDIA_*-removal, media-pool walk during npm run build,
new console command, and OptimizerFactory deletion as amendments to
the in-progress 3.3.0 release.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Final test sweep + cleanup

**Files:** none modified.

- [ ] **Step 1: Run all PHP tests**

```bash
vendor/bin/phpunit
```

Expected: `OK (96+ tests)` (95 from v3.3.0 baseline minus 5 OptimizerFactory cases plus 6 OptimizationCache cases = 96, plus the existing IdPrefixerTest etc.).

- [ ] **Step 2: Verify Vite plugin syntax + svgo-config still imports**

```bash
node --check assets/viterex-vite-plugin.js && node --check assets/svgo-config.mjs && echo OK
```

Expected: `OK`.

- [ ] **Step 3: Verify no `OptimizerFactory` references remain anywhere**

```bash
grep -rn "OptimizerFactory" lib/ tests/ pages/ boot.php install.php docs/ assets/ CHANGELOG.md README.md CLAUDE.md 2>/dev/null
```

Expected: empty output. (The CHANGELOG mentions the deletion but the new bullet uses the past tense; if that bullet contains the literal word, that's fine — sanity check by eye.)

- [ ] **Step 4: Confirm git working tree is clean**

```bash
git status
```

Expected: `nothing to commit, working tree clean`. All changes committed.

- [ ] **Step 5: Spot-check the commit log**

```bash
git log --oneline -10
```

Expected: 7 new commits on top of the previous worktree state, in this order (newest first):
1. `docs: simplification follow-up ...`
2. `feat(vite): media-pool walk on buildStart + shared cache`
3. `feat(svg): viterex:optimize-svgs console command`
4. `refactor(svg): delete OptimizerFactory now that no callers remain`
5. `refactor(svg): SvgHook becomes no-op in dev; drops OptimizerFactory`
6. `feat(config): emit media_dir + cache_dir in structure.json`
7. `feat(svg): OptimizationCache sidecar for shared optimization tracking`

If any commit message looks off, leave it for the user to clean up via `git rebase -i` if desired.

---

## Done

After Task 9 passes, the worktree contains a fully working simplification of the SVG optimizer architecture, ready for merge into the v3.3 work. The user (not the implementing agent) should:

- Review the commit series.
- Decide whether to squash before merge.
- Run `superpowers:finishing-a-development-branch` for the worktree merge + cleanup.
