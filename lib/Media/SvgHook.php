<?php

namespace Ynamite\ViteRex\Media;

use rex_extension;
use rex_extension_point;
use rex_file;
use rex_path;
use Ynamite\ViteRex\Config;
use Ynamite\ViteRex\Server;
use Ynamite\ViteRex\Svg\OptimizerFactory;

/**
 * Optimizes SVGs uploaded or replaced via the Redaxo media pool.
 *
 * Engine pick is delegated to OptimizerFactory; the call site supplies the
 * deployment stage and the global toggle so the factory stays a pure resolver.
 *
 * IMPORTANT: the EP closure returns void. rex_extension::registerPoint treats
 * any non-null return as the new EP subject — for MEDIA_ADDED/UPDATED that
 * would clobber the success message and any subsequent listeners. The same
 * gotcha is documented around the reload-signal handler in boot.php.
 */
final class SvgHook
{
    public static function register(): void
    {
        $handler = static function (rex_extension_point $ep): void {
            if ($ep->getParam('type') !== 'image/svg+xml') {
                return;
            }
            $optimizer = OptimizerFactory::for(
                Server::getDeploymentStage(),
                Config::isEnabled('svg_optimize_enabled'),
            );
            if ($optimizer === null) {
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
            $optimized = $optimizer->optimize($svg);
            if ($optimized !== $svg) {
                rex_file::put($path, $optimized);
            }
        };
        foreach (['MEDIA_ADDED', 'MEDIA_UPDATED'] as $ep) {
            rex_extension::register($ep, $handler);
        }
    }
}
