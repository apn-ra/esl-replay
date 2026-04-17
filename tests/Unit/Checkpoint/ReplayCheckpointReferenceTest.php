<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Checkpoint;

use Apntalk\EslReplay\Checkpoint\ReplayCheckpointReference;
use PHPUnit\Framework\TestCase;

final class ReplayCheckpointReferenceTest extends TestCase
{
    public function test_metadata_with_identity_anchors_merges_reference_fields(): void
    {
        $reference = new ReplayCheckpointReference(
            key: 'worker-a',
            replaySessionId: 'replay-1',
            jobUuid: 'job-1',
            pbxNodeSlug: 'pbx-a',
            workerSessionId: 'worker-a',
            metadata: ['attempt' => 2],
        );

        $this->assertSame([
            'attempt' => 2,
            'replay_session_id' => 'replay-1',
            'job_uuid' => 'job-1',
            'pbx_node_slug' => 'pbx-a',
            'worker_session_id' => 'worker-a',
        ], $reference->metadataWithIdentityAnchors());
    }

    public function test_rejects_empty_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayCheckpointReference('   ');
    }
}
