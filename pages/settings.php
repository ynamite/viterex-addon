<?php

/** @var rex_addon $this */

use Ynamite\ViteRex\Config;
use Ynamite\ViteRex\StubsInstaller;

if (!rex::getUser()->isAdmin()) {
    echo rex_view::error(rex_i18n::msg('viterex_no_permission'));
    return;
}

// Handle "Install stubs" POST before rendering the form
if (rex_post('viterex_install_stubs', 'boolean')) {
    if (!rex_csrf_token::factory('viterex_settings')->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $overwrite = (bool) rex_post('viterex_overwrite', 'boolean');
        $result = StubsInstaller::run($overwrite);

        $message = sprintf(
            '<strong>%d</strong> file(s) written, <strong>%d</strong> skipped. <code>.gitignore</code>: %s.',
            count($result['written']),
            count($result['skipped']),
            rex_escape($result['gitignoreAction']),
        );
        echo rex_view::success($message);

        if (!empty($result['skipped'])) {
            $items = implode('', array_map(
                static fn(string $rel): string => '<li><code>' . rex_escape($rel) . '</code></li>',
                $result['skipped'],
            ));
            echo rex_view::info(
                rex_i18n::msg('viterex_skipped_intro') . '<ul>' . $items . '</ul>',
            );
        }
    }
}

$form = rex_config_form::factory('viterex');

$form->addFieldset(rex_i18n::msg('viterex_section_entries'));

$field = $form->addTextField('js_entry');
$field->setLabel(rex_i18n::msg('viterex_field_js_entry'));
$field->setAttribute('placeholder', 'src/assets/js/main.js');
$field->setNotice(rex_i18n::msg('viterex_field_js_entry_notice'));

$field = $form->addTextField('css_entry');
$field->setLabel(rex_i18n::msg('viterex_field_css_entry'));
$field->setAttribute('placeholder', 'src/assets/css/style.css');
$field->setNotice(rex_i18n::msg('viterex_field_css_entry_notice'));

$form->addFieldset(rex_i18n::msg('viterex_section_paths'));

$field = $form->addTextField('public_dir');
$field->setLabel(rex_i18n::msg('viterex_field_public_dir'));
$field->setAttribute('placeholder', 'public');
$field->setNotice(rex_i18n::msg('viterex_field_public_dir_notice'));

$field = $form->addTextField('out_dir');
$field->setLabel(rex_i18n::msg('viterex_field_out_dir'));
$field->setAttribute('placeholder', 'public/dist');
$field->setNotice(rex_i18n::msg('viterex_field_out_dir_notice'));

$field = $form->addTextField('build_url_path');
$field->setLabel(rex_i18n::msg('viterex_field_build_url_path'));
$field->setAttribute('placeholder', '/dist');
$field->setNotice(rex_i18n::msg('viterex_field_build_url_path_notice'));

$field = $form->addTextField('assets_source_dir');
$field->setLabel(rex_i18n::msg('viterex_field_assets_source_dir'));
$field->setAttribute('placeholder', 'src/assets');
$field->setNotice(rex_i18n::msg('viterex_field_assets_source_dir_notice'));

$field = $form->addTextField('assets_sub_dir');
$field->setLabel(rex_i18n::msg('viterex_field_assets_sub_dir'));
$field->setAttribute('placeholder', 'assets');
$field->setNotice(rex_i18n::msg('viterex_field_assets_sub_dir_notice'));

$form->addFieldset(rex_i18n::msg('viterex_section_dev'));

$field = $form->addTextField('copy_dirs');
$field->setLabel(rex_i18n::msg('viterex_field_copy_dirs'));
$field->setAttribute('placeholder', 'img');
$field->setNotice(rex_i18n::msg('viterex_field_copy_dirs_notice'));

$field = $form->addCheckboxField('https_enabled');
$field->setLabel(rex_i18n::msg('viterex_field_https_enabled'));
$field->addOption(rex_i18n::msg('viterex_field_https_enabled_option'), 1);
$field->setNotice(rex_i18n::msg('viterex_field_https_enabled_notice'));

$field = $form->addTextAreaField('refresh_globs');
$field->setLabel(rex_i18n::msg('viterex_field_refresh_globs'));
$field->setAttribute('rows', 8);
$field->setAttribute('class', 'form-control rex-code');
$field->setNotice(rex_i18n::msg('viterex_field_refresh_globs_notice'));

$content = '';
$content .= $form->getMessage();
$content .= $form->get();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('viterex_settings'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');

// Install Stubs section (separate form)
$csrfFields = rex_csrf_token::factory('viterex_settings')->getHiddenField();
$installContent = '
<form action="' . rex_url::currentBackendPage() . '" method="post">
    ' . $csrfFields . '
    <input type="hidden" name="viterex_install_stubs" value="1">
    <p>' . rex_i18n::msg('viterex_install_stubs_intro') . '</p>
    <div class="checkbox">
        <label>
            <input type="checkbox" name="viterex_overwrite" value="1">
            ' . rex_i18n::msg('viterex_install_overwrite') . '
        </label>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top:10px;">
        <i class="rex-icon fa-download"></i> ' . rex_i18n::msg('viterex_install_stubs_button') . '
    </button>
</form>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'info', false);
$fragment->setVar('title', rex_i18n::msg('viterex_install_stubs_title'), false);
$fragment->setVar('body', $installContent, false);
echo $fragment->parse('core/page/section.php');

// Keep structure.json in sync with rex_config. Idempotent; on page renders
// where the form just saved, rex_config already holds the new values when
// we reach this point, so the JSON cache stays current.
Config::syncStructureJson();
