<?php

namespace Ynamite\ViteRex;

use rex_dir;
use rex_file;
use rex_path;

/**
 * Copies stub files from `viterex-addon/stubs/` into the user's project root.
 * Invoked from `pages/settings.php` when the user clicks "Install stubs".
 */
final class StubsInstaller
{
    /**
     * @return array{written: list<string>, skipped: list<string>, backedUp: list<string>, gitignoreAction: string}
     */
    public static function run(bool $overwrite = false): array
    {
        $written = [];
        $skipped = [];
        $backedUp = [];
        foreach (self::resolveStubs() as $source => $relTarget) {
            $sourcePath = self::stubsDir() . '/' . $source;
            if (!is_file($sourcePath)) {
                continue;
            }
            $target = rex_path::base(ltrim($relTarget, '/'));
            if (file_exists($target)) {
                if (!$overwrite) {
                    $skipped[] = $relTarget;
                    continue;
                }
                // Backup-on-overwrite: never blow away existing files (especially main.js / style.css)
                // without a recoverable copy. Timestamped sibling, idempotent across re-runs.
                $backupPath = $target . '.bak.' . date('Ymd-His');
                rex_file::copy($target, $backupPath);
                $backedUp[] = $relTarget . ' → ' . basename($backupPath);
            }
            rex_dir::create(dirname($target));
            rex_file::put($target, self::transform($source, $sourcePath));
            $written[] = $relTarget;
        }
        $gitignoreAction = self::mergeGitignore();
        return [
            'written'         => $written,
            'skipped'         => $skipped,
            'backedUp'        => $backedUp,
            'gitignoreAction' => $gitignoreAction,
        ];
    }

    private static function stubsDir(): string
    {
        return dirname(__DIR__) . '/stubs';
    }

    /**
     * @return array<string,string> source-relative-to-stubs/ → target-relative-to-base
     */
    private static function resolveStubs(): array
    {
        $cfg = Config::all();
        $sourceDir = trim($cfg['assets_source_dir'], '/');
        return [
            'package.json'        => '/package.json',
            'vite.config.js'      => '/vite.config.js',          // path-baked
            '.env.example'        => '/.env.example',
            '.browserslistrc'     => '/.browserslistrc',
            '.prettierrc'         => '/.prettierrc',
            'biome.jsonc'         => '/biome.jsonc',
            'stylelint.config.js' => '/stylelint.config.js',
            'jsconfig.json'       => '/jsconfig.json',
            'main.js'             => '/' . $sourceDir . '/js/main.js',
            'style.css'           => '/' . $sourceDir . '/css/style.css',
        ];
    }

    private static function transform(string $source, string $sourcePath): string
    {
        $contents = (string) rex_file::get($sourcePath);
        if ($source === 'vite.config.js') {
            $contents = str_replace(
                '__VITEREX_PLUGIN_IMPORT_PATH__',
                self::resolveViterexPluginImportPath(),
                $contents,
            );
        }
        return $contents;
    }

    private static function resolveViterexPluginImportPath(): string
    {
        $publicDir = trim(Config::get('public_dir'), '/');
        $base = $publicDir === '' ? '.' : './' . $publicDir;
        return $base . '/assets/addons/viterex/viterex-vite-plugin.js';
    }

    private static function mergeGitignore(): string
    {
        $existingPath = rex_path::base('.gitignore');
        $stubPath = self::stubsDir() . '/.gitignore.example';
        if (!is_file($stubPath)) {
            return 'no .gitignore.example stub shipped';
        }
        $required = array_values(array_filter(
            array_map('trim', explode("\n", (string) rex_file::get($stubPath))),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
        if (!file_exists($existingPath)) {
            rex_file::put($existingPath, "# Added by viterex\n" . implode("\n", $required) . "\n");
            return 'created';
        }
        $existing = (string) rex_file::get($existingPath);
        $existingLines = array_map('trim', explode("\n", $existing));
        $missing = array_values(array_diff($required, $existingLines));
        if (empty($missing)) {
            return 'already complete';
        }
        rex_file::put(
            $existingPath,
            rtrim($existing) . "\n\n# Added by viterex\n" . implode("\n", $missing) . "\n",
        );
        return 'appended ' . count($missing) . ' line(s)';
    }
}
