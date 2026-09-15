<?php

/**
 * @file plugins/generic/controlledVocabSplitter/tests/run.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Standalone runner for the test suite, for servers without PHPUnit:
 *
 *     php plugins/generic/controlledVocabSplitter/tests/run.php
 *
 * Exit code 0 when everything passes, 1 otherwise.
 */

// Use the suite's own assertions even when the application bootstrap makes
// PHPUnit autoloadable: PHPUnit assertions need its runner to report a failure.
define('CONTROLLEDVOCABSPLITTER_STANDALONE_TESTS', true);

require_once __DIR__ . '/bootstrap.php';

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$passed = $failed = 0;
$failures = [];

foreach ($files as $file) {
    require_once $file;
    $class = 'APP\plugins\generic\controlledVocabSplitter\tests\\' . basename($file, '.php');
    if (!class_exists($class)) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract()) {
        continue;
    }

    printf("\n%s\n", $reflection->getShortName());
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        $instance = $reflection->newInstance();
        try {
            $method->invoke($instance);
            $passed++;
            printf("  ok   %s\n", $method->getName());
        } catch (Throwable $e) {
            $failed++;
            $failures[] = sprintf("%s::%s\n    %s", $reflection->getShortName(), $method->getName(), $e->getMessage());
            printf("  FAIL %s\n", $method->getName());
        }
    }
}

printf("\n%s\n", str_repeat('-', 60));
printf("%d passed, %d failed\n", $passed, $failed);

if ($failures) {
    printf("\nFailures:\n\n%s\n", implode("\n\n", $failures));
}

exit($failed === 0 ? 0 : 1);
