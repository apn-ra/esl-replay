<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Checkpoint;

use Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\CheckpointException;

/**
 * Resolves whether artifact processing should start fresh or resume from a checkpoint.
 *
 * Use this at startup to decide where to begin reading:
 *   $state = ExecutionResumeState::resolve($store, $key);
 *   $cursor = $state->cursor; // safe starting position
 *
 * IMPORTANT: Resuming from a checkpoint means resuming artifact-processing progress
 * only. It does NOT restore a live FreeSWITCH socket, ESL session, or any
 * runtime continuity from apntalk/esl-react.
 *
 * Internal — not part of the stable public API.
 */
final readonly class ExecutionResumeState
{
    private function __construct(
        /**
         * The cursor position at which processing should begin.
         * Either ReplayReadCursor::start() for a fresh run or the saved checkpoint cursor.
         */
        public readonly ReplayReadCursor $cursor,

        /**
         * True if this state was resolved from a saved checkpoint.
         * False if starting fresh (no checkpoint exists for the key).
         */
        public readonly bool $isResuming,

        /**
         * The checkpoint that was loaded, or null when starting fresh.
         */
        public readonly ?ReplayCheckpoint $fromCheckpoint,
    ) {}

    /**
     * Resolve the starting cursor by consulting the checkpoint store.
     *
     * If a checkpoint exists for $key, returns a resuming state with the
     * saved cursor. Otherwise returns a fresh-start state at sequence 0.
     *
     * @throws CheckpointException if the checkpoint store cannot be read
     */
    public static function resolve(
        ReplayCheckpointStoreInterface $store,
        string $key,
    ): self {
        $checkpoint = $store->load($key);

        if ($checkpoint !== null) {
            return new self(
                cursor: $checkpoint->cursor,
                isResuming: true,
                fromCheckpoint: $checkpoint,
            );
        }

        return new self(
            cursor: ReplayReadCursor::start(),
            isResuming: false,
            fromCheckpoint: null,
        );
    }

    /**
     * Construct a fresh-start state unconditionally (no checkpoint lookup).
     */
    public static function fresh(): self
    {
        return new self(
            cursor: ReplayReadCursor::start(),
            isResuming: false,
            fromCheckpoint: null,
        );
    }
}
