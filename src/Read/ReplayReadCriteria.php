<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Read;

/**
 * Bounded criteria for deterministic append-ordered reads.
 *
 * This is intentionally not a general query DSL. All fields are optional and
 * additive. Readers must continue to apply cursor semantics and append-order
 * semantics exactly as documented.
 */
final readonly class ReplayReadCriteria
{
    public function __construct(
        public readonly ?\DateTimeImmutable $capturedFrom = null,
        public readonly ?\DateTimeImmutable $capturedUntil = null,
        public readonly ?string $artifactName = null,
        public readonly ?string $jobUuid = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $connectionGeneration = null,
    ) {
        foreach ([
            'artifactName' => $this->artifactName,
            'jobUuid' => $this->jobUuid,
            'sessionId' => $this->sessionId,
            'connectionGeneration' => $this->connectionGeneration,
        ] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw new \InvalidArgumentException("ReplayReadCriteria: {$field} must not be empty when provided.");
            }
        }

        if (
            $this->capturedFrom !== null
            && $this->capturedUntil !== null
            && $this->capturedFrom > $this->capturedUntil
        ) {
            throw new \InvalidArgumentException(
                'ReplayReadCriteria: capturedFrom must be less than or equal to capturedUntil.',
            );
        }
    }
}
