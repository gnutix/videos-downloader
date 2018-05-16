<?php declare(strict_types=1);

namespace App\UI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

final class CommandLineInterface implements UserInterface
{
    /** @var bool */
    private $dryRun;

    /** @var \Symfony\Component\Console\Command\Command */
    private $command;

    /** @var \Symfony\Component\Console\Input\InputInterface */
    private $input;

    /** @var \Symfony\Component\Console\Output\OutputInterface */
    private $output;

    /**
     * @param bool $dryRun
     * @param \Symfony\Component\Console\Command\Command $command
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function __construct(bool $dryRun, Command $command, InputInterface $input, OutputInterface $output)
    {
        $this->dryRun = $dryRun;
        $this->command = $command;
        $this->input = $input;
        $this->output = $output;

        if ($dryRun) {
            $input->setInteractive(false);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * {@inheritdoc}
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * {@inheritdoc}
     */
    public function askQuestion(string $message, $default = null): string
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $questionHelper */
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $questionHelper = $this->command->getHelper('question');

        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $answer = $questionHelper->ask($this->input, $this->output, new Question($message, $default));

        if ($answer && $this->input->isInteractive()) {
            $this->writeln('');
        }

        return (string) $answer;
    }

    /**
     * {@inheritdoc}
     */
    private function askConfirmation(string $message, bool $default = true): bool
    {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $questionHelper */
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $questionHelper = $this->command->getHelper('question');

        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $answer = $questionHelper->ask($this->input, $this->output, new ConfirmationQuestion($message, $default));

        if ($answer && $this->input->isInteractive()) {
            $this->writeln('');
        }

        return $answer;
    }

    /**
     * {@inheritdoc}
     */
    public function write($messages, bool $newLine = false): void
    {
        $this->output->write($messages, $newLine);
    }

    /**
     * {@inheritdoc}
     */
    public function writeln($messages, int $options = 0): void
    {
        $this->output->writeln($messages, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function listing(array $messages, int $indentation = 0): void
    {
        $messages = array_map(function ($message) use ($indentation) {
            return sprintf(str_repeat(' ', $indentation).' * %s', $message);
        }, $messages);

        $this->write(PHP_EOL);
        $this->writeln($messages);
        $this->write(PHP_EOL);
    }

    /**
     * {@inheritdoc}
     */
    public function forceOutput(callable $callable): void
    {
        $verbosity = $this->output->getVerbosity();
        $this->output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);

        $callable();

        $this->output->setVerbosity($verbosity);
    }

    /**
     * {@inheritdoc}
     */
    public function indent(int $indentation = 1, int $characters = 2, string $character = ' '): string
    {
        return str_repeat(str_repeat($character, $characters), $indentation);
    }

    /**
     * {@inheritdoc}
     */
    public function confirm(bool $confirmationDefault = true): bool
    {
        $confirmationQuestion = '<question>Continue?</question> ('.($confirmationDefault ? 'Y/n' : 'y/N').') ';
        if (!$this->askConfirmation($confirmationQuestion, $confirmationDefault)) {
            $this->writeln(PHP_EOL.$this->indent().'Not doing anything...');

            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function logError(string $error, array &$errors): void
    {
        $this->writeln('<error>An error occurred.</error>');
        $errors[] = $error;
    }

    /**
     * {@inheritdoc}
     */
    public function displayErrors(
        array $errors,
        string $process,
        string $type = 'error',
        int $indentation = 0
    ): void {
        $nbErrors = \count($errors);
        if ($nbErrors > 0) {
            $this->forceOutput(function () use ($nbErrors, $errors, $process, $type, $indentation) {
                $this->writeln(
                    PHP_EOL.PHP_EOL.'<'.$type.'>There were '.$nbErrors.' errors during the '.$process.' :</'.$type.'>'
                );

                $this->listing($errors, $indentation);
            });
        }
    }
}
