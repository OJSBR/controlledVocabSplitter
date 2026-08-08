<?php

/**
 * @file plugins/generic/controlledVocabSplitter/tools/fixExistingVocabs.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Applies the splitting rules to vocabulary that is already stored.
 *
 * The plugin only acts when something is saved, so an archive that was built
 * before it was installed keeps its concatenated terms. This script repairs
 * that archive in one pass. It prints what it would do and changes nothing
 * unless --write is given.
 *
 * Usage (run as the account that owns the files, never as root):
 *
 *   php plugins/generic/controlledVocabSplitter/tools/fixExistingVocabs.php
 *   php plugins/generic/controlledVocabSplitter/tools/fixExistingVocabs.php --write
 *   ... --context=1            only one journal (default: every journal)
 *   ... --publication=13       only one publication, useful for a first test
 *   ... --field=keywords       only one vocabulary (repeatable, comma separated)
 *   ... --separators=semicolon,period
 *
 * After writing, clear the OJS caches (cache/t_cache, cache/t_compile) and any
 * cache kept by keyword-cloud blocks, or the old terms keep being displayed.
 */

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\controlledVocabSplitter\ControlledVocabSplitter;
use APP\plugins\generic\controlledVocabSplitter\ControlledVocabSplitterPlugin;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 4);
chdir($root);
define('INDEX_FILE_LOCATION', $root . '/index.php');
require $root . '/lib/pkp/includes/bootstrap.php';

require_once dirname(__DIR__) . '/ControlledVocabSplitter.php';
require_once dirname(__DIR__) . '/ControlledVocabSplitterPlugin.php';

$options = getopt('', ['write', 'context::', 'publication::', 'field::', 'separators::', 'help']);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 1600);
    exit(0);
}

$write = isset($options['write']);
$onlyContext = isset($options['context']) ? (int) $options['context'] : null;
$onlyPublication = isset($options['publication']) ? (int) $options['publication'] : null;

$fields = ControlledVocabSplitterPlugin::FIELDS;
if (isset($options['field']) && $options['field'] !== '') {
    $wanted = array_map('trim', explode(',', (string) $options['field']));
    $fields = array_filter($fields, fn (string $field): bool => in_array($field, $wanted, true));
    if (!$fields) {
        exit("Unknown vocabulary. Use: " . implode(', ', ControlledVocabSplitterPlugin::FIELDS) . "\n");
    }
}

$separators = ControlledVocabSplitter::SEPARATORS;
if (isset($options['separators']) && $options['separators'] !== '') {
    $separators = array_values(array_intersect(
        ControlledVocabSplitter::SEPARATORS,
        array_map('trim', explode(',', (string) $options['separators']))
    ));
    if (!$separators) {
        exit("Unknown separator. Use: " . implode(', ', ControlledVocabSplitter::SEPARATORS) . "\n");
    }
}

// Without a context the router hands back null, and anything that asks for the
// current journal blows up half way through the run.
$contextDao = Application::getContextDAO();
$contexts = $onlyContext
    ? array_filter([$contextDao->getById($onlyContext)])
    : $contextDao->getAll()->toArray();

if (!$contexts) {
    exit("No journal found.\n");
}

$router = new class () extends PageRouter {
    private $context;

    public function setFixedContext($context): void
    {
        $this->context = $context;
    }

    public function getContext(\PKP\core\PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context
    {
        return $this->context;
    }
};
$router->setApplication(Application::get());
$router->setFixedContext(reset($contexts));
Application::get()->getRequest()->setRouter($router);

$searchIndex = Application::getSubmissionSearchIndex();
$contextIds = array_map(fn ($context): int => (int) $context->getId(), $contexts);

$totalPublications = 0;
$totalTouched = 0;
$totalBefore = 0;
$totalAfter = 0;

foreach ($fields as $symbolic => $field) {
    // Everything stored for this vocabulary, by publication and locale, in the
    // order the entries were sequenced.
    $rows = DB::table('controlled_vocabs as cv')
        ->join('controlled_vocab_entries as e', 'e.controlled_vocab_id', '=', 'cv.controlled_vocab_id')
        ->join('controlled_vocab_entry_settings as s', 's.controlled_vocab_entry_id', '=', 'e.controlled_vocab_entry_id')
        ->join('publications as p', 'p.publication_id', '=', 'cv.assoc_id')
        ->join('submissions as sub', 'sub.submission_id', '=', 'p.submission_id')
        ->where('cv.symbolic', $symbolic)
        ->where('cv.assoc_type', Application::ASSOC_TYPE_PUBLICATION)
        ->where('s.setting_name', 'name')
        ->whereIn('sub.context_id', $contextIds)
        ->when($onlyPublication, fn ($query) => $query->where('cv.assoc_id', $onlyPublication))
        ->orderBy('cv.assoc_id')
        ->orderBy('s.locale')
        ->orderBy('e.seq')
        ->get(['cv.assoc_id as publication_id', 's.locale', 's.setting_value as value']);

    $byPublication = [];
    foreach ($rows as $row) {
        $byPublication[(int) $row->publication_id][$row->locale][] = (string) $row->value;
    }

    foreach ($byPublication as $publicationId => $byLocale) {
        $totalPublications++;

        $new = [];
        $before = 0;
        $after = 0;
        $changed = false;

        foreach ($byLocale as $locale => $values) {
            $before += count($values);
            $split = ControlledVocabSplitter::splitList($values, $separators);
            $after += count($split);
            $new[$locale] = $split;

            if ($split !== $values) {
                $changed = true;
            }
        }

        if (!$changed) {
            continue;
        }

        $totalTouched++;
        $totalBefore += $before;
        $totalAfter += $after;

        printf("\n%s / publication %d: %d record(s) -> %d term(s)\n", $field, $publicationId, $before, $after);
        foreach ($new as $locale => $terms) {
            printf("  [%s] %s\n", $locale, implode(' | ', $terms));
        }

        if (!$write) {
            continue;
        }

        // The whole publication is rewritten on purpose: insertBySymbolic deletes
        // every locale of the vocabulary before inserting, so a partial array
        // would wipe the locales left out of it.
        Repo::controlledVocab()->insertBySymbolic(
            $symbolic,
            $new,
            Application::ASSOC_TYPE_PUBLICATION,
            $publicationId
        );

        $publication = Repo::publication()->get($publicationId);
        $submission = $publication ? Repo::submission()->get((int) $publication->getData('submissionId')) : null;
        if ($submission) {
            $searchIndex->submissionMetadataChanged($submission);
        }

        echo "  -> written\n";
    }
}

if ($write && $totalTouched) {
    $searchIndex->submissionChangesFinished();
}

echo "\n" . str_repeat('=', 70) . "\n";
printf("mode ...................... %s\n", $write ? 'WRITTEN' : 'DRY RUN (nothing changed)');
printf("vocabularies .............. %s\n", implode(', ', $fields));
printf("separators ................ %s\n", implode(', ', $separators));
printf("publications inspected .... %d\n", $totalPublications);
printf("publications changed ...... %d\n", $totalTouched);
printf("records before ............ %d\n", $totalBefore);
printf("terms after ............... %d\n", $totalAfter);

if (!$write) {
    echo "\nAdd --write to store these changes.\n";
} else {
    echo "\nRemember to clear cache/t_cache, cache/t_compile and any keyword-cloud cache.\n";
}
