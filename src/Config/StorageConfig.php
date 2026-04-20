<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Config;

/**
 * Immutable configuration for the artifact storage layer.
 */
final readonly class StorageConfig
{
    public const ADAPTER_FILESYSTEM = 'filesystem';
    public const ADAPTER_SQLITE = 'sqlite';
    public const ADAPTER_DATABASE = 'database';

    /**
     * @param string $storagePath  Filesystem adapter: artifact directory path. SQLite/database adapter:
     *                             SQLite database file path.
     * @param string $adapter      Storage adapter identifier. Supported: 'filesystem', 'sqlite',
     *                             and the compatibility alias 'database' (normalized to 'sqlite').
     */
    public function __construct(
        public readonly string $storagePath,
        public readonly string $adapter = self::ADAPTER_FILESYSTEM,
    ) {
        if (trim($storagePath) === '') {
            throw new \InvalidArgumentException('StorageConfig: storagePath must not be empty.');
        }

        if (trim($this->adapter) === '') {
            throw new \InvalidArgumentException('StorageConfig: adapter must not be empty.');
        }
    }
}
