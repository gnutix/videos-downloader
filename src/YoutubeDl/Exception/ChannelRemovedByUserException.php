<?php declare(strict_types=1);

namespace App\YoutubeDl\Exception;

use YoutubeDl\Exception\YoutubeDlException;

final class ChannelRemovedByUserException extends YoutubeDlException implements CustomYoutubeDlException
{
}