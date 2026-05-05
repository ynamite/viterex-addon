<?php

namespace Ynamite\ViteRex\Svg;

use MathiasReker\PhpSvgOptimizer\Service\Facade\SvgOptimizerFacade;
use Throwable;

/**
 * Pure-PHP optimizer backed by `mathiasreker/php-svg-optimizer`. Used in
 * staging/prod (where Node isn't assumed) and as the dev-stage fallback when
 * SVGO shell-out is unavailable.
 */
final class PhpOptimizer implements OptimizerInterface
{
    public function optimize(string $svg): string
    {
        if ($svg === '') {
            return '';
        }
        try {
            $result = SvgOptimizerFacade::fromString($svg)
                ->withAllRules()
                ->optimize()
                ->getContent();
            return $result === '' ? $svg : $result;
        } catch (Throwable) {
            return $svg;
        }
    }
}
