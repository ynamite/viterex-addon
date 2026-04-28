# Vite+ Evaluation für ViteRex

**Stand:** 2026-04-28 · **Status:** Recherche, keine Migration empfohlen · **Autor:** ynamite

Diese Notiz beantwortet die Frage: *Was würde es kosten, Vite+ in `viterex_addon` zu integrieren, und sollten wir es tun?*

---

## 1. Was ist Vite+?

Vite+ ist ein **Toolchain-Wrapper** der Firma [VoidZero Inc.](https://voidzero.dev/) (gegründet 2024 von Evan You, dem Erfinder von Vite und Vue). Veröffentlicht 2025, MIT-Lizenz, kostenloses Open Source.

Vite+ bündelt mehrere Tools unter einer einzigen CLI:

| Tool | Funktion |
| --- | --- |
| **Vite** | Dev-Server / Build (unverändert) |
| **Vitest** | Test-Runner |
| **Oxlint** | Linting (Rust-basierter ESLint-Ersatz) |
| **Oxfmt** | Code-Formatting (Rust-basiert) |
| **Rolldown** | Optionaler Bundler (Rust-basierter Rollup-Ersatz) |
| **Vite Task** | Task-Runner |
| **Vite env** | Node-Versions-Management |

Statt `npm run dev`, `npm run test`, `npm run lint` läuft alles über die CLI `vp`:

```bash
vp dev      # Vite-Dev-Server
vp build    # Production-Build
vp test     # Vitest
vp check    # Lint + Format
vp run      # Beliebige Tasks
vp create   # Projekt-Scaffolding
vp migrate  # Migration aus klassischen Setups
```

**Wichtig:** Vite+ ist **kein Fork** von Vite und **kein Drop-in-Replacement** auf Package-Ebene — es ist ein neuer CLI-Entry-Point, der intern Vite weiternutzt. Die `vite.config.ts` bleibt das zentrale Konfigurations-File, kann aber zusätzlich Test-/Lint-/Format-Sektionen enthalten.

**Quellen:** [viteplus.dev](https://viteplus.dev/), [viteplus.dev/guide/dev](https://viteplus.dev/guide/dev), [github.com/voidzero-dev/vite-plus](https://github.com/voidzero-dev/vite-plus)

---

## 2. Was bringt Vite+ konkret gegenüber Vite 8?

- **Konsolidierte Konfiguration**: Eine `vite.config.ts` für Build, Test, Lint und Format — statt vier Config-Dateien (`vite.config.js`, `vitest.config.ts`, `.eslintrc.js`, `.prettierrc`).
- **Schnellere Tools**: Oxlint und Oxfmt (Rust-basiert) sind 50–100× schneller als ESLint/Prettier.
- **Automatisches Node-Management**: `vp env` installiert die im Projekt geforderte Node-Version (ähnlich wie `nvm` oder `volta`, aber integriert).
- **Einheitlicher CLI-Entry**: Ein Befehl statt diverse `npm run`-Scripts.
- **Project-Scaffolding** und **Migration-Tools**: `vp create` für neue Projekte, `vp migrate` für die Übernahme aus klassischen Setups.
- **Optional Rolldown statt Rollup** als Bundler (höhere Build-Geschwindigkeit, noch experimentell).

Der **Wert für ViteRex** kommt also primär aus Test/Lint/Format-Bereichen — die heute kein Bestandteil von ViteRex sind.

---

## 3. Auswirkung auf `viterex_addon`

Risiko-Matrix der einzelnen Komponenten:

| Komponente | Vite+-Kompatibilität | Anmerkung |
| --- | --- | --- |
| `assets/viterex-vite-plugin.js` (`config()`-Hook, `configureServer()`, `apply: "serve"`) | **wahrscheinlich kompatibel** | Vite+-Doku spricht von „standard Vite configuration" — die Plugin-API bleibt also unverändert. Allerdings nicht explizit dokumentiert; testen vor Verkündung. |
| `vite-plugin-static-copy` | **wahrscheinlich kompatibel** | Reines Vite-Plugin, keine spezielle CLI-Bindung. |
| `vite-plugin-live-reload` | **wahrscheinlich kompatibel** | Dito. |
| `@tailwindcss/vite` + Lightning CSS | **wahrscheinlich kompatibel** | Tailwind 4 nutzt Lightning CSS intern; Vite+ verändert die CSS-Pipeline laut Doku nicht. |
| Hot-File-Mechanismus (`.vite-hot`) | **nicht beeinflusst** | Side-Effect des `configureServer`-Hooks — CLI-unabhängig. |
| `structure.json`-Bridge zwischen PHP und Node | **nicht beeinflusst** | Reines Filesystem-Lesen im Plugin. |
| Stub `package.json` Scripts (`vite`, `vite build`) | **muss angepasst werden** *(falls Migration)* | `vite` → `vp dev`, `vite build` → `vp build`. Beide Varianten könnten parallel angeboten werden. |
| Stub `vite.config.js` | **wahrscheinlich unverändert** | Optional Umbenennung zu `.ts`, wenn man TypeScript-Vorteile nutzen will. |
| `npm run setup-https` (mkcert) | **nicht beeinflusst** | mkcert ist CLI-extern. |

---

## 4. Migrationsaufwand-Schätzung

**Falls Plugin-API kompatibel** (wahrscheinlich):

- Stub `package.json` Scripts auf `vp dev` / `vp build` umstellen — oder beides parallel anbieten (z.B. `dev` und `dev:vp`).
- Stub `vite.config.js` ggf. zu `vite.config.ts` migrieren (optional, abhängig davon, ob wir Test/Lint/Format-Sektionen integrieren wollen).
- README-Sektion „Optionale Vite+-Nutzung" ergänzen.
- Smoke-Test gegen einen Test-Build.

→ **2–4 Stunden Aufwand**.

**Falls Plugin-API inkompatibel** (unwahrscheinlich, aber Doku-Lücke):

- Plugin-Code auf veränderte Hooks portieren.
- Möglicherweise neuer Plugin-Eintrittspunkt für Vite+-spezifische Features.

→ **1–2 Tage Aufwand** je nach Tiefe der Inkompatibilität.

---

## 5. Risiken und offene Fragen

- **Doku-Lücke**: Vite+-Docs adressieren explizit weder `config()`-Hooks noch `configureServer()`-Middleware in Custom-Plugins. „Standard Vite configuration" ist wörtlich genommen ein gutes Zeichen, aber kein verbindlicher Vertrag. Wir wissen erst nach einem echten Test-Run sicher, ob alles funktioniert.
- **Reife**: Vite+ ist neu (VoidZero-Launch 2025). Längerfristige Stabilität, Breaking-Changes-Frequenz und Community-Adoption sind offen.
- **Coupling-Konflikt**: Nutzer mit eigener CLI-Toolchain (Turborepo, custom Build-Scripts) erleben `vp` als zusätzliche, ungewollte Schicht.
- **Dokumentations-Schmerz**: Bestehende Nutzer müssten lernen, dass `npm run dev` jetzt intern `vp dev` aufruft — und Fehlermeldungen in Issues kommen unter neuem Tool-Namen.
- **TypeScript-Defaults**: Vite+ pusht `vite.config.ts` als Standard. ViteRex' Stubs sind heute `.js`. Eine Umstellung wäre eine Stub-Breaking-Change.

---

## 6. Empfehlung

**Kurzfristig (jetzt): keine Migration.**

Begründung:

- Vite 8 ist stabil, ausgereift und für ViteRex' Use-Case (dünner Vite-Layer für Redaxo) ausreichend.
- Der Mehrwert von Vite+ liegt v.a. in Vitest/Oxlint/Oxfmt-Integration. ViteRex ist aber **kein Test-/Lint-Hub**, sondern ein dünnes Brücken-Plugin. Dieser Mehrwert kommt also primär dem **Endnutzer** zugute, nicht dem Addon selbst.
- Endnutzer können Vite+ heute schon **selbst** integrieren, wenn sie wollen — sie müssen nur ihre `package.json` Scripts auf `vp` umstellen. ViteRex steht dem nicht im Weg.

**Mittelfristig (3–6 Monate): beobachten.**

- Wenn Vite+ in der Vite-Community traction gewinnt, Stabilität beweist und Redaxo-Nutzer die Integration nachfragen, dann:
  - Optionalen Stub-Schalter anbieten: `vite.config.js` (Vite 8) vs. `vite.config.ts` (Vite+-Annotations).
  - README-Sektion ergänzen, die zeigt, wie man Vite+ on-top installiert.
  - Plugin-Code bleibt CLI-agnostisch — Nutzer wählen frei.

**Langfristig: Plugin-Code CLI-agnostisch halten.**

Egal ob `vite` oder `vp` der CLI-Entry ist: das Plugin nutzt nur die Vite-Plugin-API. Solange diese stabil bleibt (was bei Vite über mehrere Major-Versionen hinweg gelang), entsteht kein Druck zur Migration.

---

## 7. Test-Plan, falls Validierung gewünscht

Falls wir die „wahrscheinlich kompatibel"-Aussagen zu „bestätigt kompatibel" upgraden wollen:

1. **Frischer Redaxo-Test-Build** (modern-Struktur, z.B. `~/Herd/primobau/`).
2. **Stubs installieren** über das Backend.
3. **`package.json` editieren** in der Test-Installation: `"dev": "vite"` → `"dev": "vp dev"`, `"build": "vite build"` → `"build": "vp build"`.
4. **`npm install vite-plus -D`** zum Installieren der CLI.
5. **`npm run dev` starten** — Erwartung:
   - `.vite-hot`-Datei entsteht im Projekt-Root.
   - Console-Output zeigt Vite-Dev-Server-URL.
   - HMR funktioniert (Test: Tailwind-Klasse in `style.css` ändern → Browser updated ohne Reload).
   - Live-Reload funktioniert (Test: PHP-Datei in `src/templates/` ändern → Browser-Reload).
6. **Build-Test**: `npm run build` — Erwartung:
   - `manifest.json` korrekt geschrieben.
   - Assets in `out_dir` mit erwarteten Hashes.
   - Static-Copy-Verzeichnisse (`img/`) korrekt mirrored.
7. **Backend-Integration**: Frontend im Browser laden — Erwartung:
   - `REX_VITE`-Platzhalter wird zu Dev-Tags ersetzt (HMR-Client + Module-Scripts).
   - Badge zeigt Vite-running mit korrekter URL.
8. **Block-Peek-Test** (falls block_peek installiert): Block-Vorschau im Backend — Erwartung: HMR funktioniert auch im iframe.
9. **Bei Erfolg**: kurze Notiz in dieser Doku ergänzen („Validiert mit Vite+ X.Y.Z am DATE") und ggf. README-Sektion „Optionale Vite+-Nutzung" hinzufügen.
10. **Bei Misserfolg**: konkrete Fehlerstelle in einem Issue dokumentieren — Vite+-Doku-Lücke identifiziert.

---

## 8. Fazit

Vite+ ist ein interessantes Stück Tooling, das mittelfristig die Vite-Welt prägen könnte — aber **für ViteRex bringt es heute keinen substanziellen Mehrwert**. Der Fokus von Vite+ liegt auf Bereichen (Test, Lint, Format), die ViteRex bewusst ausspart. Gleichzeitig ist die Plugin-API-Kompatibilität nicht offiziell garantiert.

**Aktion: nichts tun, beobachten, in 3–6 Monaten neu evaluieren.**
