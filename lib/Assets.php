<?php
/*
 *  Class with utility functions for loading scripts in templates
 */

namespace Ynamite\ViteRex;

class Assets
{
  private static ?self $instance = null;

  private string $buildUrl;
  private string $devServerUrl;
  private string $entryPoint;
  private bool $isDev;
  private array $manifest = [];

  public function __construct()
  {
    $server = Server::factory();

    $this->buildUrl = $server->getValue('buildUrl');
    $this->devServerUrl = $server->getValue('devServerUrl');
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
   *  outputs tags and paths for assets in dev and production environments
   *  @return array<string> html
   */
  public static function get(): array
  {
    $instance = self::factory();
    $critical = Critical::factory();

    // preload webfonts
    $preloadEntries = $critical->getPreloadEntries();

    $criticalCSS = rex_get('criticalcss', 'bool', false) ? $critical->getCSS() : '';

    if ($instance->isDev) {
      return [
        'preload' => $preloadEntries,
        // 'criticalCSS' => $criticalCSS,
        'criticalCSS' => '',
        'css' => '', // Vite injects CSS in dev mode
        'js' => '<script type="module" src="' . $instance->devServerUrl . '/@vite/client"></script>' .
          '<script type="module" src="' . $instance->devServerUrl . $instance->entryPoint . '"></script>'
      ];
    }

    // Production: Read manifest.json
    $entryPoint = trim($instance->entryPoint, '/');
    $entry = $instance->manifest[$entryPoint] ?? null;
    if (!$entry) {
      dump('ViteRex: Entry point "' . $entryPoint . '" not found in manifest.json');
      return [
        'preload' => $preloadEntries,
        'criticalCSS' => $criticalCSS,
        'css' => '',
        'js' => ''
      ];
    }

    return [
      'preload' => $preloadEntries,
      'criticalCSS' => $criticalCSS,
      'css' => '<link rel="stylesheet" href="' . $instance->buildUrl . '/' . $entry['css'][0] . '" media="print" onload="this.media=\'all\'">',
      'js' => '<script type="module" src="' . $instance->buildUrl . '/' . $entry['file'] . '"></script>'
    ];
  }
}
