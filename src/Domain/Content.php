<?php declare(strict_types=1);

namespace App\Domain;

final class Content
{
    public const PATH_PART_APPEND = 0;
    public const PATH_PART_PREPEND = 1;

    /** @var string */
    private $data;

    /** @var array */
    private $pathParts = [];

    /**
     * @param string $data
     * @param string $pathPart
     */
    public function __construct(string $data, string $pathPart = '')
    {
        $this->data = $data;
        $this->addPathPart($pathPart);
    }

    /**
     * @param string $pathPart
     * @param int $position
     *
     * @return self
     */
    public function addPathPart(string $pathPart, int $position = self::PATH_PART_APPEND): self
    {
        if (empty($pathPart)) {
            return $this;
        }

        if ($position === static::PATH_PART_PREPEND) {
            array_unshift($this->pathParts, $pathPart);
        } else {
            $this->pathParts[] = $pathPart;
        }

        return $this;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return implode(
            DIRECTORY_SEPARATOR,
            array_map(
                function ($pathPart) {
                    return rtrim($pathPart, DIRECTORY_SEPARATOR);
                },
                $this->pathParts
            )
        );
    }

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }
}
