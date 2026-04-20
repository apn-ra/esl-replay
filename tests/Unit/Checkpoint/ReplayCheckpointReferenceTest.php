<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Checkpoint;

use Apntalk\EslReplay\Artifact\OperatorIdentityKeys;
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
            recoveryGenerationId: 'gen-1',
            metadata: ['attempt' => 2],
        );

        $this->assertSame([
            'attempt' => 2,
            OperatorIdentityKeys::REPLAY_SESSION_ID => 'replay-1',
            'job_uuid' => 'job-1',
            OperatorIdentityKeys::PBX_NODE_SLUG => 'pbx-a',
            OperatorIdentityKeys::WORKER_SESSION_ID => 'worker-a',
            \Apntalk\EslReplay\Recovery\RecoveryMetadataKeys::RECOVERY_GENERATION_ID => 'gen-1',
        ], $reference->metadataWithIdentityAnchors());
    }

    public function test_rejects_empty_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayCheckpointReference('   ');
    }
}
