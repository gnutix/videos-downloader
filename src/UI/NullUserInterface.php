<?php declare(strict_types=1);

namespace App\UI;

final class NullUserInterface implements UserInterface
{
    /** @var bool */
    private $dryRun;

    /**
     * @param bool $dryRun
     */
    public function __construct(bool $dryRun)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * {@inheritdoc}
     */
    public function isInteractive(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * {@inheritdoc}
     */
    public function write($messages, bool $newLine = false): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function writeln($messages, int $options = 0): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function listing(array $messages, int $indentation = 0): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function forceOutput(callable $callable): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function indent(int $indentation = 1, int $characters = 2, string $character = ' '): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function confirm(bool $confirmationDefault = true): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function logError(string $error, array &$errors): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function displayErrors(array $errors, string $process, string $type = 'error', int $indentation = 0): void
    {
    }
}
