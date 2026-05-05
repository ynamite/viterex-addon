<?php

namespace Ynamite\ViteRex\Media;

use rex_extension;
use rex_extension_point;
use rex_file;
use rex_path;
use Ynamite\ViteRex\Config;
use Ynamite\ViteRex\Server;
use Ynamite\ViteRex\Svg\PhpOptimizer;

/**
 * Optimizes SVGs uploaded or replaced via the Redaxo media pool — but only
 * in staging/prod. In dev, the hook is a no-op: dev devs don't want a
 * shell-out fired on every test upload, and the Vite build (or the
 * `viterex:optimize-svgs` console command) will sweep the media pool
 * anyway when they're ready to clean up.
 *
 * The engine is always `PhpOptimizer` here because the production runtime
 * isn't expected to have Node available — and even if it did, shelling out
 * per-upload is the wrong tradeoff. The `OptimizerFactory` indirection that
 * v3.3.0 had was deleted along with the dev branch (the only caller that
 * needed engine selection at runtime).
 *
 * IMPORTANT: the EP closure returns void. `rex_extension::registerPoint`
 * treats any non-null return as the new EP subject — for MEDIA_ADDED/UPDATED
 * that would clobber the success message and any subsequent listeners. Same
 * gotcha is documented around the reload-signal handler in `boot.php`.
 */
final class SvgHook
{
    public static function register(): void
    {
        $handler = static function (rex_extension_point $ep): void {
            if (Server::getDeploymentStage() === 'dev') {
                return;
            }
            if ($ep->getParam('type') !== 'image/svg+xml') {
                return;
            }
            if (!Config::isEnabled('svg_optimize_enabled')) {
                return;
            }
            $filename = (string) $ep->getParam('filename');
            if ($filename === '') {
                return;
            }
            $path = rex_path::media($filename);
            $svg = rex_file::get($path);
            if (!is_string($svg) || $svg === '') {
                return;
            }
            $optimized = (new PhpOptimizer())->optimize($svg);
            if ($optimized !== $svg) {
                rex_file::put($path, $optimized);
            }
        };
        foreach (['MEDIA_ADDED', 'MEDIA_UPDATED'] as $ep) {
            rex_extension::register($ep, $handler);
        }
    }
}
