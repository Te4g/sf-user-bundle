<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\ResolveTaggedIteratorArgumentPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Te4g\UserBundle\Contract\AccountDeletionGuardInterface;
use Te4g\UserBundle\DependencyInjection\Te4gUserExtension;
use Te4g\UserBundle\Manager\AccountDeletionManager;
use Te4g\UserBundle\Manager\MagicLinkManager;
use Te4g\UserBundle\Manager\ProfileManager;
use Te4g\UserBundle\Security\AccountUserProvider;
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
}

final class RuntimeDefinitionDeletionGuard implements AccountDeletionGuardInterface
{
    public function assertDeletionAllowed(\Te4g\UserBundle\Contract\AccountUserInterface $user): void
    {
    }
}
