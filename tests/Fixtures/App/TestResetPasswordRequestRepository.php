<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\App;

use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

final class TestResetPasswordRequestRepository implements ResetPasswordRequestRepositoryInterface
{
    /** @var list<object> */
    public array $removedFor = [];

    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        throw new \LogicException('The in-memory helper creates requests directly.');
    }

    public function getUserIdentifier(object $user): string
    {
        return (string) spl_object_id($user);
    }

    public function persistResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
    }

    public function findResetPasswordRequest(string $selector): ?ResetPasswordRequestInterface
    {
        return null;
    }

    public function getMostRecentNonExpiredRequestDate(object $user): ?\DateTimeInterface
    {
        return null;
    }

    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
    }

    public function removeRequests(object $user): void
    {
        $this->removedFor[] = $user;
    }

    public function clearActivity(): void
    {
        $this->removedFor = [];
    }

    public function removeExpiredResetPasswordRequests(): int
    {
        return 0;
    }
}
