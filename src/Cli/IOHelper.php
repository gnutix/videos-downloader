<?php declare(strict_types=1);

namespace App\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

final class IOHelper
{
    /** @var Command */
    private $command;

    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

    /**
     * @param Command $command
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function __construct(Command $command, InputInterface $input, OutputInterface $output)
    {
        $this->command = $command;
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * @param string $message
     * @param bool $default
     *
     * @return bool
     */
    public function askConfirmation(string $message, bool $default = true): bool
    {
        if (!$this->isInteractive()) {
            return true;
        }

        /** @var \Symfony\Component\Console\Helper\QuestionHelper $questionHelper */
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $questionHelper = $this->command->getHelper('question');

        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        return $questionHelper->ask($this->input, $this->output, new ConfirmationQuestion($message, $default));
    }

    /**
     * @return bool
     */
    public function isDryRun(): bool
    {
        try {
            return $this->input->getOption('dry-run');
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * @return bool
     */
    public function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * @param string $message
     * @param bool $newLine
     */
    public function write(string $message, $newLine = false): void
    {
        $this->output->write($message, $newLine);
    }

    /**
     * @param int $indentation
     */
    public function done(int $indentation = 0): void
    {
        $this->output->writeln(str_repeat(' ', $indentation).'<info>Done.</info>');
    }

    /**
     * @param string $error
     * @param array &$errors
     * @param string $type
     */
    public function logError(string $error, array &$errors, string $type = 'error'): void
    {
        $this->output->writeln(PHP_EOL.PHP_EOL.'<'.$type.'>'.$error.'</'.$type.'>'.PHP_EOL);
        $errors[] = $error;
    }

    /**
     * @param array $errors
     * @param string $process
     * @param string $type
     * @param int $indentation
     */
    public function displayErrors(array $errors, string $process, string $type = 'error', int $indentation = 0): void
    {
        $nbErrors = \count($errors);
        if ($nbErrors > 0) {
            $this->output->writeln(
                PHP_EOL.'<'.$type.'>There were '.$nbErrors.' errors during the '.$process.' :</'.$type.'>'
            );

            $this->listing($errors, $indentation);
        }
    }

    /**
     * @param array $messages
     * @param int $indentation
     */
    public function listing(array $messages, int $indentation = 0): void
    {
        $messages = array_map(function ($message) use ($indentation) {
            return sprintf(str_repeat(' ', $indentation).' * %s', $message);
        }, $messages);

        $this->output->write(PHP_EOL);
        $this->output->writeln($messages);
        $this->output->write(PHP_EOL);
    }
}
