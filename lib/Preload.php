<?php
/*
 *  Class with utility functions for loading scripts in templates
 */

namespace Ynamite\ViteRex;

use rex_finder;

use function strtolower;

class Preload
{
  private static ?self $instance = null;

  private string $buildPath;
  private string $buildUrl;
  private string $entryPoint;
  private bool $isDev;
  private array $manifest = [];

  public function __construct()
  {
    $server = Server::factory();

    $this->buildUrl = $server->getValue('buildUrl');
    $this->buildPath = $server->getValue('buildPath');
    $this->entryPoint = $server->getValue('entryPoint');
    $this->manifest = $server->getValue('manifest');

    $this->isDev = $server->isDevMode();
  }

  public static function factory(): self
  {
    if (self::$instance) {
      return self::$instance;
    }

    return self::$instance = new self();
  }


  /**
   * Get preload links for webfonts
   * 
   * @return string
   */
  public function getPreloadEntries(): string
  {
    $preloadArray = [];

    if ($this->isDev) {
      foreach (rex_finder::factory(Server::getAssetsPath() . 'fonts')->filesOnly()->sort() as $file) {
        $preloadArray[] = '<link rel="preload" href="' . Server::getAssetsUrl() . 'fonts/' . $file->getFilename() . '" as="font" type="font/' . $file->getExtension() . '" crossorigin>';
      }
    } else {
      $entryPoint = trim($this->entryPoint, '/');
      $entry = $this->manifest[$entryPoint] ?? null;
      if (!$entry) {
        return '';
      }

      // preload other imports
      $preloadArray = [...$preloadArray, ...$this->getPreloadEntry($entry)];
      $preloadArray = [...$preloadArray, ...$this->getPreloadAssets($entry)];
    }
    $preloadArray = array_unique($preloadArray);
    return implode("\n", $preloadArray);
  }

  private function getPreloadEntry(array $entry, bool $nested = true): array
  {

    $preloadArray = [];
    $preloadArray = [...$preloadArray, ...$this->getPreloadImport($entry)];
    foreach (['imports', 'dynamicImports'] as $importType) {
      if (isset($entry[$importType])) {
        foreach ($entry[$importType] as $import) {
          if (isset($this->manifest[$import])) {
            $preloadArray = [...$preloadArray, ...$this->getPreloadImport($this->manifest[$import])];
            $preloadArray = [...$preloadArray, ...$this->getPreloadAssets($this->manifest[$import])];
            if ($nested) {
              return $preloadArray = [...$preloadArray, ...$this->getPreloadEntry($this->manifest[$import], false)];
            }
          }
        }
      }
    }
    return $preloadArray;
  }

  private function getPreloadImport(array $entry): array
  {
    $preloadArray = [];
    $preloadArray[] = '<link rel="modulepreload" href="' . $this->buildUrl . '/' . $entry['file'] . '">';
    // Preload CSS of dynamic imports as well
    if (isset($entry['css'])) {
      foreach ($entry['css'] as $cssFile) {
        $preloadArray[] = '<link rel="preload" href="' . $this->buildUrl . '/' . $cssFile . '" as="style">';
      }
    }

    return $preloadArray;
  }

  private function getPreloadAssets(array $entry): array
  {
    $preloadArray = [];
    if (!isset($entry['assets'])) {
      return $preloadArray;
    }
    // Preload web fonts
    foreach ($entry['assets'] as $asset) {
      // check if is font woff2|woff|ttf|otf
      $extension = pathinfo($asset, PATHINFO_EXTENSION);
      if (in_array(strtolower($extension), ['woff2', 'woff', 'ttf', 'otf'])) {
        $preloadArray[] = '<link rel="preload" href="' . $this->buildUrl . '/' . $asset . '" as="font" type="font/' . $extension . '" crossorigin>';
      }
      // other assets like images can be preloaded as well if needed
      if (in_array(strtolower($extension), ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif'])) {
        $preloadArray[] = '<link rel="preload" href="' . $this->buildUrl . '/' . $asset . '" as="image">';
      }
      if (in_array(strtolower($extension), ['mp4', 'webm', 'ogg'])) {
        $preloadArray[] = '<link rel="preload" href="' . $this->buildUrl . '/' . $asset . '" as="video">';
      }
      if (in_array(strtolower($extension), ['mp3', 'wav', 'flac'])) {
        $preloadArray[] = '<link rel="preload" href="' . $this->buildUrl . '/' . $asset . '" as="audio">';
      }
      if (strtolower($extension) === 'js') {
        $preloadArray[] = '<link rel="modulepreload" href="' . $this->buildUrl . '/' . $asset . '">';
      }
    }
    return $preloadArray;
  }
}
