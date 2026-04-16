<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Filesystem;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Serialization\ReplayArtifactSerializer;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

final class FilesystemChaosTest extends TestCase
{
    private string $tmpDir;
    private string $artifactFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-filesystem-chaos-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
        $this->artifactFile = $this->tmpDir . '/artifacts.ndjson';
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

    public function test_reader_skips_malformed_head_middle_and_tail_lines_but_preserves_valid_append_order(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $firstId = $store->write(FakeCapturedArtifact::apiDispatch('sess-a'));
        $store->write(FakeCapturedArtifact::eventRaw(sessionId: 'sess-b'));
        $thirdId = $store->write(FakeCapturedArtifact::bgapiDispatch('job-c'));

        $validLines = file($this->artifactFile, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($validLines);

        $invalidShape = json_encode([
            'schema_version' => ReplayArtifactSerializer::SCHEMA_VERSION,
            'id' => 'broken-shape',
            'artifact_version' => '1',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $content = implode("\n", [
            '{"broken": ',
            $validLines[0],
            '',
            $invalidShape,
            $validLines[1],
            '{"schema_version":1,"id":"bad-seq","artifact_version":"1","artifact_name":"api.dispatch","capture_timestamp":"2024-01-01T00:00:00.000000+00:00","stored_at":"2024-01-01T00:00:00.000000+00:00","append_sequence":0,"connection_generation":null,"session_id":null,"job_uuid":null,"event_name":null,"capture_path":null,"correlation_ids":{},"runtime_flags":{},"payload":{},"checksum":"abc","tags":{}}',
            $validLines[2],
            '{"truncated_tail"',
            '',
        ]) . "\n";

        file_put_contents($this->artifactFile, $content);

        $records = $store->readFromCursor($store->openCursor(), 10);

        $this->assertSame(
            [1, 2, 3],
            array_map(static fn ($record) => $record->appendSequence, $records),
        );
        $this->assertTrue($records[0]->id->equals($firstId));
        $this->assertTrue($records[2]->id->equals($thirdId));
    }

    public function test_stale_byte_offset_hint_past_end_of_file_does_not_hide_valid_records(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());
        $store->write(FakeCapturedArtifact::bgapiDispatch());

        $cursor = new ReplayReadCursor(lastConsumedSequence: 1, byteOffsetHint: 999999);

        $records = $store->readFromCursor($cursor, 10);

        $this->assertSame([2, 3], array_map(static fn ($record) => $record->appendSequence, $records));
    }

    public function test_restart_cycles_with_corrupted_tail_preserve_valid_reads_and_sequence_recovery(): void
    {
        $store = new FilesystemReplayArtifactStore($this->tmpDir);
        $store->write(FakeCapturedArtifact::apiDispatch());
        $store->write(FakeCapturedArtifact::eventRaw());

        file_put_contents($this->artifactFile, "{\"partial_tail\"\n", FILE_APPEND);

        $reopened = new FilesystemReplayArtifactStore($this->tmpDir);
        $records = $reopened->readFromCursor($reopened->openCursor(), 10);
        $this->assertSame([1, 2], array_map(static fn ($record) => $record->appendSequence, $records));

        $newId = $reopened->write(FakeCapturedArtifact::bgapiDispatch());
        $newRecord = $reopened->readById($newId);

        $this->assertNotNull($newRecord);
        $this->assertSame(3, $newRecord->appendSequence);

        $reopenedAgain = new FilesystemReplayArtifactStore($this->tmpDir);
        $allRecords = $reopenedAgain->readFromCursor($reopenedAgain->openCursor(), 10);
        $this->assertSame([1, 2, 3], array_map(static fn ($record) => $record->appendSequence, $allRecords));
    }
}
