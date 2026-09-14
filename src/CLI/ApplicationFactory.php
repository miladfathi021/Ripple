<?php

declare(strict_types=1);

namespace Ripple\CLI;

use Ripple\Analysis\AnalysisRunner;
use Ripple\Reporting\ReportFormatterFactory;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    public function __construct(
        private readonly AnalysisRunner $analysisRunner = new AnalysisRunner(),
        private readonly ReportFormatterFactory $reportFormatterFactory = new ReportFormatterFactory(),
    ) {
    }

    public function create(): Application
    {
        $application = new Application('Ripple', '0.1.0');
        $application->add(new AnalyzeCommand(
            $this->analysisRunner,
            $this->reportFormatterFactory,
        ));
        $application->setDefaultCommand('analyze');

        return $application;
    }
}
