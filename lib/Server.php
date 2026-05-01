<?php

namespace Ynamite\ViteRex;

use rex;
use rex_addon;
use rex_file;
use rex_path;
use rex_ydeploy;

final class Server
{
    private static ?self $instance = null;

    private bool $isDev = false;
    private ?string $devUrl = null;
    private array $manifest = [];

    public function __construct()
    {
        [$this->isDev, $this->devUrl] = $this->resolveDevState();
        $this->manifest = $this->readManifest();
        $this->checkDebugMode();
    }

    public static function factory(): self
    {
        return self::$instance ??= new self();
    }

    public static function isDevMode(): bool
    {
        return self::factory()->isDev;
    }

    public static function getDevUrl(): ?string
    {
        return self::factory()->devUrl;
    }

    public function getManifestArray(): array
    {
        return $this->manifest;
    }

    public static function isProductionDeployment(): bool
    {
        if (!rex_addon::get('ydeploy')->isAvailable()) {
            return false;
        }
        $ydeploy = rex_ydeploy::factory();
        if ($ydeploy->isDeployed()) {
            return str_starts_with(strtolower($ydeploy->getStage()), 'prod');
        }
        return false;
    }

    public static function isStagingDeployment(): bool
    {
        if (!rex_addon::get('ydeploy')->isAvailable()) {
            return false;
        }
        $ydeploy = rex_ydeploy::factory();
        if ($ydeploy->isDeployed()) {
            return str_starts_with(strtolower($ydeploy->getStage()), 'stage');
        }
        return false;
    }

    public static function getDeploymentStage(): string
    {
        if (self::isProductionDeployment()) {
            return 'prod';
        }
        if (self::isStagingDeployment()) {
            return 'staging';
        }
        return 'dev';
    }

    public static function getGitBranch(): string
    {
        $headFile = self::findGitHeadFile();
        if ($headFile === null) {
            return 'unknown';
        }
        $contents = rex_file::get($headFile);
        if (!is_string($contents)) {
            return 'unknown';
        }
        $contents = trim($contents);

        // Branch ref: `ref: refs/heads/<branch>`
        if (preg_match('#^ref:\s*refs/heads/(.+)$#', $contents, $m)) {
            return $m[1];
        }
        // Detached HEAD: the file holds a raw 40-char SHA → short-form
        if (preg_match('/^[0-9a-f]{40}$/', $contents)) {
            return substr($contents, 0, 7);
        }
        return 'unknown';
    }

    /**
     * Resolve the .git/HEAD path at the project root. Handles both regular
     * repos (`.git` is a directory) and worktrees (`.git` is a file pointing
     * at the actual git dir via `gitdir: <path>`).
     */
    private static function findGitHeadFile(): ?string
    {
        $base = rtrim(rex_path::base(), '/');
        $gitPath = $base . '/.git';

        if (is_dir($gitPath) && is_file($gitPath . '/HEAD')) {
            return $gitPath . '/HEAD';
        }
        if (is_file($gitPath)) {
            $pointer = rex_file::get($gitPath);
            if (is_string($pointer) && preg_match('/^gitdir:\s*(.+)$/m', $pointer, $m)) {
                $gitDir = trim($m[1]);
                if (!str_starts_with($gitDir, '/')) {
                    $gitDir = $base . '/' . $gitDir;
                }
                if (is_file($gitDir . '/HEAD')) {
                    return $gitDir . '/HEAD';
                }
            }
        }
        return null;
    }

    /**
     * Set Redaxo's debug mode to match the current environment.
     * Persists to config.yml so downstream consumers that read the file directly
     * (developer addon, error handler init, etc.) see the right value.
     * Idempotent: skips the disk write when config.yml already matches.
     */
    public function checkDebugMode(): void
    {
        $shouldDebug = $this->isDev || !self::isProductionDeployment();
        self::setDebugMode($shouldDebug);
    }

    public static function setDebugMode(bool $mode): void
    {
        rex::setProperty('debug', $mode);

        $configFile = rex_path::coreData('config.yml');
        $config = rex_file::getConfig($configFile);
        if (is_array($config['debug'] ?? null) && ($config['debug']['enabled'] ?? null) === $mode) {
            return; // disk already matches → no write
        }
        if (!is_array($config['debug'] ?? null)) {
            $config['debug'] = [];
        }
        $config['debug']['enabled'] = $mode;
        rex_file::putConfig($configFile, $config);
    }

    /**
     * Hot-file detection only. The previous HTTP probe fallback added a
     * 200ms blocking call on every request when VITE_DEV_SERVER was set
     * in env but Vite wasn't actually running — a latent perf trap.
     */
    private function resolveDevState(): array
    {
        $hotFilePath = Config::getHotFilePath();
        if (file_exists($hotFilePath)) {
            $contents = rex_file::get($hotFilePath);
            if (is_string($contents) && trim($contents) !== '') {
                return [true, trim($contents)];
            }
        }
        return [false, null];
    }

    private function readManifest(): array
    {
        $manifestPath = rex_path::base(trim(Config::get('out_dir'), '/')) . '/.vite/manifest.json';
        $manifestFile = rex_file::get($manifestPath);
        if (!is_string($manifestFile) || $manifestFile === '') {
            return [];
        }
        $decoded = json_decode($manifestFile, true);
        return is_array($decoded) ? $decoded : [];
    }
}
