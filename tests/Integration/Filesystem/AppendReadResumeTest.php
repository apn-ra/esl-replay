<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Filesystem;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Config\ReplayConfig;
use Apntalk\EslReplay\Config\StorageConfig;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
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

        // Second process (new store instance, same directory): write 1 more
        $store2 = new FilesystemReplayArtifactStore($this->tmpDir);
        $id4    = $store2->write(FakeCapturedArtifact::apiDispatch());

        $record4 = $store2->readById($id4);
        $this->assertNotNull($record4);
        $this->assertSame(4, $record4->appendSequence);
    }

    public function test_restart_can_read_all_previously_written_records(): void
    {
        // Write 3 records in first instance
        $store1 = new FilesystemReplayArtifactStore($this->tmpDir);
        $store1->write(FakeCapturedArtifact::apiDispatch());
        $store1->write(FakeCapturedArtifact::eventRaw());
        $store1->write(FakeCapturedArtifact::bgapiDispatch());

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
}
