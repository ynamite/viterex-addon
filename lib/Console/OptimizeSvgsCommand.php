<?php

namespace Ynamite\ViteRex\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ynamite\ViteRex\Config;
use Ynamite\ViteRex\Svg\OptimizationCache;
use Ynamite\ViteRex\Svg\OptimizerInterface;
use Ynamite\ViteRex\Svg\PhpOptimizer;
use Ynamite\ViteRex\Svg\SvgoCli;
use rex_console_command;
use rex_path;

/**
 * `viterex:optimize-svgs` — batch-optimize SVGs in <assets_source_dir>
 * and the media pool. Mirrors what `npm run build` would do for SVGs,
 * but invokable from the terminal (CI scripts, scheduled tasks, ad-hoc
 * maintenance).
 *
 * Engine selection is inline: SVGO via shell-out if `npx svgo` is on
 * PATH, else PhpOptimizer. Always works because PHP 8.3+ is the addon's
 * hard dependency.
 *
 * Cache: shares the `<addon-cache-dir>/svg-optimized.json` sidecar with
 * the Vite plugin's media-pool walk. Files whose current sha1 matches
 * the recorded value are skipped (already in optimal form).
 *
 * Honors `svg_optimize_enabled` — bails with a clear notice if the
 * global toggle is off.
 *
 * The constructor accepts an optional `OptimizerInterface` so tests can
 * inject a stub. Production code passes null and the engine is resolved
 * inline.
 */
final class OptimizeSvgsCommand extends rex_console_command
{
    public function __construct(private readonly ?OptimizerInterface $injectedOptimizer = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('viterex:optimize-svgs')
            ->setDescription('Optimize SVG files under <assets_source_dir> and the media pool.')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List what would change; write nothing.',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Ignore the cache and re-optimize every file.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);

        if (!Config::isEnabled('svg_optimize_enabled')) {
            $io->note('svg_optimize_enabled is off; nothing to do. Toggle it on under ViteRex → Settings.');
            return self::SUCCESS;
        }

        $optimizer = $this->injectedOptimizer
            ?? (SvgoCli::isAvailable() ? new SvgoCli() : new PhpOptimizer());
        $io->writeln('Engine: ' . ($optimizer instanceof SvgoCli ? 'SVGO (Node)' : 'PhpOptimizer (PHP)'));

        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');
        if ($dryRun) {
            $io->writeln('<comment>Dry run: no files will be written.</comment>');
        }

        $cache = new OptimizationCache(rex_path::addonCache('viterex_addon', 'svg-optimized.json'));
        if ($force) {
            $cache->clear();
        }

        $base = rtrim(rex_path::base(), '/') . '/';
        $candidateDirs = [
            rex_path::base(trim(Config::get('assets_source_dir'), '/')),
            rex_path::media(),
        ];
        $files = [];
        foreach ($candidateDirs as $dir) {
            if (is_dir($dir)) {
                foreach (self::findSvgs($dir) as $f) {
                    $files[] = $f;
                }
            }
        }
        $files = array_values(array_unique($files));

        $stats = ['scanned' => 0, 'optimized' => 0, 'skipped' => 0, 'errors' => 0];

        if ($files === []) {
            $io->writeln('No SVGs found under configured paths.');
            return self::SUCCESS;
        }

        $progress = $io->createProgressBar(\count($files));
        $progress->start();

        foreach ($files as $abs) {
            $stats['scanned']++;
            $rel = str_starts_with($abs, $base) ? substr($abs, \strlen($base)) : $abs;

            $original = @file_get_contents($abs);
            if (!\is_string($original) || $original === '') {
                $stats['errors']++;
                $progress->advance();
                continue;
            }
            if ($cache->isFresh($rel, $original)) {
                $stats['skipped']++;
                $progress->advance();
                continue;
            }

            $optimized = $optimizer->optimize($original);
            $finalBytes = ($optimized !== '' ? $optimized : $original);

            if ($finalBytes !== $original) {
                if (!$dryRun) {
                    @file_put_contents($abs, $finalBytes);
                    $cache->record($rel, $finalBytes);
                }
                $stats['optimized']++;
            } else {
                if (!$dryRun) {
                    $cache->record($rel, $original);
                }
                $stats['skipped']++;
            }
            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);

        if (!$dryRun) {
            $cache->persist();
        }

        $io->table(
            ['scanned', 'optimized' . ($dryRun ? ' (would write)' : ''), 'skipped (cached/no-op)', 'errors'],
            [[$stats['scanned'], $stats['optimized'], $stats['skipped'], $stats['errors']]],
        );

        return self::SUCCESS;
    }

    /**
     * @return iterable<string> Absolute paths to .svg files under $dir, recursively.
     */
    private static function findSvgs(string $dir): iterable
    {
        // SKIP_DOTS only (no FOLLOW_SYMLINKS) — circular symlinks would
        // hang the iterator. The default behavior is to leave symlinks
        // alone, which is what we want for media-pool / asset trees.
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'svg') {
                yield $f->getPathname();
            }
        }
    }
}
