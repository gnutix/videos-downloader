<?php declare(strict_types=1);

namespace App\Domain;

use Ramsey\Uuid\UuidInterface;

final class VideoDownload
{
    /** @var \Ramsey\Uuid\UuidInterface */
    private $id;

    /** @var string */
    private $type;

    /** @var string */
    private $videoId;

    /** @var string */
    private $path;

    /**
     * @param \Ramsey\Uuid\UuidInterface $id
     * @param string $type
     * @param string $videoId
     * @param string $path
     */
    public function __construct(UuidInterface $id, string $type, string $videoId, string $path)
    {
        $this->id = $id;
        $this->type = $type;
        $this->videoId = $videoId;
        $this->path = $path;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string) str_replace(DIRECTORY_SEPARATOR, ' - ', $this->path);
    }

    /**
     * @return \Ramsey\Uuid\UuidInterface
     */
    public function getId(): UuidInterface
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getVideoId(): string
    {
        return $this->videoId;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }
}
