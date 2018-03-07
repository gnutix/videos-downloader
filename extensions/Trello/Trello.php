<?php declare(strict_types=1);

namespace Extension\Trello;

use App\Domain\Contents;
use App\Domain\Content;
use App\Domain\Path;
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
            $trelloCards = $client->getBoardCards($this->config['board_id'], ['attachments' => true]);
        } catch (Exception $e) {
            $this->ui->writeln(
                sprintf(
                    '<error>Could not fetch the information from Trello!</error>%s<error>%s</error>'.PHP_EOL,
                    PHP_EOL.PHP_EOL.$this->ui->indent(2),
                    $e->getMessage()
                )
            );

            return $contents;
        }

        // Add the list ID as a key to the array so it's easier to find it
        $lists = array_combine(array_column($trelloLists, 'id'), $trelloLists);
        $pathPartConfig = $this->config['path_part'];

        foreach ($trelloCards as $card) {

            // Get the contents using the description of the card and the attachments
            $data = [$card->desc];
            foreach ($card->attachments as $attachment) {
                $data[] = $attachment->url;
            }

            $pathPartConfig['substitutions'] = [
                // Here we remove any directory separator in the list/song name to avoid unwanted nested folders.
                // And we remove some characters that are not liked by filesystems too.
                // Ex: "Songs/AC/DC - Hells Bells" would give "Songs/AC/DC/Hells Bells".
                '%list_name%' => $this->cleanPath($lists[$card->idList]->name),
                '%card_name%' => $this->cleanPath($card->name)
            ] + ($pathPartConfig['substitutions'] ?? []);

            $contents->add(new Content(implode(PHP_EOL, $data), new Path([new PathPart($pathPartConfig)])));
        }

        $this->ui->writeln('<info>Done.</info>');

        return $contents;
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function cleanPath(string $string): string
    {
        $chars = [DIRECTORY_SEPARATOR, '#', '%', '{', '}', '\\', '<', '>', '*', '?', '$', '!', ':', '@'];
        $sanitizedString = str_replace($chars, '', $string);

        return (string) str_replace('&', 'and', $sanitizedString);
    }
}
