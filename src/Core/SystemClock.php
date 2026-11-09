<?php
declare(strict_types=1);

namespace Mea\Core;

use Psr\Clock\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function __construct(private ?\DateTimeImmutable $fixed = null) {}

    public function now(): \DateTimeImmutable
    {
        return $this->fixed ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
