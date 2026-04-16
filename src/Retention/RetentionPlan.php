<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Retention;

/**
 * Explicit pruning plan for a retained artifact stream.
 */
final readonly class RetentionPlan
{
    /**
     * @param list<int> $prunedSequences
     */
    public function __construct(
        public readonly int $streamBytesBefore,
        public readonly int $streamBytesAfter,
        public readonly int $prunedCount,
        public readonly int $retainedCount,
        public readonly array $prunedSequences,
        public readonly ?int $retainedFirstSequence,
        public readonly ?int $retainedLastSequence,
        public readonly ?int $checkpointFloorSequence,
        public readonly ?int $protectedWindowStartSequence,
        public readonly bool $sizeTargetSatisfied,
    ) {
        if ($this->streamBytesBefore < 0 || $this->streamBytesAfter < 0) {
            throw new \InvalidArgumentException('RetentionPlan: stream byte counts must be >= 0.');
        }

        if ($this->prunedCount < 0 || $this->retainedCount < 0) {
            throw new \InvalidArgumentException('RetentionPlan: record counts must be >= 0.');
        }
    }
}
