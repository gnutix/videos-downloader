<?php declare(strict_types=1);

namespace App\Domain;

final class Content
{
    /** @var string */
    private $data;

    /** @var \App\Domain\Path */
    private $path;

    /**
     * @param mixed $data
     * @param \App\Domain\Path $path
     */
    public function __construct($data, Path $path)
    {
        $this->data = $data;
        $this->path = $path;
    }

    /**
     * @return \App\Domain\Path
     */
    public function getPath(): Path
    {
        return $this->path;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }
}
