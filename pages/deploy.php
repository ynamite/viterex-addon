<?php

/** @var rex_addon $this */

use Ynamite\ViteRex\Deploy\DeployFile;
use Ynamite\ViteRex\Deploy\Page;
use Ynamite\ViteRex\Deploy\Sidecar;

if (!rex::getUser()->isAdmin()) {
    echo rex_view::error(rex_i18n::msg('viterex_no_permission'));
    return;
}

if (!rex_addon::get('ydeploy')->isAvailable()) {
    echo rex_view::warning(rex_i18n::msg('viterex_deploy_ydeploy_missing'));
    return;
}

$sidecarPath = Sidecar::path();
$deployPath = DeployFile::path();

$deployExists = is_file($deployPath);
$deployContents = $deployExists ? rex_file::get($deployPath) : null;

if (!$deployExists) {
    echo rex_view::error(rex_i18n::msg('viterex_deploy_file_missing'));
    return;
}

$csrf = rex_csrf_token::factory('viterex_deploy');

// --- POST: Activate ---
if (rex_post('viterex_deploy_activate', 'boolean')) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } elseif (!is_file($sidecarPath)) {
        echo rex_view::error(rex_i18n::msg('viterex_deploy_activate_no_sidecar'));
    } elseif (DeployFile::hasMarkers((string) $deployContents)) {
        echo rex_view::info(rex_i18n::msg('viterex_deploy_already_active'));
    } else {
        $extracted = DeployFile::extract((string) $deployContents);
        $rewritten = DeployFile::rewrite((string) $deployContents, $extracted);
        if ($rewritten === $deployContents) {
            echo rex_view::error(rex_i18n::msg('viterex_deploy_rewrite_failed'));
        } else {
            $backup = $deployPath . '.bak.' . date('Ymd-His');
            if (!@copy($deployPath, $backup) || rex_file::put($deployPath, $rewritten) === false) {
                echo rex_view::error(rex_i18n::msg('viterex_deploy_write_failed'));
            } else {
                $deployContents = $rewritten;
                echo rex_view::success(rex_i18n::rawMsg('viterex_deploy_activated', basename($backup)));
            }
        }
    }
}

// --- POST: Add or Remove host ---
$mutateAction = null;
if (rex_post('viterex_deploy_add_host', 'boolean')) {
    $mutateAction = 'add';
} elseif (($removeIdx = rex_post('viterex_deploy_remove_host', 'int', -1)) >= 0) {
    $mutateAction = 'remove';
}
if ($mutateAction !== null) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $rawHosts = rex_post('hosts', 'array', []);
        $formCfg = [
            'repository' => rex_post('repository', 'string'),
            'hosts' => array_values($rawHosts),
        ];
        if ($mutateAction === 'add') {
            $last = end($formCfg['hosts']);
            if (is_array($last)) {
                $copy = $last;
                $copy['name'] = '';
                $copy['stage'] = '';
                $formCfg['hosts'][] = $copy;
            } else {
                $formCfg['hosts'][] = [
                    'name' => '', 'hostname' => '', 'port' => '22',
                    'user' => '', 'stage' => '', 'path' => '',
                ];
            }
        } else {
            unset($formCfg['hosts'][$removeIdx]);
            $formCfg['hosts'] = array_values($formCfg['hosts']);
            if (count($formCfg['hosts']) === 0) {
                // never let the user end up with zero rows
                $formCfg['hosts'][] = [
                    'name' => '', 'hostname' => '', 'port' => '22',
                    'user' => '', 'stage' => '', 'path' => '',
                ];
            }
        }
    }
}

// --- POST: Save form ---
$formErrors = [];
if (!isset($formCfg)) {
    $formCfg = null;
}
if (rex_post('viterex_deploy_save', 'boolean')) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $post = [
            'repository' => rex_post('repository', 'string'),
            'hosts' => rex_post('hosts', 'array', []),
        ];
        $validation = Page::validatePost($post);
        if ($validation['cfg'] === null) {
            $formErrors = $validation['errors'];
            $formCfg = $post;
            echo rex_view::error(implode('<br>', array_map('rex_escape', $formErrors)));
        } else {
            try {
                Sidecar::save($sidecarPath, $validation['cfg']);
                echo rex_view::success(rex_i18n::msg('viterex_deploy_saved'));
                $formCfg = $validation['cfg'];
            } catch (\RuntimeException $e) {
                echo rex_view::error(rex_escape($e->getMessage()));
                $formCfg = $post;
            }
        }
    }
}

// --- Determine state and pre-populate form ---
$sidecar = Sidecar::load($sidecarPath);

// Flow A: first visit, sidecar absent → auto-write from extract if possible
if ($sidecar === null && $formCfg === null) {
    $extracted = DeployFile::extract((string) $deployContents);
    if ($extracted !== null) {
        try {
            Sidecar::save($sidecarPath, $extracted);
            $sidecar = $extracted;
            echo rex_view::info(rex_i18n::msg('viterex_deploy_sidecar_created'));
        } catch (\RuntimeException $e) {
            echo rex_view::error(rex_escape($e->getMessage()));
        }
    } else {
        echo rex_view::warning(rex_i18n::msg('viterex_deploy_extract_failed'));
    }
}

if ($formCfg === null) {
    $formCfg = $sidecar ?? ['repository' => '', 'hosts' => [
        ['name' => '', 'hostname' => '', 'port' => 22, 'user' => '', 'stage' => '', 'path' => ''],
    ]];
}

$state = Page::detectState($sidecar, $deployContents);
if ($state === Page::STATE_NEEDS_ACTIVATION) {
    echo rex_view::info(rex_i18n::msg('viterex_deploy_needs_activation'));
} elseif ($state === Page::STATE_ACTIVE) {
    echo rex_view::success(rex_i18n::msg('viterex_deploy_active'));
}

// --- Render form ---
$action = rex_url::currentBackendPage();
$csrfFields = $csrf->getHiddenField();

$repositoryHtml = '<div class="form-group">'
    . '<label for="viterex-deploy-repository">' . rex_escape(rex_i18n::msg('viterex_deploy_field_repository')) . '</label>'
    . '<input type="text" id="viterex-deploy-repository" name="repository" class="form-control" value="'
    . rex_escape((string) $formCfg['repository']) . '">'
    . '</div>';

$hostFieldOrder = ['name', 'hostname', 'port', 'user', 'stage', 'path'];

$hostRows = '';
foreach ($formCfg['hosts'] as $i => $h) {
    $headerLabel = rex_escape(rex_i18n::msg('viterex_deploy_host_n')) . ' ' . ($i + 1);
    $hostRows .= '<fieldset class="rex-form" style="margin-bottom:1.5rem;border:1px solid #ddd;padding:1rem;">';
    $hostRows .= '<legend style="width:auto;padding:0 .5rem;font-size:1em;">' . $headerLabel
        . ' <button type="submit" name="viterex_deploy_remove_host" value="' . (int) $i
        . '" class="btn btn-xs btn-default" style="margin-left:.5rem;"'
        . ' formnovalidate>'
        . '<i class="rex-icon fa-trash"></i> ' . rex_escape(rex_i18n::msg('viterex_deploy_remove_host_button'))
        . '</button></legend>';
    $hostRows .= '<div class="row">';
    foreach ($hostFieldOrder as $field) {
        $val = (string) ($h[$field] ?? '');
        $label = rex_i18n::msg('viterex_deploy_field_' . $field);
        $inputId = 'viterex-deploy-host-' . $i . '-' . $field;
        $hostRows .= '<div class="col-sm-6 col-md-4">'
            . '<div class="form-group">'
            . '<label for="' . $inputId . '">' . rex_escape($label) . '</label>'
            . '<input type="text" id="' . $inputId
            . '" name="hosts[' . $i . '][' . $field . ']" value="' . rex_escape($val)
            . '" class="form-control">'
            . '</div></div>';
    }
    $hostRows .= '</div></fieldset>';
}

$addHostBtn = '<button type="submit" name="viterex_deploy_add_host" value="1"'
    . ' class="btn btn-default" formnovalidate>'
    . '<i class="rex-icon fa-plus"></i> ' . rex_escape(rex_i18n::msg('viterex_deploy_add_host_button'))
    . '</button>';

$saveBtn = '<button type="submit" name="viterex_deploy_save" value="1" class="btn btn-save">'
    . rex_escape(rex_i18n::msg('viterex_deploy_save_button')) . '</button>';

$content = '<form action="' . $action . '" method="post">' . $csrfFields
    . $repositoryHtml
    . $hostRows
    . '<div style="margin-bottom:1rem;">' . $addHostBtn . '</div>'
    . $saveBtn
    . '</form>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('viterex_deploy_settings_title'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');

// --- Activate section (separate form) ---
$activateContent = '<form action="' . $action . '" method="post">' . $csrfFields
    . '<input type="hidden" name="viterex_deploy_activate" value="1">'
    . '<p>' . rex_i18n::msg('viterex_deploy_activate_intro') . '</p>'
    . '<button type="submit" class="btn btn-primary">'
    . '<i class="rex-icon fa-bolt"></i> ' . rex_i18n::msg('viterex_deploy_activate_button')
    . '</button></form>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'info', false);
$fragment->setVar('title', rex_i18n::msg('viterex_deploy_activate_title'), false);
$fragment->setVar('body', $activateContent, false);
echo $fragment->parse('core/page/section.php');
