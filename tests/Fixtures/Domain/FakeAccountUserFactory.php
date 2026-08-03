<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Domain;

use Te4g\UserBundle\Contract\AccountUserFactoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;

final class FakeAccountUserFactory implements AccountUserFactoryInterface
{
    private int $nextId = 1;

    public function create(string $normalizedEmail): AccountUserInterface
    {
        $user = new TestAccountUser();
        $user->setId($this->nextId++);
        $user->setEmail($normalizedEmail);

        return $user;
    }
}
