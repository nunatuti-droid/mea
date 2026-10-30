<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Module\Auth\Roles;

/** Angemeldeter Benutzer und seine Sitzung für die Dauer einer Anfrage. */
final class AdminContext
{
    /** @param array<string,mixed> $user @param array<string,mixed> $session */
    public function __construct(public readonly array $user, public readonly array $session, public readonly string $csrf) {}

    public function id(): int
    {
        return (int) $this->user['id'];
    }

    public function can(string $permission): bool
    {
        return Roles::can((string) $this->user['role'], $permission);
    }
}
