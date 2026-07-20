<?php

declare(strict_types=1);

namespace Acme\Bundle\TemplateBundle\Tests\Fixtures\App;

use Acme\Bundle\TemplateBundle\Service\ExampleService;
use Acme\Bundle\TemplateBundle\TemplateBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    private string $cacheDir;

    /**
     * @param array<string, mixed> $templateConfig
     */
    public function __construct(
        private readonly array $templateConfig = [],
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

    public function registerBundles(): iterable
    {
        return [
            new TemplateBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $templateConfig = $this->templateConfig;

        $loader->load(static function (ContainerBuilder $container) use ($templateConfig): void {
            $container->loadFromExtension('template', $templateConfig);
            $container->setAlias('test.'.ExampleService::class, ExampleService::class)->setPublic(true);
        });
    }
}
