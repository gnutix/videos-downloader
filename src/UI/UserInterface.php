<?php declare(strict_types=1);

namespace App\UI;

use Symfony\Component\Console\Style\SymfonyStyle;

interface UserInterface
{
    /**
     * @return SymfonyStyle
     */
    public function getSymfonyStyle(): SymfonyStyle;

    /**
     * @return bool
     */
    public function isInteractive(): bool;

    /**
     * @param bool $interactive
     */
    public function setInteractive(bool $interactive): void;

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
     * @param string $message
     * @param mixed $default
     *
     * @return string
     */
    public function askQuestion(string $message, $default = null): string;

    /**
     * @param string[] $messages
     * @param int $indentation
     */
    public function listing(array $messages, int $indentation = 0): void;

    /**
     * @param callable $callable
     *
     * @return mixed
     */
    public function forceOutput(callable $callable);

    /**
     * @param callable $callable
     *
     * @return mixed
     */
    public function forceInteractive(callable $callable);

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
