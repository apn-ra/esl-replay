<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Execution;

use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Execution-facing projection derived from a stored replay record.
 *
 * This remains distinct from both the captured artifact envelope and the stored
 * replay record. Only explicitly classified records become candidates.
 */
final readonly class ReplayExecutionCandidate
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly StoredReplayRecord $sourceRecord,
        public readonly string $artifactName,
        public readonly int $appendSequence,
        public readonly \DateTimeImmutable $capturedAt,
        public readonly array $payload,
    ) {
        if (trim($this->artifactName) === '') {
            throw new \InvalidArgumentException('ReplayExecutionCandidate: artifactName must not be empty.');
        }
    }
}
