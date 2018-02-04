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
     * @param string $youtubeId
     */
    public function removeYoutubeId(string $youtubeId)
    {
        $this->youtubeIds = array_diff($this->youtubeIds, [$youtubeId]);
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
        // First we remove any slash in the song name to avoid bugs like nested "AC/DC" folders...
        // Then we replace " - " by a slash, as we like to have a root folder with the artist name (ACDC/Hells Bells)
        $sanitizedName = str_replace([DIRECTORY_SEPARATOR, ' - '], ['', DIRECTORY_SEPARATOR], $this->getName());

        return $this->listName.DIRECTORY_SEPARATOR.$sanitizedName;
    }
}
