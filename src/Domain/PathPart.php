<?php declare(strict_types=1);

namespace App\Domain;

final class PathPart
{
    /** @var string */
    private $path;

    /** @var int */
    private $priority;

    /** @var string[] */
    private $substitutions;

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->path = rtrim($config['path'] ?? '', DIRECTORY_SEPARATOR);
        $this->priority = $config['priority'] ?? 0;
        $this->substitutions = $config['substitutions'] ?? [];
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return str_replace(array_keys($this->substitutions), array_values($this->substitutions), $this->path);
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }
}
