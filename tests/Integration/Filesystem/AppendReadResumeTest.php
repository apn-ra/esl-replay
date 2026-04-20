<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Filesystem;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Artifact\OperatorIdentityKeys;
use Apntalk\EslReplay\Config\ReplayConfig;
use Apntalk\EslReplay\Config\StorageConfig;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\ArtifactPersistenceException;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Serialization\ArtifactChecksum;
use Apntalk\EslReplay\Storage\ReplayArtifactStore;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for append-only write, cursor-based reads, and restart safety.
 *
 * Each test uses an isolated temporary directory that is cleaned up after the test.
 */
final class AppendReadResumeTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-replay-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory and all files within it
        $found = glob($this->tmpDir . '/*');
        $files = $found !== false ? $found : [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function test_write_single_artifact_and_read_it_back_by_id(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);

        $id     = $store->write(FakeCapturedArtifact::apiDispatch());
        $record = $store->readById($id);

        $this->assertNotNull($record);
        $this->assertTrue($record->id->equals($id));
        $this->assertSame('api.dispatch', $record->artifactName);
        $this->assertSame(1, $record->appendSequence);
    }

    public function test_multiple_artifacts_are_ordered_by_append_sequence(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);

        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());
        $store->write(FakeCapturedArtifact::bgapiDispatch());

        $cursor  = $store->openCursor();
        $records = $store->readFromCursor($cursor, 10);

        $this->assertCount(3, $records);
        $this->assertSame(1, $records[0]->appendSequence);
        $this->assertSame(2, $records[1]->appendSequence);
        $this->assertSame(3, $records[2]->appendSequence);
        $this->assertSame('api.dispatch', $records[0]->artifactName);
        $this->assertSame('event.raw', $records[1]->artifactName);
        $this->assertSame('bgapi.dispatch', $records[2]->artifactName);
    }

    public function test_cursor_resumes_after_last_consumed_record(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);

        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());
        $store->write(FakeCapturedArtifact::bgapiDispatch());

        // Read only the first record
        $cursor    = $store->openCursor();
        $firstBatch = $store->readFromCursor($cursor, 1);
        $this->assertCount(1, $firstBatch);
        $this->assertSame(1, $firstBatch[0]->appendSequence);

        // Advance cursor past first record and read the rest
        $cursor     = $cursor->advance($firstBatch[0]->appendSequence);
        $remaining  = $store->readFromCursor($cursor, 10);

        $this->assertCount(2, $remaining);
        $this->assertSame(2, $remaining[0]->appendSequence);
        $this->assertSame(3, $remaining[1]->appendSequence);
    }

    public function test_open_cursor_at_start_returns_all_records(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);

        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::apiDispatch());

        $records = $store->readFromCursor($store->openCursor(), 100);
        $this->assertCount(2, $records);
    }

    public function test_read_from_cursor_returns_empty_for_empty_file(): void
    {
        $store   = new FilesystemReplayArtifactStore($this->tmpDir);
        $records = $store->readFromCursor($store->openCursor(), 100);
        $this->assertSame([], $records);
    }

    public function test_read_by_id_returns_null_for_nonexistent_id(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        // Write something so the file exists
        $store->write(FakeCapturedArtifact::apiDispatch());

        $notFound = $store->readById(new \Apntalk\EslReplay\Storage\ReplayRecordId('nonexistent-id'));
        $this->assertNull($notFound);
    }

    public function test_restart_store_continues_sequence_from_last_written(): void
    {
        // First process: write 3 records
        $store1 = new FilesystemReplayArtifactStore($this->tmpDir);
        $store1->write(FakeCapturedArtifact::apiDispatch());
        $store1->write(FakeCapturedArtifact::eventRaw());
        $store1->write(FakeCapturedArtifact::bgapiDispatch());
        unset($store1);

        // Second process (new store instance, same directory): write 1 more
        $store2 = new FilesystemReplayArtifactStore($this->tmpDir);
        $id4    = $store2->write(FakeCapturedArtifact::apiDispatch());

        $record4 = $store2->readById($id4);
        $this->assertNotNull($record4);
        $this->assertSame(4, $record4->appendSequence);
    }

    public function test_second_filesystem_writer_for_same_path_fails_closed_while_owner_is_active(): void
    {
        $owner = new FilesystemReplayArtifactStore($this->tmpDir);
        $owner->write(FakeCapturedArtifact::apiDispatch());

        $this->expectException(ArtifactPersistenceException::class);
        $this->expectExceptionMessage('another package writer already owns this artifact stream');

        new FilesystemReplayArtifactStore($this->tmpDir);
    }

    public function test_filesystem_writer_ownership_is_released_when_owner_lifecycle_ends(): void
    {
        $owner = new FilesystemReplayArtifactStore($this->tmpDir);
        $owner->write(FakeCapturedArtifact::apiDispatch());
        unset($owner);

        $nextOwner = new FilesystemReplayArtifactStore($this->tmpDir);
        $id = $nextOwner->write(FakeCapturedArtifact::eventRaw());
        $record = $nextOwner->readById($id);

        $this->assertNotNull($record);
        $this->assertSame(2, $record->appendSequence);
    }

    public function test_existing_unreadable_artifact_file_fails_sequence_recovery(): void
    {
        $artifactPath = $this->tmpDir . '/artifacts.ndjson';
        file_put_contents($artifactPath, "existing stream\n");
        chmod($artifactPath, 0000);
        clearstatcache(true, $artifactPath);

        if (is_readable($artifactPath)) {
            chmod($artifactPath, 0644);
            $this->markTestSkipped('Current filesystem permits reading chmod 0000 files.');
        }

        $this->expectException(ArtifactPersistenceException::class);
        $this->expectExceptionMessage('failed to open existing artifact file for sequence recovery');

        try {
            new FilesystemReplayArtifactStore($this->tmpDir);
        } finally {
            chmod($artifactPath, 0644);
        }
    }

    public function test_read_by_id_fails_explicitly_when_existing_artifact_file_is_unreadable(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $id = $store->write(FakeCapturedArtifact::apiDispatch());

        $artifactPath = $this->tmpDir . '/artifacts.ndjson';
        chmod($artifactPath, 0000);
        clearstatcache(true, $artifactPath);

        if (is_readable($artifactPath)) {
            chmod($artifactPath, 0644);
            $this->markTestSkipped('Current filesystem permits reading chmod 0000 files.');
        }

        $this->expectException(ArtifactPersistenceException::class);
        $this->expectExceptionMessage('failed to open artifact file for readById');

        try {
            $store->readById($id);
        } finally {
            chmod($artifactPath, 0644);
        }
    }

    public function test_read_from_cursor_fails_explicitly_when_existing_artifact_file_is_unreadable(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch());

        $artifactPath = $this->tmpDir . '/artifacts.ndjson';
        chmod($artifactPath, 0000);
        clearstatcache(true, $artifactPath);

        if (is_readable($artifactPath)) {
            chmod($artifactPath, 0644);
            $this->markTestSkipped('Current filesystem permits reading chmod 0000 files.');
        }

        $this->expectException(ArtifactPersistenceException::class);
        $this->expectExceptionMessage('failed to open artifact file for ordered read');

        try {
            $store->readFromCursor($store->openCursor(), 10);
        } finally {
            chmod($artifactPath, 0644);
        }
    }

    public function test_restart_can_read_all_previously_written_records(): void
    {
        // Write 3 records in first instance
        $store1 = new FilesystemReplayArtifactStore($this->tmpDir);
        $store1->write(FakeCapturedArtifact::apiDispatch());
        $store1->write(FakeCapturedArtifact::eventRaw());
        $store1->write(FakeCapturedArtifact::bgapiDispatch());
        unset($store1);

        // Read from a fresh store instance (simulates process restart)
        $store2  = new FilesystemReplayArtifactStore($this->tmpDir);
        $records = $store2->readFromCursor($store2->openCursor(), 100);

        $this->assertCount(3, $records);
    }

    public function test_stored_records_pass_checksum_verification(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());

        $records = $store->readFromCursor($store->openCursor(), 10);
        foreach ($records as $record) {
            $this->assertTrue(
                ArtifactChecksum::verify($record),
                "Checksum verification failed for record {$record->id}",
            );
        }
    }

    public function test_normal_reads_return_tampered_records_and_checksum_verification_is_consumer_invoked(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch());

        $artifactPath = $this->tmpDir . '/artifacts.ndjson';
        $lines = file($artifactPath, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);

        /** @var array<string, mixed> $stored */
        $stored = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($stored);
        $stored['payload'] = ['command' => 'tampered'];
        file_put_contents(
            $artifactPath,
            json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );

        $records = $store->readFromCursor($store->openCursor(), 10);

        $this->assertCount(1, $records);
        $this->assertSame(['command' => 'tampered'], $records[0]->payload);
        $this->assertFalse(ArtifactChecksum::verify($records[0]));
    }

    public function test_payload_is_preserved_exactly_through_storage(): void
    {
        $payload  = ['cmd' => 'originate', 'dest' => 'sofia/internal/1001', 'nested' => ['a' => 1]];
        $artifact = new FakeCapturedArtifact(payload: $payload);

        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $id    = $store->write($artifact);

        $record = $store->readById($id);
        $this->assertNotNull($record);
        $this->assertSame($payload, $record->payload);
    }

    public function test_limit_parameter_restricts_returned_records(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        for ($i = 0; $i < 10; $i++) {
            $store->write(FakeCapturedArtifact::apiDispatch());
        }

        $records = $store->readFromCursor($store->openCursor(), 3);
        $this->assertCount(3, $records);
        $this->assertSame(1, $records[0]->appendSequence);
        $this->assertSame(3, $records[2]->appendSequence);
    }

    public function test_entry_point_make_creates_filesystem_store(): void
    {
        $config = new ReplayConfig(new StorageConfig($this->tmpDir));
        $store  = ReplayArtifactStore::make($config);

        $id     = $store->write(FakeCapturedArtifact::apiDispatch());
        $record = $store->readById($id);

        $this->assertNotNull($record);
    }

    public function test_entry_point_rejects_unknown_adapter(): void
    {
        $config = new ReplayConfig(new StorageConfig($this->tmpDir, 'unknown-adapter'));
        $this->expectException(\InvalidArgumentException::class);
        ReplayArtifactStore::make($config);
    }

    public function test_artifact_version_is_preserved(): void
    {
        $artifact = new FakeCapturedArtifact(artifactVersion: '42');
        $store    = new FilesystemReplayArtifactStore($this->tmpDir);
        $id       = $store->write($artifact);
        $record   = $store->readById($id);

        $this->assertNotNull($record);
        $this->assertSame('42', $record->artifactVersion);
    }

    public function test_read_from_cursor_can_filter_by_artifact_name(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());
        $store->write(FakeCapturedArtifact::apiDispatch('sess-002'));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(artifactName: 'api.dispatch'),
        );

        $this->assertCount(2, $records);
        $this->assertSame([1, 3], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    public function test_read_from_cursor_can_filter_by_job_uuid(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::bgapiDispatch('job-a'));
        $store->write(FakeCapturedArtifact::bgapiDispatch('job-b'));
        $store->write(FakeCapturedArtifact::bgapiDispatch('job-a'));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(jobUuid: 'job-a'),
        );

        $this->assertCount(2, $records);
        $this->assertSame([1, 3], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    public function test_read_from_cursor_can_filter_by_session_id(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch('sess-a'));
        $store->write(FakeCapturedArtifact::eventRaw(sessionId: 'sess-b'));
        $store->write(FakeCapturedArtifact::apiDispatch('sess-a'));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(sessionId: 'sess-a'),
        );

        $this->assertCount(2, $records);
        $this->assertSame([1, 3], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    public function test_read_from_cursor_can_filter_by_connection_generation(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(new FakeCapturedArtifact(connectionGeneration: 'gen-1'));
        $store->write(new FakeCapturedArtifact(connectionGeneration: 'gen-2'));
        $store->write(new FakeCapturedArtifact(connectionGeneration: 'gen-1'));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(connectionGeneration: 'gen-1'),
        );

        $this->assertCount(2, $records);
        $this->assertSame([1, 3], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    public function test_read_from_cursor_can_filter_by_replay_session_id(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(new FakeCapturedArtifact(correlationIds: [OperatorIdentityKeys::REPLAY_SESSION_ID => 'replay-a']));
        $store->write(new FakeCapturedArtifact(correlationIds: [OperatorIdentityKeys::REPLAY_SESSION_ID => 'replay-b']));
        $store->write(new FakeCapturedArtifact(correlationIds: [OperatorIdentityKeys::REPLAY_SESSION_ID => 'replay-a']));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(replaySessionId: 'replay-a'),
        );

        $this->assertCount(2, $records);
        $this->assertSame([1, 3], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    public function test_read_from_cursor_can_filter_by_runtime_metadata_fields(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(new FakeCapturedArtifact(runtimeFlags: [
            OperatorIdentityKeys::PBX_NODE_SLUG => 'pbx-a',
            OperatorIdentityKeys::WORKER_SESSION_ID => 'worker-a',
        ]));
        $store->write(new FakeCapturedArtifact(runtimeFlags: [
            OperatorIdentityKeys::PBX_NODE_SLUG => 'pbx-b',
            OperatorIdentityKeys::WORKER_SESSION_ID => 'worker-b',
        ]));
        $store->write(new FakeCapturedArtifact(runtimeFlags: [
            OperatorIdentityKeys::PBX_NODE_SLUG => 'pbx-a',
            OperatorIdentityKeys::WORKER_SESSION_ID => 'worker-a',
        ]));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(
                pbxNodeSlug: 'pbx-a',
                workerSessionId: 'worker-a',
            ),
        );

        $this->assertCount(2, $records);
        $this->assertSame([1, 3], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    public function test_read_from_cursor_can_filter_by_inclusive_time_window(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(new FakeCapturedArtifact(
            captureTimestamp: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
        ));
        $store->write(new FakeCapturedArtifact(
            captureTimestamp: new \DateTimeImmutable('2024-01-15T10:30:00+00:00'),
        ));
        $store->write(new FakeCapturedArtifact(
            captureTimestamp: new \DateTimeImmutable('2024-01-15T11:00:00+00:00'),
        ));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(
                capturedFrom: new \DateTimeImmutable('2024-01-15T10:15:00+00:00'),
                capturedUntil: new \DateTimeImmutable('2024-01-15T11:00:00+00:00'),
            ),
        );

        $this->assertCount(2, $records);
        $this->assertSame([2, 3], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    public function test_read_from_cursor_can_apply_combined_bounded_filters(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(new FakeCapturedArtifact(
            artifactName: 'event.raw',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T09:59:00+00:00'),
            connectionGeneration: 'gen-1',
            sessionId: 'sess-a',
            jobUuid: 'job-a',
        ));
        $store->write(new FakeCapturedArtifact(
            artifactName: 'event.raw',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T10:30:00+00:00'),
            connectionGeneration: 'gen-1',
            sessionId: 'sess-a',
            jobUuid: 'job-a',
        ));
        $store->write(new FakeCapturedArtifact(
            artifactName: 'event.raw',
            captureTimestamp: new \DateTimeImmutable('2024-01-15T10:30:00+00:00'),
            connectionGeneration: 'gen-2',
            sessionId: 'sess-a',
            jobUuid: 'job-a',
        ));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(
                capturedFrom: new \DateTimeImmutable('2024-01-15T10:00:00+00:00'),
                capturedUntil: new \DateTimeImmutable('2024-01-15T10:45:00+00:00'),
                artifactName: 'event.raw',
                jobUuid: 'job-a',
                sessionId: 'sess-a',
                connectionGeneration: 'gen-1',
            ),
        );

        $this->assertCount(1, $records);
        $this->assertSame(2, $records[0]->appendSequence);
    }

    public function test_filtered_reads_preserve_append_order(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::eventRaw());
        $store->write(FakeCapturedArtifact::apiDispatch('sess-a'));
        $store->write(FakeCapturedArtifact::bgapiDispatch());
        $store->write(FakeCapturedArtifact::apiDispatch('sess-b'));

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(artifactName: 'api.dispatch'),
        );

        $this->assertSame([2, 4], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    public function test_filtered_reads_return_empty_array_when_no_records_match(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());

        $records = $store->readFromCursor(
            $store->openCursor(),
            10,
            new ReplayReadCriteria(jobUuid: 'missing-job'),
        );

        $this->assertSame([], $records);
    }

    public function test_filtered_chunked_reads_remain_deterministic_for_export_style_streaming(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch('sess-a'));
        $store->write(FakeCapturedArtifact::eventRaw(sessionId: 'sess-b'));
        $store->write(FakeCapturedArtifact::apiDispatch('sess-c'));
        $store->write(FakeCapturedArtifact::bgapiDispatch('job-z'));
        $store->write(FakeCapturedArtifact::apiDispatch('sess-d'));

        $criteria = new ReplayReadCriteria(artifactName: 'api.dispatch');
        $oneShot  = $store->readFromCursor($store->openCursor(), 10, $criteria);

        $cursor   = $store->openCursor();
        $chunked  = [];

        while (true) {
            $batch = $store->readFromCursor($cursor, 1, $criteria);
            if ($batch === []) {
                break;
            }

            $record   = $batch[0];
            $chunked[] = $record;
            $cursor    = $cursor->advance($record->appendSequence);
        }

        $this->assertSame(
            array_map(static fn ($record) => $record->appendSequence, $oneShot),
            array_map(static fn ($record) => $record->appendSequence, $chunked),
        );
    }
}
