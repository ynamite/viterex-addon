<?php

namespace Ynamite\ViteRex\Tests\Svg;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Svg\PhpOptimizer;

final class PhpOptimizerTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../fixtures/svg/' . $name;
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail("Missing fixture: {$path}");
        }
        return $contents;
    }

    public function testStripsScriptTags(): void
    {
        $optimized = (new PhpOptimizer())->optimize($this->fixture('with-script.svg'));
        $this->assertStringNotContainsString('<script', $optimized);
        $this->assertStringNotContainsString('alert', $optimized);
        $this->assertStringContainsString('<circle', $optimized);
    }

    public function testStripsEventHandlerAttributes(): void
    {
        $optimized = (new PhpOptimizer())->optimize($this->fixture('with-handlers.svg'));
        $this->assertStringNotContainsString('onload', $optimized);
        $this->assertStringNotContainsString('onclick', $optimized);
        $this->assertStringNotContainsString('alert', $optimized);
        $this->assertStringContainsString('<circle', $optimized);
    }

    public function testReducesBloatedSvgSize(): void
    {
        $input  = $this->fixture('bloated.svg');
        $output = (new PhpOptimizer())->optimize($input);

        $this->assertLessThan(strlen($input), strlen($output));
        $this->assertStringNotContainsString('<?xml', $output);
        $this->assertStringNotContainsString('<!DOCTYPE', $output);
        $this->assertStringNotContainsString('<title>', $output);
        $this->assertStringNotContainsString('<desc>', $output);
        $this->assertStringNotContainsString('<metadata>', $output);
        // path data must survive
        $this->assertMatchesRegularExpression('/<path[^>]*d="/', $output);
    }

    public function testMalformedSvgReturnedUnchanged(): void
    {
        $input  = $this->fixture('malformed.svg');
        $output = (new PhpOptimizer())->optimize($input);
        $this->assertSame($input, $output);
    }

    public function testIdempotentOnAlreadyOptimizedSvg(): void
    {
        $optimizer = new PhpOptimizer();
        $first  = $optimizer->optimize($this->fixture('clean.svg'));
        $second = $optimizer->optimize($first);
        $this->assertSame($first, $second);
    }

    public function testEmptyStringReturnedUnchanged(): void
    {
        $this->assertSame('', (new PhpOptimizer())->optimize(''));
    }
}
