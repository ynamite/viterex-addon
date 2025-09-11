<?php

namespace Ynamite\ViteRex\ModulePreview;

use rex_article_slice;
use rex_extension_point;
use rex_url;

class Extension
{
  public static function register(rex_extension_point $ep): void
  {
    $sliceData = $ep->getParams();
    $slice = rex_article_slice::getArticleSliceById($sliceData['slice_id']);
    $updateDate = $slice->getValue('updatedate');
    $endpoint = rex_url::backendController(array_merge(['rex-api-call' => 'module_preview_generate', 'updateDate' => $updateDate], $sliceData), false);
    $html = sprintf(
      '<div class="border-radius: 4px;"><iframe data-iframe-preview data-slice-id="%s" scrolling="no" loading="lazy"
src="%s" frameborder="0" style="overflow: hidden; width: %s; height: 200px; visibility: hidden;" onload="this.style.visibility = \'visible\'; this.closest(\'.panel-body\').querySelector(\'.rex-ajax-loader\')?.remove()"></iframe></div>',
      $sliceData['slice_id'],
      $endpoint,
      '100%',
    );
    $html .= '<div class="rex-visible rex-ajax-loader" style="position: absolute;">
    <div class="rex-ajax-loader-element" style="width: 100px; height: 100px; margin: -50px 0 0 -50px;"></div>
</div>';
    $ep->setSubject($html);
  }
}
