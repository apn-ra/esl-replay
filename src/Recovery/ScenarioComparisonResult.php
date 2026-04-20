<?php

declare(strict_types=1);

namespace Apntalk\EslReplay\Recovery;

final readonly class ScenarioComparisonResult
{
    /**
     * @param list<ReconstructionIssue> $issues
     */
    public function __construct(
        public string $scenarioName,
        public bool $passed,
        public string $bundleId,
        public array $issues,
    ) {
        if (trim($this->scenarioName) === '') {
            throw new \InvalidArgumentException('ScenarioComparisonResult: scenarioName must not be empty.');
        }

        if (trim($this->bundleId) === '') {
            throw new \InvalidArgumentException('ScenarioComparisonResult: bundleId must not be empty.');
        }
    }
}
