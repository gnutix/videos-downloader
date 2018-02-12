<?php declare(strict_types=1);

namespace App;

use App\Domain\Content;
use App\Domain\PathPart;
use App\UI\UserInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class Kernel
{
    /**
     * @param \App\UI\UserInterface $ui
     *
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function __invoke(UserInterface $ui): void
    {
        $config = (array) Yaml::parseFile($this->getProjectDir().DIRECTORY_SEPARATOR.'config/app.yml');
        $rootPathPart = $this->getRootPathPart($config['path_part']);

        foreach ((array) $config['sources'] as $sources) {
            foreach ((array) $sources as $sourceClassName => $sourceData) {

                /** @var \App\Domain\Source $source */
                $source = new $sourceClassName($ui, $sourceData['config'] ?? []);

                // Add the root path part to the contents' path
                $contents = $source->getContents()
                    ->map(function (Content $content) use ($rootPathPart) {
                        $content->getPath()->add($rootPathPart);

                        return $content;
                    });

                foreach ((array) $sourceData['platforms'] as $platforms) {
                    foreach ((array) $platforms as $platformClassName => $platformData) {

                        /** @var \App\Domain\Platform $platform */
                        $platform = new $platformClassName($ui, $platformData['config'] ?? []);
                        $platform->synchronizeContents($contents, $rootPathPart);
                    }
                }
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
     * @param array $config
     *
     * @return \App\Domain\PathPart
     *
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    private function getRootPathPart(array $config): PathPart
    {
        $config['substitutions'] = [
                '%project_root%' => $this->getProjectDir()
            ] + ($config['substitutions'] ?? []);
        $rootPathPart = new PathPart($config);

        // Try to create the root directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir($rootPathPart->getPath());

        return $rootPathPart;
    }
}
