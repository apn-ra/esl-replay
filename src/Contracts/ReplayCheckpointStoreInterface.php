<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Contracts;

use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;

/**
 * Persists and retrieves replay progress checkpoints.
 *
 * A checkpoint records the cursor position of the last consumed artifact so
 * that processing can resume from that position after a restart.
 *
 * IMPORTANT: Checkpoints restore artifact-processing progress only.
 * They do not restore live FreeSWITCH sockets or ESL session state.
 */
interface ReplayCheckpointStoreInterface
{
    /**
     * Persist a checkpoint. Overwrites any existing checkpoint with the same key.
     *
     * @throws \Apntalk\EslReplay\Exceptions\CheckpointException on write failure
     */
    public function save(ReplayCheckpoint $checkpoint): void;

    /**
     * Load a checkpoint by key. Returns null if no checkpoint exists for the key.
     *
     * @throws \Apntalk\EslReplay\Exceptions\CheckpointException on read failure
     */
    public function load(string $key): ?ReplayCheckpoint;

    /**
     * Whether a checkpoint exists for the given key.
     */
    public function exists(string $key): bool;

    /**
     * Delete the checkpoint for the given key. No-op if it does not exist.
     */
    public function delete(string $key): void;
}
