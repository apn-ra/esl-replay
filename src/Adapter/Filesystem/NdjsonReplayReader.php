<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Adapter\Filesystem;

use Apntalk\EslReplay\Contracts\ReplayArtifactReaderInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\SerializationException;
use Apntalk\EslReplay\Read\ReplayInspectionFields;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Serialization\ReplayArtifactSerializer;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Ordered cursor-based reader for NDJSON artifact files.
 *
 * Reads records sequentially, honouring cursor position for restart-safe reads.
 *
 * Ordering: records are returned in append-sequence ascending order.
 * Records in the file are assumed to be in append-sequence order (guaranteed
 * by NdjsonReplayWriter). The sequence filter ensures correctness even if
 * the byte-offset hint is stale.
 *
 * Byte-offset hint: when provided on the cursor, the reader seeks to that
 * position before scanning. This is a performance optimisation only — the
 * sequence check ensures no record before the cursor is returned even if
 * the hint is stale or points to a position mid-line.
 *
 * readById performs a full sequential scan; it is intended for lookups by
 * known id rather than bulk reads.
 *
 * Internal — not part of the stable public API.
 */
final class NdjsonReplayReader implements ReplayArtifactReaderInterface
{
    private readonly ReplayArtifactSerializer $serializer;

    public function __construct(private readonly string $filePath)
    {
        $this->serializer = new ReplayArtifactSerializer();
    }

    /**
     * Look up a stored record by its storage record id.
     * Performs a sequential scan — O(n) in file length.
     */
    public function readById(ReplayRecordId $id): ?StoredReplayRecord
    {
        if (!file_exists($this->filePath)) {
            return null;
        }

        $handle = @fopen($this->filePath, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line === false || trim($line) === '') {
                    continue;
                }
                try {
                    $record = $this->serializer->deserialize($line);
                    if ($record->id->equals($id)) {
                        return $record;
                    }
                } catch (SerializationException) {
                    // Skip malformed lines without propagating — the file may contain
                    // a partial write from a crash. Correctness: we still scan remaining lines.
                    continue;
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /**
     * Read up to $limit records whose appendSequence is strictly greater than
     * cursor->lastConsumedSequence, in ascending append-sequence order.
     *
     * When cursor->byteOffsetHint is provided the reader seeks to that position
     * first for performance. If the hint is stale the sequence filter ensures
     * only un-consumed records are returned.
     *
     * @return list<StoredReplayRecord>
     */
    public function readFromCursor(
        ReplayReadCursor $cursor,
        int $limit = 100,
        ?ReplayReadCriteria $criteria = null,
    ): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException('readFromCursor limit must be >= 1.');
        }

        return $this->readFilteredFromCursor($cursor, $limit, $criteria);
    }

    /**
     * @return list<StoredReplayRecord>
     */
    private function readFilteredFromCursor(
        ReplayReadCursor $cursor,
        int $limit,
        ?ReplayReadCriteria $criteria,
    ): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $handle = @fopen($this->filePath, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            // Use byte-offset hint as a seek position to avoid scanning from start.
            // The sequence filter below guarantees correctness if the hint is stale.
            if ($cursor->byteOffsetHint !== null && $cursor->byteOffsetHint > 0) {
                $fileSize = @filesize($this->filePath);
                if (
                    is_int($fileSize)
                    && $cursor->byteOffsetHint < $fileSize
                    && fseek($handle, $cursor->byteOffsetHint) === 0
                ) {
                    // Seek succeeded within the current file bounds.
                } else {
                    rewind($handle);
                }
            }

            $records = [];

            while (count($records) < $limit && !feof($handle)) {
                $line = fgets($handle);
                if ($line === false || trim($line) === '') {
                    continue;
                }
                try {
                    $record = $this->serializer->deserialize($line);
                } catch (SerializationException) {
                    // Skip malformed lines
                    continue;
                }

                // Skip records that have already been consumed.
                if ($record->appendSequence <= $cursor->lastConsumedSequence) {
                    continue;
                }

                if (!$this->matchesCriteria($record, $criteria)) {
                    continue;
                }

                $records[] = $record;
            }

            return $records;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Return a cursor positioned before any records — reads start from record 1.
     */
    public function openCursor(): ReplayReadCursor
    {
        return ReplayReadCursor::start();
    }

    private function matchesCriteria(StoredReplayRecord $record, ?ReplayReadCriteria $criteria): bool
    {
        if ($criteria === null) {
            return true;
        }

        if ($criteria->capturedFrom !== null && $record->captureTimestamp < $criteria->capturedFrom) {
            return false;
        }

        if ($criteria->capturedUntil !== null && $record->captureTimestamp > $criteria->capturedUntil) {
            return false;
        }

        if ($criteria->artifactName !== null && $record->artifactName !== $criteria->artifactName) {
            return false;
        }

        if ($criteria->jobUuid !== null && $record->jobUuid !== $criteria->jobUuid) {
            return false;
        }

        if (
            $criteria->replaySessionId !== null
            && ReplayInspectionFields::replaySessionId($record) !== $criteria->replaySessionId
        ) {
            return false;
        }

        if (
            $criteria->pbxNodeSlug !== null
            && ReplayInspectionFields::pbxNodeSlug($record) !== $criteria->pbxNodeSlug
        ) {
            return false;
        }

        if (
            $criteria->workerSessionId !== null
            && ReplayInspectionFields::workerSessionId($record) !== $criteria->workerSessionId
        ) {
            return false;
        }

        if ($criteria->sessionId !== null && $record->sessionId !== $criteria->sessionId) {
            return false;
        }

        if (
            $criteria->connectionGeneration !== null
            && $record->connectionGeneration !== $criteria->connectionGeneration
        ) {
            return false;
        }

        return true;
    }
}
