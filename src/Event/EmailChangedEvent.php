<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Event;

use Te4g\UserBundle\Contract\AccountUserInterface;

final readonly class EmailChangedEvent
{
    public function __construct(
        private AccountUserInterface $user,
        private string $previousEmail,
    ) {
    }

    public function getUser(): AccountUserInterface
    {
        return $this->user;
    }

    public function getPreviousEmail(): string
    {
        return $this->previousEmail;
    }
}
