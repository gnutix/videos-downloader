<?php declare(strict_types=1);

namespace App\UI;

final class NullUserInterface implements UserInterface
{
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
    public function askConfirmation(string $message, bool $default = true): bool
    {
        return true;
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
     * @param int $indentation
     * @param int $characters
     * @param string $character
     *
     * @return string
     */
    public function indent(int $indentation = 1, int $characters = 2, string $character = ' '): string
    {
        return '';
    }
}
