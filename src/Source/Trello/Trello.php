<?php declare(strict_types=1);

namespace App\Source\Trello;

use App\Cli\IOHelper;
use App\Domain\VideoDownload;
use App\Platform\Platform;
use App\Source\Source;
use Ramsey\Uuid\Uuid;
use Stevenmaguire\Services\Trello\Client;
use Stevenmaguire\Services\Trello\Exceptions\Exception;

final class Trello implements Source
{
    /** @var IOHelper */
    private $ioHelper;

    /** @var array */
    private $options;

    /** @var \App\Source\Trello\PhpDoc\TrelloClient */
    private $client;

    /**
     * {@inheritdoc}
     */
    public function __construct(IOHelper $ioHelper, array $options)
    {
        $this->ioHelper = $ioHelper;
        $this->options = $options;
        $this->client = new Client(['token' => $options['api_key']]);
    }

    /**
     * {@inheritdoc}
     */
    public function getVideos(Platform $platform): array
    {
        $this->ioHelper->write('Fetch the videos from the source (Trello)... ');

        try {
            $trelloLists = $this->client->getBoardLists($this->options['board_id']);
            $trelloCards = $this->client->getBoardCards($this->options['board_id']);
        } catch (Exception $e) {
            $this->ioHelper->writeln(
                sprintf(
                    '<error>Could not fetch the information from Trello!</error>'.PHP_EOL.PHP_EOL.'    <error>%s</error>'.PHP_EOL,
                    $e->getMessage()
                )
            );

            return [];
        }

        // Add the list ID as a key to the array so it's easier to find it
        $lists = array_combine(array_column($trelloLists, 'id'), $trelloLists);

        $videos = [];
        foreach ($trelloCards as $card) {
            if (empty($videosIds = $platform->extractVideosIds($card->desc))) {
                continue;
            }

            foreach ($videosIds as $fileType => $videoIdsPerType) {
                foreach ((array) $videoIdsPerType as $videoId => $fileExtension) {
                    $uuid = Uuid::uuid4(); // random UUID

                    $videos[(string) $uuid] = new VideoDownload(
                        $uuid,
                        $fileType,
                        $fileExtension,
                        $videoId,
                        $this->generatePath($lists[$card->idList]->name, $card->name)
                    );
                }
            }
        }

        $this->ioHelper->writeln('<info>Done.</info>');

        return $videos;
    }

    /**
     * Here we remove any directory separator in the list/song name to avoid unwanted nested folders.
     * Ex: "Songs/AC/DC - Hells Bells" would give "Songs/AC/DC/Hells Bells".
     *
     * Then we replace a pattern by a directory separator, to allow having a parent folder with the artist name.
     * Ex: "Songs/ACDC - Hells Bells" => "Songs/ACDC/Hells Bells"
     *
     * @param string $listName
     * @param string $cardName
     *
     * @return string
     */
    private function generatePath(string $listName, string $cardName): string
    {
        $placeholders = [
            '%list_name%' => $this->removeDS($listName),
            '%card_name%' => $this->removeDS($cardName),
            $this->options['downloads_paths']['videos']['replace_pattern_by_directory_separator'] ?? ''
                => DIRECTORY_SEPARATOR,
        ];

        return str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $this->options['downloads_paths']['videos']['path']
        );
    }

    /**
     * Remove any DIRECTORY_SEPARATOR from a string.
     *
     * @param $input
     *
     * @return string
     */
    private function removeDS($input): string
    {
        return (string) str_replace(DIRECTORY_SEPARATOR, '', $input);
    }
}
