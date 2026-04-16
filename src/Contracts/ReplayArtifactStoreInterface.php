<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Contracts;

/**
 * Combines artifact write and read capabilities into a single storage contract.
 *
 * Implementations provide both durable append-only persistence and
 * deterministic ordered reads over the stored artifact stream.
 *
 * The primary stable entry point for obtaining a store is:
 *   ReplayArtifactStore::make(ReplayConfig $config): ReplayArtifactStoreInterface
 */
interface ReplayArtifactStoreInterface extends ReplayArtifactWriterInterface, ReplayArtifactReaderInterface
{
}
