<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Storage;

use Apntalk\EslReplay\Storage\ReplayRecordId;
use PHPUnit\Framework\TestCase;

final class ReplayRecordIdTest extends TestCase
{
    public function test_construct_with_valid_value(): void
    {
        $id = new ReplayRecordId('abc-123');
        $this->assertSame('abc-123', $id->value);
    }

    public function test_construct_rejects_empty_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayRecordId('   ');
    }

    public function test_generate_produces_uuid_v4_format(): void
    {
        $id = ReplayRecordId::generate();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id->value,
        );
    }

    public function test_generate_produces_unique_ids(): void
    {
        $a = ReplayRecordId::generate();
        $b = ReplayRecordId::generate();
        $this->assertFalse($a->equals($b));
    }

    public function test_equals_returns_true_for_same_value(): void
    {
        $a = new ReplayRecordId('same-id');
        $b = new ReplayRecordId('same-id');
        $this->assertTrue($a->equals($b));
    }

    public function test_equals_returns_false_for_different_values(): void
    {
        $a = new ReplayRecordId('id-a');
        $b = new ReplayRecordId('id-b');
        $this->assertFalse($a->equals($b));
    }

    public function test_to_string_returns_value(): void
    {
        $id = new ReplayRecordId('my-id');
        $this->assertSame('my-id', (string) $id);
    }
}
