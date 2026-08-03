<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\App;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Te4g\UserBundle\Te4gUserBundle;

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
            new Te4gUserBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $templateConfig = $this->templateConfig;

        $loader->load(static function (ContainerBuilder $container) use ($templateConfig): void {
            $container->loadFromExtension('te4g_user', $templateConfig);
        });
    }
}
