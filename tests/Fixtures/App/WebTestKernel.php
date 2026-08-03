<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\UX\Turbo\TurboBundle;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Te4gUserBundle;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;
use Te4g\UserBundle\Tests\Fixtures\Persistence\TestUser;
use Te4g\UserBundle\Tests\Fixtures\Persistence\TestUserRepository;

final class WebTestKernel extends Kernel
{
    use MicroKernelTrait;

    private string $temporaryDirectory;

    public function __construct(string $environment, bool $debug)
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/te4g-user-bundle-'.bin2hex(random_bytes(6));

        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new SecurityBundle(),
            new TwigBundle(),
            new DoctrineBundle(),
            new TurboBundle(),
            new TwigComponentBundle(),
            new Te4gUserBundle(),
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

    public function getTemporaryDirectory(): string
    {
        return $this->temporaryDirectory;
    }

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $temporaryDirectory = $this->temporaryDirectory;

        $loader->load(static function (ContainerBuilder $container) use ($temporaryDirectory): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test-secret',
                'test' => true,
                'router' => [
                    'resource' => __DIR__.'/routes.php',
                    'type' => 'php',
                    'utf8' => true,
                ],
                'mailer' => ['dsn' => 'null://null'],
                'validation' => ['enabled' => true],
                'form' => ['enabled' => true],
                'session' => [
                    'storage_factory_id' => 'session.storage.factory.mock_file',
                ],
                'rate_limiter' => [
                    'te4g_user_account_requests' => [
                        'policy' => 'fixed_window',
                        'limit' => 20,
                        'interval' => '1 minute',
                    ],
                ],
            ]);
            $container->loadFromExtension('security', [
                'password_hashers' => [
                    'Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface' => 'auto',
                ],
                'providers' => [
                    'test_users' => ['id' => TestUserRepository::class],
                ],
                'firewalls' => [
                    'main' => [
                        'lazy' => true,
                        'provider' => 'test_users',
                    ],
                ],
            ]);
            $container->loadFromExtension('twig', [
                'default_path' => dirname(__DIR__, 3).'/templates',
                'paths' => [
                    dirname(__DIR__, 3).'/templates' => 'Te4gUser',
                ],
            ]);
            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'driver' => 'pdo_sqlite',
                    'path' => $temporaryDirectory.'/database.sqlite',
                ],
                'orm' => [
                    'mappings' => [
                        'TestFixtures' => [
                            'type' => 'attribute',
                            'dir' => dirname(__DIR__).'/Persistence',
                            'prefix' => 'Te4g\\UserBundle\\Tests\\Fixtures\\Persistence',
                            'alias' => 'TestFixtures',
                        ],
                    ],
                ],
            ]);
            $container->loadFromExtension('turbo', []);
            $container->loadFromExtension('twig_component', [
                'defaults' => [
                    'Te4g\\UserBundle\\Tests\\Fixtures\\' => 'components',
                ],
                'anonymous_template_directory' => 'components',
            ]);
            $container->loadFromExtension('te4g_user', [
                'enabled' => true,
                'user_class' => TestUser::class,
                'account_repository' => TestUserRepository::class,
            ]);

            $container->register(TestUserRepository::class, TestUserRepository::class)
                ->setAutowired(true)
                ->setPublic(true);
            $container->register(TestVerifyEmailHelper::class, TestVerifyEmailHelper::class);
            $container->register(TestLoginLinkHandler::class, TestLoginLinkHandler::class);
            $container->register(TestResetPasswordHelper::class, TestResetPasswordHelper::class)->setAutowired(true);
            $container->register(TestResetPasswordRequestRepository::class, TestResetPasswordRequestRepository::class);
            $container->register(RecordingAccountMailer::class, RecordingAccountMailer::class)->setPublic(true);
            $container->setAlias(AccountRepositoryInterface::class, TestUserRepository::class);
            $container->setAlias(AccountMailerInterface::class, RecordingAccountMailer::class);
            $container->setAlias('test.'.AccountMailerInterface::class, AccountMailerInterface::class)->setPublic(true);
            $container->setAlias(VerifyEmailHelperInterface::class, TestVerifyEmailHelper::class);
            $container->setAlias(LoginLinkHandlerInterface::class, TestLoginLinkHandler::class);
            $container->setAlias(ResetPasswordHelperInterface::class, TestResetPasswordHelper::class);
            $container->setAlias(ResetPasswordRequestRepositoryInterface::class, TestResetPasswordRequestRepository::class);
        });
    }
}
