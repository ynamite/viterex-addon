# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository identity

`viterex_addon` is a standalone Redaxo CMS addon (`>=5.13`, PHP `>=8.1`) that bridges Redaxo and Vite (8.x). It is installable in **any** Redaxo setup — `classic`, `modern` (ydeploy), or with the `theme` addon — without runtime auto-detection: paths are configured per project via the backend Settings form.

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
- **Auto-insert**: if no `REX_VITE` placeholder is found anywhere, `rewriteHtml` injects the asset block before the first `</head>`. Skip this if the rendered block is empty (e.g., dev with hot-file gone but no manifest yet).

Placeholder forms: `REX_VITE` (default entries), `REX_VITE[src="x.js"]` (one), `REX_VITE[src="a.css|b.js"]` (pipe-separated). Regex uses `(?<!\w)` so `REX_VITES` etc. don't match.

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

## Things to be careful about

- **Don't add a new config key without updating `Config::DEFAULTS` _and_ `Config::syncStructureJson()` _and_ the `pages/settings.php` form _and_ the `lang/` files.** All four must agree.
- **The `Server` class is a singleton** (`self::factory()`). It reads `.vite-hot` and the manifest in its constructor. If you mutate hot-file or manifest state mid-request and need fresh reads, you'd need to reset `self::$instance` — currently not done anywhere.
- **`Preload` is also a singleton** with the same caveat.
- **CSP/nonce limitation**: dev-mode `<script type="module">` tags are emitted without nonces. Strict CSP with `script-src 'self'` will block HMR. Documented in README "Known limitations".

## Roadmap

- **v3.3**: automatically optimize SVGs referenced from templates with either `vite-plugin-svgr` or a PHP alternative when using `Assets::inline()` with an SVG path. SVGs in CSS/JS should also be optimized via `vite-plugin-svgr` or similar.
  SVGs uploaded to the media pool should be optimized by hooking into the `MEDIA_ADD` EP.

  *Originally targeted v3.2; pushed to v3.3 because v3.2.0 shipped the `viterex:install-stubs` CLI command for create-viterex / other automated install flows.*
