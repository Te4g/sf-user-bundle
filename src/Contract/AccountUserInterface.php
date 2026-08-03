<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Contract;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

interface AccountUserInterface extends PasswordAuthenticatedUserInterface, UserInterface
{
    public function getId(): int|string|null;

    public function getEmail(): string;

    public function setEmail(string $email): void;

    public function getPendingEmail(): ?string;

    public function setPendingEmail(?string $email): void;

    public function setPassword(string $password): void;

    public function isVerified(): bool;

    public function setVerified(bool $verified): void;

    /** @param list<string> $roles */
    public function setRoles(array $roles): void;
}
