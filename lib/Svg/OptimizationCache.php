<?php

namespace Ynamite\ViteRex\Svg;

/**
 * Sidecar JSON cache shared by `OptimizeSvgsCommand` (PHP) and the Vite
 * plugin's media-pool walk (Node). Maps project-relative file paths to the
 * sha1 of their POST-optimization content. On the next scan, a file whose
 * current on-disk sha1 matches the recorded value is skipped — meaning it's
 * still in its optimal form and re-optimizing would be a no-op.
 *
 * Why post-optimization (not pre)? Files are mutated 1:1 in place, so the
 * "current" disk content IS the post-optimization content from the last run.
 * Hashing post-optimization keeps the freshness check trivial:
 *
 *   isFresh(path, currentContent) === (recorded[path] === sha1(currentContent))
 *
 * Pure I/O + serialization. No Redaxo runtime calls in public methods, so
 * the class is unit-testable without a bootstrap (mirrors the convention
 * established by `lib/Deploy/Sidecar.php`).
 *
 * Concurrency: not a concern in practice. You don't run `npm run build` and
 * `bin/console viterex:optimize-svgs` simultaneously. Last-writer-wins on
 * the rare race; worst case is one run's records get clobbered and those
 * files re-optimize on the next pass — idempotent, no harm.
 */
final class OptimizationCache
{
    /** @var array<string, string> path => sha1 of post-optimization content */
    private array $entries;

    public function __construct(private readonly string $jsonPath)
    {
        $this->entries = self::load($jsonPath);
    }

    public function isFresh(string $relativePath, string $currentContent): bool
    {
        return ($this->entries[$relativePath] ?? null) === sha1($currentContent);
    }

    public function record(string $relativePath, string $finalContent): void
    {
        $this->entries[$relativePath] = sha1($finalContent);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function persist(): void
    {
        $dir = \dirname($this->jsonPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        @file_put_contents(
            $this->jsonPath,
            json_encode($this->entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (\is_string($k) && \is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
