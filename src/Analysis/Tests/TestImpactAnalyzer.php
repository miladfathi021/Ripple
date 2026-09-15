<?php

declare(strict_types=1);

namespace Ripple\Analysis\Tests;

use Ripple\Analysis\Impact\TransitiveImpactResult;

final class TestImpactAnalyzer
{
    public function analyze(
        TransitiveImpactResult $blastRadius,
        TestCatalog $catalog,
    ): TestImpactResult {
        $impacts = [];
        foreach ($blastRadius->entries as $entry) {
            $test = $catalog->getTestSymbol($entry->impactedSymbolId);
            if ($test === null || $test->type !== TestSymbolType::Method) {
                continue;
            }

            $seen = [];
            foreach ($entry->origins as $origin) {
                if (isset($seen[$origin->changedSymbolId])) {
                    continue;
                }

                $seen[$origin->changedSymbolId] = true;
                $impacts[] = new TestImpact(
                    $origin->changedSymbolId,
                    $test,
                    $origin->depth,
                    $origin->depth <= 1 ? TestImpactType::Direct : TestImpactType::Indirect,
                );
            }
        }

        return TestImpactResult::fromImpacts($impacts);
    }
}
