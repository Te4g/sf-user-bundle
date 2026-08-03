<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Event;

use Te4g\UserBundle\Contract\AccountUserInterface;

final readonly class PasswordChangedEvent
{
    public function __construct(
        private AccountUserInterface $user,
        private PasswordChangeOrigin $origin,
    ) {
    }

    public function getUser(): AccountUserInterface
    {
        return $this->user;
    }

    public function getOrigin(): PasswordChangeOrigin
    {
        return $this->origin;
    }
}
