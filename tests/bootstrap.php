<?php

/**
 * @file plugins/generic/controlledVocabSplitter/tests/bootstrap.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Bootstrap for the test suite.
 *
 * Under PKP's PHPUnit configuration the application is already loaded. When the
 * suite runs standalone (`php tests/run.php`) from a plugin installed in
 * plugins/generic/controlledVocabSplitter, the OJS 3.5 installation around it is bootstrapped so the
 * plugin classes are compiled against the real PKP classes they extend: a
 * signature that does not match this PKP version is a fatal error, and that is
 * exactly what the suite must catch before a release does.
 */

if (!class_exists('\PKP\plugins\GenericPlugin')) {
    $root = dirname(__DIR__, 4);
    if (!is_file($root . '/lib/pkp/includes/bootstrap.php')) {
        fwrite(STDERR, "The plugin must be installed in plugins/generic/controlledVocabSplitter of an OJS 3.5 installation to run the suite.\n");
        exit(2);
    }
    chdir($root);
    define('INDEX_FILE_LOCATION', $root . '/index.php');
    require_once $root . '/lib/pkp/includes/bootstrap.php';
}

require_once dirname(__DIR__) . '/ControlledVocabSplitterPlugin.php';
require_once dirname(__DIR__) . '/ControlledVocabSplitterSettingsForm.php';
require_once dirname(__DIR__) . '/SplittingControlledVocabRepository.php';
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/PoFile.php';
