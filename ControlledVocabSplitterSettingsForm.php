<?php

/**
 * @file plugins/generic/controlledVocabSplitter/ControlledVocabSplitterSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ControlledVocabSplitterSettingsForm
 *
 * @brief Which vocabularies are split, and which separators are honoured.
 */

namespace APP\plugins\generic\controlledVocabSplitter;

use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class ControlledVocabSplitterSettingsForm extends Form
{
    /** Core locale keys for the four vocabularies, so they read as the rest of OJS. */
    private const FIELD_LABELS = [
        'keywords' => 'common.keywords',
        'subjects' => 'common.subjects',
        'disciplines' => 'common.discipline',
        'supportingAgencies' => 'submission.supportingAgencies',
    ];

    public function __construct(private ControlledVocabSplitterPlugin $plugin, private Context $context)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * Load the current settings of the journal.
     */
    public function initData(): void
    {
        $contextId = $this->context->getId();
        $this->setData('fields', $this->plugin->getActiveFields($contextId));
        $this->setData('separators', $this->plugin->getActiveSeparators($contextId));
        parent::initData();
    }

    /**
     * Read the vocabularies and separators that were ticked.
     */
    public function readInputData(): void
    {
        $this->readUserVars(['fields', 'separators']);
        parent::readInputData();
    }

    /**
     * Render the form with one row per vocabulary and per separator.
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $fields = (array) $this->getData('fields');
        $separators = (array) $this->getData('separators');

        // The rows are built here, not in the template: Smarty's security policy
        // does not let a template call in_array() to work out what is checked.
        $fieldRows = [];
        foreach (self::FIELD_LABELS as $field => $label) {
            $fieldRows[] = [
                'name' => $field,
                'label' => $label,
                'checked' => in_array($field, $fields, true),
            ];
        }

        $separatorRows = [];
        foreach (ControlledVocabSplitter::SEPARATORS as $separator) {
            $separatorRows[] = [
                'name' => $separator,
                'label' => 'plugins.generic.controlledVocabSplitter.separator.' . $separator,
                'example' => 'plugins.generic.controlledVocabSplitter.separator.' . $separator . '.example',
                'checked' => in_array($separator, $separators, true),
            ];
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'cvsStyleUrl' => $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/css/settings.css',
            'fieldRows' => $fieldRows,
            'separatorRows' => $separatorRows,
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * Store the settings of the journal.
     */
    public function execute(...$functionArgs)
    {
        $contextId = $this->context->getId();

        $this->plugin->updateSetting(
            $contextId,
            'fields',
            $this->keep($this->getData('fields'), array_values(ControlledVocabSplitterPlugin::FIELDS)),
            'object'
        );

        $this->plugin->updateSetting(
            $contextId,
            'separators',
            $this->keep($this->getData('separators'), ControlledVocabSplitter::SEPARATORS),
            'object'
        );

        parent::execute(...$functionArgs);
    }

    /**
     * Only ever store values the plugin itself defines, in the plugin's own
     * order, so a hand-crafted POST cannot put anything else in the settings.
     *
     * @param string[] $allowed
     *
     * @return string[]
     */
    private function keep($submitted, array $allowed): array
    {
        return array_values(array_intersect($allowed, is_array($submitted) ? $submitted : []));
    }
}
