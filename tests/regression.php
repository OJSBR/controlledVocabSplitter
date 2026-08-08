<?php

/**
 * @file tests/regression.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Regression suite for controlledVocabSplitter (blocks A to K).
 *
 *        Blocks A to D exercise the rules on their own; E to I write for real,
 *        through the same paths as the metadata form, the REST API and the
 *        native XML import; J replays the real archive that motivated the
 *        plugin; K is a property test over generated lists.
 *
 *        Everything the script touches is restored at the end, including the
 *        submissions it creates. See tests/CASES.md.
 *
 * Usage: php plugins/generic/controlledVocabSplitter/tests/regression.php [--keep]
 */

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\controlledVocabSplitter\ControlledVocabSplitter as Rules;
use APP\publication\Publication;
use PKP\controlledVocab\ControlledVocab;
use PKP\plugins\PluginRegistry;
use PKP\user\interest\UserInterest;

$root = dirname(__DIR__, 4);
chdir($root);
define('INDEX_FILE_LOCATION', $root . '/index.php');
require $root . '/lib/pkp/includes/bootstrap.php';

const CONTEXT_ID = 1;
const SECTION_ID = 1;
const UG_AUTHOR = 14;
const PLUGIN = 'controlledvocabsplitterplugin';

const ALL_SEPARATORS = ['semicolon', 'comma', 'period'];

$keep = in_array('--keep', $argv, true);

//
// Harness: there is no URL on the command line, so the journal is pinned on the
// router. Without it getContext() returns null half way through the run.
//
class RouterWithContext extends PageRouter
{
    private $pinned;
    public function pinContext($context) { $this->pinned = $context; }
    public function getContext(\PKP\core\PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context { return $this->pinned; }
}

$request = Application::get()->getRequest();
$context = Application::getContextDAO()->getById(CONTEXT_ID);
$router = new RouterWithContext();
$router->setApplication(Application::get());
$router->pinContext($context);
$request->setRouter($router);

$plugins = PluginRegistry::loadCategory('generic', true, CONTEXT_ID);
$plugin = $plugins[PLUGIN] ?? null;
if (!$plugin) {
    exit("FATAL: plugin not loaded. Is it enabled in this journal?\n");
}

//
// Tiny framework
//
$RESULTS = [];
$BLOCK = '';
$fatalFailure = null;

function block(string $title): void
{
    global $BLOCK;
    $BLOCK = $title;
    echo "\n" . str_repeat('=', 78) . "\n{$title}\n" . str_repeat('=', 78) . "\n";
}

function testCase(string $id, string $title, callable $body): void
{
    global $RESULTS, $BLOCK;
    try {
        $body();
        $RESULTS[] = ['id' => $id, 'block' => $BLOCK, 'title' => $title, 'ok' => true, 'message' => ''];
        printf("  [ PASS ] %-6s %s\n", $id, $title);
    } catch (Throwable $e) {
        $RESULTS[] = ['id' => $id, 'block' => $BLOCK, 'title' => $title, 'ok' => false, 'message' => $e->getMessage()];
        printf("  [ FAIL ] %-6s %s\n            -> %s\n", $id, $title, str_replace("\n", "\n            ", $e->getMessage()));
    }
}

function assertEquals($expected, $actual, string $message = 'value differs from the expected one'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message
            . "\n              expected: " . json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n              actual  : " . json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Short, readable label for the case name. */
function summarize(string $text, int $limit = 46): string
{
    $text = str_replace(["\n", "\t"], ['\n', '\t'], $text);
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
}

//
// Domain helpers
//
$CREATED = [];

function newSubmission(string $locale = 'pt_BR'): array
{
    global $context, $CREATED;

    $submission = Repo::submission()->newDataObject([
        'contextId' => CONTEXT_ID,
        'locale' => $locale,
        'submissionProgress' => 'start',
        'stageId' => WORKFLOW_STAGE_ID_SUBMISSION,
    ]);
    $publication = Repo::publication()->newDataObject(['sectionId' => SECTION_ID]);
    $id = Repo::submission()->add($submission, $publication, $context);
    $CREATED[] = $id;

    Repo::stageAssignment()->build($id, UG_AUTHOR, 2, false, true);

    $submission = Repo::submission()->get($id);

    return [$submission, $submission->getCurrentPublication()];
}

/** Writes a vocabulary the same way the form and the API do, and reads it back. */
function save(Publication $publication, string $field, array $valuesByLocale): array
{
    Repo::publication()->edit($publication, [$field => $valuesByLocale]);

    return terms(Repo::publication()->get($publication->getId()), $field);
}

/** Stored names by locale: the data comes back as entry data, not as strings. */
function terms(Publication $publication, string $field): array
{
    $result = [];
    foreach ((array) $publication->getData($field) as $locale => $values) {
        $result[$locale] = array_map(
            fn ($value): string => is_array($value) ? (string) ($value['name'] ?? '') : (string) $value,
            (array) $values
        );
    }
    ksort($result);

    return $result;
}

function configure(array $fields, array $separators): void
{
    global $plugin;
    $plugin->updateSetting(CONTEXT_ID, 'fields', $fields, 'object');
    $plugin->updateSetting(CONTEXT_ID, 'separators', $separators, 'object');
}

//
// Original state, restored at the end
//
$ORIGINAL = [
    'fields' => $plugin->getSetting(CONTEXT_ID, 'fields'),
    'separators' => $plugin->getSetting(CONTEXT_ID, 'separators'),
    'enabled' => $plugin->getEnabled(CONTEXT_ID),
];

try {
    configure(array_values($plugin::FIELDS), ALL_SEPARATORS);

    //
    // BLOCK A — normalize(): spacing, Unicode and dangling punctuation
    //
    block('BLOCK A — cleaning one term (normalize)');

    $normalizeCases = [
        ['A01', 'Acupuntura', 'Acupuntura'],
        ['A02', '  Acupuntura  ', 'Acupuntura'],
        ['A03', "Acupuntura\t", 'Acupuntura'],
        ['A04', 'Medicina   tradicional   chinesa', 'Medicina tradicional chinesa'],
        ['A05', "Educa\u{00A0}ção", 'Educa ção'],                             // NBSP
        ['A06', "Terapias\u{2002}Complementares", 'Terapias Complementares'], // EN SPACE
        ['A07', "Estresse\u{2009}Oxidativo", 'Estresse Oxidativo'],           // THIN SPACE
        ['A08', "Saúde\u{3000}Integrativa", 'Saúde Integrativa'],             // IDEOGRAPHIC SPACE
        ['A09', "Regula\u{200B}mentação", 'Regulamentação'],                  // ZERO WIDTH SPACE
        ['A10', "\u{FEFF}Ozonioterapia", 'Ozonioterapia'],                    // BOM
        ['A11', 'Aparelho Ortopédico.', 'Aparelho Ortopédico'],
        ['A12', 'Aparelho Ortopédico;', 'Aparelho Ortopédico'],
        ['A13', 'Aparelho Ortopédico,', 'Aparelho Ortopédico'],
        ['A14', ': Individual Microentrepreneur', 'Individual Microentrepreneur'],
        ['A15', '     Ozonioterapia', 'Ozonioterapia'],
        ['A16', '- Ozonioterapia', 'Ozonioterapia'],
        ['A17', '– Ozonioterapia', 'Ozonioterapia'],
        ['A18', '. Ozonioterapia .', 'Ozonioterapia'],
        ['A19', '', ''],
        ['A20', '   ', ''],
        ['A21', '...', ''],
        ['A22', ';;;', ''],
        ['A23', 'Lei 13.964/2019', 'Lei 13.964/2019'],
        ['A24', 'S. aureus', 'S. aureus'],
        ['A25', 'COVID-19', 'COVID-19'],
        ['A26', 'Águas (rios e lagos)', 'Águas (rios e lagos)'],
        ['A27', "Educação\ninfantil", 'Educação infantil'],
        ['A28', 'Ensino a distância / EaD', 'Ensino a distância / EaD'],
        ['A29', 'Pesquisa & desenvolvimento', 'Pesquisa & desenvolvimento'],
        ['A30', '“Fake news”', '“Fake news”'],
        ['A31', '1,5 mm', '1,5 mm'],
        ['A32', 'Q&A: métodos', 'Q&A: métodos'],
    ];

    foreach ($normalizeCases as [$id, $input, $expected]) {
        testCase($id, 'normalize(' . summarize($input) . ')', function () use ($input, $expected) {
            assertEquals($expected, Rules::normalize($input));
        });
    }

    //
    // BLOCK B — split() with the three separators enabled
    //
    block('BLOCK B — splitting one string (every separator enabled)');

    $splitCases = [
        // empty input and single terms
        ['B01', '', []],
        ['B02', '   ', []],
        ['B03', 'Acupuntura', ['Acupuntura']],
        ['B04', 'Acupuntura.', ['Acupuntura']],
        ['B05', 'Medicina tradicional chinesa', ['Medicina tradicional chinesa']],

        // semicolon
        ['B06', 'A;B', ['A', 'B']],
        ['B07', 'A; B; C', ['A', 'B', 'C']],
        ['B08', 'A ; B ; C', ['A', 'B', 'C']],
        ['B09', 'Ozonioterapia; Estresse Oxidativo; Regulamentação', ['Ozonioterapia', 'Estresse Oxidativo', 'Regulamentação']],
        ['B10', 'A;;B', ['A', 'B']],
        ['B11', 'A; B;', ['A', 'B']],
        ['B12', ';A; B', ['A', 'B']],

        // comma
        ['B13', 'A, B, C', ['A', 'B', 'C']],
        ['B14', 'Lipedema, Cuidados de Saúde, Doença Crônica', ['Lipedema', 'Cuidados de Saúde', 'Doença Crônica']],
        ['B15', 'A,B', ['A,B']],                              // no space: does not separate
        ['B16', '1,5 mm, 2,5 mm', ['1,5 mm', '2,5 mm']],
        ['B17', 'A, B,', ['A', 'B']],
        ['B18', 'A,  B', ['A', 'B']],

        // period
        ['B19', 'A. B. C', ['A. B. C']],                      // isolated initials: no split
        ['B20', 'Inovação. Meios Poluidores. Moçambique.', ['Inovação', 'Meios Poluidores', 'Moçambique']],
        ['B21', 'Ozonioterapia. Saúde Integrativa', ['Ozonioterapia', 'Saúde Integrativa']],
        ['B22', 'Ozonioterapia.Saúde Integrativa', ['Ozonioterapia.Saúde Integrativa']], // no space
        ['B23', 'saúde pública. educação', ['saúde pública', 'educação']],
        ['B24', 'Acupuntura.  Medicina chinesa', ['Acupuntura', 'Medicina chinesa']],

        // what the period must never break
        ['B25', 'Lei 13.964/2019', ['Lei 13.964/2019']],
        ['B26', 'Lei 13.964/2019. Direito penal', ['Lei 13.964/2019', 'Direito penal']],
        ['B27', 'Decreto 12.456/2025. Meio ambiente. Licenciamento', ['Decreto 12.456/2025', 'Meio ambiente', 'Licenciamento']],
        ['B28', 'S. aureus', ['S. aureus']],
        ['B29', 'S. aureus. Antibióticos', ['S. aureus', 'Antibióticos']],
        ['B30', 'E. coli', ['E. coli']],
        ['B31', 'Bacteremia. E. coli. Tratamento', ['Bacteremia', 'E. coli', 'Tratamento']],
        ['B32', 'C. albicans. Candidíase', ['C. albicans', 'Candidíase']],
        ['B33', 'Vitamina D. Deficiência', ['Vitamina D. Deficiência']],   // lone D is an initial
        ['B34', 'Child and Adolescent Statute (ECA). Educação', ['Child and Adolescent Statute (ECA)', 'Educação']],
        ['B35', 'CF/88. ECA', ['CF/88', 'ECA']],
        ['B36', 'Art. 5º da Constituição', ['Art', '5º da Constituição']], // known limitation

        // precedence
        ['B37', 'A, B; C', ['A, B', 'C']],
        ['B38', 'Hypertension, Pregnancy-Induced; Diabetes', ['Hypertension, Pregnancy-Induced', 'Diabetes']],
        ['B39', 'A. B, C', ['A. B', 'C']],
        ['B40', 'Saúde pública. Educação, Ensino', ['Saúde pública. Educação', 'Ensino']],
        ['B41', 'A; B. C', ['A', 'B. C']],

        // duplicates and case
        ['B42', 'Acupuntura; acupuntura', ['Acupuntura']],
        ['B43', 'Acupuntura; ACUPUNTURA; Agulhas', ['Acupuntura', 'Agulhas']],
        ['B44', 'A; A; A', ['A']],

        // exotic spaces inside the list
        ['B45', "Ozonioterapia.\u{00A0}Saúde Integrativa", ['Ozonioterapia', 'Saúde Integrativa']],
        ['B46', "Ozonioterapia;\u{2002}Saúde Integrativa", ['Ozonioterapia', 'Saúde Integrativa']],
        ['B47', "Terapias\u{2002}Complementares. Regulação", ['Terapias Complementares', 'Regulação']],

        // long real-world lists
        ['B48', 'Ensino Superior. Educação a Distância. Modalidade Presencial. Políticas Educacionais. Gestão Educacional', ['Ensino Superior', 'Educação a Distância', 'Modalidade Presencial', 'Políticas Educacionais', 'Gestão Educacional']],
        ['B49', 'Digital orthodontics. Orthodontic treatment. Clear aligners. Digital planning. Planning software.', ['Digital orthodontics', 'Orthodontic treatment', 'Clear aligners', 'Digital planning', 'Planning software']],
        ['B50', 'Saberes ancestrais; Educação infantil; Povos indígenas; Quilombolas; Educação ambiental', ['Saberes ancestrais', 'Educação infantil', 'Povos indígenas', 'Quilombolas', 'Educação ambiental']],

        // inner punctuation that must survive
        ['B51', 'COVID-19. SARS-CoV-2', ['COVID-19', 'SARS-CoV-2']],
        ['B52', 'Ensino a distância / EaD; Presencial', ['Ensino a distância / EaD', 'Presencial']],
        ['B53', 'Pesquisa & desenvolvimento; Inovação', ['Pesquisa & desenvolvimento', 'Inovação']],
        ['B54', 'Águas (rios e lagos); Solo', ['Águas (rios e lagos)', 'Solo']],
        ['B55', '“Fake news”; Desinformação', ['“Fake news”', 'Desinformação']],
        ['B56', 'Educação 4.0; Indústria 4.0', ['Educação 4.0', 'Indústria 4.0']],
        ['B57', 'p. 25; p. 30', ['p. 25', 'p. 30']],
        ['B58', 'H2O; CO2', ['H2O', 'CO2']],
        ['B59', 'Trabalho: sentidos; Emprego', ['Trabalho: sentidos', 'Emprego']],
        ['B60', 'Saúde do trabalhador — Brasil; Ergonomia', ['Saúde do trabalhador — Brasil', 'Ergonomia']],
    ];

    foreach ($splitCases as [$id, $input, $expected]) {
        testCase($id, 'split(' . summarize($input) . ')', function () use ($input, $expected) {
            assertEquals($expected, Rules::split($input, ALL_SEPARATORS));
        });
    }

    //
    // BLOCK C — separators turned off
    //
    block('BLOCK C — only the chosen separators are used');

    $matrix = [
        ['C01', 'A; B', ['semicolon'], ['A', 'B']],
        ['C02', 'A; B', ['comma'], ['A; B']],
        ['C03', 'A; B', ['period'], ['A; B']],
        ['C04', 'A; B', [], ['A; B']],
        ['C05', 'A, B', ['semicolon'], ['A, B']],
        ['C06', 'A, B', ['comma'], ['A', 'B']],
        ['C07', 'A, B', ['period'], ['A, B']],
        ['C08', 'A, B', [], ['A, B']],
        ['C09', 'Uma. Duas', ['semicolon'], ['Uma. Duas']],
        ['C10', 'Uma. Duas', ['comma'], ['Uma. Duas']],
        ['C11', 'Uma. Duas', ['period'], ['Uma', 'Duas']],
        ['C12', 'Uma. Duas', [], ['Uma. Duas']],
        ['C13', 'Hypertension, Pregnancy-Induced. Diabetes', ['period'], ['Hypertension, Pregnancy-Induced', 'Diabetes']],
        ['C14', 'Hypertension, Pregnancy-Induced. Diabetes', ['comma', 'period'], ['Hypertension', 'Pregnancy-Induced. Diabetes']],
        ['C15', 'A, B; C. D', ['comma', 'period'], ['A', 'B; C. D']],
        // "C." is an initial (a lone letter), so the period there does not separate.
        ['C16', 'A, B; C. D', ['period'], ['A, B; C. D']],
        ['C16b', 'A, B; Cad. Dis', ['period'], ['A, B; Cad', 'Dis']],
        ['C17', 'Ozonioterapia. Regulação', ['semicolon', 'comma'], ['Ozonioterapia. Regulação']],
        ['C18', 'Termo único', ['semicolon', 'comma', 'period'], ['Termo único']],
        ['C19', 'Termo, com vírgula', ['semicolon', 'period'], ['Termo, com vírgula']],
        ['C20', 'Termo. com ponto', ['semicolon', 'comma'], ['Termo. com ponto']],
    ];

    foreach ($matrix as [$id, $input, $separators, $expected]) {
        testCase($id, 'split(' . summarize($input, 28) . ') [' . (implode(',', $separators) ?: 'none') . ']', function () use ($input, $separators, $expected) {
            assertEquals($expected, Rules::split($input, $separators));
        });
    }

    //
    // BLOCK D — splitList(): whole lists, entry data and duplicates
    //
    block('BLOCK D — whole lists, entry data and duplicates');

    testCase('D01', 'a list that is already split does not change', function () {
        assertEquals(['Acupuntura', 'Agulhas'], Rules::splitList(['Acupuntura', 'Agulhas'], ALL_SEPARATORS));
    });
    testCase('D02', 'empty list', function () {
        assertEquals([], Rules::splitList([], ALL_SEPARATORS));
    });
    testCase('D03', 'one pasted record becomes several', function () {
        assertEquals(['A', 'B', 'C'], Rules::splitList(['A; B; C'], ALL_SEPARATORS));
    });
    testCase('D04', 'several records, one of them pasted', function () {
        assertEquals(['Zero', 'A', 'B'], Rules::splitList(['Zero', 'A; B'], ALL_SEPARATORS));
    });
    testCase('D05', 'duplicate across different records', function () {
        assertEquals(['A', 'B'], Rules::splitList(['A; B', 'a'], ALL_SEPARATORS));
    });
    testCase('D06', 'entry data untouched when nothing is split', function () {
        $input = [['name' => 'Acupuntura', 'source' => 'DeCS', 'identifier' => '123']];
        assertEquals($input, Rules::splitList($input, ALL_SEPARATORS));
    });
    testCase('D07', 'entry data that splits becomes plain text', function () {
        $input = [['name' => 'A; B', 'source' => 'DeCS', 'identifier' => '123']];
        assertEquals(['A', 'B'], Rules::splitList($input, ALL_SEPARATORS));
    });
    testCase('D08', 'a dirty name is cleaned, the other keys are kept', function () {
        $input = [['name' => ' Acupuntura. ', 'source' => 'DeCS']];
        assertEquals([['name' => 'Acupuntura', 'source' => 'DeCS']], Rules::splitList($input, ALL_SEPARATORS));
    });
    testCase('D09', 'an empty record drops out of the list', function () {
        assertEquals(['A'], Rules::splitList(['A', '   ', '...'], ALL_SEPARATORS));
    });
    testCase('D10', 'a list of nothing but junk becomes an empty list', function () {
        assertEquals([], Rules::splitList(['   ', ';', '.'], ALL_SEPARATORS));
    });
    testCase('D11', 'idempotent: splitting again changes nothing', function () {
        $once = Rules::splitList(['Inovação. Meios Poluidores. Moçambique.'], ALL_SEPARATORS);
        assertEquals($once, Rules::splitList($once, ALL_SEPARATORS));
    });
    testCase('D12', 'changes() is false when there is nothing to change', function () {
        assertEquals(false, Rules::changes(['Acupuntura', 'Agulhas'], ALL_SEPARATORS));
    });
    testCase('D13', 'changes() is true when there is', function () {
        assertEquals(true, Rules::changes(['Acupuntura; Agulhas'], ALL_SEPARATORS));
    });
    testCase('D14', 'changes() notices punctuation cleanup alone', function () {
        assertEquals(true, Rules::changes(['Acupuntura.'], ALL_SEPARATORS));
    });
    testCase('D15', 'terms keep the order they were written in', function () {
        assertEquals(['Zebra', 'Abelha', 'Macaco'], Rules::splitList(['Zebra; Abelha; Macaco'], ALL_SEPARATORS));
    });

    //
    // BLOCK E — real writes, across the four vocabularies
    //
    block('BLOCK E — real writes (metadata form and REST API path)');

    [, $publication] = newSubmission();

    testCase('E01', 'keywords pasted with periods are stored separately', function () use ($publication) {
        assertEquals(
            ['pt_BR' => ['Ozonioterapia', 'Saúde Integrativa', 'Estresse Oxidativo']],
            save($publication, 'keywords', ['pt_BR' => ['Ozonioterapia. Saúde Integrativa. Estresse Oxidativo.']])
        );
    });
    testCase('E02', 'keywords with semicolons', function () use ($publication) {
        assertEquals(
            ['pt_BR' => ['Acupuntura', 'Agulhas']],
            save($publication, 'keywords', ['pt_BR' => ['Acupuntura; Agulhas']])
        );
    });
    testCase('E03', 'keywords with commas', function () use ($publication) {
        assertEquals(
            ['pt_BR' => ['Lipedema', 'Doença Crônica']],
            save($publication, 'keywords', ['pt_BR' => ['Lipedema, Doença Crônica']])
        );
    });
    testCase('E04', 'three languages in the same write', function () use ($publication) {
        assertEquals(
            [
                'en' => ['Acupuncture', 'Needles'],
                'es' => ['Acupuntura', 'Agujas'],
                'pt_BR' => ['Acupuntura', 'Agulhas'],
            ],
            save($publication, 'keywords', [
                'pt_BR' => ['Acupuntura. Agulhas.'],
                'en' => ['Acupuncture. Needles.'],
                'es' => ['Acupuntura. Agujas.'],
            ])
        );
    });
    testCase('E05', 'a language left out of the write does not survive it (core behaviour)', function () use ($publication) {
        assertEquals(['pt_BR' => ['Só português']], save($publication, 'keywords', ['pt_BR' => ['Só português']]));
    });
    testCase('E06', 'subjects', function () use ($publication) {
        assertEquals(['pt_BR' => ['Saúde', 'Educação']], save($publication, 'subjects', ['pt_BR' => ['Saúde; Educação']]));
    });
    testCase('E07', 'disciplines', function () use ($publication) {
        assertEquals(['pt_BR' => ['Odontologia', 'Ortodontia']], save($publication, 'disciplines', ['pt_BR' => ['Odontologia. Ortodontia.']]));
    });
    testCase('E08', 'supportingAgencies', function () use ($publication) {
        assertEquals(['pt_BR' => ['CNPq', 'CAPES', 'FAPESP']], save($publication, 'supportingAgencies', ['pt_BR' => ['CNPq, CAPES, FAPESP']]));
    });
    testCase('E09', 'a list that is already correct stays as it is', function () use ($publication) {
        assertEquals(['pt_BR' => ['Um', 'Dois', 'Três']], save($publication, 'keywords', ['pt_BR' => ['Um', 'Dois', 'Três']]));
    });
    testCase('E10', 'a legal reference is not broken on write', function () use ($publication) {
        assertEquals(
            ['pt_BR' => ['Lei 13.964/2019', 'Direito penal']],
            save($publication, 'keywords', ['pt_BR' => ['Lei 13.964/2019. Direito penal.']])
        );
    });
    testCase('E11', 'a species name is not broken on write', function () use ($publication) {
        assertEquals(
            ['pt_BR' => ['S. aureus', 'Antibióticos']],
            save($publication, 'keywords', ['pt_BR' => ['S. aureus. Antibióticos']])
        );
    });
    testCase('E12', 'entry data coming from the form is split too', function () use ($publication) {
        assertEquals(
            ['pt_BR' => ['Alfa', 'Beta']],
            save($publication, 'keywords', ['pt_BR' => [['name' => 'Alfa; Beta']]])
        );
    });
    testCase('E13', 'a duplicate is stored only once', function () use ($publication) {
        assertEquals(['pt_BR' => ['Alfa', 'Beta']], save($publication, 'keywords', ['pt_BR' => ['Alfa; Beta; alfa']]));
    });
    testCase('E14', 'an exotic space does not become a different term', function () use ($publication) {
        assertEquals(
            ['pt_BR' => ['Terapias Complementares', 'Regulação']],
            save($publication, 'keywords', ['pt_BR' => ["Terapias\u{00A0}Complementares. Regulação"]])
        );
    });
    testCase('E15', 'an empty list clears the vocabulary', function () use ($publication) {
        assertEquals([], save($publication, 'keywords', ['pt_BR' => []]));
    });
    testCase('E16', 'writing the same value twice does not duplicate it (idempotent)', function () use ($publication) {
        $first = save($publication, 'keywords', ['pt_BR' => ['A. B. C']]);
        $second = save($publication, 'keywords', ['pt_BR' => ['A. B. C']]);
        assertEquals($first, $second);
        assertEquals(['pt_BR' => ['A. B. C']], $second);
    });
    testCase('E17', 'the sequence (seq) matches the order of the terms', function () use ($publication) {
        save($publication, 'keywords', ['pt_BR' => ['Zebra; Abelha; Macaco']]);
        $stored = \Illuminate\Support\Facades\DB::table('controlled_vocabs as cv')
            ->join('controlled_vocab_entries as e', 'e.controlled_vocab_id', '=', 'cv.controlled_vocab_id')
            ->join('controlled_vocab_entry_settings as s', 's.controlled_vocab_entry_id', '=', 'e.controlled_vocab_entry_id')
            ->where('cv.assoc_id', $publication->getId())
            ->where('cv.symbolic', ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD)
            ->where('s.setting_name', 'name')
            ->orderBy('e.seq')
            ->pluck('s.setting_value')
            ->all();
        assertEquals(['Zebra', 'Abelha', 'Macaco'], $stored);
    });

    //
    // BLOCK F — the other write paths
    //
    block('BLOCK F — native XML import and other vocabularies');

    testCase('F01', 'insertBySymbolic directly (the native import path) splits as well', function () use ($publication) {
        Repo::controlledVocab()->insertBySymbolic(
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            ['pt_BR' => ['Importado. Do XML. Nativo']],
            Application::ASSOC_TYPE_PUBLICATION,
            $publication->getId()
        );
        assertEquals(
            ['pt_BR' => ['Importado', 'Do XML', 'Nativo']],
            terms(Repo::publication()->get($publication->getId()), 'keywords')
        );
    });

    testCase('F02', 'incremental import (deleteFirst=false) keeps what was already there', function () use ($publication) {
        Repo::controlledVocab()->insertBySymbolic(
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            ['pt_BR' => ['Base']],
            Application::ASSOC_TYPE_PUBLICATION,
            $publication->getId()
        );
        Repo::controlledVocab()->insertBySymbolic(
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            ['pt_BR' => ['Novo; Outro']],
            Application::ASSOC_TYPE_PUBLICATION,
            $publication->getId(),
            false
        );
        assertEquals(
            ['pt_BR' => ['Base', 'Novo', 'Outro']],
            terms(Repo::publication()->get($publication->getId()), 'keywords')
        );
    });

    testCase('F03', 'a vocabulary that is not a publication one passes through untouched', function () use ($plugin) {
        $input = ['pt_BR' => ['Metodologia; Estatística']];
        assertEquals($input, $plugin->splitVocabs(
            UserInterest::CONTROLLED_VOCAB_INTEREST,
            $input,
            Application::ASSOC_TYPE_PUBLICATION,
            1
        ));
    });

    testCase('F04', 'another assoc type passes through untouched', function () use ($plugin) {
        $input = ['pt_BR' => ['Metodologia; Estatística']];
        assertEquals($input, $plugin->splitVocabs(
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            $input,
            Application::ASSOC_TYPE_USER,
            1
        ));
    });

    testCase('F05', 'an unknown publication is left alone (the journal cannot be told)', function () use ($plugin) {
        $input = ['pt_BR' => ['A; B']];
        assertEquals($input, $plugin->splitVocabs(
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            $input,
            Application::ASSOC_TYPE_PUBLICATION,
            999999
        ));
    });

    testCase('F06', 'a bare string (not an array) is accepted', function () use ($plugin, $publication) {
        assertEquals(
            ['pt_BR' => ['A', 'B']],
            $plugin->splitVocabs(
                ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
                ['pt_BR' => 'A; B'],
                Application::ASSOC_TYPE_PUBLICATION,
                $publication->getId()
            )
        );
    });

    testCase('F07', 'reviewing interests stored for real stay in one piece', function () {
        $user = Repo::user()->get(2);
        assertTrue((bool) $user, 'user 2 does not exist in this installation');

        $before = Repo::userInterest()->getInterestsForUser($user);
        Repo::userInterest()->setInterestsForUser($user, ['Metodologia; Estatística']);
        $after = Repo::userInterest()->getInterestsForUser($user);
        Repo::userInterest()->setInterestsForUser($user, $before);

        assertEquals(['Metodologia; Estatística'], array_values($after));
        assertEquals(array_values($before), array_values(Repo::userInterest()->getInterestsForUser($user)));
    });

    //
    // BLOCK G — per-journal configuration
    //
    block('BLOCK G — the journal configuration is honoured');

    testCase('G01', 'an unticked vocabulary is not touched', function () use ($publication) {
        configure(['keywords'], ALL_SEPARATORS);
        assertEquals(['pt_BR' => ['Saúde; Educação']], save($publication, 'subjects', ['pt_BR' => ['Saúde; Educação']]));
    });
    testCase('G02', 'and the ticked one still is', function () use ($publication) {
        assertEquals(['pt_BR' => ['Saúde', 'Educação']], save($publication, 'keywords', ['pt_BR' => ['Saúde; Educação']]));
    });
    testCase('G03', 'a separator turned off does not separate', function () use ($publication) {
        configure(array_values($GLOBALS['plugin']::FIELDS), ['semicolon']);
        assertEquals(['pt_BR' => ['Saúde. Educação']], save($publication, 'keywords', ['pt_BR' => ['Saúde. Educação']]));
    });
    testCase('G04', 'no separator at all means nothing is split', function () use ($publication) {
        configure(array_values($GLOBALS['plugin']::FIELDS), []);
        assertEquals(['pt_BR' => ['A; B, C. D']], save($publication, 'keywords', ['pt_BR' => ['A; B, C. D']]));
    });
    testCase('G05', 'no vocabulary at all means nothing is split', function () use ($publication) {
        configure([], ALL_SEPARATORS);
        assertEquals(['pt_BR' => ['A; B']], save($publication, 'keywords', ['pt_BR' => ['A; B']]));
    });
    testCase('G06', 'plugin disabled in the journal: core is untouched', function () use ($publication, $plugin) {
        configure(array_values($plugin::FIELDS), ALL_SEPARATORS);
        $plugin->updateSetting(CONTEXT_ID, 'enabled', false, 'bool');
        try {
            assertEquals(['pt_BR' => ['A; B']], save($publication, 'keywords', ['pt_BR' => ['A; B']]));
        } finally {
            $plugin->updateSetting(CONTEXT_ID, 'enabled', true, 'bool');
        }
    });
    testCase('G07', 'enabled again, it splits again', function () use ($publication) {
        assertEquals(['pt_BR' => ['A', 'B']], save($publication, 'keywords', ['pt_BR' => ['A; B']]));
    });
    testCase('G08', 'factory default: everything on', function () use ($plugin) {
        $plugin->updateSetting(CONTEXT_ID, 'fields', null, 'object');
        $plugin->updateSetting(CONTEXT_ID, 'separators', null, 'object');
        assertEquals(array_values($plugin::FIELDS), $plugin->getActiveFields(CONTEXT_ID));
        assertEquals(ALL_SEPARATORS, $plugin->getActiveSeparators(CONTEXT_ID));
    });
    testCase('G09', 'an invalid setting in the database is ignored safely', function () use ($plugin) {
        $plugin->updateSetting(CONTEXT_ID, 'separators', ['tab', 'pipe'], 'object');
        assertEquals([], $plugin->getActiveSeparators(CONTEXT_ID));
        $plugin->updateSetting(CONTEXT_ID, 'fields', ['authors'], 'object');
        assertEquals([], $plugin->getActiveFields(CONTEXT_ID));
        configure(array_values($plugin::FIELDS), ALL_SEPARATORS);
    });

    //
    // BLOCK H — the real archive that motivated the plugin
    //
    block('BLOCK H — real archive');

    $archive = [
        ['H01', 'Técnica de Expansão Palatina. Protocolo Clínico. Aparelho Ortopédico.', 3],
        ['H02', 'Palatal Expansion Technique. Clinical Protocol. Orthopedic appliance.', 3],
        ['H03', 'Lipedema. Cuidados de Saúde. Equipe Multiprofissional. Doença Crônica.', 4],
        ['H04', 'Lipedema. Atención de la Salud. Equipo Multiprofesional.', 3],
        ['H05', 'Ensino Superior. Educação a Distância. Modalidade Presencial. Políticas Educacionais. Gestão Educacional.', 5],
        ['H06', 'Higher Education. Distance Education. Face-to-Face Education. Educational Policies. Educational Management.', 5],
        ['H07', ' Ozonioterapia. Saúde Integrativa. Estresse Oxidativo. Terapias  Complementares. Regulamentação.', 5],
        ['H08', 'Ozone Therapy. Integrative Health. Oxidative Stress. Complementary Therapies.  Regulation.', 5],
        ['H09', 'Acupuntura. Síndrome da disfunção da articulação temporomandibular.  Medicina tradicional chinesa. ', 3],
        ['H10', 'Acupuncture. Temporomandibular joint dysfunction syndrome. Chinese traditional  medicine. ', 3],
        ['H11', 'Ortodontia digital. Tratamento ortodôntico. Alinhadores transparentes. Planejamento digital. Softwares de planejamento.', 5],
        ['H12', ' Educação infantil. Relações humanas. Formação docente. Afetividade.  CF/88. ECA. ', 6],
        ['H13', 'Early childhood education. Human relationships. Teacher education. Affectivity.  Federal Constitution of 1988. Child and Adolescent Statute (ECA).', 6],
        ['H14', ' Saberes ancestrais. Educação infantil. Povos indígenas. Quilombolas.  Educação ambiental.', 5],
        ['H15', 'Inovação. Meios Poluidores. Moçambique.', 3],
    ];

    foreach ($archive as [$id, $input, $howMany]) {
        testCase($id, summarize($input, 56), function () use ($input, $howMany) {
            $terms = Rules::split($input, ALL_SEPARATORS);
            assertEquals($howMany, count($terms), 'number of terms');
            foreach ($terms as $term) {
                assertTrue($term === trim($term), "term with edge whitespace: [{$term}]");
                assertTrue(!str_ends_with($term, '.'), "term ending in a period: [{$term}]");
                assertTrue($term !== '', 'empty term');
            }
        });
    }

    testCase('H16', 'no term of the real archive loses a character', function () use ($archive) {
        foreach ($archive as [$id, $input]) {
            $lettersBefore = preg_replace('/[^\p{L}\p{N}]/u', '', $input);
            $lettersAfter = preg_replace('/[^\p{L}\p{N}]/u', '', implode('', Rules::split($input, ALL_SEPARATORS)));
            assertTrue($lettersBefore === $lettersAfter, "{$id}: letters and digits changed while splitting");
        }
    });

    //
    // BLOCK I — property: joining and splitting must round-trip
    //
    block('BLOCK I — property test over generated lists');

    $vocabulary = [
        'Acupuntura', 'Educação infantil', 'Saúde pública', 'Ozonioterapia', 'Lipedema',
        'Ensino superior', 'Políticas educacionais', 'Estresse oxidativo', 'COVID-19',
        'SARS-CoV-2', 'Lei 13.964/2019', 'Decreto 12.456/2025', 'Educação 4.0',
        'Águas (rios e lagos)', 'Pesquisa & desenvolvimento', 'Ensino a distância / EaD',
        'Trabalho: sentidos', 'Saúde do trabalhador — Brasil', '1,5 mm', 'H2O',
        'Quilombolas', 'Povos indígenas', 'Afetividade', 'CF/88', 'ECA',
    ];

    mt_srand(20260807); // fixed seed: the suite has to be reproducible

    $glues = [
        'semicolon' => ['; ', ';'],
        'comma' => [', '],
        'period' => ['. '],
    ];

    $number = 0;
    foreach (['semicolon', 'comma', 'period'] as $separator) {
        foreach ($glues[$separator] as $glue) {
            for ($round = 0; $round < 45; $round++) {
                $number++;
                $howMany = 2 + ($round % 5);
                $terms = [];
                $used = [];
                while (count($terms) < $howMany) {
                    $candidate = $vocabulary[mt_rand(0, count($vocabulary) - 1)];
                    // The comma is a separator: a term holding one cannot go into
                    // a list joined by commas.
                    if ($separator === 'comma' && str_contains($candidate, ',')) {
                        continue;
                    }
                    $key = mb_strtolower($candidate, 'UTF-8');
                    if (isset($used[$key])) {
                        continue;
                    }
                    $used[$key] = true;
                    $terms[] = $candidate;
                }

                $line = implode($glue, $terms);
                $id = sprintf('I%02d', $number);
                testCase($id, '[' . $separator . '] ' . summarize($line, 52), function () use ($line, $terms, $separator) {
                    assertEquals($terms, Rules::split($line, [$separator]));
                });
            }
        }
    }

    //
    // BLOCK J — repairing what is already stored, as the bundled tool does
    //
    block('BLOCK J — repairing a stored archive');

    testCase('J01', 'a pasted record in the database is repaired by splitList', function () use ($publication, $plugin) {
        // Store it concatenated with the plugin off, the way an old archive looks.
        $plugin->updateSetting(CONTEXT_ID, 'enabled', false, 'bool');
        try {
            save($publication, 'keywords', ['pt_BR' => ['Velho. Acervo. Colado.']]);
            $before = terms(Repo::publication()->get($publication->getId()), 'keywords');
            assertEquals(['pt_BR' => ['Velho. Acervo. Colado.']], $before);
        } finally {
            $plugin->updateSetting(CONTEXT_ID, 'enabled', true, 'bool');
        }

        Repo::controlledVocab()->insertBySymbolic(
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            ['pt_BR' => Rules::splitList($before['pt_BR'], ALL_SEPARATORS)],
            Application::ASSOC_TYPE_PUBLICATION,
            $publication->getId()
        );
        assertEquals(['pt_BR' => ['Velho', 'Acervo', 'Colado']], terms(Repo::publication()->get($publication->getId()), 'keywords'));
    });

    testCase('J02', 'repairing in bulk is idempotent', function () use ($publication) {
        $first = terms(Repo::publication()->get($publication->getId()), 'keywords');
        Repo::controlledVocab()->insertBySymbolic(
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            ['pt_BR' => Rules::splitList($first['pt_BR'], ALL_SEPARATORS)],
            Application::ASSOC_TYPE_PUBLICATION,
            $publication->getId()
        );
        assertEquals($first, terms(Repo::publication()->get($publication->getId()), 'keywords'));
    });

    //
    // BLOCK K — the fixture the browser suite is checked against
    //
    block('BLOCK K — parity fixture for the JavaScript rules');

    testCase('K01', 'writes tests/cases.json with the input and output of every case', function () use ($normalizeCases, $splitCases, $matrix) {
        $fixture = ['normalize' => [], 'split' => []];

        foreach ($normalizeCases as [$id, $input]) {
            $fixture['normalize'][] = ['id' => $id, 'in' => $input, 'out' => Rules::normalize($input)];
        }
        foreach ($splitCases as [$id, $input]) {
            $fixture['split'][] = ['id' => $id, 'in' => $input, 'separators' => ALL_SEPARATORS, 'out' => Rules::split($input, ALL_SEPARATORS)];
        }
        foreach ($matrix as [$id, $input, $separators]) {
            $fixture['split'][] = ['id' => $id, 'in' => $input, 'separators' => $separators, 'out' => Rules::split($input, $separators)];
        }

        $bytes = file_put_contents(
            __DIR__ . '/cases.json',
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        assertTrue($bytes > 0, 'cases.json was not written');
        assertTrue(count($fixture['split']) >= 60, 'fixture is too small');
    });
} catch (Throwable $e) {
    $fatalFailure = $e;
}

//
// Cleanup
//
echo "\n" . str_repeat('=', 78) . "\nCLEANUP\n" . str_repeat('=', 78) . "\n";

$plugin->updateSetting(CONTEXT_ID, 'fields', $ORIGINAL['fields'], 'object');
$plugin->updateSetting(CONTEXT_ID, 'separators', $ORIGINAL['separators'], 'object');
$plugin->updateSetting(CONTEXT_ID, 'enabled', (bool) $ORIGINAL['enabled'], 'bool');
printf(
    "  settings restored: fields=%s separators=%s enabled=%s\n",
    json_encode($plugin->getActiveFields(CONTEXT_ID), JSON_UNESCAPED_SLASHES),
    json_encode($plugin->getActiveSeparators(CONTEXT_ID), JSON_UNESCAPED_SLASHES),
    var_export($plugin->getEnabled(CONTEXT_ID), true)
);

if ($keep) {
    echo '  test submissions KEPT (--keep): ' . implode(', ', $CREATED) . "\n";
} else {
    $deleted = 0;
    foreach ($CREATED as $id) {
        if ($submission = Repo::submission()->get($id)) {
            Repo::submission()->delete($submission);
            $deleted++;
        }
    }
    echo "  test submissions created and removed: {$deleted} (ids " . implode(', ', $CREATED) . ")\n";
}

//
// Score
//
$total = count($RESULTS);
$failures = array_values(array_filter($RESULTS, fn ($result) => !$result['ok']));

echo "\n" . str_repeat('=', 78) . "\n";
printf("SCORE: %d/%d passed\n", $total - count($failures), $total);
echo str_repeat('=', 78) . "\n";
foreach ($failures as $failure) {
    printf("  FAILED %-6s %s\n         %s\n", $failure['id'], $failure['title'], str_replace("\n", "\n         ", $failure['message']));
}
if ($fatalFailure) {
    echo "\nFATAL (suite interrupted): " . $fatalFailure->getMessage() . "\n"
        . $fatalFailure->getFile() . ':' . $fatalFailure->getLine() . "\n";
}

file_put_contents(__DIR__ . '/results.json', json_encode([
    'when' => date('c'),
    'ojs' => Application::get()->getCurrentVersion()->getVersionString(),
    'total' => $total,
    'failures' => count($failures),
    'cases' => $RESULTS,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

exit(count($failures) || $fatalFailure ? 1 : 0);
