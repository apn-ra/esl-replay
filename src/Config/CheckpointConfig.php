<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Config;

/**
 * Immutable configuration for the checkpoint layer.
 *
 * Checkpoints persist artifact-processing progress only.
 * They do not restore live FreeSWITCH socket sessions or runtime continuity.
 */
final readonly class CheckpointConfig
{
    /**
     * @param string $storagePath   Absolute path to the directory where checkpoints are stored.
     * @param string $checkpointKey Identifier for the checkpoint within the storage directory.
     *                              Safe characters: a-z, A-Z, 0-9, hyphen, underscore, dot.
     */
    public function __construct(
        public readonly string $storagePath,
        public readonly string $checkpointKey,
    ) {
        if (trim($storagePath) === '') {
            throw new \InvalidArgumentException('CheckpointConfig: storagePath must not be empty.');
        }

        if (trim($checkpointKey) === '') {
            throw new \InvalidArgumentException('CheckpointConfig: checkpointKey must not be empty.');
        }
    }
}
