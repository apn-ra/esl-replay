<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

/**
 * Deterministic JSON serializer for recovery evidence bundles and comparison results.
 */
final class EvidenceBundleSerializer
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    public function serializeBundle(EvidenceBundle $bundle): string
    {
        return json_encode($this->bundleToArray($bundle), self::JSON_FLAGS);
    }

    public function serializeComparison(ScenarioComparisonResult $comparison): string
    {
        return json_encode($this->comparisonToArray($comparison), self::JSON_FLAGS);
    }

    /**
     * @return array<string, mixed>
     */
    public function bundleToArray(EvidenceBundle $bundle): array
    {
        return [
            'manifest' => $this->manifestToArray($bundle->manifest),
            'continuity_snapshot' => $this->continuitySnapshotToArray($bundle->continuitySnapshot),
            'record_references' => array_map($this->recordReferenceToArray(...), $bundle->recordReferences),
            'operations' => array_map($this->operationToArray(...), $bundle->operations),
            'terminal_publications' => array_map($this->terminalPublicationToArray(...), $bundle->terminalPublications),
            'lifecycle_semantics' => array_map($this->lifecycleSemanticToArray(...), $bundle->lifecycleSemantics),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function comparisonToArray(ScenarioComparisonResult $comparison): array
    {
        return [
            'scenario_name' => $comparison->scenarioName,
            'passed' => $comparison->passed,
            'bundle_id' => $comparison->bundleId,
            'issues' => array_map($this->issueToArray(...), $comparison->issues),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestToArray(RecoveryManifest $manifest): array
    {
        return [
            'bundle_version' => $manifest->bundleVersion,
            'bundle_id' => $manifest->bundleId,
            'window' => $this->windowToArray($manifest->window),
            'record_count' => $manifest->recordCount,
            'first_append_sequence' => $manifest->firstAppendSequence,
            'last_append_sequence' => $manifest->lastAppendSequence,
            'verdict' => $this->verdictToArray($manifest->verdict),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function windowToArray(ReconstructionWindow $window): array
    {
        return [
            'from_last_consumed_sequence' => $window->from->lastConsumedSequence,
            'from_byte_offset_hint' => $window->from->byteOffsetHint,
            'until_append_sequence' => $window->untilAppendSequence,
            'criteria' => $window->criteria === null ? null : [
                'captured_from' => $window->criteria->capturedFrom?->format(\DateTimeInterface::RFC3339_EXTENDED),
                'captured_until' => $window->criteria->capturedUntil?->format(\DateTimeInterface::RFC3339_EXTENDED),
                'artifact_name' => $window->criteria->artifactName,
                'job_uuid' => $window->criteria->jobUuid,
                'replay_session_id' => $window->criteria->replaySessionId,
                'pbx_node_slug' => $window->criteria->pbxNodeSlug,
                'worker_session_id' => $window->criteria->workerSessionId,
                'session_id' => $window->criteria->sessionId,
                'connection_generation' => $window->criteria->connectionGeneration,
            ],
            'batch_limit' => $window->batchLimit,
            'checkpoint_key' => $window->checkpointKey,
            'checkpoint_metadata' => self::normalizeAssociative($window->checkpointMetadata),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verdictToArray(ReconstructionVerdict $verdict): array
    {
        return [
            'posture' => $verdict->posture,
            'issues' => array_map($this->issueToArray(...), $verdict->issues),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function continuitySnapshotToArray(RuntimeContinuitySnapshot $snapshot): array
    {
        return [
            'recovery_generations' => array_map(
                static fn (RecoveryGenerationObservation $observation): array => [
                    'generation_id' => $observation->generationId,
                    'append_sequence' => $observation->appendSequence,
                    'artifact_name' => $observation->artifactName,
                ],
                $snapshot->recoveryGenerations,
            ),
            'replay_continuity_posture' => $snapshot->replayContinuityPosture,
            'retry_posture' => $snapshot->retryPosture,
            'drain_posture' => $snapshot->drainPosture,
            'reconstruction_posture' => $snapshot->reconstructionPosture,
            'last_observed_append_sequence' => $snapshot->lastObservedAppendSequence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordReferenceToArray(EvidenceRecordReference $reference): array
    {
        return [
            'record_id' => $reference->recordId,
            'append_sequence' => $reference->appendSequence,
            'artifact_name' => $reference->artifactName,
            'capture_timestamp' => $reference->captureTimestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operationToArray(OperationRecoveryRecord $operation): array
    {
        return [
            'operation_id' => $operation->operationId,
            'operation_kind' => $operation->operationKind,
            'bgapi_job_uuid' => $operation->bgapiJobUuid,
            'accepted_append_sequence' => $operation->acceptedAppendSequence,
            'observed_states' => array_map(
                static fn (array $state): array => EvidenceBundleSerializer::normalizeAssociative($state),
                $operation->observedStates,
            ),
            'final_state' => $operation->finalState,
            'retry_posture' => $operation->retryPosture,
            'drain_posture' => $operation->drainPosture,
            'issues' => array_map($this->issueToArray(...), $operation->issues),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function terminalPublicationToArray(TerminalPublicationEvidenceRecord $record): array
    {
        return [
            'publication_id' => $record->publicationId,
            'status' => $record->status,
            'append_sequence' => $record->appendSequence,
            'artifact_name' => $record->artifactName,
            'operation_id' => $record->operationId,
            'facts' => self::normalizeAssociative($record->facts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lifecycleSemanticToArray(LifecycleSemanticEvidenceRecord $record): array
    {
        return [
            'semantic' => $record->semantic,
            'posture' => $record->posture,
            'append_sequence' => $record->appendSequence,
            'artifact_name' => $record->artifactName,
            'operation_id' => $record->operationId,
            'facts' => self::normalizeAssociative($record->facts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function issueToArray(ReconstructionIssue $issue): array
    {
        return [
            'kind' => $issue->kind,
            'code' => $issue->code,
            'message' => $issue->message,
            'append_sequence' => $issue->appendSequence,
            'artifact_name' => $issue->artifactName,
            'details' => self::normalizeAssociative($issue->details),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function normalizeAssociative(array $values): array
    {
        ksort($values);

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $values[$key] = EvidenceBundleSerializer::normalizeNested($value);
            }
        }

        return $values;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function normalizeNested(array $values): array
    {
        if (array_is_list($values)) {
            return array_map(
                static fn (mixed $value): mixed => is_array($value) ? EvidenceBundleSerializer::normalizeNested($value) : $value,
                $values,
            );
        }

        /** @var array<string, mixed> $values */
        return EvidenceBundleSerializer::normalizeAssociative($values);
    }
}
