<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class ScenarioExpectation
{
    /**
     * @param list<string>                       $expectedRecoveryGenerations
     * @param list<ExpectedOperationLifecycle>   $expectedOperations
     * @param list<ExpectedTerminalPublication>  $expectedTerminalPublications
     * @param list<ExpectedLifecycleSemantic>    $expectedLifecycleSemantics
     */
    public function __construct(
        public string $scenarioName,
        public array $expectedRecoveryGenerations = [],
        public ?string $expectedReplayContinuityPosture = null,
        public ?string $expectedRetryPosture = null,
        public ?string $expectedDrainPosture = null,
        public ?string $expectedReconstructionPosture = null,
        public array $expectedOperations = [],
        public array $expectedTerminalPublications = [],
        public array $expectedLifecycleSemantics = [],
    ) {
        if (trim($this->scenarioName) === '') {
            throw new \InvalidArgumentException('ScenarioExpectation: scenarioName must not be empty.');
        }
    }
}
