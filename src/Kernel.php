<?php declare(strict_types=1);

namespace App;

use App\Domain\Content;
use App\Domain\Contents;
use App\Domain\Path;
use App\Domain\PathPart;
use App\Domain\ProjectRootPathAware;
use App\Domain\RootPathPartAware;
use App\UI\UserInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

final class Kernel
{
    /** @var string */
    private $projectDir;

    /** @var string */
    private $configDir;

    /**
     * @param string $projectDir
     */
    public function __construct(string $projectDir)
    {
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $this->configDir = $projectDir.DIRECTORY_SEPARATOR.'config';
    }

    /**
     * @param \App\UI\UserInterface $ui
     * @param string $singleConfigFilePath
     *
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function __invoke(UserInterface $ui, $singleConfigFilePath = ''): void
    {
        $configFilesPaths = !empty($singleConfigFilePath)
            ? [$this->getConfigAbsoluteFilePath($singleConfigFilePath)]
            : $this->getAllConfigFilesPaths();

        foreach ($configFilesPaths as $configFilePath) {
            $ui->getSymfonyStyle()->section(
                sprintf(
                    'Processing configuration file "<info>%s</info>"... ',
                    $this->getConfigRelativeFilePath($configFilePath)
                )
            );

            $config = (array) Yaml::parseFile($configFilePath);
            $config['enabled'] = $config['enabled'] ?? true;

            if (!$config['enabled']) {
                $ui->getSymfonyStyle()->note('Skipped.');
                continue;
            }

            $rootPathPart = $this->getRootPathPart($config['path_part'] ?? []);
            $contents = new Contents([]);

            foreach ((array) $config['sources'] as $sources) {
                foreach ((array) $sources as $sourceClassName => $sourceConfig) {

                    /** @var \App\Domain\Source $source */
                    $source = new $sourceClassName($ui, $sourceConfig ?? []);
                    $this->illuminateObjectWithAwareness($source, $rootPathPart);

                    // Add the root path part to the contents' path
                    $sourceContents = $source->getContents()
                        ->map(function (Content $content) use ($rootPathPart) {
                            $content->getPath()->add($rootPathPart);

                            return $content;
                        });

                    foreach ($sourceContents as $content) {
                        $contents->add($content);
                    }
                }
            }

            foreach ((array) $config['processors'] as $processors) {
                foreach ((array) $processors as $processorClassName => $processorConfig) {

                    /** @var \App\Domain\ContentsProcessor $processor */
                    $processor = new $processorClassName($ui, $processorConfig ?? []);
                    $this->illuminateObjectWithAwareness($processor, $rootPathPart);
                    $processor->processContents(clone $contents);
                }
            }
        }
    }

    /**
     * @return array
     */
    private function getAllConfigFilesPaths(): array
    {
        $paths = [];

        foreach ((new Finder())->in($this->configDir)->files()->sortByName()->name('*.yml')->getIterator() as $file) {
            $paths[] = $file->getRealPath();
        }

        return $paths;
    }

    /**
     * @param array $config
     *
     * @return \App\Domain\PathPart
     *
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    private function getRootPathPart(array $config): PathPart
    {
        $config['substitutions'] = ['%project_root%' => $this->projectDir] + ($config['substitutions'] ?? []);
        $rootPathPart = new PathPart($config);

        // Try to create the root directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir($rootPathPart->getPath());

        return $rootPathPart;
    }

    /**
     * @param string $configFilePath
     *
     * @return string
     */
    private function getConfigRelativeFilePath(string $configFilePath): string
    {
        return (string) str_replace($this->projectDir.DIRECTORY_SEPARATOR, '', $configFilePath);
    }

    /**
     * @param string $configFilePath
     *
     * @return string
     * @throws \RuntimeException
     */
    private function getConfigAbsoluteFilePath(string $configFilePath): string
    {
        if (DIRECTORY_SEPARATOR === $configFilePath{0}) {
            return $configFilePath;
        }

        return $this->projectDir.DIRECTORY_SEPARATOR.$configFilePath;
    }

    /**
     * @param object $object
     * @param PathPart $rootPathPart
     */
    private function illuminateObjectWithAwareness($object, PathPart $rootPathPart): void
    {
        if ($object instanceof RootPathPartAware) {
            $object->setRootPathPart($rootPathPart);
        }
        if ($object instanceof ProjectRootPathAware) {
            $object->setProjectRootPath(new Path([new PathPart(['path' => $this->projectDir])]));
        }
    }
}
