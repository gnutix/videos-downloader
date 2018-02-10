<?php declare(strict_types=1);

namespace App;

use App\Domain\Content;
use App\Domain\PathPart;
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
    public function __construct(bool $dryRun = false, ?UserInterface $ui)
    {
        $this->dryRun = $dryRun;
        $this->ui = $ui ?: new NullUserInterface();
        $this->config = Yaml::parseFile($this->getProjectDir().DIRECTORY_SEPARATOR.'config/app.yml');
    }

    /**
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function boot(): void
    {
        $rootPathPart = $this->getRootPathPart();

        // Loop over the different sources
        foreach ((array) $this->config['app']['sources'] as $sourceId) {
            $sourceConfig = $this->config['sources'][$sourceId];

            /** @var \App\Source\Source $source */
            $source = new $sourceConfig['class_name']($this->ui, $sourceConfig);

            // Add the root path part
            $contents = $source->getContents()
                ->map(function(Content $content) use ($rootPathPart) {
                    $content->getPath()->add($rootPathPart);

                    return $content;
                });

            // Loop over the different platforms
            foreach ((array) $this->config['app']['platforms'] as $platformId) {
                $platformConfig = $this->config['platforms'][$platformId];

                /** @var \App\Platform\Platform $platform */
                $platform = new $platformConfig['class_name']($this->ui, $platformConfig, $this->dryRun);
                $platform->synchronizeContents($contents, $rootPathPart);
            }
        }
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
     * @return \App\Domain\PathPart
     *
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    private function getRootPathPart(): PathPart
    {
        $pathPartConfig = $this->config['app']['path_part'];
        $pathPartConfig['substitutions'] = [
                '%project_root%' => $this->getProjectDir()
            ] + ($pathPartConfig['substitutions'] ?? []);
        $rootPathPart = new PathPart($pathPartConfig);

        // Try to create the root directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir($rootPathPart->getPath());

        return $rootPathPart;
    }
}
