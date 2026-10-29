<?php
declare(strict_types=1);

namespace Mea\Core;

final class HttpException extends \RuntimeException
{
    public static function notFound(): self
    {
        return new self('Seite nicht gefunden', 404);
    }
}
