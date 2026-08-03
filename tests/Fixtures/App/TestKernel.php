<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\App;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserFactoryInterface;
use Te4g\UserBundle\Te4gUserBundle;
use Te4g\UserBundle\Tests\Fixtures\Domain\FakeAccountUserFactory;
use Te4g\UserBundle\Tests\Fixtures\Domain\InMemoryAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class TestKernel extends Kernel
{
    private string $cacheDir;

    /**
     * @param array<string, mixed> $userConfig
     */
    public function __construct(
        private readonly array $userConfig = [],
    ) {
        $this->cacheDir = sys_get_temp_dir().'/template-bundle-'.bin2hex(random_bytes(6));

        parent::__construct('test', true);
    }

    public function getCacheDir(): string
    {
        return $this->cacheDir.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->cacheDir.'/log';
    }

    public static function enabled(): self
    {
        return new self([
            'enabled' => true,
            'user_class' => TestAccountUser::class,
            'account_repository' => InMemoryAccountRepository::class,
        ]);
    }

    public function registerBundles(): iterable
    {
        return [
            new Te4gUserBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $userConfig = $this->userConfig;

        $loader->load(static function (ContainerBuilder $container) use ($userConfig): void {
            $container->loadFromExtension('te4g_user', $userConfig);

            if (true !== ($userConfig['enabled'] ?? false)) {
                return;
            }

            $container->register(InMemoryAccountRepository::class, InMemoryAccountRepository::class)->setPublic(true);
            $container->register(FakeAccountUserFactory::class, FakeAccountUserFactory::class)->setPublic(true);
            $container->register(RecordingAccountMailer::class, RecordingAccountMailer::class)->setPublic(true);

            $container->setAlias(AccountUserFactoryInterface::class, FakeAccountUserFactory::class);
            $container->setAlias(AccountMailerInterface::class, RecordingAccountMailer::class);
            $container->setAlias('test.'.AccountRepositoryInterface::class, AccountRepositoryInterface::class)->setPublic(true);
            $container->setAlias('test.'.AccountUserFactoryInterface::class, AccountUserFactoryInterface::class)->setPublic(true);
            $container->setAlias('test.'.AccountMailerInterface::class, AccountMailerInterface::class)->setPublic(true);
        });
    }
}
