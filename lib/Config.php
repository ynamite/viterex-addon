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
 * Mirrored to a JSON cache at `rex_path::addonData('viterex', 'structure.json')`
 * that the Vite plugin reads on the Node side. All paths in the JSON are
 * relative to project root — the plugin resolves with `path.resolve(cwd, ...)`.
 */
final class Config
{
    public const DEFAULT_REFRESH_GLOBS = "src/modules/**/*.php\nsrc/templates/**/*.php\nsrc/addons/project/fragments/**/*.php\nsrc/addons/project/lib/**/*.php\nsrc/assets/**/(*.svg|*.png|*.jpg|*.jpeg|*.webp|*.avif|*.gif|*.woff|*.woff2)\n.vite-reload-trigger";

    public const HOT_FILE_REL = '.vite-hot';

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
    ];

    public static function get(string $key): string
    {
        return (string) rex_config::get('viterex', $key, self::defaultFor($key));
    }

    public static function set(string $key, string $value): void
    {
        rex_config::set('viterex', $key, $value);
        self::syncStructureJson();
    }

    /** Default value for a single key (refresh_globs handled separately). */
    public static function defaultFor(string $key): string
    {
        if ($key === 'refresh_globs') {
            return self::DEFAULT_REFRESH_GLOBS;
        }
        return self::DEFAULTS[$key] ?? '';
    }

    /**
     * Seed rex_config with defaults for any keys that are unset OR currently empty.
     * Run on install + on the "Reset to defaults" button on the Settings page.
     * Empty strings are seeded too because rex_config_form writes empty inputs as ''
     * — without seeding, the form would render empty placeholders forever.
     */
    public static function seedDefaults(): void
    {
        foreach (self::keys() as $key) {
            $current = rex_config::get('viterex', $key);
            if ($current === null || $current === '') {
                rex_config::set('viterex', $key, self::defaultFor($key));
            }
        }
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
        return rex_path::base(self::HOT_FILE_REL);
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
     * Write the structure.json file the Vite plugin reads. Path resolves correctly
     * per Redaxo structure (modern → var/data, classic+theme → redaxo/data).
     *
     * All paths are relative to project root; `https_enabled` is a real bool.
     */
    public static function syncStructureJson(): void
    {
        $cfg = self::all();
        $payload = [
            'js_entry'          => $cfg['js_entry'],
            'css_entry'         => $cfg['css_entry'],
            'public_dir'        => $cfg['public_dir'],
            'out_dir'           => $cfg['out_dir'],
            'assets_source_dir' => $cfg['assets_source_dir'],
            'assets_sub_dir'    => $cfg['assets_sub_dir'],
            'build_url_path'    => $cfg['build_url_path'],
            'copy_dirs'         => $cfg['copy_dirs'],
            'https_enabled'     => self::isCheckboxChecked($cfg['https_enabled']),
            'refresh_globs'     => $cfg['refresh_globs'],
            'hot_file'          => self::HOT_FILE_REL,
            'host_url'          => self::getHostUrl(),
        ];
        rex_file::put(
            rex_path::addonData('viterex', 'structure.json'),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * `rex_form_checkbox_element` POSTs as `field[<value>]=<value>`, which
     * `rex_form_element::setValue()` then wraps in pipes (`|1|` checked,
     * `||` unchecked) before `rex_config::set` writes it. The seeded default
     * (`'0'`) and any programmatic `Config::set('…','1')` skip that wrapping.
     * Treat all forms uniformly so `=== '1'` doesn't silently miss `|1|`.
     */
    private static function isCheckboxChecked(string $value): bool
    {
        return in_array('1', explode('|', trim($value, '|')), true);
    }
}
