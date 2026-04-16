<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Execution;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Adapter\Sqlite\SqliteReplayArtifactStore;
use Apntalk\EslReplay\Config\ExecutionConfig;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Execution\OfflineReplayExecutor;
use Apntalk\EslReplay\Execution\ReplayHandlerRegistry;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use Apntalk\EslReplay\Tests\Fixtures\FakeReplayRecordHandler;
use PHPUnit\Framework\TestCase;

final class OfflineReplayDeterminismTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-determinism-' . bin2hex(random_bytes(8));
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

    public function test_handler_driven_replay_outcomes_match_across_filesystem_and_sqlite(): void
    {
        $filesystem = new FilesystemReplayArtifactStore($this->tmpDir . '/fs');
        $sqlite = new SqliteReplayArtifactStore($this->tmpDir . '/sqlite/replay.sqlite');

        foreach ([
            FakeCapturedArtifact::apiDispatch(),
            FakeCapturedArtifact::eventRaw(),
            FakeCapturedArtifact::apiDispatch('sess-002'),
        ] as $artifact) {
            $filesystem->write($artifact);
            $sqlite->write($artifact);
        }

        $filesystemOutcomes = $this->normalizeOutcomes($this->executeWithHandlers($filesystem));
        $sqliteOutcomes = $this->normalizeOutcomes($this->executeWithHandlers($sqlite));

        $this->assertSame($filesystemOutcomes, $sqliteOutcomes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function executeWithHandlers(ReplayArtifactStoreInterface $store): array
    {
        $executor = OfflineReplayExecutor::make(
            new ExecutionConfig(dryRun: false),
            $store,
            new ReplayHandlerRegistry(['api.dispatch' => new FakeReplayRecordHandler()]),
        );

        return $executor->execute($executor->plan($store->openCursor()))->outcomes;
    }

    /**
     * @param list<array<string, mixed>> $outcomes
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeOutcomes(array $outcomes): array
    {
        return array_map(
            static function (array $outcome): array {
                unset($outcome['record_id']);
                return $outcome;
            },
            $outcomes,
        );
    }
}
