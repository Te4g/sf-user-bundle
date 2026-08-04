<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\ResolveTaggedIteratorArgumentPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Firewall;
use Te4g\UserBundle\Contract\AccountDeletionGuardInterface;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Controller\AccountRequestLimiter;
use Te4g\UserBundle\Controller\RegistrationController;
use Te4g\UserBundle\DependencyInjection\Te4gUserExtension;
use Te4g\UserBundle\Form\RegistrationType;
use Te4g\UserBundle\Mailer\AccountMailer;
use Te4g\UserBundle\Manager\AccountDeletionManager;
use Te4g\UserBundle\Manager\MagicLinkManager;
use Te4g\UserBundle\Manager\ProfileManager;
use Te4g\UserBundle\Security\AccountUserProvider;
use Te4g\UserBundle\Security\LoginLinkCsrfSubscriber;
use Te4g\UserBundle\Security\VerifiedAccountUserChecker;
use Te4g\UserBundle\Tests\Fixtures\Domain\InMemoryAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class RuntimeServiceDefinitionsTest extends TestCase
{
    public function testEnabledRuntimeDefinesAuthenticationAndMagicLinkServices(): void
    {
        $container = new ContainerBuilder();

        (new Te4gUserExtension())->load([[
            'enabled' => true,
            'user_class' => TestAccountUser::class,
            'account_repository' => InMemoryAccountRepository::class,
        ]], $container);

        self::assertTrue($container->hasDefinition(AccountUserProvider::class));
        self::assertSame('%te4g_user.user_class%', $container->getDefinition(AccountUserProvider::class)->getArgument('$userClass'));
        self::assertTrue($container->hasDefinition(VerifiedAccountUserChecker::class));
        self::assertTrue($container->hasDefinition(LoginLinkCsrfSubscriber::class));
        self::assertSame([[]], $container->getDefinition(LoginLinkCsrfSubscriber::class)->getTag('kernel.event_subscriber'));
        $csrfListener = LoginLinkCsrfSubscriber::getSubscribedEvents()[KernelEvents::REQUEST];
        $routerListener = RouterListener::getSubscribedEvents()[KernelEvents::REQUEST][0];
        $firewallListener = Firewall::getSubscribedEvents()[KernelEvents::REQUEST];
        self::assertSame(['onKernelRequest', 16], $csrfListener);
        self::assertSame(['onKernelRequest', 32], $routerListener);
        self::assertSame(['onKernelRequest', 8], $firewallListener);
        self::assertGreaterThan($firewallListener[1], $csrfListener[1]);
        self::assertLessThan($routerListener[1], $csrfListener[1]);
        self::assertTrue($container->hasDefinition(MagicLinkManager::class));
    }

    public function testEnabledRuntimeAutoconfiguresPriorityAwareDeletionGuardsAndSelfAdministrationManagers(): void
    {
        $container = new ContainerBuilder();

        (new Te4gUserExtension())->load([[
            'enabled' => true,
            'user_class' => TestAccountUser::class,
            'account_repository' => InMemoryAccountRepository::class,
        ]], $container);

        self::assertTrue($container->hasDefinition(ProfileManager::class));
        self::assertTrue($container->hasDefinition(AccountDeletionManager::class));

        $guards = $container->getDefinition(AccountDeletionManager::class)->getArgument('$guards');
        self::assertInstanceOf(TaggedIteratorArgument::class, $guards);
        self::assertSame('te4g_user.account_deletion_guard', $guards->getTag());

        $autoconfiguration = $container->getAutoconfiguredInstanceof();
        self::assertArrayHasKey(AccountDeletionGuardInterface::class, $autoconfiguration);
        self::assertSame(
            [[]],
            $autoconfiguration[AccountDeletionGuardInterface::class]->getTag('te4g_user.account_deletion_guard'),
        );

        $container->register('test.guard.low', RuntimeDefinitionDeletionGuard::class)
            ->addTag('te4g_user.account_deletion_guard', ['priority' => -10]);
        $container->register('test.guard.high', RuntimeDefinitionDeletionGuard::class)
            ->addTag('te4g_user.account_deletion_guard', ['priority' => 100]);
        (new ResolveTaggedIteratorArgumentPass())->process($container);

        $resolvedGuards = $guards->getValues();
        self::assertCount(2, $resolvedGuards);
        self::assertInstanceOf(Reference::class, $resolvedGuards[0]);
        self::assertInstanceOf(Reference::class, $resolvedGuards[1]);
        self::assertSame('test.guard.high', (string) $resolvedGuards[0]);
        self::assertSame('test.guard.low', (string) $resolvedGuards[1]);
    }

    public function testEnabledRuntimeDefinesPrivatePublicHttpServicesAndConcreteMailer(): void
    {
        $container = new ContainerBuilder();

        (new Te4gUserExtension())->load([[
            'enabled' => true,
            'user_class' => TestAccountUser::class,
            'account_repository' => InMemoryAccountRepository::class,
        ]], $container);

        $controller = $container->getDefinition(RegistrationController::class);
        self::assertFalse($controller->isPublic());
        self::assertSame([[]], $controller->getTag('controller.service_arguments'));
        self::assertTrue($container->hasDefinition(RegistrationType::class));
        self::assertSame([[]], $container->getDefinition(RegistrationType::class)->getTag('form.type'));
        self::assertTrue($container->hasDefinition(AccountMailer::class));
        self::assertSame(AccountMailer::class, (string) $container->getAlias(AccountMailerInterface::class));

        $limiter = $container->getDefinition(AccountRequestLimiter::class)->getArgument('$factory');
        self::assertInstanceOf(Reference::class, $limiter);
        self::assertSame('limiter.te4g_user_account_requests', (string) $limiter);
    }
}

final class RuntimeDefinitionDeletionGuard implements AccountDeletionGuardInterface
{
    public function assertDeletionAllowed(\Te4g\UserBundle\Contract\AccountUserInterface $user): void
    {
    }
}
