<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Retention;

use Apntalk\EslReplay\Retention\PrunePolicy;
use PHPUnit\Framework\TestCase;

final class PrunePolicyTest extends TestCase
{
    public function test_constructs_with_valid_values(): void
    {
        $policy = new PrunePolicy(
            maxRecordAge: new \DateInterval('P7D'),
            maxStreamBytes: 1024,
            protectedRecordCount: 10,
        );

        $this->assertSame(1024, $policy->maxStreamBytes);
        $this->assertSame(10, $policy->protectedRecordCount);
    }

    public function test_rejects_negative_stream_byte_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrunePolicy(maxStreamBytes: -1);
    }

    public function test_rejects_negative_protected_record_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrunePolicy(protectedRecordCount: -1);
    }
}
