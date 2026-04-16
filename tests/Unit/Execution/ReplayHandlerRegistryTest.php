<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Execution;

use Apntalk\EslReplay\Execution\ReplayHandlerRegistry;
use Apntalk\EslReplay\Tests\Fixtures\FakeReplayRecordHandler;
use PHPUnit\Framework\TestCase;

final class ReplayHandlerRegistryTest extends TestCase
{
    public function test_resolves_handler_by_exact_artifact_name(): void
    {
        $handler  = new FakeReplayRecordHandler();
        $registry = new ReplayHandlerRegistry(['api.dispatch' => $handler]);

        $this->assertSame($handler, $registry->forArtifact('api.dispatch'));
        $this->assertNull($registry->forArtifact('event.raw'));
    }

    public function test_rejects_empty_artifact_name_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReplayHandlerRegistry(['   ' => new FakeReplayRecordHandler()]);
    }
}
