<?php

namespace Ynamite\ViteRex;

use rex;
use rex_addon;
use rex_addon_interface;

class Badge
{
  public static function get(): string
  {
    /** @var rex_addon_interface $addon */
    $addon = rex_addon::get('viterex');
    $version = $addon->getVersion();
    $gitBranch = Server::getGitBranch();
    $script = '<script type="module" src="' . $addon->getAssetsUrl('ViteRexBadge.js') . '" id="viterex-badge-script" data-is-dev="' . (Server::isDevMode() ? 'true' : 'false') . '" data-version="' . $version . '" data-rex-version="' . rex::getVersion() . '" data-git-branch="' . $gitBranch . '"></script>';
    $style = '<link rel="stylesheet" href="' . $addon->getAssetsUrl('ViteRexBadge.css') . '">';
    $content = $script . $style;
    return $content;
  }
}
