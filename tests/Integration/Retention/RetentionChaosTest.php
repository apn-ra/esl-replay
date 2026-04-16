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

final class RetentionChaosTest extends TestCase
{
    private string $tmpDir;
    private string $artifactFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-retention-chaos-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
        $this->artifactFile = $this->tmpDir . '/artifacts.ndjson';
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

    public function test_checkpoint_exactly_on_prune_boundary_can_resume_from_retained_suffix(): void
    {
        $store = $this->makeStore();
        for ($i = 0; $i < 4; $i++) {
            $store->write(FakeCapturedArtifact::apiDispatch("sess-{$i}"));
        }

        $pruner = new CheckpointAwarePruner($this->tmpDir);
        $result = $pruner->prune(
            activeCheckpoints: [
                new ReplayCheckpoint('worker', ReplayReadCursor::start()->advance(2), new \DateTimeImmutable()),
            ],
            policy: new PrunePolicy(maxStreamBytes: 1),
        );

        $this->assertSame([1, 2], $result->plan->prunedSequences);
        $resumed = $store->readFromCursor(new ReplayReadCursor(2, 0), 10);
        $this->assertSame([3, 4], array_map(static fn ($record) => $record->appendSequence, $resumed));
    }

    public function test_repeated_prune_runs_become_no_op_once_minimal_eligible_set_is_reached(): void
    {
        $store = $this->makeStore();
        for ($i = 0; $i < 5; $i++) {
            $store->write(FakeCapturedArtifact::apiDispatch("sess-{$i}"));
        }

        $pruner = new CheckpointAwarePruner($this->tmpDir);
        $first = $pruner->prune([], new PrunePolicy(maxStreamBytes: 0, protectedRecordCount: 2));
        $second = $pruner->prune([], new PrunePolicy(maxStreamBytes: 0, protectedRecordCount: 2));

        $this->assertTrue($first->changed);
        $this->assertFalse($second->changed);
        $this->assertSame([], $second->plan->prunedSequences);
    }

    public function test_prune_fails_clearly_when_artifact_stream_contains_malformed_rewrite_input(): void
    {
        $store = $this->makeStore();
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());

        file_put_contents($this->artifactFile, "{\"broken\":\n", FILE_APPEND);

        $this->expectException(RetentionException::class);

        (new CheckpointAwarePruner($this->tmpDir))->prune([], new PrunePolicy(maxStreamBytes: 1));
    }
}
