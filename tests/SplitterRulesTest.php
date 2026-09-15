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

class SplitterRulesTest extends TestCase
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

    public function testTheRepositoryIsOnlyReplacedWhenTheCoreSignatureMatches(): void
    {
        // The subclass is loaded only after this check; on the installed PKP it must pass.
        $this->assertTrue((new ControlledVocabSplitterPlugin())->isCoreSignatureKnown());
        $source = (string) file_get_contents(dirname(__DIR__) . '/ControlledVocabSplitterPlugin.php');
        $this->assertTrue(strpos($source, "(string) \$method->getReturnType() === 'void'") !== false, 'The guard ignores the return type.');
    }
}
