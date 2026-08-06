<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;
use Symfony\Component\Yaml\Yaml;

final class GeneratedSecurityRuntimeTest extends TestCase
{
    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testGeneratedContainerWiresNormalizedProviderAndRequestLoginLimiter(): void
    {
        $resources = dirname(__DIR__, 3).'/resources/application';
        foreach ([
            'src/Account/EmailNormalizer.php',
            'src/Entity/User.php',
            'src/Repository/UserRepository.php',
            'src/Security/LoginRequestRateLimiter.php',
        ] as $path) {
            require_once $resources.'/'.$path;
        }

        $kernel = new GeneratedTask5Kernel($resources);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            $entityManager = $container->get('test.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $userClass = 'App\\Entity\\User';
            $repositoryClass = 'App\\Repository\\UserRepository';
            $loginLimiterClass = 'App\\Security\\LoginRequestRateLimiter';
            $metadata = $entityManager->getClassMetadata($userClass);
            (new SchemaTool($entityManager))->createSchema([$metadata]);

            $repository = $container->get($repositoryClass);
            self::assertSame($repositoryClass, $repository::class);
            $user = (new \ReflectionClass($userClass))->newInstance();
            (new \ReflectionMethod($user, 'setName'))->invoke($user, 'Owner');
            (new \ReflectionMethod($user, 'setEmail'))->invoke($user, 'owner@example.com');
            (new \ReflectionMethod($user, 'setPassword'))->invoke($user, 'hash');
            (new \ReflectionMethod($repository, 'save'))->invoke($repository, $user);

            $provider = $container->get('test.app_user_provider');
            self::assertInstanceOf(EntityUserProvider::class, $provider);
            self::assertSame('owner@example.com', $provider->loadUserByIdentifier(' Owner@Example.COM ')->getUserIdentifier());

            $listener = $container->get('test.login_throttling_listener');
            $limiter = $container->get($loginLimiterClass);
            self::assertInstanceOf(LoginThrottlingListener::class, $listener);
            self::assertSame($loginLimiterClass, $limiter::class);
            self::assertSame(
                $limiter,
                (new \ReflectionProperty(LoginThrottlingListener::class, 'limiter'))->getValue($listener),
            );
        } finally {
            $kernel->shutdown();
            $kernel->cleanup();
            restore_exception_handler();
        }
    }
}

final class GeneratedTask5Kernel extends Kernel
{
    use MicroKernelTrait;

    private string $temporaryDirectory;

    public function __construct(private readonly string $applicationResources)
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/te4g-task-5-'.bin2hex(random_bytes(6));

        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new SecurityBundle(),
            new DoctrineBundle(),
        ];
    }

    public function getCacheDir(): string
    {
        return $this->temporaryDirectory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->temporaryDirectory.'/log';
    }

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $applicationResources = $this->applicationResources;
        $databasePath = $this->temporaryDirectory.'/test.db';

        $loader->load(static function (ContainerBuilder $container) use ($applicationResources, $databasePath): void {
            $accountInfrastructure = Yaml::parseFile($applicationResources.'/config/packages/account_rate_limiters.yaml');
            $security = Yaml::parseFile($applicationResources.'/config/packages/security.yaml');

            $container->loadFromExtension('framework', [
                'secret' => 'task-5-secret',
                'test' => true,
                'router' => [
                    'resource' => 'kernel::loadRoutes',
                    'type' => 'service',
                    'utf8' => true,
                ],
                'csrf_protection' => true,
                'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
                'cache' => $accountInfrastructure['framework']['cache'],
                'rate_limiter' => $accountInfrastructure['framework']['rate_limiter'],
            ]);
            $container->loadFromExtension('security', $security['security']);
            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'driver' => 'pdo_sqlite',
                    'path' => $databasePath,
                ],
                'orm' => [
                    'mappings' => [
                        'App' => [
                            'type' => 'attribute',
                            'dir' => $applicationResources.'/src/Entity',
                            'prefix' => 'App\\Entity',
                            'alias' => 'App',
                        ],
                    ],
                ],
            ]);

            $emailNormalizer = 'App\\Account\\EmailNormalizer';
            $userRepository = 'App\\Repository\\UserRepository';
            $loginRequestLimiter = 'App\\Security\\LoginRequestRateLimiter';

            $container->register($emailNormalizer, $emailNormalizer)
                ->setAutowired(true);
            $container->register($userRepository, $userRepository)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
            $container->register($loginRequestLimiter, $loginRequestLimiter)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
            $container->register(GeneratedMagicLinkSuccessHandler::class, GeneratedMagicLinkSuccessHandler::class);
            $container->setAlias('App\\Security\\MagicLinkAuthenticationSuccessHandler', GeneratedMagicLinkSuccessHandler::class);
            $container->setAlias('test.entity_manager', 'doctrine.orm.entity_manager')->setPublic(true);
            $container->setAlias('test.app_user_provider', 'security.user.provider.concrete.app_user_provider')->setPublic(true);
            $container->setAlias('test.login_throttling_listener', 'security.listener.login_throttling.main')->setPublic(true);
        });
    }

    #[Route('/', name: 'app_home')]
    #[Route('/login', name: 'app_login')]
    #[Route('/login-link/check', name: 'app_login_link_check')]
    #[Route('/logout', name: 'app_logout')]
    #[Route('/dashboard', name: 'app_dashboard')]
    public function page(): Response
    {
        return new Response();
    }
}

#[AsController]
final class GeneratedMagicLinkSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        return new Response();
    }
}
