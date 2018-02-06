<?php declare(strict_types=1);

namespace App\Platform;

use App\Cli\IOHelper;

interface Platform
{
    /**
     * @param IOHelper $ioHelper
     * @param array $options
     */
    public function __construct(IOHelper $ioHelper, array $options);

    /**
     * @param $input
     *
     * @return string[][]
     */
    public function extractVideosIds($input): array;

    /**
     * @param \App\Domain\VideoDownload[] $videoDownloads
     */
    public function downloadVideos(array $videoDownloads);
}
