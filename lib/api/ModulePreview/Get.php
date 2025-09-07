<?php

namespace FriendsOfRedaxo\ViteRex\ModulePreview\Api;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Psr\Cache\CacheItemPoolInterface;

use rex_addon;
use rex_api_function;
use rex_api_result;
use rex_path;
use rex_response;

class Get extends rex_api_function
{
  protected $published = true;
  private CacheItemPoolInterface $cache;
  protected const DEFAULT_TTL = 3600; // Default TTL for cache items (in seconds)

  public function execute()
  {
    /** @var rex_addon $addon */
    $addon = rex_addon::get('module_preview');

    $articleId = rex_get('article_id', 'int', 0);
    $cacheKey = rex_get('cache_key', 'string', "");

    $this->cache = new FilesystemAdapter("article-{$articleId}", self::DEFAULT_TTL, rex_path::frontend('cache/module_preview'));
    $cachedItem = $this->cache->getItem($cacheKey);

    if ($cachedItem->isHit()) {
      return $this->sendResponse($cachedItem->get());
    }

    return $this->sendResponse("", rex_response::HTTP_NOT_FOUND);
  }

  private function sendResponse(string $content, string $status = rex_response::HTTP_OK): rex_api_result
  {

    rex_response::cleanOutputBuffers();
    rex_response::setStatus($status);
    rex_response::sendContent($content, 'text/html');
    return new rex_api_result(true);
  }
}
