<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserFactoryInterface;
use Te4g\UserBundle\Manager\MagicLinkManager;
use Te4g\UserBundle\Security\AccountUserProvider;
use Te4g\UserBundle\Security\VerifiedAccountUserChecker;
use Te4g\UserBundle\Tests\Fixtures\App\TestKernel;
use Te4g\UserBundle\Tests\Fixtures\Domain\FakeAccountUserFactory;
use Te4g\UserBundle\Tests\Fixtures\Domain\InMemoryAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;

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
            self::assertFalse($container->has(MagicLinkManager::class));
            self::assertFalse($container->has(AccountUserProvider::class));
            self::assertFalse($container->has(VerifiedAccountUserChecker::class));
            self::assertFalse($container->has(InMemoryAccountRepository::class));
            self::assertFalse($container->has(FakeAccountUserFactory::class));
            self::assertFalse($container->has(RecordingAccountMailer::class));
            self::assertFalse($container->has('test.'.AccountRepositoryInterface::class));
            self::assertFalse($container->has('test.'.AccountUserFactoryInterface::class));
            self::assertFalse($container->has('test.'.AccountMailerInterface::class));
        } finally {
            $kernel->shutdown();
        }
    }
}
