<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

abstract class AccountWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        ObservableTestLogger::clear();
        $this->client = static::createClient();
        $entityManager = $this->entityManager();
        $entityManager->createQuery('DELETE FROM '.ResetPasswordRequest::class)->execute();
        $entityManager->createQuery('DELETE FROM '.User::class)->execute();
        $entityManager->clear();
    }

    protected function createUser(
        string $email,
        bool $verified = true,
        string $password = 'password',
    ): User {
        $user = (new User())
            ->setName('Test User')
            ->setEmail($email)
            ->setVerified($verified)
        ;

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user->setPassword($hasher->hashPassword($user, $password));

        $entityManager = $this->entityManager();
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    protected function user(string $email): ?User
    {
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository->findOneByNormalizedEmail(mb_strtolower(trim($email)));
    }

    protected function refreshUser(User $user): User
    {
        $id = $user->getId();
        self::assertNotNull($id);
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $refreshed = $entityManager->find(User::class, $id);
        self::assertInstanceOf(User::class, $refreshed);

        return $refreshed;
    }

    protected function emailLink(int $index = 0): string
    {
        $email = self::getMailerMessage($index);
        self::assertInstanceOf(Email::class, $email);

        return EmailLinkExtractor::from($email, 'localhost', 'http');
    }

    /** @param array<string, scalar> $parameters */
    protected function url(string $route, array $parameters = []): string
    {
        $router = static::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);

        return $router->generate($route, $parameters);
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    protected function csrfToken(string $tokenId): string
    {
        $csrf = static::getContainer()->get(CsrfTokenManagerInterface::class);
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $csrf);
        $requestStack = static::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push($this->client->getRequest());
        try {
            $token = $csrf->getToken($tokenId)->getValue();
        } finally {
            $requestStack->pop();
        }
        $this->client->getRequest()->getSession()->save();

        return $token;
    }

    protected function magicLink(User $user, int $lifetime): string
    {
        $handler = static::getContainer()->get(LoginLinkHandlerInterface::class);
        self::assertInstanceOf(LoginLinkHandlerInterface::class, $handler);
        $requestStack = static::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push($this->client->getRequest());
        try {
            $link = $handler->createLoginLink($user, $this->client->getRequest(), $lifetime)->getUrl();
        } finally {
            $requestStack->pop();
        }

        return $link;
    }

    protected function applicationLogs(): string
    {
        self::assertGreaterThan(0, ObservableTestLogger::count(), 'The test logger did not observe an application or security log entry.');

        return ObservableTestLogger::contents();
    }
}
