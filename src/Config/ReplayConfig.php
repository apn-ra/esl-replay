<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Config;

/**
 * Top-level immutable configuration for the replay platform.
 *
 * Composes storage, checkpoint, and execution configuration.
 * Checkpoint and execution configs are optional and may be added
 * as the consuming application requires those subsystems.
 */
final readonly class ReplayConfig
{
    public function __construct(
        public readonly StorageConfig $storage,
        public readonly ?CheckpointConfig $checkpoint = null,
        public readonly ?ExecutionConfig $execution = null,
    ) {
    }
}
