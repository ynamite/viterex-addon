<?php

namespace Ynamite\ViteRex;

use rex_extension;
use rex_extension_point;

final class Preload
{
    private static ?self $instance = null;

    private Structure $structure;
    private array $manifest;

    public function __construct()
    {
        $server = Server::factory();
        $this->structure = $server->getStructure();
        $this->manifest = $server->getManifestArray();
    }

    public static function factory(): self
    {
        return self::$instance ??= new self();
    }

    public static function renderForEntries(array $entries): string
    {
        return self::factory()->build($entries);
    }

    private function build(array $entries): string
    {
        $lines = [];

        if (!Server::isDevMode()) {
            foreach ($entries as $entry) {
                $key = trim($entry, '/');
                if ($key === '' || !isset($this->manifest[$key])) {
                    continue;
                }
                $visited = [];
                $lines = array_merge(
                    $lines,
                    $this->walkManifestEntry($this->manifest[$key], $visited),
                );
            }
        }

        $extra = rex_extension::registerPoint(
            new rex_extension_point('VITEREX_PRELOAD', [], [
                'entries' => $entries,
                'dev'     => Server::isDevMode(),
            ]),
        );
        if (is_array($extra)) {
            foreach ($extra as $item) {
                if (is_string($item) && $item !== '') {
                    $lines[] = $item;
                }
            }
        }

        return implode("\n", array_values(array_unique($lines)));
    }

    private function walkManifestEntry(array $entry, array &$visited): array
    {
        $file = $entry['file'] ?? null;
        if (!is_string($file) || isset($visited[$file])) {
            return [];
        }
        $visited[$file] = true;

        $lines = [$this->modulePreload($file)];

        foreach (($entry['css'] ?? []) as $cssFile) {
            if (is_string($cssFile)) {
                $lines[] = $this->stylePreload($cssFile);
            }
        }

        foreach (['imports', 'dynamicImports'] as $importType) {
            foreach (($entry[$importType] ?? []) as $importKey) {
                if (is_string($importKey) && isset($this->manifest[$importKey])) {
                    $lines = array_merge(
                        $lines,
                        $this->walkManifestEntry($this->manifest[$importKey], $visited),
                    );
                }
            }
        }

        foreach (($entry['assets'] ?? []) as $asset) {
            if (is_string($asset)) {
                $preload = $this->assetPreload($asset);
                if ($preload !== null) {
                    $lines[] = $preload;
                }
            }
        }

        return $lines;
    }

    private function modulePreload(string $file): string
    {
        $url = $this->url($file);
        return '<link rel="modulepreload" href="' . htmlspecialchars($url) . '">';
    }

    private function stylePreload(string $file): string
    {
        $url = $this->url($file);
        return '<link rel="preload" href="' . htmlspecialchars($url) . '" as="style">';
    }

    private function assetPreload(string $asset): ?string
    {
        $url = $this->url($asset);
        $ext = strtolower(pathinfo($asset, PATHINFO_EXTENSION));

        return match (true) {
            in_array($ext, ['woff2', 'woff', 'ttf', 'otf'], true)
                => '<link rel="preload" href="' . htmlspecialchars($url) . '" as="font" type="font/' . $ext . '" crossorigin>',
            in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif'], true)
                => '<link rel="preload" href="' . htmlspecialchars($url) . '" as="image">',
            in_array($ext, ['mp4', 'webm', 'ogg'], true)
                => '<link rel="preload" href="' . htmlspecialchars($url) . '" as="video">',
            in_array($ext, ['mp3', 'wav', 'flac'], true)
                => '<link rel="preload" href="' . htmlspecialchars($url) . '" as="audio">',
            $ext === 'js'
                => '<link rel="modulepreload" href="' . htmlspecialchars($url) . '">',
            default => null,
        };
    }

    private function url(string $file): string
    {
        return $this->structure->getBuildUrlPath() . '/' . ltrim($file, '/');
    }
}
