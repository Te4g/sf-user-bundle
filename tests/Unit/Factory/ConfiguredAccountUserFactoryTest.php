<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Factory;

use PHPUnit\Framework\TestCase;
use Te4g\UserBundle\Exception\InvalidAccountState;
use Te4g\UserBundle\Factory\ConfiguredAccountUserFactory;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class ConfiguredAccountUserFactoryTest extends TestCase
{
    public function testFactoryCreatesConfiguredContractImplementation(): void
    {
        $factory = new ConfiguredAccountUserFactory(TestAccountUser::class);

        $user = $factory->create('user@example.com');

        self::assertSame('user@example.com', $user->getEmail());
    }

    public function testFactoryRejectsAClassThatDoesNotImplementTheAccountContract(): void
    {
        $factory = new ConfiguredAccountUserFactory(NonAccountUser::class);

        $this->expectException(InvalidAccountState::class);

        $factory->create('user@example.com');
    }

    public function testFactoryRejectsAClassThatRequiresConstructorArguments(): void
    {
        $factory = new ConfiguredAccountUserFactory(AccountUserRequiringArgument::class);

        $this->expectException(InvalidAccountState::class);

        $factory->create('user@example.com');
    }

    public function testFactoryRejectsAClassWithAnOptionalConstructorArgument(): void
    {
        $factory = new ConfiguredAccountUserFactory(AccountUserWithOptionalArgument::class);

        $this->expectException(InvalidAccountState::class);

        $factory->create('user@example.com');
    }
}

final class NonAccountUser
{
}

final class AccountUserRequiringArgument extends TestAccountUser
{
    public function __construct(string $requiredArgument)
    {
    }
}

final class AccountUserWithOptionalArgument extends TestAccountUser
{
    public function __construct(?string $optionalArgument = null)
    {
    }
}
