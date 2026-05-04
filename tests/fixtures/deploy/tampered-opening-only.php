<?php

namespace Deployer;

// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>
$cfg = require __DIR__ . '/deploy.config.php';
set('repository', $cfg['repository']);
// (closing marker missing — user deleted it)

host('x')->setHostname('x')->setRemoteUser('x')->setPort(22)
    ->set('labels', ['stage' => 'x'])->setDeployPath('/x');
