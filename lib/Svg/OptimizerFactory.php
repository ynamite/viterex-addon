<?php

namespace Ynamite\ViteRex\Svg;

/**
 * Resolves the appropriate SVG optimizer for the current deployment stage.
 *
 * Engine selection rules:
 *   - Disabled globally → null (caller short-circuits, no optimization runs).
 *   - dev stage + SVGO available → SvgoCli (matches what `npm run build` will
 *     produce; consistent dev/prod artifact for source SVGs).
 *   - dev stage + no SVGO → PhpOptimizer (fallback; safe-clean still happens).
 *   - staging/prod → PhpOptimizer (no Node assumed at runtime; only the media
 *     pool runtime path uses this — built assets are already optimized).
 *
 * Pure resolver: takes all inputs as parameters so it's trivially testable
 * without Redaxo runtime. The single Config read happens at the call site.
 */
final class OptimizerFactory
{
    public static function for(string $stage, bool $enabled, ?bool $svgoAvailable = null): ?OptimizerInterface
    {
        if (!$enabled) {
            return null;
        }
        $svgoAvailable ??= SvgoCli::isAvailable();
        if ($stage === 'dev' && $svgoAvailable) {
            return new SvgoCli();
        }
        return new PhpOptimizer();
    }
}
