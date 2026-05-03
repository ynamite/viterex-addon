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
