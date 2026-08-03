<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\App;

use SymfonyCasts\Bundle\ResetPassword\Exception\InvalidResetPasswordTokenException;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;

final class TestResetPasswordHelper implements ResetPasswordHelperInterface
{
    /** @var array<string, int|string> */
    private array $tokens = [];

    public function __construct(private readonly AccountRepositoryInterface $repository)
    {
    }

    public function generateResetToken(object $user): ResetPasswordToken
    {
        $token = bin2hex(random_bytes(16));
        if (!$user instanceof AccountUserInterface || null === $user->getId()) {
            throw new \LogicException('Reset test users must expose a persisted identifier.');
        }

        $this->tokens[$token] = $user->getId();

        return new ResetPasswordToken($token, new \DateTimeImmutable('+1 hour'), time());
    }

    public function validateTokenAndFetchUser(string $fullToken): object
    {
        $id = $this->tokens[$fullToken] ?? throw new InvalidResetPasswordTokenException();

        return $this->repository->findById($id) ?? throw new InvalidResetPasswordTokenException();
    }

    public function removeResetRequest(string $fullToken): void
    {
        if (!isset($this->tokens[$fullToken])) {
            throw new InvalidResetPasswordTokenException();
        }

        unset($this->tokens[$fullToken]);
    }

    public function getTokenLifetime(): int
    {
        return 3600;
    }
}
