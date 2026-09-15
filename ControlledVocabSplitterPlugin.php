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
 * The rules (ControlledVocabSplitter) are applied through core hooks only:
 *
 * - Publication::edit splits the vocabularies about to be saved, which covers
 *   the metadata form, the submission wizard and the REST API;
 * - Publication::add splits what a new publication was created with;
 * - nativexmlpublicationfilter::execute splits what the native XML import has
 *   just stored.
 *
 * Everything is written back through the core repository's public API; no core
 * class is replaced.
 */

namespace APP\plugins\generic\controlledVocabSplitter;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\publication\Publication;
use Illuminate\Support\Arr;
use PKP\controlledVocab\ControlledVocab;
use PKP\controlledVocab\Repository as ControlledVocabRepository;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

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

    /**
     * Register the hooks that split the vocabularies when a publication is saved or imported.
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance()) {
            return $success;
        }

        // The hooks are always registered and each one checks whether the plugin
        // is enabled in the journal of the publication (pkp/pkp-lib#11793): a
        // command-line import has no journal in the request, so checking here
        // would leave its publications untouched.

        Hook::add('Publication::edit', $this->splitOnEdit(...));
        Hook::add('Publication::add', $this->splitOnAdd(...));
        Hook::add('nativexmlpublicationfilter::execute', $this->splitOnImport(...));

        return $success;
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
    // Hooks
    //

    /**
     * Hook Publication::edit — split the vocabularies sent in this edit before
     * the publication is saved.
     *
     * @param array $args [&$newPublication, $publication, $params, $request]
     */
    public function splitOnEdit(string $hookName, array $args): bool
    {
        $newPublication = &$args[0];
        $params = $args[2];

        $contextId = $this->getContextId($newPublication);
        if (!$this->getEnabled($contextId)) {
            return Hook::CONTINUE;
        }
        foreach ($this->getActiveFields($contextId) as $field) {
            if (!array_key_exists($field, $params) || !is_array($newPublication->getData($field))) {
                continue;
            }
            $newPublication->setData($field, $this->splitByLocale($newPublication->getData($field), $contextId));
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook Publication::add — the publication and its vocabularies are already
     * stored when the hook runs, so whatever needs splitting is written again.
     *
     * @param array $args [&$publication]
     */
    public function splitOnAdd(string $hookName, array $args): bool
    {
        $this->splitStoredVocabs($args[0]);

        return Hook::CONTINUE;
    }

    /**
     * Hook nativexmlpublicationfilter::execute — the native import stores the
     * vocabularies straight into the repository, after the publication exists.
     *
     * @param array $args [&$importedPublications]
     */
    public function splitOnImport(string $hookName, array $args): bool
    {
        foreach (Arr::wrap($args[0]) as $publication) {
            if ($publication instanceof Publication) {
                $this->splitStoredVocabs($publication);
            }
        }

        return Hook::CONTINUE;
    }

    //
    // The rule, on the server
    //

    /**
     * Apply the rules of the journal to terms keyed by locale.
     *
     * @param array<string, array|string> $vocabs
     *
     * @return array<string, array>
     */
    public function splitByLocale(array $vocabs, ?int $contextId): array
    {
        $separators = $this->getActiveSeparators($contextId);
        foreach ($vocabs as $locale => $values) {
            $values = Arr::wrap($values);
            if ($separators && $values) {
                $vocabs[$locale] = ControlledVocabSplitter::splitList($values, $separators);
            }
        }

        return $vocabs;
    }

    /**
     * Split, in the database, the vocabularies of a publication that are
     * already stored. Nothing is written when there is nothing to split.
     *
     * @return array<string, array{before: array, after: array}> What was split, by property name
     */
    public function splitStoredVocabs(Publication $publication, bool $write = true): array
    {
        $publicationId = (int) $publication->getId();
        $contextId = $this->getContextId($publication);
        if (!$publicationId || !$this->getEnabled($contextId) || !$this->getActiveSeparators($contextId)) {
            return [];
        }

        $changes = [];
        $symbolics = array_flip(self::FIELDS);
        foreach ($this->getActiveFields($contextId) as $field) {
            $stored = Repo::controlledVocab()->getBySymbolic(
                $symbolics[$field],
                Application::ASSOC_TYPE_PUBLICATION,
                $publicationId,
                [],
                ControlledVocabRepository::AS_ENTRY_DATA
            );
            $split = $this->splitByLocale($stored, $contextId);
            if ($this->names($split) === $this->names($stored)) {
                continue;
            }

            $changes[$field] = ['before' => $stored, 'after' => $split];
            if ($write) {
                // Every locale is written at once: insertBySymbolic() deletes the
                // whole vocabulary of the publication before inserting.
                Repo::controlledVocab()->insertBySymbolic(
                    $symbolics[$field],
                    $split,
                    Application::ASSOC_TYPE_PUBLICATION,
                    $publicationId
                );
            }
        }

        return $changes;
    }

    /**
     * The term names by locale, to compare two versions of a vocabulary.
     *
     * @param array<string, array> $vocabs
     *
     * @return array<string, string[]>
     */
    private function names(array $vocabs): array
    {
        return array_map(
            fn ($values): array => array_map(
                fn ($value): string => is_array($value) ? (string) ($value['name'] ?? '') : (string) $value,
                array_values(Arr::wrap($values))
            ),
            $vocabs
        );
    }

    /**
     * Settings are per journal, and a write can come from a context-less place
     * such as a command-line import.
     */
    private function getContextId(Publication $publication): ?int
    {
        $submission = Repo::submission()->get((int) $publication->getData('submissionId'));

        return $submission ? (int) $submission->getData('contextId') : null;
    }

    //
    // Plugin boilerplate
    //

    /**
     * Default settings installed for each new journal.
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * Add the settings action to the plugin entry in the plugins list.
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$request->getContext() || !$this->getEnabled()) {
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
     * Show and save the settings form.
     */
    public function manage($args, $request): JSONMessage
    {
        // The settings belong to a journal; there is nothing to configure site-wide.
        if ($request->getUserVar('verb') !== 'settings' || !$request->getContext()) {
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
     * Name shown in the plugins list.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.controlledVocabSplitter.displayName');
    }

    /**
     * Description shown in the plugins list.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.controlledVocabSplitter.description');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\\APP\\plugins\\generic\\controlledVocabSplitter\\ControlledVocabSplitterPlugin', '\\ControlledVocabSplitterPlugin');
}
