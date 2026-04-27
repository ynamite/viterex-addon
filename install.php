<?php

use Ynamite\ViteRex\Config;

// First-install seeding: write a structure.json with the addon's defaults so the
// Vite plugin (Node side) has something to read even before the user opens the
// Settings page. Subsequent runs (re-install) re-sync to current rex_config state.
Config::syncStructureJson();

echo rex_view::info(
    'ViteRex installed. Open <strong>AddOns → ViteRex → Settings</strong> to configure paths and click <strong>Install stubs</strong> to scaffold project files.'
);
