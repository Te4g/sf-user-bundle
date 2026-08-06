<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class GeneratedTestInfrastructureRuntimeTest extends TestCase
{
    public function testGeneratedLoggerCapturesFullContextAndOwnsRootRequestAndSecurityServices(): void
    {
        $resources = dirname(__DIR__, 3).'/resources/application';
        require_once $resources.'/tests/Functional/ObservableTestLogger.php';
        $kernel = new GeneratedTask10InfrastructureKernel($resources);

        try {
            $kernel->boot();
            $logger = $kernel->getContainer()->get('test.observable_logger');
            $loggerClass = 'App\Tests\Functional\ObservableTestLogger';
            self::assertSame($loggerClass, $logger::class);
            (new \ReflectionMethod($loggerClass, 'clear'))->invoke(null);
            $stream = fopen('php://memory', 'r');
            self::assertIsResource($stream);
            try {
                (new \ReflectionMethod($loggerClass, 'info'))->invoke(
                    $logger,
                    'application security runtime probe',
                    [
                        'password' => 'submitted-secret',
                        'exception' => new \RuntimeException('context-boom'),
                        'stringable' => new class () implements \Stringable {
                            public function __toString(): string
                            {
                                return 'stringable-value';
                            }
                        },
                        'object' => new \stdClass(),
                        'stream' => $stream,
                        'nested' => ['child' => ['key' => 'nested-value']],
                        'long' => str_repeat('x', 3000),
                    ],
                );
            } finally {
                fclose($stream);
            }
            self::assertSame(1, (new \ReflectionMethod($loggerClass, 'count'))->invoke(null));
            $contents = (new \ReflectionMethod($loggerClass, 'contents'))->invoke(null);
            self::assertIsString($contents);
            foreach (['submitted-secret', 'context-boom', 'stringable-value', 'stdClass', 'stream', 'nested-value', '[truncated]'] as $expected) {
                self::assertStringContainsString($expected, $contents, $expected);
            }
            self::assertLessThan(5000, strlen($contents));

            (new \ReflectionMethod($loggerClass, 'clear'))->invoke(null);
            (new \ReflectionMethod($loggerClass, 'info'))->invoke($logger, 'ordering probe', ['z' => 'last', 'a' => 'first']);
            $firstOrder = (new \ReflectionMethod($loggerClass, 'contents'))->invoke(null);
            (new \ReflectionMethod($loggerClass, 'clear'))->invoke(null);
            (new \ReflectionMethod($loggerClass, 'info'))->invoke($logger, 'ordering probe', ['a' => 'first', 'z' => 'last']);
            self::assertSame($firstOrder, (new \ReflectionMethod($loggerClass, 'contents'))->invoke(null));

            self::assertSame($loggerClass, $kernel->getContainer()->get('test.request_logger')::class);
            self::assertSame($loggerClass, $kernel->getContainer()->get('test.security_logger')::class);
        } finally {
            $kernel->shutdown();
            $kernel->cleanup();
            restore_exception_handler();
        }
    }

    public function testFilesystemLimiterPoolSurvivesSuccessiveBrowserKernelRebootsUntilExplicitlyCleared(): void
    {
        $resources = dirname(__DIR__, 3).'/resources/application';
        require_once $resources.'/tests/Functional/ObservableTestLogger.php';
        $kernel = new GeneratedTask10InfrastructureKernel($resources);

        try {
            $kernel->boot();
            $pool = $kernel->getContainer()->get('test.account_rate_limiter_pool');
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            self::assertTrue($pool->clear());
            $client = new KernelBrowser($kernel);

            for ($attempt = 1; $attempt <= 5; ++$attempt) {
                $client->request('POST', '/task-10/limited');
                self::assertSame(
                    204,
                    $client->getResponse()->getStatusCode(),
                    'attempt '.$attempt.' '.(string) $client->getResponse()->getContent(),
                );
            }

            $client->request('POST', '/task-10/limited');
            self::assertSame(429, $client->getResponse()->getStatusCode());

            $pool = $kernel->getContainer()->get('test.account_rate_limiter_pool');
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            self::assertTrue($pool->clear());
            $client->request('POST', '/task-10/limited');
            self::assertSame(204, $client->getResponse()->getStatusCode());
        } finally {
            $kernel->shutdown();
            $kernel->cleanup();
            restore_exception_handler();
        }
    }

    public function testGeneratedChannelDecoratorsSurviveLateMonologStyleChannelDefinitions(): void
    {
        $resources = dirname(__DIR__, 3).'/resources/application';
        require_once $resources.'/tests/Functional/ObservableTestLogger.php';
        $kernel = new GeneratedTask10InfrastructureKernel($resources, true);

        try {
            $kernel->boot();
            $loggerClass = 'App\\Tests\\Functional\\ObservableTestLogger';
            self::assertSame($loggerClass, $kernel->getContainer()->get('test.request_logger')::class);
            self::assertSame($loggerClass, $kernel->getContainer()->get('test.security_logger')::class);
        } finally {
            $kernel->shutdown();
            $kernel->cleanup();
            restore_exception_handler();
        }
    }
}

final class GeneratedTask10InfrastructureKernel extends Kernel
{
    use MicroKernelTrait;

    private string $temporaryDirectory;

    public function __construct(
        private readonly string $applicationResources,
        private readonly bool $simulateMonologChannelPass = false,
    ) {
        $this->temporaryDirectory = sys_get_temp_dir().'/te4g-task-10-'.bin2hex(random_bytes(6));

        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle()];
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
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'task-10-runtime-secret',
                'test' => true,
                'router' => [
                    'resource' => __DIR__.'/task-10-runtime-routes.yaml',
                ],
            ]);
        });
        $loader->load($this->applicationResources.'/config/packages/account_rate_limiters.yaml');
        $loader->load($this->applicationResources.'/config/packages/test/account_tests.yaml');
        $loader->load(static function (ContainerBuilder $container): void {
            $container->setAlias('test.observable_logger', 'logger')->setPublic(true);
            $container->setAlias('test.request_logger', 'monolog.logger.request')->setPublic(true);
            $container->setAlias('test.security_logger', 'monolog.logger.security')->setPublic(true);
            $container->setAlias('test.account_rate_limiter_pool', 'cache.account_test_rate_limiter')->setPublic(true);
            $container->setAlias('test.account_registration_limiter', 'limiter.account_registration')->setPublic(true);
            $container->register(GeneratedTask10LimiterController::class)
                ->setArgument('$factory', new Reference('limiter.account_registration'))
                ->setPublic(true)
            ;
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($this->simulateMonologChannelPass) {
            $container->addCompilerPass(
                new GeneratedTask10LateChannelDefinitionPass(),
                PassConfig::TYPE_BEFORE_OPTIMIZATION,
            );
        }
    }
}

final class GeneratedTask10LateChannelDefinitionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach (['monolog.logger.request', 'monolog.logger.security'] as $service) {
            $container->setDefinition($service, (new Definition(\Psr\Log\NullLogger::class))->setPublic(true));
        }
    }
}

final class GeneratedTask10LimiterController
{
    public function __construct(private readonly RateLimiterFactoryInterface $factory)
    {
    }

    public function __invoke(): Response
    {
        $accepted = $this->factory->create('task-10-browser-repeat-run')->consume()->isAccepted();

        return new Response('', $accepted ? 204 : 429);
    }
}
