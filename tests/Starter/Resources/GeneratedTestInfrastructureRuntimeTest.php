<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class GeneratedTestInfrastructureRuntimeTest extends TestCase
{
    public function testGeneratedTestLoggerAndLimiterOverridesCompileAndResetWithTheKernel(): void
    {
        $resources = dirname(__DIR__, 3).'/resources/application';
        require_once $resources.'/tests/Functional/ObservableTestLogger.php';
        $key = 'task-10-runtime-repeat-run';
        $first = new GeneratedTask10InfrastructureKernel($resources);
        $second = null;

        try {
            $first->boot();
            $logger = $first->getContainer()->get('test.observable_logger');
            $loggerClass = 'App\Tests\Functional\ObservableTestLogger';
            self::assertSame($loggerClass, $logger::class);
            (new \ReflectionMethod($loggerClass, 'clear'))->invoke(null);
            (new \ReflectionMethod($loggerClass, 'info'))->invoke($logger, 'application security runtime probe');
            self::assertSame(1, (new \ReflectionMethod($loggerClass, 'count'))->invoke(null));

            $factory = $first->getContainer()->get('test.account_registration_limiter');
            self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
            $limiter = $factory->create($key);
            for ($attempt = 1; $attempt <= 5; ++$attempt) {
                self::assertTrue($limiter->consume()->isAccepted());
            }
            self::assertFalse($limiter->consume()->isAccepted());

            $first->shutdown();
            $second = new GeneratedTask10InfrastructureKernel($resources);
            $second->boot();
            $freshFactory = $second->getContainer()->get('test.account_registration_limiter');
            self::assertInstanceOf(RateLimiterFactoryInterface::class, $freshFactory);
            self::assertTrue($freshFactory->create($key)->consume()->isAccepted());
        } finally {
            $first->shutdown();
            $first->cleanup();
            if ($second instanceof GeneratedTask10InfrastructureKernel) {
                $second->shutdown();
                $second->cleanup();
            }
            restore_exception_handler();
        }
    }
}

final class GeneratedTask10InfrastructureKernel extends Kernel
{
    use MicroKernelTrait;

    private string $temporaryDirectory;

    public function __construct(private readonly string $applicationResources)
    {
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
            ]);
        });
        $loader->load($this->applicationResources.'/config/packages/account_rate_limiters.yaml');
        $loader->load($this->applicationResources.'/config/packages/test/account_tests.yaml');
        $loader->load(static function (ContainerBuilder $container): void {
            $container->setAlias('test.observable_logger', 'logger')->setPublic(true);
            $container->setAlias('test.account_registration_limiter', 'limiter.account_registration')->setPublic(true);
        });
    }
}
