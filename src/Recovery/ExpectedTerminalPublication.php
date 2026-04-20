<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class ExpectedTerminalPublication
{
    public function __construct(
        public string $publicationId,
        public string $status,
        public ?string $operationId = null,
    ) {
        foreach ([
            'publicationId' => $this->publicationId,
            'status' => $this->status,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("ExpectedTerminalPublication: {$field} must not be empty.");
            }
        }

        if ($this->operationId !== null && trim($this->operationId) === '') {
            throw new \InvalidArgumentException(
                'ExpectedTerminalPublication: operationId must not be empty when provided.',
            );
        }
    }
}
