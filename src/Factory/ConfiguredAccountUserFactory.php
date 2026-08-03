<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Factory;

use Te4g\UserBundle\Contract\AccountUserFactoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Exception\InvalidAccountState;

final class ConfiguredAccountUserFactory implements AccountUserFactoryInterface
{
    public function __construct(
        private readonly string $userClass,
    ) {
    }

    public function create(string $normalizedEmail): AccountUserInterface
    {
        if (!class_exists($this->userClass)) {
            throw new InvalidAccountState('The configured account user cannot be created.');
        }

        $reflection = new \ReflectionClass($this->userClass);
        $constructor = $reflection->getConstructor();

        if (
            !$reflection->isInstantiable()
            || !$reflection->implementsInterface(AccountUserInterface::class)
            || (null !== $constructor && (!$constructor->isPublic() || 0 !== $constructor->getNumberOfParameters()))
        ) {
            throw new InvalidAccountState('The configured account user cannot be created.');
        }

        $user = $reflection->newInstance();

        if (!$user instanceof AccountUserInterface) {
            throw new InvalidAccountState('The configured account user cannot be created.');
        }

        $user->setEmail($normalizedEmail);

        return $user;
    }
}
