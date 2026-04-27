<?php

namespace Ynamite\ViteRex;

use rex_file;
use rex_path;

final class Assets
{
    /**
     * Render the full asset block for a list of entries (preload + CSS links + HMR client + JS).
     * Pass `null` to use the configured default entries (CSS + JS).
     */
    public static function renderBlock(?array $entries = null): string
    {
        $entries = self::normalizeEntries($entries);
        if (empty($entries)) {
            return '';
        }

        $server   = Server::factory();
        $manifest = $server->getManifestArray();
        $isDev    = Server::isDevMode();
        $devUrl   = Server::getDevUrl();
        $buildUrl = '/' . trim(Config::get('build_url_path'), '/');

        $preloadHtml = Preload::renderForEntries($entries);
        $cssLinks    = [];
        $jsScripts   = [];
        $hmrEmitted  = false;

        foreach ($entries as $entry) {
            if ($isDev && $devUrl !== null) {
                $url = $devUrl . '/' . $entry;
                if (self::isCssPath($entry)) {
                    $cssLinks[] = '<link rel="stylesheet" href="' . htmlspecialchars($url) . '">';
                } else {
                    if (!$hmrEmitted) {
                        $jsScripts[] = '<script type="module" src="' . htmlspecialchars($devUrl . '/@vite/client') . '"></script>';
                        $hmrEmitted = true;
                    }
                    $jsScripts[] = '<script type="module" src="' . htmlspecialchars($url) . '"></script>';
                }
                continue;
            }

            if (!isset($manifest[$entry]['file']) || !is_string($manifest[$entry]['file'])) {
                continue;
            }
            $file = $manifest[$entry]['file'];
            $url  = $buildUrl . '/' . ltrim($file, '/');

            if (self::isCssPath($file)) {
                $cssLinks[] = '<link rel="stylesheet" href="' . htmlspecialchars($url) . '">';
                continue;
            }

            $jsScripts[] = '<script type="module" src="' . htmlspecialchars($url) . '"></script>';

            foreach ($manifest[$entry]['css'] ?? [] as $cssChunk) {
                if (!is_string($cssChunk) || $cssChunk === '') {
                    continue;
                }
                $cssLinks[] = '<link rel="stylesheet" href="' . htmlspecialchars($buildUrl . '/' . ltrim($cssChunk, '/')) . '">';
            }
        }

        $parts = [];
        if ($preloadHtml !== '') {
            $parts[] = $preloadHtml;
        }
        foreach (array_values(array_unique($cssLinks)) as $link) {
            $parts[] = $link;
        }
        foreach ($jsScripts as $script) {
            $parts[] = $script;
        }
        return implode("\n", $parts);
    }

    /**
     * Default entries for `REX_VITE` placeholders without an explicit `src=` attribute.
     * Returns the JS entry and the CSS entry, both from `Config`.
     *
     * @return list<string>
     */
    public static function getDefaultEntries(): array
    {
        return [
            trim(Config::get('js_entry'), '/'),
            trim(Config::get('css_entry'), '/'),
        ];
    }

    /**
     * Absolute filesystem path to a static asset under `<assets_source_dir>`.
     *
     *   dev:  <base>/<assets_source_dir>/<rel>
     *   prod: <base>/<out_dir>/<assets_sub_dir>/<rel>
     */
    public static function path(string $relativePath): string
    {
        $rel = ltrim($relativePath, '/');
        if (Server::isDevMode()) {
            return rex_path::base(trim(Config::get('assets_source_dir'), '/') . '/' . $rel);
        }
        $outDir = trim(Config::get('out_dir'), '/');
        $subDir = trim(Config::get('assets_sub_dir'), '/');
        $segments = array_filter([$outDir, $subDir, $rel], static fn(string $s): bool => $s !== '');
        return rex_path::base(implode('/', $segments));
    }

    /**
     * Browser URL to a static asset under `<assets_source_dir>`.
     *
     *   dev:  <devUrl>/<assets_source_dir>/<rel>
     *   prod: <build_url_path>/<assets_sub_dir>/<rel>
     */
    public static function url(string $relativePath): string
    {
        $rel = ltrim($relativePath, '/');
        if (Server::isDevMode()) {
            $devUrl = Server::getDevUrl() ?? '';
            return $devUrl . '/' . trim(Config::get('assets_source_dir'), '/') . '/' . $rel;
        }
        $buildUrl = '/' . trim(Config::get('build_url_path'), '/');
        $subDir = trim(Config::get('assets_sub_dir'), '/');
        return $buildUrl . ($subDir !== '' ? '/' . $subDir : '') . '/' . $rel;
    }

    /**
     * Read raw file content for inlining (SVG, JSON, raw HTML fragment, …).
     * Resolves via `Assets::path()` so dev reads source, prod reads from rollup-plugin-copy'd location.
     */
    public static function inline(string $relativePath): string
    {
        $content = rex_file::get(self::path($relativePath));
        return is_string($content) ? $content : '';
    }

    private static function isCssPath(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css';
    }

    private static function normalizeEntries(?array $entries): array
    {
        if ($entries === null) {
            $entries = self::getDefaultEntries();
        }
        $normalized = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $trimmed = trim($entry, "/ \t\n\r");
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }
        return array_values(array_unique($normalized));
    }
}
