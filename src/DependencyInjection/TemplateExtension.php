<?php

declare(strict_types=1);

namespace Acme\Bundle\TemplateBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class TemplateExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('template.message', $config['message']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.php');
    }

    public function getAlias(): string
    {
        return 'template';
    }
}
