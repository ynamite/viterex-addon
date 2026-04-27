<?php

namespace Ynamite\ViteRex;

use rex_addon;
use rex_config;
use rex_file;
use rex_path;
use rex_yrewrite;

/**
 * Single source of truth for ViteRex paths and runtime config.
 *
 * Persisted via rex_config (table `rex_config`, namespace `viterex`).
 * Mirrored to a JSON cache (`rex_path::addonData('viterex', 'structure.json')`)
 * that the Vite plugin reads on the Node side.
 */
final class Config
{
    public const DEFAULT_REFRESH_GLOBS = "src/templates/**/*.php\nsrc/modules/**/*.php\nsrc/addons/**/fragments/**/*.php\nsrc/addons/**/lib/**/*.php\nvar/cache/addons/structure/**\nvar/cache/addons/url/**";

    /** @var array<string,string> */
    private const DEFAULTS = [
        'js_entry'          => 'src/assets/js/main.js',
        'css_entry'         => 'src/assets/css/style.css',
        'public_dir'        => 'public',
        'out_dir'           => 'public/dist',
        'assets_source_dir' => 'src/assets',
        'assets_sub_dir'    => 'assets',
        'build_url_path'    => '/dist',
        'copy_dirs'         => 'img',
        'https_enabled'     => '0',
        // refresh_globs default applied lazily so const can be referenced
    ];

    public static function get(string $key): string
    {
        $default = $key === 'refresh_globs'
            ? self::DEFAULT_REFRESH_GLOBS
            : (self::DEFAULTS[$key] ?? '');
        return (string) rex_config::get('viterex', $key, $default);
    }

    public static function set(string $key, string $value): void
    {
        rex_config::set('viterex', $key, $value);
        self::syncStructureJson();
    }

    /**
     * @return array<string,string>
     */
    public static function all(): array
    {
        $merged = [];
        foreach (self::keys() as $key) {
            $merged[$key] = self::get($key);
        }
        return $merged;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            ...array_keys(self::DEFAULTS),
            'refresh_globs',
        ];
    }

    public static function getHotFilePath(): string
    {
        return rex_path::base('.vite-hot');
    }

    public static function getHostUrl(): string
    {
        if (rex_addon::get('yrewrite')->isAvailable()) {
            $domain = rex_yrewrite::getDefaultDomain();
            if ($domain !== null) {
                $url = rtrim($domain->getUrl(), '/');
                if ($url !== '') {
                    return $url;
                }
            }
        }
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $protocol . '://' . $host;
    }

    /**
     * Write the structure.json file the Vite plugin reads.
     * Path resolves correctly per Redaxo structure (modern → var/data, classic+theme → redaxo/data).
     */
    public static function syncStructureJson(): void
    {
        $cfg = self::all();
        $payload = $cfg + [
            'out_dir_fs'       => rex_path::base($cfg['out_dir']),
            'assets_source_fs' => rex_path::base($cfg['assets_source_dir']),
            'hot_file_path'    => self::getHotFilePath(),
            'host_url'         => self::getHostUrl(),
        ];
        rex_file::put(
            rex_path::addonData('viterex', 'structure.json'),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
