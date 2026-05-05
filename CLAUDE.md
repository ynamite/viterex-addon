# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository identity

`viterex_addon` is a standalone Redaxo CMS addon (`>=5.13`, PHP `>=8.3` since v3.3.0; was `>=8.1` in v3.2.x) that bridges Redaxo and Vite (8.x). It is installable in **any** Redaxo setup — `classic`, `modern` (ydeploy), or with the `theme` addon — without runtime auto-detection: paths are configured per project via the backend Settings form.

PSR-4 autoload: `Ynamite\ViteRex\` → `lib/`.

## Common commands

This repo plays two roles, so it has two unrelated build chains. Don't confuse them.

**Building the addon's own badge assets** (committed to `assets/badge/`, shipped to users):

```bash
npm install
npm run build       # → outputs assets/badge/{viterex-badge.js,viterex-badge.css}
npm run watch       # rebuild on change to assets-src/*
```

The `prebuild` hook runs `scripts/sync-version.js` to mirror `package.yml` → `package.json`. **`package.yml` is the single source of truth for the addon version** — never bump `package.json` directly.

**The user-project Vite chain** lives in `stubs/` — those files are copied into a Redaxo project root by `StubsInstaller`. The user runs `npm install && npm run dev` _in their project_, not here. Don't run `vite dev` in this repo; the local `vite.config.js` is wired to build the badge to `../assets/badge`, not to serve a dev server.

## Architecture: the PHP ↔ Node bridge

The hardest thing to grok is how state flows between Redaxo (PHP runtime) and Vite (Node build). Three persistence layers tie them together:

1. **`rex_config('viterex_addon', ...)`** — backend form writes here. Authoritative for all user-configurable paths. Keys are defined in `lib/Config.php::DEFAULTS` plus `refresh_globs`.
2. **`structure.json`** at `var/data/addons/viterex_addon/` (modern) or `redaxo/data/addons/viterex_addon/` (classic+theme) — JSON cache mirrored from `rex_config` by `Config::syncStructureJson()`. **Read by the Vite plugin on the Node side.** Both candidate paths are tried in `assets/viterex-vite-plugin.js::loadStructureJson`. Re-written on every form save and at the bottom of `pages/settings.php`.
3. **`.vite-hot`** at project root — written by `viterex-vite-plugin.js`'s `hotFilePlugin` when the dev server binds, deleted on exit. PHP reads it (`Server::resolveDevState()`) to decide between dev URLs and built `manifest.json` URLs. **No HTTP probe fallback** — it was a 200ms-per-request perf trap; hot-file presence is the sole signal.

Paths in `structure.json` are always **relative to project root**. The Vite plugin resolves them with `path.resolve(cwd, ...)`; PHP uses `rex_path::base()`.

## Architecture: REX_VITE placeholder

The pipeline is `boot.php` → `OutputFilter::register` → `OutputFilter::rewriteHtml` → `Assets::renderBlock` → `Preload::renderForEntries`. Three things are easy to break:

- **Backend bail-out**: `OutputFilter::register` returns early on `rex::isBackend()` because the slice editor and other admin UIs render the literal string `REX_VITE`. Don't remove this guard.
- **block_peek preview**: rendered inside a backend response, so the OUTPUT_FILTER guard skips it. `boot.php` registers a separate `BLOCK_PEEK_OUTPUT` handler with `rex_extension::LATE` that calls `OutputFilter::rewriteHtml` directly. Conditional on `block_peek` being installed; no hard dependency.
- **Head-scoped, first-occurrence-only**: `rewriteHtml` finds the first `<head>...</head>` block and replaces only the first `REX_VITE` (or `REX_VITE[src="…"]`) inside it. Subsequent placeholders in `<head>` and any `REX_VITE` text in `<body>` (docs pages, slice content, `<code>` / `<pre>` blocks) are left as literal text. The transformation is split into a thin public `rewriteHtml()` shim that delegates to `@internal rewriteHtmlWithBlock(string, callable)` — tests target the latter with a stub block renderer (see `tests/OutputFilterTest.php`).
- **Auto-insert**: if `<head>` exists but contains no `REX_VITE` placeholder, the asset block is injected before the closing `</head>`. Skipped if the rendered block is empty (e.g., dev with hot-file gone but no manifest yet) or if the response has no `<head>` at all.

Placeholder forms: `REX_VITE` (default entries), `REX_VITE[src="x.js"]` (one), `REX_VITE[src="a.css|b.js"]` (pipe-separated). Regex uses `(?<!\w)` so `REX_VITES` etc. don't match. Multiple placeholders in `<head>` are not supported — use the pipe-separated form for multiple entries.

## Architecture: live-reload signal

Default Vite live-reload globs (in `Config::DEFAULT_REFRESH_GLOBS`) include `.vite-reload-trigger` — a single signal file at the project root. `boot.php` registers a handler on **~30 content-save extension points** (`ART_*`, `CAT_*`, `SLICE_*`, `MEDIA_*`, `CLANG_*`, `TEMPLATE_*`, `MODULE_*`; conditional `YFORM_DATA_*`) that `touch()`s the signal file. This is intentional vs. watching `var/cache/addons/...` directly — those paths are written on lazy cache regen during normal frontend navigation and caused spurious reloads.

**Critical**: the EP callback must return `void`, not the bool from `touch()`. `rex_extension::registerPoint` treats any non-null return as the new EP subject, which clobbers downstream success messages and `block_peek` iframe HTML. See the comment above `$viterexReloadSignal` in `boot.php`.

## Architecture: dev/staging/prod stage

`Server::isProductionDeployment()` and `Server::isStagingDeployment()` rely on `rex_ydeploy::factory()->getStage()` (prefix-match). **Without ydeploy installed, both return `false`** and `getDeploymentStage()` falls through to `'dev'`. The `noindex` `YREWRITE_SEO_TAGS` handler in `boot.php` is conditional on ydeploy availability for this reason.

`Server::checkDebugMode()` writes `debug.enabled` to `core/data/config.yml` so the developer addon and other consumers that read the file directly see the right value. Idempotent: skips disk write when the file already matches.

## Architecture: stubs and the auto-copied plugin

There are **two distinct asset-distribution mechanisms**:

1. **`assets/`** (top-level): Redaxo auto-copies an addon's `assets/` tree to `<frontend>/assets/addons/<addon>/` on every (re)install. This is how `viterex-vite-plugin.js` and `dev-server-index.html` end up where the user's `vite.config.js` imports them. Anything committed under `assets/` ships to user projects automatically — that's why `assets/badge/` build output is committed.
2. **`stubs/`**: copied to the project root **on demand** when the user clicks "Install stubs". `StubsInstaller::resolveStubs()` is the source of truth for what gets copied where. The `vite.config.js` stub has a `__VITEREX_PLUGIN_IMPORT_PATH__` token replaced at scaffold time with the structure-aware path (e.g., `./public/assets/addons/viterex_addon/...` for modern, `./assets/addons/viterex_addon/...` for classic with empty public_dir). Backups (`*.bak.YYYYmmdd-HHiiss`) are written before any overwrite.

The `package.yml` `installer_ignore` list excludes `stubs/`'s ancestors and dev-tooling configs from the Redaxo Installer zip — but **`stubs/` itself ships** (the installer needs them).

## Extension points exposed by this addon

- `VITEREX_BADGE` — array of HTML strings; each is rendered as an extra panel inside the dev badge. `redaxo-massif` uses this for the Tailwind breakpoint indicator.
- `VITEREX_PRELOAD` — array of `<link>` strings; appended to the preload block. Params: `entries`, `dev`. Use this for dev-mode webfont preloads (the previous hardcoded `src/assets/fonts` autoprobe was removed in v3.0).

## Plugin extension model (downstream addons)

Other addons extend the Vite plugin by **wrapping** `viterex()`, not by editing it. Convention: ship a `<name>-vite-plugin.js` under `<addon>/assets/`. See the README's `redaxo-massif` example. The user changes one import + one call in their `vite.config.js`. The escape hatch `viterex({ injectConfig: false })` keeps only the hot-file plugin and lets the user write their own config from scratch.

## Release workflow

Tagging a GitHub release triggers `.github/workflows/publish-to-redaxo.yml`:

1. Composer install (no-dev), npm ci, `npm run build` (badge).
2. Zip the addon, excluding dev-only files (mirrors `installer_ignore` plus build chain).
3. Upload zip to the GitHub release via `softprops/action-gh-release`.
4. Publish to MyREDAXO via `FriendsOfREDAXO/installer-action` using `MYREDAXO_USERNAME` / `MYREDAXO_API_KEY` secrets.

The release body becomes the addon description. Bump `package.yml` first, then commit, then tag.

### Important: Always update the `CLAUDE.md`, `CHANGELOG.md`, `README.md` and run `npm run build` to sync the badge assets before tagging a release. The `package.yml` version must match the GitHub release tag. Then create a new tag and a release.

## Things to be careful about

- **Don't add a new config key without updating `Config::DEFAULTS` _and_ `Config::syncStructureJson()` _and_ the `pages/settings.php` form _and_ the `lang/` files.** All four must agree.
- **Default-ON checkbox keys must read via `Config::isEnabled($key)`, not `Config::get` + `isCheckboxChecked`.** `rex_form_checkbox_element` saves "unchecked" as `null` in `rex_config`; `Config::get`'s `?? defaultFor($key)` fallback then resurrects the default value, silently flipping the user's "off" save back to "on" on every read. `isEnabled` uses `array_key_exists` against the namespace array — the only way to distinguish "explicitly set to null" from "never written" (`isset(null) === false` so `rex_config::has()` is no help). For default-OFF checkboxes the bug is invisible (both null and `'0'` resolve to off), which is why it lurked in the codebase until v3.3 added a default-ON toggle.
- **The `Server` class is a singleton** (`self::factory()`). It reads `.vite-hot` and the manifest in its constructor. If you mutate hot-file or manifest state mid-request and need fresh reads, you'd need to reset `self::$instance` — currently not done anywhere.
- **`Preload` is also a singleton** with the same caveat.
- **CSP/nonce limitation**: dev-mode `<script type="module">` tags are emitted without nonces. Strict CSP with `script-src 'self'` will block HMR. Documented in README "Known limitations".
- **SVGO config has one canonical source: `assets/svgo-config.mjs`.** Both runtimes consume it directly — the Vite plugin via `import VITEREX_SVGO_CONFIG from "./svgo-config.mjs"`, `SvgoCli` via `npx svgo --config <abs-path>`. Never define a parallel plugin list in PHP or JS — they will silently drift (this happened in 3.3.0 development). If per-file extensions are ever needed, splice into the canonical config at the call site (both runtimes can read the file and extend it).
- **`IdPrefixer` runs at `Assets::inline()` time only — not at the source-mutation pass.** Reason: the prefix is filename-derived, so baking it onto disk would commit a Viterex-specific class-name scheme into the source SVG and prevent the file's reuse as `<img src>` / `background-image`. Inline-time application keeps disk files generic; the cache (`rex_path::addonCache('viterex_addon', 'inline-svg/')`) absorbs the rewrite cost so subsequent inlines are pure file reads. Per-file opt-out: `<!-- viterex:no-prefix -->` magic comment anywhere in the SVG.

## Roadmap

- **3.3.0 (shipped)**: automatic SVG cleanup & optimization across three surfaces, with engine selection driven by deployment stage rather than per-feature toggles. **Dev** (`Server::getDeploymentStage() === 'dev'`) → SVGO (Node) everywhere: the Vite plugin walks `<assets_source_dir>/**/*.svg` on `configureServer`/`buildStart` and rewrites files 1:1 in place; `viteStaticCopy`'s per-target `transform` callback is extended to optimize SVGs en route; the media-pool MEDIA_ADDED/UPDATED hook shells out to `npx --no-install svgo` with a graceful PHP fallback when `exec` is disabled or svgo isn't installed. **Staging / prod** (stage ≠ `'dev'`) → `mathiasreker/php-svg-optimizer ^8.5` for the media-pool runtime path only; other SVGs are assumed already optimized in the deploy artifact. **Bumped PHP minimum to 8.3** (was 8.1) for the new runtime dep — flagged in CHANGELOG. Single `svg_optimize_enabled` toggle (default ON) added to Config::DEFAULTS, mirrored to `structure.json`, surfaced in Settings under a new "SVG optimization" fieldset. PhpOptimizer/SvgoCli/OptimizerFactory live under `lib/Svg/`; the MEDIA_* handler in `lib/Media/SvgHook.php` (returns void per the EP gotcha — same rule as the reload-signal handler in `boot.php`). 27 new PHPUnit cases under `tests/Svg/`. SVGO ships via `install.php` calling the newly-public `StubsInstaller::syncPackageDeps()` (was `private mergePackageDeps`) — additive, version-compare merge into the user's `package.json`, idempotent; existing v3.2.x installs auto-receive `svgo: ^4.0.0` on upgrade and just need `npm install`. Security side-effect for the media pool: `<script>` and `on*` handlers are stripped from uploads, closing an XSS path that exists by default in any Redaxo accepting SVG uploads. SVGO config centralized to `assets/svgo-config.mjs` — single source of truth consumed directly by both runtimes (Vite plugin imports it; `SvgoCli` passes via `--config`), so the JS↔PHP drift that surfaced during 3.3.0 testing can't recur. Inline-SVG class/id collisions (typical Figma `.cls-1` exports cross-bleeding into other inlined SVGs because their `<style>` blocks have document-level scope) are fixed by `lib/Svg/IdPrefixer.php`, applied at `Assets::inline()` runtime with caching at `rex_path::addonCache('viterex_addon', 'inline-svg/')`; opt-out per file via `<!-- viterex:no-prefix -->` magic comment.

  v3.3.0 simplification follow-up (2026-05-05): dev-stage MEDIA_*-on-upload optimization removed (devs don't want shell-out per test upload), media-pool optimization moved to `npm run build`'s `buildStart` walk + the new `bin/console viterex:optimize-svgs` command, `OptimizerFactory` deleted in favor of inline engine selection. Shared sha1 cache at `<cache_dir>/svg-optimized.json` lets Vite-build and console-command runs skip already-optimal files. Production `MEDIA_*` path unchanged — `PhpOptimizer` still strips `<script>`/on* handlers on every upload.

- **3.2.6 (shipped)**: ydeploy helper — backend subpage (ViteRex → Deploy) for editing deployment hosts via a `deploy.config.php` sidecar that the user's `deploy.php` reads at deployer runtime. Token-based parser extracts hosts from existing `deploy.php` on first visit; Activate rewrites `deploy.php` to require the sidecar inside a marker region (`// >>> VITEREX:DEPLOY_CONFIG ... >>>`). Multi-host supported. Custom user code outside the markers is preserved verbatim; redundant `set('repository', ...)` calls elsewhere in `deploy.php` are detected and commented out so the marker block's value wins (Deployer last-write-wins). Conditional on `rex_addon::get('ydeploy')->isAvailable()`. See `lib/Deploy/{Sidecar,DeployFile,Page}.php`, `pages/deploy.php`, and the design spec at `docs/superpowers/specs/2026-05-02-ydeploy-helper-design.md`.
