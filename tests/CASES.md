# Test cases — controlledVocabSplitter

Regression suite for the plugin. Re-run it **on every OJS upgrade** and on every change to the
plugin: the seam it sits on (the container binding for `PKP\controlledVocab\Repository`) and
the Vue field component are core parts that PKP has already changed between minor releases.

## How to run

```bash
php plugins/generic/controlledVocabSplitter/tests/regression.php
php plugins/generic/controlledVocabSplitter/tests/regression_http.php
```

Run them as the account that owns the files, never as root. `regression_http.php` logs in for
real: turn `[captcha] altcha_on_login` off for the run on the test site, and back on afterwards —
the suite refuses to start while the login form asks for a captcha.

The first covers the rules and real writes, through the same path as the metadata form
(`Repo::publication()->edit()`) and as the native XML import
(`Repo::controlledVocab()->insertBySymbolic()`). The second logs into the site for real and
uses the REST API, the plugin settings screen and the backend pages.

Both **restore everything they touch** (plugin settings, submissions created, temporary
manager) and print PASS/FAIL per case, exiting non-zero if anything fails. The outcome is
written to `results.json` and `results_http.json`.

> **Test installations only.** The suites create and delete submissions and a temporary
> manager, and switch the plugin on and off while they run.

The constants at the top of each file describe the reference installation and have to be
adjusted elsewhere: `CONTEXT_ID`, `SECTION_ID`, the Author user group (`UG_AUTHOR`) and the
Manager one (`UG_MANAGER`).

## What each block covers

| Block | Subject |
| --- | --- |
| A | `normalize()`: exotic spaces (NBSP, EN SPACE, THIN SPACE, ideographic), zero-width characters, dangling punctuation, empty input |
| B | `split()` with the three separators: precedence, real lists, and what must **never** be cut (`Lei 13.964/2019`, `S. aureus`, `E. coli`, `1,5 mm`, `COVID-19`) |
| C | Only the chosen separators are used — all seven combinations |
| D | `splitList()`: whole lists, entry data with `identifier`/`source`, duplicates across records, idempotence |
| E | Real writes across the four vocabularies, multilingual, sequence (`seq`) and idempotence |
| F | Native XML import (`insertBySymbolic` directly, with and without `deleteFirst`), other vocabularies and reviewing interests left untouched |
| G | Per-journal configuration: vocabulary unticked, separator off, plugin off, invalid values in the database |
| H | The real archive that motivated the plugin, including the guarantee that no letter or digit is lost |
| I | Property test: lists generated from a fixed seed (`mt_srand(20260807)`) — joining N terms and splitting must give the same N back |
| J | Repairing an archive that is already stored, the way `tools/fixExistingVocabs.php` does |
| K | Writes `cases.json`, the fixture used to prove parity with the JavaScript rules |
| HA–HE | End to end: session, REST API, script publication in the backend, settings screen, permissions |

A functional [Cypress](https://www.cypress.io/) spec lives in
`cypress/tests/functional/ControlledVocabSplitter.cy.js`: it unticks a separator, saves, reopens
the form to assert the change was persisted and puts it back; pastes a keyword line into the
metadata form of `submissionId` and expects separate terms; and runs every case of
`tests/cases.json` that uses all three separators through the browser rules. The parity check
below is still the way to cover the cases with a subset of separators.

## Parity between PHP and JavaScript

The rules exist twice — `ControlledVocabSplitter.php` and `js/controlledVocabSplitter.js` —
because one runs on the server and the other in the browser. They must not drift apart, and the
only honest way to prove that is to run the same input through both real engines.

Block K writes `tests/cases.json` with the input, the separators and the **PHP** output of
every case in blocks A, B and C. In the browser, with the backend open:

```js
const cases = await fetch('/plugins/generic/controlledVocabSplitter/tests/cases.json').then(r => r.json());
const R = window.ojsbrControlledVocabSplitterRules;   // exposed by the plugin itself
cases.normalize.filter(c => R.normalize(c.in) !== c.out);           // must come back empty
```

For the cases that use a subset of separators, evaluate the plugin file cut just before the
part that touches Vue, replacing `window.ojsbrControlledVocabSplitter` with the configuration
of the case:

```js
const src = await fetch('/plugins/generic/controlledVocabSplitter/js/controlledVocabSplitter.js').then(r => r.text());
const cut = src.indexOf('window.ojsbrControlledVocabSplitterRules');
function rulesWith(separators) {
  window.ojsbrControlledVocabSplitter = {separators, fields: ['keywords']};
  return eval(src.slice(0, cut) + 'return {normalize: normalize, split: split};})()');
}
cases.split.filter(c => JSON.stringify(rulesWith(c.separators).split(c.in)) !== JSON.stringify(c.out));
```

Checked in Chrome on 2026-08-07 (OJS 3.5.0.3): 32 `normalize` cases and 81 `split` cases, no
divergence.

## What still has to be tried by hand

- **A real paste** (Ctrl+V / Cmd+V) into the Keywords field of the submission wizard: the list
  must turn into separate tags immediately, without pressing Enter.
- **Typing and pressing Enter**: the same, from the keyboard.
- **Pasting into another language's field**: the tags must land in that language's column,
  leaving the others alone.
- **The "Reviewing interests" field** in the user profile: it is not a publication vocabulary
  and must **not** be split — it is a different widget, and the plugin does not touch it.

Done on 2026-08-07 in Chrome, with a real operating-system paste:
`Ozonioterapia. Estresse Oxidativo. Lei 13.964/2019. Terapias Complementares.` became four
tags, with the legal reference in one piece.
