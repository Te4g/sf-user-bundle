<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Domain;

use Te4g\UserBundle\Contract\AccountDeletionGuardInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Exception\AccountDeletionRejected;

final class SwitchableAccountDeletionGuard implements AccountDeletionGuardInterface
{
    private bool $rejectNextDeletion = false;

    public function rejectNextDeletion(): void
    {
        $this->rejectNextDeletion = true;
    }

    public function assertDeletionAllowed(AccountUserInterface $user): void
    {
        if (!$this->rejectNextDeletion) {
            return;
        }

        $this->rejectNextDeletion = false;

        throw new AccountDeletionRejected('test_guard_rejected_deletion');
    }
}
