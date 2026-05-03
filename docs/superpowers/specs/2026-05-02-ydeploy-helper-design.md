# ydeploy helper — design spec

**Status:** approved (brainstorming complete) — pending implementation
**Target version:** viterex_addon v3.3
**Date:** 2026-05-02

## Goal

Replace hand-editing of `deploy.php` for the common case: a backend page that lets users edit deployment hosts (and the global repository) through a form, with values persisted to a sidecar PHP file that `deploy.php` reads at deployer runtime. Conditional on `ydeploy` being installed.

## Non-goals

- Editing arbitrary deployer config (`shared_dirs`, `clear_paths`, `bin/php` overrides, custom tasks). Out of scope; users keep editing `deploy.php` for those.
- Per-host callbacks (e.g., the `set('bin/php', function() { return run('which php'); })` chain in real `deploy.php` files). Stays in `deploy.php`.
- Testing the deploy connection from the UI.
- Encrypting / hiding the sidecar contents (matches existing convention: `deploy.php` is committed too).
- Auto-generating multi-host setups from scratch — the helper edits what the installer scaffolded plus user-added hosts of the same shape.

## Architecture

The feature mediates between two files at the project root:

- **`deploy.config.php`** — viterex-owned. Returns an array. Single source of truth for editable settings. Read by `deploy.php` at deployer runtime; written by the backend form.

  ```php
  <?php
  return [
      'repository' => 'git@github.com:user/repo.git',
      'hosts' => [
          [
              'name' => 'stage',
              'hostname' => 'sl56.web.hostpoint.ch',
              'port' => 22,
              'user' => 'benutzer',
              'stage' => 'stage',
              'path' => '~/www/stage.example.com',
          ],
          // …more hosts
      ],
  ];
  ```

- **`deploy.php`** — user-owned _outside_ a marker region. Inside the markers, viterex owns:

  ```php
  // >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>
  $cfg = require __DIR__ . '/deploy.config.php';
  set('repository', $cfg['repository']);
  foreach ($cfg['hosts'] as $h) {
      host($h['name'])
          ->setHostname($h['hostname'])
          ->setRemoteUser($h['user'])
          ->setPort($h['port'])
          ->set('labels', ['stage' => $h['stage']])
          ->setDeployPath($h['path']);
  }
  // <<< VITEREX:DEPLOY_CONFIG <<<
  ```

The PHP backend never executes `deploy.php` (it's CLI-only and will throw outside a deployer context). All extraction is **lexical** via `token_get_all()`; all writes are **textual** (string surgery against the marker region or the recognized prologue+host shape).

Format choice (PHP array, not YAML/JSON): `Symfony\Component\Yaml` is not present in the deployer autoload context (verified against the test install — `deployer/deployer ^7.5` has no Symfony deps and the phar bundles none). PHP returning an array needs no parser, no extra dependency, and no autoload coordination.

The page **appears** as a subpage of the viterex menu only when `rex_addon::get('ydeploy')->isAvailable()`. Two viable mechanisms — choose during implementation: (a) statically register the subpage in `package.yml` and have `pages/deploy.php` render an "ydeploy not installed" notice on absence; (b) register the subpage dynamically in `boot.php` via `rex::getProperty('pages')` manipulation, gated on the addon check. Option (a) is simpler and consistent with how the existing `settings` and `docs` subpages are wired; option (b) hides the menu entry entirely when ydeploy is absent.

## Components

All under namespace `Ynamite\ViteRex\`.

### `lib/Deploy/Sidecar.php`

Static methods for reading and writing `deploy.config.php`:

- `Sidecar::path(): string` — `rex_path::base('deploy.config.php')`.
- `Sidecar::load(): ?array` — `require`s the file, validates shape, returns the array or `null` (missing, non-array, missing required keys, wrong types in `hosts[]`).
- `Sidecar::save(array $cfg): void` — backs up the existing file (if present), writes a new one. Output is deterministic (stable key ordering, no timestamps in the body) so it diffs cleanly.

### `lib/Deploy/DeployFile.php`

Static methods for the surgical operations on `deploy.php`:

- `DeployFile::path(): string` — `rex_path::base('deploy.php')`.
- `DeployFile::extract(string $contents): ?array` — `token_get_all()` walks the file. Returns the same shape as `Sidecar::load()` if it can recognize a prologue (`$deployment* = 'literal';` assignments at top level) and **at least one** `host(literal-or-var)->setHostname(...)->...->setDeployPath(...)` chain. **All recognized host chains are included** in the returned `hosts[]` array (a multi-host file like the formscope example produces multiple entries). Returns `null` if the file doesn't match this shape, including for syntactically invalid input. Never throws.
- `DeployFile::hasMarkers(string $contents): bool` — true iff both the opening and closing sentinel markers exist exactly once each, outside string literals.
- `DeployFile::rewrite(string $contents, ?array $extracted): string` — produces new file contents. If markers are present, replaces the region between them. Otherwise, replaces **only the contiguous span containing all recognized `host(...)` chains** (from the first `host(` to the closing `;` of the last recognized chain) with the marker region. The prologue `$deployment*` assignments and any other user code (requires, custom tasks, `add('shared_dirs', ...)` calls, etc.) are preserved verbatim. (For multi-host files, all recognized chains plus any code between them is replaced as one span; tested with the formscope-style two-host fixture.) The injected marker block ends with `set('repository', $cfg['repository'])` and a `foreach` building hosts; if the user had a pre-existing `set('repository', $deploymentRepository)` line above (using the orphaned prologue var), Deployer's last-write-wins means the marker block's value wins. The injected block is generated from a single fixed template; it always reads from the sidecar via `$cfg`.

### `lib/Deploy/Page.php`

Controller invoked by `pages/deploy.php`. Orchestrates state detection, CSRF-protected POST handling (form save + Activate), and renders status banners. Form is built with `rex_form` against an in-memory model (we're writing to a file, not `rex_config`).

### `pages/deploy.php`

Thin entry point: permission check + `Page::run()`. Same shape as `pages/settings.php`.

### `lang/*.lang`

New strings for labels, notices, banners, error messages.

### Backups

Reuse the `*.bak.YYYYmmdd-HHiiss` convention from `StubsInstaller`. Backup before any write to `deploy.php` _or_ `deploy.config.php`. If the backup write fails, abort the operation.

## Data flows

**Flow A — first visit, sidecar absent**

1. `Page::run()` calls `Sidecar::load()` → `null`.
2. Reads `deploy.php`; calls `DeployFile::extract()` → `array` or `null`.
3. If `array`: writes the sidecar via `Sidecar::save()` (no backup; new file). Banner: _"Sidecar created from current deploy.php values. Click Activate to wire up deploy.php."_ Form pre-populated.
4. If `null`: skips sidecar write. Banner: _"Couldn't auto-detect existing deploy settings. Fill in the form and click Activate to set up the sidecar and rewrite deploy.php."_ Empty form.

**Flow B — form save (sidecar exists)**

1. CSRF check.
2. Validate POST: required fields per host (name, hostname, user, path); port is integer-or-empty; ≥1 host; unique host names.
3. Build the array, backup-then-write the sidecar.
4. Re-render form. Success banner.

Independent of activation state — the sidecar is just data on disk; `deploy.php` doesn't read it until deployer runs.

**Flow C — Activate**

1. CSRF check. Confirm sidecar exists (else error).
2. Read `deploy.php`. If `DeployFile::hasMarkers()` → bail with info banner.
3. Else: backup `deploy.php`, then write `DeployFile::rewrite()`.
4. Success banner with backup filename.

**Deliberate non-flows**

- **No re-extraction after Flow A.** Once the sidecar exists, it's the source of truth. If the user edits `deploy.php`'s prologue between Flow A and Flow C, those edits are ignored — the form already shows the extracted values; user corrects in the form. Re-extracting would risk clobbering form-side edits.
- **No write-through to `deploy.php` on form save.** Once activated, `deploy.php` doesn't need re-touching for value changes; the foreach loop reads the sidecar at deployer runtime.

## Error handling

**Filesystem**
- `deploy.php` missing → page shows error: _"deploy.php not found at project root."_ No form.
- Sidecar / parent not writable → surface the OS error from `rex_file::put()`'s return; no partial write.
- Backup write failure → abort the whole save (don't proceed without a working backup).
- `deploy.php` not writable on Activate → surface error; file untouched.

**Extraction**
- `DeployFile::extract()` returns `null` whenever it can't confidently recognize the shape (no prologue, no host chain, host chain references unresolvable variables, file is syntactically invalid). Page treats this as "couldn't auto-detect" — not an error; renders empty form. Never throws.

**Marker tampering**
- `DeployFile::hasMarkers()` returns `false` for partial/duplicate markers (treated as "needs activation"). On Activate, `rewrite()` re-checks: if the file has _partial_ markers it falls through to the no-markers path, then can't find the original prologue+host shape (because a previous activation replaced it), so `rewrite()` returns the unchanged contents. `Page` shows: _"Couldn't safely rewrite deploy.php — the viterex marker block appears tampered. Restore from a backup or remove the partial markers and try again."_

**Validation**
- Per-field errors render inline (standard `rex_form` pattern). Form not saved if any error. CSRF failure → standard Redaxo CSRF error.

**Concurrency**
- Two admins saving at the same time: last write wins. No locking. Acceptable for a settings page.

**Explicitly not done**
- No automatic restore from backup. Backups exist for the user to manually inspect/restore via shell.
- No "test deploy connection" feature. Out of scope.
- No syntax-checking of generated `deploy.php`. The injected block comes from a fixed, tested template.

## Testing

### Unit — `tests/Deploy/DeployFileTest.php`

The highest-risk surface; the parser/rewriter is where this feature can silently corrupt user files.

- `extract()` against fixtures:
  - test-install scaffold (single host, with `$isGit` branch below)
  - formscope-style two-host file (one inline `host('stage')` + one hardcoded `host('prod')`)
  - file with no `host()` call at all → `null`
  - file with prologue but unrecognized host chain → `null`
  - syntactically invalid file → `null`
- `hasMarkers()` against fixtures: no markers, both present, only opening, only closing, duplicate openings, marker text inside a string literal (must NOT count).
- `rewrite()` round-trip: extract → save sidecar → rewrite → re-parse and assert the marker region is correct, user code below is byte-identical, second `rewrite()` on the result is a no-op (idempotency).
- Edge: Windows line endings, leading BOM, `<?php` on its own line vs. inline opening tag.

### Unit — `tests/Deploy/SidecarTest.php`

- Round-trip a representative array through `save()` → `load()` → deep equality.
- `save()` output is stable across runs (deterministic key ordering, no timestamps in the body).
- `load()` returns `null` for: missing file, non-array return, array missing required keys, array with wrong types in `hosts[]`.

### Integration — `tests/Deploy/PageTest.php` (smoke)

Mock filesystem (write fixtures into a temp dir, point `rex_path::base()` at it via the existing test harness pattern from `OutputFilterTest.php`). Drive the three flows end-to-end. Assert: backup files created with expected naming, sidecar contents match form input, `deploy.php` post-Activate matches the expected rewritten fixture byte-for-byte.

### Test fixtures

Live at `tests/fixtures/deploy/` — committed `deploy.php` shapes (anonymized). The test-install scaffold is the primary one; add 2–3 more variants as the parser meets new inputs.

### Manual verification

After activation in `/Users/yvestorres/Herd/viterex-installer-default/`, run a deployer dry-run command (`bin/dep deploy:print_config viterex-installer-default` or similar) and confirm it reports the same hosts/repository as the sidecar. Document this step in the implementation plan.

## File layout

```
lib/Deploy/
  Sidecar.php
  DeployFile.php
  Page.php
pages/
  deploy.php
lang/
  de_de.lang   # +ydeploy strings
  en_gb.lang   # +ydeploy strings
tests/Deploy/
  SidecarTest.php
  DeployFileTest.php
  PageTest.php
tests/fixtures/deploy/
  scaffold-single-host.php
  multi-host.php
  prologue-only.php
  no-host-call.php
  with-markers.php
  tampered-markers.php
```

`package.yml` gets a new conditional subpage entry; `boot.php` is unchanged (no extension-point work needed for this feature).

## Conventions inherited from the existing addon

- Backup format: `*.bak.YYYYmmdd-HHiiss`, same as `StubsInstaller`.
- CSRF: `rex_csrf_token::factory('viterex_deploy')` for this page (matches the `viterex_settings` token used by settings).
- Permissions: `viterex[]` perm, admin gate at the top of `pages/deploy.php`.
- Subpage gating: register only when `rex_addon::get('ydeploy')->isAvailable()`.
- i18n: all user-facing strings via `rex_i18n::msg()`; `lang/*.lang` keys prefixed `viterex_deploy_`.

## Open implementation choices (not blocking the spec)

These are decisions to make during implementation, not now:

- Whether `Sidecar::save()` writes via a hand-rolled formatter or `var_export()` + cleanup. (Output stability is the requirement; formatter style is mechanical.)
- Whether `DeployFile::extract()` walks tokens once with a small state machine, or runs two passes (prologue, then host chain). State machine is probably cleaner for the `host(…)->setHostname(…)…` chain detection.
- Whether the Activate confirmation is a separate POST or a checkbox + submit on the form save. Either works; one-button is fewer UI elements.

## Things to be careful about during implementation

- Don't `require_once` the sidecar (its `return` value is what matters — must be re-readable on every load).
- The injected `foreach` loop assumes `port` is non-null; the form must default empty input to a numeric default (probably `22`) or the loop must handle `null` (ydeploy/deployer accept `setPort(null)` as "default").
- Ensure `Page::run()` checks `rex_addon::get('ydeploy')->isAvailable()` defensively — even though the page is gated at registration time, a leftover URL shouldn't crash on a stale page registration.
- See "Architecture" for the static-vs-dynamic subpage registration choice; either is acceptable, but pick one and document it in the implementation plan.
- After activation, the `$deployment*` prologue vars in `deploy.php` become orphaned dead code (harmless; Deployer doesn't use them). The user can delete them manually. We deliberately don't auto-remove them because doing so would require a second separately-located byte range and complicate the parser; the dead code is worth the simplicity.
