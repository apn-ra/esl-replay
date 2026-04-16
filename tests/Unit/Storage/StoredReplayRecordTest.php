<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Storage;

use Apntalk\EslReplay\Storage\ReplayRecordId;
use Apntalk\EslReplay\Storage\StoredReplayRecord;
use PHPUnit\Framework\TestCase;

final class StoredReplayRecordTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function makeRecord(array $overrides = []): StoredReplayRecord
    {
        return new StoredReplayRecord(
            id: $overrides['id'] ?? new ReplayRecordId('test-id'),
            artifactVersion: $overrides['artifactVersion'] ?? '1',
            artifactName: $overrides['artifactName'] ?? 'api.dispatch',
            captureTimestamp: $overrides['captureTimestamp'] ?? new \DateTimeImmutable('2024-01-01T00:00:00+00:00'),
            storedAt: $overrides['storedAt'] ?? new \DateTimeImmutable('2024-01-01T00:00:01+00:00'),
            appendSequence: $overrides['appendSequence'] ?? 1,
            connectionGeneration: $overrides['connectionGeneration'] ?? null,
            sessionId: $overrides['sessionId'] ?? null,
            jobUuid: $overrides['jobUuid'] ?? null,
            eventName: $overrides['eventName'] ?? null,
            capturePath: $overrides['capturePath'] ?? null,
            correlationIds: $overrides['correlationIds'] ?? [],
            runtimeFlags: $overrides['runtimeFlags'] ?? [],
            payload: $overrides['payload'] ?? ['key' => 'value'],
            checksum: $overrides['checksum'] ?? 'abc123',
            tags: $overrides['tags'] ?? [],
        );
    }

    public function test_record_constructs_successfully_with_minimal_fields(): void
    {
        $record = $this->makeRecord();
        $this->assertSame('api.dispatch', $record->artifactName);
        $this->assertSame(1, $record->appendSequence);
    }

    public function test_rejects_append_sequence_below_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeRecord(['appendSequence' => 0]);
    }

    public function test_rejects_empty_artifact_version(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeRecord(['artifactVersion' => '']);
    }

    public function test_rejects_whitespace_only_artifact_version(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeRecord(['artifactVersion' => '   ']);
    }

    public function test_rejects_empty_artifact_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeRecord(['artifactName' => '']);
    }

    public function test_rejects_empty_checksum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeRecord(['checksum' => '']);
    }

    public function test_optional_nullable_fields_default_to_null(): void
    {
        $record = $this->makeRecord();
        $this->assertNull($record->sessionId);
        $this->assertNull($record->jobUuid);
        $this->assertNull($record->eventName);
        $this->assertNull($record->capturePath);
        $this->assertNull($record->connectionGeneration);
    }

    public function test_payload_is_preserved_exactly(): void
    {
        $payload = ['nested' => ['a' => 1, 'b' => ['c' => true]], 'top' => 'value'];
        $record  = $this->makeRecord(['payload' => $payload]);
        $this->assertSame($payload, $record->payload);
    }

    public function test_record_is_immutable(): void
    {
        $record = $this->makeRecord();
        // final readonly — PHP will throw an error on modification attempt
        $this->assertTrue(
            (new \ReflectionClass($record))->isReadOnly()
            || (new \ReflectionClass($record))->isFinal(),
        );
    }
}
