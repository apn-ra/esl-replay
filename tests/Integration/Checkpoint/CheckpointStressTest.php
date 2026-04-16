<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Checkpoint;

use Apntalk\EslReplay\Checkpoint\ExecutionResumeState;
use Apntalk\EslReplay\Checkpoint\FilesystemCheckpointStore;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointService;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use PHPUnit\Framework\TestCase;

final class CheckpointStressTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-checkpoint-stress-' . bin2hex(random_bytes(8));
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

    public function test_repeated_checkpoint_saves_remain_restart_safe(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        $service = new ReplayCheckpointService($store, 'stress-key');

        $cursor = ReplayReadCursor::start();
        for ($sequence = 1; $sequence <= 250; $sequence++) {
            $cursor = $cursor->advance($sequence, $sequence * 10);
            $service->save($cursor, ['iteration' => $sequence]);
        }

        $state = ExecutionResumeState::resolve($store, 'stress-key');

        $this->assertTrue($state->isResuming);
        $this->assertSame(250, $state->cursor->lastConsumedSequence);
        $this->assertSame(2500, $state->cursor->byteOffsetHint);
        $this->assertSame(250, $state->fromCheckpoint?->metadata['iteration']);
    }
}
