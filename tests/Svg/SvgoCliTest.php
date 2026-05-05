<?php

namespace Ynamite\ViteRex\Tests\Svg;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Svg\SvgoCli;

final class SvgoCliTest extends TestCase
{
    public function testIsAvailableReturnsBool(): void
    {
        // Either true or false is acceptable depending on test env;
        // we just assert the contract: a stable boolean.
        $first  = SvgoCli::isAvailable();
        $second = SvgoCli::isAvailable();
        $this->assertIsBool($first);
        $this->assertSame($first, $second, 'isAvailable() should be cached/stable within a request');
    }

    public function testEmptyStringReturnedUnchanged(): void
    {
        // Even if svgo isn't available, the optimize() call should be a no-op
        // for empty input (no shell-out attempted).
        $this->assertSame('', (new SvgoCli())->optimize(''));
    }

    public function testOptimizeStripsScriptWhenSvgoAvailable(): void
    {
        if (!SvgoCli::isAvailable()) {
            $this->markTestSkipped('svgo binary not available on PATH');
        }
        $svg = file_get_contents(__DIR__ . '/../fixtures/svg/with-script.svg');
        $this->assertIsString($svg);
        $optimized = (new SvgoCli())->optimize($svg);
        $this->assertStringNotContainsString('<script', $optimized);
        $this->assertStringContainsString('<circle', $optimized);
    }

    /**
     * Pin the canonical SVGO config file's existence and shape. Both the Vite
     * plugin (via `import`) and `SvgoCli` (via `--config`) consume this file
     * directly — if it's renamed or moved, both runtimes break in lock-step
     * and we want a fast failure here, not a runtime surprise.
     */
    public function testCanonicalConfigFileExists(): void
    {
        $configPath = __DIR__ . '/../../assets/svgo-config.mjs';
        $this->assertFileExists($configPath);
        $contents = file_get_contents($configPath);
        $this->assertIsString($contents);
        $this->assertStringContainsString('preset-default', $contents);
        $this->assertStringContainsString('removeScripts', $contents);
        $this->assertStringContainsString('export default', $contents);
    }
}
