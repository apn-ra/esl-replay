<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Checkpoint;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Checkpoint\ExecutionResumeState;
use Apntalk\EslReplay\Checkpoint\FilesystemCheckpointStore;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointService;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\CheckpointException;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

final class CheckpointChaosTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-checkpoint-chaos-' . bin2hex(random_bytes(8));
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

    public function test_zero_byte_checkpoint_file_fails_clearly(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        file_put_contents($this->tmpDir . '/zero.checkpoint.json', '');

        $this->expectException(CheckpointException::class);
        $store->load('zero');
    }

    public function test_truncated_checkpoint_json_fails_clearly_on_resume_resolution(): void
    {
        file_put_contents($this->tmpDir . '/broken.checkpoint.json', '{"key":"broken","last_consumed_sequence":4');

        $this->expectException(CheckpointException::class);
        ExecutionResumeState::resolve(new FilesystemCheckpointStore($this->tmpDir), 'broken');
    }

    public function test_semantically_invalid_checkpoint_data_fails_clearly(): void
    {
        file_put_contents($this->tmpDir . '/invalid.checkpoint.json', json_encode([
            'key' => 'invalid',
            'last_consumed_sequence' => -5,
            'byte_offset_hint' => 10,
            'saved_at' => '2024-01-01T00:00:00+00:00',
            'metadata' => [],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(CheckpointException::class);
        (new FilesystemCheckpointStore($this->tmpDir))->load('invalid');
    }

    public function test_checkpoint_pointing_past_end_of_stream_returns_empty_future_read_without_fake_resume_data(): void
    {
        $artifactStore = new FilesystemReplayArtifactStore($this->tmpDir . '/artifacts');
        $artifactStore->write(FakeCapturedArtifact::apiDispatch());
        $artifactStore->write(FakeCapturedArtifact::eventRaw());

        $checkpointStore = new FilesystemCheckpointStore($this->tmpDir . '/checkpoints');
        $service = new ReplayCheckpointService($checkpointStore, 'past-end');
        $service->save(new ReplayReadCursor(lastConsumedSequence: 99, byteOffsetHint: 0));

        $state = ExecutionResumeState::resolve($checkpointStore, 'past-end');
        $records = $artifactStore->readFromCursor($state->cursor, 10);

        $this->assertTrue($state->isResuming);
        $this->assertSame([], $records);
    }

    public function test_rapid_save_load_clear_cycles_do_not_leave_misleading_resume_positions(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        $service = new ReplayCheckpointService($store, 'cycle');

        for ($sequence = 1; $sequence <= 50; $sequence++) {
            $service->save(new ReplayReadCursor($sequence, $sequence * 5));
            $loaded = $service->load();
            $this->assertNotNull($loaded);
            $this->assertSame($sequence, $loaded->cursor->lastConsumedSequence);
        }

        $service->clear();

        $state = ExecutionResumeState::resolve($store, 'cycle');
        $this->assertFalse($state->isResuming);
        $this->assertTrue($state->cursor->isAtStart());
    }
}
