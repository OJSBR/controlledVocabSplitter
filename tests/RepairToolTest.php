<?php

/**
 * @file plugins/generic/controlledVocabSplitter/tests/RepairToolTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RepairToolTest
 *
 * @brief The journals (or presses) tools/fixExistingVocabs.php goes through.
 *        The context DAO is real except for its two database reads, so the
 *        DAOResultFactory that getAll() returns is the one of the installed
 *        OJS or OMP.
 */

namespace APP\plugins\generic\controlledVocabSplitter\tests;

use APP\core\Application;
use APP\plugins\generic\controlledVocabSplitter\ControlledVocabSplitterPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\context\ContextDAO;
use PKP\db\DAORegistry;
use PKP\tests\PKPTestCase;

#[CoversClass(ControlledVocabSplitterPlugin::class)]
class RepairToolTest extends PKPTestCase
{
    /** Rows of the mocked context table, by id. */
    private const ROWS = [
        1 => 'first-journal',
        2 => 'second-journal',
    ];

    protected function getMockedDAOs(): array
    {
        // JournalDAO in OJS, PressDAO in OMP.
        return [...parent::getMockedDAOs(), (new \ReflectionClass(Application::getContextDAO()))->getShortName()];
    }

    public function testEveryEnabledContextIsListedWithoutAPath(): void
    {
        $dao = $this->mockContextDao();

        // What broke the tool on OJS 3.5: iterator_to_array() refuses this.
        $this->assertNotInstanceOf(\Traversable::class, $dao->getAll(true));

        $contexts = ControlledVocabSplitterPlugin::getContextsToRepair();
        $this->assertSame(array_keys(self::ROWS), array_map(fn ($context) => (int) $context->getId(), $contexts));
        $this->assertSame(array_values(self::ROWS), array_map(fn ($context) => $context->getPath(), $contexts));
    }

    public function testOnlyTheContextAtThePathIsListed(): void
    {
        $this->mockContextDao();

        $contexts = ControlledVocabSplitterPlugin::getContextsToRepair('second-journal');
        $this->assertSame([2], array_map(fn ($context) => (int) $context->getId(), $contexts));
        $this->assertSame([], ControlledVocabSplitterPlugin::getContextsToRepair('no-such-journal'));
    }

    public function testTheToolListsTheContextsThroughThePlugin(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/tools/fixExistingVocabs.php');
        $this->assertStringContainsString('ControlledVocabSplitterPlugin::getContextsToRepair(', $source);
        $this->assertStringNotContainsString('iterator_to_array', $source);
    }

    /**
     * Register a context DAO whose reads come from self::ROWS instead of the database.
     */
    private function mockContextDao(): ContextDAO
    {
        $real = Application::getContextDAO();
        $fromRow = function (array $row) use ($real) {
            $context = $real->newDataObject();
            $context->setId((int) $row['id']);
            $context->setPath($row['path']);
            return $context;
        };
        $rows = fn (array $ids) => (function () use ($ids) {
            foreach ($ids as $id) {
                yield (object) ['id' => $id, 'path' => self::ROWS[$id]];
            }
        })();

        $dao = $this->getMockBuilder(get_class($real))
            ->onlyMethods(['retrieve', 'retrieveRange', '_fromRow'])
            ->getMock();
        $dao->method('_fromRow')->willReturnCallback($fromRow);
        $dao->method('retrieveRange')->willReturnCallback(fn () => $rows(array_keys(self::ROWS)));
        $dao->method('retrieve')->willReturnCallback(
            fn (string $sql, array $params = []) => $rows(array_keys(self::ROWS, $params[0] ?? null, true))
        );

        DAORegistry::registerDAO((new \ReflectionClass($real))->getShortName(), $dao);

        return $dao;
    }
}
