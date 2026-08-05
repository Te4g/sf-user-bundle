<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Te4g\UserBundle\Tests\Fixtures\Persistence\TestUser;
use Te4g\UserBundle\Tests\Fixtures\Persistence\TestUserRepository;

final class BundleWebTestCaseTest extends BundleWebTestCase
{
    public function testSchemaSupportsTheTestUserRepository(): void
    {
        $repository = self::getContainer()->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);
        $user = new TestUser();
        $user->setEmail('user@example.test');

        $repository->save($user);

        self::assertSame($user, $repository->findOneByEmail(' USER@example.test '));
        self::assertSame(1, $user->getId());
    }

    public function testRepositoryActsAsTheSecurityUserProvider(): void
    {
        $repository = self::getContainer()->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);
        $user = new TestUser();
        $user->setEmail('user@example.test');
        $repository->save($user);

        $provider = self::getContainer()->get(UserProviderInterface::class);
        self::assertInstanceOf(UserProviderInterface::class, $provider);

        self::assertSame($user, $provider->loadUserByIdentifier('user@example.test'));
    }

    public function testSchemaResetRemovesStateFromAReusedDatabase(): void
    {
        $repository = self::getContainer()->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);
        $user = new TestUser();
        $user->setEmail('stale@example.test');
        $repository->save($user);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $failure = null;
        try {
            $this->resetSchema($entityManager);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        self::assertNull($failure, $failure?->getMessage() ?? 'The reused database schema was reset.');
        self::assertNull($repository->findOneByEmail('stale@example.test'));
    }
}
