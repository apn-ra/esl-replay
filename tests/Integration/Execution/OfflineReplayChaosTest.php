<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Execution;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Config\ExecutionConfig;
use Apntalk\EslReplay\Execution\OfflineReplayExecutor;
use Apntalk\EslReplay\Execution\ReplayHandlerRegistry;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use Apntalk\EslReplay\Tests\Fixtures\FakeReplayRecordHandler;
use PHPUnit\Framework\TestCase;

final class OfflineReplayChaosTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-replay-chaos-' . bin2hex(random_bytes(8));
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

    public function test_handler_exception_mid_stream_returns_failed_result_with_partial_outcomes(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::eventRaw());
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::apiDispatch('sess-002'));

        $handler = new class implements \Apntalk\EslReplay\Contracts\ReplayRecordHandlerInterface {
            private int $calls = 0;

            public function handle(\Apntalk\EslReplay\Storage\StoredReplayRecord $record): \Apntalk\EslReplay\Execution\ReplayHandlerResult
            {
                $this->calls++;
                if ($this->calls === 2) {
                    throw new \RuntimeException('boom on second handled record');
                }

                return new \Apntalk\EslReplay\Execution\ReplayHandlerResult('handled_once');
            }
        };

        $executor = OfflineReplayExecutor::make(
            new ExecutionConfig(dryRun: false),
            $store,
            new ReplayHandlerRegistry(['api.dispatch' => $handler]),
        );

        $result = $executor->execute($executor->plan($store->openCursor()));

        $this->assertFalse($result->success);
        $this->assertSame(2, $result->processedCount);
        $this->assertCount(2, $result->outcomes);
        $this->assertSame('observed', $result->outcomes[0]['action']);
        $this->assertSame('handled_once', $result->outcomes[1]['action']);
        $this->assertSame('boom on second handled record', $result->error);
    }

    public function test_repeated_dry_run_over_same_stream_is_stable_on_documented_fields(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());

        $executor = OfflineReplayExecutor::make(
            new ExecutionConfig(dryRun: true),
            $store,
            new ReplayHandlerRegistry(['api.dispatch' => new FakeReplayRecordHandler()]),
        );

        $resultA = $executor->execute($executor->plan($store->openCursor()));
        $resultB = $executor->execute($executor->plan($store->openCursor()));

        $normalizedA = array_map(
            static fn (array $outcome): array => [
                'artifact_name' => $outcome['artifact_name'],
                'append_sequence' => $outcome['append_sequence'],
                'action' => $outcome['action'],
                'would_dispatch_handler' => $outcome['would_dispatch_handler'],
            ],
            $resultA->outcomes,
        );
        $normalizedB = array_map(
            static fn (array $outcome): array => [
                'artifact_name' => $outcome['artifact_name'],
                'append_sequence' => $outcome['append_sequence'],
                'action' => $outcome['action'],
                'would_dispatch_handler' => $outcome['would_dispatch_handler'],
            ],
            $resultB->outcomes,
        );

        $this->assertSame($normalizedA, $normalizedB);
    }
}
