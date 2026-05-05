<?php

namespace Ynamite\ViteRex\Deploy;

use rex_path;

final class Sidecar
{
    private const REQUIRED_HOST_KEYS = ['name', 'hostname', 'port', 'user', 'stage', 'path'];

    public static function path(): string
    {
        return rex_path::addonData('viterex_addon', 'deploy.config.php');
    }

    /**
     * @return array{repository: string, hosts: list<array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}>}|null
     */
    public static function load(?string $path = null): ?array
    {
        $path ??= self::path();
        if (!is_file($path)) {
            return null;
        }
        $cfg = @include $path;
        if (!is_array($cfg)) {
            return null;
        }
        if (!isset($cfg['repository']) || !is_string($cfg['repository'])) {
            return null;
        }
        if (!isset($cfg['hosts']) || !is_array($cfg['hosts'])) {
            return null;
        }
        foreach ($cfg['hosts'] as $host) {
            if (!is_array($host)) {
                return null;
            }
            foreach (self::REQUIRED_HOST_KEYS as $key) {
                if (!array_key_exists($key, $host)) {
                    return null;
                }
            }
            if (
                !is_string($host['name']) || !is_string($host['hostname'])
                || !is_string($host['user']) || !is_string($host['stage'])
                || !is_string($host['path'])
            ) {
                return null;
            }
            if ($host['port'] !== null && !is_int($host['port'])) {
                return null;
            }
        }
        return $cfg;
    }

    /**
     * @param array{repository: string, hosts: list<array{name: string, hostname: string, port: int|null, user: string, stage: string, path: string}>} $cfg
     */
    public static function save(?string $path, array $cfg): void
    {
        $path ??= self::path();
        if (is_file($path)) {
            $backup = $path . '.bak.' . date('Ymd-His');
            if (!@copy($path, $backup)) {
                throw new \RuntimeException("Failed to back up sidecar to {$backup}");
            }
        }
        if (file_put_contents($path, self::render($cfg)) === false) {
            throw new \RuntimeException("Failed to write sidecar to {$path}");
        }
    }

    /**
     * @param array{repository: string, hosts: list<array<string,mixed>>} $cfg
     */
    private static function render(array $cfg): string
    {
        $out = "<?php\n\nreturn [\n";
        $out .= '    ' . self::renderKey('repository') . ' => ' . self::renderValue($cfg['repository']) . ",\n";
        $out .= "    'hosts' => [\n";
        foreach ($cfg['hosts'] as $host) {
            $out .= "        [\n";
            foreach (self::REQUIRED_HOST_KEYS as $key) {
                $out .= '            ' . self::renderKey($key) . ' => ' . self::renderValue($host[$key]) . ",\n";
            }
            $out .= "        ],\n";
        }
        $out .= "    ],\n];\n";
        return $out;
    }

    private static function renderKey(string $key): string
    {
        return "'" . $key . "'";
    }

    private static function renderValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        // strings: single-quoted, escape backslash + single-quote
        return "'" . strtr((string) $value, ["\\" => "\\\\", "'" => "\\'"]) . "'";
    }
}
