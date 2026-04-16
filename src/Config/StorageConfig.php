<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Config;

/**
 * Immutable configuration for the artifact storage layer.
 */
final readonly class StorageConfig
{
    public const ADAPTER_FILESYSTEM = 'filesystem';

    /**
     * @param string $storagePath  Absolute path to the directory where artifacts are stored.
     * @param string $adapter      Storage adapter identifier. Currently only 'filesystem' is supported.
     */
    public function __construct(
        public readonly string $storagePath,
        public readonly string $adapter = self::ADAPTER_FILESYSTEM,
    ) {
        if (trim($storagePath) === '') {
            throw new \InvalidArgumentException('StorageConfig: storagePath must not be empty.');
        }

        if (trim($adapter) === '') {
            throw new \InvalidArgumentException('StorageConfig: adapter must not be empty.');
        }
    }
}
