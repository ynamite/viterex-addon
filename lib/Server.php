<?php
/*
 *  Class with utility functions for loading scripts in templates
 */

namespace Ynamite\ViteRex;

use Dotenv\Dotenv;
use rex;
use rex_file;
use rex_path;
use rex_ydeploy;


/** @api */
class Server
{
  private static ?self $instance = null;

  private string $buildPath;
  private string $buildUrl;
  private string $devServerUrl;
  private string $entryPoint;
  private bool $isDev;
  private array $manifest = [];
  private string $manifestPath;

  public function __construct()
  {
    $dotenv = Dotenv::createImmutable(rex_path::base(), ['.env', '.env.local', '.env.development', '.env.production'], false);
    $dotenv->load();

    $this->buildUrl = $_ENV['VITE_DIST_DIR'] ?: '/dist';
    $this->buildPath = isset($_ENV['VITE_PUBLIC_DIR']) ? rex_path::base(ltrim($_ENV['VITE_PUBLIC_DIR'], '/') . $this->buildUrl) : rex_path::base('public' . $this->buildUrl);
    $this->devServerUrl = isset($_ENV['VITE_DEV_SERVER']) ? $_ENV['VITE_DEV_SERVER'] . ':' . $_ENV['VITE_DEV_SERVER_PORT'] : 'http://localhost:3000';
    $this->entryPoint = $_ENV['VITE_ENTRY_POINT'] ?: '/index.js';
    $this->manifestPath = $this->buildPath . '/.vite/manifest.json';
    $this->manifest = $this->getManifestArray();

    $this->isDev = $this->isDevServerRunning();
    // dd($this->devServerUrl);

    $this->checkDebugMode();
  }

  public static function factory(): self
  {
    if (self::$instance) {
      return self::$instance;
    }

    return self::$instance = new self();
  }

  private function isDevServerRunning(): bool
  {
    // Check if Vite dev server is accessible
    $context = stream_context_create([
      'http' => [
        'timeout' => 1,
        'ignore_errors' => true,
      ],
      'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
      ]
    ]);
    return @file_get_contents($this->devServerUrl . '/@vite/client', false, $context) !== false;
  }

  /**
   *  get manifest data as array
   *  @return array
   */
  public function getManifestArray(): array
  {
    if (!empty($this->manifest)) {
      return $this->manifest;
    }
    $manifest = rex_file::get($this->manifestPath) ?: '{}';
    $this->manifest = array_reverse(json_decode($manifest, true));
    return $this->manifest;
  }

  /**
   *  sets key/value pairs
   *  @param string $key Key to set
   *  @param string $value Value to set
   *  @return void
   */
  public static function setValue($key, $value): void
  {
    self::${$key} = $value;
  }

  /**
   *  gets key
   *  @param string $key Key to set
   *  @return mixed
   */
  public function getValue($key): mixed
  {
    return $this->{$key};
  }

  /**
   *  get asset content
   *  @param string $filename Filename to get
   *  @param string $dir Directory to get from
   *  @return string
   */
  public static function getAsset($file, $dir = ''): string|null
  {
    return rex_file::get(self::getAssetsPath() . $dir . '/' . $file);
  }

  /**
   *  get image content
   *  @param string $filename Filename to get
   *  @return string
   */
  public static function getImg($name): string|null
  {
    return self::getAsset($name, 'img');
  }

  /**
   *  get css content
   *  @param string $filename Filename to get
   *  @return string
   */
  public static function getCss($name): string
  {
    return self::getAsset($name, 'css');
  }

  /**
   *  get font content
   *  @param string $filename Filename to get
   *  @return string
   */
  public static function getFont($name): string
  {
    return self::getAsset($name, 'fonts');
  }

  /**
   *  get js content
   *  @param string $filename Filename to get
   *  @return string
   */
  public static function getJs($name): string
  {
    return self::getAsset($name, 'js');
  }

  /**
   *  get assets path
   *  @return string
   */
  public static function getAssetsPath(): string
  {
    $instance = self::factory();
    return $instance->isDev ? rex_path::base('src/assets/') : $instance->buildPath . '/assets/';
  }

  /**
   *  get assets url
   *  @return string
   */
  public static function getAssetsUrl(): string
  {
    $instance = self::factory();
    return $instance->isDev ? $instance->devServerUrl . '/src/assets/' : $instance->buildUrl . '/assets/';
  }

  /**
   *  check if dev mode is active
   *  @return bool
   */
  public static function isDevMode(): bool
  {
    $instance = self::factory();
    return $instance->isDev;
  }

  /**
   *  get current git branch
   *  @return string
   */
  public static function getGitBranch(): string
  {
    if (!file_exists(rex_path::base('.git/HEAD'))) {
      return 'unknown';
    }
    $contents = rex_file::get(rex_path::base('.git/HEAD')); //read the file
    $explodedstring = explode("/", $contents, 3); //seperate out by the "/" in the string
    return trim($explodedstring[2]); //get the one that is always the branch name
  }

  /**
   *  check if debug mode is active and set it if not
   *  @return void
   */
  public function checkDebugMode(): void
  {
    $isDebugMode = rex::isDebugMode();
    if ($this->isDev) {
      if (!$isDebugMode) {
        self::setDebugMode(true);
      }
    } else {
      if (self::isProductionDeployment()) {
        if ($isDebugMode) {
          self::setDebugMode(false);
        }
      } else if (!$isDebugMode) {
        self::setDebugMode(true);
      }
    }
  }

  /**
   *  set debug mode
   *  @param boolean $mode Debug mode to set
   *  @return void
   */
  public static function setDebugMode($mode): void
  {
    $configFile = rex_path::coreData('config.yml');
    $config =
      rex_file::getConfig($configFile);

    if (!is_array($config['debug'])) {
      $config['debug'] = [];
    }

    $config['debug']['enabled'] = $mode;
    rex::setProperty('debug', $mode);
    rex_file::putConfig($configFile, $config);
  }

  /**
   * Check if the current environment is a production deployment
   * @return bool
   */
  public static function isProductionDeployment(): bool
  {
    $ydeploy = rex_ydeploy::factory();
    if ($ydeploy->isDeployed()) {
      $stage = strtolower($ydeploy->getStage());
      return str_starts_with($stage, 'prod');
    }
    return false;
  }
  /**
   * Check if the current environment is a staging deployment
   * @return bool
   */
  public static function isStagingDeployment(): bool
  {
    $ydeploy = rex_ydeploy::factory();
    if ($ydeploy->isDeployed()) {
      $stage = strtolower($ydeploy->getStage());
      return str_starts_with($stage, 'stage');
    }
    return false;
  }
}
