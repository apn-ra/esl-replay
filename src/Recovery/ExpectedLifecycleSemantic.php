<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class ExpectedLifecycleSemantic
{
    public function __construct(
        public string $semantic,
        public string $posture,
        public ?string $operationId = null,
    ) {
        foreach ([
            'semantic' => $this->semantic,
            'posture' => $this->posture,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("ExpectedLifecycleSemantic: {$field} must not be empty.");
            }
        }

        if ($this->operationId !== null && trim($this->operationId) === '') {
            throw new \InvalidArgumentException(
                'ExpectedLifecycleSemantic: operationId must not be empty when provided.',
            );
        }
    }
}
