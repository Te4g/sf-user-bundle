<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Event;

final readonly class AfterAccountDeletedEvent
{
    public function __construct(
        private string $accountId,
        private string $email,
    ) {
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}
