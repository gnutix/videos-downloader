<?php declare(strict_types=1);

namespace App\Cli;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class IOHelper
{
    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * @param string $message
     * @param bool $newLine
     */
    public function write(string $message, $newLine = false)
    {
        $this->output->write($message, $newLine);
    }


    /**
     * @param int $newLines
     */
    public function done(int $newLines = 0)
    {
        for ($i = 0; $i < $newLines; $i++) {
            $this->output->write(PHP_EOL);
        }

        $this->output->writeln(' <info>Done.</info>');
    }

    /**
     * @param \Exception $e
     * @param array &$errors
     * @param string $type
     */
    public function logError(\Exception $e, array &$errors, string $type = 'error')
    {
        $this->output->writeln(PHP_EOL.PHP_EOL.'<'.$type.'>'.$e->getMessage().'</'.$type.'>'.PHP_EOL);
        $errors[] = $e->getMessage();
    }

    /**
     * @param array $errors
     * @param string $process
     * @param string $type
     */
    public function displayErrors(array $errors, string $process, string $type = 'error')
    {
        $nbErrors = \count($errors);
        if ($nbErrors > 0) {
            $this->output->writeln(
                PHP_EOL.'<'.$type.'>There were '.$nbErrors.' errors during the '.$process.' :</'.$type.'>'
            );
            (new SymfonyStyle($this->input, $this->output))->listing($errors);
        }
    }
}
