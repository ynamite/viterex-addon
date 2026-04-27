<?php

use Ynamite\ViteRex\Config;

// First-install seeding: write a structure.json with the addon's defaults so the
// Vite plugin (Node side) has something to read even before the user opens the
// Settings page. Subsequent runs (re-install) re-sync to current rex_config state.
Config::syncStructureJson();
