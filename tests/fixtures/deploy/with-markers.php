<?php

namespace Deployer;

require __DIR__ . '/src/addons/ydeploy/deploy.php';

// >>> VITEREX:DEPLOY_CONFIG (do not edit by hand) >>>
$cfg = require __DIR__ . '/deploy.config.php';
set('repository', $cfg['repository']);
foreach ($cfg['hosts'] as $h) {
    host($h['name'])
        ->setHostname($h['hostname'])
        ->setRemoteUser($h['user'])
        ->setPort($h['port'])
        ->set('labels', ['stage' => $h['stage']])
        ->setDeployPath($h['path']);
}
// <<< VITEREX:DEPLOY_CONFIG <<<

task('custom:hello', static function () {
    info('hello');
});
