<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class RuntimeContinuitySnapshot
{
    /**
     * @param list<RecoveryGenerationObservation> $recoveryGenerations
     */
    public function __construct(
        public array $recoveryGenerations,
        public ?string $replayContinuityPosture,
        public ?string $retryPosture,
        public ?string $drainPosture,
        public ?string $reconstructionPosture,
        public ?int $lastObservedAppendSequence,
    ) {
        foreach ([
            'replayContinuityPosture' => $this->replayContinuityPosture,
            'retryPosture' => $this->retryPosture,
            'drainPosture' => $this->drainPosture,
            'reconstructionPosture' => $this->reconstructionPosture,
        ] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw new \InvalidArgumentException("RuntimeContinuitySnapshot: {$field} must not be empty when provided.");
            }
        }

        if ($this->lastObservedAppendSequence !== null && $this->lastObservedAppendSequence < 1) {
            throw new \InvalidArgumentException(
                'RuntimeContinuitySnapshot: lastObservedAppendSequence must be >= 1 when provided.',
            );
        }
    }
}
