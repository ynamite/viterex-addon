# Changelog

## **Version 3.2.0**

### Added

- **`viterex:install-stubs` Symfony Console command** (`lib/Console/InstallStubsCommand.php`, registered via `console_commands:` in `package.yml`). Programmatic counterpart of the *AddOns → ViteRex → Settings → Install stubs* button — reuses the same `Ynamite\ViteRex\StubsInstaller::run()` path, so output, backups, and `VITEREX_INSTALL_STUBS` extension-point dispatch are identical. Intended for automated install flows that scaffold a project without a browser session (e.g. `create-viterex`). Default behaviour skips existing files; pass `--overwrite` to back them up (`.bak.<timestamp>`) and replace. Print `-v` for the list of written paths.

  ```bash
  php bin/console viterex:install-stubs            # write only missing files
  php bin/console viterex:install-stubs --overwrite # backup + replace existing
  ```

## **Version 3.1.3**

### Fixed

- Restored `rex_developer_manager::setBasePath(rex_path::src())` in `boot.php` when the `developer` addon is available. The call was dropped during the v3 refactor (commit `137f8ea`) along with the now-removed `Structure` class; without it, the developer addon writes to its default location instead of the Redaxo source directory.

## **Version 3.1.2**

- Path-naming consistency pass. No behavior changes beyond the rename. **Migration**: existing 3.0.x / 3.1.0 / 3.1.1 installs have their `structure.json` at `var/data/addons/viterex/` (or `redaxo/data/addons/viterex/`) and reference `assets/addons/viterex/viterex-vite-plugin.js` in their `vite.config.js`. After upgrading, re-save the Settings form to seed the new path, and update the `viterex-vite-plugin.js` import in `vite.config.js` from `…/viterex/…` to `…/viterex_addon/…` (or click "Install stubs" with overwrite to have the import re-baked).
- add exclude to gitignore example for the new `assets/addons/viterex_addon/` path.

### Changed

- **Addon-name normalization across all paths**: `viterex` → `viterex_addon` (matches `package.yml`'s `package:` key, which is what Redaxo's package manager actually uses on disk). Touches:
  - `assets/viterex-vite-plugin.js` — `STRUCTURE_PATH_CANDIDATES` now points at `var/data/addons/viterex_addon/structure.json` and `redaxo/data/addons/viterex_addon/structure.json`; error message updated.
  - `lib/StubsInstaller.php::resolvePluginImportPath()` — `__VITEREX_PLUGIN_IMPORT_PATH__` token is now baked as `./<public>/assets/addons/viterex_addon/viterex-vite-plugin.js` in the scaffolded `vite.config.js`.
  - `stubs/biome.jsonc` — Biome include glob updated to `**/assets/addons/viterex_addon/**/*.*`.
  - `stubs/.env.example`, `README.md`, `CLAUDE.md` — references aligned.

## **Version 3.1.1**

### Fixed

- **HTTPS dev-server checkbox** in _AddOns → ViteRex → Settings_ didn't actually enable HTTPS. `rex_form_checkbox_element` saves checked options as pipe-delimited strings (`|1|`), but `Config::syncStructureJson()` compared `=== '1'` and so always wrote `"https_enabled": false` to `structure.json`. The Vite plugin then started in plain HTTP and the hot file ended up `http://127.0.0.1:5173`. New `isCheckboxChecked()` helper in `lib/Config.php` strips outer pipes, splits on `|`, and tests for `'1'` membership — round-trips correctly across all five values the field can take (`|1|`, `||`, `''`, `'0'`, `'1'`).

## **Version 3.1.0**

Programmatic API additions for downstream addons (e.g. redaxo-massif). No breaking changes.

### Added

- **`StubsInstaller::installFromDir($sourceDir, $stubsMap, $overwrite, $packageDeps)`** — public, reusable file-installer. Downstream addons call it from their own `install.php` / Settings handlers to push files into the user's project, reusing viterex_addon's path-baking, backup-on-overwrite, and structure-aware target resolution.
- **`StubsInstaller::appendRefreshGlobs($lines)`** — public helper for downstream addons that need Vite to live-reload on additional paths (e.g. their own fragments/lib directories). Reads `rex_config('viterex','refresh_globs')`, idempotently appends only missing lines.
- **`VITEREX_INSTALL_STUBS` extension point** — fired from inside `StubsInstaller::run()` (the path triggered by viterex's "Install Stubs" button). Subject is the result array `{written, skipped, backedUp, gitignoreAction}`. Subscribers can append entries by calling `installFromDir()` themselves and merging the returned arrays into the subject. Params: `overwrite` (bool, from the user's checkbox).
- **`mergePackageDeps()` private helper** — wired into `installFromDir` via the `$packageDeps` argument. Merges npm dependencies into the user's `package.json` additively, with `version_compare` resolving conflicts (higher wins).

### Internal

- `StubsInstaller::run()` refactored to delegate file copying to `installFromDir()`. Behavior preserved; `gitignoreAction` still set via the existing `mergeGitignore()` (now only run by `run()`, not the generic `installFromDir`).
- Hardcoded install-result message in `pages/settings.php` extracted to a `viterex_install_result` lang key (`lang/en_gb.lang`, `lang/de_de.lang`). Now uses `rex_i18n::rawMsg()` like the rest of the page.
- German translation polish in `lang/de_de.lang` — leftover English fragments translated (`Entry Points` → `Einstiegspunkte`, `Tooling` → `Werkzeuge`, `Web-served` → `Vom Webserver ausgeliefertes`, `Install stubs` → `Stubs installieren`, `JS-Entry` / `CSS-Entry` → `JS-Einstiegspunkt` / `CSS-Einstiegspunkt`, `Dev-Tooling-Configs` → `Dev-Tooling-Konfigurationen`).

### Documentation

- `README.md` rewritten in German with full feature coverage matching the English version (entry points, paths, dev settings, hot-file flow, `REX_VITE` placeholder, dev badge, downstream-addon API). Earlier in the cycle, the English README was also revised for installation paths and settings.
- `docs/vite-plus-evaluation.md` added — evaluation of a potential Vite+ migration. Recommendation: monitor, no migration today.
- Hero image `viterex.png` added at repo root.

## **Version 3.0.0**

Breaking release. ViteRex is now a standalone Redaxo addon installable in **any** Redaxo setup (classic / modern / theme), with a Laravel-vite-plugin-style architecture and a Redaxo backend CRUD form for configuration.

### Added

- **Backend CRUD form** at _AddOns → ViteRex → Settings_ (`pages/settings.php`) for all paths and dev settings — entries, public dir, out dir, URL prefix, source dir, static copy dirs, HTTPS, live-reload globs. Persisted via `rex_config('viterex', ...)` and mirrored to `var/data/addons/viterex/structure.json` (modern) or `redaxo/data/addons/viterex/structure.json` (classic+theme) for the Vite plugin to read.
- **"Install stubs" button** with optional "overwrite existing" checkbox — copies `package.json`, `vite.config.js`, `.env.example`, dev-tooling configs, and entry stubs to the project root. Bakes the structure-aware `viterex-vite-plugin.js` import path at scaffold time. Also performs `.gitignore` scan-and-merge.
- **`REX_VITE` placeholder** with optional `[src="a|b"]` attribute (pipe-separated entries). Auto-inserts before `</head>` when no placeholder is present.
- **`assets/viterex-vite-plugin.js`** — Laravel-style Vite plugin shipped in the addon's `assets/` (Redaxo auto-copies to `<frontend>/assets/addons/viterex/`). Exports default `viterex(options)` returning a Plugin[] (hot-file plugin, named `viterex` plugin with `config()` hook injecting build/server/css/resolve, `viteStaticCopy` sibling, `liveReload` sibling). Also exports `fixTailwindFullReload()` for the Tailwind 4 hotUpdate workaround. Single-bind exit handlers for `exit`/`SIGINT`/`SIGTERM`/`SIGHUP`.
- **`Assets::path/url/inline`** — PHP helpers for static-asset references in templates (background images, inline SVG, etc.).
- **`Tailwind 4` shipped by default**: `@tailwindcss/vite`, `@tailwindcss/forms`, `@tailwindcss/typography`, `tailwind-clamp` are all in the scaffolded `package.json`. Users who don't want Tailwind delete two lines from `vite.config.js`.
- **`vite-plugin-static-copy`** for static-asset mirroring — replaces `rollup-plugin-copy` (which had `writeBundle` timing issues under Vite 8 + rolldown).
- **`mkcert`** as devDependency + `npm run setup-https` script — one command for local HTTPS certs.
- **Hot-file detection** at `<base>/.vite-hot` (dotfile, project root, single source of truth across all structures). Optional HTTP probe fallback when `VITE_DEV_SERVER` is set in env.
- **Reliable live-reload trigger** via PHP extension-point handlers. `boot.php` registers callbacks on ~30 content-save EPs (`ART_*`, `CAT_*`, `SLICE_*`, `MEDIA_*`, `CLANG_*`, `TEMPLATE_*`, `MODULE_*`, `YFORM_DATA_*`) that `touch()` a single signal file `<base>/.vite-reload-trigger`. The Vite plugin watches that file via `vite-plugin-live-reload`. Fixes the previous default that watched `var/cache/addons/{structure,url}/**` — those dirs are written on lazy cache regeneration during normal frontend navigation, causing spurious reloads. **Migration**: existing v3 installs that had the old default in their `Live-reload globs` setting need to manually update via Settings (replace `var/cache/addons/...` with `.vite-reload-trigger`).
- **Badge enhancements**: stage indicator (`dev`/`staging`/`prod`), Vite-running indicator with click-to-copy URL, Clear-cache button (CSRF-protected), `VITEREX_BADGE` extension point for downstream addons.
- **`VITEREX_PRELOAD`** extension point for custom dev-mode preload tags (e.g. webfonts).
- **Full manifest import-graph walk** with cycle detection in `Preload` (previously only one level deep).
- **`lib/Config.php`** — single source of truth for paths; `get/set/all/syncStructureJson/getHotFilePath/getHostUrl`.
- **`lib/StubsInstaller.php`** — handles the Install Stubs button action (copy + path-baking + gitignore merge).

### Changed

- **Composer autoload** PSR-4 (`Ynamite\ViteRex\`); `lib/Badge/Badge.php` moved to `lib/Badge.php`.
- **`package.yml`** PHP requirement `>=7.3` → `>=8.1`. Adds `page` section with subpages (settings, docs) and `installer_ignore` list.
- **Addon `assets/` layout**: badge build artifacts under `assets/badge/`, the Vite plugin file at `assets/viterex-vite-plugin.js` (top level). Internal vite.config.js outDir is `../assets/badge` so `emptyOutDir: true` doesn't wipe the committed plugin file.
- **`rex_developer_manager::setBasePath`** call removed — was structure-specific and not generally applicable now that paths are user-configured.
- **Build paths configurable via Settings** — defaults are modern (`public/dist`); classic/theme users adjust via the form. No more runtime auto-detection magic.

### Removed

- **`Assets::get()`** — templates must use `REX_VITE` placeholders.
- **`Server::getAssetsUrl/getImg/getCss/getFont/getJs/getAssetsPath/setValue`** etc. — replaced by `Assets::url/path/inline`.
- **`lib/Structure.php`** — replaced by `lib/Config.php`. No more runtime classic/modern/theme auto-detection.
- **Dev-mode font autoprobe** in `Preload` — was MASSIF-specific (hardcoded `src/assets/fonts`). Use the `VITEREX_PRELOAD` extension point for custom preloads.
- **`stubs/vite/`** subfolder — the Vite plugin file is no longer scaffolded into the user project; it ships inside the addon and is auto-copied by Redaxo.
- **`rollup-plugin-copy`** — replaced by `vite-plugin-static-copy` (rolldown-safe).

## **12.02.2024 Version 1.0.0**

- erste Version
