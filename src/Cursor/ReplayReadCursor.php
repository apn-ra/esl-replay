<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Cursor;

/**
 * Immutable cursor position for ordered artifact reads.
 *
 * Tracks the last successfully consumed append sequence. Readers use this
 * to resume reading after the last consumed record without re-processing.
 *
 * Ordering guarantee: cursors are valid within a single adapter stream.
 * Cross-stream global ordering is not promised unless explicitly implemented.
 *
 * The byteOffsetHint is an optional performance hint for adapters that support
 * direct seeking. It is never load-bearing for correctness — the adapter must
 * always fall back to sequence-based scanning if the hint is absent or stale.
 */
final readonly class ReplayReadCursor
{
    public function __construct(
        /**
         * The append sequence of the last consumed record.
         * 0 means "no records have been consumed — start from the beginning".
         */
        public readonly int $lastConsumedSequence,
        /**
         * Optional byte offset hint for the start of the next record.
         * Null means the adapter should scan from the beginning or last
         * known safe position.
         */
        public readonly ?int $byteOffsetHint = null,
    ) {
        if ($lastConsumedSequence < 0) {
            throw new \InvalidArgumentException(
                'lastConsumedSequence must be >= 0. Use ReplayReadCursor::start() for a fresh cursor.'
            );
        }

        if ($byteOffsetHint !== null && $byteOffsetHint < 0) {
            throw new \InvalidArgumentException('byteOffsetHint must be >= 0 when provided.');
        }
    }

    /**
     * A cursor positioned before any records — reads will start from the first record.
     */
    public static function start(): self
    {
        return new self(0, 0);
    }

    /**
     * Whether this cursor is at the very beginning (no records consumed yet).
     */
    public function isAtStart(): bool
    {
        return $this->lastConsumedSequence === 0;
    }

    /**
     * Advance the cursor to reflect that a record has been consumed.
     */
    public function advance(int $sequence, ?int $nextByteOffset = null): self
    {
        if ($sequence <= $this->lastConsumedSequence) {
            throw new \InvalidArgumentException(
                "Cannot advance cursor from sequence {$this->lastConsumedSequence} to {$sequence}: new sequence must be strictly greater."
            );
        }

        return new self($sequence, $nextByteOffset);
    }
}
