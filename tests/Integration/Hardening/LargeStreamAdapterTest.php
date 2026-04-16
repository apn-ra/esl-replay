<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Hardening;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Adapter\Sqlite\SqliteReplayArtifactStore;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LargeStreamAdapterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-large-stream-' . bin2hex(random_bytes(8));
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
    public static function adapterProvider(): iterable
    {
        yield 'filesystem' => ['adapter' => 'filesystem'];
        yield 'sqlite' => ['adapter' => 'sqlite'];
    }

    #[DataProvider('adapterProvider')]
    public function test_large_stream_reads_remain_ordered_and_complete(string $adapter): void
    {
        $store = $this->makeStore($adapter);
        $total = 1000;

        for ($i = 0; $i < $total; $i++) {
            $store->write(FakeCapturedArtifact::apiDispatch("sess-{$i}"));
        }

        $cursor = $store->openCursor();
        $sequences = [];

        while (true) {
            $batch = $store->readFromCursor($cursor, 137);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $record) {
                $sequences[] = $record->appendSequence;
                $cursor = $cursor->advance($record->appendSequence);
            }
        }

        $this->assertCount($total, $sequences);
        $this->assertSame(range(1, $total), $sequences);
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
