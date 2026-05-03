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

// --- POST: Save form ---
$formCfg = null;
$formErrors = [];
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

$repositoryHtml = '<input type="text" name="repository" class="form-control" value="'
    . rex_escape((string) $formCfg['repository']) . '">';

$hostRows = '';
foreach ($formCfg['hosts'] as $i => $h) {
    $hostRows .= '<fieldset style="margin-bottom:1rem;border:1px solid #ddd;padding:.5rem 1rem;">';
    $hostRows .= '<legend>' . rex_i18n::msg('viterex_deploy_host_n') . ' ' . ($i + 1) . '</legend>';
    foreach (['name', 'hostname', 'port', 'user', 'stage', 'path'] as $field) {
        $val = (string) ($h[$field] ?? '');
        $label = rex_i18n::msg('viterex_deploy_field_' . $field);
        $hostRows .= '<label>' . rex_escape($label)
            . ' <input type="text" name="hosts[' . $i . '][' . $field . ']" value="' . rex_escape($val) . '" class="form-control"></label>';
    }
    $hostRows .= '</fieldset>';
}

$content = '<form action="' . $action . '" method="post">' . $csrfFields
    . '<input type="hidden" name="viterex_deploy_save" value="1">'
    . '<div class="form-group"><label>' . rex_escape(rex_i18n::msg('viterex_deploy_field_repository'))
    . '</label>' . $repositoryHtml . '</div>'
    . $hostRows
    . '<button type="submit" class="btn btn-save">' . rex_i18n::msg('viterex_deploy_save_button') . '</button>'
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
