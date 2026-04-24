# Changelog

## **Version 3.0.0**

Breaking release. ViteRex is now a standalone Redaxo addon installable in any Redaxo setup (classic / modern / theme).

### Added
- `REX_VITE` placeholder with optional `[src="a|b"]` attribute, replaced in the `OUTPUT_FILTER`. Auto-inserts before `</head>` when no placeholder is present.
- `lib/Structure.php` — auto-detects classic / modern / theme layouts; caches result to `data/structure.json` for the Node side.
- `lib/OutputFilter.php` — frontend-only placeholder rewriter with backend early-bail.
- `install.php` — scaffolds `package.json`, `vite.config.js`, `vite/viterex.js`, `vite/hotfile-plugin.js`, `.env.example`, dev-tooling configs, and minimal `src/Main.js` + `src/style.css` into the project root.
  - Existing files are preserved; defaults land as `*.viterex-default` siblings for manual diffing.
  - `.gitignore` is scanned line-by-line and missing entries appended under `# Added by viterex`.
- Hot-file primary dev-server detection (`<public>/.hot`), replacing the per-request HTTP probe. HTTP probe remains as a fallback when `VITE_DEV_SERVER` is set in `.env`.
- Badge extensions: stage (`dev`/`staging`/`prod`), Vite running indicator + click-to-copy URL, Clear-cache button, `VITEREX_BADGE` extension point for other addons.
- `VITEREX_PRELOAD` extension point for custom preload tags (e.g. webfont preloads).
- Full manifest import-graph walk with cycle detection (previously only descended one level).
- `Server::getDeploymentStage()` and `Server::getDevUrl()`.

### Changed
- Composer autoload switched from classmap to PSR-4 (`Ynamite\ViteRex\`). `lib/Badge/Badge.php` moved to `lib/Badge.php`.
- `package.yml` PHP requirement bumped `>=7.3` → `>=8.1` (already required by `composer.json`).
- Addon's own `package.json` no longer `rsync`s build output to `../../../public/...`; Redaxo's native `assets/addons/<addon>` symlink handles backend asset serving.
- `rex_developer_manager::setBasePath(rex_path::src())` now only runs on `modern` structure (previously broke classic-structure users).

### Removed
- `Assets::get()` — templates must use `REX_VITE` placeholders.
- `Assets::getAsset()`, `getImg()`, `getCss()`, `getFont()`, `getJs()`, `getAssetsPath()`, `getAssetsUrl()`, `Server::setValue()` — legacy helpers dropped in favor of the new OUTPUT_FILTER contract.
- Dev-mode font autoprobe in `Preload` — was MASSIF-specific (hardcoded `src/assets/fonts`). Register a `VITEREX_PRELOAD` handler for custom preloads.

## **12.02.2024 Version 1.0.0**

- erste Version
