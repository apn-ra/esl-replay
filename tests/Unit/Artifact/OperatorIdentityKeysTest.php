<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Tests\Unit\Artifact;

use Apntalk\EslReplay\Artifact\OperatorIdentityKeys;
use PHPUnit\Framework\TestCase;

final class OperatorIdentityKeysTest extends TestCase
{
    public function test_published_operator_identity_keys_match_stored_contract(): void
    {
        $constants = (new \ReflectionClass(OperatorIdentityKeys::class))->getConstants();

        $this->assertSame('replay_session_id', $constants['REPLAY_SESSION_ID'] ?? null);
        $this->assertSame('pbx_node_slug', $constants['PBX_NODE_SLUG'] ?? null);
        $this->assertSame('worker_session_id', $constants['WORKER_SESSION_ID'] ?? null);
    }
}
