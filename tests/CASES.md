# Test cases — controlledVocabSplitter

Three layers of tests, all kept in the repository and none shipped in the release package.
Re-run them **on every OJS upgrade** and on every change to the plugin: the hooks it uses
(`Publication::edit`, `Publication::add`, `nativexmlpublicationfilter::execute`) are core parts.

## PHPUnit

`tests/*Test.php`, on `PKP\tests\PKPTestCase`, run with PKP's configuration from the OJS root:

```bash
lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/controlledVocabSplitter/tests"
```

| File | Subject |
| --- | --- |
| `PluginTest.php` | Every plugin class compiles against the installed PKP; overridden methods keep compatible return types |
| `SplitterRulesTest.php` | Splitting rules, hooks registered and nothing else touched, the edit hook, the site level without a journal |
| `TemplateSafetyTest.php` | Forms post with a CSRF token, translations in attributes are escaped, no core template is replaced |
| `LocaleFilesTest.php` | The 38 locales: codes of this OJS line, same keys, headers, fuzzy state, placeholders |

## Cypress

`cypress/tests/functional/ControlledVocabSplitter.cy.js`, also run by
[pkp-github-actions](https://github.com/pkp/pkp-github-actions) on every push
(`.github/workflows/stable-3_5_0.yml`, `.github/actions/tests.sh`). It enables the plugin, turns a
separator off and puts it back, and — when `submissionId` is given — saves a keyword line entered
as one term and expects separate terms after the save. Parameters (`--env`): `contextPath`,
`adminUser`, `adminPassword`, `submissionId`; the defaults match PKP's CI data set. The login
form must not ask for a captcha during the run.

## Regression suites (test installations only)

```bash
[CVS_TEST_JOURNAL=path] php plugins/generic/controlledVocabSplitter/tests/regression.php
[CVS_TEST_JOURNAL=path] php plugins/generic/controlledVocabSplitter/tests/regression_http.php
```

They create and delete submissions and a temporary manager, switch the plugin on and off while
they run, and **restore everything they touch**. The journal is `CVS_TEST_JOURNAL`, or the first
journal of the site; its first section and its Author and Manager groups are looked up. Run them
as the account that owns the files, never as root, and only from the command line (they refuse a
web request). `regression_http.php` logs in for real: turn `[captcha] altcha_on_login` off for the
run and back on afterwards — the suite refuses to start while the login form asks for a captcha
and never tries to solve one.

Each prints PASS/FAIL per case and exits non-zero on failure; the outcome is also written to
`results.json` and `results_http.json`, which git ignores.

| Block | Subject |
| --- | --- |
| A | `normalize()`: exotic spaces (NBSP, EN SPACE, THIN SPACE, ideographic), zero-width characters, dangling punctuation, empty input |
| B | `split()` with the three separators: precedence, real lists, and what must **never** be cut (`Lei 13.964/2019`, `S. aureus`, `E. coli`, `1,5 mm`, `COVID-19`) |
| C | Only the chosen separators are used — all seven combinations |
| D | `splitList()`: whole lists, entry data with `identifier`/`source`, duplicates across records, idempotence |
| E | Real writes through `Repo::publication()->edit()` across the four vocabularies, multilingual, sequence (`seq`) and idempotence |
| F | The native import hook on stored vocabularies, a direct repository write left untouched (no core class replaced), reviewing interests left untouched |
| G | Per-journal configuration: vocabulary unticked, separator off, plugin off, invalid values in the database |
| H | The real archive that motivated the plugin, including the guarantee that no letter or digit is lost |
| I | Property test: lists generated from a fixed seed (`mt_srand(20260807)`) — joining N terms and splitting must give the same N back |
| J | Repairing an archive that is already stored |
| HA–HE | End to end: session, REST API writes, nothing published to the browser, settings screen, permissions |

## What was checked by hand

- **Command-line native XML import** (2026-09-15, OJS 3.5.0.3): an article exported with
  `tools/importExport.php NativeImportExportPlugin export` and imported back with the keyword line
  `Importado. Do XML nativo. Lei 13.964/2019. S. aureus` was stored as four terms, with the legal
  reference and the species name whole.
- **`tools/fixExistingVocabs.php`** on a submission with concatenated keywords in two languages:
  the dry run listed the change, `--write` stored it, and a second run found nothing to do.
