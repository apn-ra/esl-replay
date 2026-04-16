<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Cursor;

use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use PHPUnit\Framework\TestCase;

final class ReplayReadCursorTest extends TestCase
{
    public function test_start_returns_cursor_at_sequence_zero(): void
    {
        $cursor = ReplayReadCursor::start();
        $this->assertSame(0, $cursor->lastConsumedSequence);
    }

    public function test_start_sets_byte_offset_hint_to_zero(): void
    {
        $cursor = ReplayReadCursor::start();
        $this->assertSame(0, $cursor->byteOffsetHint);
    }

    public function test_is_at_start_returns_true_for_zero_sequence(): void
    {
        $cursor = ReplayReadCursor::start();
        $this->assertTrue($cursor->isAtStart());
    }

    public function test_is_at_start_returns_false_after_advance(): void
    {
        $cursor = ReplayReadCursor::start()->advance(1);
        $this->assertFalse($cursor->isAtStart());
    }

    public function test_advance_increments_last_consumed_sequence(): void
    {
        $cursor   = ReplayReadCursor::start();
        $advanced = $cursor->advance(5);
        $this->assertSame(5, $advanced->lastConsumedSequence);
    }

    public function test_advance_accepts_optional_byte_offset(): void
    {
        $advanced = ReplayReadCursor::start()->advance(1, 128);
        $this->assertSame(128, $advanced->byteOffsetHint);
    }

    public function test_advance_clears_offset_when_not_provided(): void
    {
        $advanced = ReplayReadCursor::start()->advance(1);
        $this->assertNull($advanced->byteOffsetHint);
    }

    public function test_advance_throws_when_new_sequence_not_strictly_greater(): void
    {
        $cursor = ReplayReadCursor::start()->advance(3);
        $this->expectException(\InvalidArgumentException::class);
        $cursor->advance(3); // same, not strictly greater
    }

    public function test_advance_throws_when_new_sequence_is_less(): void
    {
        $cursor = ReplayReadCursor::start()->advance(5);
        $this->expectException(\InvalidArgumentException::class);
        $cursor->advance(4);
    }

    public function test_constructor_rejects_negative_sequence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayReadCursor(-1);
    }

    public function test_constructor_rejects_negative_byte_offset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayReadCursor(0, -1);
    }

    public function test_cursor_is_immutable(): void
    {
        $cursor   = ReplayReadCursor::start();
        $advanced = $cursor->advance(1);
        // The original cursor must not have changed
        $this->assertSame(0, $cursor->lastConsumedSequence);
        $this->assertSame(1, $advanced->lastConsumedSequence);
    }

    public function test_chained_advances_work_correctly(): void
    {
        $cursor = ReplayReadCursor::start()
            ->advance(1)
            ->advance(2)
            ->advance(10);

        $this->assertSame(10, $cursor->lastConsumedSequence);
    }
}
