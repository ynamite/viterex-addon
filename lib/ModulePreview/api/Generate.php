<?php

namespace Ynamite\ViteRex\ModulePreview\Api;

use Exception;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Psr\Cache\CacheItemPoolInterface;

use rex_addon;
use rex_addon_interface;
use rex_api_function;
use rex_api_exception;
use rex_api_result;
use rex_article_content;
use rex_clang;
use rex_file;
use rex_response;

use Ynamite\MassifSettings;
use Ynamite\ViteRex\ViteRex;

class Generate extends rex_api_function
{

  private rex_addon_interface $addon;
  private CacheItemPoolInterface $cache;
  private int $articleId = 0;
  private int $clangId = 0;
  private int $sliceId = 0;
  private int $updateDate = 0;
  private int $revision = 0;

  protected const DEFAULT_TTL = 3600; // Default TTL for cache items (in seconds)
  protected $published = false;

  public bool $cacheActive = true;

  /**
   * @return rex_api_result
   * @throws rex_api_exception
   * @throws Exception
   */
  public function execute(): rex_api_result
  {

    $this->articleId = rex_get('article_id', 'int', 0);
    $this->clangId = rex_get('clang', 'int', 0);
    $this->sliceId = rex_get('slice_id', 'int', 0);
    $this->updateDate = rex_get('updateDate', 'int', 0);
    $this->revision = rex_get('revision', 'int', 0);

    $this->cacheActive = !ViteRex::isDevMode();

    $html = $this->getContent();

    return $this->sendResponse($html);
  }

  /**
   * Fetch the slice data from the database.
   * @param int $ttl Time to live for cache (in seconds)
   * 
   * @return string
   * @throws rex_api_exception
   * @throws Exception
   */
  public function getContent($ttl = self::DEFAULT_TTL): string
  {
    /** @var rex_addon_interface $addon */
    $this->addon = rex_addon::get('viterex');

    $this->cache = new FilesystemAdapter("article-{$this->articleId}", self::DEFAULT_TTL, $this->addon->getCachePath());
    $cacheKey = md5($this->articleId . $this->sliceId . $this->updateDate . $this->revision);
    $cachedItem = $this->cache->getItem($cacheKey);

    if (!$cachedItem->isHit() || !$this->cacheActive) {
      $articleContent = new rex_article_content();
      $articleContent->setArticleId($this->articleId);
      $articleContent->setClang($this->clangId);
      $articleContent->setSliceRevision($this->revision);
      $sliceContent = $articleContent->getSlice($this->sliceId);
      $content = $this->prepareOutput($sliceContent);
      $cachedItem->set($content);
      $cachedItem->expiresAfter(self::DEFAULT_TTL);
      $this->cache->save($cachedItem);
    } else {
      $content = $cachedItem->get();
    }

    return $content;
  }

  /**
   * Prepare the output for the response.
   * adds a full html structure and JS/CSS assets
   * @param string $html
   * 
   * @return string
   */
  private function prepareOutput(string $html): string
  {
    $html = MassifSettings\Utils::replaceStrings($html);
    $clang = rex_clang::get($this->clangId);
    $langCode = $clang ? $clang->getCode() : 'en';
    $assets = ViteRex::getAssets();
    $posterJsFileContent = rex_file::get($this->addon->getAssetsPath('ModulePreviewPoster.js'));
    $posterJsFileContent = str_replace('VITEREX_PLACEHOLDER_SLICE_ID', $this->sliceId, $posterJsFileContent);

    $htmlTemplate = '<!DOCTYPE html>
<html lang="' . $langCode . '" class="[scrollbar-gutter:_auto]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    ' . $assets['preload'] . $assets['criticalCSS'] . $assets['css'] . '
</head>
<body class="bg-body min-h-0">
    <div id="viterex-slice" class="pointer-events-none">
    ' . $html . '
    </div>
    ' . $assets['js'] . '
    <script>
' . $posterJsFileContent . '
    </script> 
</body>
</html>';
    return $htmlTemplate;
  }

  /**
   * Sends the response with the given content and status.
   * @param string $content
   * @param string $status
   * 
   * @return rex_api_result
   */
  private function sendResponse(string $content, string $status = rex_response::HTTP_OK): rex_api_result
  {

    rex_response::setStatus($status);
    exit($content);
    return new rex_api_result(true);
  }
}
