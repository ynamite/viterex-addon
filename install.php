<?php

use Ynamite\ViteRex\Config;

// Seed rex_config with defaults for any unset/empty keys, then write structure.json.
// On first install everything is unset → all defaults written. On re-install,
// only previously-cleared (or never-set) keys are repopulated; user-customized
// values stay intact. Idempotent.
Config::seedDefaults();
