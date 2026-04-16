<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Execution;

use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Execution\OfflineReplayPlan;
use Apntalk\EslReplay\Serialization\ArtifactChecksum;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

final class OfflineReplayPlanTest extends TestCase
{
    private function makeRecord(): StoredReplayRecord
    {
        $artifact = FakeCapturedArtifact::apiDispatch();
        return new StoredReplayRecord(
            id: ReplayRecordId::generate(),
            artifactVersion: $artifact->getArtifactVersion(),
            artifactName: $artifact->getArtifactName(),
            captureTimestamp: $artifact->getCaptureTimestamp(),
            storedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            appendSequence: 1,
            connectionGeneration: null,
            sessionId: null,
            jobUuid: null,
            eventName: null,
            capturePath: null,
            correlationIds: [],
            runtimeFlags: [],
            payload: $artifact->getPayload(),
            checksum: ArtifactChecksum::compute($artifact),
            tags: [],
        );
    }

    public function test_constructs_empty_plan(): void
    {
        $plan = new OfflineReplayPlan(
            from: ReplayReadCursor::start(),
            recordCount: 0,
            records: [],
            isDryRun: true,
            plannedAt: new \DateTimeImmutable(),
        );

        $this->assertTrue($plan->isEmpty());
        $this->assertSame(0, $plan->recordCount);
    }

    public function test_is_empty_returns_false_when_records_present(): void
    {
        $plan = new OfflineReplayPlan(
            from: ReplayReadCursor::start(),
            recordCount: 1,
            records: [$this->makeRecord()],
            isDryRun: true,
            plannedAt: new \DateTimeImmutable(),
        );

        $this->assertFalse($plan->isEmpty());
    }

    public function test_rejects_negative_record_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OfflineReplayPlan(
            from: ReplayReadCursor::start(),
            recordCount: -1,
            records: [],
            isDryRun: true,
            plannedAt: new \DateTimeImmutable(),
        );
    }

    public function test_rejects_mismatched_record_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OfflineReplayPlan(
            from: ReplayReadCursor::start(),
            recordCount: 3,   // says 3
            records: [],      // but has 0
            isDryRun: true,
            plannedAt: new \DateTimeImmutable(),
        );
    }

    public function test_dry_run_flag_is_preserved(): void
    {
        $plan = new OfflineReplayPlan(
            from: ReplayReadCursor::start(),
            recordCount: 0,
            records: [],
            isDryRun: false,
            plannedAt: new \DateTimeImmutable(),
        );
        $this->assertFalse($plan->isDryRun);
    }
}
