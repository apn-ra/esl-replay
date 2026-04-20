<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

use Apntalk\EslReplay\Contracts\ReplayArtifactReaderInterface;
use Apntalk\EslReplay\Storage\StoredReplayRecord;

/**
 * Deterministic bounded reconstruction and comparison engine over stored artifacts.
 *
 * This engine reconstructs evidence from append-ordered stored records only.
 * It does not restore live sockets, live sessions, or runtime supervision state.
 */
final readonly class RecoveryEvidenceEngine
{
    public function __construct(
        private ReplayArtifactReaderInterface $reader,
        private EvidenceBundleSerializer $serializer = new EvidenceBundleSerializer(),
    ) {}

    public static function make(ReplayArtifactReaderInterface $reader): self
    {
        return new self($reader);
    }

    public function reconstruct(ReconstructionWindow $window): EvidenceBundle
    {
        $records = $this->readWindow($window);
        $issues = [];
        $recordReferences = [];
        $recoveryGenerations = [];
        $operations = [];
        $terminalPublications = [];
        $lifecycleSemantics = [];
        $replayContinuityPosture = null;
        $retryPosture = null;
        $drainPosture = null;
        $reconstructionPosture = null;

        foreach ($records as $record) {
            $recordReferences[] = new EvidenceRecordReference(
                recordId: $record->id->value,
                appendSequence: $record->appendSequence,
                artifactName: $record->artifactName,
                captureTimestamp: $record->captureTimestamp,
            );

            $generationId = RuntimeTruthExtractor::recoveryGenerationId($record);
            if ($generationId !== null && $this->lastGenerationId($recoveryGenerations) !== $generationId) {
                $recoveryGenerations[] = new RecoveryGenerationObservation(
                    generationId: $generationId,
                    appendSequence: $record->appendSequence,
                    artifactName: $record->artifactName,
                );
            }

            $replayContinuityPosture = $this->observePosture(
                $replayContinuityPosture,
                RuntimeTruthExtractor::replayContinuityPosture($record),
                'replay_continuity_posture_changed',
                $record,
                $issues,
            );
            $retryPosture = $this->observePosture(
                $retryPosture,
                RuntimeTruthExtractor::retryPosture($record),
                'retry_posture_changed',
                $record,
                $issues,
            );
            $drainPosture = $this->observePosture(
                $drainPosture,
                RuntimeTruthExtractor::drainPosture($record),
                'drain_posture_changed',
                $record,
                $issues,
            );
            $reconstructionPosture = $this->observePosture(
                $reconstructionPosture,
                RuntimeTruthExtractor::reconstructionPosture($record),
                'reconstruction_posture_changed',
                $record,
                $issues,
            );

            $this->observeOperation($record, $operations, $issues);
            $this->observeTerminalPublication($record, $terminalPublications, $issues);
            $this->observeLifecycleSemantic($record, $lifecycleSemantics, $issues);
        }

        $operationRecords = array_values(array_map(
            static fn (array $operation): OperationRecoveryRecord => new OperationRecoveryRecord(
                operationId: $operation['operation_id'],
                operationKind: $operation['operation_kind'],
                bgapiJobUuid: $operation['bgapi_job_uuid'],
                acceptedAppendSequence: $operation['accepted_append_sequence'],
                observedStates: $operation['observed_states'],
                finalState: $operation['final_state'],
                retryPosture: $operation['retry_posture'],
                drainPosture: $operation['drain_posture'],
                issues: $operation['issues'],
            ),
            $operations,
        ));

        usort(
            $operationRecords,
            static fn (OperationRecoveryRecord $left, OperationRecoveryRecord $right): int => $left->operationId <=> $right->operationId,
        );
        usort(
            $terminalPublications,
            static fn (TerminalPublicationEvidenceRecord $left, TerminalPublicationEvidenceRecord $right): int => [$left->appendSequence, $left->publicationId]
                <=> [$right->appendSequence, $right->publicationId],
        );
        usort(
            $lifecycleSemantics,
            static fn (LifecycleSemanticEvidenceRecord $left, LifecycleSemanticEvidenceRecord $right): int => [$left->appendSequence, $left->semantic]
                <=> [$right->appendSequence, $right->semantic],
        );

        if ($records === []) {
            $issues[] = new ReconstructionIssue(
                kind: 'insufficient',
                code: 'no_records_in_window',
                message: 'The reconstruction window contains no stored artifacts.',
            );
        }

        if ($recoveryGenerations === []) {
            $issues[] = new ReconstructionIssue(
                kind: 'insufficient',
                code: 'missing_recovery_generation_evidence',
                message: 'Stored artifacts did not provide a bounded recovery_generation_id observation.',
            );
        }

        $continuitySnapshot = new RuntimeContinuitySnapshot(
            recoveryGenerations: $recoveryGenerations,
            replayContinuityPosture: $replayContinuityPosture,
            retryPosture: $retryPosture,
            drainPosture: $drainPosture,
            reconstructionPosture: $reconstructionPosture,
            lastObservedAppendSequence: $records === [] ? null : $records[array_key_last($records)]->appendSequence,
        );

        $verdict = new ReconstructionVerdict(
            posture: $this->deriveVerdictPosture($issues),
            issues: $issues,
        );

        $manifest = new RecoveryManifest(
            bundleVersion: 1,
            bundleId: $this->bundleIdFor(
                $window,
                $continuitySnapshot,
                $recordReferences,
                $operationRecords,
                $terminalPublications,
                $lifecycleSemantics,
                $verdict,
            ),
            window: $window,
            recordCount: count($records),
            firstAppendSequence: $records === [] ? null : $records[0]->appendSequence,
            lastAppendSequence: $records === [] ? null : $records[array_key_last($records)]->appendSequence,
            verdict: $verdict,
        );

        return new EvidenceBundle(
            manifest: $manifest,
            continuitySnapshot: $continuitySnapshot,
            recordReferences: $recordReferences,
            operations: $operationRecords,
            terminalPublications: $terminalPublications,
            lifecycleSemantics: $lifecycleSemantics,
        );
    }

    public function compareScenario(EvidenceBundle $bundle, ScenarioExpectation $expectation): ScenarioComparisonResult
    {
        $issues = [];

        $actualGenerations = array_map(
            static fn (RecoveryGenerationObservation $observation): string => $observation->generationId,
            $bundle->continuitySnapshot->recoveryGenerations,
        );
        if ($expectation->expectedRecoveryGenerations !== [] && $actualGenerations !== $expectation->expectedRecoveryGenerations) {
            $issues[] = new ReconstructionIssue(
                kind: 'mismatch',
                code: 'recovery_generation_sequence_mismatch',
                message: 'Expected recovery generation sequence does not match reconstructed sequence.',
                details: [
                    'expected' => $expectation->expectedRecoveryGenerations,
                    'actual' => $actualGenerations,
                ],
            );
        }

        $issues = array_merge(
            $issues,
            $this->compareOptionalPosture(
                'expectedReplayContinuityPosture',
                $expectation->expectedReplayContinuityPosture,
                $bundle->continuitySnapshot->replayContinuityPosture,
                'replay_continuity_posture_mismatch',
            ),
            $this->compareOptionalPosture(
                'expectedRetryPosture',
                $expectation->expectedRetryPosture,
                $bundle->continuitySnapshot->retryPosture,
                'retry_posture_mismatch',
            ),
            $this->compareOptionalPosture(
                'expectedDrainPosture',
                $expectation->expectedDrainPosture,
                $bundle->continuitySnapshot->drainPosture,
                'drain_posture_mismatch',
            ),
            $this->compareOptionalPosture(
                'expectedReconstructionPosture',
                $expectation->expectedReconstructionPosture,
                $bundle->continuitySnapshot->reconstructionPosture,
                'reconstruction_posture_mismatch',
            ),
        );

        $operations = [];
        foreach ($bundle->operations as $operation) {
            $operations[$operation->operationId] = $operation;
        }

        foreach ($expectation->expectedOperations as $expectedOperation) {
            $actual = $operations[$expectedOperation->operationId] ?? null;
            if ($actual === null) {
                $issues[] = new ReconstructionIssue(
                    kind: 'mismatch',
                    code: 'missing_operation_lifecycle',
                    message: sprintf('Expected operation %s was not reconstructed.', $expectedOperation->operationId),
                );
                continue;
            }

            $actualStates = array_map(
                static fn (array $state): string => (string) ($state['state'] ?? ''),
                $actual->observedStates,
            );

            if ($actualStates !== $expectedOperation->states) {
                $issues[] = new ReconstructionIssue(
                    kind: 'mismatch',
                    code: 'operation_state_sequence_mismatch',
                    message: sprintf('Expected operation lifecycle for %s does not match reconstructed states.', $expectedOperation->operationId),
                    details: [
                        'operation_id' => $expectedOperation->operationId,
                        'expected' => $expectedOperation->states,
                        'actual' => $actualStates,
                    ],
                );
            }

            foreach ([
                'finalState' => ['expected' => $expectedOperation->finalState, 'actual' => $actual->finalState, 'code' => 'operation_final_state_mismatch'],
                'retryPosture' => ['expected' => $expectedOperation->retryPosture, 'actual' => $actual->retryPosture, 'code' => 'operation_retry_posture_mismatch'],
                'drainPosture' => ['expected' => $expectedOperation->drainPosture, 'actual' => $actual->drainPosture, 'code' => 'operation_drain_posture_mismatch'],
            ] as $comparison) {
                if ($comparison['expected'] !== null && $comparison['expected'] !== $comparison['actual']) {
                    $issues[] = new ReconstructionIssue(
                        kind: 'mismatch',
                        code: $comparison['code'],
                        message: sprintf('Expected operation posture for %s does not match reconstructed posture.', $expectedOperation->operationId),
                        details: [
                            'operation_id' => $expectedOperation->operationId,
                            'expected' => $comparison['expected'],
                            'actual' => $comparison['actual'],
                        ],
                    );
                }
            }
        }

        $publications = [];
        foreach ($bundle->terminalPublications as $publication) {
            $publications[$publication->publicationId] = $publication;
        }

        foreach ($expectation->expectedTerminalPublications as $expectedPublication) {
            $actual = $publications[$expectedPublication->publicationId] ?? null;
            if ($actual === null || $actual->status !== $expectedPublication->status || $actual->operationId !== $expectedPublication->operationId) {
                $issues[] = new ReconstructionIssue(
                    kind: 'mismatch',
                    code: 'terminal_publication_mismatch',
                    message: sprintf('Expected terminal publication %s does not match reconstructed evidence.', $expectedPublication->publicationId),
                    details: [
                        'publication_id' => $expectedPublication->publicationId,
                        'expected_status' => $expectedPublication->status,
                        'actual_status' => $actual?->status,
                        'expected_operation_id' => $expectedPublication->operationId,
                        'actual_operation_id' => $actual?->operationId,
                    ],
                );
            }
        }

        $lifecycleSemantics = [];
        foreach ($bundle->lifecycleSemantics as $record) {
            $lifecycleSemantics[$record->semantic . '|' . ($record->operationId ?? '')] = $record;
        }

        foreach ($expectation->expectedLifecycleSemantics as $expectedSemantic) {
            $key = $expectedSemantic->semantic . '|' . ($expectedSemantic->operationId ?? '');
            $actual = $lifecycleSemantics[$key] ?? null;
            if ($actual === null || $actual->posture !== $expectedSemantic->posture) {
                $issues[] = new ReconstructionIssue(
                    kind: 'mismatch',
                    code: 'lifecycle_semantic_mismatch',
                    message: sprintf('Expected lifecycle semantic %s does not match reconstructed evidence.', $expectedSemantic->semantic),
                    details: [
                        'semantic' => $expectedSemantic->semantic,
                        'expected_posture' => $expectedSemantic->posture,
                        'actual_posture' => $actual?->posture,
                        'operation_id' => $expectedSemantic->operationId,
                    ],
                );
            }
        }

        return new ScenarioComparisonResult(
            scenarioName: $expectation->scenarioName,
            passed: $issues === [],
            bundleId: $bundle->manifest->bundleId,
            issues: $issues,
        );
    }

    public function exportBundle(EvidenceBundle $bundle): string
    {
        return $this->serializer->serializeBundle($bundle);
    }

    public function exportComparison(ScenarioComparisonResult $comparison): string
    {
        return $this->serializer->serializeComparison($comparison);
    }

    /**
     * @return list<StoredReplayRecord>
     */
    private function readWindow(ReconstructionWindow $window): array
    {
        $cursor = $window->from;
        $records = [];

        while (true) {
            $batch = $this->reader->readFromCursor($cursor, $window->batchLimit, $window->criteria);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $record) {
                if ($window->untilAppendSequence !== null && $record->appendSequence > $window->untilAppendSequence) {
                    return $records;
                }

                $records[] = $record;
                $cursor = $cursor->advance($record->appendSequence);
            }
        }

        return $records;
    }

    /**
     * @param list<RecoveryGenerationObservation> $observations
     */
    private function lastGenerationId(array $observations): ?string
    {
        if ($observations === []) {
            return null;
        }

        return $observations[array_key_last($observations)]->generationId;
    }

    /**
     * @param list<ReconstructionIssue> $issues
     */
    private function observePosture(
        ?string $current,
        ?string $observed,
        string $issueCode,
        StoredReplayRecord $record,
        array &$issues,
    ): ?string {
        if ($observed === null) {
            return $current;
        }

        if ($current !== null && $current !== $observed) {
            $issues[] = new ReconstructionIssue(
                kind: 'ambiguity',
                code: $issueCode,
                message: sprintf('Observed posture changed from %s to %s within one reconstruction window.', $current, $observed),
                appendSequence: $record->appendSequence,
                artifactName: $record->artifactName,
            );
        }

        return $observed;
    }

    /**
     * @param array<string, array<string, mixed>> $operations
     * @param list<ReconstructionIssue>           $issues
     */
    private function observeOperation(StoredReplayRecord $record, array &$operations, array &$issues): void
    {
        $state = RuntimeTruthExtractor::operationState($record);
        $operationId = RuntimeTruthExtractor::operationId($record);

        if ($state === null && $operationId === null) {
            return;
        }

        if ($operationId === null) {
            $issues[] = new ReconstructionIssue(
                kind: 'insufficient',
                code: 'operation_state_without_identity',
                message: 'Stored artifacts exposed operation-state evidence without a stable operation_id.',
                appendSequence: $record->appendSequence,
                artifactName: $record->artifactName,
            );
            return;
        }

        if (!array_key_exists($operationId, $operations)) {
            $operations[$operationId] = [
                'operation_id' => $operationId,
                'operation_kind' => RuntimeTruthExtractor::operationKind($record),
                'bgapi_job_uuid' => RuntimeTruthExtractor::bgapiJobUuid($record),
                'accepted_append_sequence' => null,
                'observed_states' => [],
                'final_state' => null,
                'retry_posture' => null,
                'drain_posture' => null,
                'issues' => [],
            ];
        }

        if ($state !== null) {
            $operations[$operationId]['observed_states'][] = [
                'state' => $state,
                'append_sequence' => $record->appendSequence,
                'artifact_name' => $record->artifactName,
            ];
            $operations[$operationId]['final_state'] = $state;

            if ($state === 'accepted' && $operations[$operationId]['accepted_append_sequence'] === null) {
                $operations[$operationId]['accepted_append_sequence'] = $record->appendSequence;
            }
        }

        $operations[$operationId]['retry_posture'] = RuntimeTruthExtractor::retryPosture($record)
            ?? $operations[$operationId]['retry_posture'];
        $operations[$operationId]['drain_posture'] = RuntimeTruthExtractor::drainPosture($record)
            ?? $operations[$operationId]['drain_posture'];
    }

    /**
     * @param list<TerminalPublicationEvidenceRecord> $terminalPublications
     * @param list<ReconstructionIssue>               $issues
     */
    private function observeTerminalPublication(
        StoredReplayRecord $record,
        array &$terminalPublications,
        array &$issues,
    ): void {
        $snapshot = RuntimeTruthExtractor::terminalPublicationSnapshot($record);
        if ($snapshot === null) {
            return;
        }

        $publicationId = RuntimeTruthExtractor::terminalPublicationId($record);
        $status = RuntimeTruthExtractor::terminalPublicationStatus($record);
        $operationId = RuntimeTruthExtractor::operationId($record);

        if ($publicationId === null || $status === null) {
            $issues[] = new ReconstructionIssue(
                kind: 'insufficient',
                code: 'terminal_publication_snapshot_incomplete',
                message: 'Stored artifacts exposed terminal-publication evidence without a stable publication id and status.',
                appendSequence: $record->appendSequence,
                artifactName: $record->artifactName,
            );
            return;
        }

        $terminalPublications[] = new TerminalPublicationEvidenceRecord(
            publicationId: $publicationId,
            status: $status,
            appendSequence: $record->appendSequence,
            artifactName: $record->artifactName,
            operationId: $operationId,
            facts: $snapshot,
        );
    }

    /**
     * @param list<LifecycleSemanticEvidenceRecord> $lifecycleSemantics
     * @param list<ReconstructionIssue>             $issues
     */
    private function observeLifecycleSemantic(
        StoredReplayRecord $record,
        array &$lifecycleSemantics,
        array &$issues,
    ): void {
        $snapshot = RuntimeTruthExtractor::lifecycleSemanticSnapshot($record);
        if ($snapshot === null) {
            return;
        }

        $semantic = RuntimeTruthExtractor::lifecycleSemantic($record);
        $posture = RuntimeTruthExtractor::lifecyclePosture($record);

        if ($semantic === null || $posture === null) {
            $issues[] = new ReconstructionIssue(
                kind: 'insufficient',
                code: 'lifecycle_semantic_snapshot_incomplete',
                message: 'Stored artifacts exposed lifecycle-semantic evidence without semantic and posture values.',
                appendSequence: $record->appendSequence,
                artifactName: $record->artifactName,
            );
            return;
        }

        $lifecycleSemantics[] = new LifecycleSemanticEvidenceRecord(
            semantic: $semantic,
            posture: $posture,
            appendSequence: $record->appendSequence,
            artifactName: $record->artifactName,
            operationId: RuntimeTruthExtractor::operationId($record),
            facts: $snapshot,
        );
    }

    /**
     * @param list<ReconstructionIssue> $issues
     */
    private function deriveVerdictPosture(array $issues): string
    {
        foreach ($issues as $issue) {
            if ($issue->kind === 'contradiction') {
                return 'contradicted';
            }
        }

        foreach ($issues as $issue) {
            if ($issue->kind === 'insufficient') {
                return 'insufficient';
            }
        }

        foreach ($issues as $issue) {
            if ($issue->kind === 'ambiguity') {
                return 'partial';
            }
        }

        return 'complete';
    }

    /**
     * @return list<ReconstructionIssue>
     */
    private function compareOptionalPosture(
        string $field,
        ?string $expected,
        ?string $actual,
        string $code,
    ): array {
        if ($expected === null || $expected === $actual) {
            return [];
        }

        return [
            new ReconstructionIssue(
                kind: 'mismatch',
                code: $code,
                message: sprintf('Expected %s does not match reconstructed posture.', $field),
                details: [
                    'expected' => $expected,
                    'actual' => $actual,
                ],
            ),
        ];
    }

    /**
     * @param list<EvidenceRecordReference>           $recordReferences
     * @param list<OperationRecoveryRecord>           $operations
     * @param list<TerminalPublicationEvidenceRecord> $terminalPublications
     * @param list<LifecycleSemanticEvidenceRecord>   $lifecycleSemantics
     */
    private function bundleIdFor(
        ReconstructionWindow $window,
        RuntimeContinuitySnapshot $continuitySnapshot,
        array $recordReferences,
        array $operations,
        array $terminalPublications,
        array $lifecycleSemantics,
        ReconstructionVerdict $verdict,
    ): string {
        $payload = [
            'window' => [
                'from_last_consumed_sequence' => $window->from->lastConsumedSequence,
                'from_byte_offset_hint' => $window->from->byteOffsetHint,
                'until_append_sequence' => $window->untilAppendSequence,
                'checkpoint_key' => $window->checkpointKey,
                'checkpoint_metadata' => $window->checkpointMetadata,
            ],
            'continuity_snapshot' => $this->serializer->bundleToArray(new EvidenceBundle(
                manifest: new RecoveryManifest(
                    bundleVersion: 1,
                    bundleId: 'pending',
                    window: $window,
                    recordCount: count($recordReferences),
                    firstAppendSequence: $recordReferences === [] ? null : $recordReferences[0]->appendSequence,
                    lastAppendSequence: $recordReferences === [] ? null : $recordReferences[array_key_last($recordReferences)]->appendSequence,
                    verdict: $verdict,
                ),
                continuitySnapshot: $continuitySnapshot,
                recordReferences: $recordReferences,
                operations: $operations,
                terminalPublications: $terminalPublications,
                lifecycleSemantics: $lifecycleSemantics,
            )),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
