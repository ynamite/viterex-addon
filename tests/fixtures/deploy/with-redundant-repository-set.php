<?php

namespace Deployer;

$deploymentName = 'staging';
$deploymentHost = 'example.com';
$deploymentPort = '22';
$deploymentUser = 'webuser';
$deploymentType = 'stage';
$deploymentPath = '/var/www/staging';
$deploymentRepository = 'git@github.com:user/repo.git';

set('repository', $deploymentRepository);

host($deploymentName)
    ->setHostname($deploymentHost)
    ->setRemoteUser($deploymentUser)
    ->setPort($deploymentPort)
    ->set('labels', ['stage' => $deploymentType])
    ->setDeployPath($deploymentPath);

$isGit = true;
if ($isGit) {
    set('repository', $deploymentRepository);
    set('branch', 'main');
}
