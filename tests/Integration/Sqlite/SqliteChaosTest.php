<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Sqlite;

use Apntalk\EslReplay\Adapter\Sqlite\SqliteReplayArtifactStore;
use Apntalk\EslReplay\Exceptions\SerializationException;
use Apntalk\EslReplay\Read\ReplayReadCriteria;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

final class SqliteChaosTest extends TestCase
{
    private string $tmpDir;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-sqlite-chaos-' . bin2hex(random_bytes(8));
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

    public function test_corrupt_row_after_valid_rows_fails_clearly_and_repeated_reopen_keeps_failure_explicit(): void
    {
        $store = new SqliteReplayArtifactStore($this->dbPath);
        $store->write(FakeCapturedArtifact::apiDispatch());

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec(<<<'SQL'
            INSERT INTO replay_records (
                id, artifact_version, artifact_name, capture_timestamp, stored_at,
                append_sequence, connection_generation, session_id, job_uuid, event_name,
                capture_path, correlation_ids, runtime_flags, payload, checksum, tags
            ) VALUES (
                'corrupt-middle', '1', 'event.raw', '2024-01-01T00:00:00.000000+00:00', '2024-01-01T00:00:00.000000+00:00',
                2, NULL, NULL, NULL, NULL,
                NULL, '{}', '{}', '{bad-json', 'checksum', '{}'
            );
            SQL
        );

        foreach ([new SqliteReplayArtifactStore($this->dbPath), new SqliteReplayArtifactStore($this->dbPath)] as $reopened) {
            try {
                $reopened->readFromCursor($reopened->openCursor(), 10);
                $this->fail('Expected SerializationException was not thrown.');
            } catch (SerializationException $e) {
                $this->assertStringContainsString('failed to reconstruct stored replay record', $e->getMessage());
            }
        }
    }

    public function test_chunked_bounded_reads_over_large_stream_remain_ordered_under_sqlite(): void
    {
        $store = new SqliteReplayArtifactStore($this->dbPath);
        for ($i = 0; $i < 200; $i++) {
            $store->write(new FakeCapturedArtifact(
                artifactName: $i % 2 === 0 ? 'api.dispatch' : 'event.raw',
                sessionId: $i % 2 === 0 ? 'sess-even' : 'sess-odd',
            ));
        }

        $criteria = new ReplayReadCriteria(artifactName: 'api.dispatch', sessionId: 'sess-even');
        $cursor = $store->openCursor();
        $sequences = [];

        while (true) {
            $batch = $store->readFromCursor($cursor, 17, $criteria);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $record) {
                $sequences[] = $record->appendSequence;
                $cursor = $cursor->advance($record->appendSequence);
            }
        }

        $this->assertSame(range(1, 199, 2), $sequences);
    }
}
