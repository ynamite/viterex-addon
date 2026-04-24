<?php

namespace Ynamite\ViteRex;

use Dotenv\Dotenv;
use rex_addon;
use rex_file;
use rex_path;

final class Structure
{
    private static ?self $instance = null;

    public function __construct(
        public readonly string $name,
        public readonly string $publicFsPath,
        public readonly string $buildFsPath,
        public readonly string $buildUrlPath,
        public readonly string $hotFilePath,
    ) {
    }

    public static function detect(bool $forceRefresh = false): self
    {
        if (self::$instance !== null && !$forceRefresh) {
            return self::$instance;
        }

        self::loadEnv();

        $name = self::resolveName();
        $publicFsPath = self::resolvePublicFsPath($name);
        $buildFsPath = self::resolveBuildFsPath($publicFsPath);
        $buildUrlPath = self::resolveBuildUrlPath();
        $hotFilePath = $publicFsPath . '.hot';

        $instance = new self($name, $publicFsPath, $buildFsPath, $buildUrlPath, $hotFilePath);
        $instance->persistCache();

        return self::$instance = $instance;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPublicFsPath(): string
    {
        return $this->publicFsPath;
    }

    public function getBuildFsPath(): string
    {
        return $this->buildFsPath;
    }

    public function getBuildUrlPath(): string
    {
        return $this->buildUrlPath;
    }

    public function getHotFilePath(): string
    {
        return $this->hotFilePath;
    }

    public function getManifestPath(): string
    {
        return $this->buildFsPath . '/.vite/manifest.json';
    }

    public static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function loadEnv(): void
    {
        $dotenv = Dotenv::createImmutable(
            rex_path::base(),
            ['.env', '.env.local', '.env.development', '.env.production'],
            false,
        );
        $dotenv->safeLoad();
    }

    private static function resolveName(): string
    {
        $override = self::env('VITE_STRUCTURE');
        if ($override !== null && in_array($override, ['classic', 'modern', 'theme'], true)) {
            return $override;
        }
        if (rex_addon::get('theme')->isAvailable()) {
            return 'theme';
        }
        if (file_exists(rex_path::base('public/index.php'))) {
            return 'modern';
        }
        return 'classic';
    }

    private static function resolvePublicFsPath(string $name): string
    {
        return match ($name) {
            'modern' => rex_path::base('public/'),
            'theme'  => rex_path::base('theme/public/'),
            default  => rex_path::base(),
        };
    }

    private static function resolveBuildFsPath(string $publicFsPath): string
    {
        $override = self::env('VITE_OUTPUT_DIR');
        if ($override !== null) {
            return str_starts_with($override, '/')
                ? $override
                : rex_path::base(ltrim($override, '/'));
        }
        return $publicFsPath . 'assets/addons/viterex';
    }

    private static function resolveBuildUrlPath(): string
    {
        $override = self::env('VITE_DIST_URL');
        if ($override !== null) {
            return '/' . ltrim($override, '/');
        }
        return '/assets/addons/viterex';
    }

    private function persistCache(): void
    {
        $cachePath = rex_path::addonData('viterex', 'structure.json');
        $envPath = rex_path::base('.env');

        if (file_exists($cachePath) && file_exists($envPath) && filemtime($cachePath) >= filemtime($envPath)) {
            return;
        }

        rex_file::put($cachePath, json_encode([
            'structure'    => $this->name,
            'publicFsPath' => $this->publicFsPath,
            'buildFsPath'  => $this->buildFsPath,
            'buildUrlPath' => $this->buildUrlPath,
            'hotFilePath'  => $this->hotFilePath,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
