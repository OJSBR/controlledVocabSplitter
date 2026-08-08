<?php

/**
 * @file plugins/generic/controlledVocabSplitter/SplittingControlledVocabRepository.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SplittingControlledVocabRepository
 *
 * @brief The controlled-vocabulary repository, with the splitting rules applied
 *        on the way in.
 *
 * Every write of keywords, subjects, disciplines and supporting agencies goes
 * through Repo::controlledVocab()->insertBySymbolic(): the publication DAO
 * (metadata form, submission wizard and REST API), the native XML import filter
 * and any plugin that stores vocabulary. Because the facade resolves the
 * repository from the container on every call, replacing that binding covers all
 * of them at once, instead of chasing each entry point with its own hook.
 *
 * Nothing else is overridden, so reads, sequencing and deletion stay exactly as
 * the core wrote them.
 */

namespace APP\plugins\generic\controlledVocabSplitter;

use PKP\controlledVocab\Repository as ControlledVocabRepository;

class SplittingControlledVocabRepository extends ControlledVocabRepository
{
    public function __construct(private ControlledVocabSplitterPlugin $plugin)
    {
    }

    /**
     * @copydoc ControlledVocabRepository::insertBySymbolic()
     */
    public function insertBySymbolic(
        string $symbolic,
        array $vocabs,
        int $assocType,
        ?int $assocId,
        bool $deleteFirst = true,
    ): void {
        parent::insertBySymbolic(
            $symbolic,
            $this->plugin->splitVocabs($symbolic, $vocabs, $assocType, $assocId),
            $assocType,
            $assocId,
            $deleteFirst
        );
    }
}
