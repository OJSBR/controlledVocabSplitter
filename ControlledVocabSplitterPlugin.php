<?php

/**
 * @file plugins/generic/controlledVocabSplitter/ControlledVocabSplitterPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ControlledVocabSplitterPlugin
 *
 * @brief Splits controlled-vocabulary values that were pasted as a single line
 *        ("Palatal Expansion. Clinical Protocol. Orthopedic appliance.") into
 *        the separate terms the author meant.
 *
 * Authors select the keyword line in their manuscript, copy it and paste the
 * whole thing into the field. One term is stored, the reader sees a sentence
 * where a tag should be, the keyword cloud shows the whole phrase and
 * citation_keywords goes out as a single meta tag, which hurts indexing.
 *
 * The plugin works on two levels:
 *
 * - In the browser, the vocabulary field itself splits what is pasted, so the
 *   author immediately sees separate tags and can fix any term that was cut in
 *   the wrong place. This is a courtesy, not the guarantee.
 * - On the server, the controlled-vocabulary repository is replaced by one that
 *   applies the same rules on every write. That covers the metadata form, the
 *   submission wizard, the REST API and the native XML import — including the
 *   browsers where the script never ran.
 *
 * Both levels share one rule set (ControlledVocabSplitter), mirrored in
 * js/controlledVocabSplitter.js and pinned by the regression suite in tests/.
 */

namespace APP\plugins\generic\controlledVocabSplitter;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use Illuminate\Support\Arr;
use PKP\controlledVocab\ControlledVocab;
use PKP\controlledVocab\Repository as ControlledVocabRepository;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\template\PKPTemplateManager;

class ControlledVocabSplitterPlugin extends GenericPlugin
{
    /**
     * The four controlled vocabularies of a publication, keyed by the symbolic
     * name used in the database and valued by the property name used by the API
     * and by the form fields.
     */
    public const FIELDS = [
        ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD => 'keywords',
        ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_SUBJECT => 'subjects',
        ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_DISCIPLINE => 'disciplines',
        ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_AGENCY => 'supportingAgencies',
    ];

    /** Templates that render a controlled-vocabulary field. */
    public const TEMPLATES = ['dashboard/editors.tpl', 'submission/wizard.tpl'];

    /** Publication id => context id, for the length of one request. */
    private array $contextIdCache = [];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if (Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return true;
        }

        if ($this->isCoreSignatureKnown()) {
            app()->bind(
                ControlledVocabRepository::class,
                fn (): SplittingControlledVocabRepository => new SplittingControlledVocabRepository($this)
            );
        }

        Hook::add('TemplateManager::display', $this->addFieldScript(...));

        return true;
    }

    /**
     * Is the core repository still the one this plugin knows how to extend?
     *
     * Overriding a method with a signature the parent no longer has is a fatal
     * error that PHP raises while compiling the class, which no try/catch can
     * recover from. So the parent is inspected first, and the subclass is only
     * ever loaded when it matches. On an OJS release that changed it, the plugin
     * silently falls back to the browser-side split instead of taking the site
     * down.
     */
    public function isCoreSignatureKnown(): bool
    {
        try {
            $method = new \ReflectionMethod(ControlledVocabRepository::class, 'insertBySymbolic');
        } catch (\ReflectionException) {
            return false;
        }

        $expected = ['symbolic', 'vocabs', 'assocType', 'assocId', 'deleteFirst'];

        return array_map(
            fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters()
        ) === $expected;
    }

    //
    // Configuration
    //

    /**
     * Vocabularies the journal wants split. Everything is split until the
     * journal says otherwise.
     *
     * @return string[] Property names, e.g. ['keywords', 'subjects']
     */
    public function getActiveFields(?int $contextId): array
    {
        $value = $this->getSetting($contextId, 'fields');
        if (!is_array($value)) {
            return array_values(self::FIELDS);
        }

        return array_values(array_intersect(array_values(self::FIELDS), $value));
    }

    /**
     * Separators the journal accepts. Same default: all of them.
     *
     * @return string[]
     */
    public function getActiveSeparators(?int $contextId): array
    {
        $value = $this->getSetting($contextId, 'separators');
        if (!is_array($value)) {
            return ControlledVocabSplitter::SEPARATORS;
        }

        return array_values(array_intersect(ControlledVocabSplitter::SEPARATORS, $value));
    }

    //
    // The rule, on the server
    //

    /**
     * Apply the splitting rules to one controlled-vocabulary write.
     *
     * Called by SplittingControlledVocabRepository for every vocabulary the core
     * stores, including the ones this plugin has no business touching (user
     * interests, for one), which is why the symbolic name is checked first.
     *
     * @param array<string, array|string> $vocabs Terms keyed by locale
     *
     * @return array<string, array|string>
     */
    public function splitVocabs(string $symbolic, array $vocabs, int $assocType, ?int $assocId): array
    {
        $field = self::FIELDS[$symbolic] ?? null;
        if ($field === null || $assocType !== Application::ASSOC_TYPE_PUBLICATION || !$assocId) {
            return $vocabs;
        }

        $contextId = $this->getContextId($assocId);
        if ($contextId === null || !$this->getEnabled($contextId)) {
            return $vocabs;
        }

        if (!in_array($field, $this->getActiveFields($contextId), true)) {
            return $vocabs;
        }

        $separators = $this->getActiveSeparators($contextId);
        if (!$separators) {
            return $vocabs;
        }

        foreach ($vocabs as $locale => $values) {
            $values = Arr::wrap($values);
            if (!$values) {
                continue;
            }
            $vocabs[$locale] = ControlledVocabSplitter::splitList($values, $separators);
        }

        return $vocabs;
    }

    /**
     * Which journal does this publication belong to? Settings are per journal,
     * and a write can reach the repository from a context-less place such as a
     * command-line import.
     */
    private function getContextId(int $publicationId): ?int
    {
        if (array_key_exists($publicationId, $this->contextIdCache)) {
            return $this->contextIdCache[$publicationId];
        }

        $contextId = null;
        $publication = Repo::publication()->get($publicationId);
        if ($publication) {
            $submission = Repo::submission()->get((int) $publication->getData('submissionId'));
            $contextId = $submission ? (int) $submission->getData('contextId') : null;
        }

        return $this->contextIdCache[$publicationId] = $contextId;
    }

    //
    // The courtesy, in the browser
    //

    /**
     * Hook TemplateManager::display — publishes the script that makes the
     * vocabulary field split what is pasted into it.
     *
     * It has to load after js/build.js (registered by the core with
     * STYLE_SEQUENCE_LATE) and before the inline pkp.registry.init() call at the
     * end of the page, so that the component is replaced before the Vue app is
     * created. STYLE_SEQUENCE_LAST is that slot.
     *
     * @param array $args [$templateMgr, &$template, &$output]
     */
    public function addFieldScript(string $hookName, array $args): bool
    {
        $templateMgr = $args[0];
        $template = $args[1];

        if (!in_array($template, self::TEMPLATES, true)) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $contextId = $request->getContext()?->getId();

        $config = [
            'fields' => $this->getActiveFields($contextId),
            'separators' => $this->getActiveSeparators($contextId),
        ];

        $templateMgr->addJavaScript(
            'controlledVocabSplitterConfig',
            'window.ojsbrControlledVocabSplitter = ' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
            [
                'inline' => true,
                'priority' => PKPTemplateManager::STYLE_SEQUENCE_LAST,
                'contexts' => ['backend'],
            ]
        );

        $templateMgr->addJavaScript(
            'controlledVocabSplitter',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/controlledVocabSplitter.js',
            [
                'priority' => PKPTemplateManager::STYLE_SEQUENCE_LAST,
                'contexts' => ['backend'],
            ]
        );

        return Hook::CONTINUE;
    }

    //
    // Plugin boilerplate
    //

    /**
     * @copydoc Plugin::getContextSpecificPluginSettingsFile()
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $url = $request->getRouter()->url($request, null, null, 'manage', null, [
            'verb' => 'settings',
            'plugin' => $this->getName(),
            'category' => 'generic',
        ]);
        array_unshift($actions, new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings')));

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $form = new ControlledVocabSplitterSettingsForm($this, $request->getContext());
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();
        (new NotificationManager())->createTrivialNotification($request->getUser()->getId());

        return new JSONMessage(true);
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.controlledVocabSplitter.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.controlledVocabSplitter.description');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\controlledVocabSplitter\ControlledVocabSplitterPlugin', '\ControlledVocabSplitterPlugin');
}
