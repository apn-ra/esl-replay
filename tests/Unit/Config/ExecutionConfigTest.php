<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Config;

use Apntalk\EslReplay\Config\ExecutionConfig;
use PHPUnit\Framework\TestCase;

final class ExecutionConfigTest extends TestCase
{
    public function test_defaults_to_dry_run_true(): void
    {
        $config = new ExecutionConfig();
        $this->assertTrue($config->dryRun);
    }

    public function test_defaults_reinjection_enabled_to_false(): void
    {
        $config = new ExecutionConfig();
        $this->assertFalse($config->reinjectionEnabled);
    }

    public function test_defaults_batch_limit_to_500(): void
    {
        $config = new ExecutionConfig();
        $this->assertSame(500, $config->batchLimit);
    }

    public function test_accepts_dry_run_false(): void
    {
        $config = new ExecutionConfig(dryRun: false);
        $this->assertFalse($config->dryRun);
    }

    public function test_reinjection_enabled_requires_explicit_allowlist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reinjectionArtifactAllowlist/');
        new ExecutionConfig(reinjectionEnabled: true);
    }

    public function test_reinjection_enabled_accepts_explicit_allowlist(): void
    {
        $config = new ExecutionConfig(
            reinjectionEnabled: true,
            reinjectionArtifactAllowlist: ['api.dispatch'],
        );

        $this->assertTrue($config->reinjectionEnabled);
        $this->assertSame(['api.dispatch'], $config->reinjectionArtifactAllowlist);
    }

    public function test_rejects_batch_limit_below_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExecutionConfig(batchLimit: 0);
    }

    public function test_accepts_custom_batch_limit(): void
    {
        $config = new ExecutionConfig(batchLimit: 100);
        $this->assertSame(100, $config->batchLimit);
    }

    public function test_config_is_immutable(): void
    {
        $config = new ExecutionConfig();
        $this->assertTrue((new \ReflectionClass($config))->isReadOnly());
    }
}
