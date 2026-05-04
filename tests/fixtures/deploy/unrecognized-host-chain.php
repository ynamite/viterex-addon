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

// uses ->set('hostname', ...) instead of ->setHostname(...) — unrecognized
host($deploymentName)
    ->set('hostname', $deploymentHost)
    ->set('remote_user', $deploymentUser)
    ->set('port', $deploymentPort)
    ->set('labels', ['stage' => $deploymentType])
    ->set('deploy_path', $deploymentPath);
