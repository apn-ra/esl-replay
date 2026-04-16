<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Execution;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Config\ExecutionConfig;
use Apntalk\EslReplay\Execution\OfflineReplayExecutor;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

final class GuardedReinjectionChaosTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-reinject-chaos-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $found = glob($this->tmpDir . '/*');
        $files = $found !== false ? $found : [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function test_policy_blocked_stream_reports_explicit_rejection_reasons(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::eventRaw());
        $store->write(FakeCapturedArtifact::bgapiDispatch('job-1'));

        $injector = new class implements \Apntalk\EslReplay\Contracts\ReplayInjectorInterface {
            public function inject(\Apntalk\EslReplay\Execution\ReplayExecutionCandidate $candidate): \Apntalk\EslReplay\Execution\InjectionResult
            {
                return new \Apntalk\EslReplay\Execution\InjectionResult('injected');
            }
        };

        $executor = OfflineReplayExecutor::make(
            new ExecutionConfig(
                dryRun: false,
                reinjectionEnabled: true,
                reinjectionArtifactAllowlist: ['api.dispatch'],
            ),
            $store,
            null,
            $injector,
        );

        $result = $executor->execute($executor->plan($store->openCursor()));

        $this->assertSame('artifact type is observational and not injectable', $result->outcomes[0]['reason']);
        $this->assertSame('artifact type is not allowlisted for reinjection', $result->outcomes[1]['reason']);
    }

    public function test_injector_exception_returns_failed_result_with_partial_outcomes(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::bgapiDispatch('job-2'));

        $injector = new class implements \Apntalk\EslReplay\Contracts\ReplayInjectorInterface {
            private int $calls = 0;

            public function inject(\Apntalk\EslReplay\Execution\ReplayExecutionCandidate $candidate): \Apntalk\EslReplay\Execution\InjectionResult
            {
                $this->calls++;
                if ($this->calls === 2) {
                    throw new \RuntimeException('injector boom');
                }

                return new \Apntalk\EslReplay\Execution\InjectionResult('injected');
            }
        };

        $executor = OfflineReplayExecutor::make(
            new ExecutionConfig(
                dryRun: false,
                reinjectionEnabled: true,
                reinjectionArtifactAllowlist: ['api.dispatch', 'bgapi.dispatch'],
            ),
            $store,
            null,
            $injector,
        );

        $result = $executor->execute($executor->plan($store->openCursor()));

        $this->assertFalse($result->success);
        $this->assertSame(1, $result->processedCount);
        $this->assertCount(1, $result->outcomes);
        $this->assertSame('injected', $result->outcomes[0]['action']);
        $this->assertSame('injector boom', $result->error);
    }
}
