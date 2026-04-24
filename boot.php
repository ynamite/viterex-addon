<?php
/*
 * Redaxo CMS VITE JIT development
 * Inspired by https://github.com/andrefelipe/vite-php-setup
 *
 *  @author      ynamite @ GitHub <https://github.com/ynamite/viterex>
 *
 *  For copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 *
 */

namespace Ynamite\ViteRex;

use rex_addon;
use rex_addon_interface;
use rex_backend_login;
use rex_developer_manager;
use rex_extension;
use rex_extension_point;
use rex_path;

if (rex_addon::get('developer')->isAvailable()) {
    rex_developer_manager::setBasePath(rex_path::src());
}

/** @var rex_addon_interface $addon */
$addon = $this;

/**
 * Check if we are in production deployment mode and set robots meta tag accordingly.
 */
rex_extension::register('YREWRITE_SEO_TAGS', function (rex_extension_point $ep) {
    $tags = $ep->getSubject();
    if (!Server::isProductionDeployment()) {
        $tags['robots'] = '<meta name="robots" content="noindex, nofollow" />';
    }
    $ep->setSubject($tags);
});

/**
 * Add ViteRex-Badge
 */
if (rex_backend_login::hasSession() && !Server::isProductionDeployment() && !Server::isStagingDeployment()) {
    rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) use ($addon) {
        $content = $ep->getSubject();
        $badge = Badge::get();
        $content = str_ireplace('</body>', $badge . '</body>', $content);
        $ep->setSubject($content);
    });
}
