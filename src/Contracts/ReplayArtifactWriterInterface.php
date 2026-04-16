<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Contracts;

use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;
use Apntalk\EslReplay\Storage\ReplayRecordId;

/**
 * Writes captured artifacts to durable storage.
 *
 * Implementations must:
 * - preserve artifact version and payload exactly as captured
 * - assign a unique storage record id
 * - persist atomically where the storage medium supports it
 * - fail explicitly on write error — never silently drop artifacts
 * - never mutate artifact meaning during persistence
 */
interface ReplayArtifactWriterInterface
{
    /**
     * Persist a captured artifact and return its assigned storage record id.
     *
     * @throws \Apntalk\EslReplay\Exceptions\ArtifactPersistenceException on write failure
     */
    public function write(CapturedArtifactEnvelope $artifact): ReplayRecordId;
}
