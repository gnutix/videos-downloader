<?php declare(strict_types=1);

namespace Extension\YouTube\Exception;

use YoutubeDl\Exception\YoutubeDlException;

final class VideoBlockedByCopyrightException extends YoutubeDlException
{
}
