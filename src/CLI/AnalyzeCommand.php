<?php

declare(strict_types=1);

namespace Ripple\CLI;

use Ripple\Analysis\ReadinessService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'analyze',
    description: 'Analyze the blast radius of code changes',
)]
final class AnalyzeCommand extends Command
{
    public function __construct(
        private readonly ReadinessService $readinessService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->readinessService->isReady()) {
            $output->writeln('<error>Ripple is not ready.</error>');

            return Command::FAILURE;
        }

        $output->writeln('🌊 Ripple');
        $output->writeln('');
        $output->writeln('Ripple is ready.');

        return Command::SUCCESS;
    }
}
