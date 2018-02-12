<?php declare(strict_types=1);

namespace Extension\Trello;

use App\Domain\Collection\Contents;
use App\Domain\Content;
use App\Domain\Collection\Path;
use App\Domain\PathPart;
use App\Domain\Source;
use App\UI\UserInterface;
use Stevenmaguire\Services\Trello\Client;
use Stevenmaguire\Services\Trello\Exceptions\Exception;
use Symfony\Component\Yaml\Yaml;

final class Trello implements Source
{
    /** @var \App\UI\UserInterface */
    private $ui;

    /** @var array */
    private $config;

    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function __construct(UserInterface $ui, array $config = [])
    {
        $this->ui = $ui;
        $this->config = array_merge(
            (array) Yaml::parseFile(__DIR__.DIRECTORY_SEPARATOR.'config/config.yml'),
            $config
        );

        if (!isset($this->config['board_id']) || empty($this->config['board_id'])) {
            throw new \RuntimeException('The board_id must be provided for Trello source.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(): Contents
    {
        $this->ui->write('Fetch the contents from Trello... ');
        $contents = new Contents();

        try {
            /** @var \Extension\Trello\PhpDoc\TrelloClient $client */
            $client = new Client(['token' => $this->config['api_key']]);
            $trelloLists = $client->getBoardLists($this->config['board_id']);
            $trelloCards = $client->getBoardCards($this->config['board_id']);
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
        $pathPartConfig = $this->config['path_part'];

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
