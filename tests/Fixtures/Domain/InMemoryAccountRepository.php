<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Domain;

use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Util\EmailNormalizer;

final class InMemoryAccountRepository implements AccountRepositoryInterface
{
    /** @var list<AccountUserInterface> */
    public array $saved = [];

    /** @var list<AccountUserInterface> */
    public array $removed = [];

    /** @var array<string, AccountUserInterface> */
    private array $usersByEmail = [];

    /** @var array<string, AccountUserInterface> */
    private array $usersById = [];

    public function __construct(
        private readonly bool $failOnSave = false,
        private readonly bool $failOnRemove = false,
    ) {
    }

    public function findOneByEmail(string $email): ?AccountUserInterface
    {
        return $this->usersByEmail[(new EmailNormalizer())->normalize($email)] ?? null;
    }

    public function findById(int|string $id): ?AccountUserInterface
    {
        return $this->usersById[(string) $id] ?? null;
    }

    public function save(AccountUserInterface $user): void
    {
        $this->saved[] = $user;

        if ($this->failOnSave) {
            throw new \RuntimeException('The in-memory repository is configured to fail on save.');
        }

        $this->usersByEmail[(new EmailNormalizer())->normalize($user->getEmail())] = $user;

        if (null !== $user->getId()) {
            $this->usersById[(string) $user->getId()] = $user;
        }
    }

    public function remove(AccountUserInterface $user): void
    {
        $this->removed[] = $user;

        if ($this->failOnRemove) {
            throw new \RuntimeException('The in-memory repository is configured to fail on remove.');
        }

        unset($this->usersByEmail[(new EmailNormalizer())->normalize($user->getEmail())]);

        if (null !== $user->getId()) {
            unset($this->usersById[(string) $user->getId()]);
        }
    }
}
