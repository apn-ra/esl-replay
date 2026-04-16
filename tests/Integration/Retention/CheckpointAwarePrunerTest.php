<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Retention;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\RetentionException;
use Apntalk\EslReplay\Retention\CheckpointAwarePruner;
use Apntalk\EslReplay\Retention\PrunePolicy;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

final class CheckpointAwarePrunerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-retention-test-' . bin2hex(random_bytes(8));
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

    public function test_prune_by_age_does_not_prune_beyond_oldest_active_checkpoint(): void
    {
        $store = $this->makeStore();
        $store->write(new FakeCapturedArtifact(captureTimestamp: new \DateTimeImmutable('2024-01-01T00:00:00+00:00')));
        $store->write(new FakeCapturedArtifact(captureTimestamp: new \DateTimeImmutable('2024-01-02T00:00:00+00:00')));
        $store->write(new FakeCapturedArtifact(captureTimestamp: new \DateTimeImmutable('2024-01-03T00:00:00+00:00')));

        $pruner = new CheckpointAwarePruner($this->tmpDir);
        $result = $pruner->prune(
            activeCheckpoints: [
                new ReplayCheckpoint(
                    'worker-a',
                    ReplayReadCursor::start()->advance(1),
                    new \DateTimeImmutable('2024-01-04T00:00:00+00:00'),
                ),
            ],
            policy: new PrunePolicy(maxRecordAge: new \DateInterval('P1D')),
            now: new \DateTimeImmutable('2024-01-10T00:00:00+00:00'),
        );

        $this->assertTrue($result->changed);
        $this->assertSame([1], $result->plan->prunedSequences);
        $remaining = $store->readFromCursor($store->openCursor(), 10);
        $this->assertSame([2, 3], array_map(static fn ($record) => $record->appendSequence, $remaining));
    }

    public function test_prune_respects_protected_record_window(): void
    {
        $store = $this->makeStore();
        for ($i = 0; $i < 5; $i++) {
            $store->write(FakeCapturedArtifact::apiDispatch("sess-{$i}"));
        }

        $pruner = new CheckpointAwarePruner($this->tmpDir);
        $result = $pruner->prune(
            activeCheckpoints: [],
            policy: new PrunePolicy(maxStreamBytes: 0, protectedRecordCount: 2),
        );

        $this->assertSame([1, 2, 3], $result->plan->prunedSequences);
        $this->assertSame(4, $result->plan->retainedFirstSequence);
    }

    public function test_prune_throws_when_checkpoint_is_already_incompatible_with_retained_stream(): void
    {
        $store = $this->makeStore();
        for ($i = 0; $i < 5; $i++) {
            $store->write(FakeCapturedArtifact::apiDispatch("sess-{$i}"));
        }

        $aggressivePruner = new CheckpointAwarePruner($this->tmpDir);
        $aggressivePruner->prune([], new PrunePolicy(maxStreamBytes: 1, protectedRecordCount: 1));

        $this->expectException(RetentionException::class);

        (new CheckpointAwarePruner($this->tmpDir))->plan(
            activeCheckpoints: [
                new ReplayCheckpoint(
                    'lagging-worker',
                    ReplayReadCursor::start()->advance(1),
                    new \DateTimeImmutable('2024-01-05T00:00:00+00:00'),
                ),
            ],
            policy: new PrunePolicy(maxStreamBytes: 0),
        );
    }

    public function test_plan_reports_unsatisfied_size_target_when_checkpoint_blocks_more_pruning(): void
    {
        $store = $this->makeStore();
        for ($i = 0; $i < 4; $i++) {
            $store->write(FakeCapturedArtifact::apiDispatch("sess-{$i}"));
        }

        $plan = (new CheckpointAwarePruner($this->tmpDir))->plan(
            activeCheckpoints: [
                new ReplayCheckpoint(
                    'checkpoint',
                    ReplayReadCursor::start()->advance(1),
                    new \DateTimeImmutable('2024-01-05T00:00:00+00:00'),
                ),
            ],
            policy: new PrunePolicy(maxStreamBytes: 1),
        );

        $this->assertFalse($plan->sizeTargetSatisfied);
        $this->assertSame([1], $plan->prunedSequences);
    }
}
