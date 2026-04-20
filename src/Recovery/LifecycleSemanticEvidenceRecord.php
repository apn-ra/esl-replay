<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class LifecycleSemanticEvidenceRecord
{
    /**
     * @param array<string, mixed> $facts
     */
    public function __construct(
        public string $semantic,
        public string $posture,
        public int $appendSequence,
        public string $artifactName,
        public ?string $operationId,
        public array $facts = [],
    ) {
        foreach ([
            'semantic' => $this->semantic,
            'posture' => $this->posture,
            'artifactName' => $this->artifactName,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("LifecycleSemanticEvidenceRecord: {$field} must not be empty.");
            }
        }

        if ($this->appendSequence < 1) {
            throw new \InvalidArgumentException('LifecycleSemanticEvidenceRecord: appendSequence must be >= 1.');
        }

        if ($this->operationId !== null && trim($this->operationId) === '') {
            throw new \InvalidArgumentException(
                'LifecycleSemanticEvidenceRecord: operationId must not be empty when provided.',
            );
        }
    }
}
