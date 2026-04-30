<?php

namespace Ynamite\ViteRex\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\OutputFilter;

final class OutputFilterTest extends TestCase
{
    /** @var Closure(?array<int,string>): string */
    private Closure $stubBlock;

    /** @var list<?array<int,string>> */
    private array $capturedEntries;

    protected function setUp(): void
    {
        $this->capturedEntries = [];
        $this->stubBlock = function (?array $entries): string {
            $this->capturedEntries[] = $entries;
            return '<!--BLOCK-->';
        };
    }

    public function testReplacesSingleRexViteInHead(): void
    {
        $html = '<html><head>REX_VITE</head><body>hi</body></html>';

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame('<html><head><!--BLOCK--></head><body>hi</body></html>', $out);
        $this->assertSame([null], $this->capturedEntries);
    }

    public function testForwardsParsedSrcAttribute(): void
    {
        $html = '<html><head>REX_VITE[src="x.js"]</head></html>';

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame('<html><head><!--BLOCK--></head></html>', $out);
        $this->assertSame([['x.js']], $this->capturedEntries);
    }

    public function testLeavesBodyOccurrencesUntouched(): void
    {
        $html = '<html><head>REX_VITE</head>'
              . '<body><pre>REX_VITE</pre><code>REX_VITE[src="a.js"]</code></body></html>';

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame(
            '<html><head><!--BLOCK--></head>'
            . '<body><pre>REX_VITE</pre><code>REX_VITE[src="a.js"]</code></body></html>',
            $out,
        );
        $this->assertSame([null], $this->capturedEntries);
    }

    public function testReplacesOnlyFirstWhenMultipleInHead(): void
    {
        $html = "<html><head>REX_VITE\nREX_VITE[src=\"x.js\"]</head><body></body></html>";

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame(
            "<html><head><!--BLOCK-->\nREX_VITE[src=\"x.js\"]</head><body></body></html>",
            $out,
        );
        $this->assertSame([null], $this->capturedEntries);
    }

    public function testAutoInsertsBeforeClosingHeadWhenNoPlaceholder(): void
    {
        $html = '<html><head><title>x</title></head><body></body></html>';

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame(
            "<html><head><title>x</title><!--BLOCK-->\n</head><body></body></html>",
            $out,
        );
        $this->assertSame([null], $this->capturedEntries);
    }

    public function testReturnsContentUnchangedWhenNoHeadElement(): void
    {
        $html = '<html><body>REX_VITE</body></html>';

        $out = OutputFilter::rewriteHtmlWithBlock($html, $this->stubBlock);

        $this->assertSame($html, $out);
        $this->assertSame([], $this->capturedEntries);
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        $out = OutputFilter::rewriteHtmlWithBlock('', $this->stubBlock);

        $this->assertSame('', $out);
    }
}
