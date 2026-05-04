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

task('build:vendors', static function () {
    on(
        host('local'),  // nested host() call — must NOT be detected as a host chain
        static function () {
            run('echo hi');
        }
    );
});

host($deploymentName)
    ->setHostname($deploymentHost)
    ->setRemoteUser($deploymentUser)
    ->setPort($deploymentPort)
    ->set('labels', ['stage' => $deploymentType])
    ->setDeployPath($deploymentPath);
