# SVG optimizer simplification (v3.3 follow-up)

**Date:** 2026-05-05
**Status:** Approved — ready for implementation plan
**Worktree:** `.worktrees/v3.3-svg-optimization` (continues v3.3 work)

## Context

v3.3.0 shipped automatic SVG optimization across three surfaces:

1. **Source assets** (`<assets_source_dir>/**/*.svg`) — Vite plugin walks + mutates 1:1 in dev/build via SVGO.
2. **Vite-pipeline copies** — `viteStaticCopy` transform optimizes en route to build output.
3. **Media-pool uploads** — `MEDIA_ADDED`/`MEDIA_UPDATED` hook (`lib/Media/SvgHook.php`) shells out to SVGO in dev, falls back to `mathiasreker/php-svg-optimizer` (`PhpOptimizer`) in staging/prod.

Surface 3's dev branch — shelling out to `npx svgo` on every SVG upload — overlaps awkwardly with the Vite plugin's own walk and isn't what dev users actually want. Devs uploading test images don't want a Node child process to fire on every save; they want the media-pool left alone until they explicitly choose to clean it up.

This spec simplifies the dev architecture: one engine per surface per stage, with a manual escape hatch.

## Goals

- Remove the dev-stage `MEDIA_*` hook entirely (no automatic optimization on upload in dev).
- Keep the prod/staging `MEDIA_*` hook unchanged (PHP runtime optimization on upload — Node-free, the deploy artifact case).
- Move automatic media-pool optimization in dev to **build time**: `npm run build` walks `<media_dir>` in addition to `<assets_source_dir>` and optimizes any SVGs that need it.
- Add a console command `viterex:optimize-svgs` so the same batch optimization can be triggered manually from the terminal (CI scripts, scheduled tasks, ad-hoc maintenance).
- Cache content hashes so subsequent runs skip already-optimized files (cheap on subsequent builds even with large media-pools).

## Non-goals

- No changes to the Vite plugin's existing dev-time asset behavior (source-tree mutation + `viteStaticCopy` transform stay as-is).
- No changes to `Assets::inline()` — `IdPrefixer` runtime path is unaffected.
- No new user-facing settings. The single `svg_optimize_enabled` toggle still controls everything.
- No support for partial walks (`--dir=foo`, glob filters). YAGNI.

## Architecture

### Engine selection by surface, after this change

```
                    Surface                  Stage           Engine
─────────────────────────────────────────────────────────────────────
Source assets       (auto, Vite)             dev / build     SVGO (Node)
Vite copy pipeline  (auto, Vite transform)   build           SVGO (Node)
Media pool          (auto, Redaxo EP)        prod / staging  PhpOptimizer
Media pool          (auto, Vite walk)        build           SVGO (Node)
Media pool          (manual, console)        any             SVGO if available, else PhpOptimizer
Source assets       (manual, console)        any             SVGO if available, else PhpOptimizer
```

`Server::getDeploymentStage()` is no longer the central engine selector — each surface picks its own. The `MEDIA_*` hook simplifies to "skip in dev, run `PhpOptimizer` otherwise". The console command picks the best available engine inline. The Vite plugin always uses SVGO (it's a Node process, SVGO is its native tooling).

### What gets removed

- **`SvgHook` short-circuits in dev stage.** The handler bails out at the top with `if (Server::getDeploymentStage() === 'dev') return;`. Registration shape stays consistent across stages — just a one-line guard. Production/staging handler body unchanged.
- **`OptimizerFactory` deleted.** Its callers reduce to `SvgHook` (now always wants `PhpOptimizer`) and the new console command (does its own SVGO-availability check inline). The factory's `$stage` parameter no longer carries information once `SvgHook` doesn't need it. Removing the abstraction is cleaner than keeping a one-method factory whose only job is `SvgoCli::isAvailable() ? new SvgoCli() : new PhpOptimizer()`.
- **`tests/Svg/OptimizerFactoryTest.php` deleted.** Replaced by direct tests on the engine classes (already covered) plus the new console-command test.
- **`SvgoCli` retained.** Still used by the console command in dev environments where SVGO is on PATH. `optimize()` body is unchanged.

### What gets added

#### 1. Vite plugin `buildStart` extension — walk media-pool

`structure.json` gains two new keys, both **derived** (computed in `Config::syncStructureJson()`, not stored in `rex_config` and not surfaced in `pages/settings.php`):

- `media_dir` — `rex_path::media()` made relative to `rex_path::base()`. Always points at Redaxo's media root regardless of structure.
- `cache_dir` — `rex_path::addonCache('viterex_addon', '')` made relative to `rex_path::base()`. Lets the Vite plugin read/write the cache JSON via a single canonical path instead of the dual-candidate dance used for `structure.json`.

`assets/viterex-vite-plugin.js`'s `svgOptimizePlugin` extension:

- `configureServer` (dev) — walks `<assets_source_dir>` only. **Unchanged.**
- `buildStart` (build) — walks `<assets_source_dir>` AND `<media_dir>`.

Both code paths use the existing `walkSvgs` + `optimizeSvgFile` helpers and the canonical `svgo-config.mjs`. The current `scanned` Set already dedupes across `configureServer`+`buildStart`. Skip silently when `media_dir` doesn't exist on disk (small/new sites). Honor `svg_optimize_enabled` via the existing `!== false` defensive read.

Cache integration (section 3 below) means a second `npm run build` against an unchanged media-pool reduces to file reads + sha1 checks — fast enough that even multi-thousand-file media-pools build without measurable overhead.

#### 2. Console command `viterex:optimize-svgs`

New file `lib/Console/OptimizeSvgsCommand.php`, sibling to the existing `InstallStubsCommand`. Walks **both** `<assets_source_dir>` and `<media_dir>` — everything Vite would touch during a build — so a manual run is feature-complete relative to the Vite path.

Engine selection inline:

```php
$optimizer = SvgoCli::isAvailable() ? new SvgoCli() : new PhpOptimizer();
```

SVGO is faster + better output when Node is on PATH; PhpOptimizer is the safety net (always available since PHP 8.3+ is the addon's hard dep).

**Testable seam:** `OptimizeSvgsCommand` accepts an optional `OptimizerInterface` constructor argument; production code passes the inline-resolved engine, tests pass a stub. Same pattern as `OptimizerFactory::for($stage, $enabled, ?$svgoAvailable)`'s third parameter, just collapsed onto the command itself instead of a separate factory.

Flags:

- `--dry-run` — list what would change, write nothing.
- `--force` — ignore the cache and re-optimize every file.

UX:

- Symfony progress bar.
- Final summary table: `scanned / optimized / skipped (cached) / errors`.
- Honors `svg_optimize_enabled` — bails with a clear "toggle is off" notice if false.

#### 3. Cache sidecar — shared between Vite plugin and console command

Location: `<addon-cache-dir>/svg-optimized.json`. Resolved via `rex_path::addonCache('viterex_addon', 'svg-optimized.json')` from PHP; via the new `cache_dir` field from `structure.json` in JS. Single canonical path on each runtime.

Schema:

```json
{
  "src/assets/img/icon.svg": "a3f5...e9",
  "public/media/upload/photo.svg": "7b1c...02"
}
```

Keys are project-relative paths (so the cache is portable across machines / git clones). Values are sha1 of pre-optimization content.

Per-file flow:

1. Read file → compute sha1.
2. Look up path in cache.
3. If recorded sha1 matches current sha1, skip (already optimized).
4. Otherwise: optimize, write back, update in-memory cache.

Cache JSON written **once at run end** (single atomic write — simpler than per-file). On crash mid-run, worst case is re-running already-optimized files on the next invocation — idempotent, so no harm.

- **PHP side (console command):** persistence runs after the walk completes inside `OptimizeSvgsCommand::execute()`. `--dry-run` skips both the file write-back and the cache persist.
- **Node side (Vite plugin):** persistence runs in the same `buildStart` hook after the walk (linear: load cache → walk → optimize → persist, all within one hook). Simpler than splitting across hooks. Vite swallows + reports any I/O errors; we treat persistence failures as non-fatal (next build re-derives from disk + cache misses).

Both runtimes write to the same file. Concurrent access is rare (you don't run `npm run build` and `bin/console viterex:optimize-svgs` simultaneously), and last-writer-wins is acceptable.

`--force` empties the in-memory cache before scanning, so every file gets re-optimized and re-recorded.

### Sequence diagram — `npm run build` after this change

```
Vite           svgOptimizePlugin           Disk                    Cache JSON
 │                  │                       │                          │
 │── buildStart ───>│                       │                          │
 │                  │── load structure.json>│                          │
 │                  │<──────────────────────│                          │
 │                  │── load svg-optimized >│                          │
 │                  │<──────────────────────│                          │
 │                  │ (in-memory cache)     │                          │
 │                  │                       │                          │
 │                  │── walkSvgs(asset_dir) │                          │
 │                  │── walkSvgs(media_dir) │                          │
 │                  │                       │                          │
 │                  │ for each .svg file:   │                          │
 │                  │   read + sha1         │                          │
 │                  │   in cache? skip      │                          │
 │                  │   else: optimize, writeBack, recordHash         │
 │                  │                       │                          │
 │                  │── persistCache ───────────────────────────────> │
 │<── continue ─────│                       │                          │
 │                                          │                          │
```

### Sequence diagram — production media-pool upload

```
User browser           Redaxo backend           SvgHook            PhpOptimizer       Disk
     │                       │                     │                    │                │
     │─── upload .svg ─────> │                     │                    │                │
     │                       │── MEDIA_ADDED ────> │                    │                │
     │                       │                     │ stage=prod ✓       │                │
     │                       │                     │── optimize() ────> │                │
     │                       │                     │<── bytes ──────────│                │
     │                       │                     │── put to disk ─────────────────────>│
     │<── 200 OK ────────────│                     │                    │                │
```

Behaves like v3.3.0; only the `if (stage === 'dev')` short-circuit is added (handler returns early before reaching the optimizer).

## Files to create / modify

**New files**

- `lib/Console/OptimizeSvgsCommand.php` — the new command.
- `lib/Svg/OptimizationCache.php` — small PHP helper that reads/writes the sidecar JSON. Used by `OptimizeSvgsCommand`. Pure I/O + serialization; no Redaxo runtime calls in its public methods (so it's unit-testable without a Redaxo bootstrap, mirroring the convention in `lib/Deploy/Sidecar.php`).
- `tests/Console/OptimizeSvgsCommandTest.php` — exercise dry-run, force, cache hit/miss, mixed engine availability.
- `tests/Svg/OptimizationCacheTest.php` — round-trip, sha1 mismatch, missing key, corrupted JSON fail-open.

**Modified files**

- `lib/Media/SvgHook.php` — add the dev short-circuit at the top of the closure; remove the `OptimizerFactory::for()` call; instantiate `PhpOptimizer` directly. Net effect: shorter handler, fewer indirections, dev becomes a no-op.
- `lib/Config.php` — extend `syncStructureJson()` to compute and emit `media_dir` and `cache_dir`. **Not** added to `Config::DEFAULTS` (these are derived, not user-config). Settings form unchanged.
- `assets/viterex-vite-plugin.js` — extend `svgOptimizePlugin` to scan `media_dir` during `buildStart`. Add cache load/persist around the existing `scanAndRewrite`. Read `cache_dir` from `structure`.
- `boot.php` — `SvgHook::register()` call unchanged (the closure now self-guards on stage).
- `CHANGELOG.md`, `README.md`, `CLAUDE.md` — document the new architecture, the deletion of `OptimizerFactory`, and the cache file location.

**Deleted files**

- `lib/Svg/OptimizerFactory.php`
- `tests/Svg/OptimizerFactoryTest.php`

**Note on test count drift**

3.3.0 shipped 27 cases under `tests/Svg/`. After this change: minus the OptimizerFactory cases, plus the new `OptimizationCache` cases, plus the `OptimizeSvgsCommand` cases. Net should land in the high 20s / low 30s — order of magnitude unchanged.

## Existing utilities to reuse

- `Server::getDeploymentStage()` — `lib/Server.php` — the dev guard in `SvgHook`.
- `Config::isEnabled('svg_optimize_enabled')` — single source for the global toggle (both runtimes).
- `Config::syncStructureJson()` — extend to add `media_dir`, `cache_dir`.
- `SvgoCli::isAvailable()` + `SvgoCli::optimize()` — used by the console command.
- `PhpOptimizer::optimize()` — used by the console command (fallback) and the `MEDIA_*` hook (always, in prod/staging).
- `walkSvgs()` + `optimizeSvgFile()` in `viterex-vite-plugin.js` — extended scan target only; helpers unchanged.
- `loadStructureJson()` in `viterex-vite-plugin.js` — pattern reused for cache JSON loading (single canonical path now, via `cache_dir`).
- The existing console-command base (`InstallStubsCommand`'s pattern + Symfony console plumbing already wired into Redaxo).

## Verification

1. **PHP unit tests** — `composer install && vendor/bin/phpunit`. Covers `OptimizationCache` round-trip, fail-open on corrupted JSON, sha1 mismatch detection. Console command tests cover dry-run output, `--force` cache invalidation, mixed engine availability via mockable seam.

2. **Real-install media-pool dev smoke** at `/Users/yvestorres/Herd/viterex-installer-default/`:
   - Upload `with-script.svg` via Redaxo media pool with stage=dev.
   - Verify file on disk is **untouched** (no optimization, `<script>` still present — confirms the dev short-circuit works).
   - Set stage to prod (or simulate via ydeploy config), upload again.
   - Verify `<script>` stripped on disk (confirms the prod path still works through `PhpOptimizer`).

3. **Vite build with media-pool**:
   - Drop a deliberately bloated SVG into `public/media/`.
   - Run `npm run build`.
   - Confirm the file on disk was mutated 1:1 (smaller, no XML decl).
   - Confirm `var/cache/addons/viterex_addon/svg-optimized.json` now contains the file's pre-optimization sha1.
   - Run `npm run build` again — confirm the file is skipped (cache hit, no SVGO invocation).
   - Edit the source SVG (e.g., add a comment), run `npm run build` — confirm cache miss → re-optimization.

4. **Console command parity**:
   - Run `bin/console viterex:optimize-svgs --dry-run` — confirm output lists what would change.
   - Run `bin/console viterex:optimize-svgs` — confirm same files get optimized as the Vite path would.
   - Run `bin/console viterex:optimize-svgs --force` — confirm every file is re-processed regardless of cache.
   - Toggle `svg_optimize_enabled` off — confirm command bails with a clear message.

5. **No-Node fallback for console command**:
   - Temporarily rename `node` on PATH (or run on a host without Node).
   - Run `bin/console viterex:optimize-svgs` — confirm `PhpOptimizer` is used (no crash, files still optimized).

6. **Pre-`npm install` graceful no-op** (regression check): start `npm run dev` after upgrading the addon, before `npm install` runs. Verify the existing svgo-not-installed warning still fires; no new failure modes from the media-pool walk path (which only fires on `buildStart`, not `configureServer`).

7. **Cache safety**:
   - Manually corrupt `svg-optimized.json` (truncate, invalid JSON).
   - Run `bin/console viterex:optimize-svgs` — confirm fail-open: cache treated as empty, all files re-scanned, fresh JSON written.

## Migration notes

This is a follow-up to v3.3.0 (which has not shipped externally yet — it lives on the `v3.3-svg-optimization` worktree). No external migration concerns: the dev-stage `MEDIA_*` shell-out being removed is a behavior change only relative to the in-progress branch, not relative to any released version.

For future release notes (`3.3.0` final): document that media-pool optimization in dev happens at build time, not on upload. Production behavior is identical to the original v3.3.0 plan.

## Open questions

None. All design decisions are explicit above.
