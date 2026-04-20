<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class EvidenceBundle
{
    /**
     * @param list<EvidenceRecordReference>              $recordReferences
     * @param list<OperationRecoveryRecord>              $operations
     * @param list<TerminalPublicationEvidenceRecord>    $terminalPublications
     * @param list<LifecycleSemanticEvidenceRecord>      $lifecycleSemantics
     */
    public function __construct(
        public RecoveryManifest $manifest,
        public RuntimeContinuitySnapshot $continuitySnapshot,
        public array $recordReferences,
        public array $operations,
        public array $terminalPublications,
        public array $lifecycleSemantics,
    ) {}
}
