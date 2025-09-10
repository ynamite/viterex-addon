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
use rex_api_function;
use rex_article_slice;
use rex_developer_manager;
use rex_extension;
use rex_extension_point;
use rex_path;
use rex_url;
use rex_view;

if (rex_addon::get('developer')->isAvailable()) {
    rex_developer_manager::setBasePath(rex_path::src());
}

new ViteRex();

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
 * Add preview iframe to slice preview view in backend.
 */
if (rex::isBackend() && rex::getUser()) {
    rex_view::addJsFile($this->getAssetsUrl('ModulePreview.js'));
}
rex_api_function::register('module_preview_generate', ModulePreview\Api\Generate::class);
rex_extension::register('PACKAGES_INCLUDED', function () {

    rex_extension::register('SLICE_BE_PREVIEW', ModulePreview\Extension::register(...), rex_extension::LATE);
});
