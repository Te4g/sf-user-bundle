<?php

declare(strict_types=1);

namespace Te4g\Starter\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('install');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starter installer pipeline is not configured yet.');

        return Command::SUCCESS;
    }
}
