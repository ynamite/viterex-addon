# ViteRex für REDAXO 5

ViteRex ist ein eigenständiges Redaxo-Addon, das Vite-basierte Frontend-Entwicklung in beliebige Redaxo-Installationen integriert — unabhängig davon, ob das Projekt mit der klassischen Verzeichnisstruktur, ydeploy (modern) oder dem `theme`-Addon arbeitet. Nach der Aktivierung wird im Projekt-Root ein minimales, framework-agnostisches Vite-Setup ausgerollt (`package.json`, `vite.config.js`, `.env.example`, Dev-Tooling wie Biome/Prettier/Stylelint). Ein `OUTPUT_FILTER` ersetzt `REX_VITE`-Platzhalter in Templates durch die passenden `<link>`/`<script>`-Tags — in der Entwicklung zeigt er auf den Vite-Dev-Server, in der Produktion auf die über die Manifest-Datei referenzierten Build-Assets.

> Upgraden von 2.x? Siehe [Migration](#migration-from-2x).

---

## Installation

```bash
composer require ynamite/viterex
```

Anschliessend das Addon in Redaxo aktivieren (Backend → AddOns → ViteRex). Beim Installieren werden die Scaffolding-Dateien in das Projekt-Root kopiert:

| Datei | Zweck |
|---|---|
| `package.json` | Node-Abhängigkeiten (Vite + Dev-Tooling). Keine Framework-Pakete. |
| `vite.config.js` | Thin-Wrapper, der `defineViterexConfig` aus `vite/viterex.js` aufruft. |
| `vite/viterex.js` | Struktur-bewusste Vite-Konfiguration (outDir, Manifest, Hot-File-Plugin, optional HTTPS). |
| `vite/hotfile-plugin.js` | Schreibt `<public>/.hot` mit der Dev-Server-URL. |
| `.env.example` | Dokumentierte `VITE_*`-Variablen. |
| `.browserslistrc`, `.prettierrc`, `biome.json`, `stylelint.config.js`, `jsconfig.json` | Dev-Tooling-Defaults. |
| `src/Main.js`, `src/style.css` | Minimaler Beispiel-Entry, damit `npm run dev` sofort funktioniert. |

Existiert eine Datei bereits im Projekt-Root, wird sie **nicht** überschrieben. Stattdessen wird eine Kopie als `<datei>.viterex-default` angelegt; du kannst die Default-Variante manuell mit deiner Version vergleichen.

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

| Form | Verhalten |
|---|---|
| `REX_VITE` | Default-Entry (`VITE_ENTRY_POINT` in `.env`, Fallback `src/Main.js`). |
| `REX_VITE[src="src/Main.js"]` | Einzelner, expliziter Entry. |
| `REX_VITE[src="src/Main.js\|src/style.css"]` | Mehrere Entries, pipe-separiert (Statamic-Konvention). |

Pro Vorkommen gibt der Filter in dieser Reihenfolge aus:

1. `<link rel="modulepreload">` und `<link rel="preload">` für Imports, CSS, Fonts und Assets (vollständige Walk durch die Manifest-Graph, Zyklen werden erkannt).
2. `<link rel="stylesheet">` für jede CSS-Chunk (nur Produktion — im Dev wird CSS von Vite per JS injiziert).
3. `<script type="module" src="<devUrl>/@vite/client">` (nur Dev, pro Vorkommen; Browser dedupliziert anhand der URL).
4. `<script type="module" src="...">` pro Entry.

**Auto-Insert-Fallback**: Wird in der gerenderten Seite **kein** `REX_VITE` gefunden, fügt der Filter denselben Block unmittelbar vor dem ersten `</head>` ein — mit dem Default-Entry. Damit lässt sich ViteRex ohne Template-Änderung aktivieren.

Der Filter ist **frontend-only** (`rex::isBackend()` verlässt früh).

---

## `.env`-Variablen

```
VITE_DEV_SERVER=http://localhost       # HTTP-Fallback, wenn das Hot-File fehlt
VITE_DEV_SERVER_PORT=5173

VITE_ENTRY_POINT=src/Main.js           # Default-Entry

VITE_HTTPS=false                       # HTTPS-Dev-Server aktivieren
# mkcert localhost 127.0.0.1 ::1       # dann diese Zertifikate erzeugen

# VITE_STRUCTURE=modern                # classic | modern | theme (auto)
# VITE_OUTPUT_DIR=/public/assets/addons/viterex
# VITE_DIST_URL=/assets/addons/viterex
```

---

## Struktur-Erkennung

Beim ersten `Structure::detect()` wird die Verzeichnisstruktur ermittelt und in `redaxo/data/addons/viterex/structure.json` gecached. Priorität (höchste zuerst):

1. `VITE_STRUCTURE` in `.env` → `classic` | `modern` | `theme`
2. `theme`-Addon aktiv → `theme` (Assets nach `theme/public/assets/addons/viterex`)
3. `public/index.php` existiert → `modern` (ydeploy-Konvention)
4. sonst → `classic`

Die Cache-Datei wird erneuert, sobald die `.env`-Modifikationszeit neuer ist als die Cache-Datei. `vite/viterex.js` liest dieselbe Datei, damit die Node-Seite nie eigene Detection-Logik pflegen muss.

---

## Dev-Server-Erkennung

Primär: Existiert `<public>/.hot` (Dotfile), wird dessen Inhalt (die vollständige Dev-URL) als Dev-Server verwendet. Schreibt das `hotfile-plugin.js` beim Start von Vite. Beim Beenden (exit/SIGINT/SIGTERM) löscht das Plugin die Datei.

Fallback: Ist `VITE_DEV_SERVER` in `.env` gesetzt, wird ein HTTP-Probe gegen `<devUrl>/@vite/client` mit 200 ms Timeout durchgeführt. Hilft, wenn Vite unsauber beendet wurde und `.hot` noch fehlt — ansonsten wird sofort auf Produktionsmodus umgeschaltet.

---

## Erweiterungspunkte

| Name | Subject | Verwendung |
|---|---|---|
| `VITEREX_BADGE` | `array` von HTML-Strings | Zusätzliche Panels im ViteRex-Badge rendern (z. B. Tailwind-Breakpoint-Indikator). |
| `VITEREX_PRELOAD` | `array` von `<link>`-Strings | Zusätzliche Preload-Links einfügen (z. B. Webfonts im Dev-Modus). Parameter: `entries`, `dev`. |

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
3. **Dev-Mode (default entry):** Ein `REX_VITE` im Template platzieren (oder ganz weglassen für Auto-Insert). Frontend laden → `<script ... @vite/client>` und `<script ... src/Main.js>` sichtbar; keine `<link rel="stylesheet">` (Vite injiziert CSS im Dev per JS).
4. **Prod-Mode (explizit):** `npm run build` ausführen, Vite beenden (`.hot` verschwindet). `REX_VITE[src="src/Main.js"]` → liefert modulepreload (inkl. Deep Imports), `<link rel="stylesheet">`, `<script type="module">` mit Hashfile.
5. **Multi-Entry:** `REX_VITE[src="src/Main.js|src/Admin.js"]` → beide Entries rendern Preload + CSS + JS.
6. **Classic:** Auf Install ohne `public/index.php` und ohne `theme`-Addon → `structure.json` meldet `"classic"`; Assets unter `<base>/assets/addons/viterex/`.
7. **Classic + Theme:** `theme`-Addon aktivieren → `structure.json` meldet `"theme"`; Assets unter `<base>/theme/public/assets/addons/viterex/`; Hot-File unter `<base>/theme/public/.hot`.
8. **`.env`-Overrides:** `VITE_STRUCTURE=modern` in einer classic-Installation → erzwingt modern (Cache wird invalidiert, sobald `.env` neuer ist als `structure.json`).
9. **Hot-File vs. HTTP-Fallback:** Vite crasht ohne Cleanup → mit `VITE_DEV_SERVER` in `.env` übernimmt der HTTP-Probe (200 ms). Ohne → sofort Prod-Mode.
10. **HTTPS:** `mkcert localhost 127.0.0.1 ::1` + `VITE_HTTPS=true` → Vite serves HTTPS, Hot-File enthält `https://…`. Zertifikate fehlen → stilles Fallback auf HTTP.
11. **Badge:** Im Frontend und Backend sichtbar. `data-stage` korrekt. Vite-Running-Anzeige wechselt beim Start/Stopp. "Clear cache"-Button POSTet an `viterex_clear_cache`, wird erfolgreich geleert. `VITEREX_BADGE`-Extension-Point rendert Extra-Panels.
12. **Keine Regressionen:** `YREWRITE_SEO_TAGS`-`noindex` bei Nicht-Prod aktiv. Auf classic wird `rex_developer_manager::setBasePath` **nicht** aufgerufen.

---

## Known limitations

- **CSP / Nonces:** Im Dev-Modus emittiert der Filter `<script type="module">`-Tags ohne Nonce. Eine strikte Content-Security-Policy mit `script-src 'self'` blockiert daher HMR. Workaround: CSP im Dev temporär lockern, oder im eigenen `boot.php` einen OUTPUT_FILTER mit `LATE`-Priorität registrieren, der die Tags um das passende Nonce-Attribut erweitert.
- **Multi-Language mit Subpath-Mount:** URLs werden aktuell als Root-absolute Pfade (`/assets/addons/viterex/...`) emittiert. Das ist für 99 % der Installationen korrekt; wer Redaxo unterhalb eines Subpfads mountet, sollte `VITE_DIST_URL` explizit setzen.
- **Manifest-Caching:** Das Manifest wird pro Request einmal gelesen. Für sehr hoch frequentierte Produktionsinstallationen lässt sich eigenständiges Caching via `rex_cache` nachrüsten — aktuell genügt die Per-Request-Memoization der `Server`-Singleton.

---

## Issues / Kontakt

Bug-Reports und Feature-Requests bitte auf [GitHub](https://github.com/ynamite/viterex-addon/issues). Änderungen im [CHANGELOG.md](CHANGELOG.md).

## Lizenz

[The MIT License (MIT)](LICENSE.md)

## Credits

- [FriendsOfREDAXO](https://github.com/FriendsOfREDAXO)
- Project Lead: [Yves Torres](https://github.com/ynamite)
