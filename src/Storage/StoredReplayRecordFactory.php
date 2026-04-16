<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Storage;

use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;
use Apntalk\EslReplay\Serialization\ArtifactChecksum;

/**
 * Produces StoredReplayRecord instances from CapturedArtifactEnvelopes.
 *
 * Manages the append sequence counter for a single adapter stream.
 * Each factory instance owns exactly one sequence state — one factory
 * per open storage stream.
 *
 * The factory preserves all artifact fields exactly as captured and adds
 * only storage-layer metadata: id, storedAt, appendSequence, checksum.
 *
 * Internal — not part of the stable public API.
 */
final class StoredReplayRecordFactory
{
    private int $currentSequence;

    /**
     * @param int $initialSequence The sequence number of the last record already in the stream.
     *                             Pass 0 for a new stream. Pass N to continue after N existing records.
     */
    public function __construct(int $initialSequence = 0)
    {
        if ($initialSequence < 0) {
            throw new \InvalidArgumentException('initialSequence must be >= 0.');
        }

        $this->currentSequence = $initialSequence;
    }

    /**
     * Create a StoredReplayRecord from a captured artifact envelope.
     *
     * Increments the internal append sequence. Each call produces a record
     * with a sequence strictly greater than the previous call.
     *
     * The artifact version, name, capture timestamp, and payload are copied
     * verbatim — never upgraded, reinterpreted, or mutated.
     */
    public function fromEnvelope(CapturedArtifactEnvelope $envelope): StoredReplayRecord
    {
        $this->currentSequence++;

        return new StoredReplayRecord(
            id: ReplayRecordId::generate(),
            artifactVersion: $envelope->getArtifactVersion(),
            artifactName: $envelope->getArtifactName(),
            captureTimestamp: $envelope->getCaptureTimestamp(),
            storedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            appendSequence: $this->currentSequence,
            connectionGeneration: $envelope->getConnectionGeneration(),
            sessionId: $envelope->getSessionId(),
            jobUuid: $envelope->getJobUuid(),
            eventName: $envelope->getEventName(),
            capturePath: $envelope->getCapturePath(),
            correlationIds: $envelope->getCorrelationIds(),
            runtimeFlags: $envelope->getRuntimeFlags(),
            payload: $envelope->getPayload(),
            checksum: ArtifactChecksum::compute($envelope),
            tags: [],
        );
    }

    public function currentSequence(): int
    {
        return $this->currentSequence;
    }
}
