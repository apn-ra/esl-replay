<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Checkpoint;

/**
 * Bounded exact-match checkpoint lookup criteria for operational recovery.
 *
 * At least one field must be provided. This is intentionally not a generic
 * checkpoint search DSL.
 */
final readonly class ReplayCheckpointCriteria
{
    public function __construct(
        public readonly ?string $replaySessionId = null,
        public readonly ?string $jobUuid = null,
        public readonly ?string $pbxNodeSlug = null,
        public readonly ?string $workerSessionId = null,
        public readonly int $limit = 100,
    ) {
        foreach ([
            'replaySessionId' => $this->replaySessionId,
            'jobUuid' => $this->jobUuid,
            'pbxNodeSlug' => $this->pbxNodeSlug,
            'workerSessionId' => $this->workerSessionId,
        ] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw new \InvalidArgumentException("ReplayCheckpointCriteria: {$field} must not be empty when provided.");
            }
        }

        if (
            $this->replaySessionId === null
            && $this->jobUuid === null
            && $this->pbxNodeSlug === null
            && $this->workerSessionId === null
        ) {
            throw new \InvalidArgumentException(
                'ReplayCheckpointCriteria: at least one checkpoint identity field must be provided.',
            );
        }

        if ($this->limit < 1) {
            throw new \InvalidArgumentException('ReplayCheckpointCriteria: limit must be >= 1.');
        }
    }
}
