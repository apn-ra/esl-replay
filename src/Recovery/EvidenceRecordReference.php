<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class EvidenceRecordReference
{
    public function __construct(
        public string $recordId,
        public int $appendSequence,
        public string $artifactName,
        public \DateTimeImmutable $captureTimestamp,
    ) {
        if (trim($this->recordId) === '') {
            throw new \InvalidArgumentException('EvidenceRecordReference: recordId must not be empty.');
        }

        if ($this->appendSequence < 1) {
            throw new \InvalidArgumentException('EvidenceRecordReference: appendSequence must be >= 1.');
        }

        if (trim($this->artifactName) === '') {
            throw new \InvalidArgumentException('EvidenceRecordReference: artifactName must not be empty.');
        }
    }
}
