<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Checkpoint;

use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use PHPUnit\Framework\TestCase;

final class ReplayCheckpointTest extends TestCase
{
    public function test_constructs_with_valid_fields(): void
    {
        $cursor     = ReplayReadCursor::start()->advance(5);
        $savedAt    = new \DateTimeImmutable('2024-01-15T10:00:00+00:00');
        $checkpoint = new ReplayCheckpoint('my-key', $cursor, $savedAt);

        $this->assertSame('my-key', $checkpoint->key);
        $this->assertSame($cursor, $checkpoint->cursor);
        $this->assertSame($savedAt, $checkpoint->savedAt);
        $this->assertSame([], $checkpoint->metadata);
    }

    public function test_metadata_is_stored(): void
    {
        $checkpoint = new ReplayCheckpoint(
            key: 'key',
            cursor: ReplayReadCursor::start(),
            savedAt: new \DateTimeImmutable(),
            metadata: ['batch' => 'run-1', 'extra' => true],
        );
        $this->assertSame('run-1', $checkpoint->metadata['batch']);
    }

    public function test_rejects_empty_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayCheckpoint('', ReplayReadCursor::start(), new \DateTimeImmutable());
    }

    public function test_rejects_whitespace_only_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayCheckpoint('   ', ReplayReadCursor::start(), new \DateTimeImmutable());
    }

    public function test_cursor_sequence_is_preserved(): void
    {
        $cursor     = ReplayReadCursor::start()->advance(42);
        $checkpoint = new ReplayCheckpoint('k', $cursor, new \DateTimeImmutable());
        $this->assertSame(42, $checkpoint->cursor->lastConsumedSequence);
    }

    public function test_checkpoint_is_immutable(): void
    {
        $checkpoint = new ReplayCheckpoint('k', ReplayReadCursor::start(), new \DateTimeImmutable());
        $this->assertTrue((new \ReflectionClass($checkpoint))->isReadOnly());
    }
}
