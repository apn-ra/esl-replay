<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Execution;

use Apntalk\EslReplay\Execution\InjectionResult;
use PHPUnit\Framework\TestCase;

final class InjectionResultTest extends TestCase
{
    public function test_constructs_with_valid_action(): void
    {
        $result = new InjectionResult('injected', ['ok' => true]);
        $this->assertSame('injected', $result->action);
    }

    public function test_rejects_empty_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InjectionResult('   ');
    }
}
