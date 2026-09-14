<?php

declare(strict_types=1);

namespace Ripple\CLI;

use InvalidArgumentException;
use Ripple\Analysis\AnalysisRunner;
use Ripple\Reporting\ReportFormatterFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'analyze',
    description: 'Analyze the blast radius of code changes',
)]
final class AnalyzeCommand extends Command
{
    public function __construct(
        private readonly AnalysisRunner $analysisRunner,
        private readonly ReportFormatterFactory $reportFormatterFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_REQUIRED,
            'Output format (text or json)',
            'text',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');

        try {
            $formatter = $this->reportFormatterFactory->forFormat($format);
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::INVALID;
        }

        $result = $this->analysisRunner->run();
        $output->writeln($formatter->format($result));

        return $result->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
