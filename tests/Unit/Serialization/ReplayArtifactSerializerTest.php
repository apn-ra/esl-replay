<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Serialization;

use Apntalk\EslReplay\Exceptions\SerializationException;
use Apntalk\EslReplay\Serialization\ArtifactChecksum;
use Apntalk\EslReplay\Serialization\ReplayArtifactSerializer;
use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use Apntalk\EslReplay\Tests\Fixtures\FakeCapturedArtifact;
use PHPUnit\Framework\TestCase;

final class ReplayArtifactSerializerTest extends TestCase
{
    private ReplayArtifactSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new ReplayArtifactSerializer();
    }

    /** @param array<string, mixed> $overrides */
    private function makeRecord(array $overrides = []): StoredReplayRecord
    {
        $artifact = FakeCapturedArtifact::apiDispatch();
        return new StoredReplayRecord(
            id: $overrides['id'] ?? new ReplayRecordId('test-uuid-0001'),
            artifactVersion: $artifact->getArtifactVersion(),
            artifactName: $artifact->getArtifactName(),
            captureTimestamp: $artifact->getCaptureTimestamp(),
            storedAt: new \DateTimeImmutable('2024-01-15T10:00:01.000000+00:00'),
            appendSequence: $overrides['appendSequence'] ?? 1,
            connectionGeneration: null,
            sessionId: 'sess-001',
            jobUuid: null,
            eventName: null,
            capturePath: null,
            correlationIds: [],
            runtimeFlags: [],
            payload: $artifact->getPayload(),
            checksum: ArtifactChecksum::compute($artifact),
            tags: [],
        );
    }

    public function test_serialize_produces_single_line_json(): void
    {
        $record = $this->makeRecord();
        $json   = $this->serializer->serialize($record);

        $this->assertStringNotContainsString("\n", $json);
        $this->assertJson($json);
    }

    public function test_serialize_includes_schema_version(): void
    {
        $json = $this->serializer->serialize($this->makeRecord());
        $data = json_decode($json, true);
        $this->assertSame(ReplayArtifactSerializer::SCHEMA_VERSION, $data['schema_version']);
    }

    public function test_serialize_deserialize_roundtrip_preserves_all_fields(): void
    {
        $original    = $this->makeRecord();
        $json        = $this->serializer->serialize($original);
        $reconstituted = $this->serializer->deserialize($json);

        $this->assertSame($original->id->value, $reconstituted->id->value);
        $this->assertSame($original->artifactVersion, $reconstituted->artifactVersion);
        $this->assertSame($original->artifactName, $reconstituted->artifactName);
        $this->assertSame($original->appendSequence, $reconstituted->appendSequence);
        $this->assertSame($original->sessionId, $reconstituted->sessionId);
        $this->assertSame($original->checksum, $reconstituted->checksum);
        $this->assertSame($original->payload, $reconstituted->payload);
        $this->assertSame(
            $original->captureTimestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
            $reconstituted->captureTimestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }

    public function test_serialization_is_deterministic(): void
    {
        $record = $this->makeRecord();
        $a = $this->serializer->serialize($record);
        $b = $this->serializer->serialize($record);
        $this->assertSame($a, $b);
    }

    public function test_deserialize_throws_on_invalid_json(): void
    {
        $this->expectException(SerializationException::class);
        $this->serializer->deserialize('{not valid json}');
    }

    public function test_deserialize_throws_on_wrong_schema_version(): void
    {
        $record = $this->makeRecord();
        $json   = $this->serializer->serialize($record);
        /** @var array<string, mixed> $data */
        $data   = json_decode($json, true);
        $data['schema_version'] = 999;
        $badJson = json_encode($data, JSON_THROW_ON_ERROR);

        $this->expectException(SerializationException::class);
        $this->serializer->deserialize($badJson);
    }

    public function test_deserialize_throws_when_required_field_missing(): void
    {
        $record = $this->makeRecord();
        $json   = $this->serializer->serialize($record);
        /** @var array<string, mixed> $data */
        $data   = json_decode($json, true);
        unset($data['artifact_name']);
        $badJson = json_encode($data, JSON_THROW_ON_ERROR);

        $this->expectException(SerializationException::class);
        $this->serializer->deserialize($badJson);
    }

    public function test_deserialize_throws_on_non_object_json(): void
    {
        $this->expectException(SerializationException::class);
        $this->serializer->deserialize('"just a string"');
    }

    public function test_null_optional_fields_survive_roundtrip(): void
    {
        $record        = $this->makeRecord();
        $json          = $this->serializer->serialize($record);
        $reconstituted = $this->serializer->deserialize($json);

        $this->assertNull($reconstituted->jobUuid);
        $this->assertNull($reconstituted->eventName);
        $this->assertNull($reconstituted->capturePath);
        $this->assertNull($reconstituted->connectionGeneration);
    }

    public function test_tags_survive_roundtrip(): void
    {
        $artifact = FakeCapturedArtifact::apiDispatch();
        $record = new StoredReplayRecord(
            id: new ReplayRecordId('tag-test'),
            artifactVersion: $artifact->getArtifactVersion(),
            artifactName: $artifact->getArtifactName(),
            captureTimestamp: $artifact->getCaptureTimestamp(),
            storedAt: new \DateTimeImmutable('2024-01-15T10:00:01.000000+00:00'),
            appendSequence: 1,
            connectionGeneration: null,
            sessionId: null,
            jobUuid: null,
            eventName: null,
            capturePath: null,
            correlationIds: [],
            runtimeFlags: [],
            payload: $artifact->getPayload(),
            checksum: ArtifactChecksum::compute($artifact),
            tags: ['env' => 'test', 'source' => 'unit'],
        );

        $reconstituted = $this->serializer->deserialize($this->serializer->serialize($record));
        $this->assertSame(['env' => 'test', 'source' => 'unit'], $reconstituted->tags);
    }

    public function test_deserialize_throws_when_correlation_ids_is_not_an_object(): void
    {
        $this->assertWrongTypeObjectFieldFails('correlation_ids');
    }

    public function test_deserialize_throws_when_runtime_flags_is_not_an_object(): void
    {
        $this->assertWrongTypeObjectFieldFails('runtime_flags');
    }

    public function test_deserialize_throws_when_payload_is_not_an_object(): void
    {
        $this->assertWrongTypeObjectFieldFails('payload');
    }

    public function test_deserialize_throws_when_tags_is_not_an_object(): void
    {
        $this->assertWrongTypeObjectFieldFails('tags');
    }

    private function assertWrongTypeObjectFieldFails(string $field): void
    {
        $json = $this->serializer->serialize($this->makeRecord());
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $data[$field] = 'wrong-type';

        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage("Missing or non-object field: {$field}");

        $this->serializer->deserialize(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
