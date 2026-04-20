<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Contract;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Adapter\Sqlite\SqliteReplayArtifactStore;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Recovery\RecoveryEvidenceEngine;
use Apntalk\EslReplay\Recovery\RecoveryMetadataKeys;
use Apntalk\EslReplay\Recovery\ReconstructionWindow;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReplayArtifactStoreContractTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-store-contract-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $found = glob($this->tmpDir . '/*');
        $files = $found !== false ? $found : [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * @return iterable<string, array{adapter: string}>
     */
    public static function storeProvider(): iterable
    {
        yield 'filesystem' => ['adapter' => 'filesystem'];
        yield 'sqlite' => ['adapter' => 'sqlite'];
    }

    #[DataProvider('storeProvider')]
    public function test_append_order_and_bounded_filters_match_across_adapters(string $adapter): void
    {
        $store = $this->makeStore($adapter);
        $store->write(new FakeCapturedArtifact(
            artifactName: 'api.dispatch',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
            sessionId: 'sess-a',
            connectionGeneration: 'gen-1',
            correlationIds: ['replay_session_id' => 'replay-a'],
            runtimeFlags: ['pbx_node_slug' => 'pbx-a', 'worker_session_id' => 'worker-a'],
        ));
        $store->write(new FakeCapturedArtifact(
            artifactName: 'event.raw',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T10:30:00+00:00'),
            sessionId: 'sess-b',
            connectionGeneration: 'gen-2',
            jobUuid: 'job-b',
            correlationIds: ['replay_session_id' => 'replay-b'],
            runtimeFlags: ['pbx_node_slug' => 'pbx-b', 'worker_session_id' => 'worker-b'],
        ));
        $store->write(new FakeCapturedArtifact(
            artifactName: 'api.dispatch',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T11:00:00+00:00'),
            sessionId: 'sess-a',
            connectionGeneration: 'gen-1',
            correlationIds: ['replay_session_id' => 'replay-a'],
            runtimeFlags: ['pbx_node_slug' => 'pbx-a', 'worker_session_id' => 'worker-a'],
        ));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(
                capturedFrom: new \DateTimeImmutable('2024-01-15T09:00:00+00:00'),
                capturedUntil: new \DateTimeImmutable('2024-01-15T12:00:00+00:00'),
                artifactName: 'api.dispatch',
                sessionId: 'sess-a',
                connectionGeneration: 'gen-1',
                replaySessionId: 'replay-a',
                pbxNodeSlug: 'pbx-a',
                workerSessionId: 'worker-a',
            ),
        );

        $this->assertSame([1, 3], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    #[DataProvider('storeProvider')]
    public function test_restart_safe_sequence_recovery_matches_across_adapters(string $adapter): void
    {
        $store = $this->makeStore($adapter);
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());
        unset($store);

        $storeAfterRestart = $this->makeStore($adapter);
        $id = $storeAfterRestart->write(FakeCapturedArtifact::bgapiDispatch());
        $record = $storeAfterRestart->readById($id);

        $this->assertNotNull($record);
        $this->assertSame(3, $record->appendSequence);
    }

    #[DataProvider('storeProvider')]
    public function test_operator_identity_filters_match_across_adapters(string $adapter): void
    {
        $store = $this->makeStore($adapter);
        $store->write(new FakeCapturedArtifact(
            artifactName: 'event.raw',
            correlationIds: ['replay_session_id' => 'replay-1'],
            runtimeFlags: ['pbx_node_slug' => 'pbx-a', 'worker_session_id' => 'worker-1'],
        ));
        $store->write(new FakeCapturedArtifact(
            artifactName: 'event.raw',
            correlationIds: ['replay_session_id' => 'replay-2'],
            runtimeFlags: ['pbx_node_slug' => 'pbx-b', 'worker_session_id' => 'worker-2'],
        ));
        $store->write(new FakeCapturedArtifact(
            artifactName: 'event.raw',
            correlationIds: ['replay_session_id' => 'replay-1'],
            runtimeFlags: ['pbx_node_slug' => 'pbx-a', 'worker_session_id' => 'worker-1'],
        ));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(
                replaySessionId: 'replay-1',
                pbxNodeSlug: 'pbx-a',
                workerSessionId: 'worker-1',
            ),
        );

        $this->assertSame([1, 3], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    #[DataProvider('storeProvider')]
    public function test_enriched_runtime_truth_roundtrips_into_deterministic_reconstruction(string $adapter): void
    {
        $store = $this->makeStore($adapter);
        $store->write(FakeCapturedArtifact::enriched(
            artifactName: 'bgapi.dispatch',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
            sessionId: 'sess-1',
            jobUuid: 'job-1',
            preparedRecoveryContext: [RecoveryMetadataKeys::RECOVERY_GENERATION_ID => 'gen-1'],
            runtimeRecoverySnapshot: [
                RecoveryMetadataKeys::REPLAY_CONTINUITY_POSTURE => 'continuous',
            ],
            runtimeOperationSnapshot: [
                RecoveryMetadataKeys::OPERATION_ID => 'op-1',
                RecoveryMetadataKeys::OPERATION_STATE => 'accepted',
            ],
            correlationIds: ['replay_session_id' => 'replay-1'],
        ));
        $store->write(FakeCapturedArtifact::enriched(
            artifactName: 'bgapi.complete',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T10:00:01+00:00'),
            sessionId: 'sess-1',
            jobUuid: 'job-1',
            runtimeOperationSnapshot: [
                RecoveryMetadataKeys::OPERATION_ID => 'op-1',
                RecoveryMetadataKeys::OPERATION_STATE => 'completed',
            ],
            runtimeTerminalPublicationSnapshot: [
                RecoveryMetadataKeys::TERMINAL_PUBLICATION_ID => 'pub-1',
                RecoveryMetadataKeys::TERMINAL_PUBLICATION_STATUS => 'published',
            ],
            correlationIds: ['replay_session_id' => 'replay-1'],
        ));

        $bundle = RecoveryEvidenceEngine::make($store)->reconstruct(
            new ReconstructionWindow($store->openCursor()),
        );

        $this->assertSame('complete', $bundle->manifest->verdict->posture);
        $this->assertSame('gen-1', $bundle->continuitySnapshot->recoveryGenerations[0]->generationId);
        $this->assertSame('completed', $bundle->operations[0]->finalState);
        $this->assertSame('pub-1', $bundle->terminalPublications[0]->publicationId);
    }

    private function makeStore(string $adapter): ReplayArtifactStoreInterface
    {
        return match ($adapter) {
            'filesystem' => new FilesystemReplayArtifactStore($this->tmpDir),
            'sqlite' => new SqliteReplayArtifactStore($this->tmpDir . '/replay.sqlite'),
            default => throw new \InvalidArgumentException("Unknown adapter {$adapter}."),
        };
    }
}
