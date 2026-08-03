<?php

declare(strict_types=1);

namespace Te4g\UserBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Te4g\UserBundle\Contract\AccountDeletionGuardInterface;

final class Te4gUserExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('te4g_user.config', $config);
        $container->setParameter('te4g_user.user_class', $config['user_class']);
        $container->setParameter('te4g_user.redirects.login_success', $config['redirects']['login_success']);
        $container->setAlias('te4g_user.configured_account_repository', $config['account_repository']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');
        $loader->load('installer.php');

        if (true === $config['enabled']) {
            $container
                ->registerForAutoconfiguration(AccountDeletionGuardInterface::class)
                ->addTag('te4g_user.account_deletion_guard')
            ;
            $loader->load('runtime.php');
        }
    }

    public function getAlias(): string
    {
        return 'te4g_user';
    }
}
