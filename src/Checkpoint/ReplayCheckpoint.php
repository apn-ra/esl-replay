<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Checkpoint;

use Apntalk\EslReplay\Cursor\ReplayReadCursor;

/**
 * A persisted checkpoint recording artifact-processing progress.
 *
 * A checkpoint saves the cursor position of the last successfully consumed
 * artifact so that processing can resume from that position after a restart.
 *
 * IMPORTANT: A replay checkpoint restores progress over persisted artifact data.
 * It does NOT restore:
 *  - a live FreeSWITCH socket
 *  - a live ESL session
 *  - runtime continuity
 *  - reconnect state
 *
 * The checkpoint key identifies which processing context this checkpoint belongs to.
 */
final readonly class ReplayCheckpoint
{
    /**
     * @param string                 $key      Identifies the processing context for this checkpoint.
     * @param ReplayReadCursor       $cursor   The cursor position to resume from.
     * @param \DateTimeImmutable     $savedAt  UTC timestamp when this checkpoint was saved.
     * @param array<string, mixed>   $metadata Optional metadata recorded with the checkpoint.
     */
    public function __construct(
        public readonly string $key,
        public readonly ReplayReadCursor $cursor,
        public readonly \DateTimeImmutable $savedAt,
        public readonly array $metadata = [],
    ) {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('ReplayCheckpoint key must not be empty.');
        }
    }
}
