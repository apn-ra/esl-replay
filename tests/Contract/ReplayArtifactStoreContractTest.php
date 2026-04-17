<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Contract;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Adapter\Sqlite\SqliteReplayArtifactStore;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
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

    private function makeStore(string $adapter): ReplayArtifactStoreInterface
    {
        return match ($adapter) {
            'filesystem' => new FilesystemReplayArtifactStore($this->tmpDir),
            'sqlite' => new SqliteReplayArtifactStore($this->tmpDir . '/replay.sqlite'),
            default => throw new \InvalidArgumentException("Unknown adapter {$adapter}."),
        };
    }
}
