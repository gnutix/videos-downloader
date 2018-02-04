<?php declare(strict_types=1);

namespace App;

final class Song
{
    /** @var array */
    private $youtubeIds;

    /** @var string */
    private $listName;

    /** @var string */
    private $name;

    /**
     * @param array $youtubeIds
     * @param string $listName
     * @param string $name
     */
    public function __construct(array $youtubeIds, string $listName, string $name)
    {
        $this->youtubeIds = $youtubeIds;
        $this->listName = $listName;
        $this->name = $name;
    }

    /**
     * @return array
     */
    public function getYoutubeIds(): array
    {
        return $this->youtubeIds;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return sprintf(
            '%s/%s',
            $this->listName,
            str_replace([DIRECTORY_SEPARATOR, ' - '], ['', DIRECTORY_SEPARATOR], $this->getName())
        );
    }
}
