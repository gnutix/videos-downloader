<?php declare(strict_types=1);

namespace Extension\CSV;

use App\Domain\Contents;
use App\Domain\Path;
use App\Domain\Content;
use App\Domain\PathPart;
use App\Domain\Source;
use App\UI\UserInterface;
use League\Csv\Exception;
use League\Csv\Reader;
use Symfony\Component\Yaml\Yaml;

final class CSV implements Source
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

        foreach (['resources', 'base_url'] as $key) {
            if (!isset($this->config[$key]) || empty($this->config[$key])) {
                throw new \RuntimeException(sprintf('The "%s" config key must be provided for CSV source.', $key));
            }
        }
    }

    /**
     * @return \App\Domain\Contents
     * @throws \RuntimeException
     */
    public function getContents(): Contents
    {
        $this->ui->write('Fetch the contents from the TAC CSV files... ');
        $contents = new Contents();

        foreach ((array) $this->config['resources'] as $resource) {
            $filePath = str_replace('%extension_path%', __DIR__, $resource);

            if (!file_exists($filePath)) {
                throw new \RuntimeException(sprintf('The resource "%s" does not exist.', $filePath));
            }

            try {
                /** @var \League\Csv\Reader $csv */
                $csv = Reader::createFromPath($filePath);
                $csv->setDelimiter($this->config['delimiter'] ?? ',');
                $csv->setHeaderOffset(0);

                foreach ($csv->getRecords() as $record) {

                    // Remove headers as keys so it's more flexible
                    $record = array_values($record);

                    $data = [];
                    foreach ((array) $this->config['columns'] as $column) {
                        $data[] = $record[$column];
                    }

                    $contents->add(new Content(implode(PHP_EOL, $data), $this->generatePath($record)));
                }
            } catch (Exception $e) {
                throw new \RuntimeException(sprintf('The resource "%s" could not be parsed.', $filePath));
            }
        }

        $this->ui->writeln('<info>Done.</info>');

        return $contents;
    }

    /**
     * @param array $record
     *
     * @return \App\Domain\Path
     */
    private function generatePath(array $record): Path
    {
        $pathPartConfig = $this->config['path_part'] ?? [];
        $pathPartConfig['substitutions'] = [
            '%base_url_path%' => $this->cleanPath(trim(
                str_replace(
                    $this->config['base_url'],
                    '',
                    $record[$this->config['base_url_column']]
                ),
                DIRECTORY_SEPARATOR
            )),
        ] + ($pathPartConfig['substitutions'] ?? []);

        return new Path([new PathPart($pathPartConfig)]);
    }

    /**
     * @todo Integrate into Path class ?
     *
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

        return trim(str_replace(array_keys($chars), array_values($chars), $string));
    }
}
