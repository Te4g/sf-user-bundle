<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Contract;

interface AccountDeletionGuardInterface
{
    public function assertDeletionAllowed(AccountUserInterface $user): void;
}
