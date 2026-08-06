<?php

declare(strict_types=1);

namespace Te4g\Starter\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Te4g\Starter\Exception\GenerationFailed;
use Te4g\Starter\Exception\InvalidProject;
use Te4g\Starter\Exception\SubprocessFailed;
use Te4g\Starter\Installation\StarterInstaller;
use Te4g\Starter\Project\ProjectLayout;

final class InstallCommand extends Command
{
    public function __construct(
        private readonly StarterInstaller $installer,
        private readonly string $workingDirectory,
    ) {
        parent::__construct('install');
    }

    protected function configure(): void
    {
        $this->setName('install');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $layout = ProjectLayout::discover($this->workingDirectory);
            $this->installer->install($layout, static function (string $phase) use ($output): void {
                $output->writeln('Completed: '.$phase);
            });
        } catch (SubprocessFailed $exception) {
            $output->writeln($exception->phase(), OutputInterface::OUTPUT_RAW);
            $output->writeln('command: '.$this->renderCommand($exception->command()), OutputInterface::OUTPUT_RAW);
            $output->writeln(sprintf('exit code %d', $exception->exitCode()));
            $output->writeln('stdout:');
            $output->writeln($exception->stdout(), OutputInterface::OUTPUT_RAW);
            $output->writeln('stderr:');
            $output->writeln($exception->stderr(), OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        } catch (InvalidProject|GenerationFailed $exception) {
            $output->writeln(explode("\n", $exception->getMessage()), OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }

        $output->writeln('Starter installed. The generated code now belongs to this application.');
        $output->writeln('');
        $output->writeln('Next:');
        $output->writeln('  php bin/console make:migration');
        $output->writeln('  php bin/console doctrine:migrations:migrate');
        $output->writeln('  composer remove te4g/starter --dev');

        return Command::SUCCESS;
    }

    /** @param list<string> $command */
    private function renderCommand(array $command): string
    {
        return implode(' ', array_map(static function (string $argument): string {
            if (1 === preg_match('/^[A-Za-z0-9_@%+=:,\.\/-]+$/D', $argument)) {
                return $argument;
            }

            return "'".str_replace("'", "'\\''", $argument)."'";
        }, $command));
    }
}
