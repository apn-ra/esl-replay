<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Checkpoint;

use Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\CheckpointException;

/**
 * Higher-level checkpoint lifecycle API.
 *
 * Wraps a ReplayCheckpointStoreInterface and a fixed checkpoint key so that
 * callers only need to supply the cursor, not the full checkpoint structure.
 *
 * IMPORTANT: Saving a checkpoint records progress over persisted artifact data.
 * It does NOT restore a live FreeSWITCH socket or ESL session.
 *
 * Internal — not part of the stable public API.
 */
final class ReplayCheckpointService
{
    public function __construct(
        private readonly ReplayCheckpointStoreInterface $store,
        private readonly string $key,
    ) {}

    /**
     * Persist the current cursor as a checkpoint.
     *
     * @param array<string, mixed> $metadata Optional caller-supplied metadata.
     * @throws CheckpointException on write failure
     */
    public function save(ReplayReadCursor $cursor, array $metadata = []): void
    {
        $checkpoint = new ReplayCheckpoint(
            key: $this->key,
            cursor: $cursor,
            savedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            metadata: $metadata,
        );
        $this->store->save($checkpoint);
    }

    /**
     * Load the most recently saved checkpoint, or null if none exists.
     *
     * @throws CheckpointException on read failure
     */
    public function load(): ?ReplayCheckpoint
    {
        return $this->store->load($this->key);
    }

    /**
     * Whether a checkpoint has been saved for this key.
     */
    public function exists(): bool
    {
        return $this->store->exists($this->key);
    }

    /**
     * Delete the checkpoint for this key. No-op if it does not exist.
     */
    public function clear(): void
    {
        $this->store->delete($this->key);
    }
}
