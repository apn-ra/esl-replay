<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Contracts;

use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Reads stored replay records deterministically.
 *
 * Ordering guarantee: records are returned in append-sequence order
 * within a single adapter stream. Cross-stream total ordering is not
 * guaranteed unless explicitly documented by a specific implementation.
 *
 * Implementations must:
 * - return records in append-sequence order
 * - honour cursor position for restart-safe reads
 * - return an empty result (not throw) when no records are available
 * - never modify records during read
 */
interface ReplayArtifactReaderInterface
{
    /**
     * Look up a stored record by its storage record id.
     * Returns null if not found.
     */
    public function readById(ReplayRecordId $id): ?StoredReplayRecord;

    /**
     * Read up to $limit records starting after the cursor position.
     *
     * Returns an empty array when no further records exist.
     * The returned records are ordered by appendSequence ascending.
     * When criteria are provided, filtering remains bounded and conservative:
     * append-order and cursor semantics are preserved and no broader query
     * language is implied.
     *
     * @return list<StoredReplayRecord>
     */
    public function readFromCursor(
        ReplayReadCursor $cursor,
        int $limit = 100,
        ?ReplayReadCriteria $criteria = null,
    ): array;

    /**
     * Return a cursor positioned at the start of the stream (before any records).
     */
    public function openCursor(): ReplayReadCursor;
}
