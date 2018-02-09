<?php declare(strict_types=1);

namespace App\Cli\Command;

use App\Cli\CommandLineInterface;
use App\Kernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Download extends Command
{
    public const COMMAND_NAME = 'download';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $this->setName(static::COMMAND_NAME)
            ->addOption('dry-run', null, InputOption::VALUE_OPTIONAL, 'Execute the script as a dry run.', false);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        (new Kernel((bool) $input->getOption('dry-run'), new CommandLineInterface($this, $input, $output)))->boot();
    }
}
