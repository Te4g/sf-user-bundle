<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Yaml\Yaml;
use SymfonyCasts\Bundle\ResetPassword\SymfonyCastsResetPasswordBundle;
use SymfonyCasts\Bundle\VerifyEmail\SymfonyCastsVerifyEmailBundle;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Factory\ConfiguredAccountUserFactory;
use Te4g\UserBundle\Installer\ProjectLayout;
use Te4g\UserBundle\Installer\SkeletonRenderer;
use Te4g\UserBundle\Te4gUserBundle;

final class GeneratedSkeletonTest extends TestCase
{
    private const string RESET_REQUEST_CLASS = 'GeneratedApp\Entity\ResetPasswordRequest';
    private const string USER_CLASS = 'GeneratedApp\Entity\User';
    private const string USER_REPOSITORY_CLASS = 'GeneratedApp\Repository\UserRepository';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/te4g-generated-app-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testGeneratedApplicationLintsLoadsMetadataCompilesSecurityAndResolvesRoutes(): void
    {
        file_put_contents($this->projectDir.'/composer.json', <<<'JSON'
            {"autoload":{"psr-4":{"GeneratedApp\\":"./src/./"}}}
            JSON);
        $files = (new SkeletonRenderer())->renderAll(ProjectLayout::at($this->projectDir));
        $this->write($files);

        foreach ($files as $relativePath => $contents) {
            if (str_ends_with($relativePath, '.php')) {
                $lint = new Process([PHP_BINARY, '-l', $this->projectDir.'/'.$relativePath]);
                $lint->run();
                self::assertTrue($lint->isSuccessful(), $lint->getErrorOutput().$lint->getOutput());
            }

            if (str_ends_with($relativePath, '.yaml')) {
                self::assertIsArray(Yaml::parse($contents));
            }
        }

        $security = Yaml::parseFile($this->projectDir.'/config/packages/te4g_user_security.yaml');
        self::assertSame('none', $security['security']['expose_security_errors']);
        self::assertSame([
            'login_path' => 'te4g_user_login',
            'check_path' => 'te4g_user_login',
            'enable_csrf' => true,
            'default_target_path' => '%te4g_user.redirects.login_success%',
        ], $security['security']['firewalls']['main']['form_login']);
        self::assertSame([
            'max_attempts' => 5,
            'interval' => '15 minutes',
        ], $security['security']['firewalls']['main']['login_throttling']);
        self::assertSame([
            'check_route' => 'te4g_user_login_link_check',
            'signature_properties' => ['id', 'email', 'password'],
            'lifetime' => 600,
            'max_uses' => 1,
            'check_post_only' => true,
            'used_link_cache' => 'cache.te4g_user_login_links',
            'default_target_path' => '%te4g_user.redirects.login_success%',
        ], $security['security']['firewalls']['main']['login_link']);
        self::assertSame([
            'path' => 'te4g_user_logout',
            'enable_csrf' => true,
        ], $security['security']['firewalls']['main']['logout']);
        self::assertSame([
            'policy' => 'sliding_window',
            'limit' => 5,
            'interval' => '15 minutes',
        ], $security['framework']['rate_limiter']['te4g_user_account_requests']);
        self::assertArrayHasKey('cache.te4g_user_login_links', $security['framework']['cache']['pools']);
        self::assertSame([
            ['route' => 'te4g_user_register', 'roles' => 'PUBLIC_ACCESS'],
            ['route' => 'te4g_user_verify_email', 'roles' => 'PUBLIC_ACCESS'],
            ['route' => 'te4g_user_verify_resend', 'roles' => 'PUBLIC_ACCESS'],
            ['route' => 'te4g_user_login', 'roles' => 'PUBLIC_ACCESS'],
            ['route' => 'te4g_user_login_link_request', 'roles' => 'PUBLIC_ACCESS'],
            ['route' => 'te4g_user_logout', 'roles' => 'PUBLIC_ACCESS'],
            ['route' => 'te4g_user_login_link_check', 'roles' => 'PUBLIC_ACCESS'],
            ['route' => 'te4g_user_password_request', 'roles' => 'PUBLIC_ACCESS'],
            ['route' => 'te4g_user_password_reset', 'roles' => 'PUBLIC_ACCESS'],
            ['route' => 'te4g_user_check_email', 'roles' => 'PUBLIC_ACCESS'],
            ['route' => 'te4g_user_account', 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ['route' => 'te4g_user_profile', 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ['route' => 'te4g_user_email_change_confirm', 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ['route' => 'te4g_user_password_change', 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ['route' => 'te4g_user_account_delete', 'roles' => 'IS_AUTHENTICATED_FULLY'],
        ], $security['security']['access_control']);
        foreach ($security['security']['access_control'] as $rule) {
            self::assertArrayNotHasKey('path', $rule);
        }

        self::assertSame([
            'request_password_repository' => 'GeneratedApp\Repository\ResetPasswordRequestRepository',
            'lifetime' => 3600,
            'throttle_limit' => 3600,
            'enable_garbage_collection' => true,
        ], Yaml::parseFile($this->projectDir.'/config/packages/reset_password.yaml')['symfonycasts_reset_password']);
        self::assertSame(
            ['lifetime' => 3600],
            Yaml::parseFile($this->projectDir.'/config/packages/verify_email.yaml')['symfonycasts_verify_email'],
        );

        $this->requireGeneratedClasses();
        $kernel = new GeneratedSkeletonKernel($this->projectDir);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            self::assertSame('app_home', $container->getParameter('te4g_user.redirects.login_success'));

            $entityManager = $container->get('doctrine.orm.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
            self::assertEqualsCanonicalizing(
                [self::RESET_REQUEST_CLASS, self::USER_CLASS],
                array_map(static fn ($class): string => $class->getName(), $metadata),
            );
            $userMetadata = $entityManager->getClassMetadata(self::USER_CLASS);
            self::assertSame(180, $userMetadata->getFieldMapping('email')->length);
            self::assertFalse($userMetadata->getFieldMapping('email')->nullable);
            self::assertSame(180, $userMetadata->getFieldMapping('pendingEmail')->length);
            self::assertTrue($userMetadata->getFieldMapping('pendingEmail')->nullable);
            self::assertSame('json', $userMetadata->getFieldMapping('roles')->type);
            self::assertSame(255, $userMetadata->getFieldMapping('password')->length);
            self::assertSame('boolean', $userMetadata->getFieldMapping('verified')->type);
            self::assertArrayHasKey('UNIQ_USER_EMAIL', $userMetadata->table['uniqueConstraints'] ?? []);
            (new SchemaTool($entityManager))->createSchema($metadata);

            $repository = $container->get(self::USER_REPOSITORY_CLASS);
            self::assertInstanceOf(AccountRepositoryInterface::class, $repository);
            self::assertInstanceOf(PasswordUpgraderInterface::class, $repository);
            $user = (new ConfiguredAccountUserFactory(self::USER_CLASS))->create('  Owner@Example.COM ');
            $user->setPendingEmail(' NEXT@Example.COM ');
            $user->setPassword('$2y$plain-hash-must-not-be-in-session');
            $repository->save($user);

            self::assertNotNull($user->getId());
            $userId = $user->getId();
            self::assertSame('owner@example.com', $user->getUserIdentifier());
            self::assertSame('next@example.com', $user->getPendingEmail());
            self::assertSame($user, $repository->findOneByEmail(' OWNER@EXAMPLE.COM '));
            self::assertStringNotContainsString('$2y$plain-hash-must-not-be-in-session', serialize($user));
            $repository->upgradePassword($user, '$2y$upgraded-hash');
            $entityManager->clear();
            $reloaded = $repository->findById($userId);
            self::assertNotNull($reloaded);
            self::assertSame('$2y$upgraded-hash', $reloaded->getPassword());
            $repository->remove($reloaded);
            self::assertNull($repository->findOneByEmail('owner@example.com'));

            $router = $container->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);
            $bundleRoutes = [];
            foreach (array_keys($router->getRouteCollection()->all()) as $route) {
                if (str_starts_with($route, 'te4g_user_')) {
                    $bundleRoutes[] = $route;
                }
            }
            self::assertSame([
                'te4g_user_register',
                'te4g_user_verify_email',
                'te4g_user_verify_resend',
                'te4g_user_login',
                'te4g_user_login_link_request',
                'te4g_user_logout',
                'te4g_user_login_link_check',
                'te4g_user_password_request',
                'te4g_user_password_reset',
                'te4g_user_check_email',
                'te4g_user_account',
                'te4g_user_profile',
                'te4g_user_email_change_confirm',
                'te4g_user_password_change',
                'te4g_user_account_delete',
            ], $bundleRoutes);
        } finally {
            $kernel->shutdown();
            restore_exception_handler();
        }
    }

    /** @param array<string, string> $files */
    private function write(array $files): void
    {
        foreach ($files as $relativePath => $contents) {
            $path = $this->projectDir.'/'.$relativePath;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }
    }

    private function requireGeneratedClasses(): void
    {
        require_once $this->projectDir.'/src/Entity/User.php';
        require_once $this->projectDir.'/src/Entity/ResetPasswordRequest.php';
        require_once $this->projectDir.'/src/Repository/UserRepository.php';
        require_once $this->projectDir.'/src/Repository/ResetPasswordRequestRepository.php';
    }
}

final class GeneratedSkeletonKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $projectDirectory)
    {
        parent::__construct('generated_skeleton', false);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new SecurityBundle(),
            new TwigBundle(),
            new DoctrineBundle(),
            new SymfonyCastsVerifyEmailBundle(),
            new SymfonyCastsResetPasswordBundle(),
            new Te4gUserBundle(),
        ];
    }

    public function getCacheDir(): string
    {
        return $this->projectDirectory.'/var/cache';
    }

    public function getLogDir(): string
    {
        return $this->projectDirectory.'/var/log';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $projectDirectory = $this->projectDirectory;
        $loader->load(static function (ContainerBuilder $container) use ($projectDirectory): void {
            $container->loadFromExtension('framework', [
                'secret' => 'generated-skeleton-secret',
                'test' => true,
                'form' => ['enabled' => true],
                'validation' => ['enabled' => true],
                'csrf_protection' => true,
                'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
                'mailer' => ['dsn' => 'null://null'],
                'router' => [
                    'resource' => $projectDirectory.'/config/routes/te4g_user.yaml',
                    'type' => 'yaml',
                    'utf8' => true,
                ],
            ]);
            $container->loadFromExtension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
                'orm' => [
                    'mappings' => [
                        'GeneratedApp' => [
                            'type' => 'attribute',
                            'dir' => $projectDirectory.'/src/Entity',
                            'prefix' => 'GeneratedApp\\Entity',
                            'alias' => 'GeneratedApp',
                        ],
                    ],
                ],
            ]);
            $container->register('GeneratedApp\Repository\UserRepository', 'GeneratedApp\Repository\UserRepository')
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
            $container->register('GeneratedApp\Repository\ResetPasswordRequestRepository', 'GeneratedApp\Repository\ResetPasswordRequestRepository')
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        });

        // Deliberately load security first: the later application config must still
        // seed the redirect parameter before extension processing begins.
        $loader->load($this->projectDirectory.'/config/packages/te4g_user_security.yaml');
        $loader->load($this->projectDirectory.'/config/packages/te4g_user_twig.yaml');
        $loader->load($this->projectDirectory.'/config/packages/reset_password.yaml');
        $loader->load($this->projectDirectory.'/config/packages/verify_email.yaml');
        $loader->load($this->projectDirectory.'/config/packages/te4g_user.yaml');
    }
}
