<?php

namespace Deployer;

if ('cli' !== PHP_SAPI) {
    throw new \Exception('CLI only.');
}

$deploymentName = 'staging';
$deploymentHost = 'example.com';
$deploymentPort = '22';
$deploymentUser = 'webuser';
$deploymentType = 'stage';
$deploymentPath = '/var/www/staging';
$deploymentRepository = 'git@github.com:user/repo.git';

require __DIR__ . '/src/addons/ydeploy/deploy.php';

set('repository', $deploymentRepository);

host($deploymentName)
    ->setHostname($deploymentHost)
    ->setRemoteUser($deploymentUser)
    ->setPort($deploymentPort)
    ->set('labels', ['stage' => $deploymentType])
    ->setDeployPath($deploymentPath);

// custom user code below — must survive any rewrite
task('custom:hello', static function () {
    info('hello');
});
