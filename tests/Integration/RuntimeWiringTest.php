<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserFactoryInterface;
use Te4g\UserBundle\Tests\Fixtures\App\TestKernel;
use Te4g\UserBundle\Tests\Fixtures\App\WebTestKernel;
use Te4g\UserBundle\Tests\Fixtures\Domain\FakeAccountUserFactory;
use Te4g\UserBundle\Tests\Fixtures\Domain\InMemoryAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;
use Te4g\UserBundle\Tests\Fixtures\Persistence\TestUserRepository;

final class RuntimeWiringTest extends TestCase
{
    public function testEnabledRuntimeUsesApplicationAdapters(): void
    {
        $kernel = TestKernel::enabled();
        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            self::assertInstanceOf(
                InMemoryAccountRepository::class,
                $container->get('test.'.AccountRepositoryInterface::class),
            );
            self::assertInstanceOf(
                FakeAccountUserFactory::class,
                $container->get('test.'.AccountUserFactoryInterface::class),
            );
            self::assertInstanceOf(
                RecordingAccountMailer::class,
                $container->get('test.'.AccountMailerInterface::class),
            );
        } finally {
            $kernel->shutdown();
        }
    }

    public function testWebKernelBootsWithThePersistenceFixtureOnly(): void
    {
        $kernel = new WebTestKernel('test', false);
        $kernel->boot();

        try {
            self::assertInstanceOf(TestUserRepository::class, $kernel->getContainer()->get(TestUserRepository::class));
        } finally {
            $kernel->shutdown();
            $kernel->cleanup();
            restore_exception_handler();
        }
    }
}
