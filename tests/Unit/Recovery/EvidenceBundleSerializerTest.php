<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Recovery;

use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Recovery\EvidenceBundle;
use Apntalk\EslReplay\Recovery\EvidenceBundleSerializer;
use Apntalk\EslReplay\Recovery\EvidenceRecordReference;
use Apntalk\EslReplay\Recovery\RecoveryManifest;
use Apntalk\EslReplay\Recovery\ReconstructionVerdict;
use Apntalk\EslReplay\Recovery\ReconstructionWindow;
use Apntalk\EslReplay\Recovery\RuntimeContinuitySnapshot;
use PHPUnit\Framework\TestCase;

final class EvidenceBundleSerializerTest extends TestCase
{
    public function test_bundle_serialization_is_deterministic(): void
    {
        $bundle = new EvidenceBundle(
            manifest: new RecoveryManifest(
                bundleVersion: 1,
                bundleId: 'bundle-1',
                window: new ReconstructionWindow(ReplayReadCursor::start(), checkpointMetadata: ['b' => 2, 'a' => 1]),
                recordCount: 1,
                firstAppendSequence: 1,
                lastAppendSequence: 1,
                verdict: new ReconstructionVerdict('complete', []),
            ),
            continuitySnapshot: new RuntimeContinuitySnapshot([], 'continuous', 'stable', 'drained', 'bounded', 1),
            recordReferences: [
                new EvidenceRecordReference(
                    recordId: 'record-1',
                    appendSequence: 1,
                    artifactName: 'event.raw',
                    captureTimestamp: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
                ),
            ],
            operations: [],
            terminalPublications: [],
            lifecycleSemantics: [],
        );

        $serializer = new EvidenceBundleSerializer();

        $this->assertSame(
            $serializer->serializeBundle($bundle),
            $serializer->serializeBundle($bundle),
        );
        $this->assertStringContainsString('"checkpoint_metadata":{"a":1,"b":2}', $serializer->serializeBundle($bundle));
    }
}
