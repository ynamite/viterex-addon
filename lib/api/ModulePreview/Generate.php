<?php

namespace FriendsOfRedaxo\ViteRex\ModulePreview\Api;

use Exception;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Psr\Cache\CacheItemPoolInterface;

use rex;
use rex_addon;
use rex_api_function;
use rex_api_exception;
use rex_api_result;
use rex_article_content;
use rex_clang;
use rex_response;
use rex_sql;
use rex_yrewrite;

use Ynamite\ViteRex\ViteRex;

class Generate extends rex_api_function
{

  private CacheItemPoolInterface $cache;
  private array $sliceData;
  private ?array $slice = null;
  private int $articleId = 0;
  private int $clangId = 0;
  private int $ctypeId = 0;
  private int $moduleId = 0;
  private int $sliceId = 0;
  private int $updateDate = 0;
  private int $revision = 0;

  protected const DEFAULT_TTL = 3600; // Default TTL for cache items (in seconds)
  protected string $domainUrl;
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
    $this->ctypeId = rex_get('ctype', 'int', 0);
    $this->moduleId = rex_get('module_id', 'int', 0);
    $this->sliceId = rex_get('slice_id', 'int', 0);
    $this->updateDate = rex_get('updateDate', 'int', 0);
    $this->revision = rex_get('revision', 'int', 0);

    $html = $this->getContent();

    // $this->domainUrl = rex_yrewrite::getCurrentDomain()->getUrl();
    // $url = "@preview/?sliceId={$this->sliceData['slice_id']}&updateDate={$this->sliceData['update_date']}";

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
    /** @var rex_addon $addon */
    $addon = rex_addon::get('module_preview');

    $this->cache = new FilesystemAdapter("article-{$this->articleId}", self::DEFAULT_TTL, $addon->getCachePath());
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
    $clang = rex_clang::get($this->clangId);
    $langCode = $clang ? $clang->getCode() : 'en';
    $assets = ViteRex::getAssets();

    $htmlTemplate = '<!DOCTYPE html>
<html lang="' . $langCode . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    ' . $assets['preload'] . $assets['criticalCSS'] . $assets['css'] . '
</head>
<body class="!min-h-0">
    ' . $html . $assets['js'] . '
    <script>
      function sendHeight() {
        const height = document.body.scrollHeight;
        parent.postMessage({ type: "resize", id: ' . $this->sliceId . ', height }, "*");
      }

      window.addEventListener("load", sendHeight);
      window.addEventListener("resize", sendHeight);
      setInterval(sendHeight, 1000); // send height every second
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
