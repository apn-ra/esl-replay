<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Config;

use Apntalk\EslReplay\Config\CheckpointConfig;
use PHPUnit\Framework\TestCase;

final class CheckpointConfigTest extends TestCase
{
    public function test_constructs_with_valid_values(): void
    {
        $config = new CheckpointConfig('/var/checkpoints', 'my-processor');
        $this->assertSame('/var/checkpoints', $config->storagePath);
        $this->assertSame('my-processor', $config->checkpointKey);
    }

    public function test_rejects_empty_storage_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CheckpointConfig('', 'key');
    }

    public function test_rejects_empty_checkpoint_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CheckpointConfig('/var/checkpoints', '');
    }

    public function test_rejects_whitespace_only_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CheckpointConfig('   ', 'key');
    }

    public function test_rejects_whitespace_only_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CheckpointConfig('/var/checkpoints', '   ');
    }

    public function test_config_is_immutable(): void
    {
        $config = new CheckpointConfig('/var/checkpoints', 'key');
        $this->assertTrue((new \ReflectionClass($config))->isReadOnly());
    }
}
