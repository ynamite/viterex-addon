<?php

namespace Ynamite\ViteRex;

use rex;
use rex_file;
use rex_path;
use rex_ydeploy;

final class Server
{
    private static ?self $instance = null;

    private Structure $structure;
    private bool $isDev = false;
    private ?string $devUrl = null;
    private array $manifest = [];

    public function __construct()
    {
        $this->structure = Structure::detect();
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

    public function getStructure(): Structure
    {
        return $this->structure;
    }

    public static function isProductionDeployment(): bool
    {
        $ydeploy = rex_ydeploy::factory();
        if ($ydeploy->isDeployed()) {
            return str_starts_with(strtolower($ydeploy->getStage()), 'prod');
        }
        return false;
    }

    public static function isStagingDeployment(): bool
    {
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
        $headFile = rex_path::base('.git/HEAD');
        if (!file_exists($headFile)) {
            return 'unknown';
        }
        $contents = rex_file::get($headFile);
        if (!is_string($contents)) {
            return 'unknown';
        }
        $parts = explode('/', $contents, 3);
        return isset($parts[2]) ? trim($parts[2]) : 'unknown';
    }

    public function checkDebugMode(): void
    {
        $isDebugMode = rex::isDebugMode();
        if ($this->isDev) {
            if (!$isDebugMode) {
                self::setDebugMode(true);
            }
            return;
        }
        if (self::isProductionDeployment()) {
            if ($isDebugMode) {
                self::setDebugMode(false);
            }
            return;
        }
        if (!$isDebugMode) {
            self::setDebugMode(true);
        }
    }

    public static function setDebugMode(bool $mode): void
    {
        $configFile = rex_path::coreData('config.yml');
        $config = rex_file::getConfig($configFile);

        if (!is_array($config['debug'] ?? null)) {
            $config['debug'] = [];
        }

        $config['debug']['enabled'] = $mode;
        rex::setProperty('debug', $mode);
        rex_file::putConfig($configFile, $config);
    }

    private function resolveDevState(): array
    {
        $hotFilePath = $this->structure->getHotFilePath();
        if (file_exists($hotFilePath)) {
            $contents = rex_file::get($hotFilePath);
            if (is_string($contents) && trim($contents) !== '') {
                return [true, trim($contents)];
            }
        }

        $configuredServer = Structure::env('VITE_DEV_SERVER');
        if ($configuredServer === null) {
            return [false, null];
        }

        $configuredPort = Structure::env('VITE_DEV_SERVER_PORT');
        $devUrl = $configuredServer . ($configuredPort !== null ? ':' . $configuredPort : '');
        if ($this->probeHttp($devUrl . '/@vite/client')) {
            return [true, $devUrl];
        }

        return [false, null];
    }

    private function probeHttp(string $url): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout'       => 0.2,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        return @file_get_contents($url, false, $context) !== false;
    }

    private function readManifest(): array
    {
        $manifestFile = rex_file::get($this->structure->getManifestPath());
        if (!is_string($manifestFile) || $manifestFile === '') {
            return [];
        }
        $decoded = json_decode($manifestFile, true);
        return is_array($decoded) ? $decoded : [];
    }
}
