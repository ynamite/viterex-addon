# ViteRex für REDAXO 5

ViteRex ist ein eigenständiges Redaxo-Addon, das Vite-basierte Frontend-Entwicklung in beliebige Redaxo-Installationen integriert — unabhängig davon, ob das Projekt mit der klassischen Verzeichnisstruktur, ydeploy (modern) oder dem `theme`-Addon arbeitet. Nach der Aktivierung wird im Projekt-Root ein minimales, framework-agnostisches Vite-Setup ausgerollt (`package.json`, `vite.config.js`, `.env.example`, Dev-Tooling wie Biome/Prettier/Stylelint). Ein `OUTPUT_FILTER` ersetzt `REX_VITE`-Platzhalter in Templates durch die passenden `<link>`/`<script>`-Tags — in der Entwicklung zeigt er auf den Vite-Dev-Server, in der Produktion auf die über die Manifest-Datei referenzierten Build-Assets.

> Upgraden von 2.x? Siehe [Migration](#migration-from-2x).

---

## Installation

ViteRex wird wie jedes Redaxo-Addon installiert — **nicht über Composer**. Zwei Wege:

**Über den Redaxo Installer (empfohlen):** Im Backend unter _AddOns → Installer_ nach `viterex` suchen, herunterladen und aktivieren.

**Manuell aus GitHub:** Den Inhalt dieses Repos nach `redaxo/src/addons/viterex/` (modern/ydeploy) bzw. `addons/viterex/` (classic) entpacken oder klonen, dann im Backend unter _AddOns_ installieren und aktivieren.

Beim Aktivieren werden die folgenden Scaffolding-Dateien in das Projekt-Root kopiert:

| Datei                                                                                   | Zweck                                                                                                                                                                                                                                                     |
| --------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `package.json`                                                                          | Node-Abhängigkeiten (Vite + Dev-Tooling + `vite-plugin-live-reload` + `rollup-plugin-copy`). Keine Framework-Pakete.                                                                                                                                      |
| `vite.config.js`                                                                        | Struktur-agnostischer Bootstrap: probt `<frontend>/assets/addons/viterex/vite/viterex.js` an drei Kandidaten-Pfaden und importiert `defineViterexConfig` per dynamischem `await import`. Damit überlebt die Datei einen Strukturwechsel ohne Re-Scaffold. |
| `.env.example`                                                                          | Dokumentierte `VITE_*`-Variablen inkl. `VITE_COPY_DIRS`.                                                                                                                                                                                                  |
| `.browserslistrc`, `.prettierrc`, `biome.jsonc`, `stylelint.config.js`, `jsconfig.json` | Dev-Tooling-Defaults.                                                                                                                                                                                                                                     |

Die eigentliche Vite-Logik (`viterex.js` + `hotfile-plugin.js`) wird **nicht** als Stub kopiert. Sie liegt im Addon unter `viterex-addon/assets/vite/` und wird von Redaxo automatisch nach `<frontend>/assets/addons/viterex/vite/` kopiert (siehe `packages/manager.php` Core). Ein Update des Addons aktualisiert damit gleichzeitig die Vite-Konfiguration — kein manueller Diff nötig.

Zusätzlich werden — **abhängig von der erkannten Struktur** — der Default-Entry-Point und ein passendes Stylesheet gescaffoldet:

| Struktur                          | Source-Pfade                                                    |
| --------------------------------- | --------------------------------------------------------------- |
| `modern` (ydeploy, **empfohlen**) | `src/assets/js/Main.js`, `src/assets/css/style.css`             |
| `classic`                         | `assets/js/Main.js`, `assets/css/style.css`                     |
| `theme`                           | `theme/src/assets/js/Main.js`, `theme/src/assets/css/style.css` |

Wird eine andere Struktur als `modern` erkannt, gibt das Addon nach der Installation einen Hinweis aus, der zur Migration ermutigt — alle drei Strukturen funktionieren, aber `modern` profitiert am stärksten von ydeploy, Live-Reload-Pfaden und dem Tooling.

Existiert eine Datei bereits am Zielort, wird sie **nicht** überschrieben. Stattdessen wird eine Kopie als `<datei>.viterex-default` angelegt; du kannst die Default-Variante manuell mit deiner Version vergleichen.

`.gitignore` wird zeilenweise gescannt — fehlende ViteRex-Einträge (`node_modules/`, `*.hot`, `<buildpath>/.vite/`) werden unter einer `# Added by viterex`-Markierung ergänzt. Bestehende Einträge bleiben unverändert.

---

## Der `REX_VITE`-Platzhalter

In beliebigen Redaxo-Templates einsetzen:

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

| Form                                                              | Verhalten                                                                                                                                                                                                                                                                                                                                                                   |
| ----------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `REX_VITE`                                                        | Default-Entries: **ein JS- und ein CSS-Entry** (saubere Trennung). Auto-resolved per Struktur, wenn `VITE_ENTRY_POINT` / `VITE_CSS_ENTRY_POINT` in `.env` nicht gesetzt sind. Modern: `src/assets/js/Main.js` + `src/assets/css/style.css`. Classic: `assets/js/Main.js` + `assets/css/style.css`. Theme: `theme/src/assets/js/Main.js` + `theme/src/assets/css/style.css`. |
| `REX_VITE[src="src/assets/js/Main.js"]`                           | Nur dieser eine Entry (überschreibt Defaults).                                                                                                                                                                                                                                                                                                                              |
| `REX_VITE[src="src/assets/js/Main.js\|src/assets/css/style.css"]` | Mehrere Entries, pipe-separiert (Statamic-Konvention).                                                                                                                                                                                                                                                                                                                      |

Pro Vorkommen gibt der Filter in dieser Reihenfolge aus:

1. `<link rel="modulepreload">` und `<link rel="preload">` für Imports, CSS, Fonts und Assets von **JS-Entries** (vollständige Walk durch die Manifest-Graph, Zyklen werden erkannt). CSS-Entries brauchen keinen Preload — der `<link rel="stylesheet">` selbst löst den Fetch aus.
2. `<link rel="stylesheet">` für jeden CSS-Entry und alle co-lokalisierten CSS-Chunks von JS-Entries. **Auch im Dev** — der separate CSS-Entry wird vom Vite-Dev-Server direkt ausgeliefert.
3. `<script type="module" src="<devUrl>/@vite/client">` (nur Dev, einmal pro Vorkommen mit JS-Entries).
4. `<script type="module" src="...">` pro JS-Entry.

**Auto-Insert-Fallback**: Wird in der gerenderten Seite **kein** `REX_VITE` gefunden, fügt der Filter denselben Block unmittelbar vor dem ersten `</head>` ein — mit dem Default-Entry. Damit lässt sich ViteRex ohne Template-Änderung aktivieren.

Der Filter ist **frontend-only** (`rex::isBackend()` verlässt früh).

---

## `.env`-Variablen

```
VITE_DEV_SERVER=http://localhost       # HTTP-Fallback, wenn das Hot-File fehlt
VITE_DEV_SERVER_PORT=5173

VITE_HOST_PROTOCOL=http                # Redaxo-Host (für CORS-Origin im Dev-Server)
VITE_HOST_NAME=localhost               # → cors: { origin: "http://localhost" }; leer = cors: true

# Beide Entries: auto-resolved per Struktur, wenn unset.
# VITE_ENTRY_POINT=src/assets/js/Main.js
# VITE_CSS_ENTRY_POINT=src/assets/css/style.css

# Ordner unter <assetsSourceDir>, die per rollup-plugin-copy in den Build kopiert werden.
# VITE_COPY_DIRS=img,fonts,static      # Default: img

VITE_HTTPS=false                       # HTTPS-Dev-Server aktivieren
# mkcert localhost 127.0.0.1 ::1       # dann diese Zertifikate erzeugen

# VITE_STRUCTURE=modern                # classic | modern | theme (auto)
# VITE_OUTPUT_DIR=/public/dist
# VITE_DIST_URL=/dist
# VITE_ASSETS_SOURCE_DIR=src/assets
```

---

## Struktur-Erkennung

Beim ersten `Structure::detect()` wird die Verzeichnisstruktur ermittelt und in `redaxo/data/addons/viterex/structure.json` gecached. Priorität (höchste zuerst):

1. `VITE_STRUCTURE` in `.env` → `classic` | `modern` | `theme`
2. `theme`-Addon aktiv → `theme` (Build-Output nach `theme/public/dist`)
3. `public/index.php` existiert → `modern` (ydeploy-Konvention)
4. sonst → `classic`

Die Cache-Datei wird erneuert, sobald die `.env`-Modifikationszeit neuer ist als die Cache-Datei. `vite/viterex.js` liest dieselbe Datei, damit die Node-Seite nie eigene Detection-Logik pflegen muss.

---

## Dev-Server-Erkennung

Primär: Existiert `<public>/.hot` (Dotfile), wird dessen Inhalt (die vollständige Dev-URL) als Dev-Server verwendet. Schreibt das `hotfile-plugin.js` beim Start von Vite. Beim Beenden (exit/SIGINT/SIGTERM) löscht das Plugin die Datei.

Fallback: Ist `VITE_DEV_SERVER` in `.env` gesetzt, wird ein HTTP-Probe gegen `<devUrl>/@vite/client` mit 200 ms Timeout durchgeführt. Hilft, wenn Vite unsauber beendet wurde und `.hot` noch fehlt — ansonsten wird sofort auf Produktionsmodus umgeschaltet.

---

## Erweiterungspunkte

| Name              | Subject                      | Verwendung                                                                                     |
| ----------------- | ---------------------------- | ---------------------------------------------------------------------------------------------- |
| `VITEREX_BADGE`   | `array` von HTML-Strings     | Zusätzliche Panels im ViteRex-Badge rendern (z. B. Tailwind-Breakpoint-Indikator).             |
| `VITEREX_PRELOAD` | `array` von `<link>`-Strings | Zusätzliche Preload-Links einfügen (z. B. Webfonts im Dev-Modus). Parameter: `entries`, `dev`. |

---

## Vite-Konfiguration erweitern (für Addon-Entwickler)

Das gescaffoldete `vite/viterex.js` exportiert eine einzige Factory-Funktion:

```js
import { defineViterexConfig } from './vite/viterex.js'
export default defineViterexConfig({
  // userOverrides — wird via Vite's mergeConfig deep-gemerged.
  // Arrays (plugins, build.rollupOptions.input, …) werden konkateniert,
  // Objekte (build, server, css, …) deep-gemerged.
})
```

**Konvention für nachgelagerte Addons** (z. B. `redaxo-massif`): nicht `viterex.js` direkt editieren — stattdessen ein eigenes Helper-File daneben legen, das `defineViterexConfig` umschliesst. Ein Re-Install von viterex überschreibt `viterex.js` nicht (defaults landen als `viterex.js.viterex-default`-Sibling), aber das saubere Wrapping macht klar, wer was beisteuert.

```js
// vite/massif.js — gescaffoldet vom redaxo-massif install.php
import tailwind from '@tailwindcss/vite'
import alpinePlugin from 'vite-plugin-alpine'
import { defineViterexConfig } from './viterex.js'

export function defineMassifConfig(userOverrides = {}) {
  return defineViterexConfig({
    plugins: [tailwind(), alpinePlugin()],
    resolve: {
      alias: [{ find: '~massif', replacement: '/path/to/massif/assets' }]
    },
    ...userOverrides
  })
}
```

Der User passt nur seine `vite.config.js` an (eine Zeile):

```js
// Vorher:
// import { defineViterexConfig } from "./vite/viterex.js";
// export default defineViterexConfig({});

// Nachher:
import { defineMassifConfig } from './vite/massif.js'
export default defineMassifConfig({})
```

Mehrere Addons können sich kettenförmig wrappen — jedes Helper-File importiert das nächste in der Kette und erweitert dessen Config. Da Vite's `mergeConfig` array-Felder konkateniert, addieren sich Plugins und Entries; sie überschreiben sich nicht.

---

## PHP-Helpers für statische Assets

Für statische Assets, die aus PHP-Templates referenziert werden (Background-Images, Logos, inline SVGs, JSON-Fragments), gibt es drei Helfer auf der `Assets`-Klasse. Sie respektieren automatisch dev/prod-Modus und die erkannte Struktur.

```php
use Ynamite\ViteRex\Assets;

// URL für ein bereits existierendes Asset (Browser):
//   dev  → https://localhost:5173/src/assets/img/logo-640w.webp
//   prod → /dist/assets/img/logo-640w.webp
<img src="<?= Assets::url('img/logo-640w.webp') ?>">

// CSS background-image:
<div style="background-image:url(<?= Assets::url('img/hero.jpg') ?>)">…</div>

// Absoluter Filesystem-Pfad (für rex_file::get oder andere PHP-Reads):
//   dev  → <base>/src/assets/img/logo.svg
//   prod → <base>/public/dist/assets/img/logo.svg
$svgPath = Assets::path('img/logo.svg');

// Inline-SVG/JSON/Text (liest die Datei direkt):
<?= Assets::inline('img/icon-arrow.svg') ?>
```

**Wichtig:** Damit Assets in der **Produktion** unter den von `Assets::url()` und `Assets::path()` zurückgegebenen Pfaden existieren, müssen sie beim Build ins `<outDir>/assets/<dir>/` kopiert werden. Das übernimmt `rollup-plugin-copy`, gesteuert über die Env-Variable `VITE_COPY_DIRS` (Default: `img`). Für Fonts oder weitere statische Verzeichnisse:

```env
VITE_COPY_DIRS=img,fonts,static
```

Assets, die per JS oder CSS importiert werden (`import "../img/foo.png?url"` oder `background: url("../img/foo.png")`), behandelt Vite ohnehin automatisch über das Manifest — dafür sind `Assets::url()` und `VITE_COPY_DIRS` nicht nötig.

---

## Das Badge

Bei aktiver Backend-Session auf Nicht-Prod/Nicht-Staging-Umgebungen wird ein Badge am unteren Fensterrand eingeblendet. Es zeigt:

- Stage (`dev` / `staging` / `prod` — aus ydeploy)
- Git-Branch (farblich hervorgehoben, wenn nicht `main`/`master`)
- Vite-Status (Dev-Server läuft? URL?) — Click-to-copy
- Redaxo-Version
- ViteRex-Version
- **Clear cache**-Button (sendet CSRF-geschützten POST an `viterex_clear_cache`)

Andere Addons können via `VITEREX_BADGE` weitere Panels einhängen (siehe Erweiterungspunkte).

---

## Contributing

Das Backend-Badge wird mit Vite gebaut. Wenn du Files unter `viterex-addon/assets-src/` änderst (z. B. `ViteRexBadge.js` oder `.module.css`), musst du die kompilierten Versionen unter `viterex-addon/assets/` neu erzeugen:

```bash
cd viterex-addon
npm install   # einmalig
npm run build
```

Die generierten Dateien landen unter `assets/badge/ViteRexBadge.{js,css,js.map}` (dedizierter Subfolder, damit `emptyOutDir: true` die committeten Helper-Dateien unter `assets/vite/` nicht mitlöscht). Beide Verzeichnisse werden mitcommittet — sie sind Teil des Releases, das via `publish-to-redaxo.yml` zum Redaxo Installer gepusht wird.

---

## Migration from 2.x

- `Assets::get()` wurde entfernt. Templates, die bisher `<?= $assets['js'] ?>` / `$assets['css']` / `$assets['preload']` nutzten, auf `REX_VITE[src="..."]` umstellen — oder den Platzhalter komplett entfernen und den Auto-Insert-Fallback nutzen.
- `Ynamite\ViteRex\Badge` liegt jetzt unter `lib/Badge.php` statt `lib/Badge/Badge.php` (PSR-4-Autoload).
- Mindest-PHP-Version ist jetzt `>=8.1` (vorher 7.3, effektiv aber bereits 8.1 via `composer.json`).
- Statt des HTTP-Probes auf jedem Request erkennt ViteRex den Dev-Server jetzt über die Hot-File. Der alte Probe ist nur noch Fallback (200 ms Timeout, nur wenn `VITE_DEV_SERVER` in `.env` gesetzt ist).

---

## Testing your installation

Diese Checkliste prüft end-to-end, ob das Addon in allen drei Strukturen korrekt funktioniert.

1. **Fresh install (modern):** `composer require ynamite/viterex`, aktivieren. Backend zeigt `rex_view::success` mit den gescaffoldeten Dateien. `redaxo/data/addons/viterex/structure.json` enthält `"structure":"modern"`. `npm install && npm run dev` startet Vite, `public/.hot` erscheint.
2. **Reinstall:** Deaktivieren + reinstallieren → bestehende Dateien unverändert, `*.viterex-default`-Siblings neu. `.gitignore` meldet "already complete".
3. **Dev-Mode (default entries):** Ein `REX_VITE` im Template platzieren (oder ganz weglassen für Auto-Insert). Frontend laden → drei Tags sichtbar: `<link rel="stylesheet" href="…/src/assets/css/style.css">`, `<script ... @vite/client>`, `<script ... src/assets/js/Main.js>`. CSS lädt direkt vom Dev-Server (separater Entry — kein JS-Inject).
4. **Prod-Mode (explizit):** `npm run build` ausführen, Vite beenden (`.hot` verschwindet). Built-Output unter `<frontend>/dist/assets/Main-<hash>.{js,css}`, Manifest unter `<frontend>/dist/.vite/manifest.json`. `REX_VITE[src="src/assets/js/Main.js"]` → liefert modulepreload (inkl. Deep Imports), `<link rel="stylesheet" href="/dist/assets/style-<hash>.css">`, `<script type="module" src="/dist/assets/Main-<hash>.js">`.
5. **Multi-Entry:** `REX_VITE[src="src/assets/js/Main.js|src/assets/js/Admin.js"]` → beide Entries rendern Preload + CSS + JS.
6. **Classic:** Auf Install ohne `public/index.php` und ohne `theme`-Addon → `structure.json` meldet `"classic"`; Build-Output unter `<base>/dist/`.
7. **Classic + Theme:** `theme`-Addon aktivieren → `structure.json` meldet `"theme"`; Build-Output unter `<base>/theme/public/dist/`; Hot-File unter `<base>/theme/public/.hot`.
8. **`.env`-Overrides:** `VITE_STRUCTURE=modern` in einer classic-Installation → erzwingt modern (Cache wird invalidiert, sobald `.env` neuer ist als `structure.json`).
9. **Hot-File vs. HTTP-Fallback:** Vite crasht ohne Cleanup → mit `VITE_DEV_SERVER` in `.env` übernimmt der HTTP-Probe (200 ms). Ohne → sofort Prod-Mode.
10. **HTTPS:** `mkcert localhost 127.0.0.1 ::1` + `VITE_HTTPS=true` → Vite serves HTTPS, Hot-File enthält `https://…`. Zertifikate fehlen → stilles Fallback auf HTTP.
11. **Badge:** Im Frontend und Backend sichtbar. `data-stage` korrekt. Vite-Running-Anzeige wechselt beim Start/Stopp. "Clear cache"-Button POSTet an `viterex_clear_cache`, wird erfolgreich geleert. `VITEREX_BADGE`-Extension-Point rendert Extra-Panels.
12. **Keine Regressionen:** `YREWRITE_SEO_TAGS`-`noindex` bei Nicht-Prod aktiv. Auf classic wird `rex_developer_manager::setBasePath` **nicht** aufgerufen.

---

## Known limitations

- **CSP / Nonces:** Im Dev-Modus emittiert der Filter `<script type="module">`-Tags ohne Nonce. Eine strikte Content-Security-Policy mit `script-src 'self'` blockiert daher HMR. Workaround: CSP im Dev temporär lockern, oder im eigenen `boot.php` einen OUTPUT_FILTER mit `LATE`-Priorität registrieren, der die Tags um das passende Nonce-Attribut erweitert.
- **Multi-Language mit Subpath-Mount:** URLs werden aktuell als Root-absolute Pfade (`/dist/assets/...`) emittiert. Das ist für 99 % der Installationen korrekt; wer Redaxo unterhalb eines Subpfads mountet, sollte `VITE_DIST_URL` explizit setzen.
- **Manifest-Caching:** Das Manifest wird pro Request einmal gelesen. Für sehr hoch frequentierte Produktionsinstallationen lässt sich eigenständiges Caching via `rex_cache` nachrüsten — aktuell genügt die Per-Request-Memoization der `Server`-Singleton.

---

## Issues / Kontakt

Bug-Reports und Feature-Requests bitte auf [GitHub](https://github.com/ynamite/viterex-addon/issues). Änderungen im [CHANGELOG.md](CHANGELOG.md).

## Lizenz

[The MIT License (MIT)](LICENSE.md)

## Credits

- [FriendsOfREDAXO](https://github.com/FriendsOfREDAXO)
- Project Lead: [Yves Torres](https://github.com/ynamite)
