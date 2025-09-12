<?php

namespace Ynamite\ViteRex\Critical\Api;

use Exception;

use rex_addon;
use rex_api_function;
use rex_api_exception;
use rex_api_result;
use rex_response;
use rex_sql;
use rex_sql_exception;

use function rex_getUrl;

class Routes extends rex_api_function
{

  protected string $domainUrl;
  protected $published = true;

  public bool $cacheActive = true;

  /**
   * @return rex_api_result
   * @throws rex_api_exception
   * @throws Exception
   */
  public function execute(): rex_api_result
  {

    $secret = rex_get('secret', 'string', ''); // = critical_mass
    if ($secret !== 'critical_mass') {
      exit();
    }

    $data = $this->getRoutes();

    return $this->sendResponse($data);
  }

  /**
   * Fetch all possible active routes in all languages
   * from rex_article and rex_url_generator_url

   * @return array<array{id: int, clang_id: int, url: string}>
   * @throws rex_sql_exception
   * @throws Exception
   */
  public function getRoutes(): array
  {
    $sql = rex_sql::factory();
    $sql->setQuery('SELECT `pid`, `id`, `clang_id` FROM rex_article WHERE `status` = 1 and `revision` = 0');
    $result = $sql->getArray();

    $routes = [];
    foreach ($result as $row) {
      $url = rex_getUrl($row['id'], $row['clang_id']);
      $slug = parse_url($url, PHP_URL_PATH);
      $routes[] = [
        'id' => $row['pid'],
        'article_id' => $row['id'],
        'clang_id' => $row['clang_id'],
        'url' => $slug
      ];
    }

    $redaxo_url = rex_addon::get('url');
    if ($redaxo_url->isAvailable()) {
      $sql->reset();
      $sql->setQuery('SELECT `id`, `article_id`, `url`, `clang_id` FROM rex_url_generator_url');
      $result = $sql->getArray();
      foreach ($result as $row) {
        $slug = parse_url($row['url'], PHP_URL_PATH);
        $routes[] = [
          'id' => $row['id'],
          'article_id' => $row['article_id'],
          'clang_id' => $row['clang_id'],
          'url' => $slug
        ];
      }
    }
    return $routes;
  }

  /**
   * Prepare the output for the response.
   * adds a full html structure and JS/CSS assets
   * @param string $html
   * 
   * @return string
   */

  /**
   * Sends the response with the given content and status.
   * @param string $content
   * @param string $status
   * 
   * @return rex_api_result
   */
  private function sendResponse(array $data, string $status = rex_response::HTTP_OK): rex_api_result
  {

    rex_response::setStatus($status);
    rex_response::sendJson($data);
    return new rex_api_result(true);
  }
}
