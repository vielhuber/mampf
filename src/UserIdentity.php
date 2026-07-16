<?php
declare(strict_types=1);

namespace Mampf;

final readonly class UserIdentity
{
    public function __construct(public string $id, public string $email) {}
}
