<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class OperationRecoveryRecord
{
    /**
     * @param list<array<string, mixed>> $observedStates
     * @param list<ReconstructionIssue>  $issues
     */
    public function __construct(
        public string $operationId,
        public ?string $operationKind,
        public ?string $bgapiJobUuid,
        public ?int $acceptedAppendSequence,
        public array $observedStates,
        public ?string $finalState,
        public ?string $retryPosture,
        public ?string $drainPosture,
        public array $issues,
    ) {
        if (trim($this->operationId) === '') {
            throw new \InvalidArgumentException('OperationRecoveryRecord: operationId must not be empty.');
        }

        foreach ([
            'operationKind' => $this->operationKind,
            'bgapiJobUuid' => $this->bgapiJobUuid,
            'finalState' => $this->finalState,
            'retryPosture' => $this->retryPosture,
            'drainPosture' => $this->drainPosture,
        ] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw new \InvalidArgumentException("OperationRecoveryRecord: {$field} must not be empty when provided.");
            }
        }

        if ($this->acceptedAppendSequence !== null && $this->acceptedAppendSequence < 1) {
            throw new \InvalidArgumentException(
                'OperationRecoveryRecord: acceptedAppendSequence must be >= 1 when provided.',
            );
        }
    }
}
