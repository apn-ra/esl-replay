<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Sqlite;

use Apntalk\EslReplay\Adapter\Sqlite\SqliteReplayArtifactStore;
use Apntalk\EslReplay\Config\ReplayConfig;
use Apntalk\EslReplay\Config\StorageConfig;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Storage\ReplayArtifactStore;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

final class SqliteReplayArtifactStoreTest extends TestCase
{
    private string $tmpDir;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-sqlite-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
        $this->dbPath = $this->tmpDir . '/replay.sqlite';
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

    private function makeStore(): SqliteReplayArtifactStore
    {
        return new SqliteReplayArtifactStore($this->dbPath);
    }

    public function test_write_read_restart_and_filter_behavior_matches_contract(): void
    {
        $store = $this->makeStore();
        $store->write(new FakeCapturedArtifact(
            artifactName: 'api.dispatch',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
            sessionId: 'sess-a',
            connectionGeneration: 'gen-1',
        ));
        $store->write(new FakeCapturedArtifact(
            artifactName: 'event.raw',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T10:30:00+00:00'),
            sessionId: 'sess-b',
            connectionGeneration: 'gen-2',
            jobUuid: 'job-b',
        ));
        $store->write(new FakeCapturedArtifact(
            artifactName: 'api.dispatch',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T11:00:00+00:00'),
            sessionId: 'sess-a',
            connectionGeneration: 'gen-1',
        ));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(
                artifactName: 'api.dispatch',
                sessionId: 'sess-a',
                connectionGeneration: 'gen-1',
            ),
        );

        $this->assertSame([1, 3], array_map(static fn ($record) => $record->appendSequence, $records));

        $storeAfterRestart = $this->makeStore();
        $recordId = $storeAfterRestart->write(FakeCapturedArtifact::bgapiDispatch('job-z'));
        $record = $storeAfterRestart->readById($recordId);

        $this->assertNotNull($record);
        $this->assertSame(4, $record->appendSequence);
    }

    public function test_entry_point_make_can_build_sqlite_store(): void
    {
        $store = ReplayArtifactStore::make(new ReplayConfig(
            storage: new StorageConfig($this->dbPath, StorageConfig::ADAPTER_SQLITE),
        ));

        $id = $store->write(FakeCapturedArtifact::apiDispatch());
        $record = $store->readById($id);

        $this->assertNotNull($record);
        $this->assertInstanceOf(SqliteReplayArtifactStore::class, $store);
    }
}
