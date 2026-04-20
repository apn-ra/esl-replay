<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class ReconstructionIssue
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $kind,
        public string $code,
        public string $message,
        public ?int $appendSequence = null,
        public ?string $artifactName = null,
        public array $details = [],
    ) {
        foreach ([
            'kind' => $this->kind,
            'code' => $this->code,
            'message' => $this->message,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("ReconstructionIssue: {$field} must not be empty.");
            }
        }

        if ($this->appendSequence !== null && $this->appendSequence < 1) {
            throw new \InvalidArgumentException('ReconstructionIssue: appendSequence must be >= 1 when provided.');
        }

        if ($this->artifactName !== null && trim($this->artifactName) === '') {
            throw new \InvalidArgumentException('ReconstructionIssue: artifactName must not be empty when provided.');
        }
    }
}
