<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Event;

use Te4g\UserBundle\Contract\AccountUserInterface;

final readonly class BeforeAccountDeletedEvent
{
    public function __construct(private AccountUserInterface $user)
    {
    }

    public function getUser(): AccountUserInterface
    {
        return $this->user;
    }
}
