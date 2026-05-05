<?php

namespace Ynamite\ViteRex\Svg;

interface OptimizerInterface
{
    /**
     * Optimize an SVG string. On any failure (malformed input, missing tooling,
     * etc.) implementations MUST return the original input unchanged so callers
     * can rely on a fail-open contract: a broken SVG continues to render as it
     * did before.
     */
    public function optimize(string $svg): string;
}
