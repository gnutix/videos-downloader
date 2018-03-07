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
use Symfony\Component\PropertyAccess\PropertyAccess;
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

        foreach (['board_id', 'card_properties'] as $key) {
            if (!isset($this->config[$key]) || empty($this->config[$key])) {
                throw new \RuntimeException(sprintf('The "%s" config key must be provided for Trello source.', $key));
            }
        }
    }

    /**
     * {@inheritdoc}
     * @throws \Symfony\Component\PropertyAccess\Exception\UnexpectedTypeException
     * @throws \Symfony\Component\PropertyAccess\Exception\AccessException
     * @throws \Symfony\Component\PropertyAccess\Exception\InvalidArgumentException
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

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $indexId = '[%index%]';
        $indexIdLength = \strlen($indexId);

        // Decode the stdClass object to an array to be used with PropertyAccess
        foreach (json_decode(json_encode($trelloCards), true) as $card) {

            // Get the contents from the card
            $data = [];
            foreach ((array) $this->config['card_properties'] as $property) {
                if (false === ($indexIdPosition = strpos($property, '[%index%]'))) {
                    $data[] = $propertyAccessor->getValue($card, $property);
                } else {
                    $rootProperty = substr($property, 0, $indexIdPosition);
                    $subProperties = substr($property, $indexIdPosition + $indexIdLength);

                    foreach ((array) $propertyAccessor->getValue($card, $rootProperty) as $propertyValues) {
                        $data[] = $propertyAccessor->getValue($propertyValues, $subProperties);
                    }
                }
            }

            $pathPartConfig['substitutions'] = [
                // Here we remove any directory separator in the list/song name to avoid unwanted nested folders.
                // And we remove some characters that are not liked by filesystems too.
                // Ex: "Songs/AC/DC - Hells Bells" would give "Songs/AC/DC/Hells Bells".
                '%list_name%' => $this->cleanPath($lists[$card['idList']]->name),
                '%card_name%' => $this->cleanPath($card['name'])
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
