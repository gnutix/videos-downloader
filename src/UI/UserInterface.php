<?php declare(strict_types=1);

namespace App\UI;

interface UserInterface
{
    /**
     * @return bool
     */
    public function isInteractive(): bool;

    /**
     * @return bool
     */
    public function isDryRun(): bool;

    /**
     * @param string|string[] $messages
     * @param bool $newLine
     */
    public function write($messages, bool $newLine = false): void;

    /**
     * @param string|string[] $messages
     * @param int $options
     */
    public function writeln($messages, int $options = 0): void;

    /**
     * @param bool $confirmationDefault
     *
     * @return bool
     */
    public function confirm(bool $confirmationDefault = true): bool;

    /**
     * @param string[] $messages
     * @param int $indentation
     */
    public function listing(array $messages, int $indentation = 0): void;

    /**
     * @param callable $callable
     */
    public function forceOutput(callable $callable): void;

    /**
     * @param int $indentation
     * @param int $characters
     * @param string $character
     *
     * @return string
     */
    public function indent(int $indentation = 1, int $characters = 2, string $character = ' '): string;

    /**
     * @param string $error
     * @param string[] &$errors
     */
    public function logError(string $error, array &$errors): void;

    /**
     * @param string[] $errors
     * @param string $process
     * @param string $type
     * @param int $indentation
     */
    public function displayErrors(array $errors, string $process, string $type = 'error', int $indentation = 0): void;
}
