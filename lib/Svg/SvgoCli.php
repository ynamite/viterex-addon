<?php

namespace Ynamite\ViteRex\Svg;

use rex_path;
use Throwable;

/**
 * Optimizer that shells out to the SVGO npm CLI. Used in dev stage where the
 * user already has Node + Vite running. Falls back gracefully (returns input
 * unchanged) if `npx`/`svgo` isn't on PATH, or if `exec` is disabled.
 */
final class SvgoCli implements OptimizerInterface
{
    /**
     * Path to the canonical SVGO config that the Vite plugin also imports.
     * Single source of truth — see `assets/svgo-config.mjs`.
     */
    public static function configPath(): string
    {
        return rex_path::addon('viterex_addon', 'assets/svgo-config.mjs');
    }

    private static ?bool $available = null;

    public function optimize(string $svg): string
    {
        if ($svg === '' || !self::isAvailable()) {
            return $svg;
        }

        $tmpInput = tempnam(sys_get_temp_dir(), 'viterex-svg-');
        if ($tmpInput === false) {
            return $svg;
        }
        $tmpInput .= '.svg';

        try {
            if (file_put_contents($tmpInput, $svg) === false) {
                return $svg;
            }
            $cmd = sprintf(
                'npx --no-install svgo --config %s %s 2>/dev/null',
                escapeshellarg(self::configPath()),
                escapeshellarg($tmpInput),
            );
            $exitCode = 0;
            $output = [];
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                return $svg;
            }
            $optimized = @file_get_contents($tmpInput);
            return is_string($optimized) && $optimized !== '' ? $optimized : $svg;
        } catch (Throwable) {
            return $svg;
        } finally {
            @unlink($tmpInput);
        }
    }

    /**
     * Detect whether `npx svgo` can be invoked on this host. Cached per-request:
     * exec'ing `command -v` on every MEDIA_ADDED would be wasteful.
     */
    public static function isAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        if (!\function_exists('exec')) {
            return self::$available = false;
        }
        $disabled = (string) ini_get('disable_functions');
        if ($disabled !== '' && in_array('exec', array_map('trim', explode(',', $disabled)), true)) {
            return self::$available = false;
        }
        $exitCode = 1;
        $output = [];
        @exec('command -v npx 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0 || empty($output)) {
            return self::$available = false;
        }
        // Probe for svgo specifically — npx alone isn't enough; the user's
        // node_modules must have svgo installed (declared via stubs).
        $exitCode = 1;
        $output = [];
        @exec('npx --no-install svgo --version 2>/dev/null', $output, $exitCode);
        return self::$available = ($exitCode === 0);
    }

    /** @internal Test hook — reset the cached availability flag. */
    public static function resetAvailabilityCache(): void
    {
        self::$available = null;
    }
}
