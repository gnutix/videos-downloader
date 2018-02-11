<?php declare(strict_types=1);

namespace App\Platform\YouTube\Exception;

use YoutubeDl\Exception\YoutubeDlException;

final class VideoUnavailableException extends YoutubeDlException
{
}
