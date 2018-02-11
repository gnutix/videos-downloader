<?php declare(strict_types=1);

namespace App\Domain;

use App\Domain\Collection\Path;

final class Content
{
    /** @var string */
    private $data;

    /** @var \App\Domain\Collection\Path */
    private $path;

    /**
     * @param string $data
     * @param \App\Domain\Collection\Path $path
     */
    public function __construct(string $data, Path $path)
    {
        $this->data = $data;
        $this->path = $path;
    }

    /**
     * @return \App\Domain\Collection\Path
     */
    public function getPath(): Path
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }
}
