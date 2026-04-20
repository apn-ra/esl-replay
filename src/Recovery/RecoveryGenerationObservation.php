<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class RecoveryGenerationObservation
{
    public function __construct(
        public string $generationId,
        public int $appendSequence,
        public string $artifactName,
    ) {
        if (trim($this->generationId) === '') {
            throw new \InvalidArgumentException('RecoveryGenerationObservation: generationId must not be empty.');
        }

        if ($this->appendSequence < 1) {
            throw new \InvalidArgumentException('RecoveryGenerationObservation: appendSequence must be >= 1.');
        }

        if (trim($this->artifactName) === '') {
            throw new \InvalidArgumentException('RecoveryGenerationObservation: artifactName must not be empty.');
        }
    }
}
