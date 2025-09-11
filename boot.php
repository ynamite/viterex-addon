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

use FriendsOfRedaxo\ViteRex\ModulePreview;

use rex;
use rex_addon;
use rex_addon_interface;
use rex_api_function;
use rex_backend_login;
use rex_developer_manager;
use rex_extension;
use rex_extension_point;
use rex_path;
use rex_view;

if (rex_addon::get('developer')->isAvailable()) {
    rex_developer_manager::setBasePath(rex_path::src());
}

new ViteRex();

/** @var rex_addon_interface $addon */
$addon = $this;

/**
 * Check if we are in production deployment mode and set robots meta tag accordingly.
 */
rex_extension::register('YREWRITE_SEO_TAGS', function (rex_extension_point $ep) {
    $tags = $ep->getSubject();
    if (!ViteRex::isProductionDeployment()) {
        $tags['robots'] = '<meta name="robots" content="noindex, nofollow" />';
    }
    $ep->setSubject($tags);
});

/**
 * Add ViteRex-Badge
 */

if (rex_backend_login::hasSession() && !ViteRex::isProductionDeployment()) {
    rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) use ($addon) {

        $version = $addon->getVersion();
        $content = $ep->getSubject();
        $script = '<script type="module" src="' . $addon->getAssetsUrl('ViteRexBadge.js') . '" id="viterex-badge-script" data-is-dev="' . (ViteRex::isDevMode() ? 'true' : 'false') . '" data-version="' . $version . '" data-rex-version="' . rex::getVersion() . '"></script>';
        $style = '<link rel="stylesheet" href="' . $addon->getAssetsUrl('ViteRexBadge.css') . '">';
        $content = str_ireplace('</body>', $script . $style . '</body>', $content);
        $ep->setSubject($content);
    });
}
/**
 * Add preview iframe to slice preview view in backend.
 */

if (rex::isBackend() && rex::getUser()) {
    rex_view::addJsFile($this->getAssetsUrl('ModulePreview.js'));
}
rex_api_function::register('module_preview_generate', ModulePreview\Api\Generate::class);
rex_extension::register('PACKAGES_INCLUDED', function () {

    rex_extension::register('SLICE_BE_PREVIEW', ModulePreview\Extension::register(...), rex_extension::LATE);
});
