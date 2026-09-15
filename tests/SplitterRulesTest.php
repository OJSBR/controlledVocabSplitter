<?php

/**
 * @file plugins/generic/controlledVocabSplitter/tests/SplitterRulesTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SplitterRulesTest
 *
 * @brief The splitting rules and the guard on the core repository, without
 *        writing anything. tests/regression.php covers the real writes.
 */

namespace APP\plugins\generic\controlledVocabSplitter\tests;

use APP\plugins\generic\controlledVocabSplitter\ControlledVocabSplitter as Rules;
use APP\plugins\generic\controlledVocabSplitter\ControlledVocabSplitterPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(Rules::class)]
#[CoversClass(ControlledVocabSplitterPlugin::class)]
class SplitterRulesTest extends PKPTestCase
{
    public function testTermsAreCleanedOfSpacingAndLeftoverPunctuation(): void
    {
        foreach ([
            ["Terapias\u{2002}Complementares", 'Terapias Complementares'],
            ["Regula\u{200B}mentação", 'Regulamentação'],
            ['. Ozonioterapia .', 'Ozonioterapia'],
            ['- Ozonioterapia', 'Ozonioterapia'],
            ['Lei 13.964/2019', 'Lei 13.964/2019'],
            ['...', ''],
        ] as [$input, $expected]) {
            $this->assertSame($expected, Rules::normalize($input), 'normalize(' . json_encode($input) . ')');
        }
    }

    public function testEachSeparatorSplitsOnlyWhereTheAuthorMeantIt(): void
    {
        foreach ([
            ['Palatal Expansion. Clinical Protocol. Orthopedic appliance.', ['Palatal Expansion', 'Clinical Protocol', 'Orthopedic appliance']],
            ['Ozonioterapia; Estresse Oxidativo, Regulamentação', ['Ozonioterapia', 'Estresse Oxidativo, Regulamentação']],
            ['Lipedema, Cuidados de Saúde, Doença Crônica', ['Lipedema', 'Cuidados de Saúde', 'Doença Crônica']],
            ['1,5 mm, 2,5 mm', ['1,5 mm', '2,5 mm']],
            ['A,B', ['A,B']],
            ['S. aureus. E. coli', ['S. aureus', 'E. coli']],
            ['Lei 13.964/2019. Processo penal', ['Lei 13.964/2019', 'Processo penal']],
        ] as [$input, $expected]) {
            $this->assertSame($expected, Rules::split($input), 'split(' . json_encode($input) . ')');
        }
    }

    public function testOnlyTheSeparatorsTheJournalAcceptsAreUsed(): void
    {
        $this->assertSame(['Alpha, Beta', 'Gamma'], Rules::split('Alpha, Beta. Gamma', [Rules::SEPARATOR_PERIOD]));
        $this->assertSame(['Alpha', 'Beta. Gamma'], Rules::split('Alpha, Beta. Gamma', [Rules::SEPARATOR_COMMA]));
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], Rules::splitList(['Alpha; Beta', 'Gamma', 'Alpha'], Rules::SEPARATORS));
    }

    public function testTheServerSideWorksThroughCoreHooksOnly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/ControlledVocabSplitterPlugin.php');
        foreach (['Publication::edit', 'Publication::add', 'nativexmlpublicationfilter::execute'] as $hook) {
            $this->assertTrue(strpos($source, "Hook::add('{$hook}', \$this->") !== false, "The {$hook} hook is not registered.");
        }
        foreach (['app()->bind', 'app()->instance', 'app()->singleton', 'addJavaScript'] as $forbidden) {
            $this->assertFalse(strpos($source, $forbidden) !== false, "The plugin uses {$forbidden}.");
        }
    }

    public function testAPublicationEditIsSplitBeforeItIsSaved(): void
    {
        $plugin = new class () extends ControlledVocabSplitterPlugin {
            public function getEnabled($contextId = null)
            {
                return true;
            }

            public function getSetting($contextId, $name)
            {
                return null;
            }
        };
        $publication = new \APP\publication\Publication();
        $publication->setData('keywords', ['en' => ['Palatal Expansion. Clinical Protocol.']]);
        $publication->setData('subjects', ['en' => ['Left; alone']]);

        $plugin->splitOnEdit('Publication::edit', [&$publication, null, ['keywords' => []], null]);

        $this->assertSame(['en' => ['Palatal Expansion', 'Clinical Protocol']], $publication->getData('keywords'));
        $this->assertSame(['en' => ['Left; alone']], $publication->getData('subjects'), 'A vocabulary that was not edited was touched.');
    }

    public function testTheSiteLevelHasNoSettingsToOpen(): void
    {
        $request = new class () {
            public function getContext()
            {
                return null;
            }

            public function getUserVar($name)
            {
                return $name === 'verb' ? 'settings' : null;
            }

            public function getRouter()
            {
                throw new \RuntimeException('The site level must not build a settings URL.');
            }
        };
        $plugin = new class () extends ControlledVocabSplitterPlugin {
            public function getEnabled($contextId = null)
            {
                return true;
            }
        };

        $this->assertSame([], array_filter($plugin->getActions($request, []), fn ($action) => $action->getId() === 'settings'));
        // Like any action the plugin does not handle, it is left to the core, which refuses it.
        $this->expectExceptionMessage('Unhandled management action!');
        $plugin->manage([], $request);
    }
}
