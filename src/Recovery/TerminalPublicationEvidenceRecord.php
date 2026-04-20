<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class TerminalPublicationEvidenceRecord
{
    /**
     * @param array<string, mixed> $facts
     */
    public function __construct(
        public string $publicationId,
        public string $status,
        public int $appendSequence,
        public string $artifactName,
        public ?string $operationId,
        public array $facts = [],
    ) {
        foreach ([
            'publicationId' => $this->publicationId,
            'status' => $this->status,
            'artifactName' => $this->artifactName,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("TerminalPublicationEvidenceRecord: {$field} must not be empty.");
            }
        }

        if ($this->appendSequence < 1) {
            throw new \InvalidArgumentException('TerminalPublicationEvidenceRecord: appendSequence must be >= 1.');
        }

        if ($this->operationId !== null && trim($this->operationId) === '') {
            throw new \InvalidArgumentException(
                'TerminalPublicationEvidenceRecord: operationId must not be empty when provided.',
            );
        }
    }
}
