# ydeploy helper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a backend page that lets users edit deployment hosts via a form, persisted to a sidecar PHP file (`deploy.config.php`) that the user's `deploy.php` reads at deployer runtime.

**Architecture:** Two-file model. `deploy.config.php` is viterex-owned (PHP returning an array). `deploy.php` is user-owned outside a marker region; inside the markers viterex injects a tiny block that requires the sidecar and builds Deployer hosts in a `foreach` loop. All extraction is lexical (`token_get_all`); all writes are textual (string surgery against markers or the recognized prologue+host shape). Page is a static subpage of the viterex menu.

**Tech Stack:** PHP 8.1+, PHPUnit 10.5, Redaxo 5.13+, Deployer 7.5 (consumer). No new Composer deps.

**Spec:** `docs/superpowers/specs/2026-05-02-ydeploy-helper-design.md`

**Implementation choices made up-front (from spec's "Open implementation choices" section):**
- **Subpage registration:** option (a) — static entry in `package.yml`; `pages/deploy.php` shows an "ydeploy not installed" notice when the addon is absent. Simpler, mirrors existing `settings`/`docs` subpages.
- **`Sidecar::save()` formatter:** hand-rolled string-builder. `var_export()` produces awkward indented output; a small dedicated formatter gives exact, stable diffs.
- **`DeployFile::extract()` token walker:** single-pass with a small state machine. Cleaner than two passes for the host-chain detection.
- **Activate confirmation:** separate POST (its own `<form>` + button), not a checkbox on the form save. The form save and Activate are conceptually different (data write vs. file rewrite); keeping them separate makes accidental Activate impossible.

**API testability adjustment:** the spec describes `Sidecar::load(): ?array` and `Sidecar::save(array): void` as no-arg static methods using `rex_path::base()` internally. Implementation adds an optional `?string $path = null` parameter to each — `null` means "use `Sidecar::path()`", non-null overrides for testability. Same for `DeployFile`. Public callers that don't pass a path get the spec's behavior; tests pass tmp paths.

---

## File Structure

**New files:**

| Path | Responsibility |
|---|---|
| `lib/Deploy/Sidecar.php` | Read/write `deploy.config.php`. Static methods. Pure I/O + shape validation. |
| `lib/Deploy/DeployFile.php` | Lexical extract/marker-detect/rewrite of `deploy.php`. Static methods on string contents (no I/O — caller does the I/O). |
| `lib/Deploy/Page.php` | Pure helpers for the page controller: state detection, POST validation. Redaxo-agnostic so it's unit-testable. |
| `pages/deploy.php` | Thin Redaxo entry point: permission check, ydeploy availability check, CSRF handling, calls into `Page` helpers, renders form + banners. |
| `tests/Deploy/SidecarTest.php` | Unit tests for `Sidecar`. |
| `tests/Deploy/DeployFileTest.php` | Unit tests for `DeployFile`. |
| `tests/Deploy/PageTest.php` | Unit tests for `Page` helpers (state + validator). |
| `tests/fixtures/deploy/single-host.php` | Minimal scaffold: prologue + one host chain. |
| `tests/fixtures/deploy/multi-host.php` | Two host chains (mix of var- and literal-driven). |
| `tests/fixtures/deploy/prologue-no-host.php` | Prologue assignments but no `host()` call. |
| `tests/fixtures/deploy/bare-php.php` | Only `<?php\n` — no recognizable content. |
| `tests/fixtures/deploy/with-markers.php` | Already-activated file (marker region present). |
| `tests/fixtures/deploy/tampered-opening-only.php` | Partial markers (opening only). |
| `tests/fixtures/deploy/unrecognized-host-chain.php` | Prologue + `host()` call but uses unrecognized method shape (e.g., `->set('hostname', ...)` instead of `->setHostname(...)`). |

**Modified files:**

| Path | Change |
|---|---|
| `package.yml` | Bump version `3.2.5` → `3.3.0`. Add `deploy:` subpage entry under `page.subpages`. |
| `lang/de_de.lang` | Add `viterex_deploy_*` strings. |
| `lang/en_gb.lang` | Add `viterex_deploy_*` strings. |
| `CHANGELOG.md` | New `3.3.0` section. |
| `README.md` | New "ydeploy helper" section. |
| `CLAUDE.md` | Update "Roadmap" — strike v3.3 ydeploy item, leave SVG opt as the remaining v3.3 item. |

**Test layout:** PHPUnit 10.5, bootstrap `vendor/autoload.php`, namespace `Ynamite\ViteRex\Tests\Deploy\`. Existing `tests/OutputFilterTest.php` is the reference for "pure unit, no Redaxo runtime" — the same approach applies here. `Page` tests cover only the pure helpers (state detection + validation); the Redaxo-bound parts of `pages/deploy.php` are covered by manual verification in the test install.

---

## Task 1: Project setup and test fixtures

**Files:**
- Create: `lib/Deploy/.gitkeep` (placeholder; deleted by Task 2)
- Create: `tests/Deploy/.gitkeep` (placeholder; deleted by Task 3)
- Create: `tests/fixtures/deploy/single-host.php`
- Create: `tests/fixtures/deploy/multi-host.php`
- Create: `tests/fixtures/deploy/prologue-no-host.php`
- Create: `tests/fixtures/deploy/bare-php.php`
- Create: `tests/fixtures/deploy/with-markers.php`
- Create: `tests/fixtures/deploy/tampered-opening-only.php`
- Create: `tests/fixtures/deploy/unrecognized-host-chain.php`

- [ ] **Step 1: Create the new directories**

```bash
mkdir -p lib/Deploy tests/Deploy tests/fixtures/deploy
```

- [ ] **Step 2: Write fixture — `single-host.php`**

Create `tests/fixtures/deploy/single-host.php` with the following exact contents:

```php
<?php

namespace Deployer;

if ('cli' !== PHP_SAPI) {
    throw new \Exception('CLI only.');
}

$deploymentName = 'staging';
$deploymentHost = 'example.com';
$deploymentPort = '22';
$deploymentUser = 'webuser';
$deploymentType = 'stage';
$deploymentPath = '/var/www/staging';
$deploymentRepository = 'git@github.com:user/repo.git';

require __DIR__ . '/src/addons/ydeploy/deploy.php';

set('repository', $deploymentRepository);

host($deploymentName)
    ->setHostname($deploymentHost)
    ->setRemoteUser($deploymentUser)
    ->setPort($deploymentPort)
    ->set('labels', ['stage' => $deploymentType])
    ->setDeployPath($deploymentPath);

// custom user code below — must survive any rewrite
task('custom:hello', static function () {
    info('hello');
});
```

- [ ] **Step 3: Write fixture — `multi-host.php`**

Create `tests/fixtures/deploy/multi-host.php`:

```php
<?php

namespace Deployer;

$deploymentName = 'stage';
$deploymentHost = 'shared.example.com';
$deploymentPort = '22';
$deploymentUser = 'shareduser';
$deploymentType = 'stage';
$deploymentPath = '/var/www/stage';
$deploymentRepository = 'git@github.com:user/repo.git';

set('repository', $deploymentRepository);

host($deploymentName)
    ->setHostname($deploymentHost)
    ->setRemoteUser($deploymentUser)
    ->setPort($deploymentPort)
    ->set('labels', ['stage' => $deploymentType])
    ->setDeployPath($deploymentPath);

host('prod')
    ->setHostname('shared.example.com')
    ->setRemoteUser('shareduser')
    ->setPort(22)
    ->set('labels', ['stage' => 'prod'])
    ->setDeployPath('/var/www/prod');
```

- [ ] **Step 4: Write fixture — `prologue-no-host.php`**

```php
<?php

namespace Deployer;

$deploymentName = 'staging';
$deploymentHost = 'example.com';
$deploymentPort = '22';
$deploymentUser = 'webuser';
$deploymentType = 'stage';
$deploymentPath = '/var/www/staging';
$deploymentRepository = 'git@github.com:user/repo.git';

// no host() call
```

- [ ] **Step 5: Write fixture — `bare-php.php`**

```php
<?php
```

- [ ] **Step 6: Write fixture — `with-markers.php`**

```php
<?php

namespace Deployer;

require __DIR__ . '/src/addons/ydeploy/deploy.php';

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

task('custom:hello', static function () {
    info('hello');
});
```

- [ ] **Step 7: Write fixture — `tampered-opening-only.php`**

```php
<?php

namespace Deployer;

// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>
$cfg = require __DIR__ . '/deploy.config.php';
set('repository', $cfg['repository']);
// (closing marker missing — user deleted it)

host('x')->setHostname('x')->setRemoteUser('x')->setPort(22)
    ->set('labels', ['stage' => 'x'])->setDeployPath('/x');
```

- [ ] **Step 8: Write fixture — `unrecognized-host-chain.php`**

```php
<?php

namespace Deployer;

$deploymentName = 'staging';
$deploymentHost = 'example.com';
$deploymentPort = '22';
$deploymentUser = 'webuser';
$deploymentType = 'stage';
$deploymentPath = '/var/www/staging';
$deploymentRepository = 'git@github.com:user/repo.git';

set('repository', $deploymentRepository);

// uses ->set('hostname', ...) instead of ->setHostname(...) — unrecognized
host($deploymentName)
    ->set('hostname', $deploymentHost)
    ->set('remote_user', $deploymentUser)
    ->set('port', $deploymentPort)
    ->set('labels', ['stage' => $deploymentType])
    ->set('deploy_path', $deploymentPath);
```

- [ ] **Step 9: Commit**

```bash
git add lib/Deploy tests/Deploy tests/fixtures/deploy
git commit -m "feat(deploy): add test fixtures for ydeploy helper"
```

---

## Task 2: `Sidecar` — load and path

**Files:**
- Create: `lib/Deploy/Sidecar.php`
- Create: `tests/Deploy/SidecarTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Deploy/SidecarTest.php`:

```php
<?php

namespace Ynamite\ViteRex\Tests\Deploy;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Deploy\Sidecar;

final class SidecarTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/viterex-sidecar-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    private function tmpPath(string $name = 'deploy.config.php'): string
    {
        return $this->tmpDir . '/' . $name;
    }

    public function testLoadReturnsNullWhenFileMissing(): void
    {
        $this->assertNull(Sidecar::load($this->tmpPath()));
    }

    public function testLoadReturnsNullWhenFileReturnsNonArray(): void
    {
        file_put_contents($this->tmpPath(), "<?php return 'not an array';");
        $this->assertNull(Sidecar::load($this->tmpPath()));
    }

    public function testLoadReturnsNullWhenRepositoryMissing(): void
    {
        file_put_contents($this->tmpPath(), "<?php return ['hosts' => []];");
        $this->assertNull(Sidecar::load($this->tmpPath()));
    }

    public function testLoadReturnsNullWhenHostsMissing(): void
    {
        file_put_contents($this->tmpPath(), "<?php return ['repository' => 'r'];");
        $this->assertNull(Sidecar::load($this->tmpPath()));
    }

    public function testLoadReturnsNullWhenHostMissingRequiredKey(): void
    {
        file_put_contents($this->tmpPath(), "<?php return ['repository' => 'r', 'hosts' => [['name' => 's']]];");
        $this->assertNull(Sidecar::load($this->tmpPath()));
    }

    public function testLoadReturnsNullWhenHostKeyHasWrongType(): void
    {
        $contents = "<?php return ['repository' => 'r', 'hosts' => [["
            . "'name' => 's', 'hostname' => 'h', 'port' => 'twenty-two',"
            . "'user' => 'u', 'stage' => 's', 'path' => '/p'"
            . "]]];";
        file_put_contents($this->tmpPath(), $contents);
        // port must be int|null, not string
        $this->assertNull(Sidecar::load($this->tmpPath()));
    }

    public function testLoadReturnsArrayForValidFile(): void
    {
        $contents = "<?php return ['repository' => 'git@example.com:u/r.git', 'hosts' => [["
            . "'name' => 'stage', 'hostname' => 'h.example.com', 'port' => 22,"
            . "'user' => 'u', 'stage' => 'stage', 'path' => '/var/www/s'"
            . "]]];";
        file_put_contents($this->tmpPath(), $contents);

        $cfg = Sidecar::load($this->tmpPath());

        $this->assertSame('git@example.com:u/r.git', $cfg['repository']);
        $this->assertCount(1, $cfg['hosts']);
        $this->assertSame('stage', $cfg['hosts'][0]['name']);
    }

    public function testLoadAcceptsNullPort(): void
    {
        $contents = "<?php return ['repository' => 'r', 'hosts' => [["
            . "'name' => 's', 'hostname' => 'h', 'port' => null,"
            . "'user' => 'u', 'stage' => 's', 'path' => '/p'"
            . "]]];";
        file_put_contents($this->tmpPath(), $contents);

        $cfg = Sidecar::load($this->tmpPath());
        $this->assertNull($cfg['hosts'][0]['port']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Deploy/SidecarTest.php
```

Expected: errors about `Ynamite\ViteRex\Deploy\Sidecar` not found.

- [ ] **Step 3: Implement `Sidecar`**

Create `lib/Deploy/Sidecar.php`:

```php
<?php

namespace Ynamite\ViteRex\Deploy;

use rex_path;

final class Sidecar
{
    private const REQUIRED_HOST_KEYS = ['name', 'hostname', 'port', 'user', 'stage', 'path'];

    public static function path(): string
    {
        return rex_path::base('deploy.config.php');
    }

    /**
     * @return array{repository: string, hosts: list<array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}>}|null
     */
    public static function load(?string $path = null): ?array
    {
        $path ??= self::path();
        if (!is_file($path)) {
            return null;
        }
        $cfg = @include $path;
        if (!is_array($cfg)) {
            return null;
        }
        if (!isset($cfg['repository']) || !is_string($cfg['repository'])) {
            return null;
        }
        if (!isset($cfg['hosts']) || !is_array($cfg['hosts'])) {
            return null;
        }
        foreach ($cfg['hosts'] as $host) {
            if (!is_array($host)) {
                return null;
            }
            foreach (self::REQUIRED_HOST_KEYS as $key) {
                if (!array_key_exists($key, $host)) {
                    return null;
                }
            }
            if (!is_string($host['name']) || !is_string($host['hostname'])
                || !is_string($host['user']) || !is_string($host['stage'])
                || !is_string($host['path'])) {
                return null;
            }
            if ($host['port'] !== null && !is_int($host['port'])) {
                return null;
            }
        }
        return $cfg;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Deploy/SidecarTest.php
```

Expected: 8 passing tests.

- [ ] **Step 5: Commit**

```bash
git add lib/Deploy/Sidecar.php tests/Deploy/SidecarTest.php
git rm -f lib/Deploy/.gitkeep tests/Deploy/.gitkeep 2>/dev/null || true
git commit -m "feat(deploy): add Sidecar::load + path"
```

---

## Task 3: `Sidecar::save` with backup

**Files:**
- Modify: `lib/Deploy/Sidecar.php`
- Modify: `tests/Deploy/SidecarTest.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/Deploy/SidecarTest.php` (inside the class, before the closing brace):

```php
    public function testSaveWritesValidPhpThatLoadsBackToSameArray(): void
    {
        $cfg = [
            'repository' => 'git@example.com:u/r.git',
            'hosts' => [
                ['name' => 'stage', 'hostname' => 'h1', 'port' => 22, 'user' => 'u1', 'stage' => 'stage', 'path' => '/p1'],
                ['name' => 'prod',  'hostname' => 'h2', 'port' => null, 'user' => 'u2', 'stage' => 'prod',  'path' => '/p2'],
            ],
        ];

        Sidecar::save($this->tmpPath(), $cfg);

        $loaded = Sidecar::load($this->tmpPath());
        $this->assertSame($cfg, $loaded);
    }

    public function testSaveOutputIsDeterministic(): void
    {
        $cfg = [
            'repository' => 'r',
            'hosts' => [
                ['name' => 's', 'hostname' => 'h', 'port' => 22, 'user' => 'u', 'stage' => 's', 'path' => '/p'],
            ],
        ];

        Sidecar::save($this->tmpPath('a.php'), $cfg);
        Sidecar::save($this->tmpPath('b.php'), $cfg);

        $this->assertSame(
            file_get_contents($this->tmpPath('a.php')),
            file_get_contents($this->tmpPath('b.php')),
        );
    }

    public function testSaveBacksUpExistingFile(): void
    {
        file_put_contents($this->tmpPath(), "<?php return ['old' => true];");

        $cfg = ['repository' => 'r', 'hosts' => [
            ['name' => 's', 'hostname' => 'h', 'port' => 22, 'user' => 'u', 'stage' => 's', 'path' => '/p'],
        ]];

        Sidecar::save($this->tmpPath(), $cfg);

        $backups = glob($this->tmpDir . '/deploy.config.php.bak.*') ?: [];
        $this->assertCount(1, $backups);
        $this->assertSame("<?php return ['old' => true];", file_get_contents($backups[0]));
    }

    public function testSaveDoesNotBackUpWhenNoExistingFile(): void
    {
        $cfg = ['repository' => 'r', 'hosts' => [
            ['name' => 's', 'hostname' => 'h', 'port' => 22, 'user' => 'u', 'stage' => 's', 'path' => '/p'],
        ]];

        Sidecar::save($this->tmpPath(), $cfg);

        $backups = glob($this->tmpDir . '/deploy.config.php.bak.*') ?: [];
        $this->assertCount(0, $backups);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Deploy/SidecarTest.php
```

Expected: errors about `Sidecar::save` not defined.

- [ ] **Step 3: Add `save()` to `Sidecar`**

Append to `lib/Deploy/Sidecar.php` inside the class:

```php
    /**
     * @param array{repository: string, hosts: list<array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}>} $cfg
     */
    public static function save(?string $path, array $cfg): void
    {
        $path ??= self::path();
        if (is_file($path)) {
            $backup = $path . '.bak.' . date('Ymd-His');
            if (!@copy($path, $backup)) {
                throw new \RuntimeException("Failed to back up sidecar to {$backup}");
            }
        }
        if (file_put_contents($path, self::render($cfg)) === false) {
            throw new \RuntimeException("Failed to write sidecar to {$path}");
        }
    }

    /**
     * @param array{repository: string, hosts: list<array<string,mixed>>} $cfg
     */
    private static function render(array $cfg): string
    {
        $out = "<?php\n\nreturn [\n";
        $out .= '    ' . self::renderKey('repository') . ' => ' . self::renderValue($cfg['repository']) . ",\n";
        $out .= "    'hosts' => [\n";
        foreach ($cfg['hosts'] as $host) {
            $out .= "        [\n";
            foreach (self::REQUIRED_HOST_KEYS as $key) {
                $out .= '            ' . self::renderKey($key) . ' => ' . self::renderValue($host[$key]) . ",\n";
            }
            $out .= "        ],\n";
        }
        $out .= "    ],\n];\n";
        return $out;
    }

    private static function renderKey(string $key): string
    {
        return "'" . $key . "'";
    }

    private static function renderValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        // strings: single-quoted, escape backslash + single-quote
        return "'" . strtr((string) $value, ["\\" => "\\\\", "'" => "\\'"]) . "'";
    }
```

The `save()` signature is `save(?string $path, array $cfg)`. Default-path callers pass `null` first: `Sidecar::save(null, $cfg)`. This matches the spec's "save the sidecar" semantics while keeping the test-friendly path-first arg consistent with `load()`.

- [ ] **Step 4: Update `load()` PHPDoc** (no behavior change, just align with new write path)

The `port` value must round-trip; current `load()` already accepts `int|null`. No code change required.

- [ ] **Step 5: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Deploy/SidecarTest.php
```

Expected: 12 passing tests.

- [ ] **Step 6: Commit**

```bash
git add lib/Deploy/Sidecar.php tests/Deploy/SidecarTest.php
git commit -m "feat(deploy): add Sidecar::save with backup"
```

---

## Task 4: `DeployFile::hasMarkers`

**Files:**
- Create: `lib/Deploy/DeployFile.php`
- Create: `tests/Deploy/DeployFileTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Deploy/DeployFileTest.php`:

```php
<?php

namespace Ynamite\ViteRex\Tests\Deploy;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Deploy\DeployFile;

final class DeployFileTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../fixtures/deploy/' . $name;
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail("Missing fixture: {$path}");
        }
        return $contents;
    }

    // --- hasMarkers ---

    public function testHasMarkersTrueWhenBothMarkersPresent(): void
    {
        $this->assertTrue(DeployFile::hasMarkers($this->fixture('with-markers.php')));
    }

    public function testHasMarkersFalseWhenNoMarkers(): void
    {
        $this->assertFalse(DeployFile::hasMarkers($this->fixture('single-host.php')));
    }

    public function testHasMarkersFalseWhenOnlyOpening(): void
    {
        $this->assertFalse(DeployFile::hasMarkers($this->fixture('tampered-opening-only.php')));
    }

    public function testHasMarkersFalseWhenOnlyClosing(): void
    {
        $contents = "<?php\n// <<< VITEREX:DEPLOY_CONFIG <<<\n";
        $this->assertFalse(DeployFile::hasMarkers($contents));
    }

    public function testHasMarkersFalseWhenDuplicateOpenings(): void
    {
        $contents = "<?php\n"
            . "// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>\n"
            . "// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>\n"
            . "// <<< VITEREX:DEPLOY_CONFIG <<<\n";
        $this->assertFalse(DeployFile::hasMarkers($contents));
    }

    public function testHasMarkersFalseWhenMarkerInsideStringLiteral(): void
    {
        // markers appearing inside a quoted string must not count
        $contents = "<?php\n\$s = '// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>';\n"
            . "\$t = '// <<< VITEREX:DEPLOY_CONFIG <<<';\n";
        $this->assertFalse(DeployFile::hasMarkers($contents));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Deploy/DeployFileTest.php
```

Expected: errors about `Ynamite\ViteRex\Deploy\DeployFile` not found.

- [ ] **Step 3: Implement `DeployFile::hasMarkers`**

Create `lib/Deploy/DeployFile.php`:

```php
<?php

namespace Ynamite\ViteRex\Deploy;

use rex_path;

final class DeployFile
{
    public const MARKER_OPEN = '// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>';
    public const MARKER_CLOSE = '// <<< VITEREX:DEPLOY_CONFIG <<<';

    public static function path(): string
    {
        return rex_path::base('deploy.php');
    }

    public static function hasMarkers(string $contents): bool
    {
        $tokens = @token_get_all($contents);
        if (!is_array($tokens)) {
            return false;
        }
        $opens = 0;
        $closes = 0;
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            // Only count markers that appear as PHP comments — string literals
            // are T_CONSTANT_ENCAPSED_STRING and never counted here.
            if ($token[0] !== T_COMMENT) {
                continue;
            }
            $line = $token[1];
            if (str_contains($line, self::MARKER_OPEN)) {
                $opens++;
            }
            if (str_contains($line, self::MARKER_CLOSE)) {
                $closes++;
            }
        }
        return $opens === 1 && $closes === 1;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Deploy/DeployFileTest.php
```

Expected: 6 passing tests.

- [ ] **Step 5: Commit**

```bash
git add lib/Deploy/DeployFile.php tests/Deploy/DeployFileTest.php
git commit -m "feat(deploy): add DeployFile::hasMarkers"
```

---

## Task 5: `DeployFile::extract` — happy paths (single + multi host)

**Files:**
- Modify: `lib/Deploy/DeployFile.php`
- Modify: `tests/Deploy/DeployFileTest.php`

- [ ] **Step 1: Write failing tests**

Append to `DeployFileTest.php` (inside the class):

```php
    // --- extract: happy paths ---

    public function testExtractSingleHost(): void
    {
        $cfg = DeployFile::extract($this->fixture('single-host.php'));

        $this->assertNotNull($cfg);
        $this->assertSame('git@github.com:user/repo.git', $cfg['repository']);
        $this->assertCount(1, $cfg['hosts']);
        $this->assertSame([
            'name' => 'staging',
            'hostname' => 'example.com',
            'port' => 22,
            'user' => 'webuser',
            'stage' => 'stage',
            'path' => '/var/www/staging',
        ], $cfg['hosts'][0]);
    }

    public function testExtractMultiHost(): void
    {
        $cfg = DeployFile::extract($this->fixture('multi-host.php'));

        $this->assertNotNull($cfg);
        $this->assertSame('git@github.com:user/repo.git', $cfg['repository']);
        $this->assertCount(2, $cfg['hosts']);

        $this->assertSame('stage', $cfg['hosts'][0]['name']);
        $this->assertSame('shared.example.com', $cfg['hosts'][0]['hostname']);
        $this->assertSame(22, $cfg['hosts'][0]['port']);

        $this->assertSame('prod', $cfg['hosts'][1]['name']);
        $this->assertSame('prod', $cfg['hosts'][1]['stage']);
        $this->assertSame('/var/www/prod', $cfg['hosts'][1]['path']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Deploy/DeployFileTest.php --filter Extract
```

Expected: errors about `DeployFile::extract` not defined.

- [ ] **Step 3: Implement `extract()`**

Append to `lib/Deploy/DeployFile.php` inside the class:

```php
    private const HOST_METHOD_MAP = [
        'setHostname' => 'hostname',
        'setRemoteUser' => 'user',
        'setPort' => 'port',
        'setDeployPath' => 'path',
    ];

    private const PROLOGUE_VAR_MAP = [
        'deploymentName' => 'name',
        'deploymentHost' => 'hostname',
        'deploymentPort' => 'port',
        'deploymentUser' => 'user',
        'deploymentType' => 'stage',
        'deploymentPath' => 'path',
        'deploymentRepository' => 'repository',
    ];

    /**
     * @return array{repository: string, hosts: list<array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}>}|null
     */
    public static function extract(string $contents): ?array
    {
        $tokens = @token_get_all($contents);
        if (!is_array($tokens) || count($tokens) === 0) {
            return null;
        }
        // Strip whitespace + comments — but keep doc comments out too. Keep the
        // raw token array for offsets (used in rewrite()), so produce a parallel
        // "significant tokens" array for the parser.
        $sig = [];
        foreach ($tokens as $i => $tok) {
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML], true)) {
                continue;
            }
            $sig[] = ['orig_index' => $i, 'tok' => $tok];
        }

        $prologue = self::extractPrologue($sig);
        $hosts = self::extractHosts($sig, $prologue);
        $repository = $prologue['repository'] ?? null;

        if ($repository === null || !is_string($repository) || count($hosts) === 0) {
            return null;
        }
        return ['repository' => $repository, 'hosts' => $hosts];
    }

    /**
     * Walk significant tokens; collect $deployment* = 'literal'; assignments
     * regardless of where they appear at the top level. Returns map var-suffix
     * → string value (e.g., 'name' => 'staging', 'port' => '22', 'repository' => '…').
     *
     * @param list<array{orig_index:int, tok: mixed}> $sig
     * @return array<string,string>
     */
    private static function extractPrologue(array $sig): array
    {
        $vars = [];
        $n = count($sig);
        for ($i = 0; $i < $n - 3; $i++) {
            $a = $sig[$i]['tok'];
            $b = $sig[$i + 1]['tok'];
            $c = $sig[$i + 2]['tok'];
            $d = $sig[$i + 3]['tok'];
            if (!is_array($a) || $a[0] !== T_VARIABLE) {
                continue;
            }
            $varName = ltrim($a[1], '$');
            if (!isset(self::PROLOGUE_VAR_MAP[$varName])) {
                continue;
            }
            if ($b !== '=') {
                continue;
            }
            if (!is_array($c) || $c[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if ($d !== ';') {
                continue;
            }
            $vars[self::PROLOGUE_VAR_MAP[$varName]] = self::unquote($c[1]);
        }
        return $vars;
    }

    /**
     * Walk significant tokens; for each `host(arg)->setX(arg)->...->setY(arg);`
     * chain at top level, build a host array. Skip chains that don't include
     * setHostname + setDeployPath (the minimum to be recognizable).
     *
     * @param list<array{orig_index:int, tok: mixed}> $sig
     * @param array<string,string> $prologue
     * @return list<array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}>
     */
    private static function extractHosts(array $sig, array $prologue): array
    {
        $hosts = [];
        $n = count($sig);
        $i = 0;
        while ($i < $n - 2) {
            $a = $sig[$i]['tok'];
            $b = $sig[$i + 1]['tok'];
            // looking for: T_STRING("host") '('
            if (!is_array($a) || $a[0] !== T_STRING || $a[1] !== 'host' || $b !== '(') {
                $i++;
                continue;
            }
            $arg = $sig[$i + 2]['tok'];
            $closeParen = $sig[$i + 3]['tok'] ?? null;
            if ($closeParen !== ')') {
                $i++;
                continue;
            }
            $hostName = self::resolveScalar($arg, $prologue, 'name');
            if ($hostName === null) {
                $i++;
                continue;
            }
            // walk method chain from index i+4
            $j = $i + 4;
            $collected = ['name' => $hostName];
            $endIdx = $j;
            while ($j < $n) {
                $tok = $sig[$j]['tok'];
                if ($tok === ';') {
                    $endIdx = $j;
                    break;
                }
                if (!is_array($tok) || $tok[0] !== T_OBJECT_OPERATOR) {
                    // not a method chain continuation — bail without consuming
                    $j = -1;
                    break;
                }
                $methodTok = $sig[$j + 1]['tok'] ?? null;
                $openTok = $sig[$j + 2]['tok'] ?? null;
                if (!is_array($methodTok) || $methodTok[0] !== T_STRING || $openTok !== '(') {
                    $j = -1;
                    break;
                }
                $method = $methodTok[1];
                // For 'set' with first arg 'labels' and second arg ['stage' => X],
                // capture stage. Otherwise it's a known setX() with one scalar arg.
                if ($method === 'set') {
                    [$consumed, $stage] = self::parseSetLabelsCall($sig, $j + 2, $prologue);
                    if ($consumed === 0) {
                        // not a labels call we recognize — skip past this method's parens
                        $consumed = self::skipBalancedParens($sig, $j + 2);
                        if ($consumed === 0) {
                            $j = -1;
                            break;
                        }
                    } else {
                        $collected['stage'] = $stage;
                    }
                    $j = $j + 2 + $consumed;
                    continue;
                }
                if (!isset(self::HOST_METHOD_MAP[$method])) {
                    // unrecognized method (e.g., setLabels or anything else) — bail
                    $j = -1;
                    break;
                }
                $argTok = $sig[$j + 3]['tok'] ?? null;
                $closeTok = $sig[$j + 4]['tok'] ?? null;
                if ($closeTok !== ')') {
                    $j = -1;
                    break;
                }
                $key = self::HOST_METHOD_MAP[$method];
                $value = self::resolveScalar($argTok, $prologue, $key);
                if ($value === null) {
                    $j = -1;
                    break;
                }
                $collected[$key] = $value;
                $j += 5;
            }
            if ($j < 0) {
                $i++;
                continue;
            }
            // host chain must include hostname + path at minimum to count
            if (!isset($collected['hostname'], $collected['path'])) {
                $i++;
                continue;
            }
            $hosts[] = self::completeHost($collected);
            $i = $endIdx + 1;
        }
        return $hosts;
    }

    /**
     * Parse `('labels', ['stage' => X])`. Short-array form only — long-form
     * `array('stage' => X)` is not supported (Deployer 7.x examples and the
     * viterex-installer scaffold both use short arrays). Returns
     * [tokensConsumedFromOpenParen, stageValueOrNull]. tokensConsumed=0 means
     * "not a labels call we recognize" (caller should fall back to skipping).
     *
     * Token layout expected (positions relative to $openIdx):
     *   0:( 1:'labels' 2:, 3:[ 4:'stage' 5:=> 6:X 7:] 8:)  → 9 tokens
     *
     * @param list<array{orig_index:int, tok: mixed}> $sig
     * @param array<string,string> $prologue
     * @return array{0:int, 1:string|null}
     */
    private static function parseSetLabelsCall(array $sig, int $openIdx, array $prologue): array
    {
        $get = static fn(int $idx) => $sig[$idx]['tok'] ?? null;

        if ($get($openIdx) !== '(') return [0, null];

        $first = $get($openIdx + 1);
        if (!is_array($first) || $first[0] !== T_CONSTANT_ENCAPSED_STRING
            || self::unquote($first[1]) !== 'labels'
        ) {
            return [0, null];
        }
        if ($get($openIdx + 2) !== ',') return [0, null];
        if ($get($openIdx + 3) !== '[') return [0, null];

        $keyTok = $get($openIdx + 4);
        $arrowTok = $get($openIdx + 5);
        $valTok = $get($openIdx + 6);
        if ($get($openIdx + 7) !== ']') return [0, null];
        if ($get($openIdx + 8) !== ')') return [0, null];

        if (!is_array($keyTok) || $keyTok[0] !== T_CONSTANT_ENCAPSED_STRING
            || self::unquote($keyTok[1]) !== 'stage'
        ) {
            return [0, null];
        }
        if (!is_array($arrowTok) || $arrowTok[0] !== T_DOUBLE_ARROW) {
            return [0, null];
        }

        $stage = self::resolveScalar($valTok, $prologue, 'stage');
        if ($stage === null) return [0, null];

        return [9, (string) $stage];
    }

    /**
     * Skip past one balanced parentheses pair starting at $openIdx (which must
     * point at '('). Returns the number of tokens consumed including both
     * parens, or 0 if not balanced.
     *
     * @param list<array{orig_index:int, tok: mixed}> $sig
     */
    private static function skipBalancedParens(array $sig, int $openIdx): int
    {
        if (($sig[$openIdx]['tok'] ?? null) !== '(') {
            return 0;
        }
        $depth = 1;
        $n = count($sig);
        for ($k = $openIdx + 1; $k < $n; $k++) {
            $t = $sig[$k]['tok'];
            if ($t === '(') $depth++;
            elseif ($t === ')') {
                $depth--;
                if ($depth === 0) {
                    return $k - $openIdx + 1;
                }
            }
        }
        return 0;
    }

    /**
     * Resolve a token to its string value. Accepts string literal or a
     * $deploymentX variable resolvable from the prologue. For numeric fields
     * (port), returns int.
     *
     * @param mixed $tok
     * @param array<string,string> $prologue
     * @return string|int|null
     */
    private static function resolveScalar(mixed $tok, array $prologue, string $key): mixed
    {
        $value = null;
        if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = self::unquote($tok[1]);
        } elseif (is_array($tok) && $tok[0] === T_LNUMBER) {
            $value = (int) $tok[1];
        } elseif (is_array($tok) && $tok[0] === T_VARIABLE) {
            $varName = ltrim($tok[1], '$');
            $promotedKey = self::PROLOGUE_VAR_MAP[$varName] ?? null;
            if ($promotedKey !== null && isset($prologue[$promotedKey])) {
                $value = $prologue[$promotedKey];
            }
        }
        if ($value === null) {
            return null;
        }
        if ($key === 'port') {
            return is_int($value) ? $value : (int) $value;
        }
        return (string) $value;
    }

    private static function unquote(string $literal): string
    {
        // T_CONSTANT_ENCAPSED_STRING comes with surrounding quotes intact.
        // Single-quoted: handle \\ and \'. Double-quoted: handle the same plus a few escapes.
        $first = $literal[0] ?? '';
        $inner = substr($literal, 1, -1);
        if ($first === "'") {
            return strtr($inner, ['\\\\' => '\\', "\\'" => "'"]);
        }
        // double-quoted
        return strtr($inner, ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\t' => "\t"]);
    }

    /**
     * @param array<string,mixed> $collected
     * @return array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}
     */
    private static function completeHost(array $collected): array
    {
        return [
            'name' => (string) $collected['name'],
            'hostname' => (string) $collected['hostname'],
            'port' => isset($collected['port']) ? (int) $collected['port'] : null,
            'user' => (string) ($collected['user'] ?? ''),
            'stage' => (string) ($collected['stage'] ?? ''),
            'path' => (string) $collected['path'],
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Deploy/DeployFileTest.php --filter Extract
```

Expected: 2 passing tests (the two added in Step 1).

- [ ] **Step 5: Commit**

```bash
git add lib/Deploy/DeployFile.php tests/Deploy/DeployFileTest.php
git commit -m "feat(deploy): add DeployFile::extract for single + multi host"
```

---

## Task 6: `DeployFile::extract` — null cases

**Files:**
- Modify: `tests/Deploy/DeployFileTest.php`

This task adds tests only — the extract logic from Task 5 already returns `null` for these inputs.

- [ ] **Step 1: Write tests**

Append to `DeployFileTest.php`:

```php
    // --- extract: null cases ---

    public function testExtractReturnsNullForPrologueWithoutHost(): void
    {
        $this->assertNull(DeployFile::extract($this->fixture('prologue-no-host.php')));
    }

    public function testExtractReturnsNullForBareFile(): void
    {
        $this->assertNull(DeployFile::extract($this->fixture('bare-php.php')));
    }

    public function testExtractReturnsNullForUnrecognizedHostChain(): void
    {
        // file uses ->set('hostname', ...) instead of ->setHostname(...)
        // → hostname not collected → host doesn't meet minimum → not added
        $this->assertNull(DeployFile::extract($this->fixture('unrecognized-host-chain.php')));
    }

    public function testExtractReturnsNullForSyntacticallyInvalidFile(): void
    {
        // PHP < 8 returned partial tokens for invalid PHP; on 8+ token_get_all
        // raises a parse warning but still returns tokens for the valid prefix.
        // Either way, this fixture has no recognizable prologue/hosts → null.
        $contents = "<?php this is not valid php at all !@#";
        $this->assertNull(@DeployFile::extract($contents));
    }

    // --- extract: source-encoding edge cases ---

    public function testExtractHandlesCrlfLineEndings(): void
    {
        $contents = str_replace("\n", "\r\n", $this->fixture('single-host.php'));
        $cfg = DeployFile::extract($contents);
        $this->assertNotNull($cfg);
        $this->assertSame('staging', $cfg['hosts'][0]['name']);
    }

    public function testExtractHandlesLeadingBom(): void
    {
        $contents = "\xEF\xBB\xBF" . $this->fixture('single-host.php');
        $cfg = DeployFile::extract($contents);
        $this->assertNotNull($cfg);
        $this->assertSame('staging', $cfg['hosts'][0]['name']);
    }
```

- [ ] **Step 2: Run tests**

```bash
./vendor/bin/phpunit tests/Deploy/DeployFileTest.php --filter Extract
```

Expected: 6 passing tests (2 from Task 5 + 4 new). If any fail, fix the implementation in `lib/Deploy/DeployFile.php`.

- [ ] **Step 3: Commit**

```bash
git add tests/Deploy/DeployFileTest.php
git commit -m "test(deploy): cover DeployFile::extract null cases"
```

---

## Task 7: `DeployFile::rewrite` — first-time activation (no markers)

**Files:**
- Modify: `lib/Deploy/DeployFile.php`
- Modify: `tests/Deploy/DeployFileTest.php`

- [ ] **Step 1: Write failing tests**

Append to `DeployFileTest.php`:

```php
    // --- rewrite: first-time activation ---

    public function testRewriteReplacesPrologueAndHostBlockWithMarkerRegion(): void
    {
        $orig = $this->fixture('single-host.php');
        $extracted = DeployFile::extract($orig);
        $this->assertNotNull($extracted);

        $rewritten = DeployFile::rewrite($orig, $extracted);

        // marker region present
        $this->assertStringContainsString(DeployFile::MARKER_OPEN, $rewritten);
        $this->assertStringContainsString(DeployFile::MARKER_CLOSE, $rewritten);
        // sidecar require + foreach block present
        $this->assertStringContainsString("\$cfg = require __DIR__ . '/deploy.config.php';", $rewritten);
        $this->assertStringContainsString('foreach ($cfg[\'hosts\'] as $h)', $rewritten);
        // user code below the host block must survive (the custom task)
        $this->assertStringContainsString("task('custom:hello'", $rewritten);
        // prologue assignments removed
        $this->assertStringNotContainsString('$deploymentName =', $rewritten);
        $this->assertStringNotContainsString('$deploymentHost =', $rewritten);
        // first-host chain removed
        $this->assertStringNotContainsString('->setHostname($deploymentHost)', $rewritten);
        // require above the marker region preserved
        $this->assertStringContainsString("require __DIR__ . '/src/addons/ydeploy/deploy.php';", $rewritten);
    }

    public function testRewriteReplacesPrologueAndAllHostBlocksForMultiHost(): void
    {
        $orig = $this->fixture('multi-host.php');
        $extracted = DeployFile::extract($orig);
        $this->assertNotNull($extracted);

        $rewritten = DeployFile::rewrite($orig, $extracted);

        $this->assertStringContainsString(DeployFile::MARKER_OPEN, $rewritten);
        // both original host chains removed
        $this->assertStringNotContainsString("host(\$deploymentName)", $rewritten);
        $this->assertStringNotContainsString("host('prod')", $rewritten);
        // exactly one foreach block injected
        $this->assertSame(1, substr_count($rewritten, 'foreach ($cfg[\'hosts\']'));
    }

    public function testRewriteReturnsUnchangedWhenNoMarkersAndNoExtractable(): void
    {
        // file with neither markers nor a recognizable prologue+host shape
        // → rewrite has nothing to do; returns input unchanged
        $contents = $this->fixture('bare-php.php');
        $this->assertSame($contents, DeployFile::rewrite($contents, null));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Deploy/DeployFileTest.php --filter Rewrite
```

Expected: errors about `DeployFile::rewrite` not defined.

- [ ] **Step 3: Implement `rewrite()`**

Append to `lib/Deploy/DeployFile.php` inside the class:

```php
    /**
     * Produce new file contents with viterex's marker region in place.
     *
     * - If both markers are present → replace the region between them.
     * - Else, if extraction succeeds → find the byte range from the first
     *   prologue assignment through the last host(...) chain semicolon, and
     *   replace that range with the marker region.
     * - Else (no markers, nothing extractable) → return contents unchanged.
     *
     * @param array{repository: string, hosts: list<array<string,mixed>>}|null $extracted
     */
    public static function rewrite(string $contents, ?array $extracted): string
    {
        if (self::hasMarkers($contents)) {
            return self::replaceMarkerRegion($contents);
        }
        if ($extracted === null) {
            return $contents;
        }
        $range = self::locatePrologueHostRange($contents);
        if ($range === null) {
            return $contents;
        }
        [$startByte, $endByte] = $range;
        return substr($contents, 0, $startByte)
            . self::renderMarkerRegion()
            . substr($contents, $endByte);
    }

    private static function renderMarkerRegion(): string
    {
        return self::MARKER_OPEN . "\n"
            . "\$cfg = require __DIR__ . '/deploy.config.php';\n"
            . "set('repository', \$cfg['repository']);\n"
            . "foreach (\$cfg['hosts'] as \$h) {\n"
            . "    host(\$h['name'])\n"
            . "        ->setHostname(\$h['hostname'])\n"
            . "        ->setRemoteUser(\$h['user'])\n"
            . "        ->setPort(\$h['port'])\n"
            . "        ->set('labels', ['stage' => \$h['stage']])\n"
            . "        ->setDeployPath(\$h['path']);\n"
            . "}\n"
            . self::MARKER_CLOSE;
    }

    private static function replaceMarkerRegion(string $contents): string
    {
        $openPos = strpos($contents, self::MARKER_OPEN);
        $closePos = strpos($contents, self::MARKER_CLOSE);
        if ($openPos === false || $closePos === false || $closePos < $openPos) {
            return $contents;
        }
        $endByte = $closePos + strlen(self::MARKER_CLOSE);
        return substr($contents, 0, $openPos)
            . self::renderMarkerRegion()
            . substr($contents, $endByte);
    }

    /**
     * Locate the byte range covering: from the first $deployment* assignment
     * through the closing ';' of the last recognized host(...)->...->setDeployPath();
     * chain. Both bounds are byte offsets in $contents; $endByte is exclusive
     * (ready for substr-replace).
     *
     * Anything between/after the chains (e.g., a `set('repository', ...)` call,
     * `add('shared_dirs', ...)`, etc.) is INCLUDED in the replaced range — that
     * was always going to be regenerated from the sidecar's foreach.
     *
     * @return array{0:int,1:int}|null
     */
    private static function locatePrologueHostRange(string $contents): ?array
    {
        $tokens = @token_get_all($contents);
        if (!is_array($tokens) || count($tokens) === 0) {
            return null;
        }
        // map each significant token to its absolute byte offset
        $offsets = self::computeTokenOffsets($contents, $tokens);
        $sig = [];
        foreach ($tokens as $i => $tok) {
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML], true)) {
                continue;
            }
            $sig[] = ['orig_index' => $i, 'tok' => $tok, 'byte' => $offsets[$i]];
        }
        $startByte = null;
        $endByte = null;
        $n = count($sig);
        for ($i = 0; $i < $n - 3; $i++) {
            $a = $sig[$i]['tok'];
            if (is_array($a) && $a[0] === T_VARIABLE && isset(self::PROLOGUE_VAR_MAP[ltrim($a[1], '$')])
                && ($sig[$i + 1]['tok'] ?? null) === '='
                && is_array($sig[$i + 2]['tok']) && $sig[$i + 2]['tok'][0] === T_CONSTANT_ENCAPSED_STRING
                && ($sig[$i + 3]['tok'] ?? null) === ';'
            ) {
                if ($startByte === null) {
                    $startByte = $sig[$i]['byte'];
                }
            }
        }
        // find the last host(...)->...; chain end byte
        for ($i = 0; $i < $n - 3; $i++) {
            $a = $sig[$i]['tok'];
            if (!is_array($a) || $a[0] !== T_STRING || $a[1] !== 'host') {
                continue;
            }
            if (($sig[$i + 1]['tok'] ?? null) !== '(') {
                continue;
            }
            // walk to next ';' at the same parenthesis depth (depth 0 outside the
            // initial host(...) since after that we're in a method chain)
            $j = $i + 1;
            $depth = 0;
            $chainEndIdx = null;
            while ($j < $n) {
                $t = $sig[$j]['tok'];
                if ($t === '(' || $t === '[') $depth++;
                elseif ($t === ')' || $t === ']') $depth--;
                elseif ($t === ';' && $depth === 0) {
                    $chainEndIdx = $j;
                    break;
                }
                $j++;
            }
            if ($chainEndIdx !== null) {
                // include the ';' itself: byte of token + length
                $semicolonByte = $sig[$chainEndIdx]['byte'] + 1;
                $endByte = $semicolonByte;
                $i = $chainEndIdx; // skip past this chain
            }
        }
        if ($startByte === null || $endByte === null) {
            return null;
        }
        return [$startByte, $endByte];
    }

    /**
     * Compute the byte offset of each token in the original source. Tokens
     * returned by token_get_all are in source order; we sum widths.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array<int,int> map index → byte offset
     */
    private static function computeTokenOffsets(string $contents, array $tokens): array
    {
        $offsets = [];
        $byte = 0;
        foreach ($tokens as $i => $tok) {
            $offsets[$i] = $byte;
            $text = is_array($tok) ? $tok[1] : $tok;
            $byte += strlen($text);
        }
        return $offsets;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Deploy/DeployFileTest.php --filter Rewrite
```

Expected: 3 passing tests.

- [ ] **Step 5: Commit**

```bash
git add lib/Deploy/DeployFile.php tests/Deploy/DeployFileTest.php
git commit -m "feat(deploy): add DeployFile::rewrite for first-time activation"
```

---

## Task 8: `DeployFile::rewrite` — re-rewrite with markers + idempotency

**Files:**
- Modify: `tests/Deploy/DeployFileTest.php`

The marker-replacement path is already implemented in Task 7 (`replaceMarkerRegion`). This task adds tests that exercise it.

- [ ] **Step 1: Write tests**

Append to `DeployFileTest.php`:

```php
    public function testRewriteReplacesMarkerRegionWhenAlreadyActivated(): void
    {
        $original = $this->fixture('with-markers.php');
        // Mutate the marker region to simulate a stale viterex injection
        // (e.g., a future viterex version added a new line). The rewrite
        // should normalize it back to the canonical marker region.
        $tampered = str_replace(
            "\$cfg = require __DIR__ . '/deploy.config.php';",
            "\$cfg = require __DIR__ . '/deploy.config.php'; // STALE",
            $original,
        );

        $rewritten = DeployFile::rewrite($tampered, null);

        $this->assertStringNotContainsString('// STALE', $rewritten);
        $this->assertStringContainsString("\$cfg = require __DIR__ . '/deploy.config.php';\n", $rewritten);
        // user code below the markers preserved
        $this->assertStringContainsString("task('custom:hello'", $rewritten);
    }

    public function testRewriteIsIdempotent(): void
    {
        $orig = $this->fixture('single-host.php');
        $extracted = DeployFile::extract($orig);
        $first = DeployFile::rewrite($orig, $extracted);
        $second = DeployFile::rewrite($first, null); // markers exist now → no extracted needed

        $this->assertSame($first, $second);
    }
```

- [ ] **Step 2: Run tests**

```bash
./vendor/bin/phpunit tests/Deploy/DeployFileTest.php --filter Rewrite
```

Expected: 5 passing tests (3 from Task 7 + 2 new).

- [ ] **Step 3: Commit**

```bash
git add tests/Deploy/DeployFileTest.php
git commit -m "test(deploy): cover DeployFile::rewrite re-activation + idempotency"
```

---

## Task 9: `DeployFile::rewrite` — tampered markers safety

**Files:**
- Modify: `tests/Deploy/DeployFileTest.php`

When markers are present-but-broken (only opening, only closing, or duplicates), `hasMarkers()` returns `false` AND extraction is unlikely to recognize the original prologue+host shape (because activation already replaced it). So `rewrite()` falls through and returns contents unchanged. The page handler then notices "we wrote nothing" and shows the tamper warning to the user.

- [ ] **Step 1: Write tests**

Append to `DeployFileTest.php`:

```php
    public function testRewriteIsNoOpWhenMarkersTamperedAndNoExtractable(): void
    {
        $contents = $this->fixture('tampered-opening-only.php');
        // hasMarkers() is false (only opening). No prologue assignments either.
        // → rewrite has no markers to replace and no shape to extract → unchanged.
        $this->assertFalse(DeployFile::hasMarkers($contents));
        $this->assertSame($contents, DeployFile::rewrite($contents, DeployFile::extract($contents)));
    }
```

- [ ] **Step 2: Run tests**

```bash
./vendor/bin/phpunit tests/Deploy/DeployFileTest.php
```

Expected: all DeployFile tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Deploy/DeployFileTest.php
git commit -m "test(deploy): cover DeployFile::rewrite tampered-markers no-op"
```

---

## Task 10: `Page` — pure helpers (state detection + validation)

**Files:**
- Create: `lib/Deploy/Page.php`
- Create: `tests/Deploy/PageTest.php`

`Page` exposes two pure helpers that the Redaxo entry point in `pages/deploy.php` calls. Pure = no Redaxo, no I/O, takes contents/POST as arrays. This is what we unit-test; `pages/deploy.php` does the rest.

- [ ] **Step 1: Write failing tests**

Create `tests/Deploy/PageTest.php`:

```php
<?php

namespace Ynamite\ViteRex\Tests\Deploy;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Deploy\Page;

final class PageTest extends TestCase
{
    // --- state detection ---

    public function testDetectStateNeedsActivationWhenSidecarExistsButDeployFileLacksMarkers(): void
    {
        $state = Page::detectState(
            sidecar: ['repository' => 'r', 'hosts' => []],
            deployContents: "<?php\n\$x = 1;",
        );
        $this->assertSame(Page::STATE_NEEDS_ACTIVATION, $state);
    }

    public function testDetectStateActiveWhenSidecarExistsAndDeployFileHasMarkers(): void
    {
        $deploy = "<?php\n// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>\n"
            . "// <<< VITEREX:DEPLOY_CONFIG <<<\n";
        $state = Page::detectState(['repository' => 'r', 'hosts' => []], $deploy);
        $this->assertSame(Page::STATE_ACTIVE, $state);
    }

    public function testDetectStateNoSidecarWhenSidecarMissing(): void
    {
        $state = Page::detectState(null, "<?php");
        $this->assertSame(Page::STATE_NO_SIDECAR, $state);
    }

    public function testDetectStateMissingDeployFileWhenContentsAreNull(): void
    {
        $state = Page::detectState(null, null);
        $this->assertSame(Page::STATE_MISSING_DEPLOY_FILE, $state);
    }

    // --- validation ---

    public function testValidatePostAcceptsMinimalValidInput(): void
    {
        $post = [
            'repository' => 'git@github.com:u/r.git',
            'hosts' => [
                ['name' => 'stage', 'hostname' => 'h', 'port' => '22', 'user' => 'u', 'stage' => 'stage', 'path' => '/p'],
            ],
        ];
        $result = Page::validatePost($post);
        $this->assertSame([], $result['errors']);
        $this->assertSame(22, $result['cfg']['hosts'][0]['port']);
    }

    public function testValidatePostAllowsEmptyPort(): void
    {
        $post = ['repository' => 'r', 'hosts' => [
            ['name' => 's', 'hostname' => 'h', 'port' => '', 'user' => 'u', 'stage' => 's', 'path' => '/p'],
        ]];
        $result = Page::validatePost($post);
        $this->assertSame([], $result['errors']);
        $this->assertNull($result['cfg']['hosts'][0]['port']);
    }

    public function testValidatePostRejectsMissingRequiredHostFields(): void
    {
        $post = ['repository' => 'r', 'hosts' => [['name' => 's']]];
        $result = Page::validatePost($post);
        $this->assertNotEmpty($result['errors']);
        $this->assertNull($result['cfg']);
    }

    public function testValidatePostRejectsNonNumericPort(): void
    {
        $post = ['repository' => 'r', 'hosts' => [
            ['name' => 's', 'hostname' => 'h', 'port' => 'twenty-two', 'user' => 'u', 'stage' => 's', 'path' => '/p'],
        ]];
        $result = Page::validatePost($post);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidatePostRejectsDuplicateHostNames(): void
    {
        $post = ['repository' => 'r', 'hosts' => [
            ['name' => 's', 'hostname' => 'h', 'port' => '22', 'user' => 'u', 'stage' => 's', 'path' => '/p'],
            ['name' => 's', 'hostname' => 'h2', 'port' => '22', 'user' => 'u', 'stage' => 's', 'path' => '/p2'],
        ]];
        $result = Page::validatePost($post);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidatePostRejectsZeroHosts(): void
    {
        $post = ['repository' => 'r', 'hosts' => []];
        $result = Page::validatePost($post);
        $this->assertNotEmpty($result['errors']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Deploy/PageTest.php
```

Expected: errors about `Ynamite\ViteRex\Deploy\Page` not found.

- [ ] **Step 3: Implement `Page` helpers**

Create `lib/Deploy/Page.php`:

```php
<?php

namespace Ynamite\ViteRex\Deploy;

final class Page
{
    public const STATE_NO_SIDECAR = 'no_sidecar';
    public const STATE_NEEDS_ACTIVATION = 'needs_activation';
    public const STATE_ACTIVE = 'active';
    public const STATE_MISSING_DEPLOY_FILE = 'missing_deploy_file';

    /**
     * @param array<string,mixed>|null $sidecar
     */
    public static function detectState(?array $sidecar, ?string $deployContents): string
    {
        if ($deployContents === null) {
            return self::STATE_MISSING_DEPLOY_FILE;
        }
        if ($sidecar === null) {
            return self::STATE_NO_SIDECAR;
        }
        return DeployFile::hasMarkers($deployContents) ? self::STATE_ACTIVE : self::STATE_NEEDS_ACTIVATION;
    }

    /**
     * Build the canonical sidecar array from a posted form payload, or report
     * validation errors. `errors` is a flat list of human-readable messages
     * (the page renders them as a list); `cfg` is null iff there are errors.
     *
     * @param array<string,mixed> $post Expected shape: ['repository' => string, 'hosts' => array<int, array<string,string>>]
     * @return array{cfg: ?array{repository: string, hosts: list<array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}>}, errors: list<string>}
     */
    public static function validatePost(array $post): array
    {
        $errors = [];

        $repository = trim((string) ($post['repository'] ?? ''));
        if ($repository === '') {
            $errors[] = 'repository is required';
        }

        $rawHosts = $post['hosts'] ?? [];
        if (!is_array($rawHosts) || count($rawHosts) === 0) {
            $errors[] = 'at least one host is required';
            return ['cfg' => null, 'errors' => $errors];
        }

        $cfgHosts = [];
        $seenNames = [];
        foreach ($rawHosts as $i => $raw) {
            $line = $i + 1;
            if (!is_array($raw)) {
                $errors[] = "host #{$line}: invalid payload";
                continue;
            }
            $name = trim((string) ($raw['name'] ?? ''));
            $hostname = trim((string) ($raw['hostname'] ?? ''));
            $user = trim((string) ($raw['user'] ?? ''));
            $stage = trim((string) ($raw['stage'] ?? ''));
            $path = trim((string) ($raw['path'] ?? ''));
            $portRaw = trim((string) ($raw['port'] ?? ''));

            foreach (['name' => $name, 'hostname' => $hostname, 'user' => $user, 'path' => $path] as $field => $val) {
                if ($val === '') {
                    $errors[] = "host #{$line}: {$field} is required";
                }
            }
            if ($stage === '') {
                $stage = $name;
            }

            $port = null;
            if ($portRaw !== '') {
                if (!ctype_digit($portRaw)) {
                    $errors[] = "host #{$line}: port must be an integer or empty";
                } else {
                    $port = (int) $portRaw;
                }
            }

            if ($name !== '') {
                if (isset($seenNames[$name])) {
                    $errors[] = "host #{$line}: duplicate host name '{$name}'";
                }
                $seenNames[$name] = true;
            }

            $cfgHosts[] = [
                'name' => $name,
                'hostname' => $hostname,
                'port' => $port,
                'user' => $user,
                'stage' => $stage,
                'path' => $path,
            ];
        }

        if ($errors !== []) {
            return ['cfg' => null, 'errors' => $errors];
        }
        return [
            'cfg' => ['repository' => $repository, 'hosts' => $cfgHosts],
            'errors' => [],
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Deploy/PageTest.php
```

Expected: 10 passing tests.

- [ ] **Step 5: Commit**

```bash
git add lib/Deploy/Page.php tests/Deploy/PageTest.php
git commit -m "feat(deploy): add Page helpers (state detection + validation)"
```

---

## Task 11: `pages/deploy.php` — Redaxo entry point

**Files:**
- Create: `pages/deploy.php`

This file is Redaxo-bound; we manually verify it. No PHPUnit tests. It calls into `Page::detectState`, `Page::validatePost`, `Sidecar::*`, and `DeployFile::*` — all of which are unit-tested.

- [ ] **Step 1: Create `pages/deploy.php`**

```php
<?php

/** @var rex_addon $this */

use Ynamite\ViteRex\Deploy\DeployFile;
use Ynamite\ViteRex\Deploy\Page;
use Ynamite\ViteRex\Deploy\Sidecar;

if (!rex::getUser()->isAdmin()) {
    echo rex_view::error(rex_i18n::msg('viterex_no_permission'));
    return;
}

if (!rex_addon::get('ydeploy')->isAvailable()) {
    echo rex_view::warning(rex_i18n::msg('viterex_deploy_ydeploy_missing'));
    return;
}

$sidecarPath = Sidecar::path();
$deployPath = DeployFile::path();

$deployExists = is_file($deployPath);
$deployContents = $deployExists ? rex_file::get($deployPath) : null;

if (!$deployExists) {
    echo rex_view::error(rex_i18n::msg('viterex_deploy_file_missing'));
    return;
}

$csrf = rex_csrf_token::factory('viterex_deploy');

// --- POST: Activate ---
if (rex_post('viterex_deploy_activate', 'boolean')) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif (!is_file($sidecarPath)) {
        echo rex_view::error(rex_i18n::msg('viterex_deploy_activate_no_sidecar'));
    } elseif (DeployFile::hasMarkers((string) $deployContents)) {
        echo rex_view::info(rex_i18n::msg('viterex_deploy_already_active'));
    } else {
        $extracted = DeployFile::extract((string) $deployContents);
        $rewritten = DeployFile::rewrite((string) $deployContents, $extracted);
        if ($rewritten === $deployContents) {
            echo rex_view::error(rex_i18n::msg('viterex_deploy_rewrite_failed'));
        } else {
            $backup = $deployPath . '.bak.' . date('Ymd-His');
            if (!@copy($deployPath, $backup) || rex_file::put($deployPath, $rewritten) === false) {
                echo rex_view::error(rex_i18n::msg('viterex_deploy_write_failed'));
            } else {
                $deployContents = $rewritten;
                echo rex_view::success(rex_i18n::rawMsg('viterex_deploy_activated', basename($backup)));
            }
        }
    }
}

// --- POST: Save form ---
$formCfg = null;
$formErrors = [];
if (rex_post('viterex_deploy_save', 'boolean')) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $post = [
            'repository' => rex_post('repository', 'string'),
            'hosts' => rex_post('hosts', 'array', []),
        ];
        $validation = Page::validatePost($post);
        if ($validation['cfg'] === null) {
            $formErrors = $validation['errors'];
            $formCfg = $post;
            echo rex_view::error(implode('<br>', array_map('rex_escape', $formErrors)));
        } else {
            try {
                Sidecar::save($sidecarPath, $validation['cfg']);
                echo rex_view::success(rex_i18n::msg('viterex_deploy_saved'));
                $formCfg = $validation['cfg'];
            } catch (\RuntimeException $e) {
                echo rex_view::error(rex_escape($e->getMessage()));
                $formCfg = $post;
            }
        }
    }
}

// --- Determine state and pre-populate form ---
$sidecar = Sidecar::load($sidecarPath);

// Flow A: first visit, sidecar absent → auto-write from extract if possible
if ($sidecar === null && $formCfg === null) {
    $extracted = DeployFile::extract((string) $deployContents);
    if ($extracted !== null) {
        try {
            Sidecar::save($sidecarPath, $extracted);
            $sidecar = $extracted;
            echo rex_view::info(rex_i18n::msg('viterex_deploy_sidecar_created'));
        } catch (\RuntimeException $e) {
            echo rex_view::error(rex_escape($e->getMessage()));
        }
    } else {
        echo rex_view::warning(rex_i18n::msg('viterex_deploy_extract_failed'));
    }
}

if ($formCfg === null) {
    $formCfg = $sidecar ?? ['repository' => '', 'hosts' => [
        ['name' => '', 'hostname' => '', 'port' => 22, 'user' => '', 'stage' => '', 'path' => ''],
    ]];
}

$state = Page::detectState($sidecar, $deployContents);
if ($state === Page::STATE_NEEDS_ACTIVATION) {
    echo rex_view::info(rex_i18n::msg('viterex_deploy_needs_activation'));
} elseif ($state === Page::STATE_ACTIVE) {
    echo rex_view::success(rex_i18n::msg('viterex_deploy_active'));
}

// --- Render form ---
$action = rex_url::currentBackendPage();
$csrfFields = $csrf->getHiddenField();

$repositoryHtml = '<input type="text" name="repository" class="form-control" value="'
    . rex_escape((string) $formCfg['repository']) . '">';

$hostRows = '';
foreach ($formCfg['hosts'] as $i => $h) {
    $hostRows .= '<fieldset style="margin-bottom:1rem;border:1px solid #ddd;padding:.5rem 1rem;">';
    $hostRows .= '<legend>' . rex_i18n::msg('viterex_deploy_host_n') . ' ' . ($i + 1) . '</legend>';
    foreach (['name', 'hostname', 'port', 'user', 'stage', 'path'] as $field) {
        $val = (string) ($h[$field] ?? '');
        $label = rex_i18n::msg('viterex_deploy_field_' . $field);
        $hostRows .= '<label>' . rex_escape($label)
            . ' <input type="text" name="hosts[' . $i . '][' . $field . ']" value="' . rex_escape($val) . '" class="form-control"></label>';
    }
    $hostRows .= '</fieldset>';
}

$content = '<form action="' . $action . '" method="post">' . $csrfFields
    . '<input type="hidden" name="viterex_deploy_save" value="1">'
    . '<div class="form-group"><label>' . rex_escape(rex_i18n::msg('viterex_deploy_field_repository'))
    . '</label>' . $repositoryHtml . '</div>'
    . $hostRows
    . '<button type="submit" class="btn btn-save">' . rex_i18n::msg('viterex_deploy_save_button') . '</button>'
    . '</form>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('viterex_deploy_settings_title'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');

// --- Activate section (separate form) ---
$activateContent = '<form action="' . $action . '" method="post">' . $csrfFields
    . '<input type="hidden" name="viterex_deploy_activate" value="1">'
    . '<p>' . rex_i18n::msg('viterex_deploy_activate_intro') . '</p>'
    . '<button type="submit" class="btn btn-primary">'
    . '<i class="rex-icon fa-bolt"></i> ' . rex_i18n::msg('viterex_deploy_activate_button')
    . '</button></form>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'info', false);
$fragment->setVar('title', rex_i18n::msg('viterex_deploy_activate_title'), false);
$fragment->setVar('body', $activateContent, false);
echo $fragment->parse('core/page/section.php');
```

- [ ] **Step 2: Smoke-check syntactically**

```bash
php -l pages/deploy.php
```

Expected: `No syntax errors detected in pages/deploy.php`.

- [ ] **Step 3: Commit**

```bash
git add pages/deploy.php
git commit -m "feat(deploy): add pages/deploy.php Redaxo entry"
```

---

## Task 12: Register the subpage in `package.yml`

**Files:**
- Modify: `package.yml`

- [ ] **Step 1: Edit `package.yml`** — add the `deploy` subpage between `settings` and `docs` so the menu reads Settings → Deploy → Documentation:

Find this block:

```yaml
  subpages:
    settings:
      title: 'translate:viterex_settings'
      icon: rex-icon fa-cog
    docs:
      title: 'translate:viterex_docs'
      subPath: README.md
      icon: rex-icon fa-book
      itemclass: pull-right
```

Replace with:

```yaml
  subpages:
    settings:
      title: 'translate:viterex_settings'
      icon: rex-icon fa-cog
    deploy:
      title: 'translate:viterex_deploy_title'
      icon: rex-icon fa-paper-plane
    docs:
      title: 'translate:viterex_docs'
      subPath: README.md
      icon: rex-icon fa-book
      itemclass: pull-right
```

- [ ] **Step 2: Verify YAML still parses**

```bash
php -r 'var_dump(yaml_parse_file("package.yml"));' 2>&1 | head -5
```

If `yaml_parse_file` is unavailable in your PHP build, fall back to:

```bash
php -r 'use Symfony\Component\Yaml\Yaml; require "vendor/autoload.php"; print_r(Yaml::parseFile("package.yml"));' 2>&1 | head -10
```

Expected: an array containing `subpages` with three keys (`settings`, `deploy`, `docs`).

- [ ] **Step 3: Commit**

```bash
git add package.yml
git commit -m "feat(deploy): register deploy subpage"
```

---

## Task 13: Add language strings

**Files:**
- Modify: `lang/en_gb.lang`
- Modify: `lang/de_de.lang`

- [ ] **Step 1: Append to `lang/en_gb.lang`**

Append at the end of the file:

```
viterex_deploy_title = Deploy
viterex_deploy_settings_title = Deployment hosts
viterex_deploy_activate_title = Activate sidecar
viterex_deploy_activate_intro = Click Activate to wire <code>deploy.php</code> up to <code>deploy.config.php</code>. A timestamped backup of <code>deploy.php</code> will be written first.
viterex_deploy_activate_button = Activate
viterex_deploy_save_button = Save deploy hosts
viterex_deploy_host_n = Host

viterex_deploy_field_repository = Git repository
viterex_deploy_field_name = Host name (Deployer alias)
viterex_deploy_field_hostname = Hostname
viterex_deploy_field_port = SSH port (leave empty for default)
viterex_deploy_field_user = SSH user
viterex_deploy_field_stage = Stage label (e.g. stage / prod)
viterex_deploy_field_path = Deploy path on remote

viterex_deploy_ydeploy_missing = The <strong>ydeploy</strong> addon is not installed. Install and activate it to use the deploy helper.
viterex_deploy_file_missing = <code>deploy.php</code> not found at the project root. The deploy helper requires the standard Deployer entry file.
viterex_deploy_sidecar_created = Sidecar <code>deploy.config.php</code> created from your current <code>deploy.php</code> values. Review the form below and click <em>Activate</em> to wire it up.
viterex_deploy_extract_failed = Couldn't auto-detect existing deploy settings from <code>deploy.php</code>. Fill the form below and click <em>Activate</em> to set up the sidecar.
viterex_deploy_needs_activation = Sidecar exists but <code>deploy.php</code> isn't wired up to it yet. Click <em>Activate</em> below.
viterex_deploy_active = Sidecar is active and wired into <code>deploy.php</code>. Form changes will be picked up on the next deployer run.
viterex_deploy_already_active = <code>deploy.php</code> is already wired up to the sidecar; nothing to do.
viterex_deploy_activate_no_sidecar = No sidecar to activate — save the form first.
viterex_deploy_rewrite_failed = Couldn't safely rewrite <code>deploy.php</code> — the viterex marker block appears tampered, or the file's prologue/host shape isn't recognized. Restore from a backup or remove partial markers and try again.
viterex_deploy_write_failed = Failed to write <code>deploy.php</code>. Check filesystem permissions on the project root.
viterex_deploy_saved = Deploy hosts saved to <code>deploy.config.php</code>.
viterex_deploy_activated = <code>deploy.php</code> rewritten to use the sidecar. Backup written to <code>{0}</code>.
```

- [ ] **Step 2: Append to `lang/de_de.lang`**

Append at the end of the file:

```
viterex_deploy_title = Deploy
viterex_deploy_settings_title = Deployment-Hosts
viterex_deploy_activate_title = Sidecar aktivieren
viterex_deploy_activate_intro = Mit „Aktivieren" wird <code>deploy.php</code> mit <code>deploy.config.php</code> verdrahtet. Vorher wird ein zeitgestempeltes Backup von <code>deploy.php</code> erstellt.
viterex_deploy_activate_button = Aktivieren
viterex_deploy_save_button = Deploy-Hosts speichern
viterex_deploy_host_n = Host

viterex_deploy_field_repository = Git-Repository
viterex_deploy_field_name = Host-Name (Deployer-Alias)
viterex_deploy_field_hostname = Hostname
viterex_deploy_field_port = SSH-Port (leer = Standard)
viterex_deploy_field_user = SSH-Benutzer
viterex_deploy_field_stage = Stage-Label (z. B. stage / prod)
viterex_deploy_field_path = Deploy-Pfad auf dem Remote

viterex_deploy_ydeploy_missing = Das <strong>ydeploy</strong>-Addon ist nicht installiert. Installiere und aktiviere es, um den Deploy-Helper zu nutzen.
viterex_deploy_file_missing = <code>deploy.php</code> wurde im Projekt-Root nicht gefunden. Der Deploy-Helper benötigt die Standard-Deployer-Datei.
viterex_deploy_sidecar_created = Sidecar <code>deploy.config.php</code> aus den aktuellen Werten in <code>deploy.php</code> erzeugt. Bitte das Formular prüfen und auf <em>Aktivieren</em> klicken.
viterex_deploy_extract_failed = Aktuelle Deploy-Einstellungen konnten in <code>deploy.php</code> nicht erkannt werden. Bitte das Formular ausfüllen und auf <em>Aktivieren</em> klicken.
viterex_deploy_needs_activation = Sidecar vorhanden, aber <code>deploy.php</code> noch nicht verdrahtet. Bitte unten auf <em>Aktivieren</em> klicken.
viterex_deploy_active = Sidecar ist aktiv und mit <code>deploy.php</code> verdrahtet. Formularänderungen werden beim nächsten Deployer-Lauf übernommen.
viterex_deploy_already_active = <code>deploy.php</code> ist bereits mit dem Sidecar verdrahtet; nichts zu tun.
viterex_deploy_activate_no_sidecar = Kein Sidecar zum Aktivieren vorhanden — bitte zuerst das Formular speichern.
viterex_deploy_rewrite_failed = <code>deploy.php</code> konnte nicht sicher umgeschrieben werden — der viterex-Markierungsblock scheint manipuliert oder die Prologue-/Host-Struktur wird nicht erkannt. Ein Backup wiederherstellen oder unvollständige Markierungen entfernen und es erneut versuchen.
viterex_deploy_write_failed = Schreiben in <code>deploy.php</code> fehlgeschlagen. Bitte Dateirechte im Projekt-Root prüfen.
viterex_deploy_saved = Deploy-Hosts in <code>deploy.config.php</code> gespeichert.
viterex_deploy_activated = <code>deploy.php</code> wurde so umgeschrieben, dass es das Sidecar verwendet. Backup unter <code>{0}</code>.
```

- [ ] **Step 3: Commit**

```bash
git add lang/en_gb.lang lang/de_de.lang
git commit -m "i18n(deploy): add ydeploy helper strings"
```

---

## Task 14: Manual verification in test install

**Files:** none (verification only)

The unit tests cover `Sidecar`, `DeployFile`, and `Page` helpers. The Redaxo-bound `pages/deploy.php` needs hands-on verification because it touches `rex_form`, CSRF, and i18n. Run this check before bumping the version.

- [ ] **Step 1: Symlink the addon into the test install**

If not already symlinked:

```bash
ls -la /Users/yvestorres/Herd/viterex-installer-default/src/addons/viterex_addon
```

If it's a real directory (not a symlink), back it up and replace with a symlink to the working tree:

```bash
mv /Users/yvestorres/Herd/viterex-installer-default/src/addons/viterex_addon \
   /Users/yvestorres/Herd/viterex-installer-default/src/addons/viterex_addon.bak.$(date +%Y%m%d-%H%M%S)
ln -s /Users/yvestorres/Repositories/viterex/viterex-addon \
      /Users/yvestorres/Herd/viterex-installer-default/src/addons/viterex_addon
```

- [ ] **Step 2: Re-install the addon in Redaxo backend**

In Redaxo backend → Addons, **uninstall** then **install** `viterex_addon` so the new `pages/deploy.php` and `package.yml` subpage register.

- [ ] **Step 3: Verify Flow A (auto-write sidecar from existing deploy.php)**

Before opening the page:

```bash
ls /Users/yvestorres/Herd/viterex-installer-default/deploy.config.php 2>&1
```

Expected: `No such file or directory`.

Open the new **ViteRex → Deploy** subpage in the Redaxo backend.

Expected on the page:
- Info banner: _"Sidecar … created from your current deploy.php values"_
- Info banner: _"Sidecar exists but deploy.php isn't wired up yet. Click Activate below."_
- Form pre-populated with the values from `viterex-installer-default/deploy.php` (one host: `viterex-installer-default`).

Confirm the file was written:

```bash
cat /Users/yvestorres/Herd/viterex-installer-default/deploy.config.php
```

Expected: `<?php\n\nreturn [\n    'repository' => 'git@github.com:user/repo.git',\n    'hosts' => [...]];\n` with the host data from the original `deploy.php`.

- [ ] **Step 4: Verify Flow B (form save updates sidecar only)**

Edit any field in the form (e.g., change the SSH user), click **Save deploy hosts**.

Expected: success banner, sidecar updated, `deploy.php` unchanged:

```bash
cat /Users/yvestorres/Herd/viterex-installer-default/deploy.config.php   # → new value
diff /Users/yvestorres/Herd/viterex-installer-default/deploy.php /tmp/deploy-before-save.php  # save a copy first if needed
```

A `deploy.config.php.bak.*` should be present in the project root.

- [ ] **Step 5: Verify Flow C (Activate rewrites deploy.php)**

Save a copy first:

```bash
cp /Users/yvestorres/Herd/viterex-installer-default/deploy.php /tmp/deploy-before-activate.php
```

Click **Activate** on the page.

Expected:
- Success banner with backup filename
- A `deploy.php.bak.*` file at project root
- `deploy.php` now contains the marker region:

```bash
grep -n "VITEREX:DEPLOY_CONFIG" /Users/yvestorres/Herd/viterex-installer-default/deploy.php
```

Expected: 2 lines — opening and closing markers.

- The custom `task('build:vendors', ...)` block, the `add('shared_dirs', ...)` call, and the `$isGit` branch must all still be present in `deploy.php` (verify with `grep`).

- [ ] **Step 6: Verify deployer dry-run still works**

```bash
cd /Users/yvestorres/Herd/viterex-installer-default && .tools/bin/dep config:hosts
```

Expected: lists the host(s) that were in the form (e.g., `viterex-installer-default`). No PHP errors.

If `config:hosts` doesn't exist on Deployer 7.5, try `.tools/bin/dep list` and pick a non-mutating task that prints config.

- [ ] **Step 7: Verify already-activated state**

Reload the **Deploy** page.

Expected: success banner _"Sidecar is active and wired into deploy.php"_. No "needs activation" banner. Click **Activate** again — should see _"deploy.php is already wired up to the sidecar; nothing to do."_

- [ ] **Step 8: Tidy up the test install (only if you replaced files)**

Restore the original `deploy.php` from the backup if you want a clean baseline for the next test:

```bash
cp /tmp/deploy-before-activate.php /Users/yvestorres/Herd/viterex-installer-default/deploy.php
rm /Users/yvestorres/Herd/viterex-installer-default/deploy.config.php
rm /Users/yvestorres/Herd/viterex-installer-default/deploy.config.php.bak.* 2>/dev/null
rm /Users/yvestorres/Herd/viterex-installer-default/deploy.php.bak.* 2>/dev/null
```

- [ ] **Step 9: No commit needed** — manual verification produces no code changes. If the verification surfaced a bug, fix it in the appropriate task and commit normally.

---

## Task 15: Documentation, version bump, and changelog

**Files:**
- Modify: `package.yml`
- Modify: `CHANGELOG.md`
- Modify: `README.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Bump `package.yml` version**

Find:

```yaml
version: '3.2.5'
```

Replace with:

```yaml
version: '3.3.0'
```

- [ ] **Step 2: Sync `package.json` version** (the `prebuild` hook does this automatically, but run it manually for the commit):

```bash
node scripts/sync-version.js
```

Verify:

```bash
grep '"version"' package.json
```

Expected: `"version": "3.3.0",`.

- [ ] **Step 3: Update `CHANGELOG.md`**

Add a new section at the top (above the previous most-recent entry):

```markdown
## 3.3.0

- **New:** ydeploy helper — backend page (ViteRex → Deploy, available when ydeploy is installed) for editing deployment hosts via a form. Values are persisted to `deploy.config.php` (a sidecar that `deploy.php` reads at deployer runtime). On first visit, the sidecar is auto-populated from any existing `deploy.php` values; clicking *Activate* rewrites `deploy.php` to require the sidecar (with a timestamped backup). Multi-host setups (e.g., stage + prod) supported. See README → "ydeploy helper".
```

- [ ] **Step 4: Update `README.md`**

Find a place near the top after the existing feature list / TOC and add a new section:

```markdown
## ydeploy helper

When the [ydeploy](https://github.com/yakamara/ydeploy) addon is installed, viterex_addon ships a **Deploy** subpage in the backend (ViteRex → Deploy). It lets you edit deployment hosts via a form instead of hand-editing `deploy.php`.

How it works:

- A sidecar file `deploy.config.php` at the project root holds the editable values (repository URL, list of hosts with name/hostname/port/user/stage/path).
- `deploy.php` requires the sidecar inside a clearly marked region:

  ```php
  // >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>
  $cfg = require __DIR__ . '/deploy.config.php';
  set('repository', $cfg['repository']);
  foreach ($cfg['hosts'] as $h) {
      host($h['name'])->setHostname($h['hostname'])
          ->setRemoteUser($h['user'])->setPort($h['port'])
          ->set('labels', ['stage' => $h['stage']])->setDeployPath($h['path']);
  }
  // <<< VITEREX:DEPLOY_CONFIG <<<
  ```

- **First visit:** the page tries to extract values from your current `deploy.php` and writes them into the sidecar (no `deploy.php` changes yet). Review the form and click **Activate** to rewrite `deploy.php` to use the sidecar (a `.bak.<timestamp>` backup is written first).

- **Subsequent saves:** only the sidecar is rewritten; `deploy.php` stays put. The deployer reads the new values on the next run.

- **Anything outside the marker region** is yours — custom tasks, `add('shared_dirs', ...)`, `add('clear_paths', ...)`, environment branches, etc. The helper never touches it.

- **Hand-edit the markers at your own risk.** If they end up partial or missing, the next Activate will refuse to rewrite and ask you to restore from a backup.

The sidecar is plain PHP returning an array — no parser or new dependency needed. Commit it (or `.gitignore` it — your call).
```

- [ ] **Step 5: Update `CLAUDE.md` roadmap**

In `viterex-addon/CLAUDE.md`, find the Roadmap section. Strike the v3.3 ydeploy item and update the SVG entry. Replace:

```
- **3.3**: Ydeploy helper: instead of manually editing `deploy.php`, if `ydeploy` is installed, add a backend form to edit deploy settings in a separate page or tab.
```

With:

```
- **3.3 (shipped)**: Ydeploy helper — backend form (ViteRex → Deploy) editing deploy hosts via a `deploy.config.php` sidecar that `deploy.php` reads at deployer runtime. See README → "ydeploy helper".
```

(Leave the v3.3 SVG-optimization entry above untouched.)

- [ ] **Step 6: Run the full test suite once**

```bash
./vendor/bin/phpunit
```

Expected: all tests pass (existing `OutputFilterTest`, `PreloadTest`, plus the new `Deploy/*Test`).

- [ ] **Step 7: Commit**

```bash
git add package.yml package.json CHANGELOG.md README.md CLAUDE.md
git commit -m "chore(3.3.0): bump version, document ydeploy helper"
```

- [ ] **Step 8: Tag the release** (only after the user signs off)

This is the user's call — do NOT tag without explicit approval. When approved:

```bash
git tag 3.3.0
git push origin main
git push origin 3.3.0
```

The `publish-to-redaxo.yml` workflow handles the rest (zip, GitHub release, MyREDAXO publish).

---

## Verification checklist (run before tagging)

- [ ] `./vendor/bin/phpunit` — all tests pass.
- [ ] `php -l pages/deploy.php` — no syntax errors.
- [ ] Manual verification (Task 14) — all 8 steps green.
- [ ] `package.yml` and `package.json` versions both `3.3.0`.
- [ ] `CHANGELOG.md` has a `3.3.0` section.
- [ ] `README.md` documents the new feature.
- [ ] `CLAUDE.md` roadmap reflects shipped status.
