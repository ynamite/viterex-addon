<?php

use Ynamite\ViteRex\Config;
use Ynamite\ViteRex\StubsInstaller;

// Seed rex_config with defaults for any unset/empty keys, then write structure.json.
// On first install everything is unset → all defaults written. On re-install,
// only previously-cleared (or never-set) keys are repopulated; user-customized
// values stay intact. Idempotent.
Config::seedDefaults();

// Push viterex_addon's own npm deps into the user's project package.json.
// Additive + version-compare merge — won't downgrade or duplicate. Lets the
// Vite plugin resolve `svgo` from node_modules after the user runs `npm install`.
// Existing-install upgrade path; fresh installs get svgo via stubs/package.json.
StubsInstaller::syncPackageDeps([
    'devDependencies' => [
        'svgo' => '^4.0.0',
    ],
]);
