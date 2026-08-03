<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Contract;

interface AccountRepositoryInterface
{
    public function findOneByEmail(string $email): ?AccountUserInterface;

    public function findById(int|string $id): ?AccountUserInterface;

    public function save(AccountUserInterface $user): void;

    public function remove(AccountUserInterface $user): void;
}
