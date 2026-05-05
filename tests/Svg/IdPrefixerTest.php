<?php

namespace Ynamite\ViteRex\Tests\Svg;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Svg\IdPrefixer;

final class IdPrefixerTest extends TestCase
{
    public function testPrefixesIdAttributes(): void
    {
        $svg = '<svg><path id="foo"/><circle id="bar"/></svg>';
        $out = (new IdPrefixer())->prefix($svg, 'p');
        $this->assertStringContainsString('id="p-foo"', $out);
        $this->assertStringContainsString('id="p-bar"', $out);
    }

    public function testPrefixesClassAttributes(): void
    {
        $svg = '<svg><path class="cls-1"/><circle class="cls-1 cls-2"/></svg>';
        $out = (new IdPrefixer())->prefix($svg, 'p');
        $this->assertStringContainsString('class="p-cls-1"', $out);
        $this->assertStringContainsString('class="p-cls-1 p-cls-2"', $out);
    }

    public function testRewritesUrlReferences(): void
    {
        $svg = '<svg><defs><linearGradient id="grad"/></defs><path fill="url(#grad)"/></svg>';
        $out = (new IdPrefixer())->prefix($svg, 'p');
        $this->assertStringContainsString('id="p-grad"', $out);
        $this->assertStringContainsString('fill="url(#p-grad)"', $out);
    }

    public function testRewritesUseReferences(): void
    {
        $svg = '<svg><defs><symbol id="ic"/></defs><use href="#ic"/><use xlink:href="#ic"/></svg>';
        $out = (new IdPrefixer())->prefix($svg, 'p');
        $this->assertStringContainsString('id="p-ic"', $out);
        $this->assertStringContainsString('href="#p-ic"', $out);
        $this->assertStringContainsString('xlink:href="#p-ic"', $out);
    }

    public function testRewritesStyleBlockClassSelectors(): void
    {
        $svg = '<svg><style>.cls-1{fill:red}.cls-2{fill:blue}</style><path class="cls-1"/></svg>';
        $out = (new IdPrefixer())->prefix($svg, 'p');
        $this->assertStringContainsString('.p-cls-1', $out);
        $this->assertStringContainsString('.p-cls-2', $out);
        $this->assertStringContainsString('class="p-cls-1"', $out);
    }

    public function testRewritesStyleBlockIdSelectorsWhenIdExists(): void
    {
        $svg = '<svg><style>#foo{fill:red}</style><path id="foo"/></svg>';
        $out = (new IdPrefixer())->prefix($svg, 'p');
        $this->assertStringContainsString('#p-foo', $out);
        $this->assertStringContainsString('id="p-foo"', $out);
    }

    /**
     * Hex colours like `#fff` look syntactically identical to id selectors
     * — distinguish them by checking against the actual id-attribute set
     * collected from the document.
     */
    public function testDoesNotPrefixHexColorsInStyle(): void
    {
        $svg = '<svg><style>.cls-1{fill:#fff;color:#abc}</style><path class="cls-1"/></svg>';
        $out = (new IdPrefixer())->prefix($svg, 'p');
        $this->assertStringContainsString('#fff', $out);
        $this->assertStringContainsString('#abc', $out);
        $this->assertStringNotContainsString('#p-fff', $out);
    }

    public function testLeavesFullUrlsAlone(): void
    {
        $svg = '<svg><a href="https://example.com/page#anchor"><circle/></a></svg>';
        $out = (new IdPrefixer())->prefix($svg, 'p');
        $this->assertStringContainsString('href="https://example.com/page#anchor"', $out);
    }

    public function testEmptyInputUnchanged(): void
    {
        $this->assertSame('', (new IdPrefixer())->prefix('', 'p'));
    }

    public function testEmptyPrefixIsNoOp(): void
    {
        $svg = '<svg><path id="foo" class="cls-1"/></svg>';
        $this->assertSame($svg, (new IdPrefixer())->prefix($svg, ''));
    }

    public function testOptOutCommentDetected(): void
    {
        $prefixer = new IdPrefixer();
        $this->assertTrue($prefixer->isOptedOut('<svg><!-- viterex:no-prefix --><path/></svg>'));
        $this->assertTrue($prefixer->isOptedOut('<svg><!--viterex:no-prefix--><path/></svg>'));
        $this->assertFalse($prefixer->isOptedOut('<svg><!-- something else --><path/></svg>'));
        $this->assertFalse($prefixer->isOptedOut('<svg><path/></svg>'));
    }

    public function testStablePrefixDerivation(): void
    {
        $p = new IdPrefixer();
        $this->assertSame('viterex-img-icon-foo', $p->deriveStablePrefix('img/icon-foo.svg'));
        $this->assertSame('viterex-logo', $p->deriveStablePrefix('logo.svg'));
        $this->assertSame('viterex-img-brand-logo-2', $p->deriveStablePrefix('img/brand/logo-2.svg'));
        $this->assertSame('viterex-svg', $p->deriveStablePrefix('___.svg'));
    }

    /**
     * The headline scenario: two Figma-exported SVGs both carrying
     * `.cls-1` get scoped under different prefixes so their `<style>`
     * rules can't bleed across.
     */
    public function testTwoSvgsDoNotCollide(): void
    {
        $svgA = '<svg><style>.cls-1{fill:red}</style><path class="cls-1"/></svg>';
        $svgB = '<svg><style>.cls-1{fill:blue}</style><path class="cls-1"/></svg>';
        $p = new IdPrefixer();
        $outA = $p->prefix($svgA, 'a');
        $outB = $p->prefix($svgB, 'b');
        $this->assertStringContainsString('.a-cls-1{fill:red}', $outA);
        $this->assertStringContainsString('class="a-cls-1"', $outA);
        $this->assertStringContainsString('.b-cls-1{fill:blue}', $outB);
        $this->assertStringContainsString('class="b-cls-1"', $outB);
    }
}
