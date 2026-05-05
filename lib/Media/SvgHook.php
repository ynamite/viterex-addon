<?php

namespace Ynamite\ViteRex\Media;

use rex_addon;
use rex_extension;
use rex_extension_point;
use rex_file;
use rex_path;
use Ynamite\ViteRex\Config;
use Ynamite\ViteRex\Server;
use Ynamite\ViteRex\Svg\PhpOptimizer;

/**
 * Optimizes SVGs uploaded or replaced via the Redaxo media pool. The hook
 * is a no-op in confirmed-dev environments (devs don't want a shell-out
 * fired on every test upload — the Vite build or the
 * `viterex:optimize-svgs` console command will sweep the media pool when
 * they're ready). Anywhere else — staging, prod, or any install where we
 * can't confirm dev — the hook runs and optimizes.
 *
 * "Confirmed dev" means: ydeploy is installed AND `getDeploymentStage()`
 * returns 'dev'. Without ydeploy the stage helper falls through to 'dev'
 * by default (see `Server::getDeploymentStage()`), so a bare
 * `=== 'dev'` check would silently skip optimization on every ydeploy-less
 * production install — including the `<script>` / `on*` stripping that's
 * the security side-effect of the optimizer pass. We require ydeploy
 * presence so absence of stage-detection defaults to running the optimizer
 * (the safe choice) rather than skipping it.
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
            // Only short-circuit when we can CONFIRM dev: ydeploy installed
            // AND it reports dev. Without ydeploy, stage falls through to
            // 'dev' by default — treating that as real dev would skip the
            // optimizer (and its <script>/on* stripping) on prod installs
            // that haven't installed ydeploy.
            if (
                Server::getDeploymentStage() === 'dev'
                && rex_addon::get('ydeploy')->isAvailable()
            ) {
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
