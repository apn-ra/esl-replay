<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Recovery;

use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Contracts\ReplayArtifactReaderInterface;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Recovery\CheckpointReconstructionWindowResolver;
use Apntalk\EslReplay\Recovery\ExpectedLifecycleSemantic;
use Apntalk\EslReplay\Recovery\ExpectedOperationLifecycle;
use Apntalk\EslReplay\Recovery\ExpectedTerminalPublication;
use Apntalk\EslReplay\Recovery\RecoveryEvidenceEngine;
use Apntalk\EslReplay\Recovery\RecoveryMetadataKeys;
use Apntalk\EslReplay\Recovery\ReconstructionWindow;
use Apntalk\EslReplay\Recovery\ScenarioExpectation;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use PHPUnit\Framework\TestCase;

final class RecoveryEvidenceEngineTest extends TestCase
{
    public function test_reconstructs_bounded_runtime_truth_from_stored_records(): void
    {
        $records = [
            $this->makeRecord(
                1,
                'bgapi.dispatch',
                [
                    'prepared_recovery_context' => [RecoveryMetadataKeys::RECOVERY_GENERATION_ID => 'gen-1'],
                    'runtime_recovery_snapshot' => [
                        RecoveryMetadataKeys::REPLAY_CONTINUITY_POSTURE => 'continuous',
                        RecoveryMetadataKeys::RETRY_POSTURE => 'stable',
                        RecoveryMetadataKeys::DRAIN_POSTURE => 'drained',
                        RecoveryMetadataKeys::RECONSTRUCTION_POSTURE => 'bounded',
                    ],
                    'runtime_operation_snapshot' => [
                        RecoveryMetadataKeys::OPERATION_ID => 'op-1',
                        RecoveryMetadataKeys::OPERATION_KIND => 'bgapi',
                        RecoveryMetadataKeys::OPERATION_STATE => 'accepted',
                        RecoveryMetadataKeys::BGAPI_JOB_UUID => 'job-1',
                    ],
                ],
                'job-1',
                ['replay_session_id' => 'replay-1'],
            ),
            $this->makeRecord(
                2,
                'bgapi.complete',
                [
                    'runtime_operation_snapshot' => [
                        RecoveryMetadataKeys::OPERATION_ID => 'op-1',
                        RecoveryMetadataKeys::OPERATION_KIND => 'bgapi',
                        RecoveryMetadataKeys::OPERATION_STATE => 'completed',
                        RecoveryMetadataKeys::BGAPI_JOB_UUID => 'job-1',
                    ],
                    'runtime_terminal_publication_snapshot' => [
                        RecoveryMetadataKeys::TERMINAL_PUBLICATION_ID => 'pub-1',
                        RecoveryMetadataKeys::TERMINAL_PUBLICATION_STATUS => 'published',
                    ],
                    'runtime_lifecycle_semantic_snapshot' => [
                        RecoveryMetadataKeys::LIFECYCLE_SEMANTIC => 'runtime_terminal',
                        'posture' => 'observed',
                    ],
                ],
                'job-1',
                ['replay_session_id' => 'replay-1'],
            ),
        ];

        $engine = RecoveryEvidenceEngine::make(new InMemoryReplayArtifactReader($records));
        $bundle = $engine->reconstruct(new ReconstructionWindow(ReplayReadCursor::start()));

        $this->assertSame('complete', $bundle->manifest->verdict->posture);
        $this->assertSame(['gen-1'], array_map(
            static fn ($generation) => $generation->generationId,
            $bundle->continuitySnapshot->recoveryGenerations,
        ));
        $this->assertSame('continuous', $bundle->continuitySnapshot->replayContinuityPosture);
        $this->assertCount(1, $bundle->operations);
        $this->assertSame(['accepted', 'completed'], array_map(
            static fn (array $state): string => $state['state'],
            $bundle->operations[0]->observedStates,
        ));
        $this->assertCount(1, $bundle->terminalPublications);
        $this->assertCount(1, $bundle->lifecycleSemantics);
    }

    public function test_reconstruct_fails_closed_when_operation_identity_is_missing(): void
    {
        $records = [
            $this->makeRecord(
                1,
                'bgapi.complete',
                [
                    'runtime_operation_snapshot' => [
                        RecoveryMetadataKeys::OPERATION_STATE => 'completed',
                    ],
                ],
            ),
        ];

        $engine = RecoveryEvidenceEngine::make(new InMemoryReplayArtifactReader($records));
        $bundle = $engine->reconstruct(new ReconstructionWindow(ReplayReadCursor::start()));

        $this->assertSame('insufficient', $bundle->manifest->verdict->posture);
        $this->assertSame('operation_state_without_identity', $bundle->manifest->verdict->issues[0]->code);
        $this->assertSame([], $bundle->operations);
    }

    public function test_compare_scenario_produces_machine_readable_result(): void
    {
        $records = [
            $this->makeRecord(
                1,
                'bgapi.dispatch',
                [
                    'prepared_recovery_context' => [RecoveryMetadataKeys::RECOVERY_GENERATION_ID => 'gen-1'],
                    'runtime_recovery_snapshot' => [
                        RecoveryMetadataKeys::REPLAY_CONTINUITY_POSTURE => 'continuous',
                        RecoveryMetadataKeys::RETRY_POSTURE => 'stable',
                        RecoveryMetadataKeys::DRAIN_POSTURE => 'drained',
                        RecoveryMetadataKeys::RECONSTRUCTION_POSTURE => 'bounded',
                    ],
                    'runtime_operation_snapshot' => [
                        RecoveryMetadataKeys::OPERATION_ID => 'op-1',
                        RecoveryMetadataKeys::OPERATION_STATE => 'accepted',
                    ],
                ],
            ),
            $this->makeRecord(
                2,
                'bgapi.complete',
                [
                    'runtime_operation_snapshot' => [
                        RecoveryMetadataKeys::OPERATION_ID => 'op-1',
                        RecoveryMetadataKeys::OPERATION_STATE => 'completed',
                    ],
                    'runtime_terminal_publication_snapshot' => [
                        RecoveryMetadataKeys::TERMINAL_PUBLICATION_ID => 'pub-1',
                        RecoveryMetadataKeys::TERMINAL_PUBLICATION_STATUS => 'published',
                    ],
                    'runtime_lifecycle_semantic_snapshot' => [
                        RecoveryMetadataKeys::LIFECYCLE_SEMANTIC => 'runtime_terminal',
                        'posture' => 'observed',
                    ],
                ],
            ),
        ];

        $engine = RecoveryEvidenceEngine::make(new InMemoryReplayArtifactReader($records));
        $bundle = $engine->reconstruct(new ReconstructionWindow(ReplayReadCursor::start()));
        $comparison = $engine->compareScenario($bundle, new ScenarioExpectation(
            scenarioName: 'sc19-recovery',
            expectedRecoveryGenerations: ['gen-1'],
            expectedReplayContinuityPosture: 'continuous',
            expectedRetryPosture: 'stable',
            expectedDrainPosture: 'drained',
            expectedReconstructionPosture: 'bounded',
            expectedOperations: [
                new ExpectedOperationLifecycle('op-1', ['accepted', 'completed'], 'completed'),
            ],
            expectedTerminalPublications: [
                new ExpectedTerminalPublication('pub-1', 'published', 'op-1'),
            ],
            expectedLifecycleSemantics: [
                new ExpectedLifecycleSemantic('runtime_terminal', 'observed', 'op-1'),
            ],
        ));

        $this->assertTrue($comparison->passed);
        $this->assertSame([], $comparison->issues);
        $this->assertSame($bundle->manifest->bundleId, $comparison->bundleId);
    }

    public function test_checkpoint_window_resolver_rejects_contradictory_generation_identity(): void
    {
        $records = [
            $this->makeRecord(
                2,
                'bgapi.complete',
                [
                    'prepared_recovery_context' => [RecoveryMetadataKeys::RECOVERY_GENERATION_ID => 'gen-2'],
                ],
                'job-1',
                ['replay_session_id' => 'replay-1'],
            ),
        ];
        $reader = new InMemoryReplayArtifactReader($records);
        $resolver = new CheckpointReconstructionWindowResolver($reader);

        $this->expectException(\Apntalk\EslReplay\Exceptions\CheckpointException::class);
        $this->expectExceptionMessage('expected recovery_generation_id gen-1, observed gen-2');

        $resolver->resolve(new ReplayCheckpoint(
            key: 'worker-a',
            cursor: ReplayReadCursor::start()->advance(1),
            savedAt: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
            metadata: [
                RecoveryMetadataKeys::RECOVERY_GENERATION_ID => 'gen-1',
                'replay_session_id' => 'replay-1',
            ],
        ));
    }

    public function test_checkpoint_window_resolver_preserves_lookup_metadata_and_bounds(): void
    {
        $records = [
            $this->makeRecord(
                2,
                'bgapi.complete',
                [
                    'prepared_recovery_context' => [RecoveryMetadataKeys::RECOVERY_GENERATION_ID => 'gen-1'],
                ],
                'job-1',
                ['replay_session_id' => 'replay-1'],
            ),
        ];
        $reader = new InMemoryReplayArtifactReader($records);
        $resolver = new CheckpointReconstructionWindowResolver($reader);

        $window = $resolver->resolve(
            new ReplayCheckpoint(
                key: 'worker-a',
                cursor: ReplayReadCursor::start()->advance(1),
                savedAt: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
                metadata: [
                    RecoveryMetadataKeys::RECOVERY_GENERATION_ID => 'gen-1',
                    'replay_session_id' => 'replay-1',
                ],
            ),
            new ReplayReadCriteria(replaySessionId: 'replay-1'),
            untilAppendSequence: 10,
            batchLimit: 25,
        );

        $this->assertSame('worker-a', $window->checkpointKey);
        $this->assertSame('gen-1', $window->checkpointMetadata[RecoveryMetadataKeys::RECOVERY_GENERATION_ID]);
        $this->assertSame(10, $window->untilAppendSequence);
        $this->assertSame(25, $window->batchLimit);
        $this->assertSame('replay-1', $window->criteria?->replaySessionId);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $correlationIds
     */
    private function makeRecord(
        int $appendSequence,
        string $artifactName,
        array $payload,
        ?string $jobUuid = null,
        array $correlationIds = [],
    ): StoredReplayRecord {
        return new StoredReplayRecord(
            id: new ReplayRecordId('record-' . $appendSequence),
            artifactVersion: '1',
            artifactName: $artifactName,
            captureTimestamp: new \DateTimeImmutable(sprintf('2024-01-15T10:00:%02d+00:00', $appendSequence)),
            storedAt: new \DateTimeImmutable(sprintf('2024-01-15T10:01:%02d+00:00', $appendSequence)),
            appendSequence: $appendSequence,
            connectionGeneration: null,
            sessionId: 'sess-1',
            jobUuid: $jobUuid,
            eventName: null,
            capturePath: null,
            correlationIds: $correlationIds,
            runtimeFlags: [],
            payload: $payload,
            checksum: 'checksum-' . $appendSequence,
            tags: [],
        );
    }
}

/**
 * @internal
 */
final readonly class InMemoryReplayArtifactReader implements ReplayArtifactReaderInterface
{
    /**
     * @param list<StoredReplayRecord> $records
     */
    public function __construct(
        private array $records,
    ) {}

    public function readById(\Apntalk\EslReplay\Storage\ReplayRecordId $id): ?StoredReplayRecord
    {
        foreach ($this->records as $record) {
            if ($record->id->equals($id)) {
                return $record;
            }
        }

        return null;
    }

    public function readFromCursor(
        ReplayReadCursor $cursor,
        int $limit = 100,
        ?ReplayReadCriteria $criteria = null,
    ): array {
        $records = array_values(array_filter(
            $this->records,
            static fn (StoredReplayRecord $record): bool => $record->appendSequence > $cursor->lastConsumedSequence,
        ));

        if ($criteria?->replaySessionId !== null) {
            $records = array_values(array_filter(
                $records,
                static fn (StoredReplayRecord $record): bool => ($record->correlationIds['replay_session_id'] ?? null) === $criteria->replaySessionId,
            ));
        }

        return array_slice($records, 0, $limit);
    }

    public function openCursor(): ReplayReadCursor
    {
        return ReplayReadCursor::start();
    }
}
