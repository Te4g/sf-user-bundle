<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Domain;

use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;

final class TracingAccountRepository implements AccountRepositoryInterface
{
    /** @var list<AccountUserInterface> */
    public array $saved = [];

    /** @var array<string, AccountUserInterface> */
    private array $usersByEmail = [];

    /** @var array<string, AccountUserInterface> */
    private array $usersById = [];

    public function __construct(private readonly OperationTrace $trace)
    {
    }

    public function findOneByEmail(string $email): ?AccountUserInterface
    {
        $this->trace->record('lookup:'.$email);

        return $this->usersByEmail[$email] ?? null;
    }

    public function findById(int|string $id): ?AccountUserInterface
    {
        $this->trace->record('load:'.$id);

        return $this->usersById[(string) $id] ?? null;
    }

    public function save(AccountUserInterface $user): void
    {
        $this->trace->record('save');
        $this->saved[] = $user;
        $this->usersByEmail[$user->getEmail()] = $user;

        if (null !== $user->getId()) {
            $this->usersById[(string) $user->getId()] = $user;
        }
    }

    public function remove(AccountUserInterface $user): void
    {
    }
}
