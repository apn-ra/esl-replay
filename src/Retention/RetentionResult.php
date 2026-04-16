<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Retention;

final readonly class RetentionResult
{
    public function __construct(
        public readonly RetentionPlan $plan,
        public readonly bool $changed,
        public readonly \DateTimeImmutable $prunedAt,
    ) {}
}
