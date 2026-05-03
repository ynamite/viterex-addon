<?php

namespace Deployer;

$deploymentName = 'stage';
$deploymentHost = 'shared.example.com';
$deploymentPort = '22';
$deploymentUser = 'shareduser';
$deploymentType = 'stage';
$deploymentPath = '/var/www/stage';
$deploymentRepository = 'git@github.com:user/repo.git';

set('repository', $deploymentRepository);

host($deploymentName)
    ->setHostname($deploymentHost)
    ->setRemoteUser($deploymentUser)
    ->setPort($deploymentPort)
    ->set('labels', ['stage' => $deploymentType])
    ->setDeployPath($deploymentPath);

host('prod')
    ->setHostname('shared.example.com')
    ->setRemoteUser('shareduser')
    ->setPort(22)
    ->set('labels', ['stage' => 'prod'])
    ->setDeployPath('/var/www/prod');
