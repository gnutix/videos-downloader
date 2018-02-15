<?php declare(strict_types=1);

namespace Extension\TAC;

use App\Domain\Collection\Contents;
use App\Domain\Collection\Path;
use App\Domain\Content;
use App\Domain\PathPart;
use App\Domain\Source;
use App\UI\UserInterface;
use League\Csv\Exception;
use League\Csv\Reader;
use Symfony\Component\Yaml\Yaml;

final class TAC implements Source
{
    private const TAC_URL = 'https://tonypolecastro.com/';

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

        if (!isset($this->config['resources']) || empty($this->config['resources'])) {
            throw new \RuntimeException('The resources must be provided for TAC source.');
        }
    }

    /**
     * @return \App\Domain\Collection\Contents
     * @throws \RuntimeException
     */
    public function getContents(): Contents
    {
        $this->ui->write('Fetch the contents from the CSV... ');
        $contents = new Contents();
        $separator = '; ';

        foreach ((array) $this->config['resources'] as $resource) {
            $filePath = str_replace('%extension_path%', __DIR__, $resource);

            if (!file_exists($filePath)) {
                throw new \RuntimeException(sprintf('The resource "%s" does not exist.', $filePath));
            }

            /** @var \League\Csv\Reader $csv */
            try {
                $csv = Reader::createFromPath($filePath);
                $csv->setHeaderOffset(0);

                foreach ($csv->getRecords() as $record) {

                    // Remove headers as keys so it's more flexible
                    $record = array_values($record);

                    // Concatenate the content of the second (pdf links) and third columns (video links)
                    $data = str_replace($separator, PHP_EOL, $record[1].$separator.$record[2]);

                    $contents->add(new Content($data, $this->generatePath($record)));
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
     * @return \App\Domain\Collection\Path
     */
    private function generatePath(array $record): Path
    {
        $pathPartConfig = $this->config['path_part'];
        $pathPartConfig['path'] = str_replace(
            '%lesson_url_path%',
            trim(str_replace(static::TAC_URL, '', $record[0]), DIRECTORY_SEPARATOR),
            $pathPartConfig['path']
        );

        return new Path([new PathPart($pathPartConfig)]);
    }
}
