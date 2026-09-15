<?php

declare(strict_types=1);

namespace Ripple\Analysis;

use Ripple\Analysis\ChangedSymbols\ChangedSymbolResult;
use Ripple\Analysis\Dependencies\DependencyResult;
use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Graph\DependencyGraph;
use Ripple\Analysis\Graph\ReverseDependencyGraph;
use Ripple\Analysis\Impact\DirectImpactResult;
use Ripple\Analysis\Impact\TransitiveImpactResult;
use Ripple\Analysis\Index\RepositoryIndex;
use Ripple\Analysis\Risk\RiskFactorResult;
use Ripple\Analysis\Risk\RiskScoreResult;
use Ripple\Analysis\Semantics\SemanticImpactResult;
use Ripple\Analysis\Tests\TestImpactResult;
use Ripple\Git\DiffResult;
use Ripple\Git\History\ChurnResult;

final readonly class AnalysisResult
{
    public function __construct(
        public string $status,
        public string $message = '',
        public ?DiffResult $diff = null,
        public ?ChangedSymbolResult $changedSymbols = null,
        public ?DependencyResult $dependencies = null,
        public ?DependencyGraph $graph = null,
        public ?ReverseDependencyGraph $reverseGraph = null,
        public ?RepositoryIndex $repositoryIndex = null,
        public ?DirectImpactResult $directImpact = null,
        public ?TransitiveImpactResult $blastRadius = null,
        public ?RiskFactorResult $riskFactors = null,
        public ?RiskScoreResult $riskScore = null,
        public ?AffectedFlowResult $affectedFlows = null,
        public ?SemanticImpactResult $semanticImpact = null,
        public ?ChurnResult $churn = null,
        public ?TestImpactResult $testImpact = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'ok';
    }
}
