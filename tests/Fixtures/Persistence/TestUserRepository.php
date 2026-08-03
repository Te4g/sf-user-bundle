<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Persistence;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Exception\AccountDeletionRejected;
use Te4g\UserBundle\Util\EmailNormalizer;

/**
 * @extends ServiceEntityRepository<TestUser>
 *
 * @implements UserProviderInterface<TestUser>
 */
final class TestUserRepository extends ServiceEntityRepository implements AccountRepositoryInterface, UserProviderInterface
{
    private bool $removeShouldFail = false;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TestUser::class);
    }

    public function findOneByEmail(string $email): ?AccountUserInterface
    {
        return $this->findOneBy(['email' => (new EmailNormalizer())->normalize($email)]);
    }

    public function findById(int|string $id): ?AccountUserInterface
    {
        return $this->find($id);
    }

    public function save(AccountUserInterface $user): void
    {
        $this->entityManager()->persist($this->testUser($user));
        $this->entityManager()->flush();
    }

    public function remove(AccountUserInterface $user): void
    {
        if ($this->removeShouldFail) {
            $this->removeShouldFail = false;

            throw new AccountDeletionRejected('test_repository_rejected_deletion');
        }

        $this->entityManager()->remove($this->testUser($user));
        $this->entityManager()->flush();
    }

    public function failNextRemove(): void
    {
        $this->removeShouldFail = true;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->findOneByEmail($identifier);

        if ($user instanceof TestUser) {
            return $user;
        }

        $exception = new UserNotFoundException();
        $exception->setUserIdentifier($identifier);

        throw $exception;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof TestUser) {
            throw new UnsupportedUserException('The test repository only refreshes TestUser instances.');
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return TestUser::class === $class || is_subclass_of($class, TestUser::class);
    }

    private function entityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        return $this->getEntityManager();
    }

    private function testUser(AccountUserInterface $user): TestUser
    {
        if (!$user instanceof TestUser) {
            throw new \InvalidArgumentException('The test repository only persists TestUser instances.');
        }

        return $user;
    }
}
