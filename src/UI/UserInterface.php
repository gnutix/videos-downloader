<?php declare(strict_types=1);

namespace App\UI;

interface UserInterface
{
    /**
     * @param string|array $messages
     * @param bool $newLine
     */
    public function write($messages, bool $newLine = false): void;

    /**
     * @param string|array $messages
     * @param int $options
     */
    public function writeln($messages, int $options = 0): void;

    /**
     * @param string $message
     * @param bool $default
     *
     * @return bool
     */
    public function askConfirmation(string $message, bool $default = true): bool;

    /**
     * @param array $messages
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
}
