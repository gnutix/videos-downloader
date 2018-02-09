<?php declare(strict_types=1);

namespace App\Source\Trello;

use App\Domain\Collection;
use App\Domain\Content;
use App\Domain\Path;
use App\Domain\PathPart;
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
    public function getContents(): Collection
    {
        $this->ui->write('Fetch the contents from Trello... ');
        $contents = new Collection();

        try {
            $trelloLists = $this->client->getBoardLists($this->options['board_id']);
            $trelloCards = $this->client->getBoardCards($this->options['board_id']);
        } catch (Exception $e) {
            $this->ui->writeln(
                sprintf(
                    '<error>Could not fetch the information from Trello!</error>%s<error>%s</error>'.PHP_EOL,
                    PHP_EOL.PHP_EOL.'    ',
                    $e->getMessage()
                )
            );

            return $contents;
        }

        // Add the list ID as a key to the array so it's easier to find it
        $lists = array_combine(array_column($trelloLists, 'id'), $trelloLists);
        $pathPartConfig = $this->options['path_part'];

        foreach ($trelloCards as $card) {
            $pathPartConfig['substitutions'] = [
                // Here we remove any directory separator in the list/song name to avoid unwanted nested folders.
                // Ex: "Songs/AC/DC - Hells Bells" would give "Songs/AC/DC/Hells Bells".
                '%list_name%' => str_replace(DIRECTORY_SEPARATOR, '', $lists[$card->idList]->name),
                '%card_name%' => str_replace(DIRECTORY_SEPARATOR, '', $card->name)
            ] + ($pathPartConfig['substitutions'] ?? []);

            $contents->add(new Content($card->desc, new Path([new PathPart($pathPartConfig)])));
        }

        $this->ui->writeln('<info>Done.</info>');

        return $contents;
    }
}
