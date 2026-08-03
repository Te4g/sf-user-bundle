<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Persistence;

use Doctrine\ORM\Mapping as ORM;
use Te4g\UserBundle\Contract\AccountUserInterface;

#[ORM\Entity(repositoryClass: TestUserRepository::class)]
#[ORM\Table(name: 'test_users')]
class TestUser implements AccountUserInterface
{
    #[ORM\Column]
    #[ORM\GeneratedValue]
    #[ORM\Id]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = 'test@example.com';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $pendingEmail = null;

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column]
    private bool $verified = false;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function setId(int $id): void
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
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
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
