<?php

/**
 * @file tests/regression_http.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief End-to-end suite: logs into the site for real, creates a submission
 *        through the same endpoint the wizard uses, writes vocabulary through
 *        the REST API and checks both what came back in the response AND what
 *        was stored in the database. It also covers the plugin settings screen
 *        and the publication of the script in the backend pages.
 *
 *        Creates and deletes a temporary journal manager; restores the plugin
 *        settings. See tests/CASES.md.
 *
 *        The login form must not ask for a captcha during the run
 *        ([captcha] altcha_on_login = off on a test site): the suite never
 *        tries to solve one.
 *
 * Usage: [CVS_TEST_JOURNAL=path] php plugins/generic/controlledVocabSplitter/tests/regression_http.php [--keep]
 */

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;
use PKP\controlledVocab\ControlledVocab;
use PKP\plugins\PluginRegistry;
use PKP\security\Validation;

$root = dirname(__DIR__, 4);
chdir($root);
define('INDEX_FILE_LOCATION', $root . '/index.php');
require $root . '/lib/pkp/includes/bootstrap.php';

// The journal under test: CVS_TEST_JOURNAL, or the first journal of the site.
$journalPath = getenv('CVS_TEST_JOURNAL') ?: null;
$testContext = $journalPath
    ? Application::getContextDAO()->getByPath($journalPath)
    : Application::getContextDAO()->getAll(true)->next();
if (!$testContext) {
    exit("FATAL: no journal found (set CVS_TEST_JOURNAL to a journal path).\n");
}
define('CONTEXT_ID', (int) $testContext->getId());
define('SECTION_ID', (int) Repo::section()->getCollector()->filterByContextIds([CONTEXT_ID])->getMany()->first()?->getId());
define('UG_MANAGER', (int) \PKP\userGroup\UserGroup::withContextIds([CONTEXT_ID])->withRoleIds([\PKP\security\Role::ROLE_ID_MANAGER])->first()?->id);

const PLUGIN = 'controlledvocabsplitterplugin';
const MANAGER = 'cvstest_manager';
const ALL_SEPARATORS = ['semicolon', 'comma', 'period'];

define('PASSWORD', getenv('CVS_TEST_PASSWORD') ?: ('Cvs!' . bin2hex(random_bytes(9)) . '#Aa1'));

$keep = in_array('--keep', $argv, true);

class RouterWithContext extends PageRouter
{
    private $pinned;
    public function pinContext($context)
    {
        $this->pinned = $context;
    }
    public function getContext(\PKP\core\PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context
    {
        return $this->pinned;
    }
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

$BASE = Config::getVar('general', 'base_url');
$JOURNAL = $context->getPath();
$API = "{$BASE}/index.php/{$JOURNAL}/api/v1";
$PAGE = "{$BASE}/index.php/{$JOURNAL}";
$GRID = "{$BASE}/index.php/{$JOURNAL}/\$\$\$call\$\$\$/grid/settings/plugins/settings-plugin-grid/manage";

//
// Tiny framework
//
$RESULTS = [];

function block(string $title): void
{
    echo "\n" . str_repeat('=', 78) . "\n{$title}\n" . str_repeat('=', 78) . "\n";
}

function testCase(string $id, string $title, callable $body): void
{
    global $RESULTS;
    try {
        $body();
        $RESULTS[] = ['id' => $id, 'title' => $title, 'ok' => true, 'message' => ''];
        printf("  [ PASS ] %-5s %s\n", $id, $title);
    } catch (Throwable $e) {
        $RESULTS[] = ['id' => $id, 'title' => $title, 'ok' => false, 'message' => $e->getMessage()];
        printf("  [ FAIL ] %-5s %s\n            -> %s\n", $id, $title, str_replace("\n", "\n            ", $e->getMessage()));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertEquals($expected, $actual, string $message = 'value differs from the expected one'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\n              expected: " . json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n              actual  : " . json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

//
// HTTP client with a session
//
class Session
{
    public string $jar;
    public ?string $csrf = null;

    public function __construct(string $tag)
    {
        $this->jar = sys_get_temp_dir() . '/cvs_' . $tag . '_' . getmypid() . '.cookies';
        @unlink($this->jar);
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    private function exec(string $method, string $url, $body = null, array $headers = [], bool $json = false): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130 Safari/537.36',
        ]);
        if ($body !== null) {
            if ($json) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body));
                $headers[] = 'Content-Type: application/json';
            } else {
                curl_setopt($handle, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
            }
        }
        if ($headers) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        }
        $response = curl_exec($handle);
        $code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return ['code' => $code, 'body' => (string) $response];
    }

    public function get(string $url): array
    {
        return $this->exec('GET', $url);
    }

    public function postForm(string $url, array $fields): array
    {
        return $this->exec('POST', $url, $fields);
    }

    public function api(string $method, string $url, ?array $body = null): array
    {
        $response = $this->exec($method, $url, $body, ['X-Csrf-Token: ' . $this->csrf, 'Accept: application/json'], true);
        $response['json'] = json_decode($response['body'], true);

        return $response;
    }

    public function login(string $username, string $password, string $uiLocale = 'en'): void
    {
        global $PAGE;
        $this->get("{$PAGE}/{$uiLocale}/index");
        $page = $this->get("{$PAGE}/{$uiLocale}/login");
        if (!preg_match('/name="csrfToken"\s+value="([^"]+)"/', $page['body'], $matches)) {
            throw new RuntimeException('csrfToken not found on the login page');
        }
        $response = $this->postForm("{$PAGE}/{$uiLocale}/login/signIn", [
            'username' => $username, 'password' => $password, 'csrfToken' => $matches[1],
            'remember' => 0, 'source' => '',
        ]);
        if (str_contains($response['body'], 'name="password"')) {
            throw new RuntimeException("login failed for {$username}");
        }
        $this->grabCsrf("{$PAGE}/{$uiLocale}/dashboard");
    }

    public function grabCsrf(string $url): void
    {
        $response = $this->get($url);
        if (!preg_match('/"csrfToken":"([^"]+)"/', $response['body'], $matches)) {
            throw new RuntimeException("csrf not found at {$url} (http {$response['code']})");
        }
        $this->csrf = $matches[1];
    }
}

//
// Helpers
//
function configure(array $fields, array $separators): void
{
    global $plugin;
    $plugin->updateSetting(CONTEXT_ID, 'fields', $fields, 'object');
    $plugin->updateSetting(CONTEXT_ID, 'separators', $separators, 'object');
}

/** Names stored in the database, by locale, read straight from the tables. */
function inDatabase(int $publicationId, string $symbolic = ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD): array
{
    $rows = DB::table('controlled_vocabs as cv')
        ->join('controlled_vocab_entries as e', 'e.controlled_vocab_id', '=', 'cv.controlled_vocab_id')
        ->join('controlled_vocab_entry_settings as s', 's.controlled_vocab_entry_id', '=', 'e.controlled_vocab_entry_id')
        ->where('cv.assoc_id', $publicationId)
        ->where('cv.assoc_type', Application::ASSOC_TYPE_PUBLICATION)
        ->where('cv.symbolic', $symbolic)
        ->where('s.setting_name', 'name')
        ->orderBy('s.locale')->orderBy('e.seq')
        ->get(['s.locale', 's.setting_value as value']);

    $result = [];
    foreach ($rows as $row) {
        $result[$row->locale][] = (string) $row->value;
    }
    ksort($result);

    return $result;
}

/**
 * Just the names, out of what the API returned. Locales with no term at all are
 * dropped: the API answers with every locale of the journal, empty ones
 * included, and that is none of this plugin's business.
 */
function fromApi(array $publication, string $field): array
{
    $result = [];
    foreach ((array) ($publication[$field] ?? []) as $locale => $values) {
        $names = array_map(
            fn ($value): string => is_array($value) ? (string) ($value['name'] ?? '') : (string) $value,
            (array) $values
        );
        if ($names) {
            $result[$locale] = $names;
        }
    }
    ksort($result);

    return $result;
}

$ORIGINAL = [
    'fields' => $plugin->getSetting(CONTEXT_ID, 'fields'),
    'separators' => $plugin->getSetting(CONTEXT_ID, 'separators'),
    'enabled' => $plugin->getEnabled(CONTEXT_ID),
];

$managerId = null;
$CREATED = [];
$fatalFailure = null;

if (Config::getVar('captcha', 'altcha') && Config::getVar('captcha', 'altcha_on_login')) {
    fwrite(STDERR, "The login form asks for a captcha: turn [captcha] altcha_on_login off for the run on a test site.\n");
    exit(2);
}

echo "controlledVocabSplitter — end-to-end suite\n";
echo "site: {$BASE} | journal: {$JOURNAL}\n";

try {
    configure(array_values($plugin::FIELDS), ALL_SEPARATORS);
    $plugin->updateSetting(CONTEXT_ID, 'enabled', true, 'bool');

    $existing = Repo::user()->getByUsername(MANAGER);
    if ($existing) {
        $managerId = $existing->getId();
        DB::table('users')->where('user_id', $managerId)
            ->update(['password' => Validation::encryptCredentials(MANAGER, PASSWORD)]);
    } else {
        $user = Repo::user()->newDataObject();
        $user->setUsername(MANAGER);
        $user->setEmail('cvstest_manager@example.invalid');
        $user->setGivenName('Splitter', 'en');
        $user->setFamilyName('Test Manager', 'en');
        $user->setPassword(Validation::encryptCredentials(MANAGER, PASSWORD));
        $user->setDateRegistered(\Core::getCurrentDate());
        $user->setInlineHelp(1);
        $user->setMustChangePassword(false);
        $managerId = Repo::user()->add($user);
        Repo::userGroup()->assignUserToGroup($managerId, UG_MANAGER);
    }
    echo 'temporary manager: ' . MANAGER . " (user_id={$managerId})\n";

    $session = new Session('manager');
    $session->login(MANAGER, PASSWORD);

    //
    // ------------------------------------------------------------- BLOCK HA
    //
    block('BLOCK HA — session and submission creation');

    $submissionId = null;
    $publicationId = null;

    testCase('HA01', 'manager login and CSRF token', function () use ($session) {
        assertTrue(!empty($session->csrf), 'no csrf after login');
    });

    testCase('HA02', 'POST /submissions creates the submission', function () use ($session, $API, &$submissionId, &$publicationId, &$CREATED) {
        $response = $session->api('POST', "{$API}/submissions", ['locale' => 'pt_BR', 'sectionId' => SECTION_ID]);
        assertTrue($response['code'] === 200, 'http ' . $response['code'] . ': ' . substr($response['body'], 0, 200));
        $submissionId = $response['json']['id'];
        $publicationId = $response['json']['currentPublicationId'];
        $CREATED[] = $submissionId;
        assertTrue((bool) $publicationId, 'no publicationId');
    });

    /** PUT on the endpoint the metadata form uses. */
    $put = function (array $props) use (&$session, &$API, &$submissionId, &$publicationId): array {
        $response = $session->api('PUT', "{$API}/submissions/{$submissionId}/publications/{$publicationId}", $props);
        if ($response['code'] !== 200) {
            throw new RuntimeException('http ' . $response['code'] . ': ' . substr($response['body'], 0, 300));
        }

        return $response['json'];
    };

    //
    // ------------------------------------------------------------- BLOCK HB
    //
    block('BLOCK HB — writing through the REST API (the form path)');

    testCase('HB01', 'period: the API response already comes split', function () use ($put) {
        assertEquals(
            ['pt_BR' => ['Ozonioterapia', 'Saúde Integrativa', 'Estresse Oxidativo']],
            fromApi($put(['keywords' => ['pt_BR' => ['Ozonioterapia. Saúde Integrativa. Estresse Oxidativo.']]]), 'keywords')
        );
    });

    testCase('HB02', 'and the database holds the same', function () use ($publicationId) {
        assertEquals(['pt_BR' => ['Ozonioterapia', 'Saúde Integrativa', 'Estresse Oxidativo']], inDatabase($publicationId));
    });

    testCase('HB03', 'semicolon', function () use ($put) {
        assertEquals(
            ['pt_BR' => ['Acupuntura', 'Agulhas', 'Dor']],
            fromApi($put(['keywords' => ['pt_BR' => ['Acupuntura; Agulhas; Dor']]]), 'keywords')
        );
    });

    testCase('HB04', 'comma', function () use ($put) {
        assertEquals(
            ['pt_BR' => ['Lipedema', 'Doença Crônica']],
            fromApi($put(['keywords' => ['pt_BR' => ['Lipedema, Doença Crônica']]]), 'keywords')
        );
    });

    testCase('HB05', 'every metadata language in one write', function () use ($put, $context) {
        $locales = (array) $context->getSupportedSubmissionMetadataLocales();
        assertTrue(count($locales) > 1, 'the test journal needs more than one metadata language');

        $payload = [];
        $expected = [];
        foreach ($locales as $index => $locale) {
            $payload[$locale] = ["Term {$index}A. Term {$index}B."];
            $expected[$locale] = ["Term {$index}A", "Term {$index}B"];
        }
        ksort($expected);

        assertEquals($expected, fromApi($put(['keywords' => $payload]), 'keywords'));
    });

    testCase('HB06', 'a legal reference is not broken', function () use ($put) {
        assertEquals(
            ['pt_BR' => ['Lei 13.964/2019', 'Direito penal']],
            fromApi($put(['keywords' => ['pt_BR' => ['Lei 13.964/2019. Direito penal.']]]), 'keywords')
        );
    });

    testCase('HB07', 'a species name is not broken', function () use ($put) {
        assertEquals(
            ['pt_BR' => ['S. aureus', 'Antibióticos']],
            fromApi($put(['keywords' => ['pt_BR' => ['S. aureus. Antibióticos']]]), 'keywords')
        );
    });

    testCase('HB08', 'an inverted MeSH heading survives when a semicolon is present', function () use ($put) {
        assertEquals(
            ['pt_BR' => ['Hypertension, Pregnancy-Induced', 'Diabetes']],
            fromApi($put(['keywords' => ['pt_BR' => ['Hypertension, Pregnancy-Induced; Diabetes']]]), 'keywords')
        );
    });

    testCase('HB09', 'subjects', function () use ($put) {
        assertEquals(['pt_BR' => ['Saúde', 'Educação']], fromApi($put(['subjects' => ['pt_BR' => ['Saúde; Educação']]]), 'subjects'));
    });

    testCase('HB10', 'disciplines', function () use ($put) {
        assertEquals(['pt_BR' => ['Odontologia', 'Ortodontia']], fromApi($put(['disciplines' => ['pt_BR' => ['Odontologia. Ortodontia.']]]), 'disciplines'));
    });

    testCase('HB11', 'supportingAgencies', function () use ($put) {
        assertEquals(['pt_BR' => ['CNPq', 'CAPES']], fromApi($put(['supportingAgencies' => ['pt_BR' => ['CNPq, CAPES']]]), 'supportingAgencies'));
    });

    testCase('HB12', 'writing the same value twice changes nothing (idempotent)', function () use ($put, $publicationId) {
        $first = fromApi($put(['keywords' => ['pt_BR' => ['Um. Dois. Três']]]), 'keywords');
        $second = fromApi($put(['keywords' => ['pt_BR' => ['Um. Dois. Três']]]), 'keywords');
        assertEquals($first, $second);
        assertEquals(['pt_BR' => ['Um', 'Dois', 'Três']], inDatabase($publicationId));
    });

    testCase('HB13', 'a list that is already split passes through intact', function () use ($put) {
        assertEquals(
            ['pt_BR' => ['Alfa', 'Beta', 'Gama']],
            fromApi($put(['keywords' => ['pt_BR' => ['Alfa', 'Beta', 'Gama']]]), 'keywords')
        );
    });

    testCase('HB14', 'GET on the publication returns the separate terms', function () use ($session, $API, $submissionId, $publicationId) {
        $response = $session->api('GET', "{$API}/submissions/{$submissionId}/publications/{$publicationId}");
        assertTrue($response['code'] === 200, 'http ' . $response['code']);
        assertEquals(['pt_BR' => ['Alfa', 'Beta', 'Gama']], fromApi($response['json'], 'keywords'));
    });

    testCase('HB15', 'plugin disabled: the API stores it concatenated (core untouched)', function () use ($plugin, $put, $publicationId) {
        $plugin->updateSetting(CONTEXT_ID, 'enabled', false, 'bool');
        try {
            assertEquals(
                ['pt_BR' => ['Desligado. Não separa.']],
                fromApi($put(['keywords' => ['pt_BR' => ['Desligado. Não separa.']]]), 'keywords')
            );
            assertEquals(['pt_BR' => ['Desligado. Não separa.']], inDatabase($publicationId));
        } finally {
            // Without the finally, a failure here would leave the plugin off and
            // knock down every case after it.
            $plugin->updateSetting(CONTEXT_ID, 'enabled', true, 'bool');
        }
    });

    testCase('HB16', 'enabled again, the same write splits', function () use ($put) {
        assertEquals(
            ['pt_BR' => ['Ligado', 'Separa']],
            fromApi($put(['keywords' => ['pt_BR' => ['Ligado. Separa.']]]), 'keywords')
        );
    });

    testCase('HB17', 'a separator turned off in the journal does not separate', function () use ($put) {
        configure(array_values($GLOBALS['plugin']::FIELDS), ['semicolon']);
        try {
            assertEquals(
                ['pt_BR' => ['Só ponto e vírgula. Aqui não separa']],
                fromApi($put(['keywords' => ['pt_BR' => ['Só ponto e vírgula. Aqui não separa']]]), 'keywords')
            );
        } finally {
            configure(array_values($GLOBALS['plugin']::FIELDS), ALL_SEPARATORS);
        }
    });

    //
    // ------------------------------------------------------------- BLOCK HC
    //
    block('BLOCK HC — nothing is published to the browser');

    testCase('HC01', 'the backend loads no script or configuration from the plugin', function () use ($session, $PAGE) {
        $response = $session->get("{$PAGE}/en/dashboard");
        assertTrue($response['code'] === 200, 'http ' . $response['code']);
        assertTrue(!str_contains($response['body'], 'controlledVocabSplitter/js/'), 'a plugin script is still published');
        assertTrue(!str_contains($response['body'], 'ojsbrControlledVocabSplitter'), 'an inline configuration is still published');
    });

    testCase('HC02', 'the submission wizard loads nothing from the plugin either', function () use ($session, $PAGE, $submissionId) {
        $response = $session->get("{$PAGE}/en/submission?id={$submissionId}");
        assertTrue($response['code'] === 200, 'http ' . $response['code']);
        assertTrue(!str_contains($response['body'], 'controlledVocabSplitter/js/'), 'a plugin script is still published');
    });

    //
    // ------------------------------------------------------------- BLOCK HD
    //
    block('BLOCK HD — the plugin settings screen');

    testCase('HD01', 'the form opens with the boxes in their current state', function () use ($session, $GRID) {
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic';
        $response = $session->get($url);
        assertTrue($response['code'] === 200, 'http ' . $response['code']);
        $content = json_decode($response['body'], true)['content'] ?? '';
        assertTrue(str_contains($content, 'controlledVocabSplitterSettingsForm'), 'the form did not come back');
        foreach (['keywords', 'subjects', 'disciplines', 'supportingAgencies'] as $field) {
            assertTrue(str_contains($content, 'value="' . $field . '"'), "checkbox for {$field} is missing");
        }
        foreach (ALL_SEPARATORS as $separator) {
            assertTrue(str_contains($content, 'value="' . $separator . '"'), "checkbox for {$separator} is missing");
        }
    });

    testCase('HD02', 'saving through the form changes the settings', function () use ($session, $GRID, $plugin) {
        $session->grabCsrf($GLOBALS['PAGE'] . '/en/management/settings/website');
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic&save=true';
        $response = $session->postForm($url, [
            'csrfToken' => $session->csrf,
            'fields' => ['keywords', 'subjects'],
            'separators' => ['semicolon'],
        ]);
        assertTrue($response['code'] === 200, 'http ' . $response['code'] . ': ' . substr($response['body'], 0, 200));
        assertEquals(['keywords', 'subjects'], $plugin->getActiveFields(CONTEXT_ID));
        assertEquals(['semicolon'], $plugin->getActiveSeparators(CONTEXT_ID));
    });

    testCase('HD03', 'the form reopens with what was saved ticked', function () use ($session, $GRID) {
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic';
        $content = json_decode($session->get($url)['body'], true)['content'] ?? '';
        assertTrue((bool) preg_match('/value="keywords"[^>]*checked/s', $content), 'keywords should be ticked');
        assertTrue((bool) preg_match('/value="disciplines"\s*\/>/s', $content), 'disciplines should be unticked');
        assertTrue((bool) preg_match('/value="semicolon"[^>]*checked/s', $content), 'semicolon should be ticked');
        assertTrue((bool) preg_match('/value="period"\s*\/>/s', $content), 'period should be unticked');
    });

    testCase('HD04', 'a forged value in the POST is discarded', function () use ($session, $GRID, $plugin) {
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic&save=true';
        $response = $session->postForm($url, [
            'csrfToken' => $session->csrf,
            'fields' => ['keywords', 'authors', '../../etc/passwd'],
            'separators' => ['semicolon', 'pipe'],
        ]);
        assertTrue($response['code'] === 200, 'http ' . $response['code']);
        assertEquals(['keywords'], $plugin->getActiveFields(CONTEXT_ID));
        assertEquals(['semicolon'], $plugin->getActiveSeparators(CONTEXT_ID));
    });

    testCase('HD05', 'without a CSRF token the form does not save', function () use ($session, $GRID, $plugin) {
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic&save=true';
        $session->postForm($url, ['fields' => [], 'separators' => []]);
        assertEquals(['keywords'], $plugin->getActiveFields(CONTEXT_ID));
    });

    testCase('HD06', 'the settings go back to the defaults through the form itself', function () use ($session, $GRID, $plugin) {
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic&save=true';
        $response = $session->postForm($url, [
            'csrfToken' => $session->csrf,
            'fields' => array_values($plugin::FIELDS),
            'separators' => ALL_SEPARATORS,
        ]);
        assertTrue($response['code'] === 200, 'http ' . $response['code']);
        assertEquals(array_values($plugin::FIELDS), $plugin->getActiveFields(CONTEXT_ID));
        assertEquals(ALL_SEPARATORS, $plugin->getActiveSeparators(CONTEXT_ID));
    });

    //
    // ------------------------------------------------------------- BLOCK HE
    //
    block('BLOCK HE — permissions and limits');

    testCase('HE01', 'a logged-out visitor cannot open the settings', function () use ($GRID) {
        $anonymous = new Session('anonymous');
        $url = $GRID . '?verb=settings&plugin=' . PLUGIN . '&category=generic';
        $response = $anonymous->get($url);
        assertTrue($response['code'] !== 200 || !str_contains($response['body'], 'controlledVocabSplitterSettingsForm'), 'the form opened without a login');
    });

    testCase('HE02', 'a logged-out visitor cannot write vocabulary', function () use ($API, $submissionId, $publicationId) {
        $anonymous = new Session('anonymous2');
        $response = $anonymous->api('PUT', "{$API}/submissions/{$submissionId}/publications/{$publicationId}", [
            'keywords' => ['pt_BR' => ['Invasor; Anônimo']],
        ]);
        assertTrue($response['code'] >= 400, 'an anonymous request managed to write (http ' . $response['code'] . ')');
        assertTrue(inDatabase($publicationId) !== ['pt_BR' => ['Invasor', 'Anônimo']], 'anonymous data reached the database');
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
    echo '  submissions KEPT (--keep): ' . implode(', ', $CREATED) . "\n";
    echo '  temporary manager KEPT: ' . MANAGER . " (password in CVS_TEST_PASSWORD)\n";
} else {
    $deleted = 0;
    foreach ($CREATED as $id) {
        if ($submission = Repo::submission()->get($id)) {
            Repo::submission()->delete($submission);
            $deleted++;
        }
    }
    echo "  submissions created and removed: {$deleted} (ids " . implode(', ', $CREATED) . ")\n";

    if ($managerId && ($user = Repo::user()->get($managerId))) {
        Repo::user()->delete($user);
        echo '  temporary manager removed: ' . MANAGER . "\n";
    }
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
    printf("  FAILED %-5s %s\n         %s\n", $failure['id'], $failure['title'], str_replace("\n", "\n         ", $failure['message']));
}
if ($fatalFailure) {
    echo "\nFATAL (suite interrupted): " . $fatalFailure->getMessage() . "\n"
        . $fatalFailure->getFile() . ':' . $fatalFailure->getLine() . "\n";
}

file_put_contents(__DIR__ . '/results_http.json', json_encode([
    'when' => date('c'),
    'ojs' => Application::get()->getCurrentVersion()->getVersionString(),
    'total' => $total,
    'failures' => count($failures),
    'cases' => $RESULTS,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

exit(count($failures) || $fatalFailure ? 1 : 0);
