# Changelog

## **Version 3.0.0**

Breaking release. ViteRex is now a standalone Redaxo addon installable in **any** Redaxo setup (classic / modern / theme), with a Laravel-vite-plugin-style architecture and a Redaxo backend CRUD form for configuration.

### Added

- **Backend CRUD form** at *AddOns → ViteRex → Settings* (`pages/settings.php`) for all paths and dev settings — entries, public dir, out dir, URL prefix, source dir, static copy dirs, HTTPS, live-reload globs. Persisted via `rex_config('viterex', ...)` and mirrored to `var/data/addons/viterex/structure.json` (modern) or `redaxo/data/addons/viterex/structure.json` (classic+theme) for the Vite plugin to read.
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
