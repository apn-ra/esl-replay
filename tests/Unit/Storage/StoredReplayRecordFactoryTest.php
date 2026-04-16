<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Storage;

use Apntalk\EslReplay\Storage\StoredReplayRecordFactory;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

final class StoredReplayRecordFactoryTest extends TestCase
{
    public function test_first_record_has_sequence_one_on_fresh_stream(): void
    {
        $factory = new StoredReplayRecordFactory(0);
        $record  = $factory->fromEnvelope(FakeCapturedArtifact::apiDispatch());
        $this->assertSame(1, $record->appendSequence);
    }

    public function test_sequence_increments_monotonically(): void
    {
        $factory  = new StoredReplayRecordFactory(0);
        $artifact = FakeCapturedArtifact::apiDispatch();

        $r1 = $factory->fromEnvelope($artifact);
        $r2 = $factory->fromEnvelope($artifact);
        $r3 = $factory->fromEnvelope($artifact);

        $this->assertSame(1, $r1->appendSequence);
        $this->assertSame(2, $r2->appendSequence);
        $this->assertSame(3, $r3->appendSequence);
    }

    public function test_factory_continues_from_initial_sequence(): void
    {
        $factory = new StoredReplayRecordFactory(10);
        $record  = $factory->fromEnvelope(FakeCapturedArtifact::apiDispatch());
        $this->assertSame(11, $record->appendSequence);
    }

    public function test_rejects_negative_initial_sequence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StoredReplayRecordFactory(-1);
    }

    public function test_each_record_gets_a_unique_id(): void
    {
        $factory  = new StoredReplayRecordFactory(0);
        $artifact = FakeCapturedArtifact::apiDispatch();

        $r1 = $factory->fromEnvelope($artifact);
        $r2 = $factory->fromEnvelope($artifact);

        $this->assertFalse($r1->id->equals($r2->id));
    }

    public function test_artifact_version_is_preserved_exactly(): void
    {
        $artifact = new FakeCapturedArtifact(artifactVersion: '2');
        $factory  = new StoredReplayRecordFactory(0);
        $record   = $factory->fromEnvelope($artifact);
        $this->assertSame('2', $record->artifactVersion);
    }

    public function test_artifact_name_is_preserved_exactly(): void
    {
        $artifact = FakeCapturedArtifact::eventRaw('CHANNEL_HANGUP');
        $factory  = new StoredReplayRecordFactory(0);
        $record   = $factory->fromEnvelope($artifact);
        $this->assertSame('event.raw', $record->artifactName);
    }

    public function test_payload_is_preserved_without_mutation(): void
    {
        $payload  = ['cmd' => 'originate', 'nested' => ['a' => 1]];
        $artifact = new FakeCapturedArtifact(payload: $payload);
        $factory  = new StoredReplayRecordFactory(0);
        $record   = $factory->fromEnvelope($artifact);
        $this->assertSame($payload, $record->payload);
    }

    public function test_current_sequence_reflects_writes(): void
    {
        $factory  = new StoredReplayRecordFactory(0);
        $artifact = FakeCapturedArtifact::apiDispatch();

        $this->assertSame(0, $factory->currentSequence());
        $factory->fromEnvelope($artifact);
        $this->assertSame(1, $factory->currentSequence());
        $factory->fromEnvelope($artifact);
        $this->assertSame(2, $factory->currentSequence());
    }

    public function test_session_id_is_transferred_from_envelope(): void
    {
        $artifact = FakeCapturedArtifact::apiDispatch(sessionId: 'sess-xyz');
        $factory  = new StoredReplayRecordFactory(0);
        $record   = $factory->fromEnvelope($artifact);
        $this->assertSame('sess-xyz', $record->sessionId);
    }

    public function test_job_uuid_is_transferred_from_envelope(): void
    {
        $artifact = FakeCapturedArtifact::bgapiDispatch(jobUuid: 'job-abc');
        $factory  = new StoredReplayRecordFactory(0);
        $record   = $factory->fromEnvelope($artifact);
        $this->assertSame('job-abc', $record->jobUuid);
    }

    public function test_checksum_is_non_empty(): void
    {
        $factory = new StoredReplayRecordFactory(0);
        $record  = $factory->fromEnvelope(FakeCapturedArtifact::apiDispatch());
        $this->assertNotEmpty($record->checksum);
    }

    public function test_stored_at_is_utc(): void
    {
        $factory = new StoredReplayRecordFactory(0);
        $record  = $factory->fromEnvelope(FakeCapturedArtifact::apiDispatch());
        $this->assertSame('UTC', $record->storedAt->getTimezone()->getName());
    }
}
