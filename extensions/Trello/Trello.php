<?php declare(strict_types=1);

namespace Extension\Trello;

use App\Domain\Contents;
use App\Domain\Content;
use App\Domain\Path;
use App\Domain\PathPart;
use App\Domain\Source;
use App\UI\UserInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Stevenmaguire\Services\Trello\Client;
use Stevenmaguire\Services\Trello\Exceptions\Exception;
use Symfony\Component\PropertyAccess\Exception\InvalidArgumentException as PropertyPathInvalidArgumentException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Yaml\Yaml;

final class Trello implements Source
{
    /**
     * This regex matches the following custom syntax :
     *
     *   1) `[]` allows to loop through all the entries and fetch their nested properties.
     *
     *     For example, if [foo] is an array containing N elements having each a key [bar] :
     *     - `[foo][][bar]` will loop through the N entries of `foo` and get the `bar` nested property for each of them.
     *
     *   2) `[property="value"]` does the same as `[]` but filters on one property.
     *
     *     For example, if [foo] is an array containing N elements having each a key [bar] and [baz] :
     *     - `[foo][baz="hello"][bar]` will loop through the N entries of `foo` and get the `bar` nested property for
     *       each entry matching `entry.baz === "hello"`.
     *
     * A selector can only contain ONE custom syntax and it must only occurs ONCE. So the following won't work :
     *
     * - `[foo][][bar][][baz]`
     * - `[foo][][bar][baz="hello"][world]`
     * - `[foo][bar="hello"][baz="world"][property]`
     */
    private const CUSTOM_SYNTAX_EXAMPLES = ['[]', '[property="value"]'];
    private const CUSTOM_SYNTAX_REGEX = '#^(.*?)(?:\[(?:([a-zA-Z-_]+)="(.*?)")?\])(.*?)$#';

    /** @var \App\UI\UserInterface */
    private $ui;

    /** @var array */
    private $config;

    /** @var PropertyAccessor */
    private $propertyAccessor;

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
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();

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
            $trelloCards = $client->getBoardCards(
                $this->config['board_id'],
                [
                    'attachments' => true,
                    'customFieldItems' => true,
                ]
            );
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

        // Decode the stdClass object to an array to be used with PropertyAccess
        foreach (json_decode(json_encode($trelloCards), true) as $card) {
            // Get the contents from the card's properties
            $data = [];
            foreach ((array) $this->config['card_properties'] as $selector) {
                foreach ($this->getValuesFromCardMatchingSelector($card, $selector) as $value) {
                    $data[] = $value;
                }
            }

            if (!empty($data)) {
                $listName = $this->propertyAccessor->getValue($lists, '['.$card['idList'].'].name');
                $cardName = $this->propertyAccessor->getValue($card, '[name]');

                $contents->add(
                    new Content(implode(PHP_EOL, $data), $this->generatePath($pathPartConfig, $listName, $cardName))
                );
            }
        }

        $this->ui->writeln('<info>Done.</info>');

        return $contents;
    }

    /**
     * @param string $selector
     * @param $card
     *
     * @return ArrayCollection
     * @throws \Symfony\Component\PropertyAccess\Exception\UnexpectedTypeException
     * @throws \Symfony\Component\PropertyAccess\Exception\AccessException
     * @throws \Symfony\Component\PropertyAccess\Exception\InvalidArgumentException
     */
    private function getValuesFromCardMatchingSelector($card, string $selector): ArrayCollection
    {
        // If there's no custom syntax, we simply try to fetch the value
        if (!preg_match(static::CUSTOM_SYNTAX_REGEX, $selector, $matches)) {
            return new ArrayCollection([$this->propertyAccessor->getValue($card, $selector)]);
        }

        list(, $rootProperty, $propertyName, $propertyValue, $subProperty) = $matches;

        // Guard against invalid usage of our custom syntax
        if (preg_match(static::CUSTOM_SYNTAX_REGEX, $subProperty)) {
            throw new PropertyPathInvalidArgumentException(
                sprintf(
                    'The "card_properties" selectors can only contain ONE custom syntax (available: %s).',
                    '`'.implode('`, `', static::CUSTOM_SYNTAX_EXAMPLES).'`'
                )
            );
        }

        $dataCollection = new ArrayCollection((array) $this->propertyAccessor->getValue($card, $rootProperty));

        // Filtering on property and value
        if ($propertyName && $propertyValue) {
            $dataCollection = $dataCollection
                ->filter(function ($item) use ($propertyName, $propertyValue) {
                    return $this->propertyAccessor->getValue($item, '['.$propertyName.']') === $propertyValue;
                });
        }

        $data = new ArrayCollection();
        foreach ($dataCollection as $dataCollectionItem) {
            $data->add($this->propertyAccessor->getValue($dataCollectionItem, $subProperty));
        }

        return $data;
    }

    /**
     * @param array $pathConfig
     * @param string $listName
     * @param string $cardName
     *
     * @return Path
     */
    private function generatePath(array $pathConfig, string $listName, string $cardName): Path
    {
        $pathConfig['substitutions'] = [
                // Here we remove any directory separator in the list/song name to avoid unwanted nested folders.
                // And we remove some characters that are not liked by filesystems too.
                // Ex: "AC/DC - Hells Bells" would give "AC/DC/Hells Bells". We prefer "ACDC/Hells Bells".
                '%list_name%' => $this->cleanPath($listName),
                '%card_name%' => $this->cleanPath($cardName)
            ] + ($pathConfig['substitutions'] ?? []);

        return new Path([new PathPart($pathConfig)]);
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function cleanPath(string $string): string
    {
        $chars = [
            '&' => 'and',
            DIRECTORY_SEPARATOR => '',
            '\\' => '',
            '#' => '',
            '%' => '',
            '{' => '',
            '}' => '',
            '<' => '',
            '>' => '',
            '*' => '',
            '?' => '',
            '$' => '',
            '!' => '',
            ':' => '',
            '@' => '',
        ];

        return (string) str_replace(array_keys($chars), array_values($chars), $string);
    }
}
