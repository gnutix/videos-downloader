<?php declare(strict_types=1);

namespace App\Source;

use App\Cli\IOHelper;
use App\Platform\Platform;

interface Source
{
    /**
     * @param \App\Cli\IOHelper $ioHelper
     * @param array $options
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(IOHelper $ioHelper, array $options);

    /**
     * @param \App\Platform\Platform $platform
     *
     * @return \App\Domain\VideoDownload[]
     */
    public function getVideos(Platform $platform): array;
}
