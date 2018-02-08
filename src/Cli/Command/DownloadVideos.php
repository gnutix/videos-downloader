<?php declare(strict_types=1);

namespace App\Cli\Command;

use App\Cli\IOHelper;
use App\Kernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class DownloadVideos extends Command
{
    public const COMMAND_NAME = 'download:videos';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $this->setName(static::COMMAND_NAME);

        $this->addOption('dry-run', null, InputOption::VALUE_OPTIONAL, 'Execute the script as a dry run.', false);
    }

    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $ioHelper = new IOHelper($this, $input, $output);
        $config = Yaml::parseFile(Kernel::getProjectRootPath().DIRECTORY_SEPARATOR.'config/app.yml');

        // Loop over the different sources
        foreach ((array) $config['config']['sources'] as $sourceId) {
            $sourceConfig = $config['sources'][$sourceId];

            /** @var \App\Source\Source $source */
            $source = new $sourceConfig['class_name']($ioHelper, $sourceConfig);

            // Loop over the different platforms
            foreach ((array) $config['config']['platforms'] as $platformId) {
                $platformConfig = $config['platforms'][$platformId];

                /** @var \App\Platform\Platform $platform */
                $platform = new $platformConfig['class_name']($ioHelper, $platformConfig);

                $platform->downloadVideos($source->getVideos($platform));
            }
        }
    }
}
