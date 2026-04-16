<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Storage;

/**
 * The persisted durable record owned by apntalk/esl-replay.
 *
 * Derived from a CapturedArtifactEnvelope at write time, but distinct from it.
 * The stored record adds storage-layer metadata (id, storedAt, appendSequence,
 * checksum) while preserving the captured artifact version and payload exactly.
 *
 * This is NOT the same as a CapturedArtifactEnvelope (the input contract),
 * and NOT the same as a ReplayExecutionCandidate (an execution-facing projection).
 * These three concepts must remain distinct.
 *
 * Schema version: 1
 */
final readonly class StoredReplayRecord
{
    /**
     * @param ReplayRecordId             $id                  Storage-layer identity. Assigned at write time.
     * @param string                     $artifactVersion     The replay artifact schema version as captured. Never upgraded silently.
     * @param string                     $artifactName        The artifact name as captured (e.g. "api.dispatch").
     * @param \DateTimeImmutable         $captureTimestamp    UTC timestamp from the capturing runtime (esl-react).
     * @param \DateTimeImmutable         $storedAt            UTC timestamp when this record was persisted.
     * @param int                        $appendSequence      Monotonically increasing position within this adapter stream. Starts at 1.
     * @param string|null                $connectionGeneration Connection generation from the live runtime, if captured.
     * @param string|null                $sessionId           ESL session identifier at capture time, if present.
     * @param string|null                $jobUuid             Background job UUID, if present.
     * @param string|null                $eventName           FreeSWITCH event name, if present.
     * @param string|null                $capturePath         Runtime capture path, if recorded.
     * @param array<string, string>      $correlationIds      Correlation identifiers linking related artifacts.
     * @param array<string, mixed>       $runtimeFlags        Runtime flags recorded at capture time.
     * @param array<string, mixed>       $payload             The raw artifact payload as captured. Never reinterpreted.
     * @param string                     $checksum            SHA-256 over the canonical artifact fields. Integrity only.
     * @param array<string, string>      $tags                Optional indexable tags added at storage time.
     */
    public function __construct(
        public readonly ReplayRecordId $id,
        public readonly string $artifactVersion,
        public readonly string $artifactName,
        public readonly \DateTimeImmutable $captureTimestamp,
        public readonly \DateTimeImmutable $storedAt,
        public readonly int $appendSequence,
        public readonly ?string $connectionGeneration,
        public readonly ?string $sessionId,
        public readonly ?string $jobUuid,
        public readonly ?string $eventName,
        public readonly ?string $capturePath,
        public readonly array $correlationIds,
        public readonly array $runtimeFlags,
        public readonly array $payload,
        public readonly string $checksum,
        public readonly array $tags,
    ) {
        if ($appendSequence < 1) {
            throw new \InvalidArgumentException('appendSequence must be >= 1.');
        }

        if (trim($artifactVersion) === '') {
            throw new \InvalidArgumentException('artifactVersion must not be empty.');
        }

        if (trim($artifactName) === '') {
            throw new \InvalidArgumentException('artifactName must not be empty.');
        }

        if (trim($checksum) === '') {
            throw new \InvalidArgumentException('checksum must not be empty.');
        }
    }
}
