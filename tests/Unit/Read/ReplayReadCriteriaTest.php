<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Read;

use Apntalk\EslReplay\Read\ReplayReadCriteria;
use PHPUnit\Framework\TestCase;

final class ReplayReadCriteriaTest extends TestCase
{
    public function test_it_accepts_valid_bounded_criteria(): void
    {
        $from     = new \DateTimeImmutable('2024-01-15T10:00:00+00:00');
        $until    = new \DateTimeImmutable('2024-01-15T11:00:00+00:00');
        $criteria = new ReplayReadCriteria(
            capturedFrom: $from,
            capturedUntil: $until,
            artifactName: 'event.raw',
            jobUuid: 'job-123',
            replaySessionId: 'replay-123',
            pbxNodeSlug: 'pbx-a',
            workerSessionId: 'worker-123',
            sessionId: 'sess-123',
            connectionGeneration: 'gen-7',
        );

        $this->assertSame($from, $criteria->capturedFrom);
        $this->assertSame($until, $criteria->capturedUntil);
        $this->assertSame('event.raw', $criteria->artifactName);
        $this->assertSame('job-123', $criteria->jobUuid);
        $this->assertSame('replay-123', $criteria->replaySessionId);
        $this->assertSame('pbx-a', $criteria->pbxNodeSlug);
        $this->assertSame('worker-123', $criteria->workerSessionId);
        $this->assertSame('sess-123', $criteria->sessionId);
        $this->assertSame('gen-7', $criteria->connectionGeneration);
    }

    public function test_it_rejects_empty_string_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayReadCriteria(artifactName: '   ');
    }

    public function test_it_rejects_empty_operator_identity_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayReadCriteria(replaySessionId: '   ');
    }

    public function test_it_rejects_inverted_time_windows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayReadCriteria(
            capturedFrom: new \DateTimeImmutable('2024-01-15T11:00:00+00:00'),
            capturedUntil: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
        );
    }
}
