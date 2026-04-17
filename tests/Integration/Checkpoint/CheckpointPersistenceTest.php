<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Integration\Checkpoint;

use Apntalk\EslReplay\Checkpoint\ExecutionResumeState;
use Apntalk\EslReplay\Checkpoint\FilesystemCheckpointStore;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpoint;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointCriteria;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointReference;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointRepository;
use Apntalk\EslReplay\Checkpoint\ReplayCheckpointService;
use Apntalk\EslReplay\Config\CheckpointConfig;
use Apntalk\EslReplay\Cursor\ReplayReadCursor;
use Apntalk\EslReplay\Exceptions\CheckpointException;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for checkpoint save/load/delete and restart-safe resume.
 */
final class CheckpointPersistenceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/esl-checkpoint-test-' . bin2hex(random_bytes(8));
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

    // -------------------------------------------------------------------------
    // FilesystemCheckpointStore direct tests
    // -------------------------------------------------------------------------

    public function test_save_and_load_roundtrip(): void
    {
        $store  = new FilesystemCheckpointStore($this->tmpDir);
        $cursor = ReplayReadCursor::start()->advance(7);

        $checkpoint = new ReplayCheckpoint(
            key: 'processor-1',
            cursor: $cursor,
            savedAt: new \DateTimeImmutable('2024-06-01T12:00:00+00:00'),
        );
        $store->save($checkpoint);

        $loaded = $store->load('processor-1');

        $this->assertNotNull($loaded);
        $this->assertSame('processor-1', $loaded->key);
        $this->assertSame(7, $loaded->cursor->lastConsumedSequence);
    }

    public function test_load_returns_null_when_no_checkpoint_exists(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        $this->assertNull($store->load('nonexistent-key'));
    }

    public function test_exists_returns_false_before_save(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        $this->assertFalse($store->exists('my-key'));
    }

    public function test_exists_returns_true_after_save(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        $store->save(new ReplayCheckpoint(
            key: 'my-key',
            cursor: ReplayReadCursor::start(),
            savedAt: new \DateTimeImmutable(),
        ));
        $this->assertTrue($store->exists('my-key'));
    }

    public function test_delete_removes_checkpoint(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        $store->save(new ReplayCheckpoint(
            key: 'delete-me',
            cursor: ReplayReadCursor::start(),
            savedAt: new \DateTimeImmutable(),
        ));

        $store->delete('delete-me');
        $this->assertFalse($store->exists('delete-me'));
        $this->assertNull($store->load('delete-me'));
    }

    public function test_delete_is_no_op_for_nonexistent_key(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        // Should not throw
        $store->delete('ghost-key');
        $this->assertFalse($store->exists('ghost-key'));
    }

    public function test_overwrite_updates_cursor(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);

        $store->save(new ReplayCheckpoint('k', ReplayReadCursor::start()->advance(3), new \DateTimeImmutable()));
        $store->save(new ReplayCheckpoint('k', ReplayReadCursor::start()->advance(9), new \DateTimeImmutable()));

        $loaded = $store->load('k');
        $this->assertNotNull($loaded);
        $this->assertSame(9, $loaded->cursor->lastConsumedSequence);
    }

    public function test_metadata_survives_roundtrip(): void
    {
        $store      = new FilesystemCheckpointStore($this->tmpDir);
        $checkpoint = new ReplayCheckpoint(
            key: 'meta-key',
            cursor: ReplayReadCursor::start(),
            savedAt: new \DateTimeImmutable(),
            metadata: ['run' => 'batch-42', 'count' => 5],
        );
        $store->save($checkpoint);

        $loaded = $store->load('meta-key');
        $this->assertNotNull($loaded);
        $this->assertSame('batch-42', $loaded->metadata['run']);
        $this->assertSame(5, $loaded->metadata['count']);
    }

    public function test_byte_offset_hint_survives_roundtrip(): void
    {
        $store  = new FilesystemCheckpointStore($this->tmpDir);
        $cursor = new ReplayReadCursor(lastConsumedSequence: 10, byteOffsetHint: 4096);

        $store->save(new ReplayCheckpoint('offset-key', $cursor, new \DateTimeImmutable()));
        $loaded = $store->load('offset-key');

        $this->assertNotNull($loaded);
        $this->assertSame(4096, $loaded->cursor->byteOffsetHint);
    }

    public function test_make_factory_method_creates_store(): void
    {
        $config = new CheckpointConfig($this->tmpDir, 'test-key');
        $store  = FilesystemCheckpointStore::make($config);

        $store->save(new ReplayCheckpoint('test-key', ReplayReadCursor::start(), new \DateTimeImmutable()));
        $this->assertTrue($store->exists('test-key'));
    }

    public function test_find_returns_matching_checkpoints_by_operational_identity(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        $store->save(new ReplayCheckpoint(
            key: 'worker-a',
            cursor: ReplayReadCursor::start()->advance(10),
            savedAt: new \DateTimeImmutable('2024-06-01T12:00:00+00:00'),
            metadata: [
                'replay_session_id' => 'replay-a',
                'job_uuid' => 'job-a',
                'pbx_node_slug' => 'pbx-a',
                'worker_session_id' => 'worker-a',
            ],
        ));
        $store->save(new ReplayCheckpoint(
            key: 'worker-b',
            cursor: ReplayReadCursor::start()->advance(20),
            savedAt: new \DateTimeImmutable('2024-06-01T13:00:00+00:00'),
            metadata: [
                'replay_session_id' => 'replay-b',
                'job_uuid' => 'job-b',
                'pbx_node_slug' => 'pbx-b',
                'worker_session_id' => 'worker-b',
            ],
        ));

        $matches = $store->find(new ReplayCheckpointCriteria(
            replaySessionId: 'replay-a',
            pbxNodeSlug: 'pbx-a',
        ));

        $this->assertCount(1, $matches);
        $this->assertSame('worker-a', $matches[0]->key);
    }

    // -------------------------------------------------------------------------
    // ReplayCheckpointService tests
    // -------------------------------------------------------------------------

    public function test_service_save_and_load(): void
    {
        $store   = new FilesystemCheckpointStore($this->tmpDir);
        $service = new ReplayCheckpointService($store, 'svc-key');

        $cursor = ReplayReadCursor::start()->advance(15);
        $service->save($cursor);

        $checkpoint = $service->load();
        $this->assertNotNull($checkpoint);
        $this->assertSame(15, $checkpoint->cursor->lastConsumedSequence);
    }

    public function test_service_exists_and_clear(): void
    {
        $store   = new FilesystemCheckpointStore($this->tmpDir);
        $service = new ReplayCheckpointService($store, 'svc-key');

        $this->assertFalse($service->exists());
        $service->save(ReplayReadCursor::start());
        $this->assertTrue($service->exists());
        $service->clear();
        $this->assertFalse($service->exists());
    }

    public function test_service_load_returns_null_when_no_checkpoint(): void
    {
        $store   = new FilesystemCheckpointStore($this->tmpDir);
        $service = new ReplayCheckpointService($store, 'missing');
        $this->assertNull($service->load());
    }

    public function test_repository_save_load_and_find_use_first_class_reference(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        $repository = new ReplayCheckpointRepository($store);

        $saved = $repository->save(
            new ReplayCheckpointReference(
                key: 'worker-a',
                replaySessionId: 'replay-a',
                jobUuid: 'job-a',
                pbxNodeSlug: 'pbx-a',
                workerSessionId: 'worker-a',
                metadata: ['attempt' => 2],
            ),
            ReplayReadCursor::start()->advance(15),
        );

        $loaded = $repository->load('worker-a');
        $matches = $repository->find(new ReplayCheckpointCriteria(
            replaySessionId: 'replay-a',
            workerSessionId: 'worker-a',
        ));

        $this->assertSame('worker-a', $saved->key);
        $this->assertNotNull($loaded);
        $this->assertSame(15, $loaded->cursor->lastConsumedSequence);
        $this->assertSame('replay-a', $loaded->metadata['replay_session_id']);
        $this->assertCount(1, $matches);
        $this->assertSame('worker-a', $matches[0]->key);
    }

    // -------------------------------------------------------------------------
    // ExecutionResumeState tests
    // -------------------------------------------------------------------------

    public function test_resolve_returns_fresh_start_when_no_checkpoint(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        $state = ExecutionResumeState::resolve($store, 'fresh-key');

        $this->assertFalse($state->isResuming);
        $this->assertTrue($state->cursor->isAtStart());
        $this->assertNull($state->fromCheckpoint);
    }

    public function test_resolve_returns_resume_state_when_checkpoint_exists(): void
    {
        $store = new FilesystemCheckpointStore($this->tmpDir);
        $store->save(new ReplayCheckpoint(
            key: 'resume-key',
            cursor: ReplayReadCursor::start()->advance(20),
            savedAt: new \DateTimeImmutable(),
        ));

        $state = ExecutionResumeState::resolve($store, 'resume-key');

        $this->assertTrue($state->isResuming);
        $this->assertSame(20, $state->cursor->lastConsumedSequence);
        $this->assertNotNull($state->fromCheckpoint);
    }

    public function test_fresh_always_returns_start_cursor(): void
    {
        $state = ExecutionResumeState::fresh();

        $this->assertFalse($state->isResuming);
        $this->assertTrue($state->cursor->isAtStart());
        $this->assertNull($state->fromCheckpoint);
    }

    // -------------------------------------------------------------------------
    // Restart-safe resume scenario
    // -------------------------------------------------------------------------

    public function test_restart_safe_resume_from_saved_cursor(): void
    {
        $store   = new FilesystemCheckpointStore($this->tmpDir);
        $service = new ReplayCheckpointService($store, 'restart-scenario');

        // Simulate: process 5 records, save checkpoint
        $cursorAfterFive = ReplayReadCursor::start()
            ->advance(1)->advance(2)->advance(3)->advance(4)->advance(5);
        $service->save($cursorAfterFive);

        // Simulate process restart: resolve starting position
        $storeAfterRestart   = new FilesystemCheckpointStore($this->tmpDir);
        $serviceAfterRestart = new ReplayCheckpointService($storeAfterRestart, 'restart-scenario');
        $checkpoint          = $serviceAfterRestart->load();

        $this->assertNotNull($checkpoint);
        $this->assertSame(5, $checkpoint->cursor->lastConsumedSequence);
        $this->assertFalse($checkpoint->cursor->isAtStart());
    }
}
