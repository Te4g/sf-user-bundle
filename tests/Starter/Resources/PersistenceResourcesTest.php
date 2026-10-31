<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Process\Process;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;
use Symfony\Component\Yaml\Yaml;

final class PersistenceResourcesTest extends TestCase
{
    public function testUserResourceIsApplicationOwnedAndSubscriberChecksVerifiedState(): void
    {
        $source = $this->resource('src/Entity/User.php');

        self::assertStringContainsString('namespace App\\Entity;', $source);
        self::assertStringContainsString('PasswordAuthenticatedUserInterface', $source);
        self::assertStringContainsString('UserInterface', $source);
        self::assertStringContainsString("\$roles[] = 'ROLE_USER';", $source);
        self::assertStringContainsString("#[ORM\\UniqueConstraint(name: 'UNIQ_USER_EMAIL', fields: ['email'])]", $source);
        self::assertStringContainsString("#[UniqueEntity(fields: ['email'])]", $source);
        self::assertStringNotContainsString('Te4g', $source);
        self::assertStringContainsString(
            'if (!$user instanceof User || $user->isVerified())',
            $this->resource('src/Security/VerifiedAccountSubscriber.php'),
        );
    }

    public function testUserAndRepositoryExposeDirectApplicationContracts(): void
    {
        $user = $this->resource('src/Entity/User.php');
        foreach (['name', 'email', 'pendingEmail', 'roles', 'password', 'verified'] as $property) {
            self::assertMatchesRegularExpression('/private [^;]+ \\$'.preg_quote($property, '/').'\\b/', $user);
        }

        foreach ([
            'setName(string $name): self',
            'setEmail(string $email): self',
            'setPendingEmail(?string $email): self',
            'setPassword(string $password): self',
            'setVerified(bool $verified): self',
        ] as $signature) {
            self::assertStringContainsString($signature, $user);
        }

        $repository = $this->resource('src/Repository/UserRepository.php');
        self::assertStringContainsString('UserLoaderInterface', $repository);
        self::assertStringContainsString('loadUserByIdentifier(string $identifier): ?User', $repository);
        self::assertStringContainsString('findOneByNormalizedEmail(string $normalizedEmail): ?User', $repository);
        self::assertStringContainsString('save(User $user, bool $flush = true): void', $repository);
        self::assertStringContainsString('remove(User $user, bool $flush = true): void', $repository);
        self::assertStringNotContainsString('AccountUserInterface', $repository);
        self::assertStringNotContainsString('Te4g', $repository);
    }

    public function testSecurityUsesApplicationEntityProviderAndNativeLoginLinkControls(): void
    {
        $config = Yaml::parse($this->resource('config/packages/security.yaml'));
        $security = $config['security'];

        self::assertSame('App\\Entity\\User', $security['providers']['app_user_provider']['entity']['class']);
        self::assertArrayNotHasKey('property', $security['providers']['app_user_provider']['entity']);
        self::assertSame([
            'login_path' => 'app_login',
            'check_path' => 'app_login',
            'enable_csrf' => true,
            'default_target_path' => 'app_dashboard',
        ], $security['firewalls']['main']['form_login']);
        self::assertSame(
            ['limiter' => 'App\\Security\\LoginRequestRateLimiter'],
            $security['firewalls']['main']['login_throttling'],
        );
        self::assertSame([
            'check_route' => 'app_login_link_check',
            'signature_properties' => ['id', 'email', 'password'],
            'lifetime' => 600,
            'max_uses' => 1,
            'check_post_only' => true,
            'used_link_cache' => 'cache.account_login_links',
            'success_handler' => 'App\\Security\\MagicLinkAuthenticationSuccessHandler',
        ], $security['firewalls']['main']['login_link']);
        self::assertSame([
            ['path' => '^/dashboard', 'roles' => 'ROLE_USER'],
            ['path' => '^/account', 'roles' => 'ROLE_USER'],
        ], $security['access_control']);
    }

    public function testUnverifiedSubscriberUsesTheExactRouteAllowlistAfterAuthentication(): void
    {
        $source = $this->resource('src/Security/VerifiedAccountSubscriber.php');

        foreach ([
            "'app_check_email'",
            "'app_verify_email'",
            "'app_verify_resend'",
            "'app_login_link_check'",
            "'app_logout'",
        ] as $route) {
            self::assertStringContainsString($route, $source);
        }

        self::assertStringContainsString("KernelEvents::REQUEST => ['onKernelRequest', -10]", $source);
        self::assertStringContainsString("generate('app_check_email')", $source);
        self::assertStringNotContainsString('ROLE_', $source);
    }

    public function testEntityProviderLoadsNormalizedEmailThroughApplicationRepositoryOnSQLite(): void
    {
        $this->requireApplicationPersistenceClasses();

        $configuration = ORMSetup::createAttributeMetadataConfig([$this->applicationResources().'/src/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $entityManager = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration),
            $configuration,
        );
        $userClass = $this->applicationClass('Entity\\User');
        $metadata = $entityManager->getClassMetadata($userClass);
        (new SchemaTool($entityManager))->createSchema([$metadata]);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);
        $repository = $this->newApplicationInstance(
            'Repository\\UserRepository',
            $registry,
            $this->newApplicationInstance('Account\\EmailNormalizer'),
        );
        self::assertInstanceOf(UserLoaderInterface::class, $repository);

        $user = $this->newApplicationInstance('Entity\\User');
        (new \ReflectionMethod($user, 'setName'))->invoke($user, 'Owner');
        (new \ReflectionMethod($user, 'setEmail'))->invoke($user, 'owner@example.com');
        (new \ReflectionMethod($user, 'setPassword'))->invoke($user, 'hash');
        (new \ReflectionMethod($repository, 'save'))->invoke($repository, $user);

        self::assertNull($entityManager->createQuery('SELECT u FROM App\\Entity\\User u WHERE u.email = :email')
            ->setParameter('email', ' Owner@Example.COM ')
            ->getOneOrNullResult());

        $objectManager = $this->createStub(ObjectManager::class);
        $objectManager->method('getRepository')->willReturn($repository);
        $registry->method('getManager')->willReturn($objectManager);
        $provider = new EntityUserProvider($registry, $userClass);

        self::assertSame($user, $provider->loadUserByIdentifier(' Owner@Example.COM '));
    }

    public function testSymfonyLoginThrottlingConsumesTheNormalizedApplicationLimiter(): void
    {
        $this->requireApplicationPersistenceClasses();
        $this->requireApplicationClass('src/Security/LoginRequestRateLimiter.php');

        $factory = new RateLimiterFactory([
            'id' => 'account_login',
            'policy' => 'sliding_window',
            'limit' => 5,
            'interval' => '15 minutes',
        ], new InMemoryStorage());
        $limiter = $this->newApplicationInstance(
            'Security\\LoginRequestRateLimiter',
            $this->newApplicationInstance('Account\\EmailNormalizer'),
            $factory,
        );
        self::assertInstanceOf(RequestRateLimiterInterface::class, $limiter);

        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $listener = new LoginThrottlingListener($requestStack, $limiter);
        $authenticator = $this->createStub(AuthenticatorInterface::class);

        foreach ([' Owner@Example.COM ', 'owner@example.com', 'OWNER@EXAMPLE.COM', "\towner@example.com", 'owner@example.com '] as $identifier) {
            $passport = new SelfValidatingPassport(new UserBadge($identifier));
            $listener->checkPassport(new CheckPassportEvent($authenticator, $passport));
            $listener->onFailedLogin(new LoginFailureEvent(
                new BadCredentialsException(),
                $authenticator,
                $request,
                null,
                'main',
                $passport,
            ));
        }

        $this->expectException(TooManyLoginAttemptsAuthenticationException::class);
        $listener->checkPassport(new CheckPassportEvent(
            $authenticator,
            new SelfValidatingPassport(new UserBadge('owner@example.com')),
        ));
    }

    public function testAccountInfrastructureHasNamedLimitersAndOneHourLifetimes(): void
    {
        $rateLimiters = Yaml::parse($this->resource('config/packages/account_rate_limiters.yaml'));

        self::assertArrayHasKey('cache.account_login_links', $rateLimiters['framework']['cache']['pools']);
        self::assertSame([
            'account_login',
            'account_registration',
            'account_registration_ip',
            'account_magic_link',
            'account_password_reset',
            'account_verification_resend',
            'account_email_change',
        ], array_keys($rateLimiters['framework']['rate_limiter']));

        self::assertSame(
            ['lifetime' => 3600],
            Yaml::parse($this->resource('config/packages/verify_email.yaml'))['symfonycasts_verify_email'],
        );

        $reset = Yaml::parse($this->resource('config/packages/reset_password.yaml'));
        self::assertSame(3600, $reset['symfonycasts_reset_password']['lifetime']);
        self::assertSame(3600, $reset['symfonycasts_reset_password']['throttle_limit']);
        self::assertSame(
            'App\\Repository\\ResetPasswordRequestRepository',
            $reset['services']['SymfonyCasts\\Bundle\\ResetPassword\\Persistence\\ResetPasswordRequestRepositoryInterface']['alias'],
        );

        $doctrine = Yaml::parse($this->resource('config/packages/test/doctrine.yaml'));
        self::assertSame('sqlite:///%kernel.project_dir%/var/test.db', $doctrine['doctrine']['dbal']['url']);
    }

    public function testEveryPersistencePhpResourceLintsAndHasNoStarterRuntimeReference(): void
    {
        foreach ($this->phpResources() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringNotContainsString('Te4g', $source, $path);

            $lint = new Process([PHP_BINARY, '-l', $path]);
            $lint->run();

            self::assertTrue($lint->isSuccessful(), $lint->getErrorOutput().$lint->getOutput());
        }
    }

    private function resource(string $path): string
    {
        $source = file_get_contents($this->applicationResources().'/'.$path);
        self::assertIsString($source, $path);

        return $source;
    }

    private function requireApplicationPersistenceClasses(): void
    {
        $this->requireApplicationClass('src/Account/EmailNormalizer.php');
        $this->requireApplicationClass('src/Entity/User.php');
        $this->requireApplicationClass('src/Repository/UserRepository.php');
    }

    private function requireApplicationClass(string $path): void
    {
        $resource = $this->applicationResources().'/'.$path;
        self::assertFileExists($resource);
        require_once $resource;
    }

    private function newApplicationInstance(string $suffix, mixed ...$arguments): object
    {
        $class = $this->applicationClass($suffix);
        if (!class_exists($class)) {
            throw new \LogicException(sprintf('Application class "%s" was not loaded.', $class));
        }

        return (new \ReflectionClass($class))->newInstance(...$arguments);
    }

    private function applicationClass(string $suffix): string
    {
        return 'App\\'.$suffix;
    }

    /** @return list<string> */
    private function phpResources(): array
    {
        $paths = glob($this->applicationResources().'/{src/Entity,src/Repository,src/Account,src/Security}/*.php', GLOB_BRACE);
        self::assertIsArray($paths);
        sort($paths);

        return $paths;
    }

    private function applicationResources(): string
    {
        return dirname(__DIR__, 3).'/resources/application';
    }
}
