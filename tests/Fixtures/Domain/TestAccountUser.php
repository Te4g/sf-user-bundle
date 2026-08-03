<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Domain;

use Te4g\UserBundle\Contract\AccountUserInterface;

class TestAccountUser implements AccountUserInterface
{
    private int|string|null $id = null;

    private string $email = 'test@example.com';

    private ?string $password = null;

    private ?string $pendingEmail = null;

    private bool $verified = false;

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function setId(int|string|null $id): void
    {
        $this->id = $id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPendingEmail(): ?string
    {
        return $this->pendingEmail;
    }

    public function setPendingEmail(?string $email): void
    {
        $this->pendingEmail = $email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getUserIdentifier(): string
    {
        return '' === $this->email ? 'test@example.com' : $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [];
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
    }

    public function eraseCredentials(): void
    {
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): void
    {
        $this->verified = $verified;
    }
}
