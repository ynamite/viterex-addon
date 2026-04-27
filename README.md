# ViteRex für REDAXO 5

ViteRex ist ein eigenständiges Redaxo-Addon, das ein modernes Vite-Frontend (Tailwind 4, Live-Reload, Hot-Module-Replacement) in **jede** Redaxo-Installation einbringt — `classic`, `modern` (ydeploy) oder mit `theme`-Addon. Pfade konfigurierst du im Backend; auf Knopfdruck scaffolded das Addon `package.json`, `vite.config.js`, Dev-Tooling-Defaults, Beispiel-Entries und merged `.gitignore`-Einträge in dein Projekt-Root.

> **Vorgängerversion 2.x**: siehe [Migration](#migration-from-2x). v3 ist eine breaking release.

---

## Installation

Wie jedes Redaxo-Addon — **nicht via Composer**.

**Über den Redaxo Installer (empfohlen):** Backend → *AddOns → Installer*, `viterex` suchen, herunterladen, aktivieren.

**Manuell von GitHub:** Repo nach `redaxo/src/addons/viterex/` (modern) bzw. `addons/viterex/` (classic) entpacken, im Backend installieren und aktivieren.

Beim Installieren passiert **nichts im Projekt-Root** — das Addon registriert sich nur und seedet `var/data/addons/viterex/structure.json` mit den Default-Pfaden. Konfiguration und Scaffolding läuft danach über das Backend.

---

## Erste Schritte

1. **Backend → AddOns → ViteRex → Settings** öffnen.
2. Pfade reviewen / anpassen — Defaults sind modern (ydeploy)-tauglich (`src/assets/js/main.js`, `public/dist`, `/dist`). Für `classic`: `Public directory` leer lassen, `Build output directory` auf `dist`. Für `theme`: `theme/public` und `theme/public/dist`.
3. Form speichern (synchronisiert `structure.json`).
4. Auf den Button **Install stubs** klicken (mit dem Häkchen darunter kannst du existierende Files überschreiben). Das scaffolded:
   - `package.json` (Vite 8 + Tailwind 4 + Plugins + Dev-Tooling)
   - `vite.config.js` (minimal, Laravel-style — der Import-Pfad zu `viterex-vite-plugin.js` wird aus deiner `Public directory`-Einstellung generiert)
   - `.env.example`, `.browserslistrc`, `.prettierrc`, `biome.jsonc`, `stylelint.config.js`, `jsconfig.json`
   - `<assets_source_dir>/js/main.js` und `<assets_source_dir>/css/style.css`
5. `.gitignore` wird zeilenweise gescannt — fehlende ViteRex-Einträge werden unter einer `# Added by viterex`-Markierung ergänzt.
6. `npm install && npm run dev` — fertig. Vite startet, `.vite-hot` taucht im Projekt-Root auf, Browser zeigt deine Seite mit HMR.

---

## Der `REX_VITE`-Platzhalter

In beliebigen Redaxo-Templates:

```php
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    REX_VITE
</head>
<body>
    ...
</body>
</html>
```

Formen:

| Form | Verhalten |
|---|---|
| `REX_VITE` | Default-Entries (CSS + JS) aus den CRUD-Settings. |
| `REX_VITE[src="src/assets/js/main.js"]` | Einzelner expliziter Entry. |
| `REX_VITE[src="src/assets/css/style.css\|src/assets/js/main.js"]` | Mehrere Entries, pipe-separiert. |

Pro Vorkommen werden in dieser Reihenfolge ausgegeben:

1. `<link rel="modulepreload">` und `<link rel="preload">` für Imports/CSS/Fonts/Assets aus dem Manifest (vollständige Walk, Zyklen-erkannt).
2. `<link rel="stylesheet">` für jeden CSS-Entry. **Auch im Dev** (separater Vite-Entry).
3. `<script type="module" src="<devUrl>/@vite/client">` (nur Dev, einmal pro Vorkommen mit JS-Entries).
4. `<script type="module" src="...">` pro JS-Entry.

**Auto-Insert-Fallback**: wird `REX_VITE` in der gerenderten Seite nicht gefunden, fügt der Output-Filter den Block automatisch vor dem ersten `</head>` ein.

Frontend-only — backend bails früh.

---

## PHP-Helpers für statische Assets

Für Assets, die aus PHP-Templates referenziert werden (Background-Images, Logos, inline SVG):

```php
use Ynamite\ViteRex\Assets;

// URL für ein bereits existierendes Asset (Browser):
//   dev  → https://localhost:5173/src/assets/img/logo-640w.webp
//   prod → /dist/assets/img/logo-640w.webp
<img src="<?= Assets::url('img/logo-640w.webp') ?>">

// CSS background-image:
<div style="background-image:url(<?= Assets::url('img/hero.jpg') ?>)">…</div>

// Absoluter Filesystem-Pfad:
$svgPath = Assets::path('img/logo.svg');

// Inline-SVG/JSON/Text:
<?= Assets::inline('img/icon-arrow.svg') ?>
```

Damit Assets in Produktion unter den `Assets::url()`-Pfaden existieren, müssen sie beim Build kopiert werden — das übernimmt `vite-plugin-static-copy`, gesteuert via `Static copy directories` in den Settings (Default: `img`).

Assets, die per JS oder CSS importiert werden (`import "../img/foo.png?url"` oder `background: url("../img/foo.png")`), werden von Vite ohnehin gehasht und in `manifest.json` eingetragen — keine Extra-Konfiguration nötig.

---

## Settings-Felder (CRUD)

| Feld | Default | Zweck |
|---|---|---|
| **JS entry** | `src/assets/js/main.js` | Vite JS-Entry-Pfad |
| **CSS entry** | `src/assets/css/style.css` | Vite CSS-Entry-Pfad |
| **Public directory** | `public` | Web-served Verzeichnis. Modern: `public`. Classic: leer. Theme: `theme/public`. |
| **Build output directory** | `public/dist` | Vite `outDir` |
| **Build URL prefix** | `/dist` | URL-Prefix für gebaute Assets |
| **Assets source directory** | `src/assets` | Wo deine Source-Assets liegen |
| **Assets sub-directory** | `assets` | Vite `build.assetsDir` |
| **Static copy directories** | `img` | Komma-separierte Liste — kopiert beim Build von `<assets_source_dir>/<dir>/` nach `<out_dir>/<assets_sub_dir>/<dir>` |
| **Enable HTTPS dev server** | off | Aktiviert HTTPS, wenn mkcert-Certs am Projekt-Root liegen |
| **Live-reload globs** | (PHP-Templates etc.) | Ein Glob pro Zeile — Vite triggert Full-Reload bei Änderung passender Files |

---

## HTTPS Dev-Server

`npm run setup-https` erzeugt mit [mkcert](https://github.com/FiloSottile/mkcert) lokale Zertifikate (`localhost+2-key.pem`, `localhost+2.pem`) am Projekt-Root. Dann in den Settings *Enable HTTPS dev server* aktivieren — Vite startet beim nächsten `npm run dev` über HTTPS, das Hot-File enthält `https://...`.

mkcert installiert beim ersten Start eine lokale Root-CA in deinen System-Trust-Store (eine einmalige Sicherheitsabfrage). Auf macOS via Homebrew bzw. in Chrome/Firefox automatisch erkannt.

---

## Vite-Konfiguration erweitern (für Addon-Entwickler)

`viterex-vite-plugin.js` ist Laravel-vite-plugin-style: ein Aufruf, alle Defaults injected, Override via Vite's `mergeConfig`.

**Pro Projekt** — direkt in deiner `vite.config.js`:

```js
export default defineConfig({
  plugins: [
    tailwindcss(),
    fixTailwindFullReload(),
    viterex({
      input: ["src/admin/main.js"],            // zusätzliche Entries (mergeConfig konkateniert)
      refresh: ["src/templates/**/*.php"],     // engere Live-Reload-Globs
    }),
  ],
  build: { sourcemap: true },                  // alles hier überschreibt viterex's Defaults
});
```

**Escape Hatch** — komplette Vite-Config selbst schreiben:

```js
viterex({ injectConfig: false })   // nur Hot-File bleibt; alles andere musst du selbst setzen
```

**Downstream-Addons** (z. B. `redaxo-massif`): legen ihren eigenen Helper unter `viterex-addon/assets/<name>-vite-plugin.js` an, der `viterex()` umschliesst:

```js
// redaxo-massif/assets/massif-vite-plugin.js
import vue from "@vitejs/plugin-vue";
import viterex from "../viterex/viterex-vite-plugin.js";

export default function massif(userOptions = {}) {
  return [
    ...viterex({ refresh: false, ...userOptions }),  // viterex zuerst
    vue(),
  ];
}
```

User wechselt dann in seiner `vite.config.js` einfach den Import + Plugin-Aufruf von `viterex` auf `massif`.

---

## Erweiterungspunkte (PHP)

| Name | Subject | Verwendung |
|---|---|---|
| `VITEREX_BADGE` | `array` von HTML-Strings | Zusätzliche Panels im ViteRex-Badge rendern (z. B. Tailwind-Breakpoint-Indikator). |
| `VITEREX_PRELOAD` | `array` von `<link>`-Strings | Custom Preload-Links einfügen (z. B. Webfonts im Dev). Parameter: `entries`, `dev`. |

---

## Das Badge

Bei aktiver Backend-Session und Nicht-Prod/Nicht-Staging-Umgebung wird ein Badge unten am Fenster eingeblendet (Frontend + Backend). Zeigt: Stage (`dev`/`staging`/`prod`), Git-Branch, Vite-Status (laufend? URL?) — mit Click-to-copy, ViteRex- und Redaxo-Version, **Clear cache**-Button (CSRF-geschützter POST). Andere Addons können via `VITEREX_BADGE`-Extension-Point eigene Panels hinzufügen.

---

## Migration from 2.x

- **`Assets::get()` entfernt.** Templates, die `<?= $assets['js'] ?>` / `$assets['css']` / `$assets['preload']` nutzten, auf `REX_VITE[src="..."]` umstellen — oder `REX_VITE` ganz weglassen und Auto-Insert nutzen.
- **`Server::getAssetsUrl/getImg/...` entfernt.** Stattdessen `Assets::url('img/foo.png')`, `Assets::path()`, `Assets::inline()`.
- **Struktur-Auto-Detection entfernt.** Konfiguration jetzt im Backend (Settings).
- **Mindest-PHP-Version `>=8.1`.**
- **Alles wird über das Backend konfiguriert** — `.env`-Variablen für viterex-spezifische Pfade gibt's nicht mehr.

---

## Contributing

Das Backend-Badge wird mit Vite gebaut. Wenn du Files unter `assets-src/` änderst, regeneriere die kompilierten Versionen unter `assets/badge/`:

```bash
cd viterex-addon
npm install   # einmalig
npm run build
```

Die `assets/`-Baum wird mitcommittet (Badge-Build + `viterex-vite-plugin.js`) und ist Teil des Releases.

---

## Testing your installation

End-to-end-Checkliste:

1. **Fresh install (modern):** Aktivieren → Settings öffnen → Defaults sehen. Form speichern → `var/data/addons/viterex/structure.json` existiert. **Install stubs** klicken → `package.json`, `vite.config.js`, `.env.example`, `src/assets/{js,css}/`, etc. erscheinen am Projekt-Root. `vite.config.js` enthält `import ... from "./public/assets/addons/viterex/viterex-vite-plugin.js"`. `npm install && npm run dev` → Vite startet, `.vite-hot` erscheint.
2. **Reinstall stubs (overwrite checked):** Ersetzt Files. **Reinstall stubs (overwrite unchecked):** Listet Skipped Files.
3. **Dev-Mode (default REX_VITE):** Template lädt → drei Tags sichtbar (CSS-Link, `@vite/client`-Script, JS-Script).
4. **Auto-Insert:** Template ohne `REX_VITE` → derselbe Block wird vor `</head>` eingefügt.
5. **Prod-Mode:** `npm run build` → Manifest unter `public/dist/.vite/manifest.json`. Vite stoppen → reload → gehashte URLs unter `/dist/assets/`.
6. **Static-copy:** Datei in `src/assets/img/` legen → `npm run build` → existiert unter `public/dist/assets/img/`.
7. **Assets::url/path/inline:** `<?= Assets::url('img/logo.png') ?>` → dev-server-URL in dev, `/dist/assets/img/logo.png` in prod.
8. **Classic:** Settings → `Public directory` leer, `Build output directory` `dist`. Install stubs (overwrite) → vite.config.js-Import-Pfad wird `./assets/addons/viterex/viterex-vite-plugin.js`. Build outputs nach `<base>/dist/`.
9. **Theme:** Settings → `Public directory` `theme/public`, `Build output directory` `theme/public/dist`. Install stubs.
10. **HTTPS:** `npm run setup-https` → Certs erzeugt. Settings → *Enable HTTPS* aktivieren → save → reload Vite. Hot-File enthält `https://`.
11. **Badge:** Frontend + Backend sichtbar, Stage korrekt, Vite-Running flippt beim Start/Stopp, Clear-Cache funktioniert, `VITEREX_BADGE`-Extension-Point lädt Extra-Panels.
12. **Keine Regressionen:** `YREWRITE_SEO_TAGS`-noindex bei Nicht-Prod aktiv. Backend-Bail im OUTPUT_FILTER funktioniert.

---

## Known limitations

- **CSP / Nonces:** Im Dev emittiert der Output-Filter `<script type="module">` ohne Nonce. Strikte CSP mit `script-src 'self'` blockiert HMR. Workaround: CSP im Dev lockern oder Output-Filter mit `LATE`-Priorität registrieren, der die Tags um Nonce-Attribute erweitert.
- **Multi-Language mit Subpath-Mount:** URLs als root-absolute Pfade (`/dist/assets/...`). Für 99 % korrekt; Subpath-Mounts brauchen `Build URL prefix` explizit angepasst.
- **Manifest-Caching:** Pro Request einmal gelesen. Für hochfrequente Prod-Installations lohnt sich `rex_cache`-Wrapping im Server-Singleton.

---

## Issues / Kontakt

Bug-Reports & Features auf [GitHub](https://github.com/ynamite/viterex-addon/issues). Änderungen im [CHANGELOG.md](CHANGELOG.md).

## Lizenz

[MIT](LICENSE.md)

## Credits

- [FriendsOfREDAXO](https://github.com/FriendsOfREDAXO)
- Project Lead: [Yves Torres](https://github.com/ynamite)
- Inspired by [laravel-vite-plugin](https://github.com/laravel/vite-plugin)
