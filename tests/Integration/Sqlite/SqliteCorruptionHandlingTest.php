<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Sqlite;

use Apntalk\EslReplay\Adapter\Sqlite\SqliteReplayArtifactStore;
use Apntalk\EslReplay\Exceptions\SerializationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteCorruptionHandlingTest extends TestCase
{
    private string $tmpDir;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-sqlite-corrupt-' . bin2hex(random_bytes(8));
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

    public function test_corrupt_json_columns_fail_clearly_on_read(): void
    {
        new SqliteReplayArtifactStore($this->dbPath);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec(<<<'SQL'
            INSERT INTO replay_records (
                id, artifact_version, artifact_name, capture_timestamp, stored_at,
                append_sequence, connection_generation, session_id, job_uuid, event_name,
                capture_path, correlation_ids, runtime_flags, payload, checksum, tags
            ) VALUES (
                'corrupt-id', '1', 'api.dispatch', '2024-01-01T00:00:00.000000+00:00', '2024-01-01T00:00:00.000000+00:00',
                1, NULL, NULL, NULL, NULL,
                NULL, '{}', '{}', '{invalid-json', 'checksum', '{}'
            );
            SQL
        );

        $this->expectException(SerializationException::class);

        (new SqliteReplayArtifactStore($this->dbPath))->readById(
            new \Apntalk\EslReplay\Storage\ReplayRecordId('corrupt-id'),
        );
    }
}
