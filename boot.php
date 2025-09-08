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

use FriendsOfRedaxo\ViteRex\ModulePreview\Api\Generate;
use FriendsOfRedaxo\ViteRex\ModulePreview\Api\Get;

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
rex_api_function::register('module_preview_generate', Generate::class);
rex_extension::register('PACKAGES_INCLUDED', function () {

    rex_extension::register('SLICE_BE_PREVIEW', function (rex_extension_point $ep) {
        $sliceData = $ep->getParams();
        $slice = rex_article_slice::getArticleSliceById($sliceData['slice_id']);
        $updateDate = $slice->getValue('updatedate');
        $endpoint = rex_url::backendController(array_merge(['rex-api-call' => 'module_preview_generate', 'updateDate' => $updateDate], $sliceData), false);
        $html = sprintf(
            '<iframe data-iframe-preview data-slice-id="%s" scrolling="no"
src="%s" frameborder="0" style="border-radius: 4px; overflow: hidden; width: %s" onload="this.nextElementSibling.remove()"></iframe>',
            $sliceData['slice_id'],
            $endpoint,
            '100%',
        );
        $html .= '<div class="rex-visible rex-ajax-loader" style="position: absolute;">
    <div class="rex-ajax-loader-element" style="width: 100px; height: 100px; margin: -50px 0 0 -50px;"></div>
</div>';
        $ep->setSubject($html);
    }, rex_extension::LATE);
});
