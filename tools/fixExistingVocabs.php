<?php

/**
 * @file plugins/generic/controlledVocabSplitter/tools/fixExistingVocabs.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FixExistingVocabsTool
 *
 * @brief CLI tool that applies the splitting rules to vocabulary stored before
 *        the plugin was enabled. Each journal's settings are used, and nothing
 *        is written without --write.
 */

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

require dirname(__FILE__, 5) . '/tools/bootstrap.php';

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\controlledVocabSplitter\ControlledVocabSplitterPlugin;
use PKP\cliTool\CommandLineTool;
use PKP\plugins\PluginRegistry;

class FixExistingVocabsTool extends CommandLineTool
{
    private bool $write = false;

    private ?string $contextPath = null;

    private ?int $submissionId = null;

    /**
     * Read the command-line options.
     */
    public function __construct($argv = [])
    {
        parent::__construct($argv);

        foreach ($this->argv as $argument) {
            if ($argument === '--write') {
                $this->write = true;
            } elseif (str_starts_with($argument, '--journal=')) {
                $this->contextPath = substr($argument, strlen('--journal='));
            } elseif (str_starts_with($argument, '--submission=')) {
                $this->submissionId = (int) substr($argument, strlen('--submission='));
            } else {
                $this->usage();
                exit(1);
            }
        }
    }

    /**
     * Print how to use the tool.
     */
    public function usage()
    {
        echo "Splits controlled vocabularies stored before the Controlled Vocabulary Splitter was enabled.\n"
            . "Each journal's plugin settings are applied; journals where the plugin is off are skipped.\n\n"
            . "Usage: {$this->scriptName} [--journal=path] [--submission=id] [--write]\n"
            . "  Without --write, only prints what would change.\n";
    }

    /**
     * Split the stored vocabularies of the selected journals and submissions.
     */
    public function execute()
    {
        $contextDao = Application::getContextDAO();
        $contexts = $this->contextPath !== null
            ? array_filter([$contextDao->getByPath($this->contextPath)])
            : iterator_to_array($contextDao->getAll(true));
        if (!$contexts) {
            echo "No journal found.\n";
            return false;
        }

        $searchIndex = Application::getSubmissionSearchIndex();
        $changedSubmissions = 0;

        foreach ($contexts as $context) {
            $plugins = PluginRegistry::loadCategory('generic', true, $context->getId());
            /** @var ControlledVocabSplitterPlugin|null $plugin */
            $plugin = $plugins['controlledvocabsplitterplugin'] ?? null;
            if (!$plugin || !$plugin->getEnabled($context->getId())) {
                echo "{$context->getPath()}: plugin not enabled, skipped.\n";
                continue;
            }

            if ($this->submissionId) {
                $submission = Repo::submission()->get($this->submissionId, $context->getId());
                $submissions = $submission ? [$submission] : [];
            } else {
                $submissions = Repo::submission()->getCollector()->filterByContextIds([$context->getId()])->getMany();
            }

            foreach ($submissions as $submission) {
                $changed = false;
                foreach ($submission->getData('publications') as $publication) {
                    foreach ($plugin->splitStoredVocabs($publication, $this->write) as $field => $change) {
                        $changed = true;
                        printf("%s / submission %d / publication %d / %s\n", $context->getPath(), $submission->getId(), $publication->getId(), $field);
                        foreach ($change['after'] as $locale => $terms) {
                            printf("  [%s] %s\n", $locale, implode(' | ', array_map(fn ($term) => is_array($term) ? $term['name'] : $term, $terms)));
                        }
                    }
                }
                if ($changed) {
                    $changedSubmissions++;
                    if ($this->write) {
                        $searchIndex->submissionMetadataChanged($submission);
                    }
                }
            }
        }

        if ($this->write && $changedSubmissions) {
            $searchIndex->submissionChangesFinished();
        }

        printf("\n%d submission(s) %s.\n", $changedSubmissions, $this->write ? 'changed' : 'would change (dry run, add --write to store)');
        return true;
    }
}

$tool = new FixExistingVocabsTool($argv ?? []);
$tool->execute();
