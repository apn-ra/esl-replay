<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Recovery;

use Apntalk\EslReplay\Adapter\Filesystem\FilesystemReplayArtifactStore;
use Apntalk\EslReplay\Adapter\Sqlite\SqliteReplayArtifactStore;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Recovery\ExpectedOperationLifecycle;
use Apntalk\EslReplay\Recovery\ExpectedTerminalPublication;
use Apntalk\EslReplay\Recovery\RecoveryEvidenceEngine;
use Apntalk\EslReplay\Recovery\RecoveryMetadataKeys;
use Apntalk\EslReplay\Recovery\ReconstructionWindow;
use Apntalk\EslReplay\Recovery\ScenarioExpectation;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecoveryEvidenceEngineIntegrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-recovery-evidence-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $found = glob($this->tmpDir . '/*');
        $files = $found !== false ? $found : [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir . '/fs');
        @rmdir($this->tmpDir . '/sqlite');
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
    public function test_reconstruction_and_comparison_are_deterministic_across_adapters(string $adapter): void
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
                RecoveryMetadataKeys::RETRY_POSTURE => 'stable',
                RecoveryMetadataKeys::DRAIN_POSTURE => 'drained',
                RecoveryMetadataKeys::RECONSTRUCTION_POSTURE => 'bounded',
            ],
            runtimeOperationSnapshot: [
                RecoveryMetadataKeys::OPERATION_ID => 'op-1',
                RecoveryMetadataKeys::OPERATION_KIND => 'bgapi',
                RecoveryMetadataKeys::OPERATION_STATE => 'accepted',
                RecoveryMetadataKeys::BGAPI_JOB_UUID => 'job-1',
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
                RecoveryMetadataKeys::OPERATION_KIND => 'bgapi',
                RecoveryMetadataKeys::OPERATION_STATE => 'completed',
                RecoveryMetadataKeys::BGAPI_JOB_UUID => 'job-1',
            ],
            runtimeTerminalPublicationSnapshot: [
                RecoveryMetadataKeys::TERMINAL_PUBLICATION_ID => 'pub-1',
                RecoveryMetadataKeys::TERMINAL_PUBLICATION_STATUS => 'published',
            ],
            correlationIds: ['replay_session_id' => 'replay-1'],
        ));

        $engine = RecoveryEvidenceEngine::make($store);
        $bundle = $engine->reconstruct(new ReconstructionWindow($store->openCursor()));
        $comparison = $engine->compareScenario($bundle, new ScenarioExpectation(
            scenarioName: 'sc19-style',
            expectedRecoveryGenerations: ['gen-1'],
            expectedReplayContinuityPosture: 'continuous',
            expectedRetryPosture: 'stable',
            expectedDrainPosture: 'drained',
            expectedReconstructionPosture: 'bounded',
            expectedOperations: [
                new ExpectedOperationLifecycle('op-1', ['accepted', 'completed'], 'completed'),
            ],
            expectedTerminalPublications: [
                new ExpectedTerminalPublication('pub-1', 'published', 'op-1'),
            ],
        ));

        $this->assertSame('complete', $bundle->manifest->verdict->posture);
        $this->assertTrue($comparison->passed);
        $this->assertSame($bundle->manifest->bundleId, $engine->reconstruct(new ReconstructionWindow($store->openCursor()))->manifest->bundleId);
        $this->assertSame($engine->exportBundle($bundle), $engine->exportBundle($bundle));
        $this->assertSame($engine->exportComparison($comparison), $engine->exportComparison($comparison));
    }

    private function makeStore(string $adapter): ReplayArtifactStoreInterface
    {
        return match ($adapter) {
            'filesystem' => new FilesystemReplayArtifactStore($this->tmpDir . '/fs'),
            'sqlite' => new SqliteReplayArtifactStore($this->tmpDir . '/sqlite/replay.sqlite'),
            default => throw new \InvalidArgumentException("Unknown adapter {$adapter}."),
        };
    }
}
