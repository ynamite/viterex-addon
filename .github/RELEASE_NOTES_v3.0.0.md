# ViteRex 3.0.0 — Standalone Redaxo Addon

> **Breaking release.** v2.x users: see [Migration](#migration-from-v2x).

ViteRex is now a fully standalone Redaxo addon, installable in **any** Redaxo setup (`classic`, `modern`/ydeploy, or with the `theme` addon). Configuration lives in the Redaxo backend; on a button click the addon scaffolds a complete Vite + Tailwind 4 frontend pipeline into your project root.

## Highlights

- **Backend CRUD form** for all paths and dev settings (entries, public dir, build dir, URL prefix, source dir, static-copy dirs, HTTPS, live-reload globs). Persisted to `rex_config`, mirrored to a `structure.json` the Vite plugin reads.
- **"Install Stubs" button** scaffolds `package.json`, `vite.config.js`, `.env.example`, dev-tooling configs, and entry stubs into the project root. Optional "Overwrite existing" checkbox creates timestamped backups before replacing — never silently destroys user code.
- **Tailwind 4 by default**: `@tailwindcss/vite`, `@tailwindcss/forms`, `@tailwindcss/typography`, `tailwind-clamp`. Don't want it? Delete two lines from the scaffolded `vite.config.js`.
- **Laravel-vite-plugin-style API**: scaffolded `vite.config.js` is ~10 lines. `viterex()` injects build/server/css/resolve via Vite's `config()` hook; user overrides win via Vite's `mergeConfig`. Escape hatch `viterex({ injectConfig: false })` for full control.
- **`REX_VITE` placeholder** with optional `[src="…"]` attribute (pipe-separated for multiple entries). Auto-inserts before `</head>` when no placeholder is found in the rendered HTML.
- **PHP helpers** for static assets referenced from templates: `Assets::url('img/logo.png')`, `Assets::path()`, `Assets::inline()`.
- **Reliable live-reload**: PHP extension-point handlers on ~30 Redaxo content-save events (`ART_*`, `CAT_*`, `SLICE_*`, `MEDIA_*`, `TEMPLATE_*`, `MODULE_*`, etc.) `touch()` a single signal file `<base>/.vite-reload-trigger` that Vite watches. Reloads fire only on actual admin saves — no more spurious reloads from cache regeneration.
- **Hot-file dev detection** at `<base>/.vite-hot` (single source of truth across structures); replaces the per-request HTTP probe.
- **`block_peek` integration** — `REX_VITE` placeholders work inside block_peek's preview iframes via a `BLOCK_PEEK_OUTPUT` handler.
- **`mkcert` integration** — `npm run setup-https` generates local certs; ViteRex auto-detects them when `https_enabled` is on in Settings.
- **Friendly Vite-server landing page** — visiting the Vite dev URL directly shows a styled "Vite is running, your site is at <URL>" page instead of a blank screen.
- **Badge**: stage indicator (`dev`/`staging`/`prod`), Vite-running dot with tooltip showing the dev URL on hover, Clear-cache button (CSRF-protected POST), `VITEREX_BADGE` extension point for downstream addons.
- **Multi-language**: full `de_de` + `en_gb` translations.

## Installation

> **Not via Composer.** As a regular Redaxo addon.

- **Redaxo Installer (recommended):** Backend → *AddOns → Installer*, search `viterex`, download, activate.
- **Manual from GitHub:** unpack into `redaxo/src/addons/viterex/` (modern) or `addons/viterex/` (classic), then install + activate in the backend.

After activation: open *AddOns → ViteRex → Settings*, review the defaults (modern-friendly out of the box; for classic/theme adjust `Public directory` and `Build output directory`), save, then click **Install Stubs**.

## Migration from v2.x

- **`Assets::get()` removed** — templates that called `<?= $assets['js'] ?>` / `$assets['css']` / `$assets['preload']` should switch to `REX_VITE[src="…"]`, or simply remove the placeholder and rely on auto-insert before `</head>`.
- **`Server::getAssetsUrl()` / `getImg()` / `getCss()` / `getFont()` / `getJs()` / `getAssetsPath()` removed** — replaced by `Assets::url()`, `Assets::path()`, `Assets::inline()`.
- **Auto-detection of classic / modern / theme structure removed** — paths are now configured via the backend Settings form. Defaults match modern (ydeploy); adjust `Public directory` and related fields once for classic/theme.
- **PHP `>= 8.1`**, Vite `^8`, Node `>= 20.19`.

## Requirements

- Redaxo `^5.13.0`
- PHP `>= 8.1`
- Node `>= 20.19` (Vite 8 requirement) for the frontend pipeline

## Documentation

Full docs in [README.md](https://github.com/ynamite/viterex-addon/blob/main/README.md) — covers the `REX_VITE` placeholder, PHP helpers, CRUD settings reference, extending the Vite plugin (Laravel-style), block_peek integration, and a testing checklist.

## Issues / Feedback

Bug reports and feature requests on [GitHub Issues](https://github.com/ynamite/viterex-addon/issues).
