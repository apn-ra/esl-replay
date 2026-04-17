<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Config;

use Apntalk\EslReplay\Config\StorageConfig;
use PHPUnit\Framework\TestCase;

final class StorageConfigTest extends TestCase
{
    public function test_constructs_with_valid_values(): void
    {
        $config = new StorageConfig('/var/replay', StorageConfig::ADAPTER_FILESYSTEM);
        $this->assertSame('/var/replay', $config->storagePath);
        $this->assertSame(StorageConfig::ADAPTER_FILESYSTEM, $config->adapter);
    }

    public function test_accepts_sqlite_adapter(): void
    {
        $config = new StorageConfig('/var/replay.sqlite', StorageConfig::ADAPTER_SQLITE);
        $this->assertSame(StorageConfig::ADAPTER_SQLITE, $config->adapter);
    }

    public function test_accepts_database_alias_adapter(): void
    {
        $config = new StorageConfig('/var/replay.sqlite', StorageConfig::ADAPTER_DATABASE);
        $this->assertSame(StorageConfig::ADAPTER_DATABASE, $config->adapter);
    }

    public function test_default_adapter_is_filesystem(): void
    {
        $config = new StorageConfig('/var/replay');
        $this->assertSame(StorageConfig::ADAPTER_FILESYSTEM, $config->adapter);
    }

    public function test_rejects_empty_storage_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageConfig('');
    }

    public function test_rejects_whitespace_only_storage_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageConfig('   ');
    }

    public function test_rejects_empty_adapter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StorageConfig('/var/replay', '');
    }

    public function test_config_is_immutable(): void
    {
        $config = new StorageConfig('/var/replay');
        $this->assertTrue((new \ReflectionClass($config))->isReadOnly());
    }
}
