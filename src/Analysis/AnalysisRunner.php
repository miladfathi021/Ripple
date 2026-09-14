<?php

declare(strict_types=1);

namespace Ripple\Analysis;

final class AnalysisRunner
{
    public function __construct(
        private readonly ReadinessService $readinessService = new ReadinessService(),
    ) {
    }

    public function run(): AnalysisResult
    {
        if (!$this->readinessService->isReady()) {
            return new AnalysisResult('error', 'Ripple is not ready.');
        }

        return new AnalysisResult('ready', 'Ripple is ready.');
    }
}
