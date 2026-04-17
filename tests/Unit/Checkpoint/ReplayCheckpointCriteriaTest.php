<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Checkpoint;

use Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria;
use PHPUnit\Framework\TestCase;

final class ReplayCheckpointCriteriaTest extends TestCase
{
    public function test_constructs_with_valid_fields(): void
    {
        $criteria = new ReplayCheckpointCriteria(
            replaySessionId: 'replay-1',
            jobUuid: 'job-1',
            pbxNodeSlug: 'pbx-a',
            workerSessionId: 'worker-a',
            limit: 25,
        );

        $this->assertSame('replay-1', $criteria->replaySessionId);
        $this->assertSame('job-1', $criteria->jobUuid);
        $this->assertSame('pbx-a', $criteria->pbxNodeSlug);
        $this->assertSame('worker-a', $criteria->workerSessionId);
        $this->assertSame(25, $criteria->limit);
    }

    public function test_rejects_missing_identity_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayCheckpointCriteria();
    }

    public function test_rejects_invalid_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayCheckpointCriteria(replaySessionId: 'replay-1', limit: 0);
    }
}
