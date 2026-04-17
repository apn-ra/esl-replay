<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Checkpoint;

use Apntalk\EslReplay\Contracts\ReplayCheckpointInspectorInterface;
use Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;

/**
 * First-class checkpoint repository for operational save/load/find flows.
 */
final readonly class ReplayCheckpointRepository
{
    public function __construct(
        private ReplayCheckpointStoreInterface $store,
    ) {}

    public function save(ReplayCheckpointReference $reference, ReplayReadCursor $cursor): ReplayCheckpoint
    {
        $checkpoint = new ReplayCheckpoint(
            key: $reference->key,
            cursor: $cursor,
            savedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            metadata: $reference->metadataWithIdentityAnchors(),
        );

        $this->store->save($checkpoint);

        return $checkpoint;
    }

    public function load(string $key): ?ReplayCheckpoint
    {
        return $this->store->load($key);
    }

    public function exists(string $key): bool
    {
        return $this->store->exists($key);
    }

    public function delete(string $key): void
    {
        $this->store->delete($key);
    }

    /**
     * @return list<ReplayCheckpoint>
     */
    public function find(ReplayCheckpointCriteria $criteria): array
    {
        if (!$this->store instanceof ReplayCheckpointInspectorInterface) {
            throw new \LogicException(
                'ReplayCheckpointRepository: the configured checkpoint store does not support bounded checkpoint queries.',
            );
        }

        return $this->store->find($criteria);
    }
}
