<?php declare(strict_types=1);

namespace App;

final class Kernel
{
    /**
     * @return string
     *
     * @throws \RuntimeException
     */
    public static function getProjectRootPath(): string
    {
        if (!($projectRoot = getenv('PROJECT_ROOT'))) {
            throw new \RuntimeException('The environment variable "PROJECT_ROOT" has not been defined.');
        }

        return rtrim($projectRoot, DIRECTORY_SEPARATOR);
    }
}
