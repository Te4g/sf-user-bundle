<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Tests\Fixtures\App\TestKernel;

final class BundleKernelTest extends TestCase
{
    public function testDisabledBundleBootsWithoutAnApplicationUser(): void
    {
        $kernel = new TestKernel();

        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            self::assertInstanceOf(UserBundleOptions::class, $container->get(UserBundleOptions::class));
            self::assertFalse($container->has('Te4g\\UserBundle\\Manager\\RegistrationManager'));
        } finally {
            $kernel->shutdown();
        }
    }
}
