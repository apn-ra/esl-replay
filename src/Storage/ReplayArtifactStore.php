<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Storage;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Config\ReplayConfig;
use Apntalk\EslReplay\Config\StorageConfig;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;

/**
 * Primary stable entry point for obtaining a replay artifact store.
 *
 * Usage:
 *   $store = ReplayArtifactStore::make($config);
 *
 * The concrete adapter is selected by ReplayConfig::$storage->adapter.
 * Currently supported: StorageConfig::ADAPTER_FILESYSTEM.
 *
 * This class is a static factory only. It is not itself an adapter.
 */
final class ReplayArtifactStore
{
    /** Prevent instantiation — static factory only. */
    private function __construct() {}

    /**
     * Create a ReplayArtifactStoreInterface implementation from the given config.
     *
     * @throws \InvalidArgumentException if the configured adapter is unknown
     */
    public static function make(ReplayConfig $config): ReplayArtifactStoreInterface
    {
        return match ($config->storage->adapter) {
            StorageConfig::ADAPTER_FILESYSTEM => new FilesystemReplayArtifactStore(
                $config->storage->storagePath,
            ),
            default => throw new \InvalidArgumentException(
                "ReplayArtifactStore: unknown storage adapter '{$config->storage->adapter}'. "
                . "Supported adapters: '" . StorageConfig::ADAPTER_FILESYSTEM . "'.",
            ),
        };
    }
}
