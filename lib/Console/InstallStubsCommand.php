<?php

namespace Ynamite\ViteRex\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ynamite\ViteRex\StubsInstaller;
use rex_console_command;

/**
 * `viterex:install-stubs` — copy viterex_addon's project stubs (package.json,
 * vite.config.js, .env.example, biome.jsonc, stylelint.config.js,
 * .browserslistrc, .prettierrc, jsconfig.json, plus main.js / style.css under
 * the configured assets_source_dir) into the project root.
 *
 * This is the CLI counterpart of the "Install stubs" button in
 * AddOns → ViteRex → Settings. Intended for automated install flows
 * (e.g. create-viterex) that need to scaffold the user-project Vite chain
 * without a browser session.
 *
 * Idempotent: by default, files that already exist are skipped. Pass
 * --overwrite to back them up (`.bak.YYYYmmdd-HHiiss`) and replace.
 */
final class InstallStubsCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setName('viterex:install-stubs')
            ->setDescription('Install viterex_addon project stubs (package.json, vite.config.js, ...) into the project root.')
            ->addOption(
                'overwrite',
                null,
                InputOption::VALUE_NONE,
                'Back up existing files (.bak.<timestamp>) and overwrite them.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $overwrite = (bool) $input->getOption('overwrite');

        $result = StubsInstaller::run($overwrite);

        $written = count($result['written'] ?? []);
        $skipped = count($result['skipped'] ?? []);
        $backedUp = count($result['backedUp'] ?? []);
        $packageDepsMerged = (int) ($result['packageDepsMerged'] ?? 0);

        $io->writeln(sprintf(
            'Stubs: written=%d skipped=%d backed-up=%d packageDepsMerged=%d',
            $written,
            $skipped,
            $backedUp,
            $packageDepsMerged,
        ));

        if ($written > 0 && $output->isVerbose()) {
            $io->writeln('Written files:');
            foreach ($result['written'] ?? [] as $file) {
                $io->writeln('  - ' . $file);
            }
        }

        return self::SUCCESS;
    }
}
