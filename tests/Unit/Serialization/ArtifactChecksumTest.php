<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Serialization;

use Apntalk\EslReplay\Serialization\ArtifactChecksum;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

final class ArtifactChecksumTest extends TestCase
{
    public function test_compute_returns_non_empty_hex_string(): void
    {
        $artifact = FakeCapturedArtifact::apiDispatch();
        $checksum = ArtifactChecksum::compute($artifact);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $checksum);
    }

    public function test_compute_is_deterministic_for_same_input(): void
    {
        $artifact = FakeCapturedArtifact::apiDispatch();
        $a = ArtifactChecksum::compute($artifact);
        $b = ArtifactChecksum::compute($artifact);
        $this->assertSame($a, $b);
    }

    public function test_different_artifact_names_produce_different_checksums(): void
    {
        $dispatch = FakeCapturedArtifact::apiDispatch();
        $event    = FakeCapturedArtifact::eventRaw();
        $this->assertNotSame(
            ArtifactChecksum::compute($dispatch),
            ArtifactChecksum::compute($event),
        );
    }

    public function test_different_payloads_produce_different_checksums(): void
    {
        $a = new FakeCapturedArtifact(payload: ['cmd' => 'originate']);
        $b = new FakeCapturedArtifact(payload: ['cmd' => 'hangup']);
        $this->assertNotSame(
            ArtifactChecksum::compute($a),
            ArtifactChecksum::compute($b),
        );
    }

    public function test_payload_key_order_does_not_affect_checksum(): void
    {
        $a = new FakeCapturedArtifact(payload: ['a' => 1, 'b' => 2]);
        $b = new FakeCapturedArtifact(payload: ['b' => 2, 'a' => 1]);
        $this->assertSame(
            ArtifactChecksum::compute($a),
            ArtifactChecksum::compute($b),
        );
    }

    public function test_verify_returns_true_for_valid_record(): void
    {
        $artifact = FakeCapturedArtifact::apiDispatch();
        $checksum = ArtifactChecksum::compute($artifact);

        $record = new StoredReplayRecord(
            id: ReplayRecordId::generate(),
            artifactVersion: $artifact->getArtifactVersion(),
            artifactName: $artifact->getArtifactName(),
            captureTimestamp: $artifact->getCaptureTimestamp(),
            storedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            appendSequence: 1,
            connectionGeneration: null,
            sessionId: null,
            jobUuid: null,
            eventName: null,
            capturePath: null,
            correlationIds: [],
            runtimeFlags: [],
            payload: $artifact->getPayload(),
            checksum: $checksum,
            tags: [],
        );

        $this->assertTrue(ArtifactChecksum::verify($record));
    }

    public function test_verify_returns_false_when_payload_is_tampered(): void
    {
        $artifact = FakeCapturedArtifact::apiDispatch();
        $checksum = ArtifactChecksum::compute($artifact);

        $tamperedRecord = new StoredReplayRecord(
            id: ReplayRecordId::generate(),
            artifactVersion: $artifact->getArtifactVersion(),
            artifactName: $artifact->getArtifactName(),
            captureTimestamp: $artifact->getCaptureTimestamp(),
            storedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            appendSequence: 1,
            connectionGeneration: null,
            sessionId: null,
            jobUuid: null,
            eventName: null,
            capturePath: null,
            correlationIds: [],
            runtimeFlags: [],
            payload: ['tampered' => true], // payload changed after checksum
            checksum: $checksum,
            tags: [],
        );

        $this->assertFalse(ArtifactChecksum::verify($tamperedRecord));
    }

    public function test_verify_returns_false_for_corrupted_checksum(): void
    {
        $artifact = FakeCapturedArtifact::apiDispatch();

        $record = new StoredReplayRecord(
            id: ReplayRecordId::generate(),
            artifactVersion: $artifact->getArtifactVersion(),
            artifactName: $artifact->getArtifactName(),
            captureTimestamp: $artifact->getCaptureTimestamp(),
            storedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            appendSequence: 1,
            connectionGeneration: null,
            sessionId: null,
            jobUuid: null,
            eventName: null,
            capturePath: null,
            correlationIds: [],
            runtimeFlags: [],
            payload: $artifact->getPayload(),
            checksum: 'not-a-valid-checksum-' . str_repeat('0', 43),
            tags: [],
        );

        $this->assertFalse(ArtifactChecksum::verify($record));
    }
}
