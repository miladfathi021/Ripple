<?php

declare(strict_types=1);

namespace Ripple\Analysis\Semantics;

use Ripple\Analysis\Flow\AffectedFlowResult;
use Ripple\Analysis\Impact\TransitiveImpactResult;

final class SemanticImpactAnalyzer
{
    public function __construct(
        private readonly SemanticAnnotationProvider $annotations = new StaticSemanticAnnotationProvider(),
    ) {
    }

    public function analyze(
        TransitiveImpactResult $blastRadius,
        AffectedFlowResult $affectedFlows,
    ): SemanticImpactResult {
        $annotations = $this->annotations->getAnnotations();
        if ($annotations === []) {
            return SemanticImpactResult::empty();
        }

        $blastRadiusIds = [];
        foreach ($blastRadius->getImpactedSymbolIds() as $symbolId) {
            $blastRadiusIds[$symbolId] = true;
        }

        $flowNodeIds = [];
        foreach ($affectedFlows->all() as $flow) {
            foreach ($flow->nodes as $node) {
                $flowNodeIds[$node->id] = true;
            }
        }

        $blastRadiusAnnotations = [];
        $affectedFlowAnnotations = [];
        foreach ($annotations as $annotation) {
            if (isset($blastRadiusIds[$annotation->symbolId])) {
                $blastRadiusAnnotations[] = $annotation;
            }
            if (isset($flowNodeIds[$annotation->symbolId])) {
                $affectedFlowAnnotations[] = $annotation;
            }
        }

        return SemanticImpactResult::fromAnnotations($blastRadiusAnnotations, $affectedFlowAnnotations);
    }
}
