<?php

use Ynamite\ViteRex\Structure;

$structure = Structure::detect(true);
$structureName = $structure->getName();

$rootStubs = [
    'package.json'            => '/package.json',
    'vite.config.js'          => '/vite.config.js',
    'vite/viterex.js'         => '/vite/viterex.js',
    'vite/hotfile-plugin.js'  => '/vite/hotfile-plugin.js',
    '.env.example'            => '/.env.example',
    '.browserslistrc'         => '/.browserslistrc',
    '.prettierrc'             => '/.prettierrc',
    'biome.json'              => '/biome.json',
    'stylelint.config.js'     => '/stylelint.config.js',
    'jsconfig.json'           => '/jsconfig.json',
];

$sourceStubs = match ($structureName) {
    'classic' => [
        'src/assets/js/Main.js'      => '/assets/js/Main.js',
        'src/assets/css/style.css'   => '/assets/css/style.css',
    ],
    'theme' => [
        'src/assets/js/Main.js'      => '/theme/src/assets/js/Main.js',
        'src/assets/css/style.css'   => '/theme/src/assets/css/style.css',
    ],
    default => [
        'src/assets/js/Main.js'      => '/src/assets/js/Main.js',
        'src/assets/css/style.css'   => '/src/assets/css/style.css',
    ],
};

$stubs    = $rootStubs + $sourceStubs;
$stubsDir = __DIR__ . '/stubs';

$written = [];
$skipped = [];

foreach ($stubs as $stub => $rel) {
    $source = $stubsDir . '/' . $stub;
    $target = rex_path::base(ltrim($rel, '/'));

    if (!is_file($source)) {
        continue;
    }

    if (file_exists($target)) {
        rex_file::copy($source, $target . '.viterex-default');
        $skipped[] = [$rel, $target . '.viterex-default'];
        continue;
    }

    rex_dir::create(dirname($target));
    rex_file::copy($source, $target);
    $written[] = $rel;
}

$gitignorePath = rex_path::base('.gitignore');
$requiredLines = [
    'node_modules/',
    '*.hot',
    ltrim($structure->getBuildUrlPath(), '/') . '/.vite/',
];

if (!file_exists($gitignorePath)) {
    rex_file::put($gitignorePath, "# Added by viterex\n" . implode("\n", $requiredLines) . "\n");
    $gitignoreAction = 'created';
} else {
    $existing = (string) rex_file::get($gitignorePath);
    $existingLines = array_map('trim', explode("\n", $existing));
    $missing = array_values(array_diff($requiredLines, $existingLines));
    if (!empty($missing)) {
        rex_file::put(
            $gitignorePath,
            rtrim($existing) . "\n\n# Added by viterex\n" . implode("\n", $missing) . "\n",
        );
        $gitignoreAction = 'appended ' . count($missing) . ' line(s)';
    } else {
        $gitignoreAction = 'already complete';
    }
}

echo rex_view::success(sprintf(
    '%d file(s) scaffolded. %d skipped (defaults written alongside as *.viterex-default). .gitignore: %s. Detected structure: %s.',
    count($written),
    count($skipped),
    $gitignoreAction,
    $structureName,
));

if (!empty($skipped)) {
    $items = array_map(
        static fn(array $row): string => '<li><code>' . rex_escape($row[0]) . '</code> &rarr; diff against <code>' . rex_escape($row[1]) . '</code></li>',
        $skipped,
    );
    echo rex_view::info('Existing files were preserved. Review the defaults:<ul>' . implode('', $items) . '</ul>');
}

if ($structureName !== 'modern') {
    echo rex_view::warning(sprintf(
        'Detected <code>%s</code> directory structure. ViteRex works fine here, but the recommended layout is <strong>modern</strong> (ydeploy) — sources under <code>src/assets/{js,css}/</code>, build output under <code>public/assets/addons/viterex/</code>. Consider migrating for better tooling integration. Sources for this install were scaffolded under <code>%s</code>.',
        rex_escape($structureName),
        rex_escape($structureName === 'theme' ? 'theme/src/assets/{js,css}/' : 'assets/{js,css}/'),
    ));
}
