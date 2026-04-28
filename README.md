<img width="1672" height="941" alt="Viterex Addon" src="https://github.com/user-attachments/assets/371153d5-31e9-4670-8dbe-30034e0d9dcb" />

# ViteRex für REDAXO 5 – So lernt der Dino rennen.
 
ViteRex ist ein eigenständiges Redaxo-Addon, das ein modernes Vite-Frontend (Tailwind 4, Live-Reload, Hot-Module-Replacement, JS imports) in **jede** Redaxo-Installation einbringt — egal ob klassische, moderne oder Theme-Addon Ordnerstruktur. Pfade konfigurierst du im Backend; auf Knopfdruck stellt das Addon `package.json`, `vite.config.js`, `uvm.` bereit, inkl. Dev-Tooling-Defaults, Beispiel-Entries sowie `.gitignore`-Einträge in deinem Projekt-Root.

---

## Installation

**Über den Redaxo Installer (empfohlen):** Backend → _AddOns → Installer_, `viterex_addon` suchen, herunterladen, aktivieren.

**Manuell von GitHub:** Repo nach `/src/addons/viterex_addon/` (modern) bzw. `redaxo/src/addons/viterex_addon/` (classic/Theme-Addon) entpacken, im Backend installieren und aktivieren.

Beim Installieren passiert **nichts im Projekt-Root** — das Addon registriert sich nur und seedet `var/data/addons/viterex/structure.json` mit den Default-Pfaden. Konfiguration und Bereitstellung der nötigen Dateien läuft danach über das Backend.

---

## Erste Schritte

1. **Backend → AddOns → ViteRex → Settings** öffnen.
2. Pfade prüfen / anpassen
   — Standardeinstellung sind für die `moderne` Ordnerstruktur (`src/assets/js/main.js`, `public/dist`, `/dist`).
   - Für `klassische` Ordnestruktur: `Public directory` leer lassen, `Build output directory` auf `dist`.
   - Mit `Theme-Addon`: `theme/public` und `theme/public/dist`.
4. Formular speichern (synchronisiert `structure.json`).
5. Auf den Button **Install stubs** klicken. 
   Das Häkchen _Overwrite existing files_ steuert, was mit bereits vorhandenen Dateien passiert — **mit Häkchen** wird vorher ein zeitstempel-Backup angelegt (`<datei>.bak.YYYYmmdd-HHiiss`). Nichts wird stillschweigend überschrieben!
Bereitgestellt werden:
   - `package.json` (Vite 8 + Tailwind 4 + Plugins + Dev-Tooling)
   - `vite.config.js` (minimal, Laravel-style — der Import-Pfad zu `viterex-vite-plugin.js` wird aus deiner `Public directory`-Einstellung generiert)
   - `.env.example`, `.browserslistrc`, `.prettierrc`, `biome.jsonc`, `stylelint.config.js`, `jsconfig.json`
   - `<assets_source_dir>/js/main.js` und `<assets_source_dir>/css/style.css`
6. `.gitignore` wird zeilenweise gescannt — fehlende ViteRex-Einträge (inkl. `.vite-hot`, `.vite-reload-trigger`, mkcert-Certs) werden unter einer `# Added by viterex`-Markierung ergänzt.
7. `npm install && npm run dev` — fertig. Vite startet, `.vite-hot` taucht im Projekt-Root auf, Browser zeigt deine Seite mit HMR.

---

## Der `REX_VITE`-Platzhalter

In beliebigen Redaxo-Templates den `REX_VITE` Platzhalter im `<head>` ergänzen.
*Wichtig:* wird `REX_VITE` in der gerenderten Seite nicht gefunden, fügt der Output-Filter den Block automatisch vor dem ersten `</head>` ein.

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

| Form                                                              | Verhalten                                         |
| ----------------------------------------------------------------- | ------------------------------------------------- |
| `REX_VITE`                                                        | Default-Entries (CSS + JS) aus den Settings. |
| `REX_VITE[src="src/assets/js/main.js"]`                           | Einzelner expliziter Entry.                       |
| `REX_VITE[src="src/assets/css/style.css\|src/assets/js/main.js"]` | Mehrere Entries, pipe-separiert.                  |

Pro Vorkommen werden in dieser Reihenfolge ausgegeben:

1. `<link rel="modulepreload">` und `<link rel="preload">` für Imports/CSS/Fonts/Assets aus dem Manifest (vollständige Walk, Zyklen-erkannt).
2. `<link rel="stylesheet">` für jeden CSS-Entry. **Auch im Dev** (separater Vite-Entry).
3. `<script type="module" src="<devUrl>/@vite/client">` (nur Dev, einmal pro Vorkommen mit JS-Entries).
4. `<script type="module" src="...">` pro JS-Entry.

---

## PHP-Helpers für statische Assets

Für Assets (Dateien), die aus PHP-Templates referenziert werden (Background-Images, Logos, inline SVG):

```php
use Ynamite\ViteRex\Assets;

<img src="<?= Assets::url('img/logo-640w.webp') ?>">

// CSS background-image:
<div style="background-image:url(<?= Assets::url('img/hero.jpg') ?>)">…</div>

// Absoluter Filesystem-Pfad:
$svgPath = Assets::path('img/logo.svg');

// Inline-SVG/JSON/Text:
<?= Assets::inline('img/icon-arrow.svg') ?>
```

Assets, die per JS oder CSS importiert werden (`import "../img/foo.png?url"` oder `background: url("../img/foo.png")`), werden von Vite automatisch verarbeitet (gehasht) und in `manifest.json` eingetragen — keine Extra-Konfiguration nötig.

---

## Settings-Felder

| Feld                        | Default                    | Zweck                                                                                                                |
| --------------------------- | -------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| **JS entry**                | `src/assets/js/main.js`    | Vite JS-Entry-Pfad                                                                                                   |
| **CSS entry**               | `src/assets/css/style.css` | Vite CSS-Entry-Pfad                                                                                                  |
| **Public directory**        | `public`                   | Web-served Verzeichnis. Modern: `public`. Classic: leer. Theme: `theme/public`.                                      |
| **Build output directory**  | `public/dist`              | Vite `outDir`                                                                                                        |
| **Build URL prefix**        | `/dist`                    | URL-Prefix für gebaute Assets                                                                                        |
| **Assets source directory** | `src/assets`               | Wo deine Source-Assets liegen                                                                                        |
| **Assets sub-directory**    | `assets`                   | Vite `build.assetsDir`                                                                                               |
| **Static copy directories** | `img`                      | Komma-separierte Liste — kopiert beim Build von `<assets_source_dir>/<dir>/` nach `<out_dir>/<assets_sub_dir>/<dir>` |
| **Enable HTTPS dev server** | off                        | Aktiviert HTTPS, wenn mkcert-Certs am Projekt-Root liegen                                                            |
| **Live-reload globs**       | siehe nächster Abschnitt   | Ein Glob pro Zeile — Vite triggert Full-Reload bei Änderung passender Files                                          |

---

## Live-Reload

Vite's Browser-Reload wird über `vite-plugin-live-reload` ausgelöst. Default-Globs (Settings → _Live-reload globs_):

```
src/modules/**/*.php
src/templates/**/*.php
src/addons/project/fragments/**/*.php
src/addons/project/lib/**/*.php
src/assets/**/(*.svg|*.png|*.jpg|*.jpeg|*.webp|*.avif|*.gif|*.woff|*.woff2)
.vite-reload-trigger
```

Die ersten fünf Globs decken **direkte Datei-Änderungen** ab (du speicherst eine PHP-Datei in deiner IDE → Reload).

`.vite-reload-trigger` ist ein **Signal-File** für **Backend-getriebene Content-Änderungen**. `boot.php` registriert Handler auf ~30 Redaxo-Extension-Points (`ART_*`, `CAT_*`, `SLICE_*`, `MEDIA_*`, `TEMPLATE_*`, `MODULE_*`, `CLANG_*`, `YFORM_DATA_*`). Vite sieht die Änderung → Reload.

---

## HTTPS Dev-Server

`npm run setup-https` erzeugt mit [mkcert](https://github.com/FiloSottile/mkcert) lokale Zertifikate (`localhost+2-key.pem`, `localhost+2.pem`) am Projekt-Root. Dann in den Settings _Enable HTTPS dev server_ aktivieren — Vite startet beim nächsten `npm run dev` über HTTPS, das Hot-File enthält `https://...`.

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
      input: ['src/admin/main.js'], // zusätzliche Entries (mergeConfig konkateniert)
      refresh: ['src/templates/**/*.php'] // engere Live-Reload-Globs
    })
  ],
  build: { sourcemap: true } // alles hier überschreibt viterex's Defaults
})
```

**Escape Hatch** — komplette Vite-Config selbst schreiben:

```js
viterex({ injectConfig: false }) // nur Hot-File bleibt; alles andere musst du selbst setzen
```

**Downstream-Addons** (z. B. `redaxo-massif`): legen ihren eigenen Helper unter `viterex-addon/assets/<name>-vite-plugin.js` an, der `viterex()` umschliesst:

```js
// redaxo-massif/assets/massif-vite-plugin.js
import vue from '@vitejs/plugin-vue'
import viterex from '../viterex/viterex-vite-plugin.js'

export default function massif(userOptions = {}) {
  return [
    ...viterex({ refresh: false, ...userOptions }), // viterex zuerst
    vue()
  ]
}
```

User wechselt dann in seiner `vite.config.js` einfach den Import + Plugin-Aufruf von `viterex` auf `massif`.

---

## Programmatic API für Downstream-Addons

Andere Redaxo-Addons können auf zwei Wegen ihre eigenen Dateien zusätzlich zu ViteRex' Stubs ins Projekt-Root scaffolden — beide reusen ViteRex' bewährte path-baking + backup-on-overwrite + struktur-bewusste Zielauflösung.

**Direktaufruf** — aus eigener `install.php` oder Settings-Handler:

```php
use Ynamite\ViteRex\StubsInstaller;

$result = StubsInstaller::installFromDir(
    __DIR__ . '/frontend',                  // Source-Dir des Downstream-Addons
    [                                        // Map: source-rel → target-rel-to-base
        'templates/Header/template.php' => '/src/templates/Header/template.php',
        'modules/Swiper/input.php'      => '/src/modules/Swiper/input.php',
        'assets/img/logo.svg'           => '/src/assets/img/logo.svg',
    ],
    overwrite: false,                        // true → Backup-on-overwrite (.bak.<ts>)
    packageDeps: [                           // optional npm-Dep-Merge
        'devDependencies' => ['swiper' => '^12.1.2', 'gsap' => '^3.14.2'],
    ],
);
// Returns: ['written' => [...], 'skipped' => [...], 'backedUp' => [...], 'packageDepsMerged' => 2]

// Optional: Vite-Live-Reload-Globs erweitern (idempotent)
StubsInstaller::appendRefreshGlobs([
    'src/addons/myaddon/fragments/**/*.php',
    'src/addons/myaddon/lib/**/*.php',
]);
```

**Extension-Point-Hook** — aus eigener `boot.php`, registriert sich auf ViteRex' "Install Stubs"-Button-Flow:

```php
rex_extension::register('VITEREX_INSTALL_STUBS', static function (rex_extension_point $ep) {
    $overwrite = $ep->getParam('overwrite', false);
    $myResult = Ynamite\ViteRex\StubsInstaller::installFromDir(
        __DIR__ . '/frontend', $myStubsMap, $overwrite,
    );
    // Eigenes Resultat in subject mergen, damit ViteRex' Settings-Page beide auflistet
    $subject = $ep->getSubject();
    foreach (['written', 'skipped', 'backedUp'] as $k) {
        $subject[$k] = array_merge($subject[$k] ?? [], $myResult[$k] ?? []);
    }
    return $subject;
});
```

Die zwei Wege sind komplementär: der Direktaufruf passt für Auto-Install in `install.php`; der Hook fängt explizite Re-Installs aus ViteRex' eigener UI ab. Ein Addon kann beide nutzen — der Direktaufruf für initiale Auto-Install, der Hook für nachträgliche Re-Scaffolds.

**Konvention für Idempotenz**: das Downstream-Addon trackt selbst, ob es schon scaffolded hat (z. B. via `rex_config('myaddon', 'scaffolded_at')`). `installFromDir` selbst ist nicht idempotent — sie kopiert immer.

---

## Erweiterungspunkte (PHP)

| Name              | Subject                      | Verwendung                                                                          |
| ----------------- | ---------------------------- | ----------------------------------------------------------------------------------- |
| `VITEREX_BADGE`   | `array` von HTML-Strings     | Zusätzliche Panels im ViteRex-Badge rendern (z. B. Tailwind-Breakpoint-Indikator).  |
| `VITEREX_PRELOAD` | `array` von `<link>`-Strings | Custom Preload-Links einfügen (z. B. Webfonts im Dev). Parameter: `entries`, `dev`. |

---

## Das ViteRexBadge

Bei aktiver Backend-Session und Nicht-Prod/Nicht-Staging-Umgebung wird ein Badge unten am Fenster eingeblendet (Frontend + Backend). Zeigt: Stage (`dev`/`staging`/`prod`), Git-Branch, ein farbiger Punkt für den Vite-Status (an = Stage-Farbe + Glow; aus = grau) mit **Tooltip** auf Hover (`Vite @ <url>`), ViteRex- und Redaxo-Version, **Clear cache**-Button (CSRF-geschützter POST). Andere Addons können via `VITEREX_BADGE`-Extension-Point eigene Panels hinzufügen.

---

## Dev-Server-Landing-Page

Beim direkten Aufruf der Vite-Dev-URL (z. B. `https://127.0.0.1:5173`) zeigt der eingebaute `viterex:dev-index`-Plugin (im `viterex-vite-plugin.js`) eine kleine HTML-Seite mit Hinweis "Vite läuft" + Link zur eigentlichen Projekt-URL (aus `host_url` in `structure.json`). Verhindert die irritierende leere Seite, die Vite sonst hier serviert.

---

## block_peek-Integration

Wenn das [`block_peek`](https://github.com/FriendsOfREDAXO/block_peek)-Addon installiert ist, registriert ViteRex einen Handler auf dessen `BLOCK_PEEK_OUTPUT`-EP, der `REX_VITE`-Platzhalter im Block-Preview-Template auflöst. Damit funktioniert HMR + bundled Assets auch in den iframe-Vorschauen im Backend (wo der normale `OUTPUT_FILTER` aus Sicherheitsgründen schweigt).

Die Integration ist konditional — sie aktiviert sich nur, wenn `block_peek` als Addon verfügbar ist. Kein Coupling im Code.

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

## Known limitations

- **CSP / Nonces:** Im Dev emittiert der Output-Filter `<script type="module">` ohne Nonce. Strikte CSP mit `script-src 'self'` blockiert HMR. Workaround: CSP im Dev lockern oder Output-Filter mit `LATE`-Priorität registrieren, der die Tags um Nonce-Attribute erweitert.

---

## Issues / Kontakt

Bug-Reports & Features auf [GitHub](https://github.com/ynamite/viterex_addon/issues). Änderungen im [CHANGELOG.md](CHANGELOG.md).

## Lizenz

[MIT](LICENSE.md)

## Credits

- Project Lead: [Yves Torres](https://github.com/ynamite)
- Inspired by [laravel-vite-plugin](https://github.com/laravel/vite-plugin)
