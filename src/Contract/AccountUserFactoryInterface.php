<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Contract;

interface AccountUserFactoryInterface
{
    public function create(string $normalizedEmail): AccountUserInterface;
}
