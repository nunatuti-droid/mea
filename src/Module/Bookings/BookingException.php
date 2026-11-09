<?php
declare(strict_types=1);

namespace Mea\Module\Bookings;

final class BookingException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(string $message, public readonly array $errors = [], public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
