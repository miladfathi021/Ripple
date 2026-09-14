<?php

declare(strict_types=1);

namespace Ripple\CLI;

use Ripple\Analysis\ReadinessService;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    public function __construct(
        private readonly ReadinessService $readinessService = new ReadinessService(),
    ) {
    }

    public function create(): Application
    {
        $application = new Application('Ripple', '0.1.0');
        $application->add(new AnalyzeCommand($this->readinessService));
        $application->setDefaultCommand('analyze');

        return $application;
    }
}
