<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Execution;

use Apntalk\EslReplay\Execution\ReplayHandlerResult;
use PHPUnit\Framework\TestCase;

final class ReplayHandlerResultTest extends TestCase
{
    public function test_constructs_with_valid_action_and_metadata(): void
    {
        $result = new ReplayHandlerResult('handled', ['a' => 1]);

        $this->assertSame('handled', $result->action);
        $this->assertSame(['a' => 1], $result->metadata);
    }

    public function test_rejects_empty_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayHandlerResult('   ');
    }
}
