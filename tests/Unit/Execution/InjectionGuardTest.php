<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Execution;

use Apntalk\EslReplay\Execution\InjectionGuard;
use PHPUnit\Framework\TestCase;

final class InjectionGuardTest extends TestCase
{
    public function test_allows_configured_artifact_names(): void
    {
        $guard = new InjectionGuard(['api.dispatch']);

        $this->assertTrue($guard->allows('api.dispatch'));
        $this->assertFalse($guard->allows('event.raw'));
    }

    public function test_rejects_empty_allowlist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InjectionGuard([]);
    }
}
