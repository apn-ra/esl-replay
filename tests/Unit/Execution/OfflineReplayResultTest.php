<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Execution;

use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Execution\OfflineReplayPlan;
use Apntalk\EslReplay\Execution\OfflineReplayResult;
use PHPUnit\Framework\TestCase;

final class OfflineReplayResultTest extends TestCase
{
    private function emptyPlan(): OfflineReplayPlan
    {
        return new OfflineReplayPlan(
            from: ReplayReadCursor::start(),
            recordCount: 0,
            records: [],
            isDryRun: true,
            plannedAt: new \DateTimeImmutable(),
        );
    }

    public function test_constructs_with_success(): void
    {
        $result = new OfflineReplayResult(
            plan: $this->emptyPlan(),
            success: true,
            processedCount: 0,
            skippedCount: 0,
            outcomes: [],
            executedAt: new \DateTimeImmutable(),
        );
        $this->assertTrue($result->success);
        $this->assertNull($result->error);
    }

    public function test_constructs_with_error_message(): void
    {
        $result = new OfflineReplayResult(
            plan: $this->emptyPlan(),
            success: false,
            processedCount: 0,
            skippedCount: 0,
            outcomes: [],
            executedAt: new \DateTimeImmutable(),
            error: 'Something failed',
        );
        $this->assertFalse($result->success);
        $this->assertSame('Something failed', $result->error);
    }

    public function test_rejects_negative_processed_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OfflineReplayResult(
            plan: $this->emptyPlan(),
            success: true,
            processedCount: -1,
            skippedCount: 0,
            outcomes: [],
            executedAt: new \DateTimeImmutable(),
        );
    }

    public function test_rejects_negative_skipped_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OfflineReplayResult(
            plan: $this->emptyPlan(),
            success: true,
            processedCount: 0,
            skippedCount: -1,
            outcomes: [],
            executedAt: new \DateTimeImmutable(),
        );
    }
}
