<?php declare(strict_types=1);

namespace App;

use App\UI\NullUserInterface;
use App\UI\UserInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class Kernel
{
    /** @var bool */
    private $dryRun;

    /** @var UserInterface */
    private $ui;

    /** @var array */
    private $config;

    /**
     * @param bool $dryRun
     * @param UserInterface|null $ui
     *
     * @throws \RuntimeException
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function __construct(bool $dryRun = false, UserInterface $ui = null)
    {
        $this->dryRun = $dryRun;
        $this->ui = $ui ?: new NullUserInterface();
        $this->config = Yaml::parseFile($this->getProjectDir().DIRECTORY_SEPARATOR.'config/app.yml');
    }

    public function boot(): void
    {
        $this->download();
        $this->cleanFilesystem();
    }

    private function download(): void
    {
        $rootPathPart = $this->prepareRootPathPart($this->config['app']['paths']['root']['path_part']);

        // Loop over the different sources
        foreach ((array) $this->config['app']['sources'] as $sourceId) {
            $sourceConfig = $this->config['sources'][$sourceId];

            /** @var \App\Source\Source $source */
            $source = new $sourceConfig['class_name']($this->ui, $sourceConfig);
            $contents = $source->getContents();

            // Loop over the different platforms
            foreach ((array) $this->config['app']['platforms'] as $platformId) {
                $platformConfig = $this->config['platforms'][$platformId];

                /** @var \App\Platform\Platform $platform */
                $platform = new $platformConfig['class_name']($this->ui, $platformConfig, $this->dryRun);
                $platform->downloadContents($contents, $rootPathPart);
            }
        }
    }

    private function cleanFilesystem(): void
    {
        /** @todo Extract code from Platform/YouTube and make it universal... */
    }

    /**
     * @return string
     *
     * @throws \RuntimeException
     */
    private function getProjectDir(): string
    {
        if (!($projectRoot = getenv('PROJECT_ROOT'))) {
            throw new \RuntimeException('The environment variable "PROJECT_ROOT" has not been defined.');
        }

        return rtrim($projectRoot, DIRECTORY_SEPARATOR);
    }

    /**
     * @param string $pathPart
     *
     * @return string
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    private function prepareRootPathPart(string $pathPart): string
    {
        $pathPart = str_replace('%project_root%', $this->getProjectDir(), $pathPart);

        if (empty($pathPart) || DIRECTORY_SEPARATOR !== $pathPart{0}) {
            throw new \RuntimeException('The root path must be an absolute folder path.');
        }

        // Try to create the root directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir($pathPart);

        return rtrim($pathPart, DIRECTORY_SEPARATOR);
    }
}
