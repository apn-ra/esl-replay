<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Config\ExecutionConfig;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Execution\OfflineReplayExecutor;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for offline replay planning and execution.
 *
 * Offline replay operates entirely on stored artifacts.
 * No live FreeSWITCH socket is required.
 */
final class OfflineReplayExecutorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-executor-test-' . bin2hex(random_bytes(8));
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

    private function makeStore(): FilesystemReplayArtifactStore
    {
        return new FilesystemReplayArtifactStore($this->tmpDir);
    }

    public function test_plan_with_empty_store_produces_empty_plan(): void
    {
        $store    = $this->makeStore();
        $executor = OfflineReplayExecutor::make(new ExecutionConfig(), $store);
        $plan     = $executor->plan($store->openCursor());

        $this->assertTrue($plan->isEmpty());
        $this->assertSame(0, $plan->recordCount);
    }

    public function test_plan_includes_all_available_records(): void
    {
        $store = $this->makeStore();
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());
        $store->write(FakeCapturedArtifact::bgapiDispatch());

        $executor = OfflineReplayExecutor::make(new ExecutionConfig(), $store);
        $plan     = $executor->plan($store->openCursor());

        $this->assertSame(3, $plan->recordCount);
        $this->assertFalse($plan->isEmpty());
    }

    public function test_plan_respects_batch_limit(): void
    {
        $store = $this->makeStore();
        for ($i = 0; $i < 10; $i++) {
            $store->write(FakeCapturedArtifact::apiDispatch());
        }

        $executor = OfflineReplayExecutor::make(new ExecutionConfig(batchLimit: 3), $store);
        $plan     = $executor->plan($store->openCursor());

        $this->assertSame(3, $plan->recordCount);
    }

    public function test_plan_inherits_dry_run_flag_from_config(): void
    {
        $store    = $this->makeStore();
        $executor = OfflineReplayExecutor::make(new ExecutionConfig(dryRun: false), $store);
        $plan     = $executor->plan($store->openCursor());

        $this->assertFalse($plan->isDryRun);
    }

    public function test_execute_dry_run_skips_all_records(): void
    {
        $store = $this->makeStore();
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());

        $executor = OfflineReplayExecutor::make(new ExecutionConfig(dryRun: true), $store);
        $plan     = $executor->plan($store->openCursor());
        $result   = $executor->execute($plan);

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->processedCount);
        $this->assertSame(2, $result->skippedCount);
        $this->assertCount(2, $result->outcomes);
        $this->assertSame('dry_run_skip', $result->outcomes[0]['action']);
    }

    public function test_execute_live_mode_processes_all_records(): void
    {
        $store = $this->makeStore();
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());
        $store->write(FakeCapturedArtifact::bgapiDispatch());

        $executor = OfflineReplayExecutor::make(new ExecutionConfig(dryRun: false), $store);
        $plan     = $executor->plan($store->openCursor());
        $result   = $executor->execute($plan);

        $this->assertTrue($result->success);
        $this->assertSame(3, $result->processedCount);
        $this->assertSame(0, $result->skippedCount);
        $this->assertCount(3, $result->outcomes);
        $this->assertSame('observed', $result->outcomes[0]['action']);
    }

    public function test_plan_from_cursor_only_includes_unconsumed_records(): void
    {
        $store = $this->makeStore();
        $store->write(FakeCapturedArtifact::apiDispatch()); // seq 1
        $store->write(FakeCapturedArtifact::eventRaw());     // seq 2
        $store->write(FakeCapturedArtifact::bgapiDispatch()); // seq 3

        // Simulate having already consumed record 1
        $cursor   = ReplayReadCursor::start()->advance(1);
        $executor = OfflineReplayExecutor::make(new ExecutionConfig(), $store);
        $plan     = $executor->plan($cursor);

        // Only records 2 and 3 should appear
        $this->assertSame(2, $plan->recordCount);
        $this->assertSame(2, $plan->records[0]->appendSequence);
        $this->assertSame(3, $plan->records[1]->appendSequence);
    }

    public function test_execute_result_contains_record_ids(): void
    {
        $store = $this->makeStore();
        $store->write(FakeCapturedArtifact::apiDispatch());

        $executor = OfflineReplayExecutor::make(new ExecutionConfig(dryRun: false), $store);
        $plan     = $executor->plan($store->openCursor());
        $result   = $executor->execute($plan);

        $this->assertArrayHasKey('record_id', $result->outcomes[0]);
        $this->assertNotEmpty($result->outcomes[0]['record_id']);
    }

    public function test_plan_records_are_in_append_sequence_order(): void
    {
        $store = $this->makeStore();
        for ($i = 0; $i < 5; $i++) {
            $store->write(FakeCapturedArtifact::apiDispatch());
        }

        $executor = OfflineReplayExecutor::make(new ExecutionConfig(), $store);
        $plan     = $executor->plan($store->openCursor());

        for ($i = 0; $i < count($plan->records) - 1; $i++) {
            $this->assertLessThan(
                $plan->records[$i + 1]->appendSequence,
                $plan->records[$i]->appendSequence,
            );
        }
    }

    public function test_make_does_not_throw_with_valid_config(): void
    {
        $store = $this->makeStore();
        // If make() throws, the test fails. Return type is OfflineReplayExecutorInterface.
        $plan  = OfflineReplayExecutor::make(new ExecutionConfig(), $store)
            ->plan($store->openCursor());
        $this->assertSame(0, $plan->recordCount);
    }
}
