<?php

namespace Ynamite\ViteRex;

final class Assets
{
    public static function renderBlock(?array $entries = null): string
    {
        $entries = self::normalizeEntries($entries);
        if (empty($entries)) {
            return '';
        }

        $server    = Server::factory();
        $structure = $server->getStructure();
        $manifest  = $server->getManifestArray();
        $isDev     = Server::isDevMode();
        $devUrl    = Server::getDevUrl();

        $parts = [];

        $preload = Preload::renderForEntries($entries);
        if ($preload !== '') {
            $parts[] = $preload;
        }

        if (!$isDev) {
            foreach ($entries as $entry) {
                if (!isset($manifest[$entry])) {
                    continue;
                }
                foreach ($manifest[$entry]['css'] ?? [] as $cssFile) {
                    if (!is_string($cssFile) || $cssFile === '') {
                        continue;
                    }
                    $url = $structure->getBuildUrlPath() . '/' . ltrim($cssFile, '/');
                    $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars($url) . '" media="screen">';
                }
            }
        }

        if ($isDev && $devUrl !== null) {
            $parts[] = '<script type="module" src="' . htmlspecialchars($devUrl . '/@vite/client') . '"></script>';
        }

        foreach ($entries as $entry) {
            if ($isDev && $devUrl !== null) {
                $url = $devUrl . '/' . $entry;
            } else {
                if (!isset($manifest[$entry]['file']) || !is_string($manifest[$entry]['file'])) {
                    continue;
                }
                $url = $structure->getBuildUrlPath() . '/' . ltrim($manifest[$entry]['file'], '/');
            }
            $parts[] = '<script type="module" src="' . htmlspecialchars($url) . '"></script>';
        }

        return implode("\n", $parts);
    }

    public static function getDefaultEntry(): string
    {
        $fromEnv = Structure::env('VITE_ENTRY_POINT');
        if ($fromEnv !== null) {
            return trim($fromEnv, '/');
        }
        return match (Structure::detect()->getName()) {
            'classic' => 'assets/js/Main.js',
            'theme'   => 'theme/src/assets/js/Main.js',
            default   => 'src/assets/js/Main.js',
        };
    }

    private static function normalizeEntries(?array $entries): array
    {
        $entries = $entries ?? [self::getDefaultEntry()];
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
