<?php declare(strict_types=1);

namespace App\Source\Trello;

use App\Domain\Content;
use App\Source\Source;
use App\UI\UserInterface;
use Stevenmaguire\Services\Trello\Client;
use Stevenmaguire\Services\Trello\Exceptions\Exception;

final class Trello implements Source
{
    /** @var \App\UI\UserInterface */
    private $ui;

    /** @var array */
    private $options;

    /** @var \App\Source\Trello\PhpDoc\TrelloClient */
    private $client;

    /**
     * {@inheritdoc}
     */
    public function __construct(UserInterface $ui, array $options)
    {
        $this->ui = $ui;
        $this->options = $options;
        $this->client = new Client(['token' => $options['api_key']]);
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(): array
    {
        $this->ui->write('Fetch the contents from Trello... ');

        try {
            $trelloLists = $this->client->getBoardLists($this->options['board_id']);
            $trelloCards = $this->client->getBoardCards($this->options['board_id']);
        } catch (Exception $e) {
            $this->ui->writeln(
                sprintf(
                    '<error>Could not fetch the information from Trello!</error>'.PHP_EOL.PHP_EOL.'    <error>%s</error>'.PHP_EOL,
                    $e->getMessage()
                )
            );

            return [];
        }

        // Add the list ID as a key to the array so it's easier to find it
        $lists = array_combine(array_column($trelloLists, 'id'), $trelloLists);

        $contents = [];
        foreach ($trelloCards as $card) {
            $contents[] = new Content($card->desc, $this->generatePath($lists[$card->idList]->name, $card->name));
        }

        $this->ui->writeln('<info>Done.</info>');

        return $contents;
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
            $this->options['paths']['videos_folder']['replace_pattern_by_directory_separator'] => DIRECTORY_SEPARATOR,
        ];

        return str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $this->options['paths']['videos_folder']['path_part']
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
