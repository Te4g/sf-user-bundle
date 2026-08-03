<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Te4g\UserBundle\DependencyInjection\Te4gUserExtension;
use Te4g\UserBundle\Manager\MagicLinkManager;
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
}
