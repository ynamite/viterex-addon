<?php
/*
 * Redaxo CMS Vite integration
 *
 *  @author      ynamite @ GitHub <https://github.com/ynamite/viterex-addon>
 *
 *  For copyright and license information, please view the LICENSE.md
 *  file that was distributed with this source code.
 */

use Ynamite\ViteRex\Badge;
use Ynamite\ViteRex\OutputFilter;
use Ynamite\ViteRex\Server;
use Ynamite\ViteRex\Structure;

if ('' !== (string) rex_request::request('viterex_clear_cache', 'string', '')) {
    if (rex_backend_login::hasSession() && rex_csrf_token::factory('viterex_badge')->isValid()) {
        rex_delete_cache();
        header('Content-Type: application/json', true, 200);
        echo json_encode(['ok' => true]);
    } else {
        header('Content-Type: application/json', true, 403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
    }
    exit;
}

if (rex_addon::get('developer')->isAvailable() && Structure::detect()->getName() === 'modern') {
    rex_developer_manager::setBasePath(rex_path::src());
}

/** @var rex_addon_interface $addon */
$addon = $this;

rex_extension::register('YREWRITE_SEO_TAGS', static function (rex_extension_point $ep): void {
    $tags = $ep->getSubject();
    if (!Server::isProductionDeployment()) {
        $tags['robots'] = '<meta name="robots" content="noindex, nofollow" />';
    }
    $ep->setSubject($tags);
});

rex_extension::register(
    'OUTPUT_FILTER',
    [OutputFilter::class, 'register'],
    rex_extension::EARLY,
);

if (rex_backend_login::hasSession() && !Server::isProductionDeployment() && !Server::isStagingDeployment()) {
    rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep): void {
        $content = $ep->getSubject();
        if (!is_string($content)) {
            return;
        }
        $badge = Badge::get();
        $content = str_ireplace('</body>', $badge . '</body>', $content);
        $ep->setSubject($content);
    });
}
