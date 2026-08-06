<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class SelfAdministrationDoctrineTest extends TestCase
{
    private string $databasePath;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->requireApplicationClasses();
        $this->databasePath = sys_get_temp_dir().'/te4g-self-administration-'.bin2hex(random_bytes(8)).'.sqlite';
        $this->entityManager = $this->newEntityManager();

        $metadata = [
            $this->entityManager->getClassMetadata($this->applicationClass('Entity\\User')),
            $this->entityManager->getClassMetadata($this->applicationClass('Entity\\ResetPasswordRequest')),
        ];
        (new SchemaTool($this->entityManager))->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        if (isset($this->databasePath) && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function testOlderConfirmationCannotReplaceANewerPendingEmail(): void
    {
        $user = $this->persistedUser('old@example.com', 'ORIGINAL_HASH', 'a@example.com');
        $userId = $this->userId($user);
        $helper = new TransitionVerifyEmailHelper(function () use ($userId): void {
            $concurrentEntityManager = $this->newEntityManager();
            $concurrentUser = $concurrentEntityManager->find($this->applicationClass('Entity\\User'), $userId);
            self::assertIsObject($concurrentUser);
            (new \ReflectionMethod($concurrentUser, 'setPendingEmail'))->invoke($concurrentUser, 'b@example.com');
            $concurrentEntityManager->flush();
            $concurrentEntityManager->close();
        });

        $failure = null;
        $service = $this->profileService($helper);
        try {
            (new \ReflectionMethod($service, 'confirmEmailChange'))
                ->invoke($service, 'https://example.test/confirm-a', $userId);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertNotNull($failure, 'A superseded email-change confirmation must fail.');
        self::assertSame('Account update failed.', $failure->getMessage());
        $stored = $this->reloadedUser($userId);
        self::assertSame('old@example.com', $this->userValue($stored, 'getEmail'));
        self::assertSame('b@example.com', $this->userValue($stored, 'getPendingEmail'));
    }

    public function testPasswordChangeRollsBackWhenResetRequestCleanupFails(): void
    {
        $user = $this->persistedUser('owner@example.com', 'ORIGINAL_HASH');
        $userId = $this->userId($user);
        $this->dropResetRequestTable();

        $failure = null;
        $service = $this->passwordService();
        try {
            (new \ReflectionMethod($service, 'change'))
                ->invoke($service, $user, 'valid-current-password', 'new-password');
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        self::assertNotNull($failure, 'Reset-request cleanup must fail after its table is removed.');
        $stored = $this->reloadedUser($userId);
        self::assertSame('ORIGINAL_HASH', $this->userValue($stored, 'getPassword'));
    }

    public function testEmailPromotionRollsBackWhenResetRequestCleanupFails(): void
    {
        $user = $this->persistedUser('old@example.com', 'ORIGINAL_HASH', 'new@example.com');
        $userId = $this->userId($user);
        $this->dropResetRequestTable();
        $service = $this->profileService(new TransitionVerifyEmailHelper());

        $failure = null;
        try {
            (new \ReflectionMethod($service, 'confirmEmailChange'))
                ->invoke($service, 'https://example.test/confirm-new', $userId);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        self::assertNotNull($failure, 'Reset-request cleanup must fail after its table is removed.');
        $stored = $this->reloadedUser($userId);
        self::assertSame('old@example.com', $this->userValue($stored, 'getEmail'));
        self::assertSame('new@example.com', $this->userValue($stored, 'getPendingEmail'));
    }

    private function persistedUser(string $email, string $password, ?string $pendingEmail = null): object
    {
        $user = $this->newApplicationInstance('Entity\\User');
        (new \ReflectionMethod($user, 'setName'))->invoke($user, 'Owner');
        (new \ReflectionMethod($user, 'setEmail'))->invoke($user, $email);
        (new \ReflectionMethod($user, 'setPassword'))->invoke($user, $password);
        (new \ReflectionMethod($user, 'setPendingEmail'))->invoke($user, $pendingEmail);
        $repository = $this->userRepository($this->entityManager);
        (new \ReflectionMethod($repository, 'save'))->invoke($repository, $user);

        return $user;
    }

    private function profileService(VerifyEmailHelperInterface $helper): object
    {
        $arguments = [
            $this->userRepository($this->entityManager),
            $this->newApplicationInstance('Account\\EmailNormalizer'),
            $helper,
            $this->newApplicationInstance('Mailer\\AccountMailer', $this->createStub(MailerInterface::class)),
            $this->resetRequestRepository($this->entityManager),
        ];
        $service = new \ReflectionClass($this->applicationClass('Account\\ProfileService'));
        if (count($service->getConstructor()?->getParameters() ?? []) > count($arguments)) {
            $arguments[] = $this->entityManager;
        }

        return $service->newInstanceArgs($arguments);
    }

    private function passwordService(): object
    {
        $arguments = [
            $this->userRepository($this->entityManager),
            $this->resetRequestRepository($this->entityManager),
            new TransitionPasswordHasher(),
        ];
        $service = new \ReflectionClass($this->applicationClass('Account\\PasswordService'));
        if (count($service->getConstructor()?->getParameters() ?? []) > count($arguments)) {
            $arguments[] = $this->entityManager;
        }

        return $service->newInstanceArgs($arguments);
    }

    private function userRepository(EntityManagerInterface $entityManager): object
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return $this->newApplicationInstance(
            'Repository\\UserRepository',
            $registry,
            $this->newApplicationInstance('Account\\EmailNormalizer'),
        );
    }

    private function resetRequestRepository(EntityManagerInterface $entityManager): object
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return $this->newApplicationInstance('Repository\\ResetPasswordRequestRepository', $registry);
    }

    private function dropResetRequestTable(): void
    {
        $metadata = $this->entityManager->getClassMetadata($this->applicationClass('Entity\\ResetPasswordRequest'));
        $table = $this->entityManager->getConnection()->quoteSingleIdentifier($metadata->getTableName());
        $this->entityManager->getConnection()->executeStatement('DROP TABLE '.$table);
    }

    private function reloadedUser(int $userId): object
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        $reloadedEntityManager = $this->newEntityManager();
        $stored = $reloadedEntityManager->find($this->applicationClass('Entity\\User'), $userId);
        self::assertIsObject($stored);
        $this->entityManager = $reloadedEntityManager;

        return $stored;
    }

    private function userId(object $user): int
    {
        $id = (new \ReflectionMethod($user, 'getId'))->invoke($user);
        self::assertIsInt($id);

        return $id;
    }

    private function userValue(object $user, string $method): mixed
    {
        return (new \ReflectionMethod($user, $method))->invoke($user);
    }

    private function newEntityManager(): EntityManagerInterface
    {
        $configuration = ORMSetup::createAttributeMetadataConfig([$this->applicationResources().'/src/Entity'], true);
        $configuration->enableNativeLazyObjects(true);

        return new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->databasePath], $configuration),
            $configuration,
        );
    }

    private function requireApplicationClasses(): void
    {
        foreach ([
            'src/Account/EmailNormalizer.php',
            'src/Entity/User.php',
            'src/Entity/ResetPasswordRequest.php',
            'src/Repository/UserRepository.php',
            'src/Repository/ResetPasswordRequestRepository.php',
            'src/Mailer/AccountMailer.php',
            'src/Account/ProfileService.php',
            'src/Account/PasswordService.php',
        ] as $path) {
            $resource = $this->applicationResources().'/'.$path;
            self::assertFileExists($resource);
            require_once $resource;
        }
    }

    private function newApplicationInstance(string $suffix, mixed ...$arguments): object
    {
        return (new \ReflectionClass($this->applicationClass($suffix)))->newInstance(...$arguments);
    }

    /** @return class-string */
    private function applicationClass(string $suffix): string
    {
        $class = 'App\\'.$suffix;
        if (!class_exists($class)) {
            throw new \LogicException(sprintf('Application class "%s" was not loaded.', $class));
        }

        return $class;
    }

    private function applicationResources(): string
    {
        return dirname(__DIR__, 3).'/resources/application';
    }
}

final class TransitionVerifyEmailHelper implements VerifyEmailHelperInterface
{
    public function __construct(private readonly ?\Closure $duringValidation = null)
    {
    }

    /** @param array<string, mixed> $extraParams */
    public function generateSignature(string $routeName, string $userId, string $userEmail, array $extraParams = []): VerifyEmailSignatureComponents
    {
        return new VerifyEmailSignatureComponents(new \DateTimeImmutable('+1 hour'), 'https://example.test/signed', time());
    }

    public function validateEmailConfirmation(string $signedUrl, string $userId, string $userEmail): void
    {
        ($this->duringValidation ?? static function (): void {
        })();
    }
}

final class TransitionPasswordHasher implements UserPasswordHasherInterface
{
    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        return 'NEW_HASH';
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        return true;
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return false;
    }
}
